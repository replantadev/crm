<?php
/**
 * Sistema de visitas (v1.20.0).
 *
 * Permite a comerciales y admins agendar, listar y gestionar visitas a clientes.
 * Cada visita se asocia a un cliente y opcionalmente a un sector concreto.
 *
 * Tabla: {prefix}crm_visitas
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nombre completo de la tabla.
 */
function crm_visitas_table() {
    global $wpdb;
    return $wpdb->prefix . 'crm_visitas';
}

/**
 * Crea/actualiza el esquema de la tabla de visitas.
 * Se invoca desde activación y desde el chequeo de migración del plugin.
 */
function crm_visitas_install_table() {
    global $wpdb;
    $table = crm_visitas_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        sector VARCHAR(40) DEFAULT NULL,
        comercial_id BIGINT(20) UNSIGNED NOT NULL,
        fecha_visita DATETIME NOT NULL,
        duracion_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
        lugar VARCHAR(255) DEFAULT '',
        notas LONGTEXT DEFAULT NULL,
        estado ENUM('programada','realizada','cancelada','no_show') NOT NULL DEFAULT 'programada',
        resultado VARCHAR(40) DEFAULT NULL,
        creado_por BIGINT(20) UNSIGNED NOT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY comercial_id (comercial_id),
        KEY fecha_visita (fecha_visita),
        KEY estado (estado)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Estados de la visita con label legible.
 */
function crm_visitas_estados() {
    return [
        'programada' => 'Programada',
        'realizada'  => 'Realizada',
        'cancelada'  => 'Cancelada',
        'no_show'    => 'No se presentó',
    ];
}

/**
 * Resultados posibles tras una visita.
 */
function crm_visitas_resultados() {
    return [
        ''                 => '—',
        'interesado'       => 'Interesado',
        'cerro_venta'      => 'Cerró venta',
        'pide_propuesta'   => 'Pide propuesta',
        'pide_segunda'     => 'Pide 2ª visita',
        'no_interesado'    => 'No interesado',
        'cliente_ausente'  => 'Cliente ausente',
    ];
}

/**
 * Sanitiza y valida los datos de una visita antes de persistir.
 * Devuelve array o WP_Error.
 */
function crm_visita_sanitize($input, $is_update = false) {
    $errors = new WP_Error();

    $client_id    = isset($input['client_id']) ? (int) $input['client_id'] : 0;
    $sector       = isset($input['sector']) ? sanitize_key($input['sector']) : '';
    $comercial_id = isset($input['comercial_id']) ? (int) $input['comercial_id'] : get_current_user_id();
    $fecha        = isset($input['fecha_visita']) ? trim((string) $input['fecha_visita']) : '';
    $duracion     = isset($input['duracion_min']) ? (int) $input['duracion_min'] : 60;
    $lugar        = isset($input['lugar']) ? sanitize_text_field((string) $input['lugar']) : '';
    $notas        = isset($input['notas']) ? wp_kses_post((string) $input['notas']) : '';
    $estado       = isset($input['estado']) ? sanitize_key($input['estado']) : 'programada';
    $resultado    = isset($input['resultado']) ? sanitize_key($input['resultado']) : '';

    if ($client_id <= 0) {
        $errors->add('client_id', 'Cliente no válido.');
    }
    if ($comercial_id <= 0) {
        $errors->add('comercial_id', 'Comercial no válido.');
    }
    // Aceptar Y-m-d H:i o Y-m-d H:i:s
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $fecha);
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $fecha);
    }
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha);
    }
    if (!$dt) {
        $errors->add('fecha_visita', 'Fecha y hora no válidas.');
    }
    $duracion = max(5, min($duracion, 600));
    if (!array_key_exists($estado, crm_visitas_estados())) {
        $estado = 'programada';
    }
    if ($resultado !== '' && !array_key_exists($resultado, crm_visitas_resultados())) {
        $resultado = '';
    }
    $sectores_validos = ['', 'energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    if (!in_array($sector, $sectores_validos, true)) {
        $sector = '';
    }

    if ($errors->has_errors()) {
        return $errors;
    }

    return [
        'client_id'    => $client_id,
        'sector'       => $sector === '' ? null : $sector,
        'comercial_id' => $comercial_id,
        'fecha_visita' => $dt->format('Y-m-d H:i:s'),
        'duracion_min' => $duracion,
        'lugar'        => $lugar,
        'notas'        => $notas,
        'estado'       => $estado,
        'resultado'    => $resultado === '' ? null : $resultado,
    ];
}

/**
 * Inserta una visita. Devuelve int (insert id) o WP_Error.
 */
