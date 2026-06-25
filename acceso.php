<?php
// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}
add_filter('nocache_headers', function($headers) {
    $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
    $headers['Pragma'] = 'no-cache';
    $headers['Expires'] = '0';
    return $headers;
});

/**
 * Devuelve el ID de la página de login. Filtrable y configurable por opción.
 * Filtros: `crm_login_page_id`. Opción: `crm_login_page_id`.
 */
function crm_get_login_page_id() {
    $id = (int) get_option('crm_login_page_id', 2);
    return (int) apply_filters('crm_login_page_id', $id);
}

/**
 * Devuelve el ID de la página a la que redirigir tras login.
 */
function crm_get_post_login_redirect_id() {
    $id = (int) get_option('crm_post_login_page_id', 30);
    return (int) apply_filters('crm_post_login_page_id', $id);
}

// Restringir acceso si no estás logueado
add_action('template_redirect', 'restrict_access_for_guests');
function restrict_access_for_guests() {
    $login_page_id  = crm_get_login_page_id();
    $login_page_url = $login_page_id ? get_permalink($login_page_id) : wp_login_url();

    // Permitir acceso a admin-post.php / admin-ajax.php / cron incluso si no está logueado
    if (is_admin()) {
        return;
    }
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (
        strpos($request_uri, 'admin-post.php') !== false ||
        strpos($request_uri, 'admin-ajax.php') !== false ||
        strpos($request_uri, 'wp-cron.php') !== false ||
        strpos($request_uri, 'wp-login.php') !== false
    ) {
        return;
    }

    // Permitir acceso solo si el usuario está logueado o en la página de acceso
    if (!is_user_logged_in() && (!$login_page_id || !is_page($login_page_id))) {
        if ($login_page_url) {
            wp_safe_redirect($login_page_url);
            exit;
        }
    }
}

// Redirigir al usuario logueado a la página de inicio configurada
add_filter('login_redirect', 'custom_login_redirect', 10, 3);
function custom_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (is_wp_error($user) || empty($user) || !isset($user->roles)) {
        return $redirect_to;
    }
    $target_id = crm_get_post_login_redirect_id();
    if ($target_id) {
        $url = get_permalink($target_id);
        if ($url) {
            return $url;
        }
    }
    return $redirect_to;
}

// Crear un shortcode para mostrar el perfil del usuario logueado
add_shortcode('user_profile', 'display_user_profile');
function display_user_profile() {
    if (!is_user_logged_in()) {
        return ''; // No mostrar nada si no está logueado
    }

    $current_user = wp_get_current_user();
    $output = '<div class="user-profile">';
    $output .= '<p>Hola, <strong>' . esc_html($current_user->display_name) . '</strong><br>';
    $output .= '' . esc_html(implode(', ', $current_user->roles)) . '</p>';
    $output .= '</div>';

    return $output;
}

// v1.20.2 — Shortcode de logout frontend para roles bloqueados del wp-admin.
// Uso: [crm_logout] o [crm_logout label="Salir" redirect="https://tusitio.com/"]
add_shortcode('crm_logout', 'crm_shortcode_logout');
function crm_shortcode_logout($atts = []) {
    if (!is_user_logged_in()) {
        return '';
    }
    $atts = shortcode_atts([
        'label'    => 'Cerrar sesión',
        'redirect' => '',
        'class'    => 'crm-logout-btn',
    ], $atts, 'crm_logout');

    $redirect = trim((string) $atts['redirect']);
    if ($redirect === '') {
        $login_id = (int) get_option('crm_login_page_id', 0);
        $redirect = $login_id > 0 ? (string) get_permalink($login_id) : home_url('/');
    }
    return sprintf(
        '<a class="%s" href="%s">%s</a>',
        esc_attr($atts['class']),
        esc_url(wp_logout_url($redirect)),
        esc_html($atts['label'])
    );
}

add_action('wp_head', 'hide_header_with_css_on_login_page');
function hide_header_with_css_on_login_page() {
    $login_page_id = crm_get_login_page_id();
    if ($login_page_id && is_page($login_page_id) && !is_user_logged_in()) {
        echo '<style>
            .ast-header-break-point, .ast-mobile-header-wrap { display: none !important; }
            header { display: none !important; }
        </style>';
    }
}

add_filter('wp_nav_menu_objects', 'mostrar_menu_item_crm_admin', 10, 2);
function mostrar_menu_item_crm_admin($items, $args)
{
    // Recorremos los elementos del menú
    foreach ($items as $key => $item) {
        // Verificamos si es el menu-item103
        if ($item->ID == 103) {
            // Si el usuario no tiene el rol 'crm_admin', eliminamos este elemento
            if (!current_user_can('crm_admin')) {
                unset($items[$key]);
            }
        }
    }

    return $items;
}
