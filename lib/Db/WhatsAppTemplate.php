<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTemplateId()
 * @method void setTemplateId(string $value)
 * @method string getTemplateName()
 * @method void setTemplateName(string $value)
 * @method string getCategory()
 * @method void setCategory(string $value)
 * @method string getLanguage()
 * @method void setLanguage(string $value)
 * @method string getStatus()
 * @method void setStatus(string $value)
 * @method string getTemplateText()
 * @method void setTemplateText(string $value)
 * @method int getParameters()
 * @method void setParameters(int $value)
 * @method string getMetadata()
 * @method void setMetadata(string $value)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class WhatsAppTemplate extends Entity {
	protected $templateId;
	protected $templateName;
	protected $category;
	protected $language;
	protected $status;
	protected $templateText;
	protected $parameters;
	protected $metadata;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('templateId', 'string');
		$this->addType('templateName', 'string');
		$this->addType('category', 'string');
		$this->addType('language', 'string');
		$this->addType('status', 'string');
		$this->addType('templateText', 'string');
		$this->addType('parameters', 'integer');
		$this->addType('metadata', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
