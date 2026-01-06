<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<WhatsAppEvent>
 */
class WhatsAppEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'whatsapp_events', WhatsAppEvent::class);
	}

	/**
	 * Find event by message ID
	 */
	public function findByMessageId(string $messageId): ?WhatsAppEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
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
	 * Find events by recipient
	 *
	 * @return WhatsAppEvent[]
	 */
	public function findByRecipient(string $recipient, ?string $eventType = null, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)))
			->orderBy('created_at', 'DESC');

		if ($eventType !== null) {
			$qb->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find events by status
	 *
	 * @return WhatsAppEvent[]
	 */
	public function findByStatus(string $status, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('created_at', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find failed events
	 *
	 * @return WhatsAppEvent[]
	 */
	public function findFailed(?int $limit = null): array {
		return $this->findByStatus('failed', $limit);
	}

	/**
	 * Find recent events
	 *
	 * @return WhatsAppEvent[]
	 */
	public function findRecent(?int $hoursBack = 24, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$timestamp = time() - ($hoursBack * 3600);

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count events by type
	 */
	public function countByEventType(string $eventType): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	/**
	 * Clean up old events (keep only last N days)
	 */
	public function cleanupOld(int $daysToKeep = 30): int {
		$qb = $this->db->getQueryBuilder();
		$timestamp = time() - ($daysToKeep * 86400);

		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
