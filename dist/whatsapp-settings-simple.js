/**
 * Simple WhatsApp settings form without Vue
 */
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('whatsapp-cloud-api-settings');
    if (!container) return;

    const html = `
        <div class="settings-section settings-section--limit-width">
            <h2 class="settings-section__name">WhatsApp Cloud API</h2>
            <p class="settings-section__desc">Configure a integração com WhatsApp Cloud API para autenticação em dois fatores</p>
            
            <div id="webhook-credentials-section" style="display:none; margin-bottom: 30px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">
                <h3 style="margin-top: 0;">Configuração do Webhook</h3>
                <p style="color: #666; margin-bottom: 15px;">Copie os valores abaixo e configure no painel do desenvolvedor do Facebook:</p>
                
                <div class="settings-form-field">
                    <label><strong>URL do Webhook</strong></label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="webhook-url-display" readonly class="input-field__input" style="flex: 1;">
                        <button type="button" class="button copy-btn" data-target="webhook-url-display">Copiar</button>
                    </div>
                </div>
                
                <div class="settings-form-field">
                    <label><strong>Token de Verificação</strong></label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="webhook-token-display" readonly class="input-field__input" style="flex: 1;">
                        <button type="button" class="button copy-btn" data-target="webhook-token-display">Copiar</button>
                    </div>
                </div>
                
                <p style="color: #999; font-size: 0.9em; margin-top: 15px;">Escaneie o código QR ou configure manualmente estes valores no seu painel do Facebook.</p>
            </div>
            
            <form id="whatsapp-settings-form">
                <div class="settings-form-field">
                    <label for="phone_number_id">
                        <strong>Número de telefone</strong>
                    </label>
                    <p class="field-description">Por favor, insira o número de telefone do qual a mensagem será enviada.</p>
                    <input type="text" id="phone_number_id" name="phone_number_id" class="input-field__input" placeholder="Ex: +55 11 99999-9999">
                </div>

                <div class="settings-form-field">
                    <label for="phone_number_id_fb">
                        <strong>ID do número de telefone</strong>
                    </label>
                    <p class="field-description">Por favor, insira o ID do número de telefone obtido do painel do desenvolvedor do Facebook</p>
                    <input type="text" id="phone_number_id_fb" name="phone_number_id_fb" class="input-field__input" placeholder="Ex: 123456789">
                </div>

                <div class="settings-form-field">
                    <label for="business_account_id">
                        <strong>ID da conta do WhatsApp Business</strong>
                    </label>
                    <p class="field-description">Por favor, insira o ID da conta do WhatsApp Business obtido do painel do desenvolvedor do</p>
                    <input type="text" id="business_account_id" name="business_account_id" class="input-field__input" placeholder="Ex: 123456789">
                </div>

                <div class="settings-form-field">
                    <label for="api_key">
                        <strong>Chave da API</strong>
                    </label>
                    <p class="field-description">Chave da API</p>
                    <input type="password" id="api_key" name="api_key" class="input-field__input" placeholder="Cole seu token da API aqui">
                </div>

                <div class="settings-form-field">
                    <label for="api_endpoint">
                        <strong>Endpoint da API (Opcional)</strong>
                    </label>
                    <p class="field-description">Padrão: https://graph.facebook.com. Alterar apenas se usar um endpoint personalizado.</p>
                    <input type="text" id="api_endpoint" name="api_endpoint" class="input-field__input" value="https://graph.facebook.com" placeholder="https://graph.facebook.com">
                </div>

                <div class="settings-form-actions">
                    <button type="submit" class="button primary">Salvar Configuração</button>
                    <button type="button" id="test-btn" class="button secondary">Testar Conexão</button>
                    <button type="button" id="reset-btn" class="button">Cancelar</button>
                </div>
            </form>
        </div>
    `;

    container.innerHTML = html;

    // Add event listeners
    const form = document.getElementById('whatsapp-settings-form');
    const testBtn = document.getElementById('test-btn');
    const resetBtn = document.getElementById('reset-btn');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            saveSettings();
        });
    }

    if (testBtn) {
        testBtn.addEventListener('click', function(e) {
            e.preventDefault();
            testConnection();
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            form.reset();
        });
    }

    // Load existing settings
    loadSettings();
    
    // Add copy button functionality
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (input && input.value) {
                navigator.clipboard.writeText(input.value).then(() => {
                    const originalText = this.textContent;
                    this.textContent = 'Copiado!';
                    setTimeout(() => {
                        this.textContent = originalText;
                    }, 2000);
                }).catch(() => {
                    OC.dialogs.alert('Erro ao copiar', 'Erro');
                });
            }
        });
    });
});