function crm_visita_create($input) {
    global $wpdb;
    $data = crm_visita_sanitize($input, false);
    if (is_wp_error($data)) {
        return $data;
    }
    // v1.20.3: no permitir agendar nuevas visitas en el pasado.
    // Se tolera hasta 5 min hacia atrás para evitar errores por reloj/clic tardío.
    $fecha_ts = strtotime($data['fecha_visita']);
    if ($fecha_ts && $fecha_ts < (current_time('timestamp') - 300)) {
        return new WP_Error('past', 'No se pueden agendar visitas en el pasado.');
    }
    // v1.20.2: anti-solape — bloquear si el asignado tiene otra visita en el slot.
    // Permitir saltar con $input['force_overlap'] = '1' (crm_admin o visitador).
    $force = !empty($input['force_overlap']) && crm_visita_user_can_force_overlap();
    if (!$force) {
        $conflicts = crm_visita_check_conflicts(
            $data['comercial_id'],
            $data['fecha_visita'],
            $data['duracion_min'],
            0
        );
        if (!empty($conflicts)) {
            return new WP_Error(
                'overlap',
                crm_visita_format_conflicts_message($conflicts),
                ['conflicts' => $conflicts]
            );
        }
    }
    $data['creado_por'] = get_current_user_id();
    $data['creado_en']  = current_time('mysql');
    $res = $wpdb->insert(crm_visitas_table(), $data);
    if ($res === false) {
        return new WP_Error('db', 'Error al guardar la visita: ' . $wpdb->last_error);
    }
    return (int) $wpdb->insert_id;
}

/**
 * Actualiza una visita por id. Devuelve true o WP_Error.
 */
function crm_visita_update($id, $input) {
    global $wpdb;
    $id = (int) $id;
    if ($id <= 0) {
        return new WP_Error('id', 'ID inválido.');
    }
    $data = crm_visita_sanitize($input, true);
    if (is_wp_error($data)) {
        return $data;
    }
    // v1.20.2: anti-solape al editar (excluyendo la propia visita).
    $force = !empty($input['force_overlap']) && crm_visita_user_can_force_overlap();
    if (!$force) {
        $conflicts = crm_visita_check_conflicts(
            $data['comercial_id'],
            $data['fecha_visita'],
            $data['duracion_min'],
            $id
        );
        if (!empty($conflicts)) {
            return new WP_Error(
                'overlap',
                crm_visita_format_conflicts_message($conflicts),
                ['conflicts' => $conflicts]
            );
        }
    }
    $data['actualizado_en'] = current_time('mysql');
    $res = $wpdb->update(crm_visitas_table(), $data, ['id' => $id]);
    if ($res === false) {
        return new WP_Error('db', 'Error al actualizar la visita: ' . $wpdb->last_error);
    }
    return true;
}

/**
 * Cambia el estado de una visita.
 */
function crm_visita_set_estado($id, $estado, $resultado = null) {
    global $wpdb;
    if (!array_key_exists($estado, crm_visitas_estados())) {
        return new WP_Error('estado', 'Estado inválido.');
    }
    $data = ['estado' => $estado, 'actualizado_en' => current_time('mysql')];
    if ($resultado !== null && array_key_exists($resultado, crm_visitas_resultados())) {
        $data['resultado'] = $resultado === '' ? null : $resultado;
    }
    return $wpdb->update(crm_visitas_table(), $data, ['id' => (int) $id]) !== false;
}

/**
 * Obtiene una visita por id.
 */
function crm_visita_get($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . crm_visitas_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
}

/**
 * Lista visitas con filtros.
 *
 * @param array $args client_id, comercial_id, estado, desde (Y-m-d), hasta (Y-m-d), limit, offset, orderby, order
 */
function crm_visitas_list($args = []) {
    global $wpdb;
    $defaults = [
        'client_id'    => 0,
        'comercial_id' => 0,
        'estado'       => '',
        'desde'        => '',
        'hasta'        => '',
        'limit'        => 100,
        'offset'       => 0,
        'orderby'      => 'fecha_visita',
        'order'        => 'ASC',
    ];
    $args = wp_parse_args($args, $defaults);

    $where = ['1=1'];
    $params = [];
    if (!empty($args['client_id'])) {
        $where[] = 'client_id = %d';
        $params[] = (int) $args['client_id'];
    }
    if (!empty($args['comercial_id'])) {
        $where[] = 'comercial_id = %d';
        $params[] = (int) $args['comercial_id'];
    }
    if (!empty($args['estado']) && array_key_exists($args['estado'], crm_visitas_estados())) {
        $where[] = 'estado = %s';
        $params[] = $args['estado'];
    }
    if (!empty($args['desde'])) {
        $where[] = 'fecha_visita >= %s';
        $params[] = $args['desde'] . ' 00:00:00';
    }
    if (!empty($args['hasta'])) {
        $where[] = 'fecha_visita <= %s';
        $params[] = $args['hasta'] . ' 23:59:59';
    }
    $allowed_orderby = ['fecha_visita', 'creado_en', 'estado', 'id'];
    $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'fecha_visita';
    $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
    $limit = max(1, min((int) $args['limit'], 500));
    $offset = max(0, (int) $args['offset']);

    $sql = 'SELECT * FROM ' . crm_visitas_table() . ' WHERE ' . implode(' AND ', $where)
        . " ORDER BY $orderby $order LIMIT $limit OFFSET $offset";
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    return $wpdb->get_results($sql, ARRAY_A);
}

