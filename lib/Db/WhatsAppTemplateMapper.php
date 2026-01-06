<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\\DB\\QueryBuilder\\IQueryBuilder;
use OCP\\IDBConnection;

/**
 * @template-extends QBMapper<WhatsAppTemplate>
 */
class WhatsAppTemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'whatsapp_templates', WhatsAppTemplate::class);
	}

	/**
	 * Find template by ID
	 */
	public function findByTemplateId(string $templateId): ?WhatsAppTemplate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$data = $result->fetch();
		$result->closeCursor();

		if ($data === false) {
			return null;
		}

		return $this->mapRowToEntity($data);
	}

	/**
	 * Find template by name and language
	 */
	public function findByNameAndLanguage(string $templateName, string $language): ?WhatsAppTemplate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('template_name', $qb->createNamedParameter($templateName)))
			->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$data = $result->fetch();
		$result->closeCursor();

		if ($data === false) {
			return null;
		}

		return $this->mapRowToEntity($data);
	}

	/**
	 * Find all approved templates
	 *
	 * @return WhatsAppTemplate[]
	 */
	public function findApproved(?string $language = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('APPROVED')))
			->orderBy('template_name', 'ASC');

		if ($language !== null) {
			$qb->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find templates by category
	 *
	 * @return WhatsAppTemplate[]
	 */
	public function findByCategory(string $category, ?string $language = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('category', $qb->createNamedParameter($category)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('APPROVED')))
			->orderBy('template_name', 'ASC');

		if ($language !== null) {
			$qb->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find templates by status
	 *
	 * @return WhatsAppTemplate[]
	 */
	public function findByStatus(string $status): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find OTP templates
	 *
	 * @return WhatsAppTemplate[]
	 */
	public function findOtpTemplates(?string $language = null): array {
		return $this->findByCategory('OTP', $language);
	}

	/**
	 * Count templates by status
	 */
	public function countByStatus(string $status): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	/**
	 * Check if template name exists
	 */
	public function exists(string $templateName, string $language): bool {
		return $this->findByNameAndLanguage($templateName, $language) !== null;
	}
}
