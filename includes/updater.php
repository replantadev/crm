<?php
/**
 * Sistema de actualizaciones automáticas desde GitHub.
 *
 * Usa Plugin Update Checker (yahnis-elsts) v5 para distribuir nuevas
 * versiones a través del repositorio https://github.com/replantadev/crm/
 *
 * Para repositorios privados, definir `CRM_GITHUB_TOKEN` en wp-config.php.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializa el checker de actualizaciones contra el repo de GitHub.
 *
 * @return object|null Instancia del update checker o null si no se pudo cargar.
 */
function crm_init_update_checker() {
    static $checker = null;
    if ($checker !== null) {
        return $checker ?: null;
    }

    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        $autoload = CRM_PLUGIN_PATH . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        $checker = false;
        return null;
    }

    $repo_url = apply_filters('crm_update_repo_url', 'https://github.com/replantadev/crm/');
    $branch   = apply_filters('crm_update_branch', 'master');

    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $repo_url,
        CRM_PLUGIN_FILE,
        'crm-basico'
    );

    if (defined('CRM_GITHUB_TOKEN') && CRM_GITHUB_TOKEN) {
        $checker->setAuthentication(CRM_GITHUB_TOKEN);
    }

    $checker->setBranch($branch);

    // Prefiere releases publicados (con asset zip) sobre tags simples si están disponibles.
    if (method_exists($checker->getVcsApi(), 'enableReleaseAssets')) {
        // Solo si el integrador lo activa explícitamente (releases con asset suben binario).
        if (apply_filters('crm_update_use_release_assets', false)) {
            $checker->getVcsApi()->enableReleaseAssets();
        }
    }

    // Log de cada comprobación exitosa para trazabilidad.
    $checker->addResultFilter(function ($info) {
        if ($info && function_exists('crm_log_action') && !empty($info->version)) {
            $current = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '0.0.0';
            if (version_compare($info->version, $current, '>')) {
                $context = wp_json_encode([
                    'current' => $current,
                    'remote'  => $info->version,
                    'source'  => 'github',
                ]);
                crm_log_action(
                    'actualizacion_disponible',
                    sprintf('Nueva versión detectada: %s (actual %s)', $info->version, $current),
                    null,
                    0,
                    'notice',
                    $context
                );
            }
        }
        return $info;
    });

    return $checker;
}

// Inicializar lo antes posible para que WordPress integre los hooks de update.
add_action('plugins_loaded', 'crm_init_update_checker', 1);

/**
 * Log del resultado de una actualización completada.
 */
add_action('upgrader_process_complete', function ($upgrader, $hook_extra) {
    if (empty($hook_extra['action']) || $hook_extra['action'] !== 'update') {
        return;
    }
    if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }
    if (empty($hook_extra['plugins']) || !is_array($hook_extra['plugins'])) {
        return;
    }

    $plugin_basename = plugin_basename(CRM_PLUGIN_FILE);
    if (!in_array($plugin_basename, $hook_extra['plugins'], true)) {
        return;
    }

    if (function_exists('crm_log_action')) {
        crm_log_action(
            'plugin_actualizado',
            sprintf('Plugin actualizado a la versión %s', CRM_PLUGIN_VERSION),
            null,
            0,
            'info'
        );
    }

    delete_transient('crm_plugin_cache');
    update_option('crm_last_update_at', time(), false);

    if (function_exists('crm_cleanup_duplicate_files')) {
        crm_cleanup_duplicate_files();
    }
}, 10, 2);

/**
 * Fuerza una comprobación inmediata de actualizaciones.
 *
 * @return array{checked:bool,update_available:bool,remote_version:?string}
 */
function crm_force_update_check() {
    $checker = crm_init_update_checker();
    if (!$checker) {
        return ['checked' => false, 'update_available' => false, 'remote_version' => null];
    }

    $info = $checker->requestUpdate();
    $remote = $info && !empty($info->version) ? (string) $info->version : null;
    $current = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '0.0.0';

    update_option('crm_last_update_check', time(), false);

    return [
        'checked'          => true,
        'update_available' => $remote ? version_compare($remote, $current, '>') : false,
        'remote_version'   => $remote,
    ];
}

/**
 * Handler AJAX para el botón "Buscar actualizaciones" del panel admin.
 */
add_action('wp_ajax_crm_check_updates', function () {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }
    if (!check_ajax_referer('crm_admin_actions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Error de seguridad'], 400);
    }

    $result = crm_force_update_check();
    if (!$result['checked']) {
        wp_send_json_error(['message' => 'No se pudo contactar con GitHub']);
    }

    $msg = $result['update_available']
        ? sprintf('Actualización disponible: %s', $result['remote_version'])
        : 'El plugin ya está en la última versión.';

    wp_send_json_success([
        'message'          => $msg,
        'remote_version'   => $result['remote_version'],
        'current_version'  => CRM_PLUGIN_VERSION,
        'update_available' => $result['update_available'],
    ]);
});