/**
 * Cuenta visitas con filtros.
 */
function crm_visitas_count($args = []) {
    global $wpdb;
    $args = wp_parse_args($args, ['client_id' => 0, 'comercial_id' => 0, 'estado' => '', 'desde' => '', 'hasta' => '']);
    $where = ['1=1'];
    $params = [];
    if (!empty($args['client_id']))    { $where[] = 'client_id = %d';    $params[] = (int) $args['client_id']; }
    if (!empty($args['comercial_id'])) { $where[] = 'comercial_id = %d'; $params[] = (int) $args['comercial_id']; }
    if (!empty($args['estado']) && array_key_exists($args['estado'], crm_visitas_estados())) {
        $where[] = 'estado = %s';
        $params[] = $args['estado'];
    }
    if (!empty($args['desde'])) { $where[] = 'fecha_visita >= %s'; $params[] = $args['desde'] . ' 00:00:00'; }
    if (!empty($args['hasta'])) { $where[] = 'fecha_visita <= %s'; $params[] = $args['hasta'] . ' 23:59:59'; }

    $sql = 'SELECT COUNT(*) FROM ' . crm_visitas_table() . ' WHERE ' . implode(' AND ', $where);
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    return (int) $wpdb->get_var($sql);
}

/**
 * Comprueba si el usuario actual puede ver/editar una visita concreta.
 * Admins (admin/crm_admin) pueden todo; comerciales y visitadores solo las
 * que tengan asignadas (comercial_id == su user_id).
 */
function crm_visita_can_manage($visita) {
    if (function_exists('crm_user_is_admin') && crm_user_is_admin()) {
        return true;
    }
    if (is_array($visita) && isset($visita['comercial_id'])) {
        return ((int) $visita['comercial_id']) === get_current_user_id();
    }
    return false;
}

/**
 * Devuelve true si el usuario actual puede CREAR visitas nuevas.
 *
 * Pueden crear: administrator, crm_admin, comercial.
 * NO pueden crear (a menos que tengan también otro rol): visitador.
 *
 * Esto permite que un usuario con roles `comercial + visitador` sí pueda
 * crear visitas (porque también es comercial), mientras que un visitador
 * "puro" solo gestiona las visitas que le asignan.
 */
/**
 * Devuelve un mensaje legible describiendo los conflictos de solape.
 * Lista cada conflicto con cliente, fecha y rango horario.
 *
 * @param array $conflicts array de filas ARRAY_A con id, fecha_visita,
 *                         duracion_min, client_id, estado.
 */
function crm_visita_format_conflicts_message($conflicts) {
    if (empty($conflicts)) {
        return 'Sin conflictos.';
    }
    global $wpdb;
    $client_table = $wpdb->prefix . 'crm_clients';
    $detalles = [];
    foreach ($conflicts as $c) {
        $cliente = '';
        if (!empty($c['client_id'])) {
            $cliente = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT cliente_nombre FROM $client_table WHERE id = %d", (int) $c['client_id'])
            );
        }
        if ($cliente === '') {
            $cliente = 'Cliente #' . (int) ($c['client_id'] ?? 0);
        }
        $inicio = mysql2date('d/m/Y H:i', $c['fecha_visita']);
        try {
            $fin_dt = new DateTime($c['fecha_visita']);
            $fin_dt->modify('+' . (int) $c['duracion_min'] . ' minutes');
            $fin = $fin_dt->format('H:i');
        } catch (Exception $e) {
            $fin = '?';
        }
        $detalles[] = sprintf('%s (%s-%s)', $cliente, $inicio, $fin);
    }
    return sprintf(
        'Solape con %d visita(s): %s',
        count($conflicts),
        implode(' · ', $detalles)
    );
}

/**
 * Comprueba si la visita propuesta se solapa con otra del mismo asignado
 * (comercial o visitador) en el rango [fecha_visita, fecha_visita + duracion).
 *
 * Devuelve array de visitas en conflicto (ARRAY_A) o vacío.
 *
 * @param int    $comercial_id    ID del asignado.
 * @param string $fecha_visita    'Y-m-d H:i:s' inicio propuesto.
 * @param int    $duracion_min    minutos.
 * @param int    $exclude_id      visita_id a excluir (0 = ninguna).
 */
