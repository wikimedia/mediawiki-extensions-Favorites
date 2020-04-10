<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBConnRef;

abstract class BaseAction extends Action {
	public function show() {
		$user = $this->getUser();
		$out = $this->getOutput();

		$dbw = wfGetDB( DB_MASTER );
		$title = $this->getTitle();
		$subject =
			MediaWikiServices::getInstance()
				->getNamespaceInfo()
				->getSubject( $title->getNamespace() );

		if ( $this->doAction( $dbw, $subject, $user, $title ) ) {
			$out->addWikiMsg( $this->successMessage(), $title->getPrefixedText() );
			$user->invalidateCache();
		} else {
			$out->addWikiMsg( 'favoriteerrortext', $title->getPrefixedText() );
		}
	}

	abstract protected function successMessage();

	abstract protected function doAction( DBConnRef $dbw, int $subject, User $user, Title $title );
}
