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
 * @method string getSender()
 * @method void setSender(string $value)
 * @method string getEventType()
 * @method void setEventType(string $value)
 * @method string getStatus()
 * @method void setStatus(string $value)
 * @method string getMessageType()
 * @method void setMessageType(string $value)
 * @method string getContent()
 * @method void setContent(string $value)
 * @method string getWebhookPayload()
 * @method void setWebhookPayload(string $value)
 * @method string getErrorCode()
 * @method void setErrorCode(string $value)
 * @method string getErrorMessage()
 * @method void setErrorMessage(string $value)
 * @method int getTimestamp()
 * @method void setTimestamp(int $value)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class WhatsAppEvent extends Entity {
	protected $messageId;
	protected $phoneNumberId;
	protected $recipient;
	protected $sender;
	protected $eventType;
	protected $status;
	protected $messageType;
	protected $content;
	protected $webhookPayload;
	protected $errorCode;
	protected $errorMessage;
	protected $timestamp;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('messageId', 'string');
		$this->addType('phoneNumberId', 'string');
		$this->addType('recipient', 'string');
		$this->addType('sender', 'string');
		$this->addType('eventType', 'string');
		$this->addType('status', 'string');
		$this->addType('messageType', 'string');
		$this->addType('content', 'string');
		$this->addType('webhookPayload', 'string');
		$this->addType('errorCode', 'string');
		$this->addType('errorMessage', 'string');
		$this->addType('timestamp', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
