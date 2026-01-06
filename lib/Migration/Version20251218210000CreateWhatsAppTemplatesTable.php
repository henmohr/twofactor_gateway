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

class Version20251218210000CreateWhatsAppTemplatesTable extends SimpleMigration {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?string {
		$schema = $schemaClosure();

		if (!$schema->hasTable('whatsapp_templates')) {
			$table = $schema->createTable('whatsapp_templates');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('template_id', 'string', [
				'length' => 255,
				'notnull' => true,
				'comment' => 'Template ID from Meta',
			]);
			$table->addColumn('template_name', 'string', [
				'length' => 255,
				'notnull' => true,
				'comment' => 'User-friendly name',
			]);
			$table->addColumn('category', 'string', [
				'length' => 50,
				'notnull' => true,
				'comment' => 'Category: TRANSACTIONAL, MARKETING, OTP',
			]);
			$table->addColumn('language', 'string', [
				'length' => 10,
				'notnull' => true,
				'comment' => 'Language code (pt_BR, en_US, etc)',
			]);
			$table->addColumn('status', 'string', [
				'length' => 50,
				'notnull' => true,
				'default' => 'PENDING_REVIEW',
				'comment' => 'Status: PENDING_REVIEW, APPROVED, REJECTED, DISABLED',
			]);
			$table->addColumn('template_text', 'text', [
				'notnull' => true,
				'comment' => 'Template text with parameters {{1}}, {{2}}, etc',
			]);
			$table->addColumn('parameters', 'integer', [
				'notnull' => true,
				'default' => 0,
				'comment' => 'Number of parameters',
			]);
			$table->addColumn('metadata', 'text', [
				'notnull' => false,
				'comment' => 'Extra metadata as JSON',
			]);
			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', 'datetime', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['template_id'], 'idx_template_id_unique');
			$table->addIndex(['template_name'], 'idx_template_name');
			$table->addIndex(['category'], 'idx_category');
			$table->addIndex(['status'], 'idx_status');
			$table->addIndex(['language'], 'idx_language');

			$output->info('Created table `whatsapp_templates`');
		}

		return null;
	}
}
