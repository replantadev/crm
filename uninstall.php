<?php
/**
 * Uninstall script de CRM Energitel.
 * Solo se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 *
 * Limpia roles, capacidades y opciones del plugin. NO borra las tablas
 * `wp_crm_clients` ni los logs mensuales para evitar pérdida accidental de datos.
 * Si quieres una limpieza total, define la constante CRM_DROP_TABLES_ON_UNINSTALL
 * a true en wp-config.php antes de desinstalar.
 *
 * @package CRM_Energitel
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Cargar helpers de roles para reutilizar la lógica de limpieza
$plugin_dir = plugin_dir_path(__FILE__);
if (file_exists($plugin_dir . 'includes/roles.php')) {
    require_once $plugin_dir . 'includes/roles.php';
    if (function_exists('crm_uninstall_roles')) {
        crm_uninstall_roles();
    }
}

// Eliminar opciones del plugin
$options_to_delete = [
    'crm_plugin_version',
    'crm_roles_installed_version',
    'crm_email_settings',
    'crm_login_page_id',
    'crm_post_login_page_id',
];
foreach ($options_to_delete as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}

// Borrar tablas solo si el usuario lo pide explícitamente
if (defined('CRM_DROP_TABLES_ON_UNINSTALL') && CRM_DROP_TABLES_ON_UNINSTALL) {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}crm_clients`");
    $log_tables = $wpdb->get_col(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($wpdb->prefix . 'crm_activity_log_') . '%'
        )
    );
    foreach ((array) $log_tables as $table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
    }
}

// Limpiar tareas programadas
wp_clear_scheduled_hook('crm_daily_maintenance');
