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
    $id = (int) get_option('crm_login_page_id', 0);
    // v1.20.94: si no hay nada configurado (o apunta a una página borrada),
    // usa la página nativa "acceso" ([crm_login]) que el plugin se
    // autocrea — antes el fallback era el ID 2, un valor sin sentido real.
    if ($id <= 0 || !get_post($id)) {
        $acceso = get_page_by_path('acceso');
        if ($acceso) {
            $id = (int) $acceso->ID;
        }
    }
    return (int) apply_filters('crm_login_page_id', $id);
}

/**
 * Devuelve el ID de la página a la que redirigir tras login.
 */
function crm_get_post_login_redirect_id() {
    $id = (int) get_option('crm_post_login_page_id', 30);
    return (int) apply_filters('crm_post_login_page_id', $id);
}

/**
 * Slugs de páginas públicas a propósito, sin login — a ellas llega un
 * tercero externo (proveedor, cliente) desde el enlace de un email, con
 * autorización propia por token de un solo uso, no por sesión de WordPress.
 * Única fuente de esta lista: la usan tanto `restrict_access_for_guests()`
 * (bloqueo propio del CRM) como el filtro de "sitio privado" del plugin
 * Members más abajo, para no mantenerla en dos sitios.
 *
 * @return string[]
 */
