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
 * está aprobado, más la oportunidad de ventas — ver
 * `crm_holded_lead_etapa_a_estado_avanzado()`) — si un comercial ya avanzó
 * ese sector a mano más allá de lo que Holded puede confirmar, la sincro lo
 * deja intacto.
 *
 * v1.20.99 — decisiones confirmadas con el usuario 2026-09-10 tras revisar
 * un cliente real con datos incompletos:
 * - Solo los contactos con `type === 'client'` se convierten en cliente del
 *   CRM (antes se procesaban TODOS los types — proveedores/leads sin
 *   convertir se estaban colando como fichas de cliente).
 * - `tipo` (Residencial/Autónomo/Empresa) se rellena solo si estaba vacío,
 *   a partir de `is_person` del contacto (persona física → Residencial,
 *   empresa → Empresa) — antes esta sincro nunca lo tocaba.
 * - La oportunidad de venta ("deal"/lead del CRM de Holded, distinto de
 *   contactos/presupuestos) del contacto se guarda también, y su etapa
 *   puede avanzar el estado más allá de lo que el presupuesto sabe (p.ej.
 *   "Contrato firmado") — ver `includes/holded-api.php`.
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
 * datos cacheados del último presupuesto y de la oportunidad de venta, para
 * un cliente ya vinculado a un contacto de Holded.
 *
 * Optimización importante: `GET /contacts` no tiene filtro "modificado
 * desde" (verificado en la API real) — con ~2000 contactos posibles, no es
 * viable pedir /estimates para cada uno en cada pasada horaria. Se compara
 * el `updated_at` que Holded ya trae en el propio contacto contra el que se
 * guardó la última vez: si no ha cambiado Y ya se resolvió antes, se salta
 * la llamada a /estimates y se reutiliza el presupuesto ya cacheado en el
 * propio cliente.
 *
 * La oportunidad de venta, en cambio, SIEMPRE se recalcula: no cuesta una
 * llamada extra por contacto (sale de `crm_holded_get_leads_cached()`, un
 * único listado de toda la cuenta cacheado 30 min, filtrado en memoria) y
 * puede cambiar de etapa sin que el contacto se entere (su `updated_at` no
 * se toca al mover una oportunidad de columna en el pipeline de Holded).
 *
 * @param int    $client_id
 * @param array  $contacto Objeto de contacto de Holded (id, updated_at, is_person, website...).
 * @return array{adjuntado:bool,error:?string} Resultado de intentar adjuntar el PDF del presupuesto
 *         (v1.20.101 — antes un fallo quedaba solo en el log de acciones, sin que nadie lo viera al
 *         pulsar "Sincronizar ahora").
 */
/**
 * Compara dirección/población/provincia/código postal del cliente en el CRM
 * contra el `bill_address` del contacto de Holded — reunión con cliente
 * 2026-09-30: hasta ahora solo el CP se rellenaba si estaba vacío (v1.20.154)
 * y ningún campo se comparaba si ya tenía valor. Ahora:
 *  - Campo vacío en el CRM + Holded trae algo -> se rellena solo, sin preguntar
 *    (no hay nada que decidir si no había dato previo).
 *  - Campo YA con un valor + Holded trae uno DISTINTO -> nunca se pisa; queda
 *    anotado en `holded_discrepancias` (columna JSON) para que la ficha lo
 *    muestre con un botón "usar este" — decide el usuario, no la sincro.
 *  - Si ya coinciden, o si una discrepancia pendiente de antes ya no aplica
 *    (el valor de Holded cambió otra vez, o el del CRM ahora coincide), se
 *    limpia sola de `holded_discrepancias`.
 *
 * @param array $client   Fila actual (direccion, poblacion, provincia,
 *                        codigo_postal, holded_discrepancias).
 * @param array $contacto Contacto de Holded (bill_address).
 * @return array{update:array<string,string>, discrepancias:?array}
 *         `discrepancias` es null si no hace falta tocar esa columna;
 *         si no, el array final a guardar (posiblemente vacío -> NULL en BD).
 */
