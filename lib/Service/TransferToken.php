<?php

namespace OCA\Files_PDFViewer\Service;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @package OCA\Files_PDFViewer\Service
 *
 * @method void setOwnerUid(string $uid)
 * @method string getOwnerUid()
 * @method void setEditorUid(?string $uid)
 * @method string getEditorUid()
 * @method void setFileid(int $fileid)
 * @method int getFileid()
 * @method void setVersion(int $version)
 * @method int getVersion()
 * @method void setSessionId(string $token)
 * @method string getSessionId()
 * @method void setTokenType(int $tokenType)
 * @method int getTokenType()
 * @method void setExpiry(int $expiry)
 * @method int getExpiry()
 * @method void setGuestDisplayname(string $guestDisplayName)
 * @method string getGuestDisplayname()
 * @method void setShare(string $token)
 * @method string getShare()
 * @method void setHashKey(string $hashKey)
 * @method string getHashKey()
 */
class TransferToken extends Entity implements \JsonSerializable {

	private const DEFAULT_EXPIRY = 3600; // 1 hour

	/**
	 * Token to open a file as a user on the current instance
	 */
	public const TOKEN_TYPE_USER = 0;

	/**
	 * Token to open a file as a guest on the current instance
	 */
	public const TOKEN_TYPE_GUEST = 1;

	/** @var string */
	protected $_tokenId;

	/** @var string */
	protected $ownerUid;

	/** @var string */
	protected $editorUid;

	/** @var int */
	protected $fileid;

	/** @var int */
	protected $version;

	/** @var string */
	protected $sessionId;

	/** @var int */
	protected $expiry;

	/** @var string */
	protected $guestDisplayname;

	/** @var string */
	protected $share;

	/** @var int */
	protected $tokenType = 0;

	/** @var string */
	protected $hashKey;

	public function __construct() {
		$this->addType('ownerUid', Types::STRING);
		$this->addType('editorUid', Types::STRING);
		$this->addType('fileid', Types::INTEGER);
		$this->addType('version', Types::INTEGER);
		$this->addType('sessionId', Types::STRING);
		$this->addType('expiry', Types::INTEGER);
		$this->addType('guestDisplayname', Types::STRING);
		$this->addType('tokenType', Types::INTEGER);
		$this->addType('hashKey', Types::STRING);
	}

	public function setTokenId(string $tokenId): void {
		$this->_tokenId = $tokenId;
	}

	public function getTokenId(): string {
		return $this->_tokenId;
	}

	public function isGuest() {
		return $this->getTokenType() === self::TOKEN_TYPE_GUEST;
	}

	public function getUserForFileAccess() {
		if ($this->share !== null) {
			return $this->getOwnerUid();
		}
		return $this->isGuest() ? $this->getOwnerUid() : $this->getEditorUid();
	}

	public function setDefaultExpiry() {
		$defaultExpiry = time() + self::DEFAULT_EXPIRY;
		$this->setExpiry($defaultExpiry);
	}

	public function isExpired(): bool {
		return $this->getExpiry() < time();
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		$properties = get_object_vars($this);
		$reflection = new \ReflectionClass($this);
		$json = [];
		foreach ($properties as $property => $value) {
			if (!str_starts_with($property, '_') && $reflection->hasProperty($property)) {
				$propertyReflection = $reflection->getProperty($property);
				if (!$propertyReflection->isPrivate()) {
					$json[$property] = $this->getter($property);
				}
			}
		}
		return $json;
	}
}
