<?php
/**
 * CRM — Integración con WhatsApp Business (Meta Cloud API).
 *
 * v1.20.87: andamiaje construido SIN credenciales reales todavía — el
 * usuario no tiene cuenta de ningún proveedor de WhatsApp Business en el
 * momento de escribir esto. Esta pieza deja todo listo para que, el día que
 * exista una cuenta de Meta Business verificada + número de teléfono +
 * plantillas de mensaje aprobadas, activarlo sea solo rellenar Ajustes — sin
 * tocar código.
 *
 * IMPORTANTE — esto NO es un enviador de texto libre: la API de WhatsApp
 * Business exige que cualquier mensaje que la empresa inicia (no una
 * respuesta dentro de las 24h de una conversación que empezó el cliente)
 * use una "plantilla" (message template) previamente creada y APROBADA por
 * Meta en el Business Manager, con su propio nombre y sus propias variables.
 * Por eso crm_whatsapp_enviar_plantilla() pide un nombre de plantilla, no un
 * mensaje suelto — cualquier otra cosa fallaría de verdad contra la API real.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Credenciales guardadas en Ajustes. El token nunca se imprime de vuelta en
 * el formulario (mismo patrón que crm_holded_api_key) — solo se lee aquí.
 *
 * @return array{token:string, phone_number_id:string}
 */
function crm_whatsapp_get_credenciales() {
    return [
        'token'           => (string) get_option('crm_whatsapp_api_token', ''),
        'phone_number_id' => trim((string) get_option('crm_whatsapp_phone_number_id', '')),
    ];
}

/**
 * true si hay credenciales suficientes para intentar un envío real. No
 * garantiza que sean válidas (eso solo lo sabe Meta al llamar), solo que
 * hay algo configurado.
 */
function crm_whatsapp_configurado() {
    $c = crm_whatsapp_get_credenciales();
    return $c['token'] !== '' && $c['phone_number_id'] !== '';
}

/**
 * Normaliza un número de teléfono al formato que espera la API (dígitos y,
 * opcionalmente, un + inicial — sin espacios, guiones ni paréntesis).
 */
function crm_whatsapp_normalizar_telefono($telefono) {
    $telefono = trim((string) $telefono);
    $limpio   = preg_replace('/[^0-9+]/', '', $telefono);
    return $limpio === null ? '' : $limpio;
}

/**
 * Envía un mensaje de plantilla (template/HSM) por WhatsApp Business vía
 * Meta Cloud API. Devuelve WP_Error de inmediato (sin llamar a ningún sitio)
 * si no hay credenciales — así ningún flujo que ya funciona por email se
 * rompe por intentar WhatsApp antes de tiempo.
 *
 * @param string $telefono      Número de destino (con o sin +, se normaliza solo).
 * @param string $template_name Nombre EXACTO de la plantilla ya aprobada en Meta Business Manager.
 * @param array  $parametros    Textos para las variables {{1}}, {{2}}... del cuerpo de la plantilla, en orden.
 * @param string $idioma        Código de idioma de la plantilla tal como se registró en Meta (p.ej. 'es' o 'es_ES').
 * @return true|WP_Error
 */
