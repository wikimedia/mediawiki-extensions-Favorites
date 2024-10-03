<?php

use MediaWiki\MediaWikiServices;

class Favorites {
	/** @var User */
	private $user;

	/**
	 * @param SkinTemplate &$sktemplate
	 * @param array &$links
	 * @return bool
	 */
	public function favoritesLinks( &$sktemplate, &$links ) {
		global $wgUseIconFavorite;
		// $context = $sktemplate->getContext();
		// $wgUseIconFavorite = true;
		$this->user = $user = $sktemplate->getUser();
		if ( $user->isAnon() ) {
			// do nothing
			return false;
		}

		$title = $sktemplate->getTitle();

		// See if this object even exists - if the user can't read it, the object doesn't get created.
		if ( is_object( $title ) && $title->canExist() ) {
			$ns = $title->getNamespace();
			$titleKey = $title->getDBkey();
		} else {
			return false;
		}
		$mode = $this->inFavorites( $ns, $titleKey ) ? 'unfavorite' : 'favorite';
		if ( $wgUseIconFavorite ) {
			$class = 'icon ';
			$place = 'views';
			$text = '';
		} else {
			$class = '';
			$text = $sktemplate->msg( $mode )->text();
			$place = 'actions';
		}

		$token = $this->getFavoriteToken( $title, $user, $mode );

		// from streams:
		// $fields .= Xml::input ( 'touserid', false, false, array (
		// 'type' => 'text',
		// 'id' => 'touserid',
		// 'placeholder' => wfMessage ( 'streams-push-placeholder' )->plain ()
		// ) );
		// $fields .= "<a href='javascript:streamsPush.check_empty()' id='submit' >" . wfMessage ( 'streams-push-send' )->plain () . "</a>";
		// $formAttribs = array (
		// 'action' => 'javascript:streamsPush.check_empty();',
		// 'id' => 'form',
		// 'method' => 'post',
		// 'name' => 'pushform'
		// );

		$links[$place][$mode] = [
				'class' => $class,
				'text' => $text,
				// uses 'favorite' or 'unfavorite' message
				// 'href' => $this->getTitle()->getLocalURL( array( 'action' => $mode) )
				// 'href' => $favTitle->getLocalUrl( 'action=' . $mode )
				'href' => $title->getLocalURL( [
						'action' => $mode,
						'token' => $token
				] )
		];

		return false;
	}

	/**
	 * Is this item in the user's favorite list?
	 *
	 * @param int $ns
	 * @param string $titleKey
	 * @return bool
	 */
	private function inFavorites( $ns, $titleKey ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select( 'favoritelist', 1, [
				'fl_user' => $this->user->getId(),
				'fl_namespace' => $ns,
				'fl_title' => $titleKey
		], __METHOD__ );
		$isfavorited = $res->numRows() > 0;
		return $isfavorited;
	}

	/**
	 * Get token to favorite (or unfavorite) a page for a user
	 *
	 * @param Title $title
	 *        	Title object of page to favorite
	 * @param User $user
	 *        	User for whom the action is going to be performed
	 * @param string $action
	 *        	Optionally override the action to 'unfavorite'
	 * @return string Token
	 */
	private function getFavoriteToken( Title $title, User $user, $action = 'favorite' ) {
		if ( $action != 'unfavorite' ) {
			$action = 'favorite';
		}
		$salt = [
				$action,
				$title->getDBkey()
		];

		// This token stronger salted and not compatible with ApiFavorite
		// It's title/action specific because index.php is GET and API is POST
		return $user->getEditToken( $salt );
	}

	/**
	 * Get token to unfavorite (or favorite) a page for a user
	 *
	 * @param Title $title
	 *        	Title object of page to unfavorite
	 * @param User $user
	 *        	User for whom the action is going to be performed
	 * @param string $action
	 *        	Optionally override the action to 'favorite'
	 * @return string Token
	 */
	private function getUnfavoriteToken( Title $title, User $user, $action = 'unfavorite' ) {
		return self::getFavoriteToken( $title, $user, $action );
	}

	/**
	 * Check if the given title already is favorited by the user, and if so
	 * add favorite on a new title.
	 * To be used for page renames and such.
	 *
	 * @param Title $ot
	 *        	Page title to duplicate entries from, if present
	 * @param Title $nt
	 *        	Page title to add favorite on
	 */
	public static function duplicateEntries( $ot, $nt ) {
		self::doDuplicateEntries( $ot->getSubjectPage(), $nt->getSubjectPage() );
	}

	/**
	 * Handle duplicate entries.
	 * Backend for duplicateEntries().
	 *
	 * @param Title $ot
	 * @param Title $nt
	 */
	private static function doDuplicateEntries( $ot, $nt ) {
		$oldnamespace = $ot->getNamespace();
		$newnamespace = $nt->getNamespace();
		$oldtitle = $ot->getDBkey();
		$newtitle = $nt->getDBkey();

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$res = $dbw->select( 'favoritelist', 'fl_user', [
				'fl_namespace' => $oldnamespace,
				'fl_title' => $oldtitle
		], __METHOD__, 'FOR UPDATE' );
		// Construct array to replace into the favoritelist
		$values = [];
		foreach ( $res as $s ) {
			$values[] = [
					'fl_user' => $s->fl_user,
					'fl_namespace' => $newnamespace,
					'fl_title' => $newtitle
			];
		}

		if ( empty( $values ) ) {
			// Nothing to do
			return;
		}

		// Perform replace
		// Note that multi-row replace is very efficient for MySQL but may be inefficient for
		// some other DBMSes, mostly due to poor simulation by us
		$dbw->replace( 'favoritelist', [
				[
						'fl_user',
						'fl_namespace',
						'fl_title'
				]
		], $values, __METHOD__ );

		// Delete the old item - we don't need to have the old page on the list of favorites.
		$dbw->delete( 'favoritelist', [
				'fl_namespace' => $oldnamespace,
				'fl_title' => $oldtitle
		], 'Database::delete' );
	}
}
