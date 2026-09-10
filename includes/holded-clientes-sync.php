<?php
/**
 * Sincronización de clientes desde Holded (v1.20.95).
 *
 * Decisión confirmada con el usuario 2026-09-09: TODOS los contactos del
 * Holded de Ecovolt deben aparecer como cliente en el CRM (esa cuenta de
 * Holded es básicamente para el interés "renovables" — el resto de
 * intereses los gestiona Energitel fuera de Holded), con su último
 * presupuesto conocido y un estado_por_sector['renovables'] que refleja
 * automáticamente el estado real del presupuesto en Holded — no solo al
 * crear el cliente, en cada pasada de la sincro.
 *
 * Reutiliza crm_inst_match_or_create_client_from_holded_contact() (Fase 2,
 * includes/instalaciones.php) para crear/emparejar el cliente por
 * holded_contact_id/email — aquí solo se añade el paso, nuevo, de mantener
 * actualizado el estado y el presupuesto en cada pasada (esa función solo
 * los fija una vez, al crear).
 *
 * Importante: el sector "renovables" nunca se hace retroceder más allá de
 * lo que Holded puede explicar por sí solo (si existe un presupuesto y si
 * está aprobado) — si un comercial ya avanzó ese sector a mano hasta
 * contratos_generados/firmados, la sincro lo deja intacto, porque Holded no
 * tiene ninguna señal sobre contratos.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Estados de "renovables" que Holded puede explicar por sí solo — ver nota
 * de cabecera. Cualquier estado fuera de esta lista (contratos_generados,
 * contratos_firmados) es responsabilidad humana y la sincro no lo toca.
 *
 * @return string[]
 */
function crm_holded_sync_estados_observables() {
    return ['', 'borrador', 'enviado', 'presupuesto_generado', 'presupuesto_aceptado'];
}

/**
 * Presupuesto más reciente de un contacto de Holded. Una sola llamada: la
 * propia lista de /estimates ya trae el campo `draft` (bool) — no hace
 * falta pedir el detalle de cada presupuesto para saber si está aprobado
 * (verificado en vivo contra la API real, 2026-09-09).
 *
 * @param string $holded_contact_id
 * @return array{id:string,document_number:string,total:?float,currency:string,aprobado:bool}|null
 */
function crm_holded_get_ultimo_presupuesto_contacto($holded_contact_id) {
    $holded_contact_id = trim((string) $holded_contact_id);
    if ($holded_contact_id === '') {
        return null;
    }
    $result = crm_holded_request('GET', 'estimates', [
        'query' => ['contact_id' => $holded_contact_id, 'sort' => '-date', 'limit' => 1],
    ]);
    if (is_wp_error($result) || empty($result['items'][0])) {
        return null;
    }
    $e = $result['items'][0];
    return [
        'id'              => (string) ($e['id'] ?? ''),
        'document_number' => (string) ($e['document_number'] ?? ''),
        'total'           => isset($e['total']) ? (float) str_replace(',', '.', (string) $e['total']) : null,
        'currency'        => (string) ($e['currency'] ?? ''),
        'aprobado'        => empty($e['draft']),
    ];
}

/**
 * Actualiza intereses / estado_por_sector['renovables'] / estado global /
 * datos cacheados del último presupuesto, para un cliente ya vinculado a
 * un contacto de Holded.
 *
 * Optimización importante: `GET /contacts` no tiene filtro "modificado
 * desde" (verificado en la API real) — con ~2000 contactos posibles, no es
 * viable pedir /estimates para cada uno en cada pasada horaria. Se compara
 * el `updated_at` que Holded ya trae en el propio contacto contra el que se
 * guardó la última vez: si no ha cambiado Y ya se resolvió antes, se salta
 * la llamada a /estimates entero.
 *
 * @param int    $client_id
 * @param array  $contacto Objeto de contacto de Holded (id, updated_at...).
 */
