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
 * v1.20.159 — catálogo único de plantillas de WhatsApp: opción → etiqueta
 * (qué es y quién lo recibe). Antes esta lista vivía duplicada 3 veces
 * (aquí, en $nombres_validos de crm_whatsapp_ajax_test_envio(), y de forma
 * implícita en crm_whatsapp_test_parametros()) — al añadir una plantilla
 * nueva (v1.20.155/156) se actualizó el envío real y los Ajustes de
 * wp-admin, pero se olvidó esta copia, así que ni la vista de
 * /panel-de-control/ ni el botón "Enviar prueba" la mostraban. Con una sola
 * función, añadir una plantilla nueva es tocar un único sitio.
 *
 * @return array<string,string> opción de wp_options => etiqueta
 */
function crm_whatsapp_catalogo() {
    return [
        'crm_whatsapp_template_aviso_materiales'              => 'Aviso de materiales pendientes (a jefes/crm_admin)',
        'crm_whatsapp_template_validar_extra'                 => 'Validar partida extra (al cliente)',
        'crm_whatsapp_template_en_ejecucion'                  => 'Instalación en marcha (cierre parcial, a jefes/crm_admin)',
        'crm_whatsapp_template_recordatorio_visita'           => 'Recordatorio de visita (a jefes/crm_admin)',
        'crm_whatsapp_template_instalador_asignado'           => 'Instalación asignada (a instalador)',
        'crm_whatsapp_template_instalador_visita'             => 'Visita programada/reprogramada (a instalador)',
        'crm_whatsapp_template_instalador_extra_resuelta'     => 'Partida extra resuelta (a instalador)',
        'crm_whatsapp_template_instalador_cierre_resuelto'    => 'Cierre resuelto (a instalador)',
        'crm_whatsapp_template_confirmacion_visita_cliente'   => 'Confirmación de cita (al cliente, con botones)',
        'crm_whatsapp_template_comercial_cliente_actualizado' => 'Ficha de cliente actualizada (al comercial)',
        'crm_whatsapp_template_comercial_visita'              => 'Visita comercial asignada/reprogramada (al comercial o visitador)',
    ];
}

/**
 * v1.20.113 — vista de WhatsApp para el FRONTEND (`/panel-de-control/`).
 * Nació como un formulario editable completo (Phone Number ID + token +
 * plantillas), para tapar el mismo hueco ya corregido para email
 * (v1.20.103): crm_admin no puede entrar a wp-admin, así que no tenía dónde
 * ver esto. v1.20.116: el usuario pidió simplificarla — el Phone Number ID y
 * el token son datos técnicos de una sola vez (los puso el administrador al
 * dar de alta la cuenta de Meta) y no algo que crm_admin necesite tocar el
 * día a día ("es mucha tela para el solo que salga conectado y las
 * plantillas"). Ahora es de solo lectura: estado + nombres de plantilla +
 * botón de prueba. Para cambiar Phone Number ID/token/nombres de plantilla
 * sigue estando el formulario completo en wp-admin → CRM → WhatsApp
 * (administrator/webmaster), que no se ha tocado.
 */
