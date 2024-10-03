<?php
/**
 * @file
 * @ingroup SpecialPage Favoritelist
 */

use MediaWiki\MediaWikiServices;

/**
 * Constructor
 *
 * @param $par Parameter
 *        	passed to the page
 */
class SpecialFavoritelist extends SpecialPage {

	public function __construct() {
		parent::__construct( 'Favoritelist' );
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$vwfav = new ViewFavorites( $this->getContext(), $this->getLinkRenderer() );

		$this->setHeaders();
		$param = $this->getRequest()->getText( 'param' );

		$vwfav->wfSpecialFavoritelist( $par );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}

class ViewFavorites {

	/** @var User */
	private $user;
	/** @var OutputPage */
	private $out;
	/** @var WebRequest */
	private $request;
	/** @var Language */
	private $lang;
	/** @var LinkRenderer */
	private $mLinkRenderer;

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( $context, $linkRenderer ) {
		$this->out = $context->getOutput();
		$this->request = $context->getRequest();
		$this->lang = $context->getLanguage();
		$this->user = $context->getUser();
		$this->mLinkRenderer = $linkRenderer;
	}

	/**
	 * @param string|null $par
	 */
	public function wfSpecialFavoritelist( $par ) {
		global $wgFeedClasses;

		// Add feed links
		$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
		$flToken = $userOptionsManager->getOption( $this->user, 'favoritelisttoken' );
		if ( !$flToken ) {
			$flToken = sha1( mt_rand() . microtime( true ) );
			$userOptionsManager->setOption( $this->user, 'favoritelisttoken', $flToken );
			$userOptionsManager->saveOptions( $this->user );
		}

		$apiParams = [
				'action' => 'feedfavoritelist',
				'allrev' => 'allrev',
				'flowner' => $this->user->getName(),
				'fltoken' => $flToken
		];
		$feedTemplate = wfScript( 'api' ) . '?';

		foreach ( $wgFeedClasses as $format => $class ) {
			$theseParams = $apiParams + [
					'feedformat' => $format
			];
			$url = $feedTemplate . wfArrayToCGI( $theseParams );
			$this->out->addFeedLink( $format, $url );
		}

		$specialTitle = SpecialPage::getTitleFor( 'Favoritelist' );
		$this->out->setRobotPolicy( 'noindex,nofollow' );

		// Anons don't get a favoritelist
		if ( $this->user->isAnon() ) {
			$this->out->setPageTitle( wfMessage( 'favoritenologin' ) );
			$llink = $this->mLinkRenderer->makeLink(
				SpecialPage::getTitleFor( 'Userlogin' ),
				wfMessage( 'loginreqlink' )->text(),
				[],
				[
					'returnto' => $specialTitle->getPrefixedText()
				]
			);
			$this->out->addHTML( wfMessage( 'favoritelistanontext', $llink )->text() );
			return;
		}

		$this->out->setPageTitle( wfMessage( 'favoritelist' ) );

		$sub = wfMessage( 'favoritelistfor', $this->user->getName() )->parse();
		$sub .= '<br />' . FavoritelistEditor::buildTools();
		$this->out->setSubtitle( $sub );

		if ( ( $mode = FavoritelistEditor::getMode( $this->request, $par ) ) !== false ) {
			$editor = new FavoritelistEditor();
			$editor->execute( $this->user, $this->out, $this->request, $mode );
			return;
		}

		$this->viewFavList( $this->user, $this->out, $this->request, $mode );
	}

	/**
	 * @param User $user
	 * @param OutputPage $output
	 * @param WebRequest $request
	 * @param int $mode
	 */
	private function viewFavList( $user, $output, $request, $mode ) {
		$uid = $this->user->getId();
		$output->setPageTitle( wfMessage( 'favoritelist' ) );

		if ( $request->wasPosted() && $this->checkToken( $request, $this->user ) ) {
			$titles = $this->extractTitles( $request->getArray( 'titles' ) );
			$this->unfavoriteTitles( $titles, $user );
			$user->invalidateCache();
			$output->addHTML( wfMessage( 'favoritelistedit-normal-done', $GLOBALS['wgLang']->formatNum( count( $titles ) ) )->parse() );
			$this->showTitles( $titles, $output );
		}
		$this->showNormalForm( $output, $user );

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		// $recentchanges = $dbr->tableName( 'recentchanges' );

		$favoritelistCount = $dbr->selectField( 'favoritelist', 'COUNT(fl_user)', [
				'fl_user' => $uid
		], __METHOD__ );
		// Adjust for page X, talk:page X, which are both stored separately,
		// but treated together
		// $nitems = floor($favoritelistCount / 2);
		$nitems = $favoritelistCount;
		if ( $nitems == 0 ) {
			$this->out->addWikiMsg( 'nofavoritelist' );
			return;
		}
	}

