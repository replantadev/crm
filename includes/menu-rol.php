<?php
/**
 * Menú propio del CRM por rol y desactivación del menú nativo de Astra.
 *
 * Comportamiento:
 *  - Cuando un usuario logueado con rol CRM (crm_admin, comercial, visitador)
 *    navega por el frontend, se inyecta una barra propia bajo el header con
 *    los enlaces relevantes para su rol.
 *  - Se ocultan vía CSS el menú principal de Astra y los toggles móviles,
 *    para evitar duplicar navegación.
 *  - Los usuarios sin rol CRM y los anónimos ven el sitio como siempre.
 *  - El administrator técnico (operativa) verá el menú admin del CRM.
 *
 * Las URLs se leen de los settings ya existentes; las que falten se omiten
 * (el menú no muestra enlaces rotos).
 *
 * @package CRM
 * @since 1.20.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Devuelve las URLs configuradas en settings (algunas con default razonable).
 */
function crm_nav_get_urls() {
    return [
        'escritorio'  => trim((string) get_option('crm_url_escritorio', home_url('/crm/'))),
        'panel_admin' => trim((string) get_option('crm_url_panel_admin', '')),
        'panel_comer' => trim((string) get_option('crm_url_panel_comercial', '')),
        'mi_agenda'   => trim((string) get_option('crm_url_mi_agenda', '')),
        'mis_leads'   => trim((string) get_option('crm_url_mis_leads', '')),
        'leads_mk'    => trim((string) get_option('crm_url_leads_mk_admin', '')),
        'todas_altas' => trim((string) get_option('crm_url_altas_admin', home_url('/todas-las-altas-de-cliente/'))),
        'nueva_alta'  => trim((string) get_option('crm_url_nueva_alta', home_url('/alta-de-cliente/'))),
        'resumen'     => trim((string) get_option('crm_url_resumen', home_url('/resumen/'))),
    ];
}

/**
 * Devuelve los items de menú para el usuario actual, ya filtrados por rol.
 * Cada item: ['label' => '…', 'url' => '…'].
 */
function crm_nav_items_for_current_user() {
    if (!is_user_logged_in()) {
        return [];
    }
    $u = wp_get_current_user();
    $roles = (array) $u->roles;
    $is_admin = in_array('administrator', $roles, true) || in_array('crm_admin', $roles, true);
    $is_comercial = in_array('comercial', $roles, true);
    $is_visitador = in_array('visitador', $roles, true);

    if (!$is_admin && !$is_comercial && !$is_visitador) {
        return [];
    }

    $u_urls = crm_nav_get_urls();
    $items = [];

    // Escritorio (página /crm) — para todos los roles CRM.
    if ($u_urls['escritorio']) {
        $items[] = ['label' => 'Escritorio', 'url' => $u_urls['escritorio']];
    }

    if ($is_admin) {
        if ($u_urls['panel_admin']) {
            $items[] = ['label' => 'Panel admin', 'url' => $u_urls['panel_admin']];
        }
        if ($u_urls['todas_altas']) {
            $items[] = ['label' => 'Altas', 'url' => $u_urls['todas_altas']];
        }
        if ($u_urls['leads_mk']) {
            $items[] = ['label' => 'Leads MK', 'url' => $u_urls['leads_mk']];
        }
        if ($u_urls['resumen']) {
            $items[] = ['label' => 'Resumen', 'url' => $u_urls['resumen']];
        }
        if ($u_urls['mi_agenda']) {
            $items[] = ['label' => 'Agenda', 'url' => $u_urls['mi_agenda']];
        }
    } elseif ($is_comercial) {
        if ($u_urls['panel_comer']) {
            $items[] = ['label' => 'Mis altas', 'url' => $u_urls['panel_comer']];
        }
        if ($u_urls['mis_leads']) {
            $items[] = ['label' => 'Mis leads', 'url' => $u_urls['mis_leads']];
        }
        if ($u_urls['mi_agenda']) {
            $items[] = ['label' => 'Mi agenda', 'url' => $u_urls['mi_agenda']];
        }
        if ($u_urls['nueva_alta']) {
            $items[] = ['label' => 'Nueva alta', 'url' => $u_urls['nueva_alta']];
        }
    } elseif ($is_visitador) {
        if ($u_urls['mi_agenda']) {
            $items[] = ['label' => 'Mi agenda', 'url' => $u_urls['mi_agenda']];
        }
        if ($u_urls['mis_leads']) {
            $items[] = ['label' => 'Mis leads', 'url' => $u_urls['mis_leads']];
        }
    }

    // Cerrar sesión siempre, al final.
    $items[] = [
        'label' => 'Cerrar sesión',
        'url'   => wp_logout_url(home_url('/')),
        'class' => 'crm-nav-logout',
    ];

    return $items;
}

