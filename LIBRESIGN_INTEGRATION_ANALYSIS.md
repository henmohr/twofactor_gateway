# LibreSign Integration with Twofactor Gateway - Análise Completa

## 📋 Resumo Executivo

O LibreSign integra com o `twofactor_gateway` para enviar **notificações e códigos de verificação** via múltiplos canais (SMS, WhatsApp, Signal, Telegram, XMPP) para signatários de documentos PDF. A integração é feita através de:

1. **Factory Pattern** - Obtém gateway via `Factory::getGateway()`
2. **Event Listeners** - Escuta eventos de assinatura
3. **Service Layer** - `TokenService` encapsula lógica de envio
4. **Identify Method** - Suporta múltiplos métodos de identificação

---

## 🏗️ Arquitetura da Integração

```
┌──────────────────────────────────────────────────────────────┐
│  LibreSign Document Signature Flow                           │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  1. User requests signature                                 │
│     ↓                                                        │
│  2. Identify Method selected (SMS, WhatsApp, etc)          │
│     ├─ TwofactorGateway (via Factory)                      │
│     ├─ Email (via MailService)                            │
│     └─ Nextcloud login                                      │
│     ↓                                                        │
│  3. Code generated & sent                                   │
│     ├─ TokenService::sendCodeByGateway()                   │
│     │  ├─ Get Gateway via Factory                          │
│     │  ├─ Generate 6-digit code                            │
│     │  └─ gateway->send(identifier, message)               │
│     ↓                                                        │
│  4. Notifications sent via Listener                         │
│     └─ TwofactorGatewayListener (on SignedEvent)           │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## 📊 Fluxo Detalhado de Funcionamento

### 1️⃣ **Inicialização - Verificação de Disponibilidade**

**Arquivo**: `lib/Service/IdentifyMethod/TwofactorGateway.php` (linha 60-73)

```php
public function isTwofactorGatewayEnabled(): bool {
    // 1. Verifica se app está habilitada
    $isAppEnabled = $this->appManager->isEnabledForAnyone('twofactor_gateway');
    if (!$isAppEnabled) {
        return false;
    }
    
    // 2. Obtém Factory do container
    $gatewayFactory = Server::get(Factory::class);
    
    // 3. Pega gateway (e.g., 'whatsapp' ou 'sms')
    $gatewayName = strtolower($this->getId()); // 'whatsapp'
    $gateway = $gatewayFactory->get($gatewayName);
    
    // 4. Verifica se está configurado
    return $gateway->isComplete();
}
```

**Fluxo:**
1. LibreSign verifica se `twofactor_gateway` está habilitada
2. Usa `Server::get()` (injeção de dependência Nextcloud)
3. Obtém factory que instancia o gateway correto
4. Valida se credenciais estão completas

---

### 2️⃣ **Envio de Código - TokenService**

**Arquivo**: `lib/Service/IdentifyMethod/SignatureMethod/TokenService.php` (linha 31-37)

```php
public function sendCodeByGateway(string $identifier, string $gatewayName): string {
    // 1. Obtém gateway
    $gateway = $this->getGateway($gatewayName);
    
    // 2. Gera código de 6 dígitos
    $code = $this->secureRandom->generate(
        self::TOKEN_LENGTH,  // 6
        ISecureRandom::CHAR_DIGITS  // '0-9'
    );
    
    // 3. Envia via gateway
    $gateway->send(
        $identifier,  // número de telefone/ID do usuário
        $this->l10n->t('%s is your LibreSign verification code.', $code)
    );
    
    // 4. Retorna código hash (não em texto plano)
    return $this->hasher->hash($code);
}
```

**Detalhe do Gateway Factory** (linha 43-54):

```php
private function getGateway(string $gatewayName) {
    try {
        // Obtém Factory do container de serviços
        $factory = Server::get(\OCA\TwoFactorGateway\Provider\Gateway\Factory::class);
    } catch (NotFoundExceptionInterface) {
        throw new LibresignException('App Two-Factor Gateway is not installed.');
    }
    
    // Usa getGateway() (não get()) - nota a diferença!
    $gateway = $factory->getGateway($gatewayName);
    
    // Valida configuração
    if (!$gateway->getConfig()->isComplete()) {
        throw new OCSForbiddenException(
            $this->l10n->t('Gateway %s not configured on Two-Factor Gateway.', $gatewayName)
        );
    }
    
    return $gateway;
}
```

**Integração com twofactor_gateway:**
- Chama `Factory::getGateway('whatsapp')`
- Obtém instância configurada do Gateway
- Valida `isComplete()` antes de usar
- Envia mensagem com `gateway->send()`

---

### 3️⃣ **Notificações via Event Listeners**

**Arquivo**: `lib/Listener/TwofactorGatewayListener.php`

Há dois eventos principais:

#### A) **SendSignNotificationEvent** - Notificar para assinar (linha 61-109)

```php
protected function sendSignNotification(
    SignRequest $signRequest,
    IIdentifyMethod $identifyMethod,
    FileEntity $libreSignFile,
): void {
    try {
        $entity = $identifyMethod->getEntity();
        
        // 1. Verifica se é deletado
        if ($entity->isDeletedAccount()) {
            return;
        }
        
        // 2. Verifica se é um método suportado
        if (!in_array(
            $entity->getIdentifierKey(),
            ['sms', 'signal', 'telegram', 'whatsapp', 'xmpp'],
            true
        )) {
            return;
        }
        
        // 3. Valida app habilitada
        if (!$this->appManager->isEnabledForAnyone('twofactor_gateway')) {
            return;
        }
        
        $identifier = $entity->getIdentifierValue();
        if (empty($identifier)) {
            return;
        }
        
        // 4. Incrementa contador de notificações
        $isFirstNotification = $this->signRequestMapper
            ->incrementNotificationCounter($signRequest, $entity->getIdentifierKey());
        
        // 5. Constrói mensagem
        if ($isFirstNotification) {
            $message = $this->l10n->t('There is a document for you to sign. Access the link below:');
        } else {
            $message = $this->l10n->t('Changes have been made in a file that you have to sign. Access the link below:');
        }
        $message .= "\n";
        $link = $this->urlGenerator->linkToRouteAbsolute(
            'libresign.page.sign',
            ['uuid' => $signRequest->getUuid()]
        );
        $message .= $libreSignFile->getName() . ': ' . $link;
        
        // 6. Obtém gateway via Factory
        $gatewayFactory = Server::get(Factory::class);
        $gateway = $gatewayFactory->get(strtolower($entity->getIdentifierKey()));
        
        // 7. Envia notificação
        try {
            $gateway->send($identifier, $message);
        } catch (Exception $e) {
            $this->logger->error('Could not send 2FA message', [
                'identifier' => $identifier,
                'exception' => $e,
            ]);
            return;
        }
    } catch (\InvalidArgumentException $e) {
        $this->logger->error($e->getMessage(), ['exception' => $e]);
        return;
    }
}
```

#### B) **SignedEvent** - Notificar que foi assinado (linha 111-159)

Mesma lógica, mas:
- Dispara quando documento é assinado
- Mensagem diferente: `"LibreSign: A file has been signed"`
- Informa quem assinou: `"%s signed the document..."`

**Fluxo de Eventos:**
```
Document Signature Flow:
1. SignRequest created
   ↓
