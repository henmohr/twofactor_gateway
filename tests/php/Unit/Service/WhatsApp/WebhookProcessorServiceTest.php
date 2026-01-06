<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TwoFactorGateway\Tests\Unit\Service\WhatsApp;

use OCA\TwoFactorGateway\Db\WhatsAppEvent;
use OCA\TwoFactorGateway\Db\WhatsAppEventMapper;
use OCA\TwoFactorGateway\Db\WhatsAppMessage;
use OCA\TwoFactorGateway\Db\WhatsAppMessageMapper;
use OCA\TwoFactorGateway\Service\WhatsApp\WebhookProcessorService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookProcessorServiceTest extends TestCase {
	private WebhookProcessorService $service;
	private MockObject&WhatsAppEventMapper $eventMapper;
	private MockObject&WhatsAppMessageMapper $messageMapper;
	private MockObject&ITimeFactory $timeFactory;
	private MockObject&LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->eventMapper = $this->createMock(WhatsAppEventMapper::class);
		$this->messageMapper = $this->createMock(WhatsAppMessageMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WebhookProcessorService(
			$this->eventMapper,
			$this->messageMapper,
			$this->timeFactory,
			$this->logger,
		);
	}

	public function testProcessWebhookWithIncomingMessage(): void {
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				[
					'id' => '123456789',
					'changes' => [
						[
							'field' => 'messages',
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata' => [
									'display_phone_number' => '16315551234',
									'phone_number_id' => '123456789',
								],
								'messages' => [
									[
										'id' => 'msg_001',
										'from' => '5511999999999',
										'timestamp' => 1234567890,
										'type' => 'text',
										'text' => [
											'body' => 'Hello World',
										],
									],
								],
							],
						],
					],
				],
			],
		];

		$this->eventMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WhatsAppEvent $event) {
				return $event->getMessageId() === 'msg_001'
					&& $event->getEventType() === 'message_received'
					&& $event->getSender() === '5511999999999';
			}));

		$this->service->processWebhook($payload);
	}

	public function testProcessWebhookWithDeliveryStatus(): void {
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				[
					'id' => '123456789',
					'changes' => [
						[
							'field' => 'messages',
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata' => [
									'display_phone_number' => '16315551234',
									'phone_number_id' => '123456789',
								],
								'statuses' => [
									[
										'id' => 'msg_001',
										'status' => 'delivered',
										'timestamp' => 1234567891,
										'recipient_id' => '5511999999999',
									],
								],
							],
						],
					],
				],
			],
		];

		$message = new WhatsAppMessage();
		$message->setMessageId('msg_001');
		$message->setRecipient('5511999999999');
		$message->setStatus('sent');

		$this->messageMapper->expects($this->once())
			->method('findByMessageId')
			->with('msg_001')
			->willReturn($message);

		$this->messageMapper->expects($this->once())
			->method('update')
			->with($this->callback(function (WhatsAppMessage $m) {
				return $m->getStatus() === 'delivered';
			}));

		$this->eventMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WhatsAppEvent $event) {
				return $event->getEventType() === 'message_delivered'
					&& $event->getStatus() === 'delivered';
			}));

		$this->service->processWebhook($payload);
	}

	public function testProcessWebhookWithFailedMessage(): void {
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				[
					'id' => '123456789',
					'changes' => [
						[
							'field' => 'messages',
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata' => [
									'display_phone_number' => '16315551234',
									'phone_number_id' => '123456789',
								],
								'statuses' => [
									[
										'id' => 'msg_002',
										'status' => 'failed',
										'timestamp' => 1234567892,
										'errors' => [
											[
												'code' => 131026,
												'message' => 'Message failed to send because this phone number is not registered on WhatsApp',
											],
										],
									],
								],
							],
						],
					],
				],
			],
		];

		$this->eventMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WhatsAppEvent $event) {
				return $event->getEventType() === 'message_failed'
					&& $event->getStatus() === 'failed'
					&& $event->getErrorCode() === '131026';
			}));

		$this->service->processWebhook($payload);
	}

	public function testProcessWebhookWithInvalidPayload(): void {
		$payload = [
			'invalid' => 'structure',
		];

		$this->logger->expects($this->once())
			->method('warning')
			->with('Invalid webhook payload structure');

		$this->eventMapper->expects($this->never())
			->method('insert');

		$this->service->processWebhook($payload);
	}

	public function testRegisterSentMessage(): void {
		$this->messageMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WhatsAppMessage $message) {
				return $message->getMessageId() === 'msg_new'
					&& $message->getRecipient() === '5511999999999'
					&& $message->getMessageText() === 'Test message'
					&& $message->getStatus() === 'sent';
			}));

		$this->service->registerSentMessage(
			'msg_new',
			'5511999999999',
			'Test message',
			'text',
			'123456789',
		);
	}

	public function testGetMessageEvents(): void {
		$event = new WhatsAppEvent();
		$event->setMessageId('msg_001');

		$this->eventMapper->expects($this->once())
			->method('findByMessageId')
			->with('msg_001')
			->willReturn($event);

		$result = $this->service->getMessageEvents('msg_001');

		$this->assertCount(1, $result);
		$this->assertEquals('msg_001', $result[0]->getMessageId());
	}

	public function testGetRecipientEvents(): void {
		$events = [
			new WhatsAppEvent(),
			new WhatsAppEvent(),
		];

		$this->eventMapper->expects($this->once())
			->method('findByRecipient')
			->with('5511999999999', null, 50)
			->willReturn($events);

		$result = $this->service->getRecipientEvents('5511999999999');

		$this->assertCount(2, $result);
	}

	public function testGetMessageStatus(): void {
		$message = new WhatsAppMessage();
		$message->setMessageId('msg_001');
		$message->setStatus('delivered');

		$this->messageMapper->expects($this->once())
			->method('findByMessageId')
			->with('msg_001')
			->willReturn($message);

		$result = $this->service->getMessageStatus('msg_001');

		$this->assertNotNull($result);
		$this->assertEquals('delivered', $result->getStatus());
	}

	public function testExtractMessageContentText(): void {
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				[
					'id' => '123456789',
					'changes' => [
						[
							'field' => 'messages',
							'value' => [
								'messaging_product' => 'whatsapp',
								'messages' => [
									[
										'id' => 'msg_text',
										'from' => '5511999999999',
										'timestamp' => 1234567890,
										'type' => 'text',
										'text' => [
											'body' => 'Hello from test',
										],
									],
								],
							],
						],
					],
				],
			],
		];

		$this->eventMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WhatsAppEvent $event) {
				return $event->getContent() === 'Hello from test';
			}));

		$this->service->processWebhook($payload);
	}

	public function testExtractMessageContentImage(): void {
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				[
					'id' => '123456789',
					'changes' => [
						[
							'field' => 'messages',
							'value' => [
								'messaging_product' => 'whatsapp',
								'messages' => [
									[
										'id' => 'msg_image',
										'from' => '5511999999999',
										'timestamp' => 1234567890,
										'type' => 'image',
										'image' => [
											'caption' => 'My photo',
										],
									],
								],
							],
						],
					],
				],
			],
		];

		$this->eventMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WhatsAppEvent $event) {
				return $event->getContent() === 'My photo';
			}));

		$this->service->processWebhook($payload);
	}
}
