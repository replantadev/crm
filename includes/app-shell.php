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
 * Determina si la página actual es una página CRM (según slug configurado).
 */
function crm_app_shell_is_crm_page() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $opts = crm_app_shell_get_settings();
    if (empty($opts['enabled'])) {
        $cache = false;
        return $cache;
    }
    if (is_admin()) {
        $cache = false;
        return $cache;
    }
    if (!is_singular()) {
        $cache = false;
        return $cache;
    }
    $post = get_queried_object();
    if (!$post || empty($post->post_name)) {
        $cache = false;
        return $cache;
    }
    $cache = in_array($post->post_name, $opts['slugs'], true);
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
 * Lista de items del menú del shell. Filtrable por rol.
 *
 * Cada item: label, slug (fallback), icon, opcional cap, opcional roles,
 * opcional option (option_key del que leer URL custom configurable en admin).
 *
 * Jerarquía por rol (verificada en v1.20.5):
 *  - admin/crm_admin: TODOS los items.
 *  - comercial: Escritorio, Alta, Mis altas, Mis leads, Mi agenda.
 *  - visitador: Escritorio, Mis leads, Mi agenda.
 *
 * @return array<int, array<string,mixed>>
 */
function crm_app_shell_menu_items() {
    $items = [
        [
            'label' => 'Escritorio',
            'slug'  => 'crm',
            'icon'  => 'house',
        ],
        [
            'label' => 'Alta',
            'slug'  => 'alta-de-cliente',
            'icon'  => 'plus',
            'roles' => ['administrator', 'crm_admin', 'comercial'],
        ],
        [
            'label' => 'Mis altas',
            'slug'  => 'mis-altas-de-cliente',
            'icon'  => 'list-bullets',
            'roles' => ['administrator', 'crm_admin', 'comercial'],
        ],
        [
            'label' => 'Todas las altas',
            'slug'  => 'todas-las-altas-de-cliente',
            'icon'  => 'users',
            'cap'   => 'crm_admin',
        ],
        [
            'label' => 'Resumen',
            'slug'  => 'resumen',
            'icon'  => 'chart-bar',
            'cap'   => 'crm_admin',
        ],
        [
            'label' => 'Leads MK',
            'slug'  => 'asignar-leads',
            'icon'  => 'target',
            'cap'   => 'crm_admin',
        ],
        [
            'label'  => 'Mis leads',
            'slug'   => 'mis-leads',
            'option' => 'crm_url_mis_leads',
            'icon'   => 'target',
            'roles'  => ['comercial', 'visitador'],
        ],
        [
            'label'  => 'Mi agenda',
            'slug'   => 'mi-agenda',
            'option' => 'crm_url_mi_agenda',
            'icon'   => 'calendar',
            'roles'  => ['administrator', 'crm_admin', 'comercial', 'visitador'],
        ],
        [
            'label' => 'Panel',
            'slug'  => 'panel-de-control',
            'icon'  => 'gear',
            'cap'   => 'crm_admin',
        ],
    ];

    // Resolver URLs: prioridad option > get_page_by_path(slug) > ocultar.
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
        if ($url === '') {
            continue; // No mostramos enlaces rotos.
        }
        $item['url'] = $url;
        $resolved[] = $item;
    }

    return apply_filters('crm_app_shell_menu_items', $resolved);
}

/**
 * Determina si el usuario actual puede ver un item del menú.
 *
 * Reglas:
 *  - Si el item tiene `cap` y el usuario no la tiene → false.
 *  - Si el item tiene `roles` y el usuario es admin/crm_admin → true (los admins lo ven todo).
 *  - Si el item tiene `roles` y el usuario tiene al menos uno de esos roles → true.
 *  - Si el item NO tiene `cap` ni `roles` → visible (item público para todos los logueados).
 */
function crm_app_shell_user_can_see_item(array $item) {
    if (!empty($item['cap']) && !current_user_can($item['cap'])) {
        return false;
    }
    if (!empty($item['roles'])) {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return false;
        }
        $user_roles = (array) $user->roles;
        if (in_array('administrator', $user_roles, true) || in_array('crm_admin', $user_roles, true)) {
            return true; // admins lo ven todo.
        }
        if (!array_intersect($user_roles, (array) $item['roles'])) {
            return false;
        }
    }
    return true;
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

    $icon = function ($name, $size = 16) {
        return function_exists('crm_icon') ? crm_icon($name, $size) : '';
    };
    ?>
    <header class="crm-topbar" role="banner">
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
            <?php if ($user && $user->ID): ?>
                <span class="crm-topbar__avatar"><?php echo esc_html($iniciales); ?></span>
                <span class="crm-topbar__user-name"><?php echo esc_html($user->display_name); ?></span>
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
