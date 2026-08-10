<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Files_PDFViewer\Listeners;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\Files_PDFViewer\AppInfo\Application;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadViewer>
 */
class LoadViewerListener implements IEventListener {

	public function __construct(
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private ContentSecurityPolicyNonceManager $nonceManager,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadViewer) {
			return;
		}
		Util::addScript(Application::APP_ID, 'files_pdfviewer-main', 'viewer');
		$page = $this->request->getParam('page');
		$nameddest = $this->request->getParam('nameddest');
		$params = [];
		if ($page !== null) {
			$params['page'] = (int)$page;
		}
		if ($nameddest !== null) {
			$params['nameddest'] = $nameddest;
		}
		if (empty($params)) {
			// no file parameter, don't add page script
			return;
		}
		Util::addHeader(
			'script',
			[
				'src' => $this->urlGenerator->linkToRoute('files_pdfviewer.JavaScript.start', $params),
				'nonce' => $this->nonceManager->getNonce(),
			], ''
		);
	}
}