function crm_holded_reconciliar_direccion(array $client, array $contacto) {
    $bill_address = is_array($contacto['bill_address'] ?? null) ? $contacto['bill_address'] : [];

    $valores_holded = [
        'direccion'     => trim((string) ($bill_address['address'] ?? '')),
        'poblacion'     => trim((string) ($bill_address['city'] ?? '')),
        'provincia'     => function_exists('crm_inst_safe_provincia') ? crm_inst_safe_provincia($bill_address['province'] ?? '') : '',
        'codigo_postal' => trim((string) ($bill_address['postal_code'] ?? '')),
    ];
    // El CP de Holded solo cuenta si tiene un formato válido para la
    // provincia de referencia (la que ya hay en el CRM, o si no la que trae
    // el propio Holded) — mismo criterio que
    // crm_inst_extract_client_fields_from_holded_contact() al crear.
    if ($valores_holded['codigo_postal'] !== '' && function_exists('crm_validate_codigo_postal')) {
        $provincia_actual = trim((string) ($client['provincia'] ?? ''));
        $provincia_referencia = $provincia_actual !== '' ? $provincia_actual : $valores_holded['provincia'];
        if (!crm_validate_codigo_postal($valores_holded['codigo_postal'], $provincia_referencia)) {
            $valores_holded['codigo_postal'] = '';
        }
    }

    $pendientes = [];
    if (!empty($client['holded_discrepancias'])) {
        $decoded = json_decode((string) $client['holded_discrepancias'], true);
        if (is_array($decoded)) {
            $pendientes = $decoded;
        }
    }

    $update = [];
    $cambio_en_pendientes = false;

    foreach ($valores_holded as $campo => $valor_holded) {
        if ($valor_holded === '') {
            continue; // Holded no aporta nada para este campo — nada que decidir.
        }
        $valor_actual = trim((string) ($client[$campo] ?? ''));

        if ($valor_actual === '') {
            $update[$campo] = $valor_holded;
            if (isset($pendientes[$campo])) {
                unset($pendientes[$campo]);
                $cambio_en_pendientes = true;
            }
            continue;
        }

        if ($valor_actual === $valor_holded) {
            if (isset($pendientes[$campo])) {
                unset($pendientes[$campo]); // Se resolvió sola (ya coinciden).
                $cambio_en_pendientes = true;
            }
            continue;
        }

        // Valores distintos y los dos con contenido -> discrepancia real.
        if (!isset($pendientes[$campo]) || $pendientes[$campo]['valor_holded'] !== $valor_holded) {
            $pendientes[$campo] = [
                'valor_holded' => $valor_holded,
                'detectado_en' => current_time('mysql'),
            ];
            $cambio_en_pendientes = true;
        }
    }

    return [
        'update'        => $update,
        'discrepancias' => $cambio_en_pendientes ? $pendientes : null,
    ];
}

/**
 * AJAX: botones "Usar el de Holded"/"Descartar" de la ficha de cliente
 * (aviso de holded_discrepancias). No restringido a crm_admin — un
 * comercial edita sus propios clientes, igual que el resto del formulario
 * de alta/edición (permiso real: crm_user_can_access_client()).
 */
