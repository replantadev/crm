<?php
/**
 * Registro y limpieza de roles del CRM.
 *
 * Crea los roles `crm_admin` y `comercial` con capacidades específicas
 * para que el plugin sea funcional al instalarse en una WordPress limpia.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capacidades base del rol comercial.
 */
function crm_comercial_caps() {
    return [
        'read'                  => true,
        'crm_view_own_clients'  => true,
        'crm_edit_own_clients'  => true,
        'crm_upload_files'      => true,
    ];
}

/**
 * Capacidades base del rol administrador del CRM.
 */
function crm_admin_caps() {
    return [
        'read'                  => true,
        'crm_admin'             => true,
        'crm_view_own_clients'  => true,
        'crm_edit_own_clients'  => true,
        'crm_view_all_clients'  => true,
        'crm_edit_all_clients'  => true,
        'crm_delete_clients'    => true,
        'crm_upload_files'      => true,
        'crm_manage_settings'   => true,
        'crm_view_logs'         => true,
        'crm_export_data'       => true,
    ];
}

/**
 * Crea/actualiza los roles del CRM. Idempotente.
 */
function crm_install_roles() {
    if (!function_exists('add_role')) {
        return;
    }

    // Rol comercial
    if (!get_role('comercial')) {
        add_role('comercial', __('Comercial', 'crm-basico'), crm_comercial_caps());
    } else {
        $role = get_role('comercial');
        foreach (crm_comercial_caps() as $cap => $grant) {
            if ($grant) {
                $role->add_cap($cap);
            }
        }
    }

    // Rol administrador del CRM
    if (!get_role('crm_admin')) {
        add_role('crm_admin', __('Administrador CRM', 'crm-basico'), crm_admin_caps());
    } else {
        $role = get_role('crm_admin');
        foreach (crm_admin_caps() as $cap => $grant) {
            if ($grant) {
                $role->add_cap($cap);
            }
        }
    }

    // Replicar capacidad `crm_admin` en el administrador WP, así un super-admin
    // del sitio también puede operar el CRM aunque no tenga el rol específico.
    $wp_admin = get_role('administrator');
    if ($wp_admin) {
        $wp_admin->add_cap('crm_admin');
        foreach (array_keys(crm_admin_caps()) as $cap) {
            $wp_admin->add_cap($cap);
        }
    }
}

/**
 * Quita las capacidades del CRM al desactivar/desinstalar.
 * NO borra el rol `comercial` ni `crm_admin` para no perder asignaciones de usuarios.
 */
function crm_remove_admin_caps_from_wp_admin() {
    $wp_admin = get_role('administrator');
    if (!$wp_admin) {
        return;
    }
    foreach (array_keys(crm_admin_caps()) as $cap) {
        if ($cap === 'read') {
            continue;
        }
        $wp_admin->remove_cap($cap);
    }
}

/**
 * Borra completamente los roles del CRM. Solo se llama en uninstall.
 */
function crm_uninstall_roles() {
    if (function_exists('remove_role')) {
        remove_role('crm_admin');
        remove_role('comercial');
    }
    crm_remove_admin_caps_from_wp_admin();
}

/**
 * Garantía perezosa: si por algún motivo los roles se borraron en una
 * instalación ya activa, los volvemos a crear silenciosamente.
 */
add_action('init', function () {
    if (get_option('crm_roles_installed_version') === CRM_PLUGIN_VERSION) {
        return;
    }
    crm_install_roles();
    update_option('crm_roles_installed_version', CRM_PLUGIN_VERSION, false);
}, 5);
