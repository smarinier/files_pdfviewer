<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2014-2015 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_PDFViewer\Controller;

use OCA\Files_PDFViewer\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class DisplayController extends Controller {

	/**
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 */
	public function __construct(IRequest $request,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param bool $minmode
	 * @return TemplateResponse
	 */
	public function showPdfViewer(bool $minmode = false): TemplateResponse {
		$params = [
			'urlGenerator' => $this->urlGenerator,
			'minmode' => $minmode
		];
		$spreadMode = strtolower($this->appConfig->getAppValueString('spread_mode', 'none'));
		if (in_array($spreadMode, ['odd', 'even'])) {
			$params['spread_mode'] = $spreadMode;
		}

		$response = new TemplateResponse(Application::APP_ID, 'viewer', $params, 'blank');

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedChildSrcDomain('\'self\'');
		$policy->addAllowedFontDomain('data:');
		$policy->addAllowedImageDomain('*');
		// Needed for the ES5 compatible build of PDF.js
		$policy->allowEvalScript(true);
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
