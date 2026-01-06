# Guia de Integração: Usando twofactor_gateway em Aplicações Nextcloud

## 🎯 Objetivo

Este guia mostra como integrar o `twofactor_gateway` em sua aplicação Nextcloud para enviar mensagens via SMS, WhatsApp, Signal, Telegram ou XMPP, seguindo o padrão usado pelo LibreSign.

---

## 📋 Pré-requisitos

1. ✅ Nextcloud instalado
2. ✅ `twofactor_gateway` instalado e habilitado
3. ✅ Gateway configurado (e.g., WhatsApp Cloud API com credenciais válidas)
4. ✅ Sua aplicação Nextcloud desenvolvida

---

## 🔧 Passo 1: Verificar se o Gateway está Disponível

### Método A: Verificar se app está ativada

```php
<?php

namespace OCA\YourApp\Service;

use OCP\App\IAppManager;

class NotificationService {
    public function __construct(
        private IAppManager $appManager,
    ) {}
    
    public function isTwofactorGatewayEnabled(): bool {
        return $this->appManager->isEnabledForAnyone('twofactor_gateway');
    }
}
```

### Método B: Verificar se gateway específico está configurado (RECOMENDADO)

```php
<?php

namespace OCA\YourApp\Service;

use OCA\TwoFactorGateway\Provider\Gateway\Factory;
use OCP\Server;

class NotificationService {
    public function isWhatsAppConfigured(): bool {
        try {
            // 1. Obtém factory via injeção de dependência
            $factory = Server::get(Factory::class);
            
            // 2. Obtém gateway específico
            $gateway = $factory->getGateway('whatsapp');
            
            // 3. Verifica se está completo
            return $gateway->getConfig()->isComplete();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getSupportedGateways(): array {
        $supported = [];
        foreach (['sms', 'whatsapp', 'signal', 'telegram', 'xmpp'] as $name) {
            if ($this->isGatewayConfigured($name)) {
                $supported[] = $name;
            }
        }
        return $supported;
    }
    
    private function isGatewayConfigured(string $name): bool {
        try {
            $factory = Server::get(Factory::class);
            $gateway = $factory->getGateway($name);
            return $gateway->getConfig()->isComplete();
        } catch (\Exception) {
            return false;
        }
    }
}
```

---

## 🚀 Passo 2: Enviar Mensagem Simples

### Padrão Básico

```php
<?php

namespace OCA\YourApp\Service;

use OCA\TwoFactorGateway\Provider\Gateway\Factory;
use OCP\Server;
use Psr\Log\LoggerInterface;

class MessageService {
    public function __construct(
        private LoggerInterface $logger,
    ) {}
    
    public function sendMessage(
        string $phoneNumber,
        string $message,
        string $gateway = 'whatsapp'
    ): bool {
        try {
            // 1. Obtém factory
            $factory = Server::get(Factory::class);
            
            // 2. Obtém gateway
            $gwInstance = $factory->getGateway($gateway);
            
            // 3. Envia mensagem
            $gwInstance->send($phoneNumber, $message);
            
            $this->logger->info('Message sent successfully', [
                'gateway' => $gateway,
                'phone' => substr($phoneNumber, -4),  // Log apenas últimos 4 dígitos
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send message', [
                'gateway' => $gateway,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
```

### Uso

```php
$messageService = Server::get(MessageService::class);
$messageService->sendMessage(
    '+55 11 99999-9999',
    'Your verification code is: 123456',
    'whatsapp'
);
```

---

## 🔐 Passo 3: Gerar e Enviar Código de Verificação

### Implementação Segura

```php
<?php

namespace OCA\YourApp\Service;

use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OCP\Server;

class VerificationCodeService {
    private const CODE_LENGTH = 6;
    
    public function __construct(
        private ISecureRandom $secureRandom,
        private IHasher $hasher,
        private MessageService $messageService,
    ) {}
    
    /**
     * Gera código de verificação e envia
     * 
     * @return string Código hash (armazenar no DB)
     */
    public function generateAndSendCode(
        string $identifier,
        string $gateway = 'whatsapp'
    ): ?string {
        try {
            // 1. Gera código random de 6 dígitos
            $code = $this->secureRandom->generate(
                self::CODE_LENGTH,
                ISecureRandom::CHAR_DIGITS
            );
            
            // 2. Prepara mensagem
            $message = sprintf(
                'Your verification code is: %s. Do not share with anyone.',
                $code
            );
            
            // 3. Envia via gateway
            if (!$this->messageService->sendMessage($identifier, $message, $gateway)) {
                return null;
            }
            
            // 4. Retorna código hash (NUNCA retorna código em texto plano!)
            return $this->hasher->hash($code);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Valida código fornecido
     */
    public function validateCode(
        string $userInput,
        string $storedHash
    ): bool {
        return $this->hasher->verify($userInput, $storedHash);
    }
}
```