/**
 * Renderiza la barra de navegación CRM. Se inserta vía wp_body_open.
 */
function crm_nav_render_bar() {
    // Excluir admin, AJAX, login, dentro del iframe modal de agenda, etc.
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (!empty($_GET['crm_modal'])) {
        // El modal del shortcode [crm_agenda_modal] no debe llevar nav.
        return;
    }
    $items = crm_nav_items_for_current_user();
    if (empty($items)) {
        return;
    }

    $current_url = home_url(add_query_arg(null, null));
    ?>
    <nav class="crm-nav-bar" aria-label="Navegación CRM">
        <div class="crm-nav-inner">
            <?php $favicon = function_exists('get_site_icon_url') ? get_site_icon_url(48) : ''; ?>
            <?php if ($favicon): ?>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="crm-nav-brand" aria-label="Inicio">
                    <img src="<?php echo esc_url($favicon); ?>" alt="" width="22" height="22">
                </a>
            <?php endif; ?>
            <ul class="crm-nav-list">
                <?php foreach ($items as $item):
                    $is_current = !empty($item['url']) && strpos($current_url, untrailingslashit($item['url'])) === 0;
                    $cls = 'crm-nav-item';
                    if (!empty($item['class'])) { $cls .= ' ' . $item['class']; }
                    if ($is_current) { $cls .= ' is-current'; }
                ?>
                    <li class="<?php echo esc_attr($cls); ?>">
                        <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['label']); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <span class="crm-nav-user">
                <?php echo esc_html(wp_get_current_user()->display_name); ?>
            </span>
        </div>
    </nav>
    <?php
}
add_action('wp_body_open', 'crm_nav_render_bar', 5);

/**
 * Estilos del menú. Inline para evitar dependencias.
 * Oculta menú principal de Astra cuando hay menú CRM para el usuario.
 */
add_action('wp_head', 'crm_nav_inline_styles', 99);
function crm_nav_inline_styles() {
    if (is_admin()) {
        return;
    }
    if (!empty($_GET['crm_modal'])) {
        return;
    }
    $items = crm_nav_items_for_current_user();
    if (empty($items)) {
        return;
    }
    ?>
    <style id="crm-nav-bar-styles">
        /* Ocultar menú nativo Astra para usuarios CRM */
        body.logged-in .main-header-bar-navigation,
        body.logged-in #ast-desktop-header .main-header-menu,
        body.logged-in .ast-mobile-menu-buttons,
        body.logged-in .ast-button-wrap .menu-toggle,
        body.logged-in #ast-mobile-header .main-header-bar-navigation { display: none !important; }

        .crm-nav-bar {
            background: #0f172a;
            color: #f8fafc;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: 0 1px 4px rgba(0,0,0,0.12);
        }
        .crm-nav-inner {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 8px 18px;
        }
        .crm-nav-brand { display: inline-flex; }
        .crm-nav-brand img { display: block; border-radius: 4px; }
        .crm-nav-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            gap: 4px;
            flex: 1;
            flex-wrap: wrap;
        }
        .crm-nav-item a {
            display: inline-block;
            padding: 6px 14px;
            color: #f8fafc;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .crm-nav-item a:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .crm-nav-item.is-current a { background: #1e40af; color: #fff; }
        .crm-nav-item.crm-nav-logout a {
            color: #fca5a5;
            font-weight: 400;
        }
        .crm-nav-item.crm-nav-logout a:hover { background: rgba(239,68,68,0.15); color: #fff; }
        .crm-nav-user {
            opacity: 0.7;
            font-size: 13px;
        }
        @media (max-width: 640px) {
            .crm-nav-user { display: none; }
            .crm-nav-item a { padding: 6px 10px; font-size: 13px; }
        }
    </style>
    <?php
}

/* =========================================================================
 * Registro de los settings nuevos de URL (panel admin del CRM)
 * ========================================================================= */

add_action('admin_init', 'crm_nav_register_settings', 20);
function crm_nav_register_settings() {
    $keys = [
        'crm_url_escritorio',
        'crm_url_mis_leads',
        'crm_url_leads_mk_admin',
        'crm_url_altas_admin',
        'crm_url_nueva_alta',
        'crm_url_resumen',
    ];
    foreach ($keys as $key) {
        register_setting('crm_settings', $key, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);
    }
}
