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