### Uso

```php
// Gerar e enviar
$codeService = Server::get(VerificationCodeService::class);
$hashedCode = $codeService->generateAndSendCode(
    '+55 11 99999-9999',
    'whatsapp'
);

// Armazenar hash no banco de dados
// ...

// Depois, validar
$userInput = '123456';  // Do formulário
if ($codeService->validateCode($userInput, $hashedCode)) {
    // ✅ Código válido
} else {
    // ❌ Código inválido
}
```

---

## 🎨 Passo 4: Enviar Notificações via Event Listeners

### Criar um Evento Customizado

```php
<?php

namespace OCA\YourApp\Events;

use OCP\EventDispatcher\Event;

class FileProcessedEvent extends Event {
    public function __construct(
        private string $fileName,
        private string $recipientPhone,
    ) {}
    
    public function getFileName(): string {
        return $this->fileName;
    }
    
    public function getRecipientPhone(): string {
        return $this->recipientPhone;
    }
}
```

### Criar um Listener

```php
<?php

namespace OCA\YourApp\Listener;

use OCA\TwoFactorGateway\Provider\Gateway\Factory;
use OCA\YourApp\Events\FileProcessedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Server;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<FileProcessedEvent> */
class SendNotificationListener implements IEventListener {
    public function __construct(
        private LoggerInterface $logger,
    ) {}
    
    public function handle(Event $event): void {
        if (!$event instanceof FileProcessedEvent) {
            return;
        }
        
        try {
            // 1. Prepara mensagem
            $message = sprintf(
                'Your file "%s" has been processed successfully!',
                $event->getFileName()
            );
            
            // 2. Obtém gateway
            $factory = Server::get(Factory::class);
            $gateway = $factory->getGateway('whatsapp');
            
            // 3. Envia
            $gateway->send($event->getRecipientPhone(), $message);
            
            $this->logger->info('Notification sent', [
                'file' => $event->getFileName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send notification', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
```

### Registrar o Listener (em `appinfo/info.xml` ou via code)

```php
// Em algum Bootstrap/Service
public function register(): void {
    $this->dispatcher->addListener(
        FileProcessedEvent::class,
        SendNotificationListener::class
    );
}
```

### Disparar o Evento

```php
<?php

namespace OCA\YourApp\Service;

use OCA\YourApp\Events\FileProcessedEvent;
use OCP\EventDispatcher\IEventDispatcher;

class FileService {
    public function __construct(
        private IEventDispatcher $dispatcher,
    ) {}
    
    public function processFile(string $filePath, string $userPhone): void {
        // ... processar arquivo ...
        
        // Disparar evento de notificação
        $this->dispatcher->dispatch(
            new FileProcessedEvent(
                basename($filePath),
                $userPhone
            )
        );
    }
}
```

---

## 📱 Passo 5: Suportar Múltiplos Gateways

### Classe que Suporta Múltiplos Canais

```php
<?php

namespace OCA\YourApp\Service;

use OCA\TwoFactorGateway\Provider\Gateway\Factory;
use OCP\IL10N;
use OCP\Server;
use Psr\Log\LoggerInterface;

class MultiChannelNotification {
    public function __construct(
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {}
    
    /**
     * Envia notificação via canal preferido (com fallback)
     */
    public function sendNotification(
        string $identifier,
        string $message,
        array $preferredChannels = ['whatsapp', 'sms', 'email'],
    ): bool {
        foreach ($preferredChannels as $channel) {
            if ($this->send($identifier, $message, $channel)) {
                return true;  // Sucesso, para por aqui
            }
        }
        
        // Nenhum canal funcionou
        $this->logger->error('All notification channels failed', [
            'channels' => $preferredChannels,
        ]);
        return false;
    }
    
    /**
     * Envia via canal específico
     */
    private function send(
        string $identifier,
        string $message,
        string $channel
    ): bool {
        try {
            $factory = Server::get(Factory::class);
            $gateway = $factory->getGateway($channel);
            
            if (!$gateway->getConfig()->isComplete()) {
                return false;
            }
            
            $gateway->send($identifier, $message);
            
            $this->logger->info('Notification sent via channel', [
                'channel' => $channel,
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->debug('Channel failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Lista canais disponíveis
     */
    public function getAvailableChannels(): array {
        $available = [];
        foreach (['sms', 'whatsapp', 'signal', 'telegram', 'xmpp'] as $channel) {
            try {
                $factory = Server::get(Factory::class);
                $gateway = $factory->getGateway($channel);
                if ($gateway->getConfig()->isComplete()) {
                    $available[$channel] = $this->l10n->t(ucfirst($channel));
                }
            } catch (\Exception) {
                // Ignorar
            }
        }
        return $available;
    }
}
```

