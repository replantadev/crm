<?php
/**
 * Página de administración del CRM (wp-admin).
 *
 * - Menú principal "CRM" con submenús:
 *     · Dashboard      (resumen + accesos rápidos)
 *     · Logs           (visor con filtros, paginación y exportación CSV)
 *     · Actualizaciones (versión instalada, último check, botón "Buscar ahora")
 *     · Ajustes        (retención de logs, páginas de login)
 *
 * Capacidad requerida: `crm_admin`.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Registro de menús
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'crm_register_admin_menu');
function crm_register_admin_menu() {
    $cap = 'crm_admin';

    add_menu_page(
        'CRM Energitel',
        'CRM',
        $cap,
        'crm-dashboard',
        'crm_admin_render_dashboard',
        'dashicons-businessperson',
        26
    );

    add_submenu_page('crm-dashboard', 'Dashboard', 'Dashboard', $cap, 'crm-dashboard', 'crm_admin_render_dashboard');
    add_submenu_page('crm-dashboard', 'Logs', 'Logs', $cap, 'crm-logs', 'crm_admin_render_logs');
    add_submenu_page('crm-dashboard', 'Actualizaciones', 'Actualizaciones', $cap, 'crm-updates', 'crm_admin_render_updates');
    add_submenu_page('crm-dashboard', 'Leads MK', 'Leads MK', $cap, 'crm-leads-mk', 'crm_admin_render_leads_mk');
    add_submenu_page('crm-dashboard', 'Ajustes', 'Ajustes', $cap, 'crm-settings', 'crm_admin_render_settings');
}

/**
 * Registra los ajustes (Settings API).
 */
add_action('admin_init', 'crm_register_admin_settings');
function crm_register_admin_settings() {
    register_setting('crm_settings', 'crm_logs_retention_days', [
        'type'              => 'integer',
        'default'           => 90,
        'sanitize_callback' => function ($v) { return max(7, (int) $v); },
    ]);
    register_setting('crm_settings', 'crm_logs_retention_months', [
        'type'              => 'integer',
        'default'           => 6,
        'sanitize_callback' => function ($v) { return max(1, (int) $v); },
    ]);
    register_setting('crm_settings', 'crm_login_page_id', [
        'type'              => 'integer',
        'default'           => 2,
        'sanitize_callback' => function ($v) { return max(0, (int) $v); },
    ]);
    register_setting('crm_settings', 'crm_post_login_page_id', [
        'type'              => 'integer',
        'default'           => 30,
        'sanitize_callback' => function ($v) { return max(0, (int) $v); },
    ]);
}

/* ---------------------------------------------------------------------------
 * Helpers de UI
 * ------------------------------------------------------------------------- */

function crm_admin_page_header($title) {
    echo '<div class="wrap crm-admin-wrap">';
    echo '<h1>' . esc_html($title) . '</h1>';
    crm_admin_render_nav();
}

function crm_admin_page_footer() {
    echo '</div>';
}

function crm_admin_render_nav() {
    $current = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
    $tabs = [
        'crm-dashboard' => 'Dashboard',
        'crm-logs'      => 'Logs',
        'crm-updates'   => 'Actualizaciones',
        'crm-leads-mk'  => 'Leads MK',
        'crm-settings'  => 'Ajustes',
    ];
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $active = $current === $slug ? ' nav-tab-active' : '';
        $url    = esc_url(admin_url('admin.php?page=' . $slug));
        echo '<a class="nav-tab' . $active . '" href="' . $url . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
}

/* ---------------------------------------------------------------------------
 * Dashboard
 * ------------------------------------------------------------------------- */

