<?php
/**
 * Sistema de logging del CRM.
 *
 * - Tablas mensuales `wp_crm_activity_log_YYYY_MM` con índices.
 * - Soporta `level` (debug|info|notice|warning|error|critical) y `context` JSON.
 * - Auto-migración del esquema vía dbDelta cacheado por versión.
 * - Cron diario `crm_logs_daily_maintenance` para purgar y DROPear meses antiguos.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CRM_LOG_SCHEMA_VERSION')) {
    define('CRM_LOG_SCHEMA_VERSION', '2');
}

if (!defined('CRM_LOG_LEVELS')) {
    // Niveles ordenados por severidad ascendente.
    define('CRM_LOG_LEVELS', 'debug,info,notice,warning,error,critical');
}

/**
 * Devuelve los niveles permitidos.
 *
 * @return string[]
 */
function crm_log_levels() {
    return explode(',', CRM_LOG_LEVELS);
}

/**
 * Normaliza un nivel; si es inválido, devuelve 'info'.
 */
function crm_normalize_log_level($level) {
    $level = is_string($level) ? strtolower(trim($level)) : 'info';
    return in_array($level, crm_log_levels(), true) ? $level : 'info';
}

/**
 * Crea o actualiza el esquema de la tabla mensual con cache por versión.
 *
 * Se ejecuta como mucho una vez por request y por mes.
 */
function crm_ensure_log_table($year_month) {
    static $checked = [];
    if (isset($checked[$year_month])) {
        return;
    }
    $checked[$year_month] = true;

    $option_key = 'crm_log_schema_' . $year_month;
    if (get_option($option_key) === CRM_LOG_SCHEMA_VERSION) {
        return;
    }

    global $wpdb;
    $table_name      = $wpdb->prefix . 'crm_activity_log_' . $year_month;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NOT NULL DEFAULT 0,
        user_name VARCHAR(255) NOT NULL DEFAULT '',
        action_type VARCHAR(100) NOT NULL,
        level VARCHAR(10) NOT NULL DEFAULT 'info',
        details TEXT NOT NULL,
        context LONGTEXT NULL,
        client_id BIGINT(20) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        ip_address VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY action_type (action_type),
        KEY client_id (client_id),
        KEY created_at (created_at),
        KEY level (level)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option($option_key, CRM_LOG_SCHEMA_VERSION, false);
}

/**
 * Para compatibilidad con la firma antigua: `crm_create_monthly_log_table`.
 *
 * @deprecated Usar `crm_ensure_log_table`.
 */
if (!function_exists('crm_create_monthly_log_table')) {
    function crm_create_monthly_log_table($year_month) {
        crm_ensure_log_table($year_month);
    }
}

/**
 * Detecta la IP real del cliente respetando proxies de confianza.
 */