function crm_whatsapp_settings_render() {
    if (!current_user_can('crm_admin')) {
        return;
    }

    $credenciales    = crm_whatsapp_get_credenciales();
    $phone_visible   = $credenciales['phone_number_id'] !== '' ? '•••' . substr($credenciales['phone_number_id'], -4) : '—';
    $plantillas      = crm_whatsapp_catalogo();
    ?>
    <div class="crm-mail-canal" style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
        <h4 style="margin:0 0 4px;">WhatsApp Business</h4>
        <p style="font-size:12.5px;color:#6b7280;margin:0 0 12px;">
            <?php if (crm_whatsapp_configurado()) : ?>
                <span style="color:#065f46;font-weight:600;">● Conectado</span> (número <?php echo esc_html($phone_visible); ?>)
            <?php else : ?>
                <span style="color:#92400e;font-weight:600;">● Sin configurar</span>
            <?php endif; ?>
            — cada aviso necesita su plantilla aprobada por Meta. Sin esto el CRM sigue funcionando igual que hoy, solo que sin este canal extra.
        </p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
            <?php foreach ($plantillas as $opcion => $etiqueta) :
                $nombre = trim((string) get_option($opcion, ''));
            ?>
            <tr>
                <td style="padding:4px 8px 4px 0;width:260px;color:#374151;"><?php echo esc_html($etiqueta); ?></td>
                <td style="padding:4px 0;">
                    <?php if ($nombre !== '') : ?>
                        <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?php echo esc_html($nombre); ?></code>
                    <?php else : ?>
                        <span style="color:#9ca3af;">sin plantilla asignada</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="font-size:12px;color:#9ca3af;margin:0 0 12px;">El Phone Number ID, el token y los nombres de plantilla se cambian desde wp-admin → CRM → WhatsApp.</p>
        <?php if (crm_whatsapp_configurado()) : ?>
        <p style="border-top:1px solid #f1f5f9;padding-top:10px;margin:0;">
            <input type="tel" id="crm-whatsapp-test-to" placeholder="número de prueba, ej. 34611466327" style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;">
            <select id="crm-whatsapp-test-plantilla" style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;">
                <?php foreach ($plantillas as $opcion => $etiqueta) :
                    if (trim((string) get_option($opcion, '')) === '') { continue; }
                ?>
                <option value="<?php echo esc_attr($opcion); ?>"><?php echo esc_html($etiqueta); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="crm-btn" id="crm-whatsapp-test-btn">Enviar prueba</button>
            <span id="crm-whatsapp-test-msg" style="font-size:12.5px;margin-left:6px;"></span>
        </p>
        <script>
        (function () {
            var btn = document.getElementById('crm-whatsapp-test-btn');
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                var to        = document.getElementById('crm-whatsapp-test-to').value.trim();
                var plantilla = document.getElementById('crm-whatsapp-test-plantilla').value;
                var msg       = document.getElementById('crm-whatsapp-test-msg');
                if (!to) {
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Escribe un número primero.';
                    return;
                }
                btn.disabled = true;
                msg.style.color = '#6b7280';
                msg.textContent = 'Enviando…';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_js(admin_url('admin-ajax.php')); ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function () {
                    btn.disabled = false;
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        msg.style.color = resp.success ? '#065f46' : '#991b1b';
                        msg.textContent = (resp.data && resp.data.message) ? resp.data.message : (resp.success ? 'Enviado.' : 'Error.');
                    } catch (e) {
                        msg.style.color = '#991b1b';
                        msg.textContent = 'Error de conexión.';
                    }
                };
                xhr.onerror = function () {
                    btn.disabled = false;
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Error de conexión.';
                };
                xhr.send('action=crm_whatsapp_test_envio&nonce=<?php echo esc_js(wp_create_nonce('crm_admin_actions')); ?>&to=' + encodeURIComponent(to) + '&plantilla=' + encodeURIComponent(plantilla));
            });
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * v1.20.116 — parámetros de ejemplo por plantilla, solo para el botón
 * "Enviar prueba": cada plantilla real de Meta exige el número exacto de
 * variables con el que se aprobó, así que un envío de prueba tiene que
 * respetar ese recuento aunque los datos sean ficticios.
 */
function crm_whatsapp_test_parametros($opcion_plantilla) {
    switch ($opcion_plantilla) {
        case 'crm_whatsapp_template_aviso_materiales':
            return ['Cliente de prueba', 'C/ Ejemplo 1, Madrid', 'mañana', 'ventana, inversor', 'en camino', home_url('/')];
        case 'crm_whatsapp_template_validar_extra':
            return ['Cliente de prueba', 'Partida extra de ejemplo', '123,45', home_url('/')];
        case 'crm_whatsapp_template_en_ejecucion':
            return ['Cliente de prueba', 'Instalador de prueba', home_url('/')];
        case 'crm_whatsapp_template_recordatorio_visita':
            return ['Cliente de prueba', 'mañana 10:00', 'C/ Ejemplo 1, Madrid', home_url('/')];
        case 'crm_whatsapp_template_instalador_asignado':
            return ['Cliente de prueba', home_url('/')];
        case 'crm_whatsapp_template_instalador_visita':
            return ['Cliente de prueba', 'mañana 10:00', home_url('/')];
        case 'crm_whatsapp_template_instalador_extra_resuelta':
            return ['Partida extra de ejemplo', 'aprobado', home_url('/')];
        case 'crm_whatsapp_template_instalador_cierre_resuelto':
            return ['Cliente de prueba', 'aprobado', home_url('/')];
        case 'crm_whatsapp_template_confirmacion_visita_cliente':
            return ['Cliente de prueba', 'mañana 10:00', 'C/ Ejemplo 1, Madrid'];
        case 'crm_whatsapp_template_comercial_cliente_actualizado':
            return ['Comercial de prueba', 'Cliente de prueba', 'Empresa de ejemplo S.L.', 'Madrid', 'cambió el teléfono'];
        case 'crm_whatsapp_template_comercial_visita':
            return ['Cliente de prueba', 'mañana 10:00', home_url('/mi-agenda/')];
        default:
            return [];
    }
}

