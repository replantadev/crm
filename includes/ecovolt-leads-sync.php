<?php
/**
 * Importación incremental de leads del formulario web de Ecovolt.
 *
 * Especificación del lado emisor: docs/CRM-LEADS-INTEGRATION.md del proyecto
 * Ecovolt (repo separado) — este archivo implementa el lado receptor descrito
 * ahí. Ecovolt no se toca desde este proyecto.
 *
 * Reutiliza el modelo y los helpers que ya tiene el CRM para "Leads MK"
 * (mismo patrón que includes/leads-leadkit-csv.php): inserta directamente en
 * {$wpdb->prefix}crm_clients con origen_lead='lead_mk' (comparte cola con los
 * leads de Google Sheets/Meta, ver crm_leads_mk_origenes()), reutiliza
 * crm_find_duplicate_clients() para el aviso de duplicado por teléfono/email,
 * y crm_notes_add() para la nota de sistema. La idempotencia por external_id
 * usa el mismo truco que crm_leadkit_csv_ya_existe(): buscar el marcador
 * dentro de lead_meta en vez de depender de funciones JSON de MySQL.
 *
 * Cursor: option `crm_ecovolt_leads_after_id`, se avanza y se persiste
 * (`update_option()`) inmediatamente después de CADA lead confirmado — no al
 * final del lote ni de la página — para que un fallo a mitad de un lote no
 * reprocese ni salte ningún lead ya insertado (requisito explícito de la
 * especificación).
 *
 * @package CRM_Energitel
 * @since 1.20.161
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CRM_ECOVOLT_LEADS_DEFAULT_URL', 'https://ecovolt.replanta.dev/wp-json/ecovolt/v1/leads');

/**
 * Credenciales configuradas en Ajustes. El Application Password nunca se
 * imprime de vuelta en el formulario — mismo patrón que crm_holded_api_key.
 *
 * @return array{base_url:string, usuario:string, app_password:string}
 */
function crm_ecovolt_leads_get_credenciales() {
    $base_url = trim((string) get_option('crm_ecovolt_leads_base_url', CRM_ECOVOLT_LEADS_DEFAULT_URL));
    return [
        'base_url'     => $base_url !== '' ? $base_url : CRM_ECOVOLT_LEADS_DEFAULT_URL,
        'usuario'      => trim((string) get_option('crm_ecovolt_leads_usuario', '')),
        'app_password' => (string) get_option('crm_ecovolt_leads_app_password', ''),
    ];
}

/**
 * true si hay usuario + Application Password configurados (no garantiza que
 * sean válidos — eso solo lo sabe Ecovolt al llamar).
 */
function crm_ecovolt_leads_configurado() {
    $c = crm_ecovolt_leads_get_credenciales();
    return $c['usuario'] !== '' && $c['app_password'] !== '';
}

/**
 * Pide una página del endpoint de leads de Ecovolt (HTTP Basic Auth con
 * Application Password, tal como especifica el documento del proyecto
 * Ecovolt). Devuelve WP_Error de inmediato si no hay credenciales.
 *
 * @param int $after_id
 * @param int $per_page
 * @return array|WP_Error Cuerpo JSON decodificado (con 'items'/'has_more'), o WP_Error.
 */
