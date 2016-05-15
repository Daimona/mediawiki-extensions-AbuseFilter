<?php

use MediaWiki\Auth\AuthManager;

class AbuseFilterHooks {
	public static $successful_action_vars = false;
	/** @var WikiPage|Article|bool */
	public static $last_edit_page = false; // make sure edit filter & edit save hooks match
	// So far, all of the error message out-params for these hooks accept HTML.
	// Hooray!

	public static function onRegistration() {
		global $wgDisableAuthManager, $wgAuthManagerAutoConfig;

		if ( class_exists( AuthManager::class ) && !$wgDisableAuthManager ) {
			$wgAuthManagerAutoConfig['preauth'][AbuseFilterPreAuthenticationProvider::class] = [
				'class' => AbuseFilterPreAuthenticationProvider::class,
				'sort' => 5, // run after normal preauth providers to keep the log cleaner
			];
		} else {
			Hooks::register( 'AbortNewAccount', 'AbuseFilterHooks::onAbortNewAccount' );
			Hooks::register( 'AbortAutoAccount', 'AbuseFilterHooks::onAbortAutoAccount' );
		}

	}

	/**
	 * Entry point for the APIEditBeforeSave hook.
	 *
	 * This is needed to give a useful error for API edits on MediaWiki before 1.25 (T34216).
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/APIEditBeforeSave
	 *
	 * @param EditPage $editPage
	 * @param string $text New text of the article (has yet to be saved)
	 * @param array &$result Data in this array will be added to the API result
	 *
	 * @return bool
	 */
	public static function onAPIEditBeforeSave( $editPage, $text, &$result ) {
		// Don't use the APIEditBeforeSave hook if EditFilterMergedContent allows us to produce error
		// details for the API, it lies in case of autoresolved edit conflicts (T73947).
		if ( defined( 'MW_EDITFILTERMERGED_SUPPORTS_API' ) ) {
			return true;
		}

		if ( $editPage->undidRev > 0 ) {
			// This hook is also (unlike the non-API hooks) being run on undo,
			// but we don't want to filter in that case. T126861
			return true;
		}

		$context = $editPage->mArticle->getContext();

		$status = Status::newGood();
		$minoredit = $editPage->minoredit;
		$summary = $editPage->summary;

		// poor man's PST, see bug 20310
		$text = str_replace( "\r\n", "\n", $text );

		self::filterEdit( $context, null, $text, $status, $summary, $minoredit );

		if ( !$status->isOK() ) {
			$result = self::getEditApiResult( $status );
		}

		return $status->isOK();
	}

