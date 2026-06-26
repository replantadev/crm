<?php
/**
 * Bloqueo de acceso al wp-admin para roles no-administrator (v1.20.2).
 *
 * Política:
 *  - administrator (el dev / super-admin del sitio): acceso TOTAL al wp-admin.
 *  - TODOS los demás roles (crm_admin, comercial, visitador, etc.):
 *    bloqueados, redirigidos al frontend según su rol.
 *
 * Excepciones siempre permitidas:
 *  - wp-admin/admin-ajax.php (AJAX desde frontend).
 *  - wp-admin/admin-post.php (formularios desde frontend).
 *  - WP-Cron.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Devuelve la URL frontend a la que redirigir según el rol del usuario.
 *
 * Settings:
 *  - 'crm_url_panel_admin'    → página con el panel administrativo (crm_admin)
 *  - 'crm_url_panel_comercial'→ página con shortcodes del comercial
 *  - 'crm_url_mi_agenda'      → página con [crm_mi_agenda] (visitador puro)
 *
 * Fallbacks en cascada:
 *  1. URL específica del rol (settings).
 *  2. crm_url_mi_agenda (común para roles operativos).
 *  3. URL de la página configurada como "Página de login" (crm_login_page_id).
 *  4. home_url('/') como último recurso.
 */
function crm_get_redirect_url_for_user($user) {
    if (!$user || empty($user->ID)) {
        return home_url('/');
    }
    $roles = (array) $user->roles;

    $url_admin     = trim((string) get_option('crm_url_panel_admin', ''));
    $url_comercial = trim((string) get_option('crm_url_panel_comercial', ''));
    $url_agenda    = trim((string) get_option('crm_url_mi_agenda', ''));

    $candidate = '';
    if (in_array('crm_admin', $roles, true)) {
        $candidate = $url_admin;
    } elseif (in_array('comercial', $roles, true)) {
        $candidate = $url_comercial !== '' ? $url_comercial : $url_agenda;
    } elseif (in_array('visitador', $roles, true)) {
        $candidate = $url_agenda;
    }
    if ($candidate !== '') {
        return $candidate;
    }
    // Fallback: usar la página de login configurada en el plugin.
    $login_id = (int) get_option('crm_login_page_id', 0);
    if ($login_id > 0) {
        $login_url = get_permalink($login_id);
        if ($login_url) {
            return $login_url;
        }
    }
    return home_url('/');
}

/**
 * v1.20.21: Allow-list para handlers admin-post.php propios del CRM.
 * Algunos plugins de "private site" / restricción de wp-admin (Members AddOn
 * AdminAccess, Restrict User Access, etc.) bloquean cualquier petición a
 * /wp-admin/* para roles no-admin redirigiendo a home en admin_init, incluso
 * cuando se trata de admin-post.php (que admin-post.php verifica nonce y
 * permisos por su cuenta). Esto rompe el guardado de visitas para comerciales.
 *
 * Aquí desenganchamos esos guards SOLO cuando la petición es admin-post.php
 * con un action que empieza por "crm_" (acciones de nuestro plugin).
 *
 * Se ejecuta antes que cualquier hook de admin_init (prioridad PHP_INT_MIN+1)
 * para asegurar que los remove_action surtan efecto.
 */
add_action('admin_init', 'crm_allow_own_admin_post_actions', PHP_INT_MIN + 1);
function crm_allow_own_admin_post_actions() {
    if (wp_doing_ajax()) {
        return;
    }
    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($req_uri === '') {
        return;
    }
    $path = parse_url($req_uri, PHP_URL_PATH);
    if (!is_string($path) || basename($path) !== 'admin-post.php') {
        return;
    }
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
    if ($action === '' || strpos($action, 'crm_') !== 0) {
        return;
    }

    // Members AddOn: Admin Access (https://members-plugin.com/).
    if (function_exists('Members\\AddOns\\AdminAccess\\access_check')) {
        remove_action('admin_init', 'Members\\AddOns\\AdminAccess\\access_check');
    }
    // Defensa adicional para variantes de Members u otros plugins similares:
    // recorremos los hooks de admin_init y quitamos cualquier callback cuyo
    // nombre contenga "AdminAccess" o "access_check" en namespace Members.
    global $wp_filter;
    if (isset($wp_filter['admin_init']) && is_object($wp_filter['admin_init'])) {
        foreach ($wp_filter['admin_init']->callbacks as $priority => $cbs) {
            foreach ($cbs as $key => $cb) {
                if (!isset($cb['function'])) {
                    continue;
                }
                $fn = $cb['function'];
                if (is_string($fn) && (
                    stripos($fn, 'Members\\AddOns\\AdminAccess') !== false
                    || stripos($fn, 'members_admin_access') !== false
                )) {
                    unset($wp_filter['admin_init']->callbacks[$priority][$key]);
                }
            }
        }
    }
}