function crm_visita_check_conflicts($comercial_id, $fecha_visita, $duracion_min, $exclude_id = 0) {
    global $wpdb;
    $comercial_id = (int) $comercial_id;
    $duracion_min = max(1, (int) $duracion_min);
    $exclude_id   = (int) $exclude_id;
    if ($comercial_id <= 0 || $fecha_visita === '') {
        return [];
    }
    $tabla = crm_visitas_table();
    // Solapamiento real (intervalos abiertos por la derecha):
    // existente.inicio < propuesta.fin  AND  existente.fin > propuesta.inicio
    $sql = $wpdb->prepare(
        "SELECT id, fecha_visita, duracion_min, client_id, estado
           FROM $tabla
          WHERE comercial_id = %d
            AND estado IN ('programada','realizada')
            AND id <> %d
            AND fecha_visita < DATE_ADD(%s, INTERVAL %d MINUTE)
            AND DATE_ADD(fecha_visita, INTERVAL duracion_min MINUTE) > %s",
        $comercial_id,
        $exclude_id,
        $fecha_visita,
        $duracion_min,
        $fecha_visita
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : [];
}

function crm_visita_can_create() {
    $u = wp_get_current_user();
    if (!$u || empty($u->ID)) {
        return false;
    }
    $roles_que_crean = ['administrator', 'crm_admin', 'comercial'];
    return (bool) array_intersect($roles_que_crean, (array) $u->roles);
}

/**
 * Roles autorizados a IGNORAR el chequeo anti-solape (force_overlap=1).
 *
 * El comercial NO puede forzar — debe respetar la disponibilidad del visitador.
 * El administrator es solo dev/ops y no opera el CRM en producción.
 * crm_admin y visitador SÍ pueden forzar (acumulan visitas si lo necesitan).
 */
function crm_visita_user_can_force_overlap() {
    $u = wp_get_current_user();
    if (!$u || empty($u->ID)) {
        return false;
    }
    $roles_que_fuerzan = ['crm_admin', 'visitador'];
    return (bool) array_intersect($roles_que_fuerzan, (array) $u->roles);
}

/* =========================================================================
 * HANDLERS admin-post (formularios)
 * ========================================================================= */

add_action('admin_post_crm_visita_save', 'crm_visita_handle_save');
function crm_visita_handle_save() {
    if (!is_user_logged_in()) {
        wp_die('No autorizado.', '', ['response' => 403]);
    }
    check_admin_referer('crm_visita_save');

    $id     = isset($_POST['visita_id']) ? (int) $_POST['visita_id'] : 0;
    $input  = wp_unslash($_POST);

    $is_admin = function_exists('crm_user_is_admin') && crm_user_is_admin();
    $current_id = get_current_user_id();

    if (!$is_admin) {
        // Para no-admin (comercial): permitir asignar a uno mismo o a un visitador.
        // Cualquier otro comercial_id se fuerza a uno mismo.
        $target_id = isset($input['comercial_id']) ? (int) $input['comercial_id'] : $current_id;
        if ($target_id !== $current_id) {
            $target_user = $target_id > 0 ? get_userdata($target_id) : null;
            $is_visitador_target = $target_user
                && in_array('visitador', (array) $target_user->roles, true);
            if (!$is_visitador_target) {
                $target_id = $current_id;
            }
        }
        $input['comercial_id'] = $target_id;
        // Solo admins pueden forzar saltar el chequeo de solape.
        unset($input['force_overlap']);
    }

    if ($id > 0) {
        $existing = crm_visita_get($id);
        if (!$existing || !crm_visita_can_manage($existing)) {
            wp_die('Sin permisos para editar esta visita.', '', ['response' => 403]);
        }
        $res = crm_visita_update($id, $input);
    } else {
        if (!crm_visita_can_create()) {
            wp_die('Sin permisos para crear visitas.', '', ['response' => 403]);
        }
        $res = crm_visita_create($input);
    }

    $redirect = wp_get_referer() ?: admin_url('admin.php?page=crm-mi-agenda');
    if (is_wp_error($res)) {
        $redirect = add_query_arg(['crm_visita_msg' => 'error', 'crm_msg' => urlencode($res->get_error_message())], $redirect);
    } else {
        $redirect = add_query_arg(['crm_visita_msg' => $id > 0 ? 'updated' : 'created'], $redirect);
    }
    wp_safe_redirect($redirect);
    exit;
}

add_action('admin_post_crm_visita_estado', 'crm_visita_handle_estado');
function crm_visita_handle_estado() {
    if (!is_user_logged_in()) {
        wp_die('No autorizado.', '', ['response' => 403]);
    }
    check_admin_referer('crm_visita_estado');

    $id     = isset($_POST['visita_id']) ? (int) $_POST['visita_id'] : 0;
    $estado = isset($_POST['estado']) ? sanitize_key($_POST['estado']) : '';
    $resultado = isset($_POST['resultado']) ? sanitize_key($_POST['resultado']) : null;

    $v = crm_visita_get($id);
    if (!$v || !crm_visita_can_manage($v)) {
        wp_die('Sin permisos.', '', ['response' => 403]);
    }
    crm_visita_set_estado($id, $estado, $resultado);
    $redirect = wp_get_referer() ?: admin_url('admin.php?page=crm-mi-agenda');
    $redirect = add_query_arg(['crm_visita_msg' => 'updated'], $redirect);
    wp_safe_redirect($redirect);
    exit;
}

/* =========================================================================
 * AJAX: consultar disponibilidad (busy slots) de un asignado
 * ========================================================================= */

add_action('wp_ajax_crm_visitas_busy', 'crm_ajax_visitas_busy_slots');
function crm_ajax_visitas_busy_slots() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['msg' => 'No autorizado'], 403);
    }
    check_ajax_referer('crm_visitas_busy', 'nonce');

    $comercial_id = isset($_POST['comercial_id']) ? (int) $_POST['comercial_id'] : 0;
    $desde        = isset($_POST['desde']) ? sanitize_text_field((string) $_POST['desde']) : '';
    $hasta        = isset($_POST['hasta']) ? sanitize_text_field((string) $_POST['hasta']) : '';
    $exclude_id   = isset($_POST['exclude_id']) ? (int) $_POST['exclude_id'] : 0;

    if ($comercial_id <= 0 || $desde === '' || $hasta === '') {
        wp_send_json_error(['msg' => 'Parámetros inválidos']);
    }
    // Validar formato Y-m-d (acepta también Y-m-d H:i:s)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $hasta)) {
        wp_send_json_error(['msg' => 'Formato de fecha inválido']);
    }

    global $wpdb;
    $tabla = crm_visitas_table();
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, fecha_visita, duracion_min, client_id, estado
               FROM $tabla
              WHERE comercial_id = %d
                AND estado IN ('programada','realizada')
                AND id <> %d
                AND fecha_visita >= %s
                AND fecha_visita <= %s
              ORDER BY fecha_visita ASC",
            $comercial_id,
            $exclude_id,
            substr($desde, 0, 10) . ' 00:00:00',
            substr($hasta, 0, 10) . ' 23:59:59'
        ),
        ARRAY_A
    );
    if (!is_array($rows)) {
        $rows = [];
    }

    // Cliente nombres en bloque para no hacer N queries.
    $client_ids = array_filter(array_map(static fn($r) => (int) $r['client_id'], $rows));
    $nombres = [];
    if (!empty($client_ids)) {
        $client_table = $wpdb->prefix . 'crm_clients';
        $in_ph = implode(',', array_fill(0, count($client_ids), '%d'));
        $sql_n = $wpdb->prepare("SELECT id, cliente_nombre FROM $client_table WHERE id IN ($in_ph)", $client_ids);
        $res = $wpdb->get_results($sql_n, ARRAY_A);
        foreach ((array) $res as $r) {
            $nombres[(int) $r['id']] = (string) $r['cliente_nombre'];
        }
    }

    $slots = [];
    foreach ($rows as $r) {
        $start = $r['fecha_visita'];
        try {
            $dt = new DateTime($start);
            $dt->modify('+' . (int) $r['duracion_min'] . ' minutes');
            $end = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $end = $start;
        }
        $cid = (int) $r['client_id'];
        $slots[] = [
            'id'           => (int) $r['id'],
            'start'        => $start,
            'end'          => $end,
            'duracion_min' => (int) $r['duracion_min'],
            'estado'       => $r['estado'],
            'cliente'      => $nombres[$cid] ?? ('Cliente #' . $cid),
        ];
    }

    wp_send_json_success(['slots' => $slots]);
}

