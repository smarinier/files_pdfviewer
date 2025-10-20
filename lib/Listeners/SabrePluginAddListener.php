<?php

namespace OCA\Files_PDFViewer\Listeners;

use OCP\IUserSession;
use OCP\Server;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\Event;
use OCA\Files_PDFViewer\DAV\ViewOnlyPlugin;
use OCP\Files\IRootFolder;

class SabrePluginAddListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$server = $event->getServer();
		$server->addPlugin(new ViewOnlyPlugin( $this->getUserFolder()));
	}

	/**
	 *
	 * @return \OCP\Files\Folder|null
	 */
	private function getUserFolder() {
		$user = Server::get(IUserSession::class)->getUser();
		if (!$user) {
			return null;
		}
		$rootFolder = Server::get(IRootFolder::class);
		$userId = $user->getUID();
		return $rootFolder->getUserFolder($userId);
	}
}