function crm_whatsapp_enviar_plantilla($telefono, $template_name, array $parametros = [], $idioma = 'es') {
    if (!crm_whatsapp_configurado()) {
        return new WP_Error('crm_whatsapp_no_config', 'WhatsApp Business no está configurado todavía (faltan credenciales en Ajustes).');
    }
    $telefono = crm_whatsapp_normalizar_telefono($telefono);
    if ($telefono === '') {
        return new WP_Error('crm_whatsapp_sin_telefono', 'No hay número de WhatsApp al que enviar.');
    }
    $template_name = trim((string) $template_name);
    if ($template_name === '') {
        return new WP_Error('crm_whatsapp_sin_plantilla', 'Falta el nombre de la plantilla de WhatsApp.');
    }

    $credenciales = crm_whatsapp_get_credenciales();
    $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($credenciales['phone_number_id']) . '/messages';

    $body = [
        'messaging_product' => 'whatsapp',
        'to'                => $telefono,
        'type'              => 'template',
        'template'          => [
            'name'     => $template_name,
            'language' => ['code' => $idioma],
        ],
    ];
    if (!empty($parametros)) {
        $body['template']['components'] = [[
            'type'       => 'body',
            'parameters' => array_map(function ($texto) {
                return ['type' => 'text', 'text' => (string) $texto];
            }, $parametros),
        ]];
    }

    $response = wp_remote_post($url, [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $credenciales['token'],
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        crm_whatsapp_log_error($template_name, $response->get_error_message());
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        $data    = json_decode(wp_remote_retrieve_body($response), true);
        $mensaje = is_array($data) && !empty($data['error']['message']) ? $data['error']['message'] : ('Error HTTP ' . $code);
        crm_whatsapp_log_error($template_name, $mensaje, $code);
        return new WP_Error('crm_whatsapp_http_error', $mensaje, ['status' => $code]);
    }

    return true;
}

/**
 * v1.20.113 — formulario de Ajustes de WhatsApp para el FRONTEND
 * (`/panel-de-control/`). Hasta ahora estos campos solo existían en wp-admin
 * (Settings API, `includes/admin-page.php`) — mismo hueco que ya se encontró
 * y corrigió para los canales de email (v1.20.103): crm_admin opera desde el
 * frontend y no puede entrar a wp-admin. Se deja intacto el formulario de
 * wp-admin (sigue funcionando para administrator/webmaster) y se añade este,
 * independiente, que escribe en las MISMAS opciones — un solo dato, editable
 * desde los dos sitios.
 */
function crm_whatsapp_settings_render() {
    if (!current_user_can('crm_admin')) {
        return;
    }

    $nonce_action = 'crm_whatsapp_settings_guardar';
    if (isset($_POST['crm_whatsapp_settings_guardar']) && wp_verify_nonce($_POST['crm_whatsapp_nonce'] ?? '', $nonce_action)) {
        update_option('crm_whatsapp_phone_number_id', sanitize_text_field((string) wp_unslash($_POST['crm_whatsapp_phone_number_id'] ?? '')), false);
        $token_nuevo = trim((string) wp_unslash($_POST['crm_whatsapp_api_token'] ?? ''));
        if ($token_nuevo !== '') {
            update_option('crm_whatsapp_api_token', $token_nuevo, false);
        }
        update_option('crm_whatsapp_template_aviso_materiales', sanitize_text_field((string) wp_unslash($_POST['crm_whatsapp_template_aviso_materiales'] ?? '')), false);
        update_option('crm_whatsapp_template_validar_extra', sanitize_text_field((string) wp_unslash($_POST['crm_whatsapp_template_validar_extra'] ?? '')), false);
        update_option('crm_whatsapp_template_en_ejecucion', sanitize_text_field((string) wp_unslash($_POST['crm_whatsapp_template_en_ejecucion'] ?? '')), false);
        echo '<div style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:10px;font-size:13px;">Configuración de WhatsApp guardada.</div>';
    }

    $campo_style = 'width:100%;max-width:360px;box-sizing:border-box;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;';
    ?>
    <div class="crm-mail-canal" style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
        <h4 style="margin:0 0 4px;">WhatsApp Business — Meta Cloud API</h4>
        <p style="font-size:12.5px;color:#6b7280;margin:0 0 12px;">
            Estado: <?php echo crm_whatsapp_configurado() ? '<span style="color:#065f46;font-weight:600;">Configurado</span>' : '<span style="color:#92400e;font-weight:600;">Sin configurar</span>'; ?>
            — requiere una plantilla de mensaje aprobada por Meta por cada aviso. Sin esto, el CRM sigue funcionando igual que hoy, nunca bloquea nada.
        </p>
        <form method="post">
            <?php wp_nonce_field($nonce_action, 'crm_whatsapp_nonce'); ?>
            <input type="hidden" name="crm_whatsapp_settings_guardar" value="1">
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:4px 8px 4px 0;width:220px;">Phone Number ID</td>
                    <td style="padding:4px 0;"><input type="text" name="crm_whatsapp_phone_number_id" value="<?php echo esc_attr((string) get_option('crm_whatsapp_phone_number_id', '')); ?>" style="<?php echo esc_attr($campo_style); ?>" placeholder="Del panel de Meta for Developers"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Token de acceso</td>
                    <td style="padding:4px 0;"><input type="password" name="crm_whatsapp_api_token" value="" autocomplete="off" style="<?php echo esc_attr($campo_style); ?>" placeholder="<?php echo crm_whatsapp_configurado() ? '••••••••• (sin cambios si lo dejas en blanco)' : 'Token permanente del usuario del sistema'; ?>"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Plantilla — aviso de materiales</td>
                    <td style="padding:4px 0;"><input type="text" name="crm_whatsapp_template_aviso_materiales" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_aviso_materiales', '')); ?>" style="<?php echo esc_attr($campo_style); ?>" placeholder="nombre_exacto_de_la_plantilla"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Plantilla — validar partida extra</td>
                    <td style="padding:4px 0;"><input type="text" name="crm_whatsapp_template_validar_extra" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_validar_extra', '')); ?>" style="<?php echo esc_attr($campo_style); ?>" placeholder="nombre_exacto_de_la_plantilla"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Plantilla — instalación en marcha</td>
                    <td style="padding:4px 0;"><input type="text" name="crm_whatsapp_template_en_ejecucion" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_en_ejecucion', '')); ?>" style="<?php echo esc_attr($campo_style); ?>" placeholder="nombre_exacto_de_la_plantilla"></td>
                </tr>
            </table>
            <p><button type="submit" class="crm-btn">Guardar</button></p>
        </form>
    </div>
    <?php
}

/**
 * Registra un fallo de envío en el log general del plugin — mismo patrón
 * que crm_holded_log_error(), para que quede visible en Panel → Registro de
 * actividades sin tener que abrir los logs de PHP del servidor.
 */
function crm_whatsapp_log_error($template_name, $mensaje, $http_code = null) {
    if (function_exists('crm_log_action')) {
        crm_log_action(
            'whatsapp_api_error',
            sprintf('WhatsApp [%s]: %s', $template_name, $mensaje),
            null,
            null,
            'error',
            ['template' => $template_name, 'http_code' => $http_code]
        );
    }
}
