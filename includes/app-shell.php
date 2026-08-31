<?php
/**
 * CRM — App Shell (v1.19.0).
 *
 * Convierte las páginas CRM en un layout tipo "app":
 *  - Detecta páginas por slug configurable.
 *  - Añade <body class="crm-app-mode"> automáticamente.
 *  - Inyecta topbar fija propia (logo + nav + buscador + avatar).
 *  - El CSS asociado (crm-design-v2.css) oculta header/footer de Astra
 *    y resetea márgenes en esas páginas.
 *
 * No requiere shortcode wrapper: el usuario sigue usando sus shortcodes
 * existentes en las páginas; el shell rodea el contenido automáticamente.
 *
 * Configuración: panel admin → Aspecto / App Shell.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CRM_APP_SHELL_OPTION = 'crm_app_shell';

/**
 * Devuelve la configuración del App Shell con defaults.
 */
function crm_app_shell_get_settings() {
    $defaults = [
        'enabled' => 1,
        'slugs'   => [
            'alta-de-cliente',
            'mis-altas-de-cliente',
            'todas-las-altas-de-cliente',
            'resumen',
            'asignar-leads',
            'panel-de-control',
            'editar-cliente',
            'crm',
            'mi-agenda',
            'mis-leads',
            'instalaciones',
            'nueva-instalacion',
            'instalacion',
            // v1.20.46: el instalador entra al mismo App Shell que el resto de
            // roles (misma topbar, variante clara con su marca) en vez de vivir
            // en una página 100% aparte — ver crm_app_shell_render_topbar().
            'panel-instalador',
            'calendario-instalador',
            'notificaciones',
            'mi-perfil-instalador',
        ],
        'brand_label' => 'CRM',
    ];
    $opts = get_option(CRM_APP_SHELL_OPTION, []);
    if (!is_array($opts)) {
        $opts = [];
    }
    $merged = array_merge($defaults, $opts);
    // Forzar tipos
    $merged['enabled'] = (int) $merged['enabled'] === 1 ? 1 : 0;
    if (!is_array($merged['slugs'])) {
        $merged['slugs'] = $defaults['slugs'];
    }
    $merged['slugs'] = array_filter(array_map('sanitize_title', $merged['slugs']));
    return $merged;
}

/**
 * Lista de shortcodes que, si están presentes en el contenido de la página,
 * activan el shell aunque el slug no esté listado. Filtrable.
 *
 * @return string[]
 */
function crm_app_shell_trigger_shortcodes() {
    return apply_filters('crm_app_shell_trigger_shortcodes', [
        'crm_alta_cliente',
        'crm_lista_altas',
        'crm_editar_cliente',
        'todas_las_altas',
        'crm_admin_panel',
        'crm_clientes_recientes',
        'crm_clientes_por_interes',
        'crm_clientes_por_estado',
        'crm_rendimiento_comercial',
        'crm_comerciales_estadisticas',
        'crm_mi_agenda',
        'crm_mi_gcal',
        'asignacion_leads_mk',
        'crm_guia_admin',
        'crm_guia_comerciales',
        'crm_inst_nueva_desde_presupuesto',
        'crm_inst_listado',
        'crm_inst_ficha',
        'crm_inst_panel_instalador',
        'crm_inst_panel_calendario',
        'crm_inst_panel_perfil',
        'crm_notificaciones_lista',
    ]);
}

/**
 * Helper: devuelve true cuando la pagina actual se solicita en modo
 * embed/iframe del CRM (parametro GET `crm_modal=1`).
 *
 * En este modo el plugin suprime el shell propio (topbar/menu) y ademas
 * oculta el header/footer del tema y la admin bar de WordPress para que el
 * iframe muestre solo el contenido limpio, sin chrome ni navegacion.
 *
 * v1.20.13
 */
function crm_app_shell_is_modal_request() {
    return isset($_GET['crm_modal']) && (string) $_GET['crm_modal'] === '1';
}