2. SendSignNotificationEvent fired
   ├─ TwofactorGatewayListener::sendSignNotification()
   └─ Envia: "There is a document for you to sign..."
   ↓
3. User signs document
   ↓
4. SignedEvent fired
   ├─ TwofactorGatewayListener::sendSignedNotification()
   └─ Envia: "Document has been signed..."
```

---

## 🔌 Métodos de Integração

### A) **Direto via TokenService** (para códigos)

```php
$tokenService->sendCodeByGateway(
    $phoneNumber,      // "+55 11 99999-9999"
    'whatsapp'         // gateway name
);
// Retorna: hash do código (armazenado no DB)
```

**Usado em:**
- Fluxo de verificação de assinatura
- Geração de tokens únicos de 6 dígitos

### B) **Via Event Listener** (para notificações)

```php
// Event disparado automaticamente
$this->dispatcher->dispatch(new SendSignNotificationEvent(
    $signRequest,
    $identifyMethod,
    $libreSignFile
));

// TwofactorGatewayListener processa e envia
```

**Usado em:**
- Notificação de assinatura pendente
- Notificação de documento assinado

### C) **Via Factory diretamente**

```php
$factory = Server::get(Factory::class);
$gateway = $factory->getGateway('whatsapp');
$gateway->send($phoneNumber, $message);
```

---

## 📱 Métodos de Identificação Suportados

LibreSign suporta estes gateways (através do twofactor_gateway):

| Gateway | Canal | Tipo | Usado Para |
|---------|-------|------|-----------|
| `whatsapp` | WhatsApp | Mensagem | Códigos + Notificações |
| `sms` | SMS/Telefone | SMS | Códigos + Notificações |
| `signal` | Signal | App | Códigos + Notificações |
| `telegram` | Telegram | App | Códigos + Notificações |
| `xmpp` | XMPP | Protocolo | Códigos + Notificações |
| `email` | Email | Email | Códigos + Notificações (nativo) |

---

## 🔄 Fluxo Completo: Assinatura com WhatsApp

```
1. Admin configura twofactor_gateway com WhatsApp Cloud API
   ├─ Phone Number ID
   ├─ Business Account ID
   └─ API Token

