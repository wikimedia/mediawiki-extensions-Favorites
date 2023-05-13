<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

class FavoritesHooks {
	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModules( 'ext.favorites' );
		$out->addModules( 'ext.favorites.style' );
	}

	/**
	 * @param Parser &$parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setHook( 'favorites', [ __CLASS__, 'renderFavorites' ] );
	}

	/**
	 * @param string $input
	 * @param array $argv
	 * @param Parser $parser
	 * @return string
	 */
	public static function renderFavorites( $input, $argv, $parser ) {
		# The parser function itself
		# The input parameters are wikitext with templates expanded
		# The output should be wikitext too
		//$output = "Parser Output goes here.";

		$favParse = new FavParser();
		$output = $favParse->wfSpecialFavoritelist( $argv, $parser );
		$parser->getOutput()->updateCacheExpiry( 0 );
		return $output;
	}

	/**
	 * Creates the necessary database table when the user runs
	 * maintenance/update.php.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$file = __DIR__ . '/../sql/favorites.sql';
		$updater->addExtensionTable( 'favoritelist', $file );
	}

	/**
	 * @param Title &$title
	 * @param Title &$nt
	 * @param User $user
	 * @param int $pageid
	 * @param int $redirid
	 * @return bool
	 */
	public static function onTitleMoveComplete( &$title, &$nt, $user, $pageid, $redirid ) {
		# Update watchlists
		$oldnamespace = $title->getNamespace() & ~1;
		$newnamespace = $nt->getNamespace() & ~1;
		$oldtitle = $title->getDBkey();
		$newtitle = $nt->getDBkey();

		if ( $oldnamespace != $newnamespace || $oldtitle != $newtitle ) {
			Favorites::duplicateEntries( $title, $nt );
		}
		return true;
	}

	/**
	 * @param WikiPage &$article
	 * @param User &$user
	 * @param string $reason
	 * @param int $id
	 * @return bool
	 */
	public static function onArticleDeleteComplete( &$article, &$user, $reason, $id ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->delete( 'favoritelist', [
				'fl_namespace' => $article->getTitle()->getNamespace(),
				'fl_title' => $article->getTitle()->getDBKey() ],
				__METHOD__ );
		return true;
	}

	/**
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 * @return bool
	 */
	public static function onSkinTemplateNavigation__Universal( $sktemplate, &$links ) {
		global $wgFavoritesPersonalURL;

		if ( $wgFavoritesPersonalURL && $sktemplate->getUser()->isRegistered() ) {
			$personal_urls = &$links['user-menu'];
			$url[] = [
				'text' => $sktemplate->msg( 'myfavoritelist' )->text(),
				'href' => SpecialPage::getTitleFor( 'Favoritelist' )->getLocalURL()
			];
			$personal_urls = wfArrayInsertAfter( $personal_urls, $url, 'watchlist' );
		}

		$favClass = new Favorites;
		$favClass->favoritesLinks( $sktemplate, $links );

		return true;
	}
}
