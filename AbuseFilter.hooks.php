<?php

class AbuseFilterHooks {
	static $successful_action_vars = false;
	/** @var WikiPage|Article|bool */
	static $last_edit_page = false; // make sure edit filter & edit save hooks match
	// So far, all of the error message out-params for these hooks accept HTML.
	// Hooray!

	/**
	 * Entry point for the APIEditBeforeSave hook.
	 * This is needed to give a useful error for API edits (Bug 32216)
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/APIEditBeforeSave
	 *
	 * @param EditPage $editPage
	 * @param string $text New text of the article (has yet to be saved)
	 * @param array &$resultArr Data in this array will be added to the API result
	 *
	 * @return bool
	 */
	public static function onAPIEditBeforeSave( $editPage, $text, &$result ) {
		global $wgUser;

		$context = $editPage->mArticle->getContext();

		$status = Status::newGood();
		$minoredit = $editPage->minoredit;
		$summary = $editPage->summary;

		// poor man's PST, see bug 20310
		$text = str_replace( "\r\n", "\n", $text );

		$continue = self::filterEdit( $context, null, $text, $status, $summary, $wgUser, $minoredit );

		if ( !$status->isOK() ) {
			$msg = $status->getErrorsArray();

			// Use the error message key name as error code, the first parameter is the filter description.
			if ( $msg[0] instanceof Message ) {
				// For forward compatibility: In case we switch over towards using Message objects someday.
				// (see the todo for AbuseFilter::buildStatus)
				$code = $msg[0]->getKey();
				$filterDescription = $msg[0]->getParams();
				$filterDescription = $filterDescription[0];
			} else {
				$code = $msg[0][0];
				$filterDescription = $msg[0][1];
			}

			$result = array(
				'code' => $code,
				'info' => 'Hit AbuseFilter: ' . $filterDescription,
				'warning' => $status->getHTML()
			);
		}

		return $status->isOK();
	}

	/**
	 * Entry points for MediaWiki hook 'EditFilterMerged' (MW 1.20 and earlier)
	 *
	 * @param $editor EditPage instance (object)
	 * @param $text string Content of the edit box
	 * @param &$error string Error message to return
	 * @param $summary string Edit summary for page
	 * @return bool
	 */
	public static function onEditFilterMerged( $editor, $text, &$error, $summary ) {
		global $wgUser;

		$context = $editor->mArticle->getContext();

		$status = Status::newGood();
		$minoredit = $editor->minoredit;

		// poor man's PST, see bug 20310
		$text = str_replace( "\r\n", "\n", $text );

		$continue = self::filterEdit( $context, null, $text, $status, $summary, $wgUser, $minoredit );

		if ( !$status->isOK() ) {
			$error = $status->getWikiText();
		}

		return $continue;
	}

	/**
	 * Entry points for MediaWiki hook 'EditFilterMergedContent' (MW 1.21 and later)
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content $content the new Content generated by the edit
	 * @param Status $status  Error message to return
	 * @param string $summary Edit summary for page
	 * @param User $user the user performing the edit
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 *
	 * @return bool
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit ) {

		$text = AbuseFilter::contentToString( $content, Revision::RAW );

		$continue = self::filterEdit( $context, $content, $text, $status, $summary, $user, $minoredit );
		return $continue;
	}

	/**
	 * Common implementation for the APIEditBeforeSave, EditFilterMerged
	 * and EditFilterMergedContent hooks.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content|null $content the new Content generated by the edit
	 * @param string $text new page content (subject of filtering)
	 * @param Status $status  Error message to return
	 * @param string $summary Edit summary for page
	 * @param User $user the user performing the edit
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 *
	 * @return bool
	 */
	public static function filterEdit( IContextSource $context, $content, $text,
				Status $status, $summary, User $user, $minoredit ) {
		// Load vars
		$vars = new AbuseFilterVariableHolder();

		$title = $context->getTitle();

		// Some edits are running through multiple hooks, but we only want to filter them once
		if ( isset( $title->editAlreadyFiltered ) ) {
			return true;
		}
		$title->editAlreadyFiltered = true;

		self::$successful_action_vars = false;
		self::$last_edit_page = false;

		// Check for null edits.
		$oldtext = '';
		$oldcontent = null;

		if ( ( $title instanceof Title ) && $title->canExist() && $title->exists() ) {
			// Make sure we load the latest text saved in database (bug 31656)
			$page = $context->getWikiPage();
			$revision = $page->getRevision();
			if ( !$revision ) {
				return true;
			}

			if ( defined( 'MW_SUPPORTS_CONTENTHANDLER' ) ) {
				$oldcontent = $revision->getContent( Revision::RAW );
				$oldtext = AbuseFilter::contentToString( $oldcontent );
			} else {
				$oldtext = AbuseFilter::revisionToString( $revision, Revision::RAW );
			}

			// Cache article object so we can share a parse operation
			$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
			AFComputedVariable::$articleCache[$articleCacheKey] = $page;
		} else {
			$page = null;
		}

		// Don't trigger for null edits.
		if ( $content && $oldcontent && $oldcontent->equals( $content ) ) {
			// Compare Content objects if available
			return true;
		} else if ( strcmp( $oldtext, $text ) == 0 ) {
			// Otherwise, compare strings
			return true;
		}

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title , 'ARTICLE' )
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