/**
 * Modo chromeless: cuando el iframe del modal pide la pagina con
 * `?crm_modal=1` ocultamos la admin bar y el chrome del tema (Astra y
 * temas similares), para que dentro del iframe solo se vea el contenido.
 *
 * v1.20.13
 */
add_action('after_setup_theme', function () {
    if (crm_app_shell_is_modal_request()) {
        show_admin_bar(false);
    }
});

/**
 * Selectores para anular el chrome del tema (Astra: .ast-*, #masthead, y
 * genéricos de WP) — probados en el modo "chromeless" del modal. Extraído a
 * función para que cualquier página 100% propia del CRM (el modal, el panel
 * del instalador...) pueda ocultar el mismo menú de Astra sin duplicar la
 * lista de selectores en cada sitio que lo necesite.
 *
 * @param bool $reset_layout Si también se resetean paddings/márgenes del
 *             contenedor de contenido (crm-shell-main). Los que no usan ese
 *             wrapper (p.ej. el panel del instalador) pasan false.
 */
function crm_app_shell_chromeless_css($reset_layout = true) {
    $css = "
    #wpadminbar,
    header.site-header,
    #masthead,
    .site-header,
    .ast-primary-header-bar,
    .ast-above-header,
    .ast-below-header,
    .main-header-bar-wrap,
    .main-header-bar,
    .ast-main-header,
    .ast-mobile-header-wrap,
    footer.site-footer,
    #colophon,
    .site-footer,
    .ast-scroll-to-top-wrap,
    .crm-topbar {
        display: none !important;
    }
    html.wp-toolbar { padding-top: 0 !important; }
    ";
    if ($reset_layout) {
        $css .= "
        .ast-container,
        .site-content,
        #content,
        .ast-row,
        .entry-content,
        .ast-article-single,
        main {
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100% !important;
        }
        body.crm-app-mode .crm-shell-main { padding-top: 0 !important; }
        ";
    }
    return $css;
}

add_action('wp_head', function () {
    if (!crm_app_shell_is_modal_request()) {
        return;
    }
    // CSS muy especifico para neutralizar chrome del tema dentro del modal.
    ?>
    <style id="crm-modal-chromeless">
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
    <?php echo crm_app_shell_chromeless_css(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </style>
    <?php
}, 9999);

/**
 * Determina si la página actual es una página CRM.
 *
 * Detección en dos pasos para robustez:
 *  1) Slug del post listado en la opción.
 *  2) Presencia de alguno de los shortcodes CRM en el contenido del post.
 */
function crm_app_shell_is_crm_page() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    // v1.20.12 / v1.20.13: si la pagina se solicita en modo embed/iframe
    // (crm_modal=1), suprimir el shell (topbar/menu) para que dentro del
    // iframe solo se vea el contenido y no se duplique el menu lateral.
    if (crm_app_shell_is_modal_request()) {
        $cache = false;
        return $cache;
    }
    $opts = crm_app_shell_get_settings();
    if (empty($opts['enabled']) || is_admin() || !is_singular()) {
        $cache = false;
        return $cache;
    }
    $post = get_queried_object();
    if (!$post || empty($post->post_name)) {
        $cache = false;
        return $cache;
    }
    if (in_array($post->post_name, $opts['slugs'], true)) {
        $cache = true;
        return $cache;
    }
    // Fallback: detectar por shortcode en el contenido.
    if (!empty($post->post_content)) {
        $content = $post->post_content;
        foreach (crm_app_shell_trigger_shortcodes() as $sc) {
            if (has_shortcode($content, $sc)) {
                $cache = true;
                return $cache;
            }
        }
    }
    $cache = false;
    return $cache;
}

/**
 * Añade la clase body para activar el modo app.
 */
add_filter('body_class', function ($classes) {
    if (crm_app_shell_is_crm_page()) {
        $classes[] = 'crm-app-mode';
        $classes[] = 'crm-ui';
    }
    return $classes;
});

