<?php
/**
 * Shortcode [asignacion_leads_mk]
 * Pantalla de admin con la cola de leads importados desde Google Sheets / Meta
 * que aún no tienen comercial asignado. Permite:
 *  - asignar comercial
 *  - reclasificar como contacto frío
 *  - eliminar el lead
 *  - búsqueda libre (nombre / email / teléfono / campaña)
 *  - filtro por sector de interés inicial (asignado al asignar)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('asignacion_leads_mk', 'crm_shortcode_asignacion_leads_mk');

function crm_shortcode_asignacion_leads_mk() {
    if (!is_user_logged_in() || !current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }

    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $table WHERE origen_lead='lead_mk' AND (user_id IS NULL OR user_id = 0)"
    );

    $comerciales = get_users(['role' => 'comercial', 'fields' => ['ID', 'display_name', 'user_email']]);
    $sectores = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    $sectores_label = [
        'energia' => 'Energía', 'alarmas' => 'Alarmas',
        'telecomunicaciones' => 'Telecomunicaciones',
        'seguros' => 'Seguros', 'renovables' => 'Renovables',
    ];

    $nonce = wp_create_nonce('crm_leads_mk');
    $settings = function_exists('crm_leads_sheets_get_settings') ? crm_leads_sheets_get_settings() : [];

    ob_start();
    ?>
    <div class="crm-leads-mk" data-nonce="<?php echo esc_attr($nonce); ?>">
        <div class="crm-leads-mk-header">
            <h2>Asignación de leads (Marketing)</h2>
            <p class="crm-leads-mk-counter">
                <strong><?php echo (int) $count; ?></strong> leads sin asignar.
                <?php if (!empty($settings['last_sync'])): ?>
                    Última sincronización: <em><?php echo esc_html(date_i18n('d/m/Y H:i', (int) $settings['last_sync'])); ?></em>
                    · <?php echo esc_html($settings['last_status'] ?? ''); ?>
                <?php endif; ?>
            </p>
            <div class="crm-leads-mk-actions">
                <button type="button" class="crm-btn crm-leads-mk-sync">Sincronizar ahora</button>
                <span class="crm-leads-mk-sync-status"></span>
            </div>
        </div>

        <div class="crm-leads-mk-filters">
            <label for="crm-leads-mk-search">Buscar:</label>
            <input type="search" id="crm-leads-mk-search" placeholder="Nombre, email, teléfono o campaña…">
            <label for="crm-leads-mk-sector">Sector inicial:</label>
            <select id="crm-leads-mk-sector">
                <option value="">(sin asignar sector)</option>
                <?php foreach ($sectores as $s): ?>
                    <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($sectores_label[$s]); ?></option>
                <?php endforeach; ?>
            </select>
            <small>El sector se aplica al asignar el lead a un comercial (opcional).</small>
        </div>

        <div class="crm-leads-mk-table-wrap">
            <table class="crm-leads-mk-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Nombre</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th>Campaña</th>
                        <th>Asignar a</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = $wpdb->get_results(
                        "SELECT id, fecha, cliente_nombre, telefono, email_cliente, lead_meta
                         FROM $table
                         WHERE origen_lead='lead_mk' AND (user_id IS NULL OR user_id = 0)
                         ORDER BY id DESC
                         LIMIT 500",
                        ARRAY_A
                    );
                    if (empty($rows)):
                    ?>
                        <tr><td colspan="7" class="crm-leads-mk-empty">No hay leads en cola.</td></tr>
                    <?php else:
                        foreach ($rows as $r):
                            $meta = !empty($r['lead_meta']) ? json_decode($r['lead_meta'], true) : [];
                            $campana = is_array($meta) ? trim(($meta['campaign_name'] ?? '') . ($meta['ad_name'] ? ' · ' . $meta['ad_name'] : '')) : '';
                            $haystack = strtolower(trim(($r['cliente_nombre'] ?: '') . ' ' . ($r['email_cliente'] ?: '') . ' ' . ($r['telefono'] ?: '') . ' ' . $campana));
                    ?>
                        <tr class="crm-leads-mk-row" data-id="<?php echo (int) $r['id']; ?>" data-haystack="<?php echo esc_attr($haystack); ?>">
                            <td><?php echo esc_html(mysql2date('d/m/Y H:i', $r['fecha'])); ?></td>
                            <td><?php echo esc_html($r['cliente_nombre'] ?: '(sin nombre)'); ?></td>
                            <td><?php echo esc_html($r['telefono']); ?></td>
                            <td><?php echo esc_html($r['email_cliente']); ?></td>
                            <td class="campana"><?php echo esc_html($campana); ?></td>
                            <td>
                                <select class="crm-leads-mk-assignee">
                                    <option value="">— Comercial —</option>
                                    <?php foreach ($comerciales as $c): ?>
                                        <option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html($c->display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="crm-leads-mk-actions-cell">
                                <button type="button" class="crm-btn crm-btn-sm crm-leads-mk-assign">Asignar</button>
                                <button type="button" class="crm-btn crm-btn-sm crm-btn-ghost crm-leads-mk-cold">A frío</button>
                                <button type="button" class="crm-btn crm-btn-sm crm-btn-danger crm-leads-mk-delete">Eliminar</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * AJAX endpoints
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_crm_lead_assign', 'crm_lead_assign_ajax');
function crm_lead_assign_ajax() {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    check_ajax_referer('crm_leads_mk', 'nonce');

    $lead_id = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $sector  = isset($_POST['sector'])  ? sanitize_key($_POST['sector'])  : '';

    if (!$lead_id || !$user_id) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    $user = get_userdata($user_id);
    if (!$user || !in_array('comercial', (array) $user->roles, true)) {
        wp_send_json_error(['message' => 'El usuario no es comercial']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $lead_id), ARRAY_A);
    if (!$row) {
        wp_send_json_error(['message' => 'Lead no encontrado']);
    }

    $update = [
        'user_id'         => $user_id,
        'delegado'        => $user->display_name,
        'email_comercial' => $user->user_email,
        'actualizado_en'  => current_time('mysql'),
        'actualizado_por' => get_current_user_id(),
    ];

    // Si el admin marcó un sector inicial, lo añadimos a intereses y al estado_por_sector como 'borrador'
    $sectores_validos = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    if ($sector && in_array($sector, $sectores_validos, true)) {
        $intereses_actuales = (array) maybe_unserialize($row['intereses'] ?? '');
        if (!in_array($sector, $intereses_actuales, true)) {
            $intereses_actuales[] = $sector;
        }
        $update['intereses'] = maybe_serialize(array_values(array_unique($intereses_actuales)));

        $eps = (array) maybe_unserialize($row['estado_por_sector'] ?? '');
        if (empty($eps[$sector])) {
            $eps[$sector] = 'borrador';
        }
        $update['estado_por_sector'] = maybe_serialize($eps);
    }

    $ok = $wpdb->update($table, $update, ['id' => $lead_id]);
    if ($ok === false) {
        wp_send_json_error(['message' => 'Error BD: ' . $wpdb->last_error]);
    }

    if (function_exists('crm_notes_log_assignment')) {
        crm_notes_log_assignment($lead_id, 0, $user_id);
    }
    if (function_exists('crm_notif_assignment_send')) {
        crm_notif_assignment_send($lead_id, $user_id);
    }

    wp_send_json_success(['message' => 'Lead asignado a ' . $user->display_name]);
}

add_action('wp_ajax_crm_lead_to_cold', 'crm_lead_to_cold_ajax');
function crm_lead_to_cold_ajax() {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    check_ajax_referer('crm_leads_mk', 'nonce');

    $lead_id = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
    if (!$lead_id) {
        wp_send_json_error(['message' => 'lead_id requerido']);
    }
    global $wpdb;
    $ok = $wpdb->update(
        $wpdb->prefix . 'crm_clients',
        ['origen_lead' => 'contacto_frio', 'actualizado_en' => current_time('mysql'), 'actualizado_por' => get_current_user_id()],
        ['id' => $lead_id]
    );
    if ($ok === false) {
        wp_send_json_error(['message' => 'Error BD: ' . $wpdb->last_error]);
    }
    if (function_exists('crm_notes_add')) {
        crm_notes_add([
            'client_id'     => $lead_id,
            'tipo'          => 'sistema',
            'texto'         => 'Reclasificado a contacto frío desde la cola de leads MK.',
            'source_action' => 'lead_to_cold',
        ]);
    }
    wp_send_json_success(['message' => 'Lead movido a contacto frío']);
}

add_action('wp_ajax_crm_lead_delete', 'crm_lead_delete_ajax');
function crm_lead_delete_ajax() {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    check_ajax_referer('crm_leads_mk', 'nonce');

    $lead_id = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
    if (!$lead_id) {
        wp_send_json_error(['message' => 'lead_id requerido']);
    }
    global $wpdb;
    $ok = $wpdb->delete($wpdb->prefix . 'crm_clients', ['id' => $lead_id]);
    if ($ok === false) {
        wp_send_json_error(['message' => 'Error BD: ' . $wpdb->last_error]);
    }
    // Borrar notas asociadas
    if (defined('CRM_NOTES_TABLE')) {
        $wpdb->delete($wpdb->prefix . CRM_NOTES_TABLE, ['client_id' => $lead_id]);
    }
    wp_send_json_success(['message' => 'Lead eliminado']);
}

/* -------------------------------------------------------------------------
 * Enqueue del JS/CSS del shortcode
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'crm_leads_mk_enqueue');
function crm_leads_mk_enqueue() {
    if (!is_singular()) {
        return;
    }
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'asignacion_leads_mk')) {
        return;
    }
    wp_enqueue_script(
        'crm-leads-mk',
        CRM_PLUGIN_URL . 'js/crm-leads-mk.js',
        ['jquery'],
        CRM_PLUGIN_VERSION,
        true
    );
    wp_localize_script('crm-leads-mk', 'crmLeadsMK', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'syncNonce' => wp_create_nonce('crm_leads_sheets'),
    ]);
}
