<?php

class AbuseFilterExaminePager extends ReverseChronologicalPager {
	/**
	 * @param AbuseFilterViewExamine $page
	 * @param AbuseFilterChangesList $changesList
	 */
	function __construct( $page, $changesList ) {
		parent::__construct();
		$this->mChangesList = $changesList;
		$this->mPage = $page;
	}

	/**
	 * @fixme this is similar to AbuseFilterViewTestBatch::doTest
	 * @return array
	 */
	function getQueryInfo() {
		$dbr = wfGetDB( DB_REPLICA );
		$conds = [];
		$conds['rc_user_text'] = $this->mPage->mSearchUser;

		$startTS = strtotime( $this->mPage->mSearchPeriodStart );
		if ( $startTS ) {
			$conds[] = 'rc_timestamp>=' . $dbr->addQuotes( $dbr->timestamp( $startTS ) );
		}
		$endTS = strtotime( $this->mPage->mSearchPeriodEnd );
		if ( $endTS ) {
			$conds[] = 'rc_timestamp<=' . $dbr->addQuotes( $dbr->timestamp( $endTS ) );
		}

		$conds[] = $this->mPage->buildTestConditions( $dbr );

		$rcQuery = RecentChange::getQueryInfo();
		$info = [
			'tables' => $rcQuery['tables'],
			'fields' => $rcQuery['fields'],
			'conds' => array_filter( $conds ),
			'options' => [ 'ORDER BY' => 'rc_timestamp DESC' ],
			'join_conds' => $rcQuery['joins'],
		];

		return $info;
	}

	function formatRow( $row ) {
		# Incompatible stuff.
		$rc = RecentChange::newFromRow( $row );
		$rc->counter = $this->mPage->mCounter++;
		return $this->mChangesList->recentChangesLine( $rc, false );
	}

	function getIndexField() {
		return 'rc_id';
	}

	function getTitle() {
		return $this->mPage->getTitle( 'examine' );
	}

	function getEmptyBody() {
		return $this->msg( 'abusefilter-examine-noresults' )->parseAsBlock();
	}
}
