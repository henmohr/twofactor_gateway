<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getMessageId()
 * @method void setMessageId(string $value)
 * @method string getPhoneNumberId()
 * @method void setPhoneNumberId(string $value)
 * @method string getRecipient()
 * @method void setRecipient(string $value)
 * @method string getMessageText()
 * @method void setMessageText(string $value)
 * @method string getMessageType()
 * @method void setMessageType(string $value)
 * @method string getStatus()
 * @method void setStatus(string $value)
 * @method string getMetadata()
 * @method void setMetadata(string $value)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class WhatsAppMessage extends Entity {
	protected $messageId;
	protected $phoneNumberId;
	protected $recipient;
	protected $messageText;
	protected $messageType;
	protected $status;
	protected $metadata;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('messageId', 'string');
		$this->addType('phoneNumberId', 'string');
		$this->addType('recipient', 'string');
		$this->addType('messageText', 'string');
		$this->addType('messageType', 'string');
		$this->addType('status', 'string');
		$this->addType('metadata', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
