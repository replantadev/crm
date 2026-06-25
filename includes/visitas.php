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
 * Visitadores NO pueden crear; solo gestionan las asignadas por el admin
 * o el comercial responsable del cliente.
 */
function crm_visita_can_create() {
    if (function_exists('crm_user_is_visitador') && crm_user_is_visitador()) {
        return false;
    }
    return is_user_logged_in();
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

    // Si el usuario no es admin, fuerza comercial_id = usuario actual
    if (!function_exists('crm_user_is_admin') || !crm_user_is_admin()) {
        $input['comercial_id'] = get_current_user_id();
    }

    $client_id = (int) ($input['client_id'] ?? 0);

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
    ?>
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
                    ?>
                        <tr style="border-top:1px solid #eee;">
                            <td style="padding:6px 8px;"><?php echo esc_html($fecha_dt); ?></td>
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
                        <input type="datetime-local" name="fecha_visita" required style="width:100%;">
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
                    <?php else: ?>
                        <input type="hidden" name="comercial_id" value="<?php echo (int) $current_user_id; ?>">
                    <?php endif; ?>
                    <label style="grid-column:1/-1;">
                        <span style="display:block; font-weight:600; font-size:13px;">Notas</span>
                        <textarea name="notas" rows="3" style="width:100%;"></textarea>
                    </label>
                </div>

                <div style="margin-top:12px; display:flex; gap:8px;">
                    <button type="submit" class="crm-btn">Guardar visita</button>
                    <button type="button" class="button" onclick="this.closest('form').style.display='none';">Cancelar</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