2. LibreSign descobre gateway disponível
   ├─ isTwofactorGatewayEnabled() → true
   └─ Mostra "WhatsApp" como opção de método de identificação

3. Signatário inicia processo de assinatura
   ├─ Seleciona "WhatsApp" como método
   └─ Fornece número de telefone

4. LibreSign gera código e envia via WhatsApp
   ├─ TokenService::sendCodeByGateway('5511999999999', 'whatsapp')
   ├─ Gateway valida credenciais
   ├─ CloudApiDriver prepara requisição:
   │  POST https://graph.facebook.com/v14.0/{phone_number_id}/messages
   │  ├─ to: 5511999999999
   │  └─ text: "123456 is your LibreSign verification code."
   └─ Código hash armazenado no DB

5. Signatário recebe código no WhatsApp
   ├─ Abre LibreSign
   └─ Insere código

6. LibreSign valida código
   ├─ Compara hash
   └─ Assinatura aceita

7. SendSignNotificationEvent dispara
   ├─ Cria mensagem com link do documento
   ├─ Chama TwofactorGatewayListener
   └─ Envia via WhatsApp (se configurado)

8. Documento é assinado por todos
   ↓

9. SignedEvent dispara
   ├─ Notifica signatários de quem assinou
   ├─ Chama TwofactorGatewayListener
   └─ Envia notificação via WhatsApp
