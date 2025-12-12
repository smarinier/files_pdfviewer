<?php

namespace OCA\Files_PDFViewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
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
	public function page(int $id) {
		$script = 'window.FilesPdfViewerPage = ' . $id . ";";

		return new DataDownloadResponse($script, 'page', 'text/javascript');
	}
}