function crm_ecovolt_leads_request($after_id = 0, $per_page = 50) {
    if (!crm_ecovolt_leads_configurado()) {
        return new WP_Error('crm_ecovolt_no_config', 'No hay usuario/contraseña de aplicación de Ecovolt configurados en Ajustes.');
    }
    $c = crm_ecovolt_leads_get_credenciales();

    $url = add_query_arg([
        'after_id' => max(0, (int) $after_id),
        'per_page' => min(100, max(1, (int) $per_page)),
    ], $c['base_url']);

    $response = wp_remote_get($url, [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($c['usuario'] . ':' . $c['app_password']),
            'Accept'        => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        crm_ecovolt_leads_log_error('Fallo de red: ' . $response->get_error_message());
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 401) {
        crm_ecovolt_leads_log_error('401 — credenciales no válidas o ausentes.');
        return new WP_Error('crm_ecovolt_unauthorized', 'Ecovolt rechazó las credenciales (401).', ['status' => 401]);
    }
    if ($code === 403) {
        crm_ecovolt_leads_log_error('403 — el usuario técnico no tiene el permiso read_ecovolt_leads.');
        return new WP_Error('crm_ecovolt_forbidden', 'El usuario técnico de Ecovolt no tiene permiso para leer leads (403).', ['status' => 403]);
    }
    if ($code < 200 || $code >= 300) {
        crm_ecovolt_leads_log_error('Error HTTP ' . $code . ' al pedir leads.');
        return new WP_Error('crm_ecovolt_http_error', 'Error HTTP ' . $code . ' al pedir leads a Ecovolt.', ['status' => $code]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
        crm_ecovolt_leads_log_error('Respuesta con formato inesperado (sin "items").');
        return new WP_Error('crm_ecovolt_bad_response', 'La respuesta de Ecovolt no tiene el formato esperado.');
    }
    return $body;
}

/**
 * Registra un fallo en el log general del plugin — nunca con credenciales ni
 * datos personales completos (requisito explícito de la especificación),
 * solo el mensaje de error/estado HTTP.
 */
function crm_ecovolt_leads_log_error($mensaje) {
    if (function_exists('crm_log_action')) {
        crm_log_action('ecovolt_leads_error', 'Ecovolt leads: ' . $mensaje, null, null, 'error');
    }
}

/**
 * true si ya existe un cliente importado con este external_id — mismo
 * criterio que crm_leadkit_csv_ya_existe(): búsqueda de texto sobre
 * lead_meta, sin depender de funciones JSON de MySQL que puede que el
 * hosting no tenga. Idempotente aunque se repita el mismo lote.
 *
 * @param string $external_id
 * @return bool
 */
function crm_ecovolt_lead_ya_existe($external_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $encontrado = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE lead_meta LIKE %s LIMIT 1",
        '%"lead_id":"' . $wpdb->esc_like($external_id) . '"%'
    ));
    return $encontrado > 0;
}

/**
 * Inserta un lead de Ecovolt como cliente nuevo en la cola de Leads MK.
 * Asume que ya se comprobó que no existe (crm_ecovolt_lead_ya_existe()) —
 * esta función no vuelve a comprobarlo.
 *
 * @param array $item Un elemento de "items" de la respuesta del endpoint.
 * @return bool
 */