/**
 * AJAX: botón "Enviar prueba" de WhatsApp — mismo patrón que
 * crm_mail_ajax_test_canal() en mail-settings.php.
 */
add_action('wp_ajax_crm_whatsapp_test_envio', 'crm_whatsapp_ajax_test_envio');
function crm_whatsapp_ajax_test_envio() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_admin_actions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $to        = sanitize_text_field((string) ($_POST['to'] ?? ''));
    $plantilla = sanitize_key((string) ($_POST['plantilla'] ?? ''));
    $nombres_validos = array_keys(crm_whatsapp_catalogo());
    if (!in_array($plantilla, $nombres_validos, true)) {
        wp_send_json_error(['message' => 'Plantilla no válida.']);
    }
    $template_name = trim((string) get_option($plantilla, ''));
    if ($template_name === '') {
        wp_send_json_error(['message' => 'Esa plantilla todavía no tiene nombre asignado.']);
    }

    $resultado = crm_whatsapp_enviar_plantilla($to, $template_name, crm_whatsapp_test_parametros($plantilla));

    if (is_wp_error($resultado)) {
        wp_send_json_error(['message' => 'No se pudo enviar: ' . $resultado->get_error_message()]);
    }
    wp_send_json_success(['message' => 'Enviado a ' . $to . '.']);
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

// ──────────────────────────────────────────────────────────────────────────────
// v1.20.130 — Fase 8: webhook de ENTRADA. Hasta ahora WhatsApp solo enviaba
// (todo lo de arriba en este archivo) — esto es lo primero que RECIBE algo
// de Meta: la respuesta del cliente a los botones "Confirmo"/"Necesito
// cambiar" de la plantilla de confirmación de cita.
//
// IMPORTANTE — sin verificar empíricamente todavía: el payload exacto que
// Meta manda al pulsar un botón de plantilla se ha construido según su
// documentación pública, no contra una respuesta real. Por eso
// crm_whatsapp_webhook_recibir() registra SIEMPRE el payload completo en el
// log — la primera respuesta real dirá si el parseo hay que ajustarlo.
//
// Endpoint público (Meta no puede autenticarse como un usuario de
// WordPress): NO se sirve vía la REST API de WP — el framework de la REST
// API envuelve automáticamente cualquier respuesta en JSON, lo que en la
// verificación (GET) rompe el handshake: Meta exige el "challenge" tal
// cual, en texto plano y SIN comillas, y `WP_REST_Response($challenge)`
// lo devuelve como `"1234567890"` (con comillas) en vez de `1234567890` —
// confirmado en vivo contra el endpoint real, no una suposición. Por eso
// se intercepta la petición a mano en `init` (antes de que WP intente
// enrutarla como REST) y se responde con `echo`/`exit`, control total del
// body exacto que se envía. No pasa por admin-lockdown.php (eso solo
// bloquea /wp-admin/, esto es una ruta cualquiera del sitio).
// ──────────────────────────────────────────────────────────────────────────────

add_action('init', 'crm_whatsapp_webhook_interceptar', 1);
function crm_whatsapp_webhook_interceptar() {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path) || rtrim($path, '/') !== '/wp-json/crm/v1/whatsapp-webhook') {
        return;
    }
    $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($metodo === 'GET') {
        crm_whatsapp_webhook_verificar();
    } elseif ($metodo === 'POST') {
        crm_whatsapp_webhook_recibir();
    } else {
        status_header(405);
    }
    exit;
}