/* =========================================================================
 * RENDER: bloque "Visitas" dentro de la ficha del cliente
 * ========================================================================= */

/**
 * Renderiza el bloque de visitas en la ficha del cliente.
 * Diseñado para ir DENTRO del formulario principal, pero usa POST a admin-post
 * de forma independiente (los <form> no se anidan).
 */
function crm_render_visitas_box($client_id) {
    $client_id = (int) $client_id;
    if ($client_id <= 0) {
        return;
    }
    $visitas = crm_visitas_list(['client_id' => $client_id, 'orderby' => 'fecha_visita', 'order' => 'DESC', 'limit' => 50]);
    $estados = crm_visitas_estados();
    $sectores_lbl = [
        'energia'            => 'Energía',
        'alarmas'            => 'Alarmas',
        'telecomunicaciones' => 'Telecomunicaciones',
        'seguros'            => 'Seguros',
        'renovables'         => 'Renovables',
    ];
    $is_admin = function_exists('crm_user_is_admin') && crm_user_is_admin();
    $can_create = crm_visita_can_create();
    $current_user_id = get_current_user_id();

    // v1.20.3: detectar visitas programadas asignadas al usuario actual
    // (badge destacado para que el visitador no pase por alto su asignación).
    $mis_visitas_futuras = [];
    $now_ts = current_time('timestamp');
    foreach ($visitas as $v) {
        if ((int) $v['comercial_id'] === (int) $current_user_id
            && $v['estado'] === 'programada'
            && strtotime($v['fecha_visita']) >= $now_ts - 3600) {
            $mis_visitas_futuras[] = $v;
        }
    }
    ?>
    <?php if (!empty($mis_visitas_futuras)): ?>
        <div class="crm-visita-mia-banner" role="status" style="margin-top:16px; padding:14px 16px; background:#fff8e1; border-left:4px solid #f59e0b; border-radius:6px;">
            <strong style="color:#92400e; font-size:15px;">
                Tienes <?php echo count($mis_visitas_futuras); ?>
                visita<?php echo count($mis_visitas_futuras) > 1 ? 's' : ''; ?>
                programada<?php echo count($mis_visitas_futuras) > 1 ? 's' : ''; ?> con este cliente:
            </strong>
            <ul style="margin:6px 0 0 0; padding-left:20px; color:#78350f;">
                <?php foreach ($mis_visitas_futuras as $mv):
                    $mv_fecha = mysql2date(get_option('date_format') . ' H:i', $mv['fecha_visita']);
                ?>
                    <li>
                        <strong><?php echo esc_html($mv_fecha); ?></strong>
                        <?php if (!empty($mv['lugar'])): ?> — <?php echo esc_html($mv['lugar']); ?><?php endif; ?>
                        <?php if (!empty($mv['sector'])): ?> · <?php echo esc_html($sectores_lbl[$mv['sector']] ?? $mv['sector']); ?><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <div class="crm-card crm-visitas-card" style="margin-top:20px;">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
            <h4 style="display:inline-flex; align-items:center; gap:8px; margin:0;">
                <?php echo function_exists('crm_icon') ? crm_icon('calendar', 18) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span>Visitas</span>
                <span class="crm-pill crm-pill--neutral crm-pill--sm"><?php echo count($visitas); ?></span>
            </h4>
            <?php if ($can_create): ?>
                <button type="button" class="crm-btn crm-btn--sm" onclick="document.getElementById('crm-visita-form-<?php echo (int) $client_id; ?>').style.display='block'; this.style.display='none';">
                    + Agendar visita
                </button>
            <?php endif; ?>
        </div>

        <div class="card-body">
            <?php if (empty($visitas)): ?>
                <p style="color:#777; margin:8px 0;">No hay visitas registradas para este cliente.</p>
            <?php else: ?>
                <table class="crm-visitas-table" style="width:100%; border-collapse:collapse; font-size:13.5px;">
                    <thead>
                        <tr style="background:#f5f5f5; text-align:left;">
                            <th style="padding:6px 8px;">Fecha</th>
                            <th style="padding:6px 8px;">Sector</th>
                            <th style="padding:6px 8px;">Lugar</th>
                            <th style="padding:6px 8px;">Estado</th>
                            <?php if ($is_admin): ?><th style="padding:6px 8px;">Comercial</th><?php endif; ?>
                            <th style="padding:6px 8px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visitas as $v):
                        $can = crm_visita_can_manage($v);
                        $com = (int) $v['comercial_id'];
                        $com_user = $com ? get_userdata($com) : null;
                        $fecha_dt = mysql2date(get_option('date_format') . ' H:i', $v['fecha_visita']);
                        $estado_label = $estados[$v['estado']] ?? $v['estado'];
                        $estado_class = 'crm-pill--neutral';
                        if ($v['estado'] === 'programada')  $estado_class = 'crm-pill--accent';
                        if ($v['estado'] === 'realizada')   $estado_class = 'crm-pill--success';
                        if ($v['estado'] === 'cancelada')   $estado_class = 'crm-pill--neutral';
                        if ($v['estado'] === 'no_show')     $estado_class = 'crm-pill--warn';
                        $es_mia = ($com === (int) $current_user_id);
                        $row_style = 'border-top:1px solid #eee;';
                        if ($es_mia && $v['estado'] === 'programada') {
                            $row_style .= ' background:#fff8e1;';
                        }
                    ?>
                        <tr style="<?php echo esc_attr($row_style); ?>"<?php if ($es_mia) echo ' class="crm-visita-mia"'; ?>>
                            <td style="padding:6px 8px;">
                                <?php echo esc_html($fecha_dt); ?>
                                <?php if ($es_mia): ?>
                                    <span style="margin-left:4px; background:#f59e0b; color:#fff; font-size:10px; padding:1px 6px; border-radius:10px; font-weight:600;">Para ti</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px 8px;"><?php echo $v['sector'] ? esc_html($sectores_lbl[$v['sector']] ?? $v['sector']) : '—'; ?></td>
                            <td style="padding:6px 8px;"><?php echo esc_html($v['lugar']); ?></td>
                            <td style="padding:6px 8px;">
                                <span class="crm-pill <?php echo esc_attr($estado_class); ?> crm-pill--sm"><?php echo esc_html($estado_label); ?></span>
                            </td>
                            <?php if ($is_admin): ?>
                                <td style="padding:6px 8px;"><?php echo $com_user ? esc_html($com_user->display_name) : '—'; ?></td>
                            <?php endif; ?>
                            <td style="padding:6px 8px;">
                                <?php if ($can && $v['estado'] === 'programada'): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="realizada">
                                        <button type="submit" class="button button-small" title="Marcar como realizada">✓ Realizada</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="cancelada">
                                        <button type="submit" class="button button-small" onclick="return confirm('¿Cancelar esta visita?');">Cancelar</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($v['estado'] === 'programada' && function_exists('crm_gcal_get_event_url')):
                                    if (!isset($client_row_cache)) { $client_row_cache = []; }
                                    if (!isset($client_row_cache[$client_id])) {
                                        global $wpdb;
                                        $client_row_cache[$client_id] = $wpdb->get_row($wpdb->prepare("SELECT cliente_nombre FROM {$wpdb->prefix}crm_clients WHERE id=%d", $client_id), ARRAY_A);
                                    }
                                    $gcal_url = crm_gcal_get_event_url($v, $client_row_cache[$client_id]['cliente_nombre'] ?? '');
                                ?>
                                    <a href="<?php echo esc_url($gcal_url); ?>" target="_blank" rel="noopener noreferrer"
                                       class="button button-small"
                                       title="Añadir a Google Calendar"
                                       style="display:inline-flex; align-items:center; gap:4px;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                        Google Calendar
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($v['notas'])): ?>
                            <tr style="background:#fafafa;">
                                <td colspan="<?php echo $is_admin ? 6 : 5; ?>" style="padding:4px 12px 8px; color:#555; font-style:italic;">
                                    <?php echo wp_kses_post($v['notas']); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Formulario de alta -->
            <?php if ($can_create): ?>
            <form id="crm-visita-form-<?php echo (int) $client_id; ?>"
                  method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="display:none; margin-top:16px; padding:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                <?php wp_nonce_field('crm_visita_save'); ?>
                <input type="hidden" name="action" value="crm_visita_save">
                <input type="hidden" name="client_id" value="<?php echo (int) $client_id; ?>">

                <h5 style="margin:0 0 10px;">Nueva visita</h5>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <label>
                        <span style="display:block; font-weight:600; font-size:13px;">Fecha y hora</span>
                        <?php
                        // Slots de 15 min; mínimo: ahora redondeado al próximo cuarto de hora
                        $now_ts   = current_time('timestamp');
                        $min_ts   = (int) (ceil($now_ts / 900) * 900);
                        $min_attr = date('Y-m-d\TH:i', $min_ts);
                        ?>
                        <input type="datetime-local" name="fecha_visita" required
                               step="900"
                               min="<?php echo esc_attr($min_attr); ?>"
                               style="width:100%;">
                        <small style="color:#64748b;">Slots de 15 min. No se permite agendar en el pasado.</small>
                    </label>
                    <label>
                        <span style="display:block; font-weight:600; font-size:13px;">Duración (min)</span>
                        <input type="number" name="duracion_min" min="5" max="600" value="60" style="width:100%;">
                    </label>
                    <label>
                        <span style="display:block; font-weight:600; font-size:13px;">Sector (opcional)</span>
                        <select name="sector" style="width:100%;">
                            <option value="">— Cualquiera —</option>
                            <?php foreach ($sectores_lbl as $sk => $sl): ?>
                                <option value="<?php echo esc_attr($sk); ?>"><?php echo esc_html($sl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span style="display:block; font-weight:600; font-size:13px;">Lugar</span>
                        <input type="text" name="lugar" placeholder="Domicilio / oficina / videollamada" style="width:100%;">
                    </label>
                    <?php if ($is_admin): ?>
                        <label style="grid-column:1/-1;">
                            <span style="display:block; font-weight:600; font-size:13px;">Asignar a</span>
                            <?php wp_dropdown_users([
                                'name'             => 'comercial_id',
                                'role__in'         => ['comercial', 'crm_admin', 'administrator', 'visitador'],
                                'selected'         => $current_user_id,
                                'show_option_none' => '— Seleccionar —',
                            ]); ?>
                        </label>
                    <?php elseif (in_array('comercial', (array) wp_get_current_user()->roles, true)): ?>
                        <label style="grid-column:1/-1;">
                            <span style="display:block; font-weight:600; font-size:13px;">Asignar a</span>
                            <select name="comercial_id" style="width:100%;">
                                <option value="<?php echo (int) $current_user_id; ?>">Yo mismo (<?php echo esc_html(wp_get_current_user()->display_name); ?>)</option>
                                <?php
                                $visitadores = get_users([
                                    'role'    => 'visitador',
                                    'orderby' => 'display_name',
                                    'order'   => 'ASC',
                                    'fields'  => ['ID', 'display_name'],
                                ]);
                                foreach ($visitadores as $vu) {
                                    if ((int) $vu->ID === (int) $current_user_id) {
                                        continue;
                                    }
                                    printf(
                                        '<option value="%d">%s (visitador)</option>',
                                        (int) $vu->ID,
                                        esc_html($vu->display_name)
                                    );
                                }
                                ?>
                            </select>
                            <small style="color:#666;">Si asignas a un visitador, se validará su disponibilidad horaria.</small>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="comercial_id" value="<?php echo (int) $current_user_id; ?>">
                    <?php endif; ?>
                    <label style="grid-column:1/-1;">
                        <span style="display:block; font-weight:600; font-size:13px;">Notas</span>
                        <textarea name="notas" rows="3" style="width:100%;"></textarea>
                    </label>

                    <?php
                    $can_force = crm_visita_user_can_force_overlap();
                    ?>
                    <div style="grid-column:1/-1;">
                        <div class="crm-visita-availability"
                             data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                             data-nonce="<?php echo esc_attr(wp_create_nonce('crm_visitas_busy')); ?>"
                             style="margin-top:4px; min-height:0;"></div>
                        <?php if ($can_force): ?>
                            <label style="display:inline-flex; align-items:center; gap:6px; margin-top:8px; font-size:13px;">
                                <input type="checkbox" name="force_overlap" value="1">
                                <span>Forzar (saltar validación de solape)</span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top:12px; display:flex; gap:8px;">
                    <button type="submit" class="crm-btn">Guardar visita</button>
                    <button type="button" class="button" onclick="this.closest('form').style.display='none';">Cancelar</button>
                </div>
            </form>
            <script>
            (function(){
                var formId = 'crm-visita-form-<?php echo (int) $client_id; ?>';
                var form = document.getElementById(formId);
                if (!form || form.dataset.crmAvailabilityWired === '1') return;
                form.dataset.crmAvailabilityWired = '1';

                var box = form.querySelector('.crm-visita-availability');
                if (!box) return;
                var ajaxUrl = box.dataset.ajaxUrl;
                var nonce   = box.dataset.nonce;
                var fechaInput = form.querySelector('input[name="fecha_visita"]');
                var duracInput = form.querySelector('input[name="duracion_min"]');
                var asignSelect = form.querySelector('select[name="comercial_id"]');
                var asignHidden = form.querySelector('input[type="hidden"][name="comercial_id"]');

                function getAsignId(){
                    if (asignSelect) return parseInt(asignSelect.value, 10) || 0;
                    if (asignHidden) return parseInt(asignHidden.value, 10) || 0;
                    return 0;
                }

                function fmt(s){
                    if (!s) return '';
                    return s.replace('T',' ').substring(0,16);
                }

                function render(slots, conflict){
                    if (!slots || !slots.length) {
                        box.innerHTML = '<div style="padding:8px 10px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:4px; font-size:13px; color:#065f46;">Sin visitas del asignado ese día. Disponible.</div>';
                        return;
                    }
                    var html = '';
                    if (conflict) {
                        html += '<div style="padding:8px 10px; background:#fef2f2; border:1px solid #fca5a5; border-radius:4px; font-size:13px; color:#991b1b; margin-bottom:6px;"><strong>Solape detectado</strong> con la franja propuesta.</div>';
                    } else {
                        html += '<div style="padding:8px 10px; background:#fffbeb; border:1px solid #fcd34d; border-radius:4px; font-size:13px; color:#92400e; margin-bottom:6px;">Otras visitas del asignado ese día:</div>';
                    }
                    html += '<ul style="margin:0; padding-left:18px; font-size:12px; color:#374151;">';
                    slots.forEach(function(s){
                        html += '<li>' + fmt(s.start) + ' a ' + s.end.substring(11,16) + ' &mdash; ' + s.cliente + '</li>';
                    });
                    html += '</ul>';
                    box.innerHTML = html;
                }

                function check(){
                    var cid = getAsignId();
                    var fecha = fechaInput ? fechaInput.value : '';
                    var durac = duracInput ? parseInt(duracInput.value, 10) || 60 : 60;
                    if (!cid || !fecha) {
                        box.innerHTML = '';
                        return;
                    }
                    var dia = fecha.substring(0,10);
                    var body = new URLSearchParams();
                    body.append('action', 'crm_visitas_busy');
                    body.append('nonce', nonce);
                    body.append('comercial_id', cid);
                    body.append('desde', dia);
                    body.append('hasta', dia);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r){ return r.json(); })
                        .then(function(j){
                            if (!j || !j.success) { box.innerHTML = ''; return; }
                            var slots = j.data && j.data.slots ? j.data.slots : [];
                            // Detectar conflicto con la franja propuesta
                            var newStart = new Date(fecha);
                            var newEnd = new Date(newStart.getTime() + durac * 60000);
                            var conflict = slots.some(function(s){
                                var sStart = new Date(s.start.replace(' ', 'T'));
                                var sEnd = new Date(s.end.replace(' ', 'T'));
                                return sStart < newEnd && sEnd > newStart;
                            });
                            render(slots, conflict);
                        })
                        .catch(function(){ box.innerHTML = ''; });
                }

                ['change','input','blur'].forEach(function(ev){
                    if (fechaInput) fechaInput.addEventListener(ev, check);
                    if (duracInput) duracInput.addEventListener(ev, check);
                    if (asignSelect) asignSelect.addEventListener('change', check);
                });
            })();
            </script>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
