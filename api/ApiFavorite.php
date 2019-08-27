<?php

/**
 * API module to allow users to favorite a page
 *
 * @ingroup API
 */
class ApiFavorite extends ApiBase {

	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		$user = $this->getUser();
		if ( !$user->isLoggedIn() ) {
			$this->dieUsage( 'You must be logged-in to have a favoritelist', 'notloggedin' );
		}

		$params = $this->extractRequestParams();
		$title = Title::newFromText( $params['title'] );

		if ( !$title || $title->getNamespace() < 0 ) {
			$this->dieUsageMsg( array( 'invalidtitle', $params['title'] ) );
		}

		$res = array( 'title' => $title->getPrefixedText() );

		if ( $params['unfavorite'] ) {
			$res['unfavorited'] = '';
			$res['message'] = $this->msg( 'removedfavoritetext', $title->getPrefixedText() )->title( $title )->parseAsBlock();
			$success = new FavoriteAction('unfavorite',$title);
			//$success = UnfavoriteAction::doUnfavorite( $title, $user );
		} else {
			$res['favorited'] = '';
			$res['message'] = $this->msg( 'addedfavoritetext', $title->getPrefixedText() )->title( $title )->parseAsBlock();
			$success = new FavoriteAction('favorite',$title);
			//$success = FavAction::doFavorite( $title, $user );
		}
		if ( !$success ) {
			$this->dieUsageMsg( 'hookaborted' );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $res );

	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	// since this makes changes the database, we should use this, but I just can't get it to work.
 	//public function needsToken() {
 	//	return 'favorite';
 	//}

	//public function getTokenSalt() {
	//	return 'favorite';
	//}

	public function getAllowedParams() {
		return array(
			'title' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			),
			'unfavorite' => false,
 			//'token' => array(
 			//	ApiBase::PARAM_TYPE => 'string',
 			//	ApiBase::PARAM_REQUIRED => true
			//),
		);
	}

	public function getParamDescription() {
		return array(
			'title' => 'The page to (un)favorite',
			'unfavorite' => 'If set the page will be unfavorited rather than favorited',
			'token' => 'A token previously acquired via prop=info',
		);
	}

	public function getDescription() {
		return 'Add or remove a page from/to the current user\'s favoritelist';
	}

	public function getExamples() {
		return array(
			'api.php?action=favorite&title=Main_Page' => 'Favorite the page "Main Page"',
			'api.php?action=favorite&title=Main_Page&unfavorite=' => 'Unfavorite the page "Main Page"',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:Favorites';
	}

	public static function getTokenFav() {
		global $wgUser;

		return $wgUser->getEditToken( 'favorite' );
	}
	public static function getTokenUnfav() {
		global $wgUser;

		return $wgUser->getEditToken( 'unfavorite' );
	}

	public static function injectTokenFunction( &$list ) {
		$list['favorite'] = array( __CLASS__, 'getTokenFav' );
		$list['unfavorite'] = array( __CLASS__, 'getTokenUnfav' );
		return true; // Hooks must return bool
	}
}
