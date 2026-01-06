<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Service\WhatsApp;

use OCA\TwoFactorGateway\Db\WhatsAppEvent;
use OCA\TwoFactorGateway\Db\WhatsAppEventMapper;
use OCA\TwoFactorGateway\Db\WhatsAppMessage;
use OCA\TwoFactorGateway\Db\WhatsAppMessageMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Service para processar webhooks recebidos do WhatsApp Cloud API
 *
 * Estructura do webhook:
 * {
 *   "object": "whatsapp_business_account",
 *   "entry": [{
 *     "id": "PHONE_NUMBER_ID",
 *     "changes": [{
 *       "value": {
 *         "messaging_product": "whatsapp",
 *         "metadata": {
 *           "display_phone_number": "16315551234",
 *           "phone_number_id": "PHONE_NUMBER_ID"
 *         },
 *         "messages": [...],
 *         "statuses": [...]
 *       },
 *       "field": "messages"
 *     }]
 *   }]
 * }
 */
class WebhookProcessorService {
	public function __construct(
		private WhatsAppEventMapper $eventMapper,
		private WhatsAppMessageMapper $messageMapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {}

	/**
	 * Processa payload do webhook do WhatsApp
	 */
	public function processWebhook(array $payload): void {
		try {
			// Validar estrutura básica
			if (!$this->isValidPayload($payload)) {
				$this->logger->warning('Invalid webhook payload structure');
				return;
			}

			// Extrair dados principais
			$entry = $payload['entry'][0] ?? null;
			if ($entry === null) {
				return;
			}

			$phoneNumberId = $entry['id'] ?? null;
			$changes = $entry['changes'] ?? [];

			foreach ($changes as $change) {
				if ($change['field'] !== 'messages') {
					continue;
				}

				$value = $change['value'] ?? [];

				// Processar mensagens recebidas
				if (!empty($value['messages'])) {
					$this->processIncomingMessages($value, $phoneNumberId);
				}

				// Processar status de entrega
				if (!empty($value['statuses'])) {
					$this->processDeliveryStatuses($value, $phoneNumberId);
				}
			}
		} catch (\Exception $e) {
			$this->logger->error('Error processing webhook', ['exception' => $e]);
		}
	}

	/**
	 * Processa mensagens recebidas
	 */
	private function processIncomingMessages(array $value, ?string $phoneNumberId): void {
		$messages = $value['messages'] ?? [];
		$metadata = $value['metadata'] ?? [];

		foreach ($messages as $message) {
			try {
				$event = new WhatsAppEvent();
				$event->setMessageId($message['id'] ?? '');
				$event->setPhoneNumberId($phoneNumberId);
				$event->setSender($message['from'] ?? '');
				$event->setEventType('message_received');
				$event->setMessageType($message['type'] ?? 'unknown');
				$event->setTimestamp($message['timestamp'] ?? time());
				$event->setStatus('received');

				// Extrair conteúdo baseado no tipo
				$content = $this->extractMessageContent($message);
				$event->setContent($content);
				$event->setWebhookPayload(json_encode($message));

				$event->setCreatedAt($this->timeFactory->now());
				$event->setUpdatedAt($this->timeFactory->now());

				$this->eventMapper->insert($event);

				$this->logger->info('Stored incoming message', [
					'message_id' => $message['id'],
					'from' => $message['from'],
					'type' => $message['type'],
				]);
			} catch (\Exception $e) {
				$this->logger->error('Error processing incoming message', ['exception' => $e]);
			}
		}
	}

	/**
	 * Processa status de entrega das mensagens
	 */
	private function processDeliveryStatuses(array $value, ?string $phoneNumberId): void {
		$statuses = $value['statuses'] ?? [];

		foreach ($statuses as $status) {
			try {
				$messageId = $status['id'] ?? '';
				$statusValue = $status['status'] ?? 'unknown';
				$timestamp = $status['timestamp'] ?? time();

				// Encontrar mensagem enviada anterior
				$message = $this->messageMapper->findByMessageId($messageId);

				// Criar evento de status
				$event = new WhatsAppEvent();
				$event->setMessageId($messageId);
				$event->setPhoneNumberId($phoneNumberId);
				if ($message) {
					$event->setRecipient($message->getRecipient());
				}

				// Mapear status Meta para nosso formato
				$eventType = $this->mapStatusToEventType($statusValue);
				$event->setEventType($eventType);
				$event->setStatus($statusValue);
				$event->setTimestamp($timestamp);

				// Processar erros se houver
				$errors = $status['errors'] ?? [];
				if (!empty($errors)) {
					$error = $errors[0] ?? [];
					$event->setErrorCode($error['code'] ?? '');
					$event->setErrorMessage($error['message'] ?? '');
					$event->setStatus('failed');
				}

				$event->setWebhookPayload(json_encode($status));
				$event->setCreatedAt($this->timeFactory->now());
				$event->setUpdatedAt($this->timeFactory->now());

				$this->eventMapper->insert($event);

				// Atualizar status da mensagem enviada
				if ($message) {
					$message->setStatus($statusValue);
					$message->setUpdatedAt($this->timeFactory->now());
					$this->messageMapper->update($message);
				}

				$this->logger->info('Stored delivery status', [
					'message_id' => $messageId,
					'status' => $statusValue,
					'event_type' => $eventType,
				]);
			} catch (\Exception $e) {
				$this->logger->error('Error processing delivery status', ['exception' => $e]);
			}
		}
	}

	/**
	 * Extrai conteúdo da mensagem baseado no tipo
	 */
	private function extractMessageContent(array $message): string {
		$type = $message['type'] ?? 'unknown';

		return match ($type) {
			'text' => $message['text']['body'] ?? '',
			'image' => $message['image']['caption'] ?? '[Image]',
			'video' => $message['video']['caption'] ?? '[Video]',
			'audio' => '[Audio]',
			'document' => $message['document']['filename'] ?? '[Document]',
			'button' => $message['button']['text'] ?? '[Button]',
			'interactive' => $message['interactive']['type'] ?? '[Interactive]',
			'template' => $message['template']['name'] ?? '[Template]',
			default => '[' . strtoupper($type) . ']',
		};
	}

	/**
	 * Mapeia status Meta para nosso tipo de evento
	 */
	private function mapStatusToEventType(string $metaStatus): string {
		return match ($metaStatus) {
			'sent' => 'message_sent',
			'delivered' => 'message_delivered',
			'read' => 'message_read',
			'failed' => 'message_failed',
			default => 'message_status_update',
		};
	}

	/**
	 * Valida estrutura básica do payload
	 */
	private function isValidPayload(array $payload): bool {
		if (empty($payload['entry']) || !is_array($payload['entry'])) {
			return false;
		}

		$entry = $payload['entry'][0] ?? null;
		if ($entry === null || !is_array($entry)) {
			return false;
		}

		if (empty($entry['changes']) || !is_array($entry['changes'])) {
			return false;
		}

		return true;
	}

	/**
	 * Registra uma mensagem enviada
	 */
	public function registerSentMessage(
		string $messageId,
		string $recipient,
		string $content,
		string $messageType = 'text',
		?string $phoneNumberId = null,
	): void {
		try {
			$message = new WhatsAppMessage();
			$message->setMessageId($messageId);
			$message->setPhoneNumberId($phoneNumberId ?? '');
			$message->setRecipient($recipient);
			$message->setMessageText($content);
			$message->setMessageType($messageType);
			$message->setStatus('sent');
			$message->setCreatedAt($this->timeFactory->now());
			$message->setUpdatedAt($this->timeFactory->now());

			$this->messageMapper->insert($message);

			$this->logger->debug('Registered sent message', [
				'message_id' => $messageId,
				'recipient' => substr($recipient, -4),
			]);
		} catch (\Exception $e) {
			$this->logger->error('Error registering sent message', ['exception' => $e]);
		}
	}

	/**
	 * Recupera eventos de uma mensagem
	 *
	 * @return WhatsAppEvent[]
	 */
	public function getMessageEvents(string $messageId): array {
		try {
			$event = $this->eventMapper->findByMessageId($messageId);
			return $event ? [$event] : [];
		} catch (\Exception $e) {
			$this->logger->error('Error retrieving message events', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Recupera eventos de um recipient
	 *
	 * @return WhatsAppEvent[]
	 */
	public function getRecipientEvents(string $recipient, ?string $eventType = null, int $limit = 50): array {
		try {
			return $this->eventMapper->findByRecipient($recipient, $eventType, $limit);
		} catch (\Exception $e) {
			$this->logger->error('Error retrieving recipient events', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Recupera status de uma mensagem enviada
	 */
	public function getMessageStatus(string $messageId): ?WhatsAppMessage {
		try {
			return $this->messageMapper->findByMessageId($messageId);
		} catch (\Exception $e) {
			$this->logger->error('Error retrieving message status', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Recupera estatísticas
	 */
	public function getStatistics(): array {
		try {
			return [
				'total_events' => $this->eventMapper->countByEventType(''),
				'messages_sent' => $this->eventMapper->countByEventType('message_sent'),
				'messages_delivered' => $this->eventMapper->countByEventType('message_delivered'),
				'messages_read' => $this->eventMapper->countByEventType('message_read'),
				'messages_failed' => $this->eventMapper->countByEventType('message_failed'),
				'messages_received' => $this->eventMapper->countByEventType('message_received'),
			];
		} catch (\Exception $e) {
			$this->logger->error('Error retrieving statistics', ['exception' => $e]);
			return [];
		}
	}
}
