<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<WhatsAppMessage>
 */
class WhatsAppMessageMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'whatsapp_messages', WhatsAppMessage::class);
	}

	/**
	 * Find message by message ID
	 */
	public function findByMessageId(string $messageId): ?WhatsAppMessage {
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
	 * Find messages by recipient
	 *
	 * @return WhatsAppMessage[]
	 */
	public function findByRecipient(string $recipient, ?string $status = null, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)))
			->orderBy('created_at', 'DESC');

		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find messages by status
	 *
	 * @return WhatsAppMessage[]
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
	 * Find pending messages
	 *
	 * @return WhatsAppMessage[]
	 */
	public function findPending(?int $limit = null): array {
		return $this->findByStatus('pending', $limit);
	}

	/**
	 * Count messages by status
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
	 * Clean up old messages
	 */
	public function cleanupOld(int $daysToKeep = 90): int {
		$qb = $this->db->getQueryBuilder();
		$timestamp = time() - ($daysToKeep * 86400);

		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
