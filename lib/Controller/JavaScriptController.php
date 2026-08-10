<?php

namespace OCA\Files_PDFViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;

class JavaScriptController extends Controller {

	/**
	 * constructor of the controller
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param Config $config
	 */
	public function __construct($appName,
		IRequest $request) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return Response
	 */
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: 'js/start')]
	public function start(int $page = 0, ?string $nameddest = null): Response {
		$script = 'window.FilesPdfViewerPage = ' . $page . ";";

		if ($nameddest !== null) {
			$script .= 'window.FilesPdfViewerNamedDest = "' . base64_encode($nameddest) . '";';
		}

		return new DataDownloadResponse($script, 'start', 'text/javascript');
	}
}