```

---

## 🔐 Segurança na Integração

### ✅ **Boas práticas implementadas:**

1. **Código nunca fica em texto plano**
   ```php
   return $this->hasher->hash($code);  // SHA256
   ```

2. **Validação de configuração**
   ```php
   if (!$gateway->getConfig()->isComplete()) {
       throw new OCSForbiddenException(...);
   }
   ```

3. **Tratamento de erros robusto**
   ```php
   try {
       $gateway->send($identifier, $message);
   } catch (Exception $e) {
       $this->logger->error(...);
       return;  // Não propaga exceção
   }
   ```

4. **Verificação de conta deletada**
   ```php
   if ($entity->isDeletedAccount()) {
       return;
   }
   ```

5. **Logging sem exposição de dados sensíveis**
   ```php
   $this->logger->error('Could not send 2FA message', [
       'identifier' => $identifier,  // ⚠️ Poderia ter hash
       'exception' => $e,
   ]);
   ```

6. **Injeção de dependência Nextcloud**
   - Não faz `new Factory()` direto
   - Usa `Server::get()` para singleton

---

## 💡 Insights da Integração

### **O que LibreSign faz certo:**

1. ✅ **Abstração limpa** - Não conhece detalhes internos do gateway
2. ✅ **Fail-safe** - Se twofactor_gateway não está configurado, retorna erro claro
3. ✅ **Multi-canal** - Suporta SMS, WhatsApp, Signal, Telegram, XMPP
4. ✅ **Event-driven** - Usa listeners, não chamadas diretas
5. ✅ **Código seguro** - Hashing de códigos, validações

### **O que poderia melhorar:**

1. ⚠️ **Armazenamento de identifier** - Poderia hash/encrypt números de telefone
2. ⚠️ **Rate limiting** - Não há limite de tentativas de envio
3. ⚠️ **Retry automático** - Se falhar, não tenta novamente
4. ⚠️ **Fallback** - Se WhatsApp falhar, não oferece alternativa
5. ⚠️ **Templates** - Mensagens são construídas manualmente

---

## 🔍 Comparação com Padrão do Chatwoot

### LibreSign vs Chatwoot na Integração:

| Aspecto | Chatwoot | LibreSign |
|---------|----------|----------|
| **Acesso ao Gateway** | Direto | Via Factory |
| **Evento trigger** | HTTP Webhook | Event Dispatcher |
| **Mensagens** | Templates | Construídas via L10N |
| **Error Handling** | Propaga | Silencioso + Log |
| **Multi-gateway** | ✅ Sim | ✅ Sim (6 tipos) |
| **Fallback** | ❌ Não | ❌ Não |
| **Rate Limiting** | ❌ Não | ❌ Não |

---

## 📚 Arquivos Principais

| Arquivo | Responsabilidade |
|---------|------------------|
| `TwofactorGateway.php` | Identify method - valida disponibilidade |
| `TwofactorGatewayToken.php` | Token signature - requisição e validação de código |
| `TokenService.php` | **CORE** - Gera e envia códigos |
| `TwofactorGatewayListener.php` | **CORE** - Envia notificações via eventos |

---

## 🚀 Como Usar no Seu Projeto

### 1. **Verificar se Gateway está disponível**
```php
$identifyService = Server::get(IdentifyService::class);
$twofactorMethod = $identifyService->getTwofactorGateway();
if ($twofactorMethod->isTwofactorGatewayEnabled()) {
    // Usar WhatsApp/SMS/etc
}
```

### 2. **Enviar código**
```php
$tokenService = Server::get(TokenService::class);
$hashedCode = $tokenService->sendCodeByGateway(
    '+55 11 99999-9999',
    'whatsapp'
);
```

### 3. **Enviar notificação**
```php
$this->dispatcher->dispatch(new SendSignNotificationEvent(
    $signRequest,
    $identifyMethod,
    $file
));
```

---

## 📞 Conclusão

LibreSign implementa uma integração **limpa, segura e bem estruturada** com o twofactor_gateway:

✅ **Pontos fortes:**
- Usa Factory Pattern corretamente
- Abstração adequada da implementação
- Tratamento de erros robusto
- Multi-canal suportado
- Event-driven architecture

⚠️ **Melhorias sugeridas:**
- Adicionar rate limiting
- Implementar retry automático
- Criptografar números de telefone armazenados
- Adicionar fallback de gateways
- Melhorar logging (sem expor identifiers)

A implementação segue as **melhores práticas do Nextcloud** e é um exemplo de como integrar corretamente com o twofactor_gateway em apps de terceiros.

---

**Fontes:**
- `/home/mohr/git/libresign/lib/Service/IdentifyMethod/TwofactorGateway.php`
- `/home/mohr/git/libresign/lib/Service/IdentifyMethod/SignatureMethod/TokenService.php`
- `/home/mohr/git/libresign/lib/Listener/TwofactorGatewayListener.php`
- `/home/mohr/git/libresign/lib/Service/IdentifyMethod/SignatureMethod/TwofactorGatewayToken.php`
