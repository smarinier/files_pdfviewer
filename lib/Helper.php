<?php

namespace OCA\Files_PDFViewer;

use DateTime;
use DateTimeZone;
use OCP\IUserSession;
use OCP\Util;

class Helper {

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	public function isEnabledForUser(?string $userId = null): bool {
		if ($userId === null) {
			// Share links set the incognito mode so in order to still get the
			// user information we need to temporarily switch it off to get the current user
			$incognito = false;
			if (\OC_User::isIncognitoMode()) {
				\OC_User::setIncognitoMode(false);
				$incognito = true;
			}
			$user = $this->userSession->getUser();
			$userId = $user?->getUID();
			if ($incognito) {
				\OC_User::setIncognitoMode(true);
			}
		}

		if ($userId === null) {
			// Access for public users will be checked separately based on the share owner
			// when generating the transfer token and loading the scripts on public share links
			return false;
		}

		return true;
	}

	/**
	 * @param string $fileId
	 * @return array
	 * @throws \Exception
	 */
	public static function parseFileId(string $fileId) {
		$arr = explode('_', $fileId);
		$templateId = null;
		if (count($arr) === 1) {
			$fileId = $arr[0];
			$instanceId = '';
			$version = '0';
		} elseif (count($arr) === 2) {
			[$fileId, $instanceId] = $arr;
			$version = '0';
		} elseif (count($arr) === 3) {
			[$fileId, $instanceId, $version] = $arr;
		} else {
			throw new \Exception('$fileId has not the expected format');
		}

		if (str_contains($fileId, '-')) {
			[$fileId, $templateId] = array_pad(explode('/', $fileId), 2, null);
		}

		return [
			$fileId,
			$instanceId,
			$version,
			$templateId
		];
	}

	/**
	 * Helper function to convert to ISO 8601 round-trip format.
	 * @param integer $time Must be seconds since unix epoch
	 */
	public static function toISO8601($time) {
		// TODO: Be more precise and don't ignore milli, micro seconds ?
		$datetime = DateTime::createFromFormat('U', $time, new DateTimeZone('UTC'));
		if ($datetime) {
			return $datetime->format('Y-m-d\TH:i:s.u\Z');
		}

		return false;
	}

	private function getGuestNameFromCookie(?string $userId): ?string {
		if ($userId !== null || !isset($_COOKIE['guestUser']) || $_COOKIE['guestUser'] === '') {
			return null;
		}
		return $_COOKIE['guestUser'];
	}

	/**
	 * Prepare guest display name
	 * @param string|null $userId
	 * @return string
	 */
	public function prepareGuestName(?string $userId): string {

		$guestName = $this->getGuestNameFromCookie($userId);

		if (empty($guestName)) {
			return 'Anonymous guest';
		}

		$guestName = Util::sanitizeHTML($guestName);
		$cut = 56;
		while (mb_strlen($guestName) >= 64) {
			$guestName = Util::sanitizeHTML(
				mb_substr($guestName, 0, $cut)
			);
			$cut -= 5;
		}

		return $guestName;
	}

	/**
	 * Encrypt data with AES-256-CBC
	 * @param string $plain Plain text
	 * @param string $hashKey Encryption key
	 * @return string Encrypted data with IV prepended
	 */
	public function encrypt(string $plain, string $hashKey): string {
		$cipher = 'AES-256-CBC';
		$iv = random_bytes(16);
		$encrypted = openssl_encrypt($plain, $cipher, $hashKey, OPENSSL_RAW_DATA, $iv);
		return $iv . $encrypted;
	}
}
