<?php

namespace OCA\Files_PDFViewer\Service;

use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OCA\Files_PDFViewer\AppInfo\Application;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use OCP\Security\ISecureRandom;

class TokenService {

	public function __construct(
		private IProvider $tokenProvider,
		private ISession $session,
		private ISecureRandom $random,
	) {
	}

	/**
	 * Create a transfer temporary token
	 *
	 * @param ?string $userId User id or null for guest
	 * @param TransferToken $transferToken Transfer token data
	 */
	public function generateTransferToken(?string $userId, TransferToken &$transferToken): void {

		if ($userId === null) {
			$userId = 'guest-' . $this->random->generate(8);
		}
		$randToken = $this->random->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

		$sessionId = $this->session->getId();
		if ($transferToken->isGuest()) {
			$loginName = $userId;
			$password = null;
		} else {
			$sessionToken = $this->tokenProvider->getToken($sessionId);
			$loginName = $sessionToken->getLoginName();
			try {
				$password = $this->tokenProvider->getPassword($sessionToken, $sessionId);
			} catch (PasswordlessTokenException $ex) {
				$password = null;
			}
		}

		$transferToken->setSessionId($sessionId);
		$transferToken->setDefaultExpiry();

		// generate hash key for chunk encryption
		$secret = random_bytes(16);
		$transferToken->setHashKey(base64_encode(hash('sha256', $secret, true)));

		// create temporary token with file access info in the scope
		$this->tokenProvider->generateToken(
			$randToken,
			$userId,
			$loginName,
			$password,
			'chunk-transfer-' . Application::APP_ID,
			IToken::TEMPORARY_TOKEN,
			IToken::DO_NOT_REMEMBER,
			$transferToken->jsonSerialize()
		);

		$transferToken->setTokenId($randToken);
	}

	public function getTransferTokenFromId(string $token): ?TransferToken {
		try {
			$t = $this->tokenProvider->getToken($token);
			$transferToken = TransferToken::fromParams($t->getScopeAsArray());
			$transferToken->setTokenId($token);
			return $transferToken;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get a valid transfer token or throw
	 *
	 * @param string $accessTokenId
	 * @return TransferToken
	 * @throws InvalidTokenException
	 */
	public function getTransferToken(string $accessTokenId): ?TransferToken {
		$transferToken = $this->getTransferTokenFromId($accessTokenId);
		if ($transferToken === null) {
			throw new InvalidTokenException('No valid transfer token');
		}
		if ($transferToken->getSessionId() !== $this->session->getId()) {
			throw new InvalidTokenException('Transfer token session not valid');
		}
		if ($transferToken->isExpired()) {
			throw new InvalidTokenException('Transfer token expired');
		}
		return $transferToken;
	}

}
