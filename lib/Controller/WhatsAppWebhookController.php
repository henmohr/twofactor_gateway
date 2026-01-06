<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Controller;

use OCA\TwoFactorGateway\Service\WhatsApp\WebhookProcessorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WhatsAppWebhookController extends Controller {
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private WebhookProcessorService $webhookProcessor,
	) {
		parent::__construct('twofactor_gateway', $request);
	}

	/**
	 * Verify webhook (Facebook sends GET request during setup)
	 *
	 * @param string $hub_mode
	 * @param string $hub_challenge
	 * @param string $hub_verify_token
	 * @return DataResponse
	 */
	#[ApiRoute(verb: 'GET', url: '/api/v1/webhooks/whatsapp')]
	#[NoAdminRequired]
	public function verify(
		string $hub_mode = '',
		string $hub_challenge = '',
		string $hub_verify_token = '',
	): DataResponse {
		try {
			// Get stored verification token
			$storedToken = $this->appConfig->getValueString('twofactor_gateway', 'whatsapp_cloud_verify_token', '');

			// Verify the mode and token
			if ($hub_mode === 'subscribe' && $hub_verify_token === $storedToken) {
				$this->logger->info('WhatsApp webhook verified successfully');
				return new DataResponse($hub_challenge, 200, [
					'Content-Type' => 'text/plain',
				]);
			}

			$this->logger->warning('Invalid webhook verification token');
			return new DataResponse(['error' => 'Invalid verification token'], 403);
		} catch (\Exception $e) {
			$this->logger->error('Error verifying webhook', ['exception' => $e]);
			return new DataResponse(['error' => 'Error verifying webhook'], 500);
		}
	}

	/**
	 * Handle incoming webhook messages (Facebook sends POST requests)
	 *
	 * Webhook structure:
	 * {
	 *   "object": "whatsapp_business_account",
	 *   "entry": [{
	 *     "id": "PHONE_NUMBER_ID",
	 *     "changes": [{
	 *       "value": {
	 *         "messaging_product": "whatsapp",
	 *         "metadata": {...},
	 *         "messages": [...],
	 *         "statuses": [...]
	 *       },
	 *       "field": "messages"
	 *     }]
	 *   }]
	 * }
	 *
	 * @return DataResponse
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/webhooks/whatsapp')]
	#[NoAdminRequired]
	public function webhook(): DataResponse {
		try {
			$body = $this->request->getParams();

			// Log the webhook payload (truncated for security)
			$this->logger->debug('WhatsApp webhook received', [
				'object' => $body['object'] ?? 'unknown',
				'has_messages' => !empty($body['entry'][0]['changes'][0]['value']['messages']),
				'has_statuses' => !empty($body['entry'][0]['changes'][0]['value']['statuses']),
			]);

			// Processar webhook via serviço
			$this->webhookProcessor->processWebhook($body);

			// Facebook requer resposta 200 rápida para confirmar recebimento
			return new DataResponse(['success' => true], 200);
		} catch (\Exception $e) {
			$this->logger->error('Error processing webhook', [
				'exception' => $e->getMessage(),
			]);
			// Retorna 200 mesmo em caso de erro para não ficar retentando
			return new DataResponse(['success' => false], 200);
		}
	}
}
