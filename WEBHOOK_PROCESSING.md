# WhatsApp Cloud API - Webhook Processing

## 📋 Resumo

Implementação completa de processamento de webhooks do WhatsApp Cloud API, incluindo:

✅ **Processamento de Mensagens Recebidas** - Armazenamento de mensagens de entrada
✅ **Processamento de Status de Entrega** - Rastreamento de sent, delivered, read, failed
✅ **Armazenamento de Eventos** - Banco de dados estruturado para auditoria e análise
✅ **Testes Unitários** - Cobertura completa de cenários

---

## 🏗️ Arquitetura

```
┌──────────────────────────────────────────────────────────┐
│ WhatsApp Cloud API (Meta)                                 │
└──────────────────────────────────────────────────────────┘
           ↓ Webhook POST
┌──────────────────────────────────────────────────────────┐
│ WhatsAppWebhookController::webhook()                      │
│ - Verifica autenticidade                                  │
│ - Extrai payload                                          │
│ - Valida estrutura                                        │
└──────────────────────────────────────────────────────────┘
           ↓
┌──────────────────────────────────────────────────────────┐
│ WebhookProcessorService                                   │
│ - Processa mensagens recebidas                            │
│ - Processa status de entrega                              │
│ - Extrai conteúdo por tipo                                │
│ - Mapeia status Meta para nosso formato                   │
└──────────────────────────────────────────────────────────┘
           ↓
┌──────────────────────────────────────────────────────────┐
│ Banco de Dados                                             │
│ - whatsapp_events (todos os eventos)                      │
│ - whatsapp_messages (mensagens enviadas)                  │
└──────────────────────────────────────────────────────────┘
```

---

## 📊 Tabelas de Banco de Dados

### `whatsapp_events`

Armazena todos os eventos de webhook (mensagens, status, erros):

```
id (PK)                  // ID único
message_id              // ID do webhook Meta
phone_number_id         // ID do número de telefone
recipient               // Número do destinatário
sender                  // Número do remetente (para mensagens recebidas)
event_type              // Tipo: message_received, message_sent, message_delivered, etc
status                  // Status: sent, delivered, read, failed, received
message_type            // Tipo da mensagem: text, image, video, document, audio, etc
content                 // Conteúdo ou descrição
webhook_payload         // Payload JSON completo (debug)
error_code              // Código de erro (se houver)
error_message           // Mensagem de erro
timestamp               // Timestamp do Meta
created_at              // Quando foi armazenado
updated_at              // Última atualização
```

### `whatsapp_messages`

Rastreia mensagens que **nós** enviamos:

```
id (PK)                 // ID único
message_id              // ID retornado pelo Meta (único)
phone_number_id         // ID do número usado para enviar
recipient               // Número do destinatário
message_text            // Texto da mensagem
message_type            // Tipo: text, template, image, etc
status                  // Status: pending, sent, delivered, read, failed
metadata                // JSON com dados adicionais
created_at              // Quando foi enviada
updated_at              // Última atualização de status
```

---

## 🔄 Fluxo de Processamento

### 1. Webhook Recebido

Meta envia POST para: `/apps/twofactor_gateway/api/v1/webhooks/whatsapp`

```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "id": "PHONE_NUMBER_ID",
    "changes": [{
      "field": "messages",
      "value": {
        "messaging_product": "whatsapp",
        "metadata": {
          "display_phone_number": "16315551234",
          "phone_number_id": "PHONE_NUMBER_ID"
        },
        "messages": [
          {
            "id": "wamid.xxx",
            "from": "5511999999999",
            "timestamp": 1234567890,
            "type": "text",
            "text": {"body": "Hello!"}
          }
        ],
        "statuses": [
          {
            "id": "wamid.yyy",
            "status": "delivered",
            "timestamp": 1234567891,
            "recipient_id": "5511999999999"
          }
        ]
      }
    }]
  }]
}
```

### 2. Validação

Controller valida:
- ✅ Estrutura básica (`entry`, `changes`, `value`)
- ✅ Presença de `messages` ou `statuses`
- ✅ Responde com 200 rapidamente (não bloqueia)

### 3. Processamento Paralelo

Service processa em background:

#### A. Mensagens Recebidas
```php
foreach ($webhook['entry'][0]['changes'][0]['value']['messages'] as $msg) {
    // Extrair sender, tipo, conteúdo
    // Armazenar em whatsapp_events (event_type='message_received')
    // Log para auditoria
}
```

#### B. Status de Entrega
```php
foreach ($webhook['entry'][0]['changes'][0]['value']['statuses'] as $status) {
    // Mapear status Meta (sent → message_sent, etc)
    // Encontrar mensagem original em whatsapp_messages
    // Atualizar status da mensagem
    // Armazenar evento em whatsapp_events
    // Se houver erro, capturar error_code e error_message
}
```

