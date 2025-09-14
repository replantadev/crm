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

// Restringir acceso si no estás logueado
add_action('template_redirect', 'restrict_access_for_guests');
function restrict_access_for_guests() {
    $login_page_id = 2; // ID de la página de acceso
    $login_page_url = get_permalink($login_page_id);
    // Permitir acceso a admin-post.php incluso si no está logueado
    if (is_admin() || strpos($_SERVER['REQUEST_URI'], 'admin-post.php') !== false) {
        return;
    }

    // Permitir acceso solo si el usuario está logueado o en la página de acceso
    if (!is_user_logged_in() && !is_page($login_page_id)) {
        wp_redirect($login_page_url);
        exit;
    }
}

// Redirigir al usuario logueado a la página de inicio (ID: 30)
add_filter('login_redirect', 'custom_login_redirect', 10, 3);
function custom_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (isset($user->roles)) {
        return get_permalink(30); // Redirigir a la página con ID 30
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

add_action('wp_head', 'hide_header_with_css_on_login_page');
function hide_header_with_css_on_login_page() {
    if (is_page(2) && !is_user_logged_in()) { // Reemplaza 2 con el ID de tu página de login
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