function crm_ecovolt_lead_insertar(array $item) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $external_id = (string) ($item['external_id'] ?? '');
    $nombre      = (string) ($item['name'] ?? '');
    if ($external_id === '' || $nombre === '') {
        return false;
    }

    // Igual que el resto de altas del CRM (crm-plugin.php): se guarda el
    // teléfono tal cual llega, saneado — la normalización a 9 dígitos
    // (crm_normalize_phone()) es solo para comparar, no para almacenar.
    $telefono = sanitize_text_field((string) ($item['phone'] ?? ''));
    $email    = sanitize_email((string) ($item['email'] ?? ''));

    $fecha_db = current_time('mysql');
    if (!empty($item['created_at'])) {
        $ts = strtotime((string) $item['created_at']);
        if ($ts) {
            $fecha_db = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    $service_label = (string) ($item['service']['label'] ?? '');
    $lead_meta = [
        'lead_id'          => $external_id,
        'platform'         => 'ecovolt_web',
        'form_name'        => (string) ($item['source']['form'] ?? ''),
        'source_url'       => (string) ($item['source_url'] ?? ''),
        'service_key'      => (string) ($item['service']['key'] ?? ''),
        'service_label'    => $service_label,
        'start_preference' => (string) ($item['start_preference']['key'] ?? ''),
        'utm_source'       => (string) ($item['utm']['source'] ?? ''),
        'utm_medium'       => (string) ($item['utm']['medium'] ?? ''),
        'utm_campaign'     => (string) ($item['utm']['campaign'] ?? ''),
        'utm_content'      => (string) ($item['utm']['content'] ?? ''),
        'utm_term'         => (string) ($item['utm']['term'] ?? ''),
        'consent_at'       => (string) ($item['consent']['accepted_at'] ?? ''),
        'imported_at'      => current_time('mysql'),
    ];

    $insert = [
        'delegado'                 => '',
        'user_id'                  => null,
        'email_comercial'          => '',
        'fecha'                    => $fecha_db,
        'cliente_nombre'           => substr($nombre, 0, 255),
        'empresa'                  => '',
        'direccion'                => '',
        'telefono'                 => $telefono,
        'email_cliente'            => $email,
        'poblacion'                => substr((string) ($item['locality'] ?? ''), 0, 255),
        'provincia'                => '',
        'tipo'                     => $service_label,
        'comentarios'              => sanitize_textarea_field((string) ($item['message'] ?? '')),
        'intereses'                => maybe_serialize([]),
        'estado'                   => 'borrador',
        'estado_por_sector'        => maybe_serialize([]),
        'fecha_envio_por_sector'   => '',
        'usuario_envio_por_sector' => '',
        'creado_por'               => 0,
        'creado_en'                => current_time('mysql'),
        'origen_lead'              => 'lead_mk',
        'es_cliente_activo'        => 0,
        'lead_meta'                => wp_json_encode($lead_meta),
    ];

    $ok = $wpdb->insert($table, $insert);
    if ($ok === false) {
        crm_ecovolt_leads_log_error('Fallo al insertar en BD (external_id ' . $external_id . '): ' . $wpdb->last_error);
        return false;
    }
    $client_id = (int) $wpdb->insert_id;

    // Aviso de posible duplicado por teléfono/email — no bloquea el alta,
    // mismo criterio que el resto del CRM (crm_find_duplicate_clients()
    // nunca impide guardar, solo informa).
    $dup_texto = '';
    if (function_exists('crm_find_duplicate_clients')) {
        $dupes = crm_find_duplicate_clients($telefono, $email, $client_id, 1);
        if (!empty($dupes)) {
            $dup_texto = ' Posible duplicado con la ficha #' . (int) $dupes[0]['id'] . '.';
        }
    }

    if (function_exists('crm_notes_add')) {
        $detalle = 'Lead importado desde el formulario web de Ecovolt.';
        if ($service_label !== '') {
            $detalle .= ' Servicio: ' . $service_label . '.';
        }
        if (!empty($lead_meta['utm_campaign'])) {
            $detalle .= ' Campaña: ' . $lead_meta['utm_campaign'] . '.';
        }
        crm_notes_add([
            'client_id' => $client_id,
            'tipo'      => 'sistema',
            'texto'     => $detalle . $dup_texto,
        ]);
    }

    return true;
}

/**
 * Sincronización incremental completa: pide páginas hasta agotar has_more o
 * encontrar un error, avanzando y persistiendo el cursor lead a lead (no al
 * final del lote) para que un fallo a mitad de un lote no reprocese ni salte
 * ningún lead ya confirmado.
 *
 * @param int $max_paginas Límite de seguridad contra un has_more que nunca
 *                         se apague — no una expectativa real (2000 leads
 *                         por pasada de sobra para el volumen de Ecovolt).
 * @return array{ok:bool, procesados:int, importados:int, ya_existian:int, errores:int, mensaje:string}
 */
function crm_ecovolt_leads_sync_run($max_paginas = 40) {
    if (!crm_ecovolt_leads_configurado()) {
        return [
            'ok' => false, 'procesados' => 0, 'importados' => 0,
            'ya_existian' => 0, 'errores' => 0,
            'mensaje' => 'No hay usuario/contraseña de aplicación configurados.',
        ];
    }

    $cursor      = (int) get_option('crm_ecovolt_leads_after_id', 0);
    $procesados  = 0;
    $importados  = 0;
    $ya_existian = 0;
    $errores     = 0;
    $mensaje     = 'ok';

    for ($pagina = 0; $pagina < $max_paginas; $pagina++) {
        $respuesta = crm_ecovolt_leads_request($cursor, 50);
        if (is_wp_error($respuesta)) {
            $errores++;
            $mensaje = $respuesta->get_error_message();
            break;
        }

        $parar = false;
        foreach ($respuesta['items'] as $item) {
            $item_id     = isset($item['id']) ? (int) $item['id'] : 0;
            $external_id = (string) ($item['external_id'] ?? '');
            if ($item_id <= 0 || $external_id === '') {
                $errores++;
                $mensaje = 'Lead sin id/external_id válido — parado en el cursor ' . $cursor . '.';
                $parar = true;
                break;
            }

            $procesados++;
            if (crm_ecovolt_lead_ya_existe($external_id)) {
                $ya_existian++;
            } else {
                if (!crm_ecovolt_lead_insertar($item)) {
                    $errores++;
                    $mensaje = 'Fallo al insertar un lead — parado en el cursor ' . $cursor . '.';
                    $parar = true;
                    break;
                }
                $importados++;
            }

            // Cursor confirmado hasta AQUÍ — se guarda ya, lead a lead.
            $cursor = $item_id;
            update_option('crm_ecovolt_leads_after_id', $cursor, false);
        }

        if ($parar || empty($respuesta['has_more'])) {
            break;
        }
    }

    if (function_exists('crm_log_action')) {
        crm_log_action(
            'ecovolt_leads_sync',
            sprintf('Sincronización de leads Ecovolt: %d procesados, %d importados, %d ya existían, %d errores.', $procesados, $importados, $ya_existian, $errores),
            null, null, $errores > 0 ? 'error' : 'info'
        );
    }

    return [
        'ok'          => $errores === 0,
        'procesados'  => $procesados,
        'importados'  => $importados,
        'ya_existian' => $ya_existian,
        'errores'     => $errores,
        'mensaje'     => $mensaje,
    ];
}

/**
 * Cron autorreparable — mismo patrón que crm_holded_sync_clientes_schedule_cron().
 */
add_action('init', 'crm_ecovolt_leads_sync_schedule_cron', 20);
function crm_ecovolt_leads_sync_schedule_cron() {
    if (!wp_next_scheduled('crm_ecovolt_leads_sync_cron')) {
        wp_schedule_event(time() + 300, 'hourly', 'crm_ecovolt_leads_sync_cron');
    }
}
add_action('crm_ecovolt_leads_sync_cron', 'crm_ecovolt_leads_sync_cron_run');
function crm_ecovolt_leads_sync_cron_run() {
    if (!get_option('crm_ecovolt_leads_sync_enabled', false)) {
        return;
    }
    crm_ecovolt_leads_sync_run();
}

/**
 * Botón "Sincronizar ahora" en Ajustes.
 */
add_action('wp_ajax_crm_ecovolt_leads_sync_ahora', 'crm_ecovolt_leads_ajax_sync_ahora');
function crm_ecovolt_leads_ajax_sync_ahora() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_admin_actions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }
    wp_send_json_success(crm_ecovolt_leads_sync_run());
}

/**
 * Roadmap (v1.20.161) — ver includes/flujos-page.php.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Ecovolt · leads web',
        'titulo'  => 'Importación incremental de leads del formulario web de Ecovolt',
        'estado'  => 'en_pruebas',
        'detalle' => 'Especificación del lado emisor en docs/CRM-LEADS-INTEGRATION.md del proyecto Ecovolt (repo separado, no se toca desde aquí). Cron horario + botón "Sincronizar ahora" (Ajustes) piden el endpoint autenticado de Ecovolt (usuario técnico + Application Password, Basic Auth) y los insertan en la cola de Leads MK (origen_lead=\'lead_mk\'), reutilizando los helpers de duplicados y notas ya existentes. Idempotente por external_id (mismo criterio que el import de LeadKit CSV), cursor por opción avanzado y guardado lead a lead — un fallo a mitad de lote no reprocesa ni salta nada. Verificado con un script standalone (401, 403, importación, duplicados, cursor, paginación, error a mitad de lote) antes de desplegar. Pendiente: probar contra el endpoint real de Ecovolt en cuanto exista el usuario técnico.',
    ];
    return $fases;
});