function crm_holded_sync_actualizar_cliente($client_id, array $contacto) {
    $client_id = (int) $client_id;
    if ($client_id <= 0) {
        return;
    }
    $holded_contact_id = (string) ($contacto['id'] ?? '');
    $updated_at        = (string) ($contacto['updated_at'] ?? '');

    global $wpdb;
    $table  = $wpdb->prefix . 'crm_clients';
    $client = $wpdb->get_row($wpdb->prepare(
        "SELECT intereses, estado_por_sector, presupuesto, holded_contact_updated_at, holded_last_synced_at FROM {$table} WHERE id = %d",
        $client_id
    ), ARRAY_A);
    if (!$client) {
        return;
    }
    $client['id'] = $client_id;

    $ya_procesado_antes = !empty($client['holded_last_synced_at']);
    $sin_cambios        = $updated_at !== '' && $updated_at === $client['holded_contact_updated_at'];
    if ($sin_cambios && $ya_procesado_antes) {
        return; // Nada que Holded haya cambiado desde la última pasada.
    }

    $presupuesto = crm_holded_get_ultimo_presupuesto_contacto($holded_contact_id);

    $intereses = crm_safe_unserialize_array($client['intereses'] ?? '');
    if (!in_array('renovables', $intereses, true)) {
        $intereses[] = 'renovables';
    }

    $estado_por_sector = crm_safe_unserialize_array($client['estado_por_sector'] ?? '');
    $actual = (string) ($estado_por_sector['renovables'] ?? '');
    if (in_array($actual, crm_holded_sync_estados_observables(), true)) {
        if (!$presupuesto) {
            $estado_por_sector['renovables'] = 'borrador';
        } else {
            $estado_por_sector['renovables'] = $presupuesto['aprobado'] ? 'presupuesto_aceptado' : 'presupuesto_generado';
        }
    }

    $update = [
        'intereses'                 => maybe_serialize(array_values($intereses)),
        'estado_por_sector'         => maybe_serialize($estado_por_sector),
        'estado'                    => crm_calcula_estado_global($estado_por_sector),
        'holded_last_synced_at'     => current_time('mysql'),
        'holded_contact_updated_at' => $updated_at !== '' ? $updated_at : null,
        'holded_estimate_id'        => $presupuesto['id'] ?? null,
        'holded_estimate_numero'    => $presupuesto['document_number'] ?? null,
        'holded_estimate_total'     => $presupuesto['total'] ?? null,
        'holded_estimate_moneda'    => $presupuesto['currency'] ?? null,
        'holded_estimate_aprobado'  => $presupuesto ? ( $presupuesto['aprobado'] ? 1 : 0 ) : null,
    ];

    // v1.20.98: adjuntar el PDF del presupuesto al campo "Presupuestos" del
    // cliente (antes la sincro solo guardaba el número/importe en las
    // columnas holded_estimate_*, mostrados en la card de la ficha, pero
    // nunca el propio documento — quedaba sin marcar en la columna
    // "Documentos" del listado de clientes). Reutiliza el mismo mecanismo
    // que ya usaba el alta de instalación desde presupuesto.
    if ($presupuesto && !empty($presupuesto['id']) && function_exists('crm_inst_adjuntar_presupuesto_holded_si_falta')) {
        $presupuesto_actualizado = crm_inst_adjuntar_presupuesto_holded_si_falta($client, $presupuesto['id']);
        if ($presupuesto_actualizado !== null) {
            $update['presupuesto'] = $presupuesto_actualizado;
        }
    }

    $wpdb->update($table, $update, ['id' => $client_id]);
}

/**
 * Recorre todos los contactos de Holded y crea/actualiza su cliente
 * correspondiente en el CRM. Best-effort por contacto: un fallo puntual no
 * interrumpe el resto de la pasada.
 *
 * @return array{procesados:int, creados:int, errores:int}
 */
function crm_holded_sync_clientes_run() {
    if (!function_exists('crm_holded_get_contacts_cached') || !function_exists('crm_inst_match_or_create_client_from_holded_contact')) {
        return ['procesados' => 0, 'creados' => 0, 'errores' => 0];
    }

    $contactos = crm_holded_get_contacts_cached();
    if (is_wp_error($contactos)) {
        if (function_exists('crm_log_action')) {
            crm_log_action('holded_sync_clientes_error', 'No se pudo obtener el listado de contactos de Holded: ' . $contactos->get_error_message(), null, null, 'error');
        }
        return ['procesados' => 0, 'creados' => 0, 'errores' => 1];
    }

    global $wpdb;
    $table      = $wpdb->prefix . 'crm_clients';
    $procesados = 0;
    $creados    = 0;
    $errores    = 0;

    foreach ((array) $contactos as $contacto) {
        $holded_contact_id = (string) ($contacto['id'] ?? '');
        if ($holded_contact_id === '') {
            continue;
        }

        $ya_existia = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE holded_contact_id = %s",
            $holded_contact_id
        )) > 0;

        $client_id = crm_inst_match_or_create_client_from_holded_contact($contacto);
        if (is_wp_error($client_id)) {
            $errores++;
            continue;
        }
        if (!$ya_existia) {
            $creados++;
        }

        crm_holded_sync_actualizar_cliente($client_id, $contacto);
        $procesados++;
    }

    update_option('crm_holded_sync_clientes_last_run', time(), false);
    if (function_exists('crm_log_action')) {
        crm_log_action(
            'holded_sync_clientes',
            sprintf('Sincronización de clientes desde Holded: %d procesados, %d creados, %d errores.', $procesados, $creados, $errores),
            null,
            null,
            'info'
        );
    }

    return ['procesados' => $procesados, 'creados' => $creados, 'errores' => $errores];
}

/**
 * Cron autorreparable — mismo patrón que crm_leads_sheets_schedule_cron()
 * (includes/leads-sheets.php) y crm_inst_aviso_schedule_cron()
 * (includes/instalaciones.php): registrado en init, se comprueba
 * wp_next_scheduled() para no duplicar, y el propio callback respeta un
 * ajuste de "activado/desactivado" en vez de desprogramarse.
 */
add_action('init', 'crm_holded_sync_clientes_schedule_cron', 20);
function crm_holded_sync_clientes_schedule_cron() {
    if (!wp_next_scheduled('crm_holded_sync_clientes_cron')) {
        wp_schedule_event(time() + 300, 'hourly', 'crm_holded_sync_clientes_cron');
    }
}
add_action('crm_holded_sync_clientes_cron', 'crm_holded_sync_clientes_cron_run');
function crm_holded_sync_clientes_cron_run() {
    if (!get_option('crm_holded_sync_clientes_enabled', false)) {
        return;
    }
    crm_holded_sync_clientes_run();
}

/**
 * Botón "Sincronizar ahora" en Ajustes.
 */
add_action('wp_ajax_crm_holded_sync_clientes_ahora', 'crm_holded_ajax_sync_clientes_ahora');
function crm_holded_ajax_sync_clientes_ahora() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_admin_actions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }
    $resultado = crm_holded_sync_clientes_run();
    wp_send_json_success($resultado);
}