function loadSettings() {
    fetch(OC.generateUrl('/ocs/v2.php/apps/twofactor_gateway/api/v1/whatsapp/configuration'), {
        method: 'GET',
        headers: {
            'OCS-APIRequest': 'true'
        }
    })
    .then(response => {
        if (!response.ok) {
            console.warn('Failed to load settings:', response.status);
            return null;
        }
        return response.json();
    })
    .then(data => {
        if (!data) return;
        
        // Handle both OCS format and direct JSON format
        const config = data.ocs?.data || data;
        
        if (config && typeof config === 'object') {
            console.log('Loaded settings:', config);
            document.getElementById('phone_number_id').value = config.phone_number_fb || '';
            document.getElementById('phone_number_id_fb').value = config.phone_number_id || '';
            document.getElementById('business_account_id').value = config.business_account_id || '';
            document.getElementById('api_endpoint').value = config.api_endpoint || 'https://graph.facebook.com';
        }
    })
    .catch(err => console.error('Error loading settings:', err));
}

function saveSettings() {
    const formData = {
        phone_number_id: document.getElementById('phone_number_id_fb').value,
        phone_number_fb: document.getElementById('phone_number_id').value,
        business_account_id: document.getElementById('business_account_id').value,
        api_key: document.getElementById('api_key').value,
        api_endpoint: document.getElementById('api_endpoint').value
    };

    fetch(OC.generateUrl('/ocs/v2.php/apps/twofactor_gateway/api/v1/whatsapp/configuration'), {
        method: 'POST',
        headers: {
            'OCS-APIRequest': 'true',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        // Handle both OCS and direct response formats
        const isOcs = data.ocs ? true : false;
        const meta = isOcs ? data.ocs.meta : { status: data.status || 'ok' };
        const message = isOcs ? data.ocs.meta.message : (data.message || 'Erro ao salvar');
        
        if (meta.status === 'ok' || (data.ocs && data.ocs.meta.statuscode === 200) || data.statuscode === 200) {
            OC.dialogs.alert('Configuração salva com sucesso', 'Sucesso');
            // Fetch and display webhook credentials
            loadWebhookCredentials();
        } else {
            OC.dialogs.alert(message || 'Erro ao salvar', 'Erro');
        }
    })
    .catch(err => {
        OC.dialogs.alert('Erro: ' + err.message, 'Erro');
        console.error('Error saving settings:', err);
    });
}

function loadWebhookCredentials() {
    fetch(OC.generateUrl('/ocs/v2.php/apps/twofactor_gateway/api/v1/whatsapp/webhook-credentials'), {
        method: 'GET',
        headers: {
            'OCS-APIRequest': 'true'
        }
    })
    .then(response => {
        if (!response.ok) {
            console.warn('Failed to load webhook credentials:', response.status);
            return null;
        }
        return response.json();
    })
    .then(data => {
        if (!data) return;
        
        // Handle both OCS format and direct JSON format
        const config = data.ocs?.data || data;
        
        if (config && config.webhook_url && config.verify_token) {
            document.getElementById('webhook-url-display').value = config.webhook_url;
            document.getElementById('webhook-token-display').value = config.verify_token;
            document.getElementById('webhook-credentials-section').style.display = 'block';
        }
    })
    .catch(err => console.error('Error loading webhook credentials:', err));
}

function testConnection() {
    const formData = {
        phone_number_id: document.getElementById('phone_number_id_fb').value,
        phone_number_fb: document.getElementById('phone_number_id').value,
        business_account_id: document.getElementById('business_account_id').value,
        api_key: document.getElementById('api_key').value,
        api_endpoint: document.getElementById('api_endpoint').value
    };

    fetch(OC.generateUrl('/ocs/v2.php/apps/twofactor_gateway/api/v1/whatsapp/test'), {
        method: 'POST',
        headers: {
            'OCS-APIRequest': 'true',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        // Handle both OCS and direct response formats
        const isOcs = data.ocs ? true : false;
        const meta = isOcs ? data.ocs.meta : { status: data.status || 'ok' };
        const message = isOcs ? data.ocs.meta.message : (data.message || 'Falha no teste de conexão');
        
        if (meta.status === 'ok' || (data.ocs && data.ocs.meta.statuscode === 200) || data.statuscode === 200) {
            OC.dialogs.alert('Conexão teste bem-sucedida!', 'Sucesso');
        } else {
            OC.dialogs.alert(message || 'Falha no teste de conexão', 'Erro');
        }
    })
    .catch(err => {
        OC.dialogs.alert('Erro: ' + err.message, 'Erro');
        console.error('Error testing connection:', err);
    });
}