function crm_paginas_publicas_sin_login() {
    return apply_filters('crm_paginas_publicas_sin_login', [
        'confirmar-pedido',   // v1.20.57 — el proveedor (Santoki) confirma un pedido.
        'plan-de-seguridad',  // v1.20.60 — se abre en pestaña nueva desde el panel del instalador.
        'validar-extra',      // v1.20.75 — el cliente aprueba/rechaza una partida extra.
    ]);
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

    if (is_page(crm_paginas_publicas_sin_login())) {
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

/**
 * v1.20.94: el plugin "Members" (modo "sitio privado") intercepta ANTES que
 * restrict_access_for_guests() (template_redirect prioridad 0 vs 10) y
 * bloquearía también las páginas públicas de arriba si no se le avisa
 * explícitamente — usa su propio punto de extensión documentado para esto,
 * en vez de pelear por prioridades de hook.
 *
 * La propia página de login TAMBIÉN tiene que quedar excluida: si no,
 * Members interceptaría incluso el envío (POST) del formulario nativo
 * antes de que crm_procesar_login_nativo() llegue a procesarlo, con un
 * redirect que convierte el POST en un GET y pierde usuario/contraseña —
 * el visitante vería el formulario vacío otra vez, sin ningún error.
 */
add_filter('members_is_private_page', function ($is_private) {
    if (is_page(crm_paginas_publicas_sin_login())) {
        return false;
    }
    $login_page_id = crm_get_login_page_id();
    if ($login_page_id && is_page($login_page_id)) {
        return false;
    }
    return $is_private;
});

/**
 * v1.20.94: cuando algo de WordPress (auth_redirect(), que usan tanto el
 * núcleo como el modo "sitio privado" de Members) necesita mandar a un
 * visitante a iniciar sesión, que vaya a NUESTRA página de acceso en vez de
 * al wp-login.php por defecto — sin esto, activar "sitio privado" en
 * Members hace que se vea el login genérico de WordPress.
 */
add_filter('login_url', 'crm_filtro_login_url', 10, 3);
function crm_filtro_login_url($login_url, $redirect, $force_reauth) {
    $login_page_id = crm_get_login_page_id();
    if (!$login_page_id) {
        return $login_url;
    }
    $custom_url = get_permalink($login_page_id);
    if (!$custom_url) {
        return $login_url;
    }
    if ($redirect) {
        $custom_url = add_query_arg('redirect_to', rawurlencode($redirect), $custom_url);
    }
    return $custom_url;
}

/**
 * A dónde mandar a un usuario justo después de iniciar sesión — misma
 * lógica tanto si entra por el formulario nativo del CRM ([crm_login]) como
 * por el login por defecto de WordPress (filtro `login_redirect`).
 *
 * @param WP_User $user
 * @return string
 */
function crm_resolve_post_login_redirect($user) {
    // v1.20.42: si el rol tiene un panel de frontend propio configurado
    // (crm_admin/comercial/visitador/instalador), va directo ahí desde el
    // login — antes solo se aplicaba al intentar entrar a wp-admin (ver
    // crm_get_role_panel_url() en admin-lockdown.php, misma fuente para
    // los dos sitios). Los roles sin panel propio (administrator...) siguen
    // yendo a la página genérica de siempre, sin cambios.
    if (function_exists('crm_get_role_panel_url')) {
        $role_url = crm_get_role_panel_url($user);
        if ($role_url !== '') {
            return $role_url;
        }
    }

    $target_id = crm_get_post_login_redirect_id();
    if ($target_id) {
        $url = get_permalink($target_id);
        if ($url) {
            return $url;
        }
    }
    return home_url('/');
}

// Redirigir al usuario logueado a la página de inicio configurada (login por
// defecto de WordPress, p.ej. wp-login.php accedido directamente).
add_filter('login_redirect', 'custom_login_redirect', 10, 3);
function custom_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (is_wp_error($user) || empty($user) || !isset($user->roles)) {
        return $redirect_to;
    }
    return crm_resolve_post_login_redirect($user);
}

/**
 * v1.20.94: formulario de acceso propio del plugin — hasta ahora la página
 * de login dependía por completo de que Elementor + el widget de login de
 * Members la renderizaran; al desactivar Elementor se quedó en blanco. Se
 * procesa aquí, en template_redirect (antes de que se renderice nada), para
 * poder redirigir con wp_safe_redirect() sin problemas de cabeceras ya
 * enviadas — mismo patrón que restrict_access_for_guests().
 */
add_action('template_redirect', 'crm_procesar_login_nativo', 5);
function crm_procesar_login_nativo() {
    if (!isset($_POST['crm_login_submit'])) {
        return;
    }
    if (!isset($_POST['crm_login_nonce']) || !wp_verify_nonce($_POST['crm_login_nonce'], 'crm_login')) {
        $GLOBALS['crm_login_error'] = 'El formulario caducó — inténtalo de nuevo.';
        return;
    }

    $user_login    = sanitize_text_field(wp_unslash($_POST['crm_user_login'] ?? ''));
    $user_password = (string) ($_POST['crm_user_password'] ?? ''); // sin sanitizar: una contraseña puede llevar cualquier carácter.
    if ($user_login === '' || $user_password === '') {
        $GLOBALS['crm_login_error'] = 'Rellena usuario/email y contraseña.';
        return;
    }

    $user = wp_signon([
        'user_login'    => $user_login,
        'user_password' => $user_password,
        'remember'      => !empty($_POST['crm_remember']),
    ], is_ssl());

    if (is_wp_error($user)) {
        $GLOBALS['crm_login_error'] = 'Usuario o contraseña incorrectos.';
        return;
    }

    $redirect_to = isset($_GET['redirect_to']) ? (string) wp_unslash($_GET['redirect_to']) : '';
    if ($redirect_to === '' || !wp_validate_redirect($redirect_to, '')) {
        $redirect_to = crm_resolve_post_login_redirect($user);
    }
    wp_safe_redirect($redirect_to);
    exit;
}

/**
 * Shortcode [crm_login] — formulario de acceso nativo del plugin, sin
 * depender de Elementor ni de ningún otro plugin. Colócalo en la página que
 * apunte la opción `crm_login_page_id`.
 */
add_shortcode('crm_login', 'crm_shortcode_login');
function crm_shortcode_login($atts = []) {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        return '<p>Ya has iniciado sesión como <strong>' . esc_html($user->display_name) . '</strong>. <a href="' . esc_url(crm_resolve_post_login_redirect($user)) . '">Ir a tu panel</a></p>';
    }

    $error = isset($GLOBALS['crm_login_error']) ? (string) $GLOBALS['crm_login_error'] : '';

    ob_start();
    ?>
    <div class="crm-login-wrap">
        <h2>Acceso</h2>
        <form method="post">
            <?php wp_nonce_field('crm_login', 'crm_login_nonce'); ?>
            <input type="hidden" name="crm_login_submit" value="1">
            <label for="crm-login-user">Nombre de usuario o email</label>
            <input type="text" id="crm-login-user" name="crm_user_login" autocomplete="username" required autofocus>
            <label for="crm-login-pass">Contraseña</label>
            <input type="password" id="crm-login-pass" name="crm_user_password" autocomplete="current-password" required>
            <label class="crm-login-remember">
                <input type="checkbox" name="crm_remember" value="1"> Recuérdame
            </label>
            <?php if ($error !== '') : ?>
                <p class="crm-login-error"><?php echo esc_html($error); ?></p>
            <?php endif; ?>
            <button type="submit">Acceder</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
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
        // v1.20.90: ".ast-header-break-point" NO es un contenedor del menu
        // movil — Astra lo pone como CLASE DEL <body> por debajo del
        // breakpoint de cabecera. Ocultarlo ocultaba el body entero en
        // movil (pagina en blanco). El contenedor real a ocultar es
        // ".ast-mobile-header-wrap".
        echo '<style>
            .ast-mobile-header-wrap { display: none !important; }
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