/**
 * Inyecta la topbar y abre el wrapper .crm-shell-main al inicio del body.
 */
add_action('wp_body_open', function () {
    if (!crm_app_shell_is_crm_page()) {
        return;
    }
    crm_app_shell_render_topbar();
    // Wrapper se cierra en wp_footer (con prioridad baja para envolver el contenido).
    echo '<main class="crm-shell-main">';
}, 1);

add_action('wp_footer', function () {
    if (!crm_app_shell_is_crm_page()) {
        return;
    }
    echo '</main>';
}, 999);

/**
 * Busca una página publicada que contenga el shortcode dado y devuelve su
 * permalink. Se usa para resolver el enlace a las guías de uso sin depender
 * de un slug fijo (las páginas de guía las crea el propio usuario en
 * WordPress, el slug puede ser cualquiera).
 *
 * Cacheado 12h en transient porque es una query LIKE sobre post_content.
 *
 * @param string $shortcode_tag Nombre del shortcode, p.ej. 'crm_guia_admin'.
 * @return string URL o '' si no se encontró ninguna página.
 */
function crm_app_shell_find_page_url_by_shortcode($shortcode_tag) {
    $transient_key = 'crm_guia_url_' . $shortcode_tag;
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }
    global $wpdb;
    $post_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'page' AND post_status = 'publish'
         AND post_content LIKE %s
         ORDER BY ID ASC LIMIT 1",
        '%[' . $wpdb->esc_like($shortcode_tag) . '%'
    ));
    $url = $post_id ? (string) get_permalink($post_id) : '';
    set_transient($transient_key, $url, 12 * HOUR_IN_SECONDS);
    return $url;
}

/**
 * Invalida la caché de URLs de guía cuando se guarda cualquier página, para
 * que un cambio de slug o una guía movida/creada se refleje sin esperar
 * las 12h del transient.
 */
add_action('save_post_page', function () {
    delete_transient('crm_guia_url_crm_guia_admin');
    delete_transient('crm_guia_url_crm_guia_comerciales');
});

/**
 * Devuelve la URL de la guía de uso que corresponde al usuario actual, según
 * el mismo criterio de acceso que usan los shortcodes de guía
 * (crm_guia_admin_shortcode / crm_guia_comerciales_shortcode en guia-*.php):
 * administrator/crm_admin/jefe_instalaciones → guía de administrador,
 * comercial/visitador/instalador → guía de usuario.
 *
 * @return string URL o '' si el usuario no tiene guía o no existe la página.
 */
function crm_app_shell_guia_url() {
    if (!is_user_logged_in()) {
        return '';
    }
    $user  = wp_get_current_user();
    $roles = (array) $user->roles;
    $is_full_admin = function_exists('crm_user_is_admin') && crm_user_is_admin();
    $is_jefe_inst  = !$is_full_admin && in_array('jefe_instalaciones', $roles, true);

    if ($is_full_admin || $is_jefe_inst) {
        return crm_app_shell_find_page_url_by_shortcode('crm_guia_admin');
    }
    if (array_intersect(['comercial', 'visitador', 'instalador'], $roles)) {
        return crm_app_shell_find_page_url_by_shortcode('crm_guia_comerciales');
    }
    return '';
}

/**
 * Pie de página propio del App Shell: un único enlace a "Guía de uso", que
 * resuelve automáticamente a la guía que le corresponde al rol del usuario
 * (ver crm_app_shell_guia_url()). Sustituye a mantener el enlace a mano en
 * el footer del tema, que además queda oculto en las páginas del CRM.
 */