### Uso

```php
$notification = Server::get(MultiChannelNotification::class);

// Tentar enviar em ordem de preferência
$notification->sendNotification(
    '+55 11 99999-9999',
    'Important message',
    ['whatsapp', 'sms']  // Tenta WhatsApp primeiro, depois SMS
);

// Listar canais disponíveis para UI
$channels = $notification->getAvailableChannels();
// Resultado: ['whatsapp' => 'Whatsapp', 'sms' => 'Sms']
```

---

## 🏗️ Passo 6: Integração em Controller REST

### Exemplo de API Endpoint

```php
<?php

namespace OCA\YourApp\Controller;

use OCA\YourApp\Service\MessageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class NotificationController extends Controller {
    public function __construct(
        IRequest $request,
        private MessageService $messageService,
        private LoggerInterface $logger,
    ) {
        parent::__construct('yourapp', $request);
    }
    
    /**
     * POST /api/v1/notify
     * 
     * @param string $phone
     * @param string $message
     * @param string $gateway
     * @return DataResponse
     */
    public function sendNotification(
        string $phone = '',
        string $message = '',
        string $gateway = 'whatsapp'
    ): DataResponse {
        // Validação
        if (empty($phone) || empty($message)) {
            return new DataResponse([
                'error' => 'Missing required parameters: phone, message'
            ], 400);
        }
        
        // Valida formato de telefone básico
        if (!preg_match('/^\+?[\d\s\-()]{10,}$/', $phone)) {
            return new DataResponse([
                'error' => 'Invalid phone format'
            ], 400);
        }
        
        // Valida comprimento da mensagem
        if (strlen($message) > 1000) {
            return new DataResponse([
                'error' => 'Message too long'
            ], 400);
        }
        
        // Tenta enviar
        if ($this->messageService->sendMessage($phone, $message, $gateway)) {
            return new DataResponse([
                'success' => true,
                'message' => 'Notification sent'
            ], 200);
        } else {
            return new DataResponse([
                'error' => 'Failed to send notification'
            ], 500);
        }
    }
}
```

### Registrar Rota (em routes.php)

```php
<?php

return [
    'routes' => [
        // POST /apps/yourapp/api/v1/notify
        ['name' => 'Notification#sendNotification', 'url' => '/api/v1/notify', 'verb' => 'POST'],
    ]
];
```

---

## 🔒 Passo 7: Validação e Segurança

### Validar Credenciais Antes de Usar

```php
<?php

namespace OCA\YourApp\Service;

use OCA\TwoFactorGateway\Provider\Gateway\Factory;
use OCA\YourApp\Exception\ConfigurationException;
use OCP\Server;

class GatewayValidator {
    public function validateGateway(string $gatewayName): bool {
        try {
            $factory = Server::get(Factory::class);
            $gateway = $factory->getGateway($gatewayName);
            
            if (!$gateway->getConfig()->isComplete()) {
                throw new ConfigurationException(
                    "Gateway '$gatewayName' is not properly configured"
                );
            }
            
            // Alguns gateways permitem teste de conexão
            if (method_exists($gateway, 'validateConfig')) {
                $gateway->validateConfig();
            }
            
            return true;
        } catch (\Exception $e) {
            throw new ConfigurationException(
                "Gateway validation failed: " . $e->getMessage()
            );
        }
    }
}
```

### Rate Limiting (Opcional)

```php
<?php

namespace OCA\YourApp\Service;

use OCP\IAppConfig;
use OCP\IUserSession;

class RateLimiter {
    private const RATE_LIMIT_KEY = 'notification_rate_limit_';
    private const MAX_ATTEMPTS = 5;
    private const TIME_WINDOW = 3600;  // 1 hora
    
    public function __construct(
        private IAppConfig $appConfig,
        private IUserSession $userSession,
    ) {}
    
    public function isAllowed(string $identifier): bool {
        $key = self::RATE_LIMIT_KEY . md5($identifier);
        $count = (int) $this->appConfig->getValueString('yourapp', $key, '0');
        
        if ($count >= self::MAX_ATTEMPTS) {
            return false;
        }
        
        $this->appConfig->setValueString('yourapp', $key, (string)($count + 1));
        
        return true;
    }
    
    public function resetLimit(string $identifier): void {
        $key = self::RATE_LIMIT_KEY . md5($identifier);
        $this->appConfig->deleteKey('yourapp', $key);
    }
}
```