	public static function onArticleSaveComplete(
		&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor,
		&$flags, $revision
	) {
		if ( ! self::$successful_action_vars || ! $revision ) {
			self::$successful_action_vars = false;
			return true;
		}

		$vars = self::$successful_action_vars;

		if ( $vars->getVar('article_prefixedtext')->toString() !==
			$article->getTitle()->getPrefixedText()
		) {
			return true;
		}

		if ( !self::identicalPageObjects( $article, self::$last_edit_page ) ) {
			return true; // this isn't the edit $successful_action_vars was set for
		}
		self::$last_edit_page = false;

		if ( $vars->getVar('local_log_ids') ) {
			// Now actually do our storage
			$log_ids = $vars->getVar('local_log_ids')->toNative();
			$dbw = wfGetDB( DB_MASTER );

			if ( count($log_ids) ) {
				$dbw->update( 'abuse_filter_log',
					array( 'afl_rev_id' => $revision->getId() ),
					array( 'afl_id' => $log_ids ),
					__METHOD__
				);
			}
		}

		if ( $vars->getVar('global_log_ids') ) {
			$log_ids = $vars->getVar('global_log_ids')->toNative();

			global $wgAbuseFilterCentralDB;
			$fdb = wfGetDB( DB_MASTER, array(), $wgAbuseFilterCentralDB );

			if ( count($log_ids) ) {
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
		if ( 	( class_exists('MWInit') && MWInit::methodExists( 'Article', 'getPage' ) ) ||
			( !class_exists('MWInit') && method_exists('Article', 'getPage') )
		) {
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
		global $wgMemc;

		$key = AbuseFilter::autoPromoteBlockKey( $user );

		if ( $wgMemc->get( $key ) ) {
			$promote = array();
		}

		return true;
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
		$vars = new AbuseFilterVariableHolder;

		global $wgUser;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $wgUser ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
		);
		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'move' );

		$filter_result = AbuseFilter::filterAction( $vars, $oldTitle );

		$error = $filter_result->isOK() ? '' : $filter_result->getWikiText();
		return $filter_result->isOK();
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
		$error = $filter_result->isOK() ? '' : $filter_result->getWikiText();

		return $filter_result->isOK();
	}

	/**
	 * @param $user User
	 * @param $message
	 * @param $autocreate bool Indicates whether the account is created automatically.
	 * @return bool
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
		if ( $wgUser->getId() ) {
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
	 */
	public static function onAbortNewAccount( $user, &$message ) {
		return self::checkNewAccount( $user, $message, false );
	}

	/**
	 * @param $user User
	 * @param $message
	 * @return bool
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
			&& count( $tags = AbuseFilter::$tagsToSet[$actionID] ) )
		{
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
	 * @param $emptyTags array
	 * @return bool
	 */
	public static function onListDefinedTags( &$emptyTags ) {
		# This is a pretty awful hack.
		$dbr = wfGetDB( DB_SLAVE );

		$res = $dbr->select(
			array( 'abuse_filter_action', 'abuse_filter' ),
			'afa_parameters',
			array( 'afa_consequence' => 'tag', 'af_enabled' => true ),
			__METHOD__,
			array(),
			array( 'abuse_filter' => array( 'INNER JOIN', 'afa_filter=af_id' ) )
		);

		foreach ( $res as $row ) {
			$emptyTags = array_filter(
				array_merge( explode( "\n", $row->afa_parameters ), $emptyTags )
			);
		}

		return true;
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
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter', "$dir/abusefilter.tables.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter', "$dir/abusefilter.tables.sqlite.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.sqlite.sql", true ) );
			}
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter_history', 'afh_changed_fields', "$dir/db_patches/patch-afh_changed_fields.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_deleted', "$dir/db_patches/patch-af_deleted.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_actions', "$dir/db_patches/patch-af_actions.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_global', "$dir/db_patches/patch-global_filters.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter_log', 'afl_rev_id', "$dir/db_patches/patch-afl_action_id.sql", true ) );
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'filter_timestamp', "$dir/db_patches/patch-fix-indexes.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'afl_filter_timestamp', "$dir/db_patches/patch-fix-indexes.sqlite.sql", true ) );
			}

			$updater->addExtensionUpdate( array('addField', 'abuse_filter', 'af_group', "$dir/db_patches/patch-af_group.sql", true ) );

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'wiki_timestamp', "$dir/db_patches/patch-global_logging_wiki-index.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'afl_wiki_timestamp', "$dir/db_patches/patch-global_logging_wiki-index.sqlite.sql", true ) );
			}

		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter', "$dir/abusefilter.tables.pg.sql", true ) );
			$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.pg.sql", true ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter', 'af_actions', "TEXT NOT NULL DEFAULT ''" ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter', 'af_deleted', 'SMALLINT NOT NULL DEFAULT 0' ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter', 'af_global', 'SMALLINT NOT NULL DEFAULT 0' ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter_log', 'afl_wiki', 'TEXT' ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter_log', 'afl_deleted', 'SMALLINT' ) );
			$updater->addExtensionUpdate( array( 'changeField', 'abuse_filter_log', 'afl_filter', 'TEXT', '' ) );
			$updater->addExtensionUpdate( array( 'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_ip', "(afl_ip)" ) );
			$updater->addExtensionUpdate( array( 'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_wiki', "(afl_wiki)" ) );
		}

		$updater->addExtensionUpdate( array( array( __CLASS__, 'createAbuseFilterUser' ) ) );

		return true;
	}

	/**
	 * Updater callback to create the AbuseFilter user after the user tables have been updated.
	 * @param $updater DatabaseUpdater
	 */
	public static function createAbuseFilterUser( $updater ) {
		$user = User::newFromName( wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() );

		if ( $user && !$updater->updateRowExists( 'create abusefilter-blocker-user' ) ) {
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
		global $wgUser, $wgVersion;

		$vars = new AbuseFilterVariableHolder;
		$title = $upload->getTitle();

		if ( !$title ) {
			// If there's no valid title assigned to the upload
			// it wont proceed anyway, so no point in filtering it.
			return true;
		}

		$vars->addHolders(
			AbuseFilter::generateUserVars( $wgUser ),
			AbuseFilter::generateTitleVars( $title, 'FILE' )
		);

		$vars->setVar( 'ACTION', 'upload' );

		// We us the hexadecimal version of the file sha1
		if ( version_compare( $wgVersion, '1.21', '>=' ) ) {
			// Use UploadBase::getTempFileSha1Base36 so that we don't have to calculate the sha1 sum again
			$sha1 = wfBaseConvert( $upload->getTempFileSha1Base36() , 36, 16, 40 );
		} else {
			// UploadBase::getTempFileSha1Base36 wasn't public until 1.21
			$sha1 = sha1_file( $upload->getTempPath() );
		}

		$vars->setVar( 'file_sha1', $sha1 );

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
		if ( AbuseFilter::$editboxName !== null ) {
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
