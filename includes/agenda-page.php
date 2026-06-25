<?php
/**
 * Página "Mi agenda" — listado de visitas para comerciales y admin.
 *
 * Comerciales: solo sus propias visitas.
 * Admin: todas, con filtro por comercial.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'crm_register_agenda_menu', 20);
function crm_register_agenda_menu() {
    // Página accesible para cualquier usuario con el dashboard del CRM (cap mínima `read` ya basta;
    // restringimos por rol en el render).
    add_menu_page(
        'Mi agenda',
        'Mi agenda',
        'read',
        'crm-mi-agenda',
        'crm_render_agenda_page',
        'dashicons-calendar-alt',
        26
    );
}

/**
 * v1.20.1 — Redirect al entrar al wp-admin para visitadores.
 *
 * Obsoleto en v1.20.2: el bloqueo total de wp-admin a no-administrator
 * (admin-lockdown.php) ya intercepta a visitadores y comerciales antes de
 * que lleguen aquí. Se mantiene el código sin acción para no romper si
 * algún hook externo lo referencia.
 */
add_action('admin_init', 'crm_visitador_redirect_to_agenda');
function crm_visitador_redirect_to_agenda() {
    // No-op desde v1.20.2 — gestionado por crm_block_wpadmin_for_non_admins().
    return;
}