add_action('wp_footer', function () {
    if (!crm_app_shell_is_crm_page()) {
        return;
    }
    $guia_url = crm_app_shell_guia_url();
    if ($guia_url === '') {
        return;
    }
    ?>
    <footer class="crm-shell-footer">
        <a href="<?php echo esc_url($guia_url); ?>">
            <?php echo function_exists('crm_icon') ? crm_icon('file-text', 14) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span>Guía de uso</span>
        </a>
    </footer>
    <style>
    .crm-shell-footer { display:flex; justify-content:center; padding:18px 20px 28px; }
    .crm-shell-footer a { display:inline-flex; align-items:center; gap:6px; color:#9ca3af; text-decoration:none; font-size:12px; font-weight:500; }
    .crm-shell-footer a:hover { color:#4a5568; text-decoration:underline; }
    </style>
    <?php
}, 1000);

/**
 * Lista de items del menú del shell. Filtrable por rol.
 *
 * Cada item: label, slug (fallback), icon, roles (whitelist ESTRICTA de roles
 * primarios permitidos), opcional `option` (option_key con URL custom).
 *
 * Jerarquía verificada (v1.20.6):
 *  - administrator / crm_admin: Escritorio, Alta, Mis altas, Todas las altas,
 *    Resumen, Leads MK, Mi agenda, Panel.
 *  - comercial: Escritorio, Alta, Mis altas, Mis leads, Mi agenda.
 *  - visitador: Escritorio, Mis leads, Mi agenda.
 *
 * NO se usa `capability` para filtrar el menú: el filtrado es por rol primario
 * (función `crm_user_primary_role()`), para evitar que un plugin externo
 * (p.ej. Members) que añada la capability `crm_admin` a un comercial le haga
 * ver items que no le corresponden.
 *
 * @return array<int, array<string,mixed>>
 */
function crm_app_shell_menu_items() {
    $items = [
        [
            'label' => 'Escritorio',
            'slug'  => 'crm',
            'icon'  => 'house',
            'roles' => ['administrator', 'crm_admin', 'comercial', 'visitador'],
        ],
        [
            'label' => 'Alta',
            'slug'  => 'alta-de-cliente',
            'icon'  => 'plus',
            // v1.20.7: visitador tambien puede dar altas (actua como comercial).
            'roles' => ['administrator', 'crm_admin', 'comercial', 'visitador'],
        ],
        [
            'label' => 'Mis altas',
            'slug'  => 'mis-altas-de-cliente',
            'icon'  => 'list-bullets',
            // v1.20.7: visitador ve sus propias altas igual que un comercial.
            'roles' => ['administrator', 'crm_admin', 'comercial', 'visitador'],
        ],
        [
            'label' => 'Todas las altas',
            'slug'  => 'todas-las-altas-de-cliente',
            'icon'  => 'users',
            'roles' => ['administrator', 'crm_admin'],
        ],
        [
            'label' => 'Resumen',
            'slug'  => 'resumen',
            'icon'  => 'chart-bar',
            'roles' => ['administrator', 'crm_admin'],
        ],
        [
            'label' => 'Leads MK',
            'slug'  => 'asignar-leads',
            'icon'  => 'target',
            'roles' => ['administrator', 'crm_admin'],
        ],
        [
            'label'  => 'Mis leads',
            'slug'   => 'mis-leads',
            'option' => 'crm_url_mis_leads',
            'icon'   => 'target',
            'roles'  => ['comercial', 'visitador'],
        ],
        [
            // v1.20.67: administrator/crm_admin ven la MISMA página con otra
            // etiqueta — ahí ya ven todas las visitas (sin filtro) más las
            // citas de instalación combinadas (crm_shortcode_mi_agenda()), así
            // que "Mi agenda" no describe lo que ven; "Agenda" sí.
            'label'  => 'Agenda',
            'slug'   => 'mi-agenda',
            'option' => 'crm_url_mi_agenda',
            'icon'   => 'calendar',
            'roles'  => ['administrator', 'crm_admin'],
        ],
        [
            'label'  => 'Mi agenda',
            'slug'   => 'mi-agenda',
            'option' => 'crm_url_mi_agenda',
            'icon'   => 'calendar',
            'roles'  => ['comercial', 'visitador'],
        ],
        [
            'label' => 'Panel',
            'slug'  => 'panel-de-control',
            'icon'  => 'gear',
            'roles' => ['administrator', 'crm_admin'],
        ],
        [
            // v1.20.27 — Fase 2 del módulo de instalaciones. Apunta al listado;
            // la alta desde presupuesto se abre con el botón "+ Nueva instalación"
            // de esa misma pantalla, no hay submenú anidado en esta topbar.
            'label' => 'Instalaciones',
            'slug'  => 'instalaciones',
            'icon'  => 'map-pin',
            'roles' => ['administrator', 'crm_admin', 'jefe_instalaciones'],
        ],
        [
            // v1.20.46 — el instalador puro entra al mismo App Shell que el
            // resto de roles (antes vivía en una página aparte sin menú).
            'label' => 'Mis instalaciones',
            'slug'  => 'panel-instalador',
            'icon'  => 'map-pin',
            'roles' => ['instalador'],
        ],
        [
            'label' => 'Calendario',
            'slug'  => 'calendario-instalador',
            'icon'  => 'calendar',
            'roles' => ['instalador'],
        ],
        [
            'label' => 'Mi perfil',
            'slug'  => 'mi-perfil-instalador',
            'icon'  => 'gear',
            'roles' => ['instalador'],
        ],
    ];

    // Resolver URLs: prioridad option > get_page_by_path(slug) > fallback home_url(slug).
    // v1.20.8: antes ocultabamos items sin pagina con `continue` lo cual hacia que el
    // menu desapareciera para comercial/visitador si las paginas no estaban creadas.
    // Ahora siempre devolvemos URL (fallback) y el bootstrap de paginas las crea.
    $resolved = [];
    foreach ($items as $item) {
        $url = '';
        if (!empty($item['option'])) {
            $url = (string) get_option($item['option'], '');
            if ($url !== '') {
                $url = esc_url_raw($url);
            }
        }
        if ($url === '' && !empty($item['slug'])) {
            $page = get_page_by_path($item['slug']);
            if ($page) {
                $url = get_permalink($page);
            }
        }
        if ($url === '' && !empty($item['slug'])) {
            // Fallback: la pagina puede que no exista todavia, pero el shortcode si.
            // Usamos el slug directamente para que el menu sea consistente.
            $url = home_url('/' . ltrim($item['slug'], '/') . '/');
        }
        if ($url === '') {
            continue;
        }
        $item['url'] = $url;
        $resolved[] = $item;
    }

    return apply_filters('crm_app_shell_menu_items', $resolved);
}

/**
 * Determina si el usuario actual puede ver un item del menú.
 *
 * Reglas estrictas (v1.20.7):
 *  - Si el item declara `roles`, BASTA con que el usuario tenga AL MENOS UNO
 *    de esos roles asignados (intersección con $user->roles). Esto permite
 *    combinaciones como comercial+visitador. NO se mira capabilities (para
 *    evitar bypass por plugins tipo Members que inyectan caps).
 *  - Administradores deben estar en la whitelist explícitamente (lo están).
 */
function crm_app_shell_user_can_see_item(array $item) {
    if (empty($item['roles'])) {
        return true;
    }
    $user = wp_get_current_user();
    if (!$user || !$user->ID) {
        return false;
    }
    $user_roles = (array) $user->roles;
    if (empty($user_roles)) {
        return false;
    }
    return count(array_intersect($user_roles, (array) $item['roles'])) > 0;
}

/**
 * Render de la topbar fija.
 */
function crm_app_shell_render_topbar() {
    $opts = crm_app_shell_get_settings();
    $current_slug = '';
    if (is_singular()) {
        $obj = get_queried_object();
        if ($obj && !empty($obj->post_name)) {
            $current_slug = $obj->post_name;
        }
    }
    $user = wp_get_current_user();
    $iniciales = '';
    if ($user && $user->ID) {
        $display = $user->display_name ?: $user->user_login;
        $iniciales = function_exists('crm_avatar_initials') ? crm_avatar_initials($display) : strtoupper(substr($display, 0, 2));
    }
    $logout_url = wp_logout_url(home_url('/'));
    $brand_label = $opts['brand_label'] !== '' ? $opts['brand_label'] : 'CRM';
    $home_url = home_url('/');

    // Logo del sitio: prioriza site_icon (favicon en Ajustes › General), luego custom_logo.
    $logo_url = function_exists('get_site_icon_url') ? get_site_icon_url(64) : '';
    if (!$logo_url) {
        $custom_logo_id = (int) get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $img = wp_get_attachment_image_src($custom_logo_id, 'thumbnail');
            if ($img) {
                $logo_url = $img[0];
            }
        }
    }

    // v1.20.46: el instalador puro ve la misma topbar, pero con la marca del
    // panel de instalador (Ecovolt hoy) en vez del logo genérico del CRM, y
    // en una variante clara ("aunque blanca") — reutiliza los ajustes que ya
    // existían para la página aparte del instalador (crm_instalador_panel_*).
    $is_instalador = $user && in_array('instalador', (array) $user->roles, true);
    $accent = '';
    if ($is_instalador && function_exists('crm_inst_panel_get_settings')) {
        $inst = crm_inst_panel_get_settings();
        if ($inst['brand'] !== '') {
            $brand_label = $inst['brand'];
        }
        if ($inst['logo'] !== '') {
            $logo_url = $inst['logo'];
        }
        $accent = $inst['color'] !== '' ? $inst['color'] : '#15803d';
        // v1.20.55: el logo debe llevar a "Mis instalaciones", no a la home
        // genérica del sitio — un instalador no tiene nada que hacer ahí.
        $home_url = home_url('/panel-instalador/');
    }
    $topbar_class = 'crm-topbar' . ($is_instalador ? ' crm-topbar--light' : '');
    $topbar_style = $accent !== '' ? ' style="--crm-panel-inst-accent:' . esc_attr($accent) . ';"' : '';

    $icon = function ($name, $size = 16) {
        return function_exists('crm_icon') ? crm_icon($name, $size) : '';
    };
    ?>
    <header class="<?php echo esc_attr($topbar_class); ?>" role="banner"<?php echo $topbar_style; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
        <a href="<?php echo esc_url($home_url); ?>" class="crm-topbar__brand">
            <span class="crm-topbar__logo">
                <?php if ($logo_url): ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="" width="22" height="22" loading="eager" decoding="async">
                <?php else: ?>
                    <?php echo $icon('lightning', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
            </span>
            <span><?php echo esc_html($brand_label); ?></span>
        </a>
        <nav class="crm-topbar__nav" aria-label="Navegación CRM">
            <?php foreach (crm_app_shell_menu_items() as $item):
                if (!crm_app_shell_user_can_see_item($item)) {
                    continue;
                }
                $is_current = ($current_slug === $item['slug']) ? ' is-current' : '';
            ?>
                <a class="crm-topbar__link<?php echo esc_attr($is_current); ?>" href="<?php echo esc_url($item['url']); ?>">
                    <?php echo $icon($item['icon'], 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span><?php echo esc_html($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="crm-topbar__user">
            <?php if ($user && $user->ID):
                $role_label = function_exists('crm_user_role_label') ? crm_user_role_label() : '';
            ?>
                <?php if (function_exists('crm_notificaciones_render_campana')) {
                    echo crm_notificaciones_render_campana(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } ?>
                <span class="crm-topbar__avatar"><?php echo esc_html($iniciales); ?></span>
                <span class="crm-topbar__user-meta">
                    <span class="crm-topbar__user-name"><?php echo esc_html($user->display_name); ?></span>
                    <?php if ($role_label !== ''): ?>
                        <span class="crm-topbar__user-role"><?php echo esc_html($role_label); ?></span>
                    <?php endif; ?>
                </span>
                <a href="<?php echo esc_url($logout_url); ?>" class="crm-topbar__logout" title="Cerrar sesión">
                    <?php echo $icon('sign-out', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </a>
            <?php endif; ?>
        </div>
    </header>
    <?php
}

/**
 * Submenú admin para configurar el App Shell.
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'crm-dashboard',
        'Aspecto / App Shell',
        'Aspecto (App Shell)',
        'crm_admin',
        'crm-app-shell',
        'crm_app_shell_render_admin'
    );
}, 20);

function crm_app_shell_render_admin() {
    if (!current_user_can('crm_admin')) {
        wp_die('Acceso denegado');
    }

    if (isset($_POST['crm_app_shell_save'])) {
        check_admin_referer('crm_app_shell_save');
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $raw_slugs = isset($_POST['slugs']) ? wp_unslash($_POST['slugs']) : '';
        $slugs = array_filter(array_map('sanitize_title', preg_split('/\r?\n/', (string) $raw_slugs)));
        $brand_label = isset($_POST['brand_label']) ? sanitize_text_field(wp_unslash($_POST['brand_label'])) : 'CRM';
        update_option(CRM_APP_SHELL_OPTION, [
            'enabled'     => $enabled,
            'slugs'       => $slugs,
            'brand_label' => $brand_label,
        ]);
        echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
    }

    $opts = crm_app_shell_get_settings();
    ?>
    <div class="wrap">
        <h1>CRM — Aspecto / App Shell</h1>
        <p style="max-width:780px">
            El <strong>App Shell</strong> convierte las páginas del CRM en una interfaz tipo aplicación:
            oculta el header y footer del tema Astra, añade una barra superior propia con el menú del CRM,
            y elimina los márgenes laterales para aprovechar todo el ancho. Sólo se aplica a las páginas
            cuyo <em>slug</em> aparezca en la lista de abajo.
        </p>

        <form method="post">
            <?php wp_nonce_field('crm_app_shell_save'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Activar App Shell</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($opts['enabled'], 1); ?>>
                            Aplicar el shell automáticamente en las páginas listadas abajo
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Etiqueta de marca</th>
                    <td>
                        <input type="text" name="brand_label" value="<?php echo esc_attr($opts['brand_label']); ?>" class="regular-text" placeholder="CRM">
                        <p class="description">Texto que aparece junto al logo en la barra superior.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Slugs de páginas CRM</th>
                    <td>
                        <textarea name="slugs" rows="10" cols="50" class="large-text code"><?php echo esc_textarea(implode("\n", $opts['slugs'])); ?></textarea>
                        <p class="description">
                            Un slug por línea. Estas son las páginas que se mostrarán con el shell.
                            Por defecto incluye: <code>alta-de-cliente</code>, <code>mis-altas-de-cliente</code>,
                            <code>todas-las-altas-de-cliente</code>, <code>resumen</code>, <code>asignar-leads</code>,
                            <code>panel-de-control</code>, <code>editar-cliente</code>.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Guardar configuración', 'primary', 'crm_app_shell_save'); ?>
        </form>

        <h2>¿Cómo funciona?</h2>
        <ol>
            <li>El plugin detecta si la página actual coincide con alguno de los slugs configurados.</li>
            <li>Si coincide, añade <code>&lt;body class="crm-app-mode"&gt;</code>.</li>
            <li>El CSS <code>crm-design-v2.css</code> oculta <code>.site-header</code>, <code>.site-footer</code> y resetea márgenes.</li>
            <li>Se inyecta una barra superior propia con el menú del CRM.</li>
        </ol>
        <p><strong>Si quieres volver al modo Astra normal</strong>, desactiva la casilla "Activar App Shell" y guarda.</p>
    </div>
    <?php
}