---

## 📊 Comparativo: LibreSign vs Seu Projeto

| Aspecto | LibreSign | Seu Projeto |
|---------|-----------|------------|
| Verificação | ✅ `isTwofactorGatewayEnabled()` | ✅ Similar recomendado |
| Envio de código | ✅ `TokenService::sendCodeByGateway()` | ✅ `VerificationCodeService` |
| Notificações | ✅ Event Listeners | ✅ MultiChannelNotification |
| Segurança | ✅ Código hasheado | ✅ Código hasheado |
| Multi-canal | ✅ 5 canais + email | ✅ Suporta todos |
| Error handling | ✅ Robusto | ✅ Recomendado |

---

## 🧪 Testando a Integração

### Teste Unitário

```php
<?php

namespace OCA\YourApp\Tests\Unit\Service;

use OCA\TwoFactorGateway\Provider\Gateway\Factory;
use OCA\YourApp\Service\MessageService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

class MessageServiceTest extends TestCase {
    private MessageService $service;
    
    protected function setUp(): void {
        $this->service = new MessageService(
            $this->createMock(LoggerInterface::class),
        );
    }
    
    public function testSendMessage(): void {
        // Mock factory
        $factory = $this->createMock(Factory::class);
        $gateway = $this->createMock(IGateway::class);
        
        $factory->expects($this->once())
            ->method('getGateway')
            ->with('whatsapp')
            ->willReturn($gateway);
        
        $gateway->expects($this->once())
            ->method('send')
            ->with('+55 11 99999-9999', 'Test message');
        
        // Testar
        $result = $this->service->sendMessage(
            '+55 11 99999-9999',
            'Test message',
            'whatsapp'
        );
        
        $this->assertTrue($result);
    }
}
```

### Teste Manual via CLI

```bash
# Verificar se gateway está configurado
php occ twofactor_gateway:status whatsapp

# Testar envio
php occ twofactor_gateway:test whatsapp +55119999999999 "Test message"

# Verificar logs
tail -f /var/www/nextcloud/data/nextcloud.log
```

---

## 🎯 Checklist de Implementação

- [ ] Instalado `twofactor_gateway`
- [ ] Gateway (WhatsApp/SMS/etc) configurado
- [ ] Implementada verificação de disponibilidade
- [ ] Implementado serviço de mensagem
- [ ] Geração segura de códigos
- [ ] Validação de códigos hasheados
- [ ] Event listeners criados
- [ ] Suporte a múltiplos canais
- [ ] Testes unitários
- [ ] Tratamento de erros robusto
- [ ] Logging implementado
- [ ] Documentação de configuração
- [ ] Rate limiting (opcional)

---

## 📚 Referências Úteis

- [twofactor_gateway](https://github.com/nextcloud/twofactor_gateway)
- [LibreSign Integration](./LIBRESIGN_INTEGRATION_ANALYSIS.md)
- [WhatsApp Cloud API](./WHATSAPP_CLOUD_API.md)
- [Nextcloud App Development](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-api-overview.html)

---

## 💬 Perguntas Frequentes

### P: Como suportar múltiplos gateways?

R: Use `MultiChannelNotification` com array de canais preferidos. O serviço tentará cada um até sucesso.

### P: Qual é o tamanho máximo da mensagem?

R: Depende do gateway. WhatsApp Cloud API suporta até 4096 caracteres.

### P: E se o twofactor_gateway não estiver instalado?

R: Use try-catch. O código já lida com `NotFoundExceptionInterface` do container.

### P: Como criptografar números de telefone?

R: Use `OCP\Security\ICrypto` do Nextcloud:
```php
$encrypted = $this->crypto->encrypt($phoneNumber);
$decrypted = $this->crypto->decrypt($encrypted);
```

### P: Posso enviar imagens/mídia via WhatsApp?

R: Sim, a CloudApiDriver do projeto suporta tipos de mensagem "image", "document", etc. Veja `WHATSAPP_CLOUD_API.md`.

---

## 🤝 Contribuindo

Se encontrar problemas ou tiver melhorias, abra issue ou pull request no repositório do projeto.

