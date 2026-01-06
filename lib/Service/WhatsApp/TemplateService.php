<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Service\WhatsApp;

use OCA\TwoFactorGateway\Db\WhatsAppTemplate;
use OCA\TwoFactorGateway\Db\WhatsAppTemplateMapper;
use OCA\TwoFactorGateway\Exception\ConfigurationException;
use OCP\AppFramework\\Utility\\ITimeFactory;
use OCP\\Http\\Client\\IClientService;
use OCP\\IAppConfig;
use Psr\\Log\\LoggerInterface;

/**
 * Service para gerenciar templates do WhatsApp Cloud API
 * 
 * Templates são mensagens pré-aprovadas pelo Meta que podem ser enviadas com parâmetros.
 * Exemplo:
 * - Template: "Your verification code is {{1}}"
 * - Parâmetros: ["123456"]
 * - Resultado: "Your verification code is 123456"
 */
class TemplateService {
	private const API_VERSION = 'v14.0';
	private const API_BASE_URL = 'https://graph.facebook.com';

	public function __construct(
		private WhatsAppTemplateMapper $templateMapper,
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {}

	/**
	 * Sincroniza templates do Meta com banco de dados local
	 *
	 * @return int Número de templates sincronizados
	 */
	public function syncTemplates(): int {
		try {
			$businessAccountId = $this->appConfig->getValueString(
				'twofactor_gateway',
				'whatsapp_cloud_business_account_id',
				''
			);

			if (empty($businessAccountId)) {
				throw new ConfigurationException('Business Account ID not configured');
			}

			$apiKey = $this->appConfig->getValueString(
				'twofactor_gateway',
				'whatsapp_cloud_api_key',
				''
			);

			if (empty($apiKey)) {
				throw new ConfigurationException('API Key not configured');
			}

			$apiEndpoint = $this->appConfig->getValueString(
				'twofactor_gateway',
				'whatsapp_cloud_api_endpoint',
				self::API_BASE_URL
			);

			// Buscar templates do Meta
			$url = sprintf(
				'%s/%s/%s/message_templates',
				rtrim($apiEndpoint, '/'),
				self::API_VERSION,
				$businessAccountId
			);

			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'headers' => [
					'Authorization' => "Bearer $apiKey",
				],
			]);

			if ($response->getStatusCode() !== 200) {
				throw new ConfigurationException('Failed to fetch templates from Meta');
			}

			$data = json_decode((string)$response->getBody(), true);
			$templates = $data['data'] ?? [];
			$synced = 0;

			foreach ($templates as $template) {
				if ($this->storeTemplate($template)) {
					$synced++;
				}
			}

			$this->logger->info('Templates synchronized', ['count' => $synced]);
			return $synced;
		} catch (\Exception $e) {
			$this->logger->error('Failed to sync templates', ['exception' => $e]);
			return 0;
		}
	}

	/**
	 * Armazena template do Meta no banco de dados
	 */
	private function storeTemplate(array $templateData): bool {
		try {
			$templateId = $templateData['id'] ?? null;
			$templateName = $templateData['name'] ?? null;
			$category = $templateData['category'] ?? 'TRANSACTIONAL';
			$language = $templateData['language'] ?? 'en';
			$status = $templateData['status'] ?? 'PENDING_REVIEW';

			if (!$templateId || !$templateName) {
				return false;
			}

			// Buscar template no banco
			$existing = $this->templateMapper->findByTemplateId($templateId);

			// Extrair texto e parâmetros
			$components = $templateData['components'] ?? [];
			$templateText = '';
			$parameters = 0;

			foreach ($components as $component) {
				if ($component['type'] === 'BODY') {
					$templateText = $component['text'] ?? '';
					// Contar placeholders {{1}}, {{2}}, etc
					if (preg_match_all('/\{\{(\d+)\}\}/', $templateText, $matches)) {
						$parameters = count(array_unique($matches[1]));
					}
					break;
				}
			}

			if ($existing) {
				// Atualizar existente
				$existing->setStatus($status);
				$existing->setTemplateText($templateText);
				$existing->setParameters($parameters);
				$existing->setUpdatedAt($this->timeFactory->now());
				$this->templateMapper->update($existing);
			} else {
				// Criar novo
				$template = new WhatsAppTemplate();
				$template->setTemplateId($templateId);
				$template->setTemplateName($templateName);
				$template->setCategory($category);
				$template->setLanguage($language);
				$template->setStatus($status);
				$template->setTemplateText($templateText);
				$template->setParameters($parameters);
				$template->setMetadata(json_encode($templateData));
				$template->setCreatedAt($this->timeFactory->now());
				$template->setUpdatedAt($this->timeFactory->now());
				$this->templateMapper->insert($template);
			}

			return true;
		} catch (\Exception $e) {
			$this->logger->warning('Failed to store template', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Busca template por nome
	 */
	public function getTemplateByName(string $name, string $language = 'pt_BR'): ?WhatsAppTemplate {
		try {
			$template = $this->templateMapper->findByNameAndLanguage($name, $language);

			if (!$template || $template->getStatus() !== 'APPROVED') {
				return null;
			}

			return $template;
		} catch (\Exception $e) {
			$this->logger->error('Failed to get template', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Busca todos os templates aprovados
	 *
	 * @return WhatsAppTemplate[]
	 */
	public function getApprovedTemplates(?string $language = null): array {
		try {
			return $this->templateMapper->findApproved($language);
		} catch (\Exception $e) {
			$this->logger->error('Failed to get approved templates', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Busca templates OTP
	 *
	 * @return WhatsAppTemplate[]
	 */
	public function getOtpTemplates(?string $language = null): array {
		try {
			return $this->templateMapper->findOtpTemplates($language);
		} catch (\Exception $e) {
			$this->logger->error('Failed to get OTP templates', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Prepara payload para enviar via template
	 *
	 * @param string[] $parameters Parâmetros para o template
	 */
	public function prepareTemplatePayload(
		WhatsAppTemplate $template,
		array $parameters = [],
		string $language = 'pt_BR'
	): array {
		return [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'type' => 'template',
			'template' => [
				'name' => $template->getTemplateName(),
				'language' => [
					'code' => $language,
				],
				'body' => [
					'parameters' => array_map(fn($param) => ['type' => 'text', 'text' => $param], $parameters),
				],
			],
		];
	}

	/**
	 * Valida se os parâmetros correspondem ao template
	 */
	public function validateTemplateParameters(WhatsAppTemplate $template, array $parameters): bool {
		$expectedCount = $template->getParameters();
		$actualCount = count($parameters);

		if ($expectedCount !== $actualCount) {
			$this->logger->warning('Template parameter count mismatch', [
				'expected' => $expectedCount,
				'actual' => $actualCount,
			]);
			return false;
		}

		// Validar que todos são strings não-vazias
		foreach ($parameters as $param) {
			if (!is_string($param) || empty($param)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Processa template text para exibição
	 */
	public function processTemplateText(WhatsAppTemplate $template, array $parameters = []): string {
		$text = $template->getTemplateText();

		foreach ($parameters as $index => $value) {
			// Placeholders são {{1}}, {{2}}, etc (começam em 1)
			$placeholder = '{{' . ($index + 1) . '}}';
			$text = str_replace($placeholder, $value, $text);
		}

		return $text;
	}

	/**
	 * Obtém estatísticas de templates
	 */
	public function getStatistics(): array {
		try {
			return [
				'total' => $this->templateMapper->countByStatus('APPROVED') +
					$this->templateMapper->countByStatus('PENDING_REVIEW') +
					$this->templateMapper->countByStatus('REJECTED'),
				'approved' => $this->templateMapper->countByStatus('APPROVED'),
				'pending' => $this->templateMapper->countByStatus('PENDING_REVIEW'),
				'rejected' => $this->templateMapper->countByStatus('REJECTED'),
				'disabled' => $this->templateMapper->countByStatus('DISABLED'),
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to get template statistics', ['exception' => $e]);
			return [];
		}
	}
}
