<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class FavParser {

	/**
	 * @param array $argv
	 * @param Parser $parser
	 * @return string
	 */
	public function wfSpecialFavoritelist( $argv, $parser ) {
		$output = '';

		$specialTitle = SpecialPage::getTitleFor( 'Favoritelist' );
		$mTitle = $parser->getTitle();

		if ( $mTitle->getNamespace() == NS_USER && array_key_exists( 'userpage', $argv ) && $argv['userpage'] ) {
			$parts = explode( '/', $mTitle->getText() );
			$rootPart = $parts[0];
			$user = User::newFromName( $rootPart, true /* don't allow IP users*/ );
			// echo "Userpage: $user";
			$output = $this->viewFavList( $user, $output );

			$output .= $this->editlink( $argv );
			return $output;
		} else {
			$user = $parser->getUserIdentity();
		}

		# Anons don't get a favoritelist
		if ( !$user->isRegistered() ) {
			$llink = MediaWikiServices::getInstance()->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Userlogin' ),
				wfMessage( 'loginreqlink' )->text(),
				[],
				[ 'returnto' => $specialTitle->getPrefixedText() ]
			);

			return wfMessage( 'favoritelistanontext' )->rawParams( $llink )->escaped();

		}

		$output = $this->viewFavList( $user, $output );
		$output .= $this->editlink( $argv );

		return $output;
	}

	/**
	 * @param UserIdentity $user
	 * @param OutputPage $output
	 * @return string
	 */
	private function viewFavList( $user, $output ) {
		$output = $this->showNormalForm( $output, $user );

		return $output;
	}

	/**
	 * Does the user want to display an editlink?
	 *
	 * @param array $argv Array of values from the parser
	 * @return string
	 */
	private function editlink( $argv ) {
		$output = '';
		if ( array_key_exists( 'editlink', $argv ) && $argv['editlink'] ) {
			# Add an edit link if you want it:
			$output = "<div id='contentSub'><br>" .
				MediaWikiServices::getInstance()->getLinkRenderer()->makeLink(
					SpecialPage::getTitleFor( 'Favoritelist', 'edit' ),
					wfMessage( "favoritelisttools-edit" )->text()
				) . "</div>";
		}
		return $output;
	}

	/**
	 * Count the number of titles on a user's favoritelist, excluding talk pages
	 *
	 * @param UserIdentity $user
	 * @return int
	 */
	private function countFavoritelist( $user ) {
		$dbr = wfGetDB( DB_PRIMARY );
		$row = $dbr->selectRow( 'favoritelist', 'COUNT(fl_user) AS count', [ 'fl_user' => $user->getId() ], __METHOD__ );
		return ceil( $row->count );
	}

	/**
	 * Get a list of titles on a user's favoritelist, excluding talk pages,
	 * and return as a two-dimensional array with namespace, title and
	 * redirect status
	 *
	 * @param UserIdentity $user
	 * @return array
	 */
	private function getFavoritelistInfo( $user ) {
		$titles = [];
		$dbr = wfGetDB( DB_PRIMARY );
		$uid = intval( $user->getId() );
		list( $favoritelist, $page ) = $dbr->tableNamesN( 'favoritelist', 'page' );
		$sql = "SELECT fl_namespace, fl_title, page_id, page_len, page_is_redirect
			FROM {$favoritelist} LEFT JOIN {$page} ON ( fl_namespace = page_namespace
			AND fl_title = page_title ) WHERE fl_user = {$uid}";
		$res = $dbr->query( $sql, __METHOD__ );
		if ( $res->numRows() > 0 ) {
			$cache = MediaWikiServices::getInstance()->getLinkCache();
			foreach ( $res as $row ) {
				$title = Title::makeTitleSafe( $row->fl_namespace, $row->fl_title );
				if ( $title instanceof Title ) {
					// Update the link cache while we're at it
					if ( $row->page_id ) {
						$cache->addGoodLinkObj( $row->page_id, $title, $row->page_len, $row->page_is_redirect );
					} else {
						$cache->addBadLinkObj( $title );
					}
					// Ignore non-talk
					if ( !$title->isTalkPage() ) {
						$titles[$row->fl_namespace][$row->fl_title] = $row->page_is_redirect;
					}
				}
			}
		}
		return $titles;
	}

	/**
	 * Show the standard favoritelist
	 *
	 * @param OutputPage $output
	 * @param UserIdentity $user
	 * @return string
	 */
	private function showNormalForm( $output, $user ) {
		if ( $this->countFavoritelist( $user ) > 0 ) {
			$form = $this->buildRemoveList( $user );
			$output .= $form;
			return $output;
		} else {
			return wfMessage( 'nofavoritelist' )->text();
		}
	}

	/**
	 * Build part of the standard favoritelist
	 *
	 * @param UserIdentity $user
	 * @return string
	 */
	private function buildRemoveList( $user ) {
		$list = "";
		$list .= "<ul>\n";
		foreach ( $this->getFavoritelistInfo( $user ) as $namespace => $pages ) {
			foreach ( $pages as $dbkey => $redirect ) {
				$title = Title::makeTitleSafe( $namespace, $dbkey );
				$list .= $this->buildRemoveLine( $title, $redirect );
			}
		}
		$list .= "</ul>\n";
		return $list;
	}

	/**
	 * Build a single list item containing a link
	 *
	 * @param Title $title
	 * @param bool $redirect
	 * @return string
	 */
	private function buildRemoveLine( $title, $redirect ) {
		$link = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( $title );
		if ( $redirect ) {
			$link = '<span class="favoritelistredir">' . $link . '</span>';
		}

		return "<li>" . $link . "</li>\n";
	}

}
