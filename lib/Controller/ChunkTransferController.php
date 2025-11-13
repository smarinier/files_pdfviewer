<?php

namespace OCA\Files_PDFViewer\Controller;

use Exception;
use OCA\Files_PDFViewer\Helper as PDFViewerHelper;
use OCA\Files_PDFViewer\Service\TokenService;
use OCA\Files_PDFViewer\Service\TransferToken;
use OCA\Files_Versions\Versions\IVersionManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class ChunkTransferController extends Controller {

	public function __construct(
		$appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private TokenService $tokenService,
		private ISession $session,
		private ?string $userId,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private IShareManager $shareManager,
		private IEventDispatcher $eventDispatcher,
		private PDFViewerHelper $helper,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Build a transfer token to download the file in chunks
	 */
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: 'chunk/files/{fileId}/transfer')]
	public function getFileToken(string $fileId, ?string $shareToken = null, ?string $path = null, ?string $guestName = null): DataResponse {

		$userId = $this->userId;

		try {
			$share = $shareToken ? $this->shareManager->getShareByToken($shareToken) : null;
			$file = $shareToken ? $this->getFileForShare($share, $fileId, $path) : $this->getFileForUser($fileId, $userId, $path);

			$isGuest = $guestName || !$userId;
			$transferToken = $this->createToken($userId, $file, $share, null, $isGuest);

			return new DataResponse([
				'token' => $transferToken->getTokenId(),
				'size' => $file->getSize(),
				'key' => $transferToken->getHashKey(),
			], Http::STATUS_CREATED);
		} catch (Exception $e) {
			$this->logger->error('Failed to generate token for file', [ 'exception' => $e ]);
			return new DataResponse('Failed to generate token', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 * @throws NoUserException
	 */
	private function getFileForUser(int $fileId, string $userId, ?string $path = null): File {
		$folder = $this->rootFolder->getUserFolder($userId);

		if ($path !== null) {
			$node = $folder->get($path);
		} else {
			$node = $folder->getFirstNodeById($fileId);
		}

		if ($node instanceof File) {
			return $node;
		}

		throw new NotFoundException();
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function getFileForShare(IShare $share, ?int $fileId, ?string $path = null): File {
		// not authenticated ?
		if ($share->getPassword()) {
			if (!$this->session->exists('public_link_authenticated')
				|| $this->session->get('public_link_authenticated') !== (string)$share->getId()
			) {
				throw new NotPermittedException('Invalid password');
			}
		}

		if (($share->getPermissions() & Constants::PERMISSION_READ) === 0) {
			throw new NotPermittedException();
		}

		$node = $share->getNode();
		if ($node instanceof File) {
			return $node;
		}

		if ($fileId === null && $path === null) {
			throw new NotFoundException();
		}

		if ($path !== null) {
			$node = $node->get($path);
		} else {
			$node = $node->getFirstNodeById($fileId);
		}

		if ($node instanceof File) {
			return $node;
		}

		throw new NotFoundException();
	}

	/**
	 * Given an access token and a fileId, returns the contents of the file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: 'chunk/files/{fileId}/contents')]
	public function getFileContents(string $fileId, string $access_token): JSONResponse|StreamResponse|Http\Response {

		try {
			[$fileId, , $version] = PDFViewerHelper::parseFileId($fileId);

			$transferToken = $this->tokenService->getTransferToken($access_token);
			$file = $this->getFileForToken($transferToken);
			if (!($file instanceof File)) {
				throw new NotFoundException('No valid file found for ' . $fileId);
			}
			if ($file->getId() !== (int)$fileId) {
				throw new NotFoundException('Token is not valid for ' . $fileId);
			}
		} catch (NotFoundException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (InvalidTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			\OC_User::setIncognitoMode(true);
			if ($version !== '0') {
				$versionManager = \OC::$server->get(IVersionManager::class);
				$file = $versionManager->getVersionFile($this->userManager->get($transferToken->getUserForFileAccess()), $file, $version);
			}

			if ($file->getSize() === 0) {
				$response = new Http\Response();
			} else {
				$filesize = $file->getSize();
				if ($this->request->getHeader('Range')) {
					preg_match('/bytes=(\d+)-(\d+)?/', $this->request->getHeader('Range'), $matches);

					$offset = intval($matches[1] ?? 0);
					$length = intval($matches[2] ?? 0) - $offset + 1;
					if ($length <= 0) {
						$length = $filesize - $offset;
					}

					$fp = $file->fopen('rb');
					$rangeStream = fopen('php://temp', 'w+b');

					fseek($fp, $offset);
					$plain = stream_get_contents($fp, $length);
					$encrypted = $this->helper->encrypt($plain, base64_decode($transferToken->getHashKey()));
					fwrite($rangeStream, $encrypted);
					fclose($fp);

					fseek($rangeStream, 0);
					$response = new StreamResponse($rangeStream);
					$response->addHeader('Accept-Ranges', 'bytes');
					$response->addHeader('Content-Length', strlen($encrypted));
					$response->setStatus(Http::STATUS_PARTIAL_CONTENT);
					$response->addHeader('Content-Range', 'bytes ' . $offset . '-' . ($offset + $length) . '/' . $filesize);
				} else {
					$this->logger->error('Invalid chunk request without range');
					return new JSONResponse([], Http::STATUS_BAD_REQUEST);
				}
			}
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Type', 'application/octet-stream');
			return $response;
		} catch (\Exception $e) {
			$this->logger->error('getFile failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
			$this->logger->error('Version manager could not be found when trying to restore file. Versioning app disabled?: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @param TransferToken $transferToken
	 * @return File|Folder|Node|null
	 * @throws NotFoundException
	 * @throws ShareNotFound
	 */
	private function getFileForToken(TransferToken $transferToken): File|Folder|Node|null {
		if (!empty($transferToken->getShare())) {
			$share = $this->shareManager->getShareByToken($transferToken->getShare());
			$node = $share->getNode();

			if ($node instanceof File) {
				return $node;
			}

			return $node->getFirstNodeById($transferToken->getFileid());
		}

		// Group folders requires an active user to be set in order to apply the proper acl permissions as for anonymous requests it requires share permissions for read access
		// https://github.com/nextcloud/groupfolders/blob/e281b1e4514cf7ef4fb2513fb8d8e433b1727eb6/lib/Mount/MountProvider.php#L169
		$userFolder = $this->rootFolder->getUserFolder($transferToken->getUserForFileAccess());
		$files = $userFolder->getById($transferToken->getFileid());

		if (count($files) === 0) {
			throw new NotFoundException('No valid file found for transfer token');
		}

		// Workaround to always open files with edit permissions if multiple occurrences of
		// the same file id are in the user home, ideally we should also track the path of the file when opening
		usort($files, fn (Node $a, Node $b) => ($b->getPermissions() & Constants::PERMISSION_UPDATE) <=> ($a->getPermissions() & Constants::PERMISSION_UPDATE));

		return array_shift($files);
	}

	private function createToken(?string $userId, File $file, ?IShare $share = null, ?int $version = null, bool $isGuest = false): TransferToken {
		$owneruid = null;
		$shareToken = $share?->getToken();
		$editoruid = $userId;

		// if the user is not logged-in do use the sharers storage
		if ($shareToken !== null) {
			$share = $this->shareManager->getShareByToken($shareToken);

			if (($share->getPermissions() & Constants::PERMISSION_READ) === 0) {
				throw new ShareNotFound();
			}

			$owneruid = $share->getShareOwner();
		}

		// Check node readability (for storage wrapper overwrites like terms of services)
		if (!$file->isReadable()) {
			throw new NotPermittedException();
		}

		// If its a public share, use the owner from the share, otherwise check the file object
		if (is_null($owneruid)) {
			$owner = $file->getOwner();
			if (is_null($owner)) {
				// Editor UID instead of owner UID in case owner is null e.g. group folders
				$owneruid = $editoruid;
			} else {
				$owneruid = $owner->getUID();
			}
		}

		// Safeguard that users without required group permissions cannot create a token
		if (!$this->helper->isEnabledForUser($owneruid) && !$this->helper->isEnabledForUser($editoruid)) {
			throw new NotPermittedException();
		}

		// force read operation to trigger possible audit logging
		$this->eventDispatcher->dispatchTyped(new BeforeNodeReadEvent($file));

		$guestName = $editoruid === null ? $this->helper->prepareGuestName($this->userId) : null;

		$transferToken = TransferToken::fromParams([
			'ownerUid' => $owneruid,
			'editorUid' => $editoruid,
			'guestDisplayname' => $guestName,
			'fileid' => $file->getId(),
			'share' => $share?->getToken() ?? null,
			'tokenType' => $isGuest ? TransferToken::TOKEN_TYPE_GUEST : TransferToken::TOKEN_TYPE_USER,
			'version' => $version,
		]);
		$this->tokenService->generateTransferToken($userId, $transferToken);
		return $transferToken;
	}

}