### 4. Armazenamento

Dados persistem para análise posterior:
- Histórico completo de mensagens
- Rastreamento de status em tempo real
- Análise de falhas
- Auditoria de conformidade

---

## 💻 Uso do WebhookProcessorService

### Processar Webhook

```php
$webhookProcessor = Server::get(WebhookProcessorService::class);

// Processar payload recebido
$webhookProcessor->processWebhook($_POST);
```

### Registrar Mensagem Enviada

Após enviar mensagem via CloudApiDriver, registrar:

```php
$webhookProcessor->registerSentMessage(
    messageId: 'wamid.xxx',          // Retornado por Meta
    recipient: '5511999999999',
    content: 'Your code is 123456',
    messageType: 'text',
    phoneNumberId: '123456789'
);
```

### Consultar Eventos de Uma Mensagem

```php
$events = $webhookProcessor->getMessageEvents('wamid.xxx');

foreach ($events as $event) {
    echo $event->getStatus();  // 'sent', 'delivered', 'read', etc
}
```

### Consultar Eventos de um Recipient

```php
$events = $webhookProcessor->getRecipientEvents(
    recipient: '5511999999999',
    eventType: 'message_received',  // optional
    limit: 50
);

foreach ($events as $event) {
    echo $event->getContent();  // Mensagem ou descrição
}
```

### Obter Status de Mensagem Enviada

```php
$message = $webhookProcessor->getMessageStatus('wamid.xxx');

if ($message) {
    echo $message->getStatus();  // 'sent', 'delivered', 'read', 'failed'
}
```

### Obter Estatísticas

```php
$stats = $webhookProcessor->getStatistics();

echo $stats['messages_sent'];       // Total de mensagens enviadas
echo $stats['messages_delivered'];  // Total de mensagens entregues
echo $stats['messages_failed'];     // Total de mensagens falhadas
echo $stats['messages_received'];   // Total de mensagens recebidas
```

---

## 📝 Tipos de Mensagens Suportadas

O webhook processor extrai conteúdo de:

| Tipo | Extração | Exemplo |
|------|----------|---------|
| `text` | `text.body` | "Hello World" |
| `image` | `image.caption` | "My photo" |
| `video` | `video.caption` | "My video" |
| `audio` | Placeholder | "[Audio]" |
| `document` | `document.filename` | "resume.pdf" |
| `button` | `button.text` | "Click me" |
| `interactive` | `interactive.type` | "list" |
| `template` | `template.name` | "hello_world" |
| Unknown | Placeholder | "[UNKNOWN]" |

---

## ⚠️ Tratamento de Erros

### Erros de Entrega

Quando Meta retorna erro de entrega:

```json
{
  "id": "wamid.xxx",
  "status": "failed",
  "errors": [{
    "code": 131026,
    "message": "Message failed to send because this phone number is not registered on WhatsApp"
  }]
}
```

Armazenamento:
- `status` = "failed"
- `error_code` = "131026"
- `error_message` = "Message failed to send..."
- Mensagem em `whatsapp_messages` atualizada para `status = 'failed'`

### Códigos de Erro Comuns

| Código | Significado |
|--------|------------|
| 131026 | Phone number not registered on WhatsApp |
| 131027 | Invalid credentials |
| 131030 | Media download failed |
| 131031 | Media type not supported |

---

## 🧪 Testes

### Executar Testes

```bash
./vendor/bin/phpunit tests/php/Unit/Service/WhatsApp/WebhookProcessorServiceTest.php
```

### Cenários Testados

✅ Processamento de mensagem recebida
✅ Processamento de status de entrega
✅ Processamento de erro de entrega
✅ Validação de payload inválido
✅ Registrar mensagem enviada
✅ Recuperar eventos de mensagem
✅ Recuperar eventos de recipient
✅ Extrair conteúdo text
✅ Extrair conteúdo image

---

## 🔐 Segurança

### Validação de Payload

```php
// Valida estrutura básica
isValidPayload($payload) // Returns bool

// Antes de processar, sempre valida:
if (!isset($payload['entry'][0]['changes'][0]['value'])) {
    // Payload inválido - descarta
    return;
}
```

### Sem Exposição de Dados Sensíveis

```php
// Logging seguro
$this->logger->debug('Message sent', [
    'phone' => substr($phoneNumber, -4),  // Últimos 4 dígitos
    'message_id' => $messageId,           // ID anônimo
]);
```

### Tratamento de Exceções

```php
try {
    // Processar
} catch (\Exception $e) {
    // Log com contexto
    $this->logger->error('Error processing', [
        'exception' => $e->getMessage(),
        // Não expõe stack trace
    ]);
    // Continua processamento
}
```