function crm_get_request_ip() {
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        $candidates[] = $first;
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }
    foreach ($candidates as $ip) {
        $ip = trim((string) $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Inserta una entrada en el log mensual.
 *
 * Firma backward-compatible: los nuevos parámetros `$level` y `$context` van al final.
 *
 * @param string      $action_type Identificador corto de la acción.
 * @param string      $details     Descripción legible.
 * @param int|null    $client_id   ID del cliente (opcional).
 * @param int|null    $user_id     ID del usuario (opcional; usa el actual si null).
 * @param string      $level       Nivel: debug|info|notice|warning|error|critical.
 * @param array|string|null $context Datos adicionales (array → JSON).
 *
 * @return bool true si insertó, false si falló.
 */
function crm_log_action($action_type, $details, $client_id = null, $user_id = null, $level = 'info', $context = null) {
    global $wpdb;

    $user_id = $user_id ?: get_current_user_id();
    $user    = $user_id ? get_userdata($user_id) : null;

    $year_month = current_time('Y_m');
    crm_ensure_log_table($year_month);

    $table_name = $wpdb->prefix . 'crm_activity_log_' . $year_month;

    if (is_array($context) || is_object($context)) {
        $context = wp_json_encode($context);
    }
    if (is_string($context) && strlen($context) > 65535) {
        $context = substr($context, 0, 65535);
    }

    $row = [
        'user_id'     => (int) $user_id,
        'user_name'   => $user ? (string) $user->display_name : 'Sistema',
        'action_type' => (string) $action_type,
        'level'       => crm_normalize_log_level($level),
        'details'     => (string) $details,
        'context'     => $context,
        'client_id'   => $client_id !== null ? (int) $client_id : null,
        'created_at'  => current_time('mysql'),
        'ip_address'  => crm_get_request_ip(),
    ];

    return (bool) $wpdb->insert($table_name, $row);
}

/**
 * Lista los meses con tabla de logs disponible (descendente).
 *
 * @return array<int, array{value:string,label:string,table:string}>
 */
if (!function_exists('crm_get_available_log_months')) {
    function crm_get_available_log_months() {
        global $wpdb;
        $like   = $wpdb->esc_like($wpdb->prefix . 'crm_activity_log_') . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        $months = [];
        foreach ((array) $tables as $table_name) {
            if (preg_match('/_log_(\d{4}_\d{2})$/', $table_name, $m)) {
                $year_month = $m[1];
                $date = DateTime::createFromFormat('Y_m', $year_month);
                if ($date) {
                    $months[] = [
                        'value' => $year_month,
                        'label' => $date->format('F Y'),
                        'table' => $table_name,
                    ];
                }
            }
        }
        usort($months, function ($a, $b) {
            return strcmp($b['value'], $a['value']);
        });
        return $months;
    }
}

/**
 * Lista los meses con tabla de logs, devolviendo solo los valores `YYYY_MM`.
 *
 * @return string[]
 */
function crm_get_log_month_keys() {
    return array_map(function ($m) { return $m['value']; }, crm_get_available_log_months());
}

/**
 * Consulta paginada de logs combinando uno o varios meses.
 *
 * @param array $args {
 *   @type string|string[] $months       Meses YYYY_MM (default: mes actual).
 *   @type string          $search       Texto libre (LIKE en details/user_name).
 *   @type string          $action       action_type exacto.
 *   @type string          $level        Nivel exacto.
 *   @type int             $user_id      Filtrar por usuario.
 *   @type int             $client_id    Filtrar por cliente.
 *   @type string          $date_from    YYYY-MM-DD.
 *   @type string          $date_to      YYYY-MM-DD.
 *   @type int             $per_page     Default 50.
 *   @type int             $page         Default 1.
 *   @type string          $orderby      created_at|level|action_type|user_name (default created_at).
 *   @type string          $order        ASC|DESC.
 * }
 * @return array{rows:array,total:int,per_page:int,page:int,pages:int}
 */
function crm_logs_query(array $args = []) {
    global $wpdb;

    $defaults = [
        'months'    => [current_time('Y_m')],
        'search'    => '',
        'action'    => '',
        'level'     => '',
        'user_id'   => 0,
        'client_id' => 0,
        'date_from' => '',
        'date_to'   => '',
        'per_page'  => 50,
        'page'      => 1,
        'orderby'   => 'created_at',
        'order'     => 'DESC',
    ];
    $args = array_merge($defaults, $args);

    $allowed_orderby = ['created_at', 'level', 'action_type', 'user_name', 'id'];
    if (!in_array($args['orderby'], $allowed_orderby, true)) {
        $args['orderby'] = 'created_at';
    }
    $args['order']    = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
    $args['per_page'] = max(1, min(500, (int) $args['per_page']));
    $args['page']     = max(1, (int) $args['page']);

    $months = (array) $args['months'];
    if (empty($months)) {
        $months = [current_time('Y_m')];
    }

    $available = crm_get_log_month_keys();
    $months    = array_values(array_filter($months, function ($m) use ($available) {
        return in_array($m, $available, true);
    }));

    if (empty($months)) {
        return ['rows' => [], 'total' => 0, 'per_page' => $args['per_page'], 'page' => 1, 'pages' => 0];
    }

    $where_parts = ['1=1'];
    $where_args  = [];

    if ($args['search'] !== '') {
        $like = '%' . $wpdb->esc_like($args['search']) . '%';
        $where_parts[] = '(details LIKE %s OR user_name LIKE %s OR action_type LIKE %s)';
        array_push($where_args, $like, $like, $like);
    }
    if ($args['action'] !== '') {
        $where_parts[] = 'action_type = %s';
        $where_args[]  = $args['action'];
    }
    if ($args['level'] !== '') {
        $where_parts[] = 'level = %s';
        $where_args[]  = crm_normalize_log_level($args['level']);
    }
    if ($args['user_id'] > 0) {
        $where_parts[] = 'user_id = %d';
        $where_args[]  = (int) $args['user_id'];
    }
    if ($args['client_id'] > 0) {
        $where_parts[] = 'client_id = %d';
        $where_args[]  = (int) $args['client_id'];
    }
    if ($args['date_from']) {
        $where_parts[] = 'created_at >= %s';
        $where_args[]  = $args['date_from'] . ' 00:00:00';
    }
    if ($args['date_to']) {
        $where_parts[] = 'created_at <= %s';
        $where_args[]  = $args['date_to'] . ' 23:59:59';
    }
    $where_sql = implode(' AND ', $where_parts);

    // Construimos UNION ALL entre los meses involucrados.
    $unions      = [];
    $union_args  = [];
    foreach ($months as $month) {
        $table = $wpdb->prefix . 'crm_activity_log_' . $month;
        $unions[]   = "SELECT id, user_id, user_name, action_type, level, details, context, client_id, created_at, ip_address FROM `$table` WHERE $where_sql";
        $union_args = array_merge($union_args, $where_args);
    }

    $base = '(' . implode(' UNION ALL ', $unions) . ') AS combined';

    // Total
    $count_sql  = "SELECT COUNT(*) FROM $base";
    $total      = (int) $wpdb->get_var($wpdb->prepare($count_sql, $union_args));

    // Rows
    $offset = ($args['page'] - 1) * $args['per_page'];
    $rows_sql = "SELECT * FROM $base ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results(
        $wpdb->prepare($rows_sql, array_merge($union_args, [$args['per_page'], $offset])),
        ARRAY_A
    );

    return [
        'rows'     => $rows ?: [],
        'total'    => $total,
        'per_page' => $args['per_page'],
        'page'     => $args['page'],
        'pages'    => (int) ceil($total / $args['per_page']),
    ];
}

/**
 * Stream CSV de los logs según el filtro (sin paginar).
 *
 * NOTE: aborta el request escribiendo a stdout, debe llamarse solo desde
 * un handler que pueda emitir headers HTTP.
 */
function crm_logs_export_csv(array $args = []) {
    if (headers_sent()) {
        return;
    }

    // Quitamos paginación; tope duro en 50k para no reventar memoria.
    $args['per_page'] = 50000;
    $args['page']     = 1;
    $result = crm_logs_query($args);

    $filename = 'crm_logs_' . date('Y-m-d_H-i-s') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 para Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Fecha', 'Nivel', 'Usuario', 'Acción', 'Cliente', 'IP', 'Detalles', 'Contexto']);
    foreach ($result['rows'] as $row) {
        fputcsv($out, [
            $row['id'],
            $row['created_at'],
            $row['level'] ?? 'info',
            $row['user_name'],
            $row['action_type'],
            $row['client_id'] ?? '',
            $row['ip_address'],
            $row['details'],
            $row['context'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/**
 * Cuenta logs por nivel (en los meses indicados).
 *
 * @param string[] $months
 * @return array<string,int>
 */
function crm_logs_count_by_level(array $months = []) {
    global $wpdb;
    if (empty($months)) {
        $months = crm_get_log_month_keys();
    }
    $counts = array_fill_keys(crm_log_levels(), 0);
    foreach ($months as $month) {
        $table   = $wpdb->prefix . 'crm_activity_log_' . $month;
        $exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            continue;
        }
        $rows = $wpdb->get_results("SELECT level, COUNT(*) AS c FROM `$table` GROUP BY level", ARRAY_A);
        foreach ((array) $rows as $r) {
            $lvl = crm_normalize_log_level($r['level'] ?? 'info');
            $counts[$lvl] = ($counts[$lvl] ?? 0) + (int) $r['c'];
        }
    }
    return $counts;
}

/**
 * Devuelve los `action_type` distintos para poblar filtros.
 *
 * @return string[]
 */
function crm_logs_distinct_actions(array $months = []) {
    global $wpdb;
    if (empty($months)) {
        $months = crm_get_log_month_keys();
    }
    $actions = [];
    foreach ($months as $month) {
        $table  = $wpdb->prefix . 'crm_activity_log_' . $month;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            continue;
        }
        $rows = $wpdb->get_col("SELECT DISTINCT action_type FROM `$table`");
        foreach ((array) $rows as $a) {
            $actions[$a] = true;
        }
    }
    $actions = array_keys($actions);
    sort($actions);
    return $actions;
}

/* ---------------------------------------------------------------------------
 * Mantenimiento de logs (cron diario)
 * ------------------------------------------------------------------------- */

/**
 * Programa la tarea cron al activar el plugin.
 */
function crm_logger_schedule_cron() {
    if (!wp_next_scheduled('crm_logs_daily_maintenance')) {
        wp_schedule_event(time() + 3600, 'daily', 'crm_logs_daily_maintenance');
    }
}

/**
 * Limpia el cron al desactivar el plugin.
 */
function crm_logger_unschedule_cron() {
    $timestamp = wp_next_scheduled('crm_logs_daily_maintenance');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'crm_logs_daily_maintenance');
    }
}

// Self-heal: asegura que el cron esté programado incluso tras un update
// (los register_activation_hook solo se disparan en activación manual).
add_action('init', function () {
    if (!wp_doing_cron() && !wp_next_scheduled('crm_logs_daily_maintenance')) {
        crm_logger_schedule_cron();
    }
}, 20);

/**
 * Tarea diaria: purga filas viejas y DROPea tablas mensuales fuera de retención.
 */
add_action('crm_logs_daily_maintenance', 'crm_logs_run_maintenance');
function crm_logs_run_maintenance() {
    global $wpdb;

    $retention_days   = (int) apply_filters('crm_logs_retention_days', (int) get_option('crm_logs_retention_days', 90));
    $retention_months = (int) apply_filters('crm_logs_retention_months', (int) get_option('crm_logs_retention_months', 6));
    $retention_days   = max(7, $retention_days);   // Mínimo 7 días por seguridad.
    $retention_months = max(1, $retention_months); // Al menos el mes actual.

    $available = crm_get_available_log_months();
    $cutoff_month = date('Y_m', strtotime('-' . ($retention_months - 1) . ' months', current_time('timestamp')));

    $rows_deleted    = 0;
    $tables_dropped  = 0;
    $tables_purged   = 0;
    $dropped_list    = [];

    foreach ($available as $month_data) {
        $month = $month_data['value'];
        $table = $month_data['table'];

        // 1) DROP de tablas fuera de la ventana de meses retenidos.
        if (strcmp($month, $cutoff_month) < 0) {
            $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
            delete_option('crm_log_schema_' . $month);
            $tables_dropped++;
            $dropped_list[] = $table;
            continue;
        }

        // 2) Purga por días dentro de cada tabla retenida.
        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `$table` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $retention_days
            )
        );
        $rows_deleted += max(0, $deleted);
        $tables_purged++;
    }

    $context = wp_json_encode([
        'rows_deleted'     => $rows_deleted,
        'tables_dropped'   => $tables_dropped,
        'tables_purged'    => $tables_purged,
        'retention_days'   => $retention_days,
        'retention_months' => $retention_months,
        'dropped'          => $dropped_list,
    ]);

    crm_log_action(
        'logs_mantenimiento',
        sprintf(
            'Mantenimiento de logs: %d filas eliminadas, %d tablas purgadas, %d tablas eliminadas.',
            $rows_deleted,
            $tables_purged,
            $tables_dropped
        ),
        null,
        0,
        'info',
        $context
    );
}
