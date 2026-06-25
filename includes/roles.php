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
 * Devuelve true si el usuario (actual por defecto) es administrador del CRM
 * por **rol explícito** (administrator o crm_admin), no por capability.
 *
 * Se usa para que un usuario con SOLO rol "comercial" jamás vea los
 * controles del administrador, aunque por algún plugin externo se le
 * hubiese inyectado la capability `crm_admin`.
 *
 * @param int|null $user_id ID de usuario; null para el actual.
 */
function crm_user_is_admin($user_id = null) {
    if ($user_id === null) {
        $user = wp_get_current_user();
    } else {
        $user = get_user_by('id', (int) $user_id);
    }
    if (!$user || empty($user->ID) || empty($user->roles)) {
        return false;
    }
    $roles = (array) $user->roles;
    // v1.20.10: si el usuario tiene rol comercial o visitador, NO se considera
    // admin del CRM aunque tambien tenga administrator/crm_admin. Asi un
    // administrador del sitio que actua como comercial no puede reasignarse
    // clientes ni alterar el flujo (cambiar estado, origen, etc).
    if (array_intersect(['comercial', 'visitador'], $roles)) {
        return false;
    }
    $admin_roles = ['administrator', 'crm_admin'];
    return (bool) array_intersect($admin_roles, $roles);
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
        'crm_manage_visits'     => true,
    ];
}

/**
 * Capacidades base del rol visitador (v1.20.1).
 * Solo puede ver y gestionar sus propias visitas asignadas. No abre fichas
 * de cliente, no sube ficheros, no ve listados ni ajustes.
 */
function crm_visitador_caps() {
    return [
        'read'                  => true,
        'crm_view_own_visits'   => true,
        'crm_edit_own_visits'   => true,
    ];
}

/**
 * Devuelve true si el usuario es visitador (rol explícito).
 */
function crm_user_is_visitador($user_id = null) {
    if ($user_id === null) {
        $user = wp_get_current_user();
    } else {
        $user = get_user_by('id', (int) $user_id);
    }
    if (!$user || empty($user->ID) || empty($user->roles)) {
        return false;
    }
    return in_array('visitador', (array) $user->roles, true);
}

/**
 * Devuelve true si el usuario es comercial (rol explícito).
 *
 * Si además tiene rol administrator/crm_admin, NO se considera comercial
 * para que un crm_admin no aparezca duplicado en menús filtrados.
 */
function crm_user_is_comercial($user_id = null) {
    if ($user_id === null) {
        $user = wp_get_current_user();
    } else {
        $user = get_user_by('id', (int) $user_id);
    }
    if (!$user || empty($user->ID) || empty($user->roles)) {
        return false;
    }
    $roles = (array) $user->roles;
    if (array_intersect(['administrator', 'crm_admin'], $roles)) {
        return false;
    }
    return in_array('comercial', $roles, true);
}

/**
 * Devuelve el rol primario del usuario para el CRM en orden de prioridad:
 * administrator > crm_admin > comercial > visitador > '' (sin rol CRM).
 *
 * @param int|null $user_id ID de usuario; null para el actual.
 * @return string Rol primario (slug).
 */
function crm_user_primary_role($user_id = null) {
    if ($user_id === null) {
        $user = wp_get_current_user();
    } else {
        $user = get_user_by('id', (int) $user_id);
    }
    if (!$user || empty($user->ID) || empty($user->roles)) {
        return '';
    }
    $roles = (array) $user->roles;
    $priority = ['administrator', 'crm_admin', 'comercial', 'visitador'];
    foreach ($priority as $r) {
        if (in_array($r, $roles, true)) {
            return $r;
        }
    }
    return '';
}

/**
 * Etiqueta legible del rol primario para mostrar en UI.
 */
function crm_user_role_label($user_id = null) {
    $role = crm_user_primary_role($user_id);
    $labels = [
        'administrator' => 'Administrador',
        'crm_admin'     => 'Admin CRM',
        'comercial'     => 'Comercial',
        'visitador'     => 'Visitador',
    ];
    return $labels[$role] ?? '';
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

    // Rol visitador (v1.20.1)
    if (!get_role('visitador')) {
        add_role('visitador', __('Visitador', 'crm-basico'), crm_visitador_caps());
    } else {
        $role = get_role('visitador');
        foreach (crm_visitador_caps() as $cap => $grant) {
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
 * v1.20.9: Filtro de capabilities. Revoca dinamicamente cualquier cap
 * de admin del CRM (crm_admin, crm_view_all_clients, crm_delete_clients,
 * crm_manage_settings, crm_export_data) para usuarios cuyo rol NO es
 * `administrator` ni `crm_admin`.
 *
 * Esto neutraliza la elevacion de privilegios por plugins externos (Members,
 * User Role Editor) que pudieran haber inyectado esas capabilities a un
 * comercial o visitador. La fuente de verdad es el ROL asignado al usuario.
 *
 * @param array $allcaps Todas las caps del usuario (cap => bool).
 * @param array $caps    Caps requeridas para la accion.
 * @param array $args    Args originales pasados a current_user_can().
 * @param WP_User $user  Objeto usuario.
 * @return array
 */
function crm_filter_user_has_cap($allcaps, $caps, $args, $user) {
    if (!is_object($user) || empty($user->ID)) {
        return $allcaps;
    }
    $roles = (array) ($user->roles ?? []);
    // v1.20.10: si el usuario tiene rol comercial o visitador, se le revocan
    // SIEMPRE las caps admin del CRM aunque tambien tenga administrator/crm_admin.
    $has_field_role = (bool) array_intersect(['comercial', 'visitador'], $roles);
    if (!$has_field_role && array_intersect(['administrator', 'crm_admin'], $roles)) {
        return $allcaps; // admin puro, no tocar
    }
    // Lista de caps que SOLO deben funcionar para administrator/crm_admin.
    $admin_only_caps = [
        'crm_admin',
        'crm_view_all_clients',
        'crm_edit_all_clients',
        'crm_delete_clients',
        'crm_manage_settings',
        'crm_view_logs',
        'crm_export_data',
        'crm_manage_visits',
    ];
    foreach ($admin_only_caps as $cap) {
        if (!empty($allcaps[$cap])) {
            $allcaps[$cap] = false;
        }
    }
    return $allcaps;
}
add_filter('user_has_cap', 'crm_filter_user_has_cap', 999, 4);

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

/**
 * Garantía dinámica: cualquier usuario con `manage_options` (el rol
 * `administrator` de WordPress por defecto) recibe en tiempo de petición
 * todas las capabilities del CRM sin depender del estado guardado en la
 * tabla de roles.
 *
 * Esto evita el síntoma "el menú CRM no aparece tras instalar/actualizar":
 * antes el panel de admin solo se renderizaba si el usuario tenía la
 * capability `crm_admin` que se sincronizaba en activación. Si la
 * activación se saltaba (silent upgrade) o la opción ya estaba guardada con
 * la misma versión, el sync no se reejecutaba y los administradores se
 * quedaban sin el cap. Con este filtro la cap se concede en runtime
 * siempre, mientras el usuario sea administrador.
 */
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (empty($allcaps['manage_options'])) {
        return $allcaps;
    }
    foreach (array_keys(crm_admin_caps()) as $cap) {
        if ($cap === 'read') {
            continue;
        }
        $allcaps[$cap] = true;
    }
    return $allcaps;
}, 10, 4);