	/**
	 * Entry point for the EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content $content the new Content generated by the edit
	 * @param Status $status Error message to return
	 * @param string $summary Edit summary for page
	 * @param User $user the user performing the edit
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 *
	 * @return bool
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit ) {

		$text = AbuseFilter::contentToString( $content );

		$continue = self::filterEdit( $context, $content, $text, $status, $summary, $minoredit );

		if ( defined( 'MW_EDITFILTERMERGED_SUPPORTS_API' ) && !$status->isOK() ) {
			// Produce a useful error message for API edits (T34216) without APIEditBeforeSave (T73947)
			$status->apiHookResult = self::getEditApiResult( $status );
		}

		return $continue;
	}

	/**
	 * Common implementation for the APIEditBeforeSave and EditFilterMergedContent hooks.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content|null $content the new Content generated by the edit
	 * @param string $text new page content (subject of filtering)
	 * @param Status $status Error message to return
	 * @param string $summary Edit summary for page
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 *
	 * @return bool
	 */
	public static function filterEdit( IContextSource $context, $content, $text,
		Status $status, $summary, $minoredit ) {
		// Load vars
		$vars = new AbuseFilterVariableHolder();

		$title = $context->getTitle();

		// Some edits are running through multiple hooks, but we only want to filter them once
		if ( isset( $title->editAlreadyFiltered ) ) {
			return true;
		} elseif ( $title ) {
			$title->editAlreadyFiltered = true;
		}

		self::$successful_action_vars = false;
		self::$last_edit_page = false;

		$user = $context->getUser();

		$oldtext = '';

		if ( ( $title instanceof Title ) && $title->canExist() && $title->exists() ) {
			// Make sure we load the latest text saved in database (bug 31656)
			$page = $context->getWikiPage();
			$revision = $page->getRevision();
			if ( !$revision ) {
				return true;
			}

			$oldcontent = $revision->getContent( Revision::RAW );
			$oldtext = AbuseFilter::contentToString( $oldcontent );

			// Cache article object so we can share a parse operation
			$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
			AFComputedVariable::$articleCache[$articleCacheKey] = $page;

			// Don't trigger for null edits.
			if ( $content && isset( $oldcontent ) && $content->equals( $oldcontent ) ) {
				// Compare Content objects if available
				return true;
			} elseif ( strcmp( $oldtext, $text ) == 0 ) {
				// Otherwise, compare strings
				return true;
			}
		} else {
			$page = null;
		}

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'ARTICLE' )
		);

		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', $summary );
		$vars->setVar( 'minor_edit', $minoredit );

		$vars->setVar( 'old_wikitext', $oldtext );
		$vars->setVar( 'new_wikitext', $text );

		// TODO: set old_content and new_content vars, use them

		$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );

		$filter_result = AbuseFilter::filterAction( $vars, $title );

		if ( !$filter_result->isOK() ) {
			$status->merge( $filter_result );

			return true; // re-show edit form
		}

		self::$successful_action_vars = $vars;
		self::$last_edit_page = $page;

		return true;
	}

	/**
	 * Common implementation for the APIEditBeforeSave and EditFilterMergedContent hooks.
	 *
	 * @param Status $status Error message details
	 * @return array API result
	 */
	public static function getEditApiResult( Status $status ) {
		$msg = $status->getErrorsArray();
		$msg = $msg[0];

		// Use the error message key name as error code, the first parameter is the filter description.
		if ( $msg instanceof Message ) {
			// For forward compatibility: In case we switch over towards using Message objects someday.
			// (see the todo for AbuseFilter::buildStatus)
			$code = $msg->getKey();
			$filterDescription = $msg->getParams();
			$filterDescription = $filterDescription[0];
			$warning = $msg->parse();
		} else {
			$code = array_shift( $msg );
			$filterDescription = $msg[0];
			$warning = wfMessage( $code )->params( $msg )->parse();
		}

		return array(
			'code' => $code,
			'info' => 'Hit AbuseFilter: ' . $filterDescription,
			'warning' => $warning
		);
	}

	public static function onArticleSaveComplete(
		&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor,
		&$flags, $revision
	) {
		if ( !self::$successful_action_vars || !$revision ) {
			self::$successful_action_vars = false;

			return true;
		}

		$vars = self::$successful_action_vars;

		if ( $vars->getVar( 'article_prefixedtext' )->toString() !==
			$article->getTitle()->getPrefixedText()
		) {
			return true;
		}

		if ( !self::identicalPageObjects( $article, self::$last_edit_page ) ) {
			return true; // this isn't the edit $successful_action_vars was set for
		}
		self::$last_edit_page = false;

		if ( $vars->getVar( 'local_log_ids' ) ) {
			// Now actually do our storage
			$log_ids = $vars->getVar( 'local_log_ids' )->toNative();
			$dbw = wfGetDB( DB_MASTER );

			if ( count( $log_ids ) ) {
				$dbw->update( 'abuse_filter_log',
					array( 'afl_rev_id' => $revision->getId() ),
					array( 'afl_id' => $log_ids ),
					__METHOD__
				);
			}
		}

		if ( $vars->getVar( 'global_log_ids' ) ) {
			$log_ids = $vars->getVar( 'global_log_ids' )->toNative();

			if ( count( $log_ids ) ) {
				global $wgAbuseFilterCentralDB;
				$fdb = wfGetDB( DB_MASTER, array(), $wgAbuseFilterCentralDB );

				$fdb->update( 'abuse_filter_log',
					array( 'afl_rev_id' => $revision->getId() ),
					array( 'afl_id' => $log_ids, 'afl_wiki' => wfWikiId() ),
					__METHOD__
				);
			}
		}

		return true;
	}

	/**
	 * Check if two article objects are identical or have an identical WikiPage
	 * @param $page1 Article|WikiPage
	 * @param $page2 Article|WikiPage
	 * @return bool
	 */
	protected static function identicalPageObjects( $page1, $page2 ) {
		if ( method_exists( 'Article', 'getPage' ) ) {
			$wpage1 = ( $page1 instanceof Article ) ? $page1->getPage() : $page1;
			$wpage2 = ( $page2 instanceof Article ) ? $page2->getPage() : $page2;

			return ( $wpage1 === $wpage2 );
		} else { // b/c for before WikiPage
			return ( $page1 === $page2 ); // should be two Article objects
		}
	}

	/**
	 * @param $user
	 * @param $promote
	 * @return bool
	 */
	public static function onGetAutoPromoteGroups( $user, &$promote ) {
		if ( $promote ) {
			$key = AbuseFilter::autoPromoteBlockKey( $user );
			$blocked = (bool)ObjectCache::getInstance( 'hash' )->getWithSetCallback(
				$key,
				30,
				function () use ( $key ) {
					return (int)ObjectCache::getMainStashInstance()->get( $key );
				}
			);

			if ( $blocked ) {
				$promote = array();
			}
		}

		return true;
	}

	public static function onMovePageCheckPermissions( Title $oldTitle, Title $newTitle,
		User $user, $reason, Status $status
	) {
		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
		);
		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'move' );

		$result = AbuseFilter::filterAction( $vars, $oldTitle );
		$status->merge( $result );

		return $result->isOK();
	}

	/**
	 * @param $oldTitle Title
	 * @param $newTitle Title
	 * @param $user User
	 * @param $error
	 * @param $reason
	 * @return bool
	 */
	public static function onAbortMove( $oldTitle, $newTitle, $user, &$error, $reason ) {
		global $wgUser;
		// HACK: This is a secret userright so system actions
		// can bypass AbuseFilter. Should not be assigned to
		// normal users. This should be turned into a proper
		// userright in bug 67936.
		if ( $wgUser->isAllowed( 'abusefilter-bypass' ) ) {
			return true;
		}

		$status = new Status();
		self::onMovePageCheckPermissions( $oldTitle, $newTitle, $wgUser, $reason, $status );
		if ( !$status->isOK() ) {
			$error = $status->getHTML();
		}

		return $status->isOK();
	}

	/**
	 * @param $article Article
	 * @param $user User
	 * @param $reason string
	 * @param $error
	 * @param $status
	 * @return bool
	 */
	public static function onArticleDelete( &$article, &$user, &$reason, &$error, &$status ) {
		$vars = new AbuseFilterVariableHolder;

		global $wgUser;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $wgUser ),
			AbuseFilter::generateTitleVars( $article->getTitle(), 'ARTICLE' )
		);

		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'delete' );

		$filter_result = AbuseFilter::filterAction( $vars, $article->getTitle() );

		$status->merge( $filter_result );
		$error = $filter_result->isOK() ? '' : $filter_result->getHTML();

		return $filter_result->isOK();
	}

	/**
	 * @param $user User
	 * @param $message
	 * @param $autocreate bool Indicates whether the account is created automatically.
	 * @return bool
	 * @deprecated AbuseFilterPreAuthenticationProvider will take over this functionality
	 */
	private static function checkNewAccount( $user, &$message, $autocreate ) {
		if ( $user->getName() == wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() ) {
			$message = wfMessage( 'abusefilter-accountreserved' )->text();

			return false;
		}

		$vars = new AbuseFilterVariableHolder;

		// Add variables only for a registered user, so IP addresses of
		// new users won't be exposed
		global $wgUser;
		if ( !$autocreate && $wgUser->getId() ) {
			$vars->addHolders( AbuseFilter::generateUserVars( $wgUser ) );
		}

		$vars->setVar( 'ACTION', $autocreate ? 'autocreateaccount' : 'createaccount' );
		$vars->setVar( 'ACCOUNTNAME', $user->getName() );

		$filter_result = AbuseFilter::filterAction(
			$vars, SpecialPage::getTitleFor( 'Userlogin' ) );

		$message = $filter_result->isOK() ? '' : $filter_result->getWikiText();

		return $filter_result->isOK();
	}

	/**
	 * @param $user User
	 * @param $message
	 * @return bool
	 * @deprecated AbuseFilterPreAuthenticationProvider will take over this functionality
	 */
	public static function onAbortNewAccount( $user, &$message ) {
		return self::checkNewAccount( $user, $message, false );
	}

	/**
	 * @param $user User
	 * @param $message
	 * @return bool
	 * @deprecated AbuseFilterPreAuthenticationProvider will take over this functionality
	 */
	public static function onAbortAutoAccount( $user, &$message ) {
		// FIXME: ERROR MESSAGE IS SHOWN IN A WEIRD WAY, BEACUSE $message
		// HERE MEANS NAME OF THE MESSAGE, NOT THE TEXT OF THE MESSAGE AS
		// IN AbortNewAccount HOOK WHICH WE CANNOT PROVIDE!
		return self::checkNewAccount( $user, $message, true );
	}

	/**
	 * @param $recentChange RecentChange
	 * @return bool
	 */
	public static function onRecentChangeSave( $recentChange ) {
		$title = Title::makeTitle(
			$recentChange->getAttribute( 'rc_namespace' ),
			$recentChange->getAttribute( 'rc_title' )
		);
		$action = $recentChange->mAttribs['rc_log_type'] ?
			$recentChange->mAttribs['rc_log_type'] : 'edit';
		$actionID = implode( '-', array(
			$title->getPrefixedText(), $recentChange->mAttribs['rc_user_text'], $action
		) );

		if ( !empty( AbuseFilter::$tagsToSet[$actionID] )
			&& count( $tags = AbuseFilter::$tagsToSet[$actionID] )
		) {
			ChangeTags::addTags(
				$tags,
				$recentChange->mAttribs['rc_id'],
				$recentChange->mAttribs['rc_this_oldid'],
				$recentChange->mAttribs['rc_logid']
			);
		}

		return true;
	}

	/**
	 * @param array $tags
	 * @param bool $enabled
	 * @return bool
	 */
	private static function fetchAllTags( array &$tags, $enabled ) {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;

		# This is a pretty awful hack.
		$dbr = wfGetDB( DB_SLAVE );

		$where = array( 'afa_consequence' => 'tag', 'af_deleted' => false );
		if ( $enabled ) {
			$where['af_enabled'] = true;
		}
		$res = $dbr->select(
			array( 'abuse_filter_action', 'abuse_filter' ),
			'afa_parameters',
			$where,
			__METHOD__,
			array(),
			array( 'abuse_filter' => array( 'INNER JOIN', 'afa_filter=af_id' ) )
		);

		foreach ( $res as $row ) {
			$tags = array_filter(
				array_merge( explode( "\n", $row->afa_parameters ), $tags )
			);
		}

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			$dbr = wfGetDB( DB_SLAVE, array(), $wgAbuseFilterCentralDB );
			$where['af_global'] = 1;
			$res = $dbr->select(
				array( 'abuse_filter_action', 'abuse_filter' ),
				'afa_parameters',
				$where,
				__METHOD__,
				array(),
				array( 'abuse_filter' => array( 'INNER JOIN', 'afa_filter=af_id' ) )
			);

			foreach ( $res as $row ) {
				$tags = array_filter(
					array_merge( explode( "\n", $row->afa_parameters ), $tags )
				);
			}
		}

		return true;
	}

	/**
	 * @param array $tags
	 * @return bool
	 */
	public static function onListDefinedTags( array &$tags ) {
		return self::fetchAllTags( $tags, false );
	}

	/**
	 * @param array $tags
	 * @return bool
	 */
	public static function onChangeTagsListActive( array &$tags ) {
		return self::fetchAllTags( $tags, true );
	}

	/**
	 * @param $updater DatabaseUpdater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = dirname( __FILE__ );

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter',
					"$dir/abusefilter.tables.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history',
					"$dir/db_patches/patch-abuse_filter_history.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter',
					"$dir/abusefilter.tables.sqlite.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history',
					"$dir/db_patches/patch-abuse_filter_history.sqlite.sql", true ) );
			}
			$updater->addExtensionUpdate( array(
				'addField', 'abuse_filter_history', 'afh_changed_fields',
				"$dir/db_patches/patch-afh_changed_fields.sql", true
			) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_deleted',
				"$dir/db_patches/patch-af_deleted.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_actions',
				"$dir/db_patches/patch-af_actions.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_global',
				"$dir/db_patches/patch-global_filters.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter_log', 'afl_rev_id',
				"$dir/db_patches/patch-afl_action_id.sql", true ) );
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log',
					'filter_timestamp', "$dir/db_patches/patch-fix-indexes.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array(
					'addIndex', 'abuse_filter_log', 'afl_filter_timestamp',
					"$dir/db_patches/patch-fix-indexes.sqlite.sql", true
				) );
			}

			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter',
				'af_group', "$dir/db_patches/patch-af_group.sql", true ) );

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array(
					'addIndex', 'abuse_filter_log', 'wiki_timestamp',
					"$dir/db_patches/patch-global_logging_wiki-index.sql", true
				) );
			} else {
				$updater->addExtensionUpdate( array(
					'addIndex', 'abuse_filter_log', 'afl_wiki_timestamp',
					"$dir/db_patches/patch-global_logging_wiki-index.sqlite.sql", true
				) );
			}

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array(
					'modifyField', 'abuse_filter_log', 'afl_namespace',
					"$dir/db_patches/patch-afl-namespace_int.sql", true
				) );
			} else {
				/**
				$updater->addExtensionUpdate( array(
					 'modifyField',
					 'abuse_filter_log',
					 'afl_namespace',
					 "$dir/db_patches/patch-afl-namespace_int.sqlite.sql",
					 true
				) );
				 */
				/* @todo Modify a column in sqlite, which do not support such
				 * things create backup, drop, create with new schema, copy,
				 * drop backup or simply see
				 * https://www.mediawiki.org/wiki/Manual:SQLite#About_SQLite :
				 * Several extensions are known to have database update or
				 * installation issues with SQLite: AbuseFilter, ...
				 */
			}
		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
			$updater->addExtensionUpdate( array(
				'addTable', 'abuse_filter', "$dir/abusefilter.tables.pg.sql", true ) );
			$updater->addExtensionUpdate( array(
				'addTable', 'abuse_filter_history',
				"$dir/db_patches/patch-abuse_filter_history.pg.sql", true
			) );
			$updater->addExtensionUpdate( array(
				'addPgField', 'abuse_filter', 'af_actions', "TEXT NOT NULL DEFAULT ''" ) );
			$updater->addExtensionUpdate( array(
				'addPgField', 'abuse_filter', 'af_deleted', 'SMALLINT NOT NULL DEFAULT 0' ) );
			$updater->addExtensionUpdate( array(
				'addPgField', 'abuse_filter', 'af_global', 'SMALLINT NOT NULL DEFAULT 0' ) );
			$updater->addExtensionUpdate( array(
				'addPgField', 'abuse_filter_log', 'afl_wiki', 'TEXT' ) );
			$updater->addExtensionUpdate( array(
				'addPgField', 'abuse_filter_log', 'afl_deleted', 'SMALLINT' ) );
			$updater->addExtensionUpdate( array(
				'changeField', 'abuse_filter_log', 'afl_filter', 'TEXT', '' ) );
			$updater->addExtensionUpdate( array(
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_ip', "(afl_ip)" ) );
			$updater->addExtensionUpdate( array(
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_wiki', "(afl_wiki)" ) );
			$updater->addExtensionUpdate( array(
				'changeField', 'abuse_filter_log', 'afl_namespace', "INTEGER" ) );
		}

		$updater->addExtensionUpdate( array( array( __CLASS__, 'createAbuseFilterUser' ) ) );

		return true;
	}

	/**
	 * Updater callback to create the AbuseFilter user after the user tables have been updated.
	 * @param $updater DatabaseUpdater
	 */
	public static function createAbuseFilterUser( $updater ) {
		$username = wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text();
		$user = User::newFromName( $username );

		if ( $user && !$updater->updateRowExists( 'create abusefilter-blocker-user' ) ) {
			if ( method_exists( 'User', 'newSystemUser' ) ) {
				$user = User::newSystemUser( $username, array( 'steal' => true ) );
			} else {
				if ( !$user->getId() ) {
					$user->addToDatabase();
					$user->saveSettings();
					# Increment site_stats.ss_users
					$ssu = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
					$ssu->doUpdate();
				} else {
					// Sorry dude, we need this account.
					$user->setPassword( null );
					$user->setEmail( null );
					$user->saveSettings();
				}
			}
			$updater->insertUpdateRow( 'create abusefilter-blocker-user' );
			# Promote user so it doesn't look too crazy.
			$user->addGroup( 'sysop' );
		}
	}

	/**
	 * @param $id
	 * @param $nt Title
	 * @param $tools
	 * @return bool
	 */
	public static function onContributionsToolLinks( $id, $nt, &$tools ) {
		global $wgUser;
		if ( $wgUser->isAllowed( 'abusefilter-log' ) ) {
			$tools[] = Linker::link(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				wfMessage( 'abusefilter-log-linkoncontribs' )->text(),
				array( 'title' => wfMessage( 'abusefilter-log-linkoncontribs-text' )->parse() ),
				array( 'wpSearchUser' => $nt->getText() )
			);
		}

		return true;
	}

	/**
	 * Handler for the UploadVerifyFile hook
	 *
	 * @param $upload UploadBase
	 * @param $mime
	 * @param $error array
	 *
	 * @return bool
	 */
	public static function onUploadVerifyFile( $upload, $mime, &$error ) {
		global $wgUser;

		$vars = new AbuseFilterVariableHolder;
		$title = $upload->getTitle();

		if ( !$title ) {
			// If there's no valid title assigned to the upload
			// it wont proceed anyway, so no point in filtering it.
			return true;
		}

		$vars->addHolders(
			AbuseFilter::generateUserVars( $wgUser ),
			AbuseFilter::generateTitleVars( $title, 'ARTICLE' )
		);

		$vars->setVar( 'ACTION', 'upload' );

		// We use the hexadecimal version of the file sha1.
		// Use UploadBase::getTempFileSha1Base36 so that we don't have to calculate the sha1 sum again
		$sha1 = Wikimedia\base_convert( $upload->getTempFileSha1Base36(), 36, 16, 40 );

		$vars->setVar( 'file_sha1', $sha1 );
		$vars->setVar( 'file_size', $upload->getFileSize() );

		// UploadBase makes it absolutely impossible to get these out of it, even though it knows them.
		$props = FSFile::getPropsFromPath( $upload->getTempPath() );
		$vars->setVar( 'file_mime', $props['mime'] );
		$vars->setVar( 'file_mediatype', MimeMagic::singleton()->getMediaType( null, $props['mime'] ) );
		$vars->setVar( 'file_width', $props['width'] );
		$vars->setVar( 'file_height', $props['height'] );
		$vars->setVar( 'file_bits_per_channel', $props['bits'] );

		$filter_result = AbuseFilter::filterAction( $vars, $title );

		if ( !$filter_result->isOK() ) {
			$error = $filter_result->getErrorsArray();
			$error = $error[0];
		}

		return $filter_result->isOK();
	}

	/**
	 * Adds global variables to the Javascript as needed
	 *
	 * @param array $vars
	 * @return bool
	 */
	public static function onMakeGlobalVariablesScript( array &$vars ) {
		if ( isset( AbuseFilter::$editboxName ) && AbuseFilter::$editboxName !== null ) {
			$vars['abuseFilterBoxName'] = AbuseFilter::$editboxName;
		}

		if ( AbuseFilterViewExamine::$examineType !== null ) {
			$vars['abuseFilterExamine'] = array(
				'type' => AbuseFilterViewExamine::$examineType,
				'id' => AbuseFilterViewExamine::$examineId,
			);
		}

		return true;
	}

	/**
	 * Tables that Extension:UserMerge needs to update
	 *
	 * @param array $updateFields
	 * @return bool
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = array( 'abuse_filter', 'af_user', 'af_user_text' );
		$updateFields[] = array( 'abuse_filter_log', 'afl_user', 'afl_user_text' );
		$updateFields[] = array( 'abuse_filter_history', 'afh_user', 'afh_user_text' );

		return true;
	}

	/**
	 * Warms the cache for getLastPageAuthors() - T116557
	 *
	 * @param WikiPage $page
	 * @param Content $content
	 * @param ParserOutput $output
	 */
	public static function onParserOutputStashForEdit(
		WikiPage $page, Content $content, ParserOutput $output
	) {
		AFComputedVariable::getLastPageAuthors( $page->getTitle() );
	}

	/**
	 * Hook to add PHPUnit test cases.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UnitTestsList
	 *
	 * @param array $files
	 *
	 * @return bool
	 */
	public static function onUnitTestsList( array &$files ) {
		$testDir = __DIR__ . '/tests/phpunit';

		$files = array_merge(
			$files,
			glob( $testDir . '/*Test.php' )
		);

		return true;
	}
}