add_action('wp_ajax_crm_holded_discrepancia_resolver', 'crm_ajax_holded_discrepancia_resolver');
function crm_ajax_holded_discrepancia_resolver() {
    if (!is_user_logged_in() || !check_ajax_referer('crm_alta_cliente_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'No autorizado.'], 403);
    }

    $client_id = (int) ($_POST['client_id'] ?? 0);
    $campo     = sanitize_key((string) ($_POST['campo'] ?? ''));
    $accion    = sanitize_key((string) ($_POST['accion'] ?? ''));

    $campos_validos = ['direccion', 'poblacion', 'provincia', 'codigo_postal'];
    if ($client_id <= 0 || !in_array($campo, $campos_validos, true) || !in_array($accion, ['aplicar', 'descartar'], true)) {
        wp_send_json_error(['message' => 'Datos incompletos.']);
    }
    if (!function_exists('crm_user_can_access_client') || !crm_user_can_access_client($client_id)) {
        wp_send_json_error(['message' => 'Sin permisos sobre este cliente.'], 403);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $row = $wpdb->get_row($wpdb->prepare("SELECT holded_discrepancias, provincia FROM {$table} WHERE id = %d", $client_id), ARRAY_A);
    if (!$row) {
        wp_send_json_error(['message' => 'Cliente no encontrado.']);
    }

    $pendientes = [];
    if (!empty($row['holded_discrepancias'])) {
        $decoded = json_decode((string) $row['holded_discrepancias'], true);
        $pendientes = is_array($decoded) ? $decoded : [];
    }
    if (!isset($pendientes[$campo])) {
        wp_send_json_error(['message' => 'Esa discrepancia ya no está pendiente (puede que se resolviera en otra pestaña).']);
    }

    $valor_holded = (string) $pendientes[$campo]['valor_holded'];
    $update = [];

    if ($accion === 'aplicar') {
        // Re-validar el CP contra la provincia actual (pudo cambiar mientras
        // el aviso estaba pendiente) antes de escribirlo de verdad.
        if ($campo === 'codigo_postal' && function_exists('crm_validate_codigo_postal') && !crm_validate_codigo_postal($valor_holded, $row['provincia'] ?? '')) {
            wp_send_json_error(['message' => 'Ese código postal ya no es válido para la provincia actual del cliente.']);
        }
        $update[$campo] = $valor_holded;
    }

    unset($pendientes[$campo]);
    $update['holded_discrepancias'] = empty($pendientes) ? null : wp_json_encode($pendientes);

    $wpdb->update($table, $update, ['id' => $client_id]);

    if (function_exists('crm_log_action')) {
        crm_log_action(
            'holded_discrepancia_resuelta',
            sprintf('Discrepancia de "%s" %s (Holded: "%s").', $campo, $accion === 'aplicar' ? 'aplicada' : 'descartada', $valor_holded),
            $client_id, null, 'info'
        );
    }

    wp_send_json_success(['valor' => $valor_holded, 'accion' => $accion]);
}

function crm_holded_sync_actualizar_cliente($client_id, array $contacto) {
    $client_id = (int) $client_id;
    if ($client_id <= 0) {
        return ['adjuntado' => false, 'error' => null];
    }
    $holded_contact_id = (string) ($contacto['id'] ?? '');
    $updated_at        = (string) ($contacto['updated_at'] ?? '');

    global $wpdb;
    $table  = $wpdb->prefix . 'crm_clients';
    $client = $wpdb->get_row($wpdb->prepare(
        "SELECT intereses, estado_por_sector, presupuesto, tipo, holded_web,
                holded_estimate_id, holded_estimate_numero, holded_estimate_total,
                holded_estimate_moneda, holded_estimate_aprobado,
                holded_contact_updated_at, holded_last_synced_at,
                codigo_postal, provincia, direccion, poblacion, holded_discrepancias
         FROM {$table} WHERE id = %d",
        $client_id
    ), ARRAY_A);
    if (!$client) {
        return ['adjuntado' => false, 'error' => null];
    }
    $client['id'] = $client_id;

    $ya_procesado_antes   = !empty($client['holded_last_synced_at']);
    $contacto_sin_cambios = $updated_at !== '' && $updated_at === $client['holded_contact_updated_at'];

    if ($contacto_sin_cambios && $ya_procesado_antes) {
        $presupuesto = !empty($client['holded_estimate_id']) ? [
            'id'              => (string) $client['holded_estimate_id'],
            'document_number' => (string) $client['holded_estimate_numero'],
            'total'           => $client['holded_estimate_total'] !== null ? (float) $client['holded_estimate_total'] : null,
            'currency'        => (string) $client['holded_estimate_moneda'],
            'aprobado'        => !empty($client['holded_estimate_aprobado']),
        ] : null;
    } else {
        $presupuesto = crm_holded_get_ultimo_presupuesto_contacto($holded_contact_id);
    }
    $oportunidad = crm_holded_get_oportunidad_contacto($holded_contact_id);

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
    // v1.20.99: la oportunidad puede saber cosas que el presupuesto nunca
    // sabrá (contrato enviado/firmado) — si su etapa mapea a un estado MÁS
    // avanzado que el que ya tenemos, se adopta; nunca al revés (ver
    // crm_holded_lead_etapa_a_estado_avanzado(), que deliberadamente no
    // mapea etapas anteriores a "aceptado" porque pueden ir por detrás del
    // presupuesto real).
    if ($oportunidad) {
        $estado_sugerido = crm_holded_lead_etapa_a_estado_avanzado($oportunidad['etapa_nombre']);
        if ($estado_sugerido !== null) {
            $orden        = crm_get_orden_estados();
            $pos_actual   = array_search((string) ($estado_por_sector['renovables'] ?? ''), $orden, true);
            $pos_sugerido = array_search($estado_sugerido, $orden, true);
            if ($pos_sugerido !== false && ($pos_actual === false || $pos_sugerido > $pos_actual)) {
                $estado_por_sector['renovables'] = $estado_sugerido;
            }
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
        'holded_lead_id'            => $oportunidad['id'] ?? null,
        'holded_lead_valor'         => $oportunidad['valor'] ?? null,
        'holded_lead_probabilidad'  => $oportunidad['probabilidad'] ?? null,
        'holded_lead_etapa'         => $oportunidad['etapa_nombre'] ?? null,
        'holded_lead_status'        => $oportunidad['status'] ?? null,
        'holded_lead_user_id'       => $oportunidad['user_id'] ?? null,
    ];

    // v1.20.99: tipo (empresa/persona) y web, solo si estaban vacíos — nunca
    // se corrige un dato real que ya hubiera puesto un comercial a mano.
    if (empty($client['tipo']) && array_key_exists('is_person', $contacto)) {
        $update['tipo'] = !empty($contacto['is_person']) ? 'Residencial' : 'Empresa';
    }
    if (empty($client['holded_web']) && !empty($contacto['website'])) {
        $update['holded_web'] = esc_url_raw((string) $contacto['website']);
    }
    // v1.20.166: dirección/población/provincia/CP — si estaban vacías se
    // rellenan solas (mismo criterio que ya tenía el CP desde v1.20.154); si
    // ya tenían un valor y Holded trae uno DISTINTO, no se pisa nunca —
    // queda anotado en holded_discrepancias para que la ficha lo muestre y
    // el usuario decida cuál de los dos es el bueno (reunión 2026-09-30).
    $discrepancias_resultado = crm_holded_reconciliar_direccion($client, $contacto);
    $update = array_merge($update, $discrepancias_resultado['update']);
    if ($discrepancias_resultado['discrepancias'] !== null) {
        $update['holded_discrepancias'] = $discrepancias_resultado['discrepancias'] === []
            ? null
            : wp_json_encode($discrepancias_resultado['discrepancias']);
    }

    // v1.20.98: adjuntar el PDF del presupuesto al campo "Presupuestos" del
    // cliente (antes la sincro solo guardaba el número/importe en las
    // columnas holded_estimate_*, mostrados en la card de la ficha, pero
    // nunca el propio documento — quedaba sin marcar en la columna
    // "Documentos" del listado de clientes). Reutiliza el mismo mecanismo
    // que ya usaba el alta de instalación desde presupuesto.
    $pdf_error     = null;
    $pdf_adjuntado = false;
    if ($presupuesto && !empty($presupuesto['id']) && function_exists('crm_inst_adjuntar_presupuesto_holded_si_falta')) {
        $presupuesto_actualizado = crm_inst_adjuntar_presupuesto_holded_si_falta($client, $presupuesto['id'], $pdf_error);
        if ($presupuesto_actualizado !== null) {
            $update['presupuesto'] = $presupuesto_actualizado;
            $pdf_adjuntado         = true;
        }
    }

    $wpdb->update($table, $update, ['id' => $client_id]);

    return ['adjuntado' => $pdf_adjuntado, 'error' => $pdf_error];
}

/**
 * Recorre todos los contactos de Holded y crea/actualiza su cliente
 * correspondiente en el CRM. Best-effort por contacto: un fallo puntual no
 * interrumpe el resto de la pasada.
 *
 * @return array{procesados:int, creados:int, errores:int, pdf_adjuntados:int, pdf_errores:array<int,string>}
 */
function crm_holded_sync_clientes_run() {
    if (!function_exists('crm_holded_get_contacts_cached') || !function_exists('crm_inst_match_or_create_client_from_holded_contact')) {
        return ['procesados' => 0, 'creados' => 0, 'errores' => 0, 'pdf_adjuntados' => 0, 'pdf_errores' => []];
    }

    $contactos = crm_holded_get_contacts_cached();
    if (is_wp_error($contactos)) {
        if (function_exists('crm_log_action')) {
            crm_log_action('holded_sync_clientes_error', 'No se pudo obtener el listado de contactos de Holded: ' . $contactos->get_error_message(), null, null, 'error');
        }
        return ['procesados' => 0, 'creados' => 0, 'errores' => 1, 'pdf_adjuntados' => 0, 'pdf_errores' => []];
    }

    global $wpdb;
    $table       = $wpdb->prefix . 'crm_clients';
    $procesados  = 0;
    $creados     = 0;
    $errores     = 0;
    // v1.20.101: el usuario reportó clientes con presupuesto Aprobado pero sin
    // el PDF adjunto en la ficha — el fallo (si lo hay) quedaba solo en el log
    // de acciones, invisible. Se cuenta y se muestra directamente en el
    // resultado de "Sincronizar ahora".
    $pdf_ok      = 0;
    $pdf_errores = [];

    foreach ((array) $contactos as $contacto) {
        $holded_contact_id = (string) ($contacto['id'] ?? '');
        if ($holded_contact_id === '') {
            continue;
        }
        // v1.20.99: solo contactos marcados como cliente en Holded — antes se
        // procesaban TODOS los `type` (proveedores, leads sin convertir...),
        // creando fichas de cliente que no correspondían.
        if (($contacto['type'] ?? '') !== 'client') {
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

        $resultado_pdf = crm_holded_sync_actualizar_cliente($client_id, $contacto);
        if (!empty($resultado_pdf['adjuntado'])) {
            $pdf_ok++;
        }
        if (!empty($resultado_pdf['error'])) {
            $pdf_errores[$client_id] = $resultado_pdf['error'];
        }
        $procesados++;
    }

    update_option('crm_holded_sync_clientes_last_run', time(), false);
    if (function_exists('crm_log_action')) {
        crm_log_action(
            'holded_sync_clientes',
            sprintf(
                'Sincronización de clientes desde Holded: %d procesados, %d creados, %d errores, %d PDF adjuntados, %d PDF fallidos.',
                $procesados,
                $creados,
                $errores,
                $pdf_ok,
                count($pdf_errores)
            ),
            null,
            null,
            'info'
        );
    }

    return [
        'procesados'     => $procesados,
        'creados'        => $creados,
        'errores'        => $errores,
        'pdf_adjuntados' => $pdf_ok,
        'pdf_errores'    => $pdf_errores,
    ];
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

/**
 * Roadmap de la sincronización de clientes desde Holded (Fase 2bis,
 * v1.20.95/98/99) — ver includes/flujos-page.php. Antes vivía en
 * includes/instalaciones.php; se movió aquí cuando este código pasó a
 * tener su propio archivo.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Fase 2bis',
        'titulo'  => 'Sincronización periódica de clientes desde Holded (contactos + oportunidades de venta)',
        'estado'  => 'hecho',
        'detalle' => 'Cron horario (activable en Ajustes) que crea/actualiza cada contacto type=client de Holded como cliente, con su último presupuesto, estado real, tipo (empresa/persona) y su oportunidad de venta (cantidad, probabilidad, etapa, comercial). Confirmado por el usuario contra la cuenta real: el botón "Sincronizar ahora" (Ajustes → Sincronización de clientes desde Holded) rellenó bien los códigos postales de clientes existentes (v1.20.154) al probarlo en vivo.',
    ];
    return $fases;
});