/**
 * Hook principal: bloquea el wp-admin a CUALQUIER usuario que NO sea
 * `administrator`. Solo el administrator del sitio gestiona el backend.
 *
 * Prioridad 99 para ejecutarse DESPUÉS de otros admin_init (login_redirect,
 * register_setting, etc.) y evitar interferencias.
 */
add_action('admin_init', 'crm_block_wpadmin_for_non_admins', 99);
function crm_block_wpadmin_for_non_admins() {
    if (wp_doing_ajax()) {
        return;
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return;
    }
    global $pagenow;
    // Endpoints siempre permitidos:
    //  - admin-post.php / admin-ajax.php: handlers POST/AJAX desde frontend.
    //  - profile.php: que el usuario pueda cambiar su contrasena/datos.
    // v1.20.19: ademas de $pagenow comprobamos REQUEST_URI por si el reverse
    // proxy / configuracion del servidor deja $pagenow vacio o incorrecto
    // (esto puede pasar en producciones con PHP-FPM detras de Nginx).
    $allowed_pages = ['admin-post.php', 'admin-ajax.php', 'profile.php'];
    if (in_array($pagenow, $allowed_pages, true)) {
        return;
    }
    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($req_uri !== '') {
        $path = parse_url($req_uri, PHP_URL_PATH);
        if (is_string($path)) {
            $basename = basename($path);
            if (in_array($basename, $allowed_pages, true)) {
                return;
            }
        }
    }

    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) {
        return;
    }

    // SOLO administrator entra al wp-admin
    if (in_array('administrator', (array) $user->roles, true)) {
        return;
    }

    $url = crm_get_redirect_url_for_user($user);
    wp_safe_redirect($url);
    exit;
}

/**
 * Oculta la admin bar de WP en el frontend para todos los roles no-admin.
 */
add_filter('show_admin_bar', 'crm_hide_admin_bar_for_non_admins', 99);
function crm_hide_admin_bar_for_non_admins($show) {
    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) {
        return $show;
    }
    if (in_array('administrator', (array) $user->roles, true)) {
        return $show;
    }
    return false;
}

/**
 * Settings: registrar URLs configurables.
 */
add_action('admin_init', 'crm_register_redirect_urls_setting');
function crm_register_redirect_urls_setting() {
    register_setting('crm_settings', 'crm_url_panel_admin', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);
    register_setting('crm_settings', 'crm_url_panel_comercial', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);
    register_setting('crm_settings', 'crm_url_mi_agenda', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);
    register_setting('crm_settings', 'crm_url_mis_leads', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);
}

/**
 * Admin notice: avisar al administrator si alguna de las URLs frontend está
 * sin configurar. Si están vacías, los usuarios no-admin serán redirigidos
 * a la home (o al login_page_id si está configurado), lo que puede
 * desorientar.
 */
add_action('admin_notices', 'crm_admin_notice_redirect_urls');
function crm_admin_notice_redirect_urls() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $missing = [];
    if (trim((string) get_option('crm_url_panel_admin', '')) === '') {
        $missing[] = 'URL panel admin';
    }
    if (trim((string) get_option('crm_url_panel_comercial', '')) === '') {
        $missing[] = 'URL panel comercial';
    }
    if (trim((string) get_option('crm_url_mi_agenda', '')) === '') {
        $missing[] = 'URL Mi agenda';
    }
    if (empty($missing)) {
        return;
    }
    ?>
    <div class="notice notice-warning is-dismissible">
        <p><strong>CRM v1.20.2:</strong> Tienes URLs de redirección frontend sin configurar
        (<?php echo esc_html(implode(', ', $missing)); ?>).
        Los usuarios no-administrator que intenten entrar al <code>wp-admin</code> serán
        redirigidos a la home, lo que puede causar confusión.</p>
        <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=crm-settings')); ?>">Configurar ahora</a></p>
    </div>
    <?php
}