---

## 📈 Limpeza de Dados (Maintenance)

### Limpar eventos antigos

```php
$eventMapper = Server::get(WhatsAppEventMapper::class);

// Manter apenas últimos 30 dias
$eventMapper->cleanupOld(daysToKeep: 30);
```

### Limpar mensagens antigas

```php
$messageMapper = Server::get(WhatsAppMessageMapper::class);

// Manter apenas últimos 90 dias
$messageMapper->cleanupOld(daysToKeep: 90);
```

Recomendado executar via Cron Job:

```bash
# Via app.php
occ twofactor_gateway:cleanup --days-events=30 --days-messages=90

# Via sistema
0 2 * * * www-data php /var/www/nextcloud/occ twofactor_gateway:cleanup
```

---

## 🔍 Queries Úteis

### Contar mensagens por status

```php
$eventMapper->countByEventType('message_delivered');  // Entregues
$eventMapper->countByEventType('message_failed');     // Falhadas
$eventMapper->countByEventType('message_read');       // Lidas
```

### Encontrar falhas recentes

```php
$failed = $eventMapper->findByStatus('failed', limit: 50);

foreach ($failed as $event) {
    echo $event->getErrorMessage();  // Por que falhou
}
```

### Histórico de um usuário

```php
$events = $eventMapper->findByRecipient(
    recipient: '5511999999999',
    eventType: null,  // Todos os eventos
    limit: 100        // Últimas 100
);
```

---

## 🚀 Integração com CloudApiDriver

Quando CloudApiDriver envia mensagem:

```php
// Em CloudApiDriver::send()
$response = $this->client->post($url, [
    // ... request config
]);

$messageId = $responseBody['messages'][0]['id'];  // Obter ID da Meta

// Registrar no webhook processor
$webhookProcessor = Server::get(WebhookProcessorService::class);
$webhookProcessor->registerSentMessage(
    $messageId,
    $identifier,
    $message
);
```

Depois, quando Meta enviar webhook de status, será correlacionado automaticamente.

---

## 📚 Arquivos Criados

| Arquivo | Responsabilidade |
|---------|------------------|
| `lib/Migration/Version20251218200000...` | Criação das tabelas |
| `lib/Db/WhatsAppEvent.php` | Entidade de evento |
| `lib/Db/WhatsAppEventMapper.php` | Query builder para eventos |
| `lib/Db/WhatsAppMessage.php` | Entidade de mensagem |
| `lib/Db/WhatsAppMessageMapper.php` | Query builder para mensagens |
| `lib/Service/WhatsApp/WebhookProcessorService.php` | Processador principal |
| `lib/Controller/WhatsAppWebhookController.php` | Endpoint e validação |
| `tests/.../WebhookProcessorServiceTest.php` | Testes unitários |

---

## 🎯 Próximos Passos

### Melhorias Futuras

1. **Event Listeners** - Disparar eventos customizados para cada webhook
   ```php
   $this->dispatcher->dispatch(new WhatsAppMessageReceivedEvent($event));
   ```

2. **Retry de Falhas** - Implementar fila de retry para mensagens falhadas
   ```php
   $failedMessages = $messageMapper->findByStatus('failed');
   // Tentar enviar novamente
   ```

3. **Templates** - Suporte a templates aprovados pelo Meta
   ```php
   'type' => 'template',
   'template' => [
       'name' => 'hello_world',
       'language' => ['code' => 'pt_BR']
   ]
   ```

4. **Media Upload** - Suporte a envio de imagens e documentos
   ```php
   'type' => 'image',
   'image' => [
       'link' => 'https://...',
       'caption' => 'My image'
   ]
   ```

5. **Webhook Redelivery** - Suporte a reentrega automática de webhooks

---

## 📞 Troubleshooting

### Webhook não é recebido

1. Verificar se URL do webhook está correta em Meta Business Manager
2. Verificar se número de telefone está verificado no Meta
3. Verificar logs: `/var/www/nextcloud/data/nextcloud.log`

### Status não atualiza

1. Verificar se `registerSentMessage()` foi chamado
2. Verificar se `message_id` enviado é igual ao `id` do webhook
3. Verificar se banco de dados está sendo atualizado

### Mensagens não são processadas

1. Verificar permissões do webhook endpoint
2. Verificar se `WebhookProcessorService` está sendo injetado
3. Verificar estrutura do payload do webhook

---

## 📖 Referências

- [Meta Webhook Documentation](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples)
- [WhatsApp Business API](https://developers.facebook.com/docs/whatsapp)
- [HTTP Webhooks Best Practices](https://zapier.com/blog/webhook/)

