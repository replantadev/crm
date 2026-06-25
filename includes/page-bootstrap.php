<?php
/**
 * CRM - Bootstrap de páginas requeridas por el plugin.
 *
 * Crea automáticamente las páginas necesarias (Alta, Mis altas, Todas las altas,
 * Resumen, Asignar Leads, Panel, Editar Cliente, Mi agenda) si no existen.
 *
 * Se ejecuta en activación del plugin y como upgrade routine en admin_init
 * (controlado por la option `crm_pages_bootstrap_version`).
 *
 * Diseño defensivo:
 *  - Si ya existe una página publicada con el slug, NO se toca (idempotente).
 *  - Si existe en estado distinto (trash, draft), NO se restaura ni se duplica.
 *  - Sólo se ejecuta para users con `manage_options`/`crm_admin` o desde activación.
 *
 * @since 1.20.8
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lista de páginas que el plugin necesita. Filtrable.
 *
 * @return array<int,array{slug:string,title:string,content:string}>
 */
function crm_required_pages() {
    $pages = [
        [
            'slug'    => 'alta-de-cliente',
            'title'   => 'Alta de cliente',
            'content' => '[crm_alta_cliente]',
        ],
        [
            'slug'    => 'mis-altas-de-cliente',
            'title'   => 'Mis altas',
            'content' => '[crm_lista_altas]',
        ],
        [
            'slug'    => 'todas-las-altas-de-cliente',
            'title'   => 'Todas las altas',
            'content' => '[todas_las_altas]',
        ],
        [
            'slug'    => 'editar-cliente',
            'title'   => 'Editar cliente',
            'content' => '[crm_editar_cliente]',
        ],
        [
            'slug'    => 'resumen',
            'title'   => 'Resumen',
            'content' => "[crm_rendimiento_comercial]\n[crm_comerciales_estadisticas]\n[crm_clientes_recientes]\n[crm_clientes_por_estado]\n[crm_clientes_por_interes]",
        ],
        [
            'slug'    => 'asignar-leads',
            'title'   => 'Asignar leads',
            'content' => '[asignacion_leads_mk]',
        ],
        [
            'slug'    => 'panel-de-control',
            'title'   => 'Panel',
            'content' => '[crm_admin_panel]',
        ],
        [
            'slug'    => 'mi-agenda',
            'title'   => 'Mi agenda',
            'content' => '[crm_mi_agenda]',
        ],
    ];
    return apply_filters('crm_required_pages', $pages);
}

/**
 * Crea las páginas que no existan (idempotente).
 *
 * @return array<string,int> Mapa slug => post_id (0 si ya existía o falló).
 */
function crm_bootstrap_pages() {
    $results = [];
    foreach (crm_required_pages() as $page) {
        $slug  = sanitize_title($page['slug']);
        $title = $page['title'];
        $body  = $page['content'];

        // Si ya existe una página con ese slug (en cualquier estado), no hacer nada.
        $existing = get_page_by_path($slug, OBJECT, 'page');
        if ($existing) {
            $results[$slug] = 0;
            continue;
        }

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $body,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if (is_wp_error($post_id)) {
            error_log('[CRM] No se pudo crear pagina ' . $slug . ': ' . $post_id->get_error_message());
            $results[$slug] = 0;
        } else {
            $results[$slug] = (int) $post_id;
        }
    }
    return $results;
}

/**
 * Upgrade routine: ejecuta el bootstrap una vez por versión.
 * No bloquea ni hace nada caro si ya se ejecutó.
 */
function crm_pages_bootstrap_maybe_run() {
    if (!is_admin()) {
        return;
    }
    if (!current_user_can('manage_options') && !current_user_can('crm_admin')) {
        return;
    }
    $done_version = (string) get_option('crm_pages_bootstrap_version', '');
    $target       = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '1.20.8';
    if ($done_version === $target) {
        return;
    }
    crm_bootstrap_pages();
    update_option('crm_pages_bootstrap_version', $target, false);
}
add_action('admin_init', 'crm_pages_bootstrap_maybe_run', 20);
