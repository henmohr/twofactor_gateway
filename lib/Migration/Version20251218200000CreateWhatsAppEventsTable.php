<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Migration;

use Closure;
use OCP\DB\ISchemaTools;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigration;

class Version20251218200000CreateWhatsAppEventsTable extends SimpleMigration {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string {
		$schema = $schemaClosure();
		$schemaTools = \OCP\Server::get(ISchemaTools::class);

		if (!$schema->hasTable('whatsapp_events')) {
			$table = $schema->createTable('whatsapp_events');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('message_id', 'string', [
				'length' => 255,
				'notnull' => false,
				'comment' => 'WhatsApp message ID from webhook',
			]);
			$table->addColumn('phone_number_id', 'string', [
				'length' => 100,
				'notnull' => false,
				'comment' => 'Phone number ID associated with event',
			]);
			$table->addColumn('recipient', 'string', [
				'length' => 50,
				'notnull' => false,
				'comment' => 'Recipient phone number',
			]);
			$table->addColumn('sender', 'string', [
				'length' => 50,
				'notnull' => false,
				'comment' => 'Sender phone number (for incoming)',
			]);
			$table->addColumn('event_type', 'string', [
				'length' => 50,
				'notnull' => true,
				'comment' => 'Event type: message_sent, message_delivered, message_read, message_failed, message_received',
			]);
			$table->addColumn('status', 'string', [
				'length' => 50,
				'notnull' => false,
				'comment' => 'Status: sent, delivered, read, failed, received',
			]);
			$table->addColumn('message_type', 'string', [
				'length' => 50,
				'notnull' => false,
				'comment' => 'Message type: text, image, document, audio, video, template',
			]);
			$table->addColumn('content', 'text', [
				'notnull' => false,
				'comment' => 'Message content or error message',
			]);
			$table->addColumn('webhook_payload', 'text', [
				'notnull' => false,
				'comment' => 'Full webhook payload for debugging',
			]);
			$table->addColumn('error_code', 'string', [
				'length' => 50,
				'notnull' => false,
				'comment' => 'Error code if delivery failed',
			]);
			$table->addColumn('error_message', 'text', [
				'notnull' => false,
				'comment' => 'Error message if delivery failed',
			]);
			$table->addColumn('timestamp', 'bigint', [
				'notnull' => false,
				'comment' => 'Webhook timestamp from Meta',
			]);
			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', 'datetime', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['message_id'], 'idx_msg_id');
			$table->addIndex(['phone_number_id'], 'idx_phone_id');
			$table->addIndex(['event_type'], 'idx_event_type');
			$table->addIndex(['status'], 'idx_status');
			$table->addIndex(['created_at'], 'idx_created_at');

			$output->info('Created table `whatsapp_events`');
		}

		if (!$schema->hasTable('whatsapp_messages')) {
			$table = $schema->createTable('whatsapp_messages');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('message_id', 'string', [
				'length' => 255,
				'notnull' => true,
				'comment' => 'WhatsApp message ID from send response',
			]);
			$table->addColumn('phone_number_id', 'string', [
				'length' => 100,
				'notnull' => false,
			]);
			$table->addColumn('recipient', 'string', [
				'length' => 50,
				'notnull' => true,
				'comment' => 'Recipient phone number',
			]);
			$table->addColumn('message_text', 'text', [
				'notnull' => false,
				'comment' => 'Sent message text',
			]);
			$table->addColumn('message_type', 'string', [
				'length' => 50,
				'notnull' => true,
				'comment' => 'Message type: text, template, image, etc',
			]);
			$table->addColumn('status', 'string', [
				'length' => 50,
				'notnull' => true,
				'comment' => 'Status: pending, sent, delivered, read, failed',
				'default' => 'pending',
			]);
			$table->addColumn('metadata', 'text', [
				'notnull' => false,
				'comment' => 'Additional metadata as JSON',
			]);
			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', 'datetime', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['message_id'], 'idx_msg_id_unique');
			$table->addIndex(['recipient'], 'idx_recipient');
			$table->addIndex(['status'], 'idx_msg_status');
			$table->addIndex(['created_at'], 'idx_msg_created_at');

			$output->info('Created table `whatsapp_messages`');
		}

		return null;
	}
}