function crm_render_agenda_page() {
    if (!is_user_logged_in()) {
        wp_die('No autorizado.');
    }
    $is_admin = function_exists('crm_user_is_admin') && crm_user_is_admin();
    $is_visitador = function_exists('crm_user_is_visitador') && crm_user_is_visitador();

    // Filtros
    $filtro_estado    = isset($_GET['estado']) ? sanitize_key($_GET['estado']) : '';
    $filtro_desde     = isset($_GET['desde']) ? sanitize_text_field((string) $_GET['desde']) : date('Y-m-d');
    $filtro_hasta     = isset($_GET['hasta']) ? sanitize_text_field((string) $_GET['hasta']) : date('Y-m-d', strtotime('+30 days'));
    $filtro_comercial = $is_admin && isset($_GET['comercial_id']) ? (int) $_GET['comercial_id'] : 0;

    $args = [
        'estado' => $filtro_estado,
        'desde'  => $filtro_desde,
        'hasta'  => $filtro_hasta,
        'limit'  => 500,
        'orderby'=> 'fecha_visita',
        'order'  => 'ASC',
    ];
    if (!$is_admin) {
        $args['comercial_id'] = get_current_user_id();
    } elseif ($filtro_comercial > 0) {
        $args['comercial_id'] = $filtro_comercial;
    }

    $visitas = crm_visitas_list($args);
    $estados = crm_visitas_estados();

    // Aviso si redirigió desde guardar
    $msg = isset($_GET['crm_visita_msg']) ? sanitize_key($_GET['crm_visita_msg']) : '';
    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:10px;">
            <?php echo function_exists('crm_icon') ? crm_icon('calendar', 24) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            Mi agenda
        </h1>

        <?php if ($msg === 'created'): ?>
            <div class="notice notice-success is-dismissible"><p>Visita creada correctamente.</p></div>
        <?php elseif ($msg === 'updated'): ?>
            <div class="notice notice-success is-dismissible"><p>Visita actualizada.</p></div>
        <?php elseif ($msg === 'error'): ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(urldecode((string)($_GET['crm_msg'] ?? 'Error.'))); ?></p></div>
        <?php endif; ?>

        <form method="get" style="margin:18px 0; padding:14px; background:#fff; border:1px solid #ccd0d4; border-radius:4px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <input type="hidden" name="page" value="crm-mi-agenda">

            <label style="display:flex; flex-direction:column;">
                <span style="font-size:12px; font-weight:600;">Desde</span>
                <input type="date" name="desde" value="<?php echo esc_attr($filtro_desde); ?>">
            </label>
            <label style="display:flex; flex-direction:column;">
                <span style="font-size:12px; font-weight:600;">Hasta</span>
                <input type="date" name="hasta" value="<?php echo esc_attr($filtro_hasta); ?>">
            </label>
            <label style="display:flex; flex-direction:column;">
                <span style="font-size:12px; font-weight:600;">Estado</span>
                <select name="estado">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $k => $lbl): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($filtro_estado, $k); ?>><?php echo esc_html($lbl); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($is_admin): ?>
                <label style="display:flex; flex-direction:column;">
                    <span style="font-size:12px; font-weight:600;">Asignado a</span>
                    <?php wp_dropdown_users([
                        'name'             => 'comercial_id',
                        'role__in'         => ['comercial', 'crm_admin', 'administrator', 'visitador'],
                        'selected'         => $filtro_comercial,
                        'show_option_all'  => 'Todos',
                    ]); ?>
                </label>
            <?php endif; ?>
            <button type="submit" class="button button-primary">Filtrar</button>
        </form>

        <?php
        // Métricas rápidas
        $hoy  = crm_visitas_count(array_merge($args, ['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d')]));
        $sem  = crm_visitas_count(array_merge($args, ['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d', strtotime('+7 days'))]));
        $prog = crm_visitas_count(array_merge($args, ['estado' => 'programada']));
        ?>
        <div style="display:flex; gap:14px; margin-bottom:18px; flex-wrap:wrap;">
            <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 18px; min-width:140px;">
                <div style="font-size:12px; color:#666;">Hoy</div>
                <div style="font-size:28px; font-weight:700;"><?php echo (int) $hoy; ?></div>
            </div>
            <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 18px; min-width:140px;">
                <div style="font-size:12px; color:#666;">Próximos 7 días</div>
                <div style="font-size:28px; font-weight:700;"><?php echo (int) $sem; ?></div>
            </div>
            <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 18px; min-width:140px;">
                <div style="font-size:12px; color:#666;">Programadas (rango)</div>
                <div style="font-size:28px; font-weight:700;"><?php echo (int) $prog; ?></div>
            </div>
        </div>

        <?php if (empty($visitas)): ?>
            <p style="padding:18px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">
                No hay visitas que coincidan con los filtros.
            </p>
        <?php else: ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <?php if ($is_visitador): ?>
                            <th>Teléfono</th>
                            <th>Dirección</th>
                        <?php endif; ?>
                        <th>Sector</th>
                        <th>Lugar</th>
                        <th>Estado</th>
                        <?php if ($is_admin): ?><th>Asignado a</th><?php endif; ?>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    global $wpdb;
                    $client_table = $wpdb->prefix . 'crm_clients';
                    foreach ($visitas as $v):
                        // Para visitador necesitamos datos extra del cliente (tel/direccion)
                        $client_fields = $is_visitador
                            ? 'id, cliente_nombre, telefono, direccion, poblacion'
                            : 'id, cliente_nombre';
                        $client = $wpdb->get_row($wpdb->prepare("SELECT $client_fields FROM $client_table WHERE id = %d", (int) $v['client_id']), ARRAY_A);
                        $com_user = $v['comercial_id'] ? get_userdata((int) $v['comercial_id']) : null;
                        $fecha_dt = mysql2date(get_option('date_format') . ' H:i', $v['fecha_visita']);
                        $can = crm_visita_can_manage($v);
                        $estado_label = $estados[$v['estado']] ?? $v['estado'];
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($fecha_dt); ?></strong></td>
                            <td>
                                <?php if ($client && !$is_visitador): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=crm-dashboard&accion=editar&id=' . (int) $client['id'])); ?>">
                                        <?php echo esc_html($client['cliente_nombre']); ?>
                                    </a>
                                <?php elseif ($client): ?>
                                    <?php echo esc_html($client['cliente_nombre']); ?>
                                <?php else: ?>
                                    <em>Cliente #<?php echo (int) $v['client_id']; ?></em>
                                <?php endif; ?>
                            </td>
                            <?php if ($is_visitador): ?>
                                <td>
                                    <?php if ($client && !empty($client['telefono'])): ?>
                                        <a href="tel:<?php echo esc_attr($client['telefono']); ?>"><?php echo esc_html($client['telefono']); ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $addr_parts = [];
                                    if ($client) {
                                        if (!empty($client['direccion'])) $addr_parts[] = $client['direccion'];
                                        if (!empty($client['poblacion'])) $addr_parts[] = $client['poblacion'];
                                    }
                                    $addr = implode(', ', $addr_parts);
                                    if ($addr !== ''):
                                    ?>
                                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo rawurlencode($addr); ?>" target="_blank" rel="noopener"><?php echo esc_html($addr); ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo $v['sector'] ? esc_html(ucfirst($v['sector'])) : '—'; ?></td>
                            <td><?php echo esc_html($v['lugar']); ?></td>
                            <td><?php echo esc_html($estado_label); ?></td>
                            <?php if ($is_admin): ?>
                                <td><?php echo $com_user ? esc_html($com_user->display_name) : '—'; ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($can && $v['estado'] === 'programada'): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="realizada">
                                        <button type="submit" class="button button-small">✓</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="no_show">
                                        <button type="submit" class="button button-small" title="No se presentó">!</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="cancelada">
                                        <button type="submit" class="button button-small" onclick="return confirm('¿Cancelar?');">✕</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