/**
 * Handshake que Meta hace UNA vez al guardar la URL del webhook en su panel
 * (y cada vez que se vuelve a verificar) — responde con el "challenge" tal
 * cual (texto plano, sin JSON) si el token coincide.
 *
 * Los parámetros que manda Meta llevan puntos (`hub.mode`, no `hub_mode`) —
 * PHP convierte automáticamente los puntos de los nombres de parámetros de
 * query string en guiones bajos al rellenar $_GET, así que leerlos con
 * guion bajo aquí es lo correcto, no un error.
 */
function crm_whatsapp_webhook_verificar() {
    $modo      = sanitize_text_field((string) ($_GET['hub_mode'] ?? ''));
    $token     = (string) ($_GET['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? '');

    $token_esperado = (string) get_option('crm_whatsapp_webhook_verify_token', '');
    if ($modo === 'subscribe' && $token !== '' && $token_esperado !== '' && hash_equals($token_esperado, $token)) {
        status_header(200);
        header('Content-Type: text/plain');
        echo $challenge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return;
    }
    status_header(403);
    header('Content-Type: text/plain');
    echo 'Token de verificacion no valido.';
}

/**
 * Recibe los eventos reales. Meta manda un POST por cada mensaje/cambio de
 * estado — solo nos interesa cuando alguien pulsa un botón de la plantilla
 * de confirmación de cita; el resto se ignora silenciosamente. Responde
 * siempre 200 rápido (JSON aquí no pasa nada, Meta no exige texto plano en
 * la recepción de eventos — solo en el handshake GET de arriba).
 */
function crm_whatsapp_webhook_recibir() {
    $body_raw = file_get_contents('php://input');

    // Verificación de firma (HMAC con el App Secret de Meta) — sin secreto
    // configurado no se puede verificar de verdad; se procesa igual en ese
    // caso (mejor que bloquear todo el flujo por un ajuste sin rellenar),
    // pero en cuanto haya secreto, una firma que no cuadre se rechaza.
    $secreto = trim((string) get_option('crm_whatsapp_app_secret', ''));
    if ($secreto !== '') {
        $firma_recibida  = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        $firma_calculada = 'sha256=' . hash_hmac('sha256', $body_raw, $secreto);
        if ($firma_recibida === '' || !hash_equals($firma_calculada, $firma_recibida)) {
            if (function_exists('crm_log_action')) {
                crm_log_action('whatsapp_webhook_firma_invalida', 'Webhook de WhatsApp recibido con firma inválida o ausente.', null, 0, 'error');
            }
            status_header(403);
            header('Content-Type: application/json');
            echo wp_json_encode(['status' => 'firma invalida']);
            return;
        }
    }

    $payload = json_decode($body_raw, true);
    if (function_exists('crm_log_action')) {
        // Barato y útil de guardar siempre — sobre todo mientras no se ha
        // verificado el formato real contra un envío de verdad.
        crm_log_action('whatsapp_webhook_recibido', wp_json_encode($payload), null, 0, 'info');
    }

    $mensajes = $payload['entry'][0]['changes'][0]['value']['messages'] ?? [];
    foreach ((array) $mensajes as $mensaje) {
        crm_whatsapp_webhook_procesar_mensaje($mensaje);
    }

    status_header(200);
    header('Content-Type: application/json');
    echo wp_json_encode(['status' => 'ok']);
}

/**
 * Un mensaje entrante = normalmente el clic de un botón. Se intenta leer el
 * texto/payload del botón de varias formas posibles (distintas versiones de
 * la API de Meta lo colocan en sitios algo distintos) — sin verificación
 * real todavía, ver nota al principio de esta sección.
 */
function crm_whatsapp_webhook_procesar_mensaje($mensaje) {
    $telefono_from = (string) ($mensaje['from'] ?? '');
    if ($telefono_from === '') {
        return;
    }
    $accion = strtolower((string) (
        $mensaje['button']['payload']
        ?? $mensaje['interactive']['button_reply']['id']
        ?? $mensaje['button']['text']
        ?? $mensaje['interactive']['button_reply']['title']
        ?? $mensaje['text']['body']
        ?? ''
    ));
    if ($accion === '') {
        return;
    }

    $confirma = strpos($accion, 'confirm') !== false;
    $cambio   = strpos($accion, 'cambi') !== false;
    if (!$confirma && !$cambio) {
        return;
    }

    if (!function_exists('crm_inst_table_agenda') || !function_exists('crm_inst_table_instalaciones')) {
        return;
    }

    global $wpdb;
    // El número que llega de Meta no lleva "+" ni espacios — comparar por
    // los últimos 9 dígitos es más robusto que un match exacto contra lo
    // que cada cliente tenga guardado (con o sin prefijo de país).
    $sufijo = substr(preg_replace('/[^0-9]/', '', $telefono_from), -9);
    if ($sufijo === '') {
        return;
    }

    $cliente = $wpdb->get_row($wpdb->prepare(
        "SELECT id, cliente_nombre FROM {$wpdb->prefix}crm_clients WHERE telefono LIKE %s ORDER BY id DESC LIMIT 1",
        '%' . $wpdb->esc_like($sufijo)
    ), ARRAY_A);
    if (!$cliente) {
        if (function_exists('crm_log_action')) {
            crm_log_action('whatsapp_webhook_cliente_no_encontrado', 'Respuesta de WhatsApp de un número no reconocido: ' . $telefono_from, null, 0, 'notice');
        }
        return;
    }

    // La cita "pendiente de confirmar" más próxima de ese cliente: la que ya
    // recibió el recordatorio (`recordatorio_enviado_en`, v1.20.127) y sigue
    // sin resolverse. No hace falta un token propio — el teléfono + "la
    // próxima cita a la que ya se le pidió confirmación" es suficiente para
    // el caso normal (una visita activa a la vez).
    $agenda_row = $wpdb->get_row($wpdb->prepare(
        "SELECT a.id, a.instalacion_id, a.fecha_cita
         FROM " . crm_inst_table_agenda() . " a
         INNER JOIN " . crm_inst_table_instalaciones() . " i ON i.id = a.instalacion_id
         WHERE i.client_id = %d
           AND a.recordatorio_enviado_en IS NOT NULL
           AND a.estado = 'pendiente'
           AND a.fecha_cita >= %s
         ORDER BY a.fecha_cita ASC
         LIMIT 1",
        (int) $cliente['id'],
        current_time('mysql')
    ), ARRAY_A);
    if (!$agenda_row) {
        if (function_exists('crm_log_action')) {
            crm_log_action('whatsapp_webhook_sin_cita_pendiente', 'Respuesta de WhatsApp de ' . $cliente['cliente_nombre'] . ' sin ninguna cita pendiente de confirmar.', (int) $cliente['id'], 0, 'notice');
        }
        return;
    }

    $instalacion_id = (int) $agenda_row['instalacion_id'];
    $fecha_label    = function_exists('date_i18n') ? date_i18n('d/m/Y H:i', strtotime($agenda_row['fecha_cita'])) : $agenda_row['fecha_cita'];
    $url_ficha      = add_query_arg('id', $instalacion_id, home_url('/instalacion/'));

    if ($confirma) {
        $wpdb->update(crm_inst_table_agenda(), ['estado' => 'confirmada'], ['id' => (int) $agenda_row['id']]);
        if (function_exists('crm_inst_log_action')) {
            crm_inst_log_action($instalacion_id, 'agenda', 'cita_confirmada_cliente', 'El cliente confirmó por WhatsApp la visita del ' . $fecha_label . '.');
        }
        if (function_exists('crm_notificar_jefes_instalaciones')) {
            crm_notificar_jefes_instalaciones('cita_confirmada_cliente', 'El cliente confirmó la visita — ' . $cliente['cliente_nombre'] . '.', $url_ficha);
        }
    } elseif ($cambio) {
        if (function_exists('crm_inst_log_action')) {
            crm_inst_log_action($instalacion_id, 'agenda', 'cita_cambio_solicitado', 'El cliente pidió cambiar por WhatsApp la visita del ' . $fecha_label . '.');
        }
        if (function_exists('crm_notificar_jefes_instalaciones')) {
            crm_notificar_jefes_instalaciones('cita_cambio_solicitado', 'El cliente pidió cambiar la visita — ' . $cliente['cliente_nombre'] . '. Contactar para reagendar.', $url_ficha);
        }
    }
}