function crm_admin_render_dashboard() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    global $wpdb;

    $clients_table = $wpdb->prefix . 'crm_clients';
    $total_clients = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$clients_table`");
    $months        = crm_get_available_log_months();
    $current_month = current_time('Y_m');
    $level_counts  = crm_logs_count_by_level([$current_month]);

    $version          = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '?';
    $last_update_at   = (int) get_option('crm_last_update_at', 0);
    $last_check       = (int) get_option('crm_last_update_check', 0);
    $retention_days   = (int) get_option('crm_logs_retention_days', 90);
    $retention_months = (int) get_option('crm_logs_retention_months', 6);

    crm_admin_page_header('CRM · Dashboard');
    ?>
    <div class="crm-grid">
        <div class="crm-card">
            <h2>Clientes</h2>
            <p class="crm-metric"><?php echo number_format_i18n($total_clients); ?></p>
            <p class="description">Total registrados en el CRM</p>
        </div>
        <div class="crm-card">
            <h2>Versión</h2>
            <p class="crm-metric"><?php echo esc_html($version); ?></p>
            <p class="description">
                <?php if ($last_update_at): ?>
                    Última actualización: <?php echo esc_html(date_i18n('d/m/Y H:i', $last_update_at)); ?>
                <?php else: ?>
                    Sin actualizaciones registradas
                <?php endif; ?>
            </p>
        </div>
        <div class="crm-card">
            <h2>Logs</h2>
            <p class="crm-metric"><?php echo count($months); ?> meses</p>
            <p class="description">
                Retención: <?php echo (int) $retention_days; ?> días · <?php echo (int) $retention_months; ?> meses
            </p>
        </div>
        <div class="crm-card">
            <h2>Errores este mes</h2>
            <p class="crm-metric">
                <?php echo (int) (($level_counts['error'] ?? 0) + ($level_counts['critical'] ?? 0)); ?>
            </p>
            <p class="description">
                Warnings: <?php echo (int) ($level_counts['warning'] ?? 0); ?> ·
                Info: <?php echo (int) ($level_counts['info'] ?? 0); ?>
            </p>
        </div>
    </div>

    <h2>Accesos rápidos</h2>
    <p>
        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=crm-logs')); ?>">Ver logs</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-updates')); ?>">Comprobar actualizaciones</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-settings')); ?>">Ajustes</a>
    </p>

    <h2>Niveles del mes actual</h2>
    <table class="widefat striped" style="max-width:480px">
        <thead><tr><th>Nivel</th><th>Entradas</th></tr></thead>
        <tbody>
            <?php foreach (crm_log_levels() as $level): ?>
                <tr>
                    <td><span class="crm-level crm-level-<?php echo esc_attr($level); ?>"><?php echo esc_html(ucfirst($level)); ?></span></td>
                    <td><?php echo (int) ($level_counts[$level] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    crm_admin_page_footer();
}

/* ---------------------------------------------------------------------------
 * Logs
 * ------------------------------------------------------------------------- */

function crm_admin_render_logs() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    $months_available = crm_get_available_log_months();
    $month_keys       = array_map(function ($m) { return $m['value']; }, $months_available);

    // Construir filtros desde GET (todo lectura, sin efectos secundarios).
    $selected_month = isset($_GET['month']) ? sanitize_text_field((string) $_GET['month']) : current_time('Y_m');
    if (!in_array($selected_month, $month_keys, true)) {
        $selected_month = !empty($month_keys) ? $month_keys[0] : current_time('Y_m');
    }
    $months_for_query = ($selected_month === '__all') ? $month_keys : [$selected_month];

    $filters = [
        'months'    => $months_for_query,
        'search'    => isset($_GET['search']) ? sanitize_text_field((string) $_GET['search']) : '',
        'action'    => isset($_GET['action_type']) ? sanitize_text_field((string) $_GET['action_type']) : '',
        'level'     => isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '',
        'user_id'   => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
        'client_id' => isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0,
        'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
        'date_to'   => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
        'page'      => isset($_GET['paged']) ? (int) $_GET['paged'] : 1,
        'per_page'  => isset($_GET['per_page']) ? (int) $_GET['per_page'] : 50,
    ];

    $result  = crm_logs_query($filters);
    $actions = crm_logs_distinct_actions($months_for_query);

    crm_admin_page_header('CRM · Logs');
    ?>
    <form method="get" class="crm-logs-filters">
        <input type="hidden" name="page" value="crm-logs">
        <p>
            <label>Mes:
                <select name="month">
                    <option value="__all"<?php selected($selected_month, '__all'); ?>>Todos los meses</option>
                    <?php foreach ($months_available as $m): ?>
                        <option value="<?php echo esc_attr($m['value']); ?>" <?php selected($selected_month, $m['value']); ?>>
                            <?php echo esc_html($m['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Acción:
                <select name="action_type">
                    <option value="">Todas</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?php echo esc_attr($a); ?>" <?php selected($filters['action'], $a); ?>>
                            <?php echo esc_html(function_exists('crm_get_action_label') ? crm_get_action_label($a) : $a); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Nivel:
                <select name="level">
                    <option value="">Cualquiera</option>
                    <?php foreach (crm_log_levels() as $lvl): ?>
                        <option value="<?php echo esc_attr($lvl); ?>" <?php selected($filters['level'], $lvl); ?>>
                            <?php echo esc_html(ucfirst($lvl)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Usuario ID:
                <input type="number" min="0" name="user_id" value="<?php echo (int) $filters['user_id']; ?>">
            </label>
            <label>Cliente ID:
                <input type="number" min="0" name="client_id" value="<?php echo (int) $filters['client_id']; ?>">
            </label>
            <label>Desde:
                <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
            </label>
            <label>Hasta:
                <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
            </label>
            <label>Por página:
                <select name="per_page">
                    <?php foreach ([25, 50, 100, 200] as $n): ?>
                        <option value="<?php echo $n; ?>" <?php selected($filters['per_page'], $n); ?>><?php echo $n; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Búsqueda:
                <input type="search" name="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Texto, usuario o acción" style="width:260px">
            </label>
            <button class="button button-primary">Filtrar</button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-logs')); ?>">Limpiar</a>

            <?php
            $export_url = wp_nonce_url(
                add_query_arg(array_merge(['action' => 'crm_export_logs_csv'], array_intersect_key($_GET, array_flip(['month','action_type','level','user_id','client_id','date_from','date_to','search']))), admin_url('admin-post.php')),
                'crm_export_logs_csv'
            );
            ?>
            <a class="button" href="<?php echo esc_url($export_url); ?>">Exportar CSV</a>
        </p>
    </form>

    <p class="description">
        Resultados: <strong><?php echo number_format_i18n($result['total']); ?></strong>
        · Página <?php echo (int) $result['page']; ?> de <?php echo max(1, (int) $result['pages']); ?>
    </p>

    <table class="widefat striped crm-logs-table">
        <thead>
            <tr>
                <th style="width:140px">Fecha</th>
                <th style="width:80px">Nivel</th>
                <th>Usuario</th>
                <th>Acción</th>
                <th>Detalles</th>
                <th style="width:80px">Cliente</th>
                <th style="width:120px">IP</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['rows'])): ?>
            <tr><td colspan="7"><em>Sin entradas para los filtros aplicados.</em></td></tr>
        <?php else: foreach ($result['rows'] as $row):
            $level = crm_normalize_log_level($row['level'] ?? 'info');
        ?>
            <tr>
                <td><?php echo esc_html(mysql2date('d/m/Y H:i:s', $row['created_at'])); ?></td>
                <td><span class="crm-level crm-level-<?php echo esc_attr($level); ?>"><?php echo esc_html(ucfirst($level)); ?></span></td>
                <td><?php echo esc_html($row['user_name'] ?: '—'); ?><br><small>#<?php echo (int) $row['user_id']; ?></small></td>
                <td><code><?php echo esc_html($row['action_type']); ?></code></td>
                <td>
                    <?php echo esc_html($row['details']); ?>
                    <?php if (!empty($row['context'])): ?>
                        <details class="crm-log-ctx">
                            <summary>contexto</summary>
                            <pre><?php echo esc_html($row['context']); ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
                <td><?php echo $row['client_id'] ? '#' . (int) $row['client_id'] : '—'; ?></td>
                <td><code><?php echo esc_html($row['ip_address']); ?></code></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php
    // Paginación
    if ($result['pages'] > 1) {
        $base = remove_query_arg('paged');
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%', $base),
            'format'    => '',
            'current'   => (int) $result['page'],
            'total'     => (int) $result['pages'],
            'prev_text' => '«',
            'next_text' => '»',
        ]);
        echo '</div></div>';
    }

    crm_admin_page_footer();
}

/**
 * Endpoint para exportar logs CSV vía admin-post.
 */
add_action('admin_post_crm_export_logs_csv', function () {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_export_logs_csv');

    $month_keys = array_map(function ($m) { return $m['value']; }, crm_get_available_log_months());
    $selected   = isset($_GET['month']) ? sanitize_text_field((string) $_GET['month']) : current_time('Y_m');
    $months     = ($selected === '__all') ? $month_keys : [$selected];

    crm_logs_export_csv([
        'months'    => $months,
        'search'    => isset($_GET['search']) ? sanitize_text_field((string) $_GET['search']) : '',
        'action'    => isset($_GET['action_type']) ? sanitize_text_field((string) $_GET['action_type']) : '',
        'level'     => isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '',
        'user_id'   => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
        'client_id' => isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0,
        'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
        'date_to'   => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
    ]);
});

/* ---------------------------------------------------------------------------
 * Actualizaciones
 * ------------------------------------------------------------------------- */

function crm_admin_render_updates() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    $version       = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '?';
    $last_check    = (int) get_option('crm_last_update_check', 0);
    $last_update   = (int) get_option('crm_last_update_at', 0);
    $nonce         = wp_create_nonce('crm_admin_actions');
    $github_set    = defined('CRM_GITHUB_TOKEN') && CRM_GITHUB_TOKEN ? 'configurado' : 'no configurado';
    $repo          = apply_filters('crm_update_repo_url', 'https://github.com/replantadev/crm/');
    $branch        = apply_filters('crm_update_branch', 'master');

    crm_admin_page_header('CRM · Actualizaciones');
    ?>
    <table class="form-table">
        <tr><th>Versión instalada</th><td><code><?php echo esc_html($version); ?></code></td></tr>
        <tr><th>Repositorio</th><td><a href="<?php echo esc_url($repo); ?>" target="_blank" rel="noopener"><?php echo esc_html($repo); ?></a></td></tr>
        <tr><th>Rama</th><td><code><?php echo esc_html($branch); ?></code></td></tr>
        <tr><th>Token GitHub</th><td><?php echo esc_html($github_set); ?> <span class="description">(para repos privados, define <code>CRM_GITHUB_TOKEN</code> en <code>wp-config.php</code>)</span></td></tr>
        <tr><th>Última comprobación</th><td><?php echo $last_check ? esc_html(date_i18n('d/m/Y H:i', $last_check)) : '—'; ?></td></tr>
        <tr><th>Última actualización</th><td><?php echo $last_update ? esc_html(date_i18n('d/m/Y H:i', $last_update)) : '—'; ?></td></tr>
    </table>

    <p>
        <button id="crm-check-updates" class="button button-primary">Buscar actualizaciones ahora</button>
        <a class="button" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Ir a Plugins</a>
        <span id="crm-check-result" class="description" style="margin-left:12px"></span>
    </p>

    <script>
    (function(){
        var btn = document.getElementById('crm-check-updates');
        var msg = document.getElementById('crm-check-result');
        if (!btn) return;
        btn.addEventListener('click', function(){
            btn.disabled = true;
            msg.textContent = 'Comprobando…';
            var body = new URLSearchParams();
            body.append('action', 'crm_check_updates');
            body.append('nonce', '<?php echo esc_js($nonce); ?>');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body: body.toString()})
                .then(function(r){ return r.json(); })
                .then(function(j){
                    btn.disabled = false;
                    msg.textContent = (j && j.data && j.data.message) ? j.data.message : 'Sin respuesta';
                })
                .catch(function(){ btn.disabled = false; msg.textContent = 'Error de red'; });
        });
    })();
    </script>
    <?php
    crm_admin_page_footer();
}

/* ---------------------------------------------------------------------------
 * Ajustes
 * ------------------------------------------------------------------------- */

function crm_admin_render_settings() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    crm_admin_page_header('CRM · Ajustes');
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('crm_settings'); ?>
        <table class="form-table">
            <tr>
                <th><label for="crm_logs_retention_days">Retención de logs (días)</label></th>
                <td>
                    <input type="number" min="7" id="crm_logs_retention_days" name="crm_logs_retention_days" value="<?php echo esc_attr((int) get_option('crm_logs_retention_days', 90)); ?>">
                    <p class="description">Filas más antiguas se eliminan diariamente. Mínimo 7.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_logs_retention_months">Retención de tablas mensuales</label></th>
                <td>
                    <input type="number" min="1" id="crm_logs_retention_months" name="crm_logs_retention_months" value="<?php echo esc_attr((int) get_option('crm_logs_retention_months', 6)); ?>">
                    <p class="description">Las tablas mensuales fuera de esta ventana se eliminan completamente (DROP).</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_login_page_id">Página de login (ID)</label></th>
                <td><input type="number" min="0" id="crm_login_page_id" name="crm_login_page_id" value="<?php echo esc_attr((int) get_option('crm_login_page_id', 2)); ?>"></td>
            </tr>
            <tr>
                <th><label for="crm_post_login_page_id">Página tras login (ID)</label></th>
                <td><input type="number" min="0" id="crm_post_login_page_id" name="crm_post_login_page_id" value="<?php echo esc_attr((int) get_option('crm_post_login_page_id', 30)); ?>"></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <h2>Mantenimiento de logs</h2>
    <p>Próxima ejecución programada:
        <?php
        $ts = wp_next_scheduled('crm_logs_daily_maintenance');
        echo $ts ? '<code>' . esc_html(date_i18n('d/m/Y H:i', $ts)) . '</code>' : '<em>no programada</em>';
        ?>
    </p>
    <p>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_run_logs_maintenance'), 'crm_run_logs_maintenance')); ?>">Ejecutar mantenimiento ahora</a>
    </p>
    <?php
    crm_admin_page_footer();
}

add_action('admin_post_crm_run_logs_maintenance', function () {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_run_logs_maintenance');
    crm_logs_run_maintenance();
    wp_safe_redirect(add_query_arg(['page' => 'crm-settings', 'maintenance' => 'done'], admin_url('admin.php')));
    exit;
});

/* ---------------------------------------------------------------------------
 * Estilos inline (sólo en páginas del plugin)
 * ------------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', function ($hook) {
    if (!is_string($hook) || strpos($hook, 'crm-') === false) {
        return;
    }
    $css = <<<CSS
    .crm-admin-wrap .crm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:18px 0}
    .crm-admin-wrap .crm-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px 16px;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
    .crm-admin-wrap .crm-card h2{font-size:14px;margin:0 0 6px;color:#1d2327;text-transform:uppercase;letter-spacing:.04em}
    .crm-admin-wrap .crm-card .crm-metric{font-size:28px;font-weight:600;margin:4px 0;color:#2271b1}
    .crm-admin-wrap .crm-logs-filters{background:#fff;padding:12px 14px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:12px}
    .crm-admin-wrap .crm-logs-filters label{margin-right:14px;display:inline-flex;align-items:center;gap:6px}
    .crm-admin-wrap .crm-logs-table td{vertical-align:top}
    .crm-admin-wrap .crm-log-ctx{margin-top:6px}
    .crm-admin-wrap .crm-log-ctx pre{background:#f6f7f7;padding:8px;border-radius:4px;overflow:auto;max-height:240px;font-size:11px}
    .crm-admin-wrap .crm-level{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
    .crm-admin-wrap .crm-level-debug{background:#eef;color:#3b3b8f}
    .crm-admin-wrap .crm-level-info{background:#e7f5ff;color:#0a558c}
    .crm-admin-wrap .crm-level-notice{background:#e6fcf5;color:#087f5b}
    .crm-admin-wrap .crm-level-warning{background:#fff4e6;color:#b45309}
    .crm-admin-wrap .crm-level-error{background:#ffe3e3;color:#b32424}
    .crm-admin-wrap .crm-level-critical{background:#b32424;color:#fff}
CSS;
    wp_register_style('crm-admin-inline', false);
    wp_enqueue_style('crm-admin-inline');
    wp_add_inline_style('crm-admin-inline', $css);
});

/* ---------------------------------------------------------------------------
 * Leads MK (Google Sheets sync + Notificaciones)
 * ------------------------------------------------------------------------- */

add_action('admin_post_crm_leads_mk_save', 'crm_admin_leads_mk_save');
function crm_admin_leads_mk_save() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_leads_mk_save');

    if (function_exists('crm_leads_sheets_save_settings')) {
        $sa_raw = isset($_POST['sa_json']) ? trim(wp_unslash($_POST['sa_json'])) : '';
        $patch = [
            'enabled'        => !empty($_POST['enabled']),
            'spreadsheet_id' => isset($_POST['spreadsheet_id']) ? sanitize_text_field($_POST['spreadsheet_id']) : '',
            'range'          => isset($_POST['range']) ? sanitize_text_field($_POST['range']) : 'A:Z',
        ];
        // Solo actualizar sa_json si el textarea trae JSON nuevo (no vacío y parseable)
        if ($sa_raw !== '' && $sa_raw !== '****') {
            $decoded = json_decode($sa_raw, true);
            if (is_array($decoded) && !empty($decoded['client_email']) && !empty($decoded['private_key'])) {
                $patch['sa_json'] = $sa_raw;
            }
        }
        crm_leads_sheets_save_settings($patch);
    }

    if (function_exists('crm_notif_save_settings')) {
        crm_notif_save_settings([
            'assignment_enabled' => !empty($_POST['notif_assignment']),
            // El remitente lo aporta WordPress (o WP Mail SMTP si está instalado). No se sobrescribe desde el CRM.
            'from_name'          => '',
            'from_email'         => '',
        ]);
    }

    wp_safe_redirect(add_query_arg(['page' => 'crm-leads-mk', 'saved' => 1], admin_url('admin.php')));
    exit;
}

function crm_admin_render_leads_mk() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }
    $sheets = function_exists('crm_leads_sheets_get_settings') ? crm_leads_sheets_get_settings() : [];
    $notif  = function_exists('crm_notif_get_settings') ? crm_notif_get_settings() : [];

    crm_admin_page_header('CRM · Leads MK / Sheets');

    if (!empty($_GET['saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>';
    }
    if (!empty($_GET['sync'])) {
        $sync_msg = sanitize_text_field(wp_unslash($_GET['sync']));
        $kind = strpos($sync_msg, 'error') === 0 ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($kind) . ' is-dismissible"><p>Sync: ' . esc_html($sync_msg) . '</p></div>';
    }

    // Botón para forzar sync manual desde wp-admin
    $sync_action = wp_nonce_url(admin_url('admin-post.php?action=crm_leads_mk_run_sync'), 'crm_leads_mk_run_sync');
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="crm_leads_mk_save">
        <?php wp_nonce_field('crm_leads_mk_save'); ?>

        <h2>Google Sheets — origen de leads</h2>
        <table class="form-table">
            <tr>
                <th><label for="enabled">Activo</label></th>
                <td>
                    <label><input type="checkbox" name="enabled" id="enabled" value="1" <?php checked(!empty($sheets['enabled'])); ?>>
                        Sincronizar automáticamente cada hora</label>
                </td>
            </tr>
            <tr>
                <th><label for="spreadsheet_id">Spreadsheet ID</label></th>
                <td>
                    <input type="text" class="regular-text" name="spreadsheet_id" id="spreadsheet_id"
                           value="<?php echo esc_attr($sheets['spreadsheet_id'] ?? ''); ?>"
                           placeholder="1mdhsmagqUsyzN9wJMkkm4TK2ua3sOARRVirQ0FMO2ZA">
                    <p class="description">ID que aparece en la URL del Sheet: <code>docs.google.com/spreadsheets/d/<strong>{ID}</strong>/edit</code></p>
                </td>
            </tr>
            <tr>
                <th><label for="range">Rango</label></th>
                <td>
                    <input type="text" name="range" id="range" value="<?php echo esc_attr($sheets['range'] ?? 'A:Z'); ?>" placeholder="Hoja1!A:Z">
                </td>
            </tr>
            <tr>
                <th><label for="sa_json">Service Account JSON</label></th>
                <td>
                    <textarea name="sa_json" id="sa_json" rows="8" class="large-text code"
                              placeholder='{ "type": "service_account", "project_id": "...", "client_email": "...", "private_key": "-----BEGIN PRIVATE KEY-----..." }'><?php echo !empty($sheets['sa_json']) ? '****' : ''; ?></textarea>
                    <p class="description">
                        Pega el JSON completo de la cuenta de servicio con permisos de lectura del Sheet.
                        Se almacena <strong>cifrado con <code>AUTH_KEY</code></strong>. Deja <code>****</code> para no modificarlo.
                    </p>
                    <details style="margin-top:6px;">
                        <summary style="cursor:pointer;font-weight:600;">¿Cómo obtener el Service Account JSON?</summary>
                        <ol style="margin-top:8px;line-height:1.5;">
                            <li>Entra en <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> con la cuenta del cliente (o crea un proyecto nuevo).</li>
                            <li>Ve a <em>APIs &amp; Services → Library</em> y activa <strong>Google Sheets API</strong>.</li>
                            <li>Ve a <em>IAM &amp; Admin → Service Accounts → Create service account</em>. Nombre: por ejemplo <code>crm-sheets-reader</code>. No hace falta darle rol de proyecto.</li>
                            <li>Abre la cuenta de servicio creada → pestaña <em>Keys</em> → <em>Add Key → Create new key → JSON</em>. Se descarga un fichero <code>.json</code>.</li>
                            <li>Copia el correo <code>client_email</code> del JSON (algo como <code>crm-sheets-reader@&lt;proyecto&gt;.iam.gserviceaccount.com</code>) y <strong>compártele el Google Sheet en modo Lector</strong> desde el botón <em>Compartir</em> de Sheets.</li>
                            <li>Pega aquí el <strong>contenido completo del JSON</strong> y guarda.</li>
                        </ol>
                        <p style="margin-top:6px;"><em>El JSON contiene una clave privada: trátalo como un secreto. El plugin lo guarda cifrado con AES-256-CBC + AUTH_KEY.</em></p>
                    </details>
                </td>
            </tr>
            <tr>
                <th>Última sincronización</th>
                <td>
                    <?php if (!empty($sheets['last_sync'])): ?>
                        <code><?php echo esc_html(date_i18n('d/m/Y H:i', (int) $sheets['last_sync'])); ?></code> · <?php echo esc_html($sheets['last_status'] ?? ''); ?>
                    <?php else: ?>
                        <em>Nunca ejecutada</em>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h2>Notificaciones por email</h2>
        <table class="form-table">
            <tr>
                <th><label for="notif_assignment">Asignación de cliente</label></th>
                <td>
                    <label><input type="checkbox" name="notif_assignment" id="notif_assignment" value="1" <?php checked(!empty($notif['assignment_enabled'])); ?>>
                        Avisar al comercial cuando se le asigne un cliente o lead</label>
                    <p class="description">
                        Los correos se envían con el sistema de email del propio sitio: si tienes instalado
                        <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener">WP Mail SMTP</a>
                        (u otro plugin equivalente) los enviará por su SMTP; en caso contrario se usa la función nativa
                        <code>wp_mail()</code> de WordPress. No es necesario configurar remitente aquí: se respeta el del sitio.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button('Guardar ajustes'); ?>
    </form>

    <h2>Sincronización manual</h2>
    <p>
        <a class="button button-primary" href="<?php echo esc_url($sync_action); ?>">Sincronizar ahora</a>
    </p>
    <p>
        <a class="button" href="<?php echo esc_url(home_url('/asignacion-leads-mk/')); ?>" target="_blank">Abrir cola de asignación (frontend)</a>
        — recuerda crear una página con el shortcode <code>[asignacion_leads_mk]</code>.
    </p>
    <?php
    crm_admin_page_footer();
}

add_action('admin_post_crm_leads_mk_run_sync', 'crm_admin_leads_mk_run_sync_post');
function crm_admin_leads_mk_run_sync_post() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_leads_mk_run_sync');
    $msg = 'ok';
    if (function_exists('crm_leads_sheets_run_sync')) {
        $res = crm_leads_sheets_run_sync(false);
        if (is_wp_error($res)) {
            $msg = 'error: ' . $res->get_error_message();
        } else {
            $msg = sprintf('ok: %d nuevos · %d dup · %d omitidos', $res['inserted'] ?? 0, $res['dupes'] ?? 0, $res['skipped'] ?? 0);
        }
    } else {
        $msg = 'error: módulo sheets no cargado';
    }
    wp_safe_redirect(add_query_arg(['page' => 'crm-leads-mk', 'sync' => rawurlencode($msg)], admin_url('admin.php')));
    exit;
}