	/**
	 * Check the edit token from a form submission
	 *
	 * @param WebRequest $request
	 * @param User $user
	 * @return bool
	 */
	private function checkToken( $request, $user ) {
		return $user->matchEditToken( $request->getVal( 'token' ), 'favorite' );
	}

	/**
	 * Extract a list of titles from a blob of text, returning
	 * (prefixed) strings; unfavoritable titles are ignored
	 *
	 * @param string[]|string $list
	 * @return string[]
	 */
	private function extractTitles( $list ) {
		$titles = [];
		if ( !is_array( $list ) ) {
			$list = explode( "\n", trim( $list ) );
			if ( !is_array( $list ) ) {
				return [];
			}
		}
		foreach ( $list as $text ) {
			$text = trim( $text );
			if ( strlen( $text ) > 0 ) {
				$title = Title::newFromText( $text );
				// if( $title instanceof Title && $title->isFavoritable() )
				$titles[] = $title->getPrefixedText();
			}
		}
		return array_unique( $titles );
	}

	/**
	 * Print out a list of linked titles
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * @param string[]|Title[] $titles
	 * @param OutputPage $output
	 */
	private function showTitles( $titles, $output ) {
		$talk = wfMessage( 'talkpagelinktext' )->text();
		// Do a batch existence check
		$batch = MediaWikiServices::getInstance()->getLinkBatchFactory()->newLinkBatch();
		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}
			// if( $title instanceof Title ) {
			// 	$batch->addObj( $title );
			// 	if ( $title->canHaveTalkPage() ) {
			// 		$batch->addObj( $title->getTalkPage() );
			// 	}
			// }
		}
		$batch->execute();
		// Print out the list
		$output->addHTML( "<ul>\n" );
		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}
			if ( $title instanceof Title ) {
				$output->addHTML( "<li>" . $this->mLinkRenderer->makeLink( $title ) . "</li>\n" );
			}
		}
		$output->addHTML( "</ul>\n" );
	}

	/**
	 * Count the number of titles on a user's favoritelist, excluding talk pages
	 *
	 * @param User $user
	 * @return int
	 */
	private function countFavoritelist( $user ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$row = $dbr->selectRow( 'favoritelist', 'COUNT(fl_user) AS count', [
				'fl_user' => $user->getId()
		], __METHOD__ );
		return ceil( $row->count ); // Paranoia
	}

	/**
	 * Get a list of titles on a user's favoritelist, excluding talk pages,
	 * and return as a two-dimensional array with namespace, title and
	 * redirect status
	 *
	 * @param User $user
	 * @return array
	 */
	private function getFavoritelistInfo( $user ) {
		$titles = [];
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$uid = intval( $user->getId() );

		$res = $dbr->newSelectQueryBuilder()
			->select( 'fl_namespace', 'fl_title', 'page_id', 'page_len', 'page_is_redirect' )
			->from( 'favoritelist' )
			->leftJoin( 'page', null, 'fl_namespace = page_namespace AND fl_title = page_title' )
			->where( [ 'fl_user' => $uid ] )
			->fetchResultSet();

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
	 * Remove a list of titles from a user's favoritelist
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * @param string[]|Title[] $titles
	 * @param User $user
	 */
	private function unfavoriteTitles( $titles, $user ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();

		foreach ( $titles as $title ) {
			if ( !$title instanceof Title ) {
				$title = Title::newFromText( $title );
			}
			if ( $title instanceof Title ) {
				$dbw->delete( 'favoritelist', [
						'fl_user' => $user->getId(),
						'fl_namespace' => ( $title->getNamespace() | 1 ),
						'fl_title' => $title->getDBkey()
				], __METHOD__ );
				$article = new Article( $title );
				$hookContainer->run( 'UnfavoriteArticleComplete', [
						&$user,
						&$article
				] );
			}
		}
	}

	/**
	 * Show the standard favoritelist editing form
	 *
	 * @param OutputPage $output
	 * @param User $user
	 */
	private function showNormalForm( $output, $user ) {
		if ( ( $count = $this->countFavoritelist( $user ) ) > 0 ) {
			$self = SpecialPage::getTitleFor( 'Favoritelist' );
			$form = Xml::openElement( 'form', [
					'method' => 'post',
					'action' => $self->getLocalUrl( [
							'action' => 'edit'
					] )
			] );
			$form .= Html::hidden( 'token', $this->user->getEditToken( 'favorite' ) );
			$form .= $this->buildRemoveList( $user );
			$form .= '</fieldset></form>';
			$output->addHTML( $form );
		}
	}

	/**
	 * Build the part of the standard favoritelist editing form with the actual
	 * title selection checkboxes and stuff.
	 * Also generates a table of
	 * contents if there's more than one heading.
	 *
	 * @param User $user
	 * @return string
	 */
	private function buildRemoveList( $user ) {
		$list = "";
		$toc = Linker::tocIndent();
		$tocLength = 0;
		foreach ( $this->getFavoritelistInfo( $user ) as $namespace => $pages ) {
			$tocLength++;
			$heading = htmlspecialchars( $this->getNamespaceHeading( $namespace ) );
			$anchor = "editfavoritelist-ns" . $namespace;

			$list .= Linker::makeHeadLine( 2, ">", $anchor, $heading, "" );
			$toc .= Linker::tocLine( $anchor, $heading, $tocLength, 1 ) . Linker::tocLineEnd();

			$list .= "<ul>\n";
			foreach ( $pages as $dbkey => $redirect ) {
				$title = Title::makeTitleSafe( $namespace, $dbkey );
				$list .= $this->buildRemoveLine( $title, $redirect );
			}
			$list .= "</ul>\n";
		}
		// ISSUE: omit the TOC if the total number of titles is low?
		if ( $tocLength > 10 ) {
			$list = Linker::tocList( $toc ) . $list;
		}

		return $list;
	}

	/**
	 * Get the correct "heading" for a namespace
	 *
	 * @param int $namespace
	 * @return string
	 */
	private function getNamespaceHeading( $namespace ) {
		return $namespace == NS_MAIN
			? wfMessage( 'blanknamespace' )->text()
			: htmlspecialchars( MediaWikiServices::getInstance()->getContentLanguage()->getFormattedNsText( $namespace ) );
	}

	/**
	 * Build a single list item containing a check box selecting a title
	 * and a link to that title, with various additional bits
	 *
	 * @param Title $title
	 * @param bool $redirect
	 * @return string
	 */
	private function buildRemoveLine( $title, $redirect ) {
		// In case the user adds something unusual to their list using the raw editor
		// We moved the Tools array completely into the "if( $title->exists() )" section.
		$showlinks = false;
		$link = $this->mLinkRenderer->makeLink( $title );
		if ( $redirect ) {
			$link = '<span class="favoritelistredir">' . $link . '</span>';
		}

		if ( $title->exists() ) {
			$showlinks = true;
			if ( $title->canHaveTalkPage() ) {
				$tools[] = $this->mLinkRenderer->makeLink( $title->getTalkPage(), wfMessage( 'talkpagelinktext' )->text() );
			}
			$tools[] = $this->mLinkRenderer->makeLink(
				$title,
				wfMessage( 'history_short' )->text(),
				[],
				[ 'action' => 'history' ]
			);
		}
		if ( $title->getNamespace() == NS_USER && !$title->isSubpage() ) {
			$tools[] = $this->mLinkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'Contributions', $title->getText() ),
				wfMessage( 'contributions' )->text()
			);
		}

		if ( $showlinks ) {
			return "<li>" . $link . " (" . $this->lang->pipeList( $tools ) . ")" . "</li>\n";
		} else {
			return "<li>" . $link . "</li>\n";
		}
	}
}
