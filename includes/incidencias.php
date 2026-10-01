<?php
/**
 * "Incidencias" como concepto propio en una instalación (2026-10-01).
 *
 * Hueco real del presupuesto original: hasta ahora un problema en una
 * instalación (material dañado, acceso imposible, cliente no está, lo que
 * sea) solo se podía anotar como nota/log genérico, mezclado con todo lo
 * demás, sin ciclo de vida ni aviso dedicado.
 *
 * Decisiones confirmadas con el usuario (2026-10-01):
 * - La puede abrir tanto el instalador (desde el panel de campo, igual que
 *   ya declara partidas extra o el cierre) como el jefe/crm_admin (desde la
 *   ficha) — una incidencia no siempre se detecta desde el terreno.
 * - Ciclo de vida: abierta → en_curso → resuelta (3 estados, no solo 2).
 * - Es puramente informativa por ahora: NO cambia el estado de la
 *   instalación ni bloquea nada (ni el cierre, ni el checklist). El estado
 *   "Bloqueada" que ya existe en el ENUM de la instalación se deja fuera a
 *   propósito — no hay ninguna lógica automática que lo dispare.
 *
 * Mismo patrón que "partida extra" (crm_inst_ajax_declarar_extra() /
 * crm_inst_ajax_validar_extra(), includes/instalaciones.php): declarar →
 * notificar in-app a jefes (o al instalador, si quien declara es el jefe) →
 * resolver → notificar in-app + email de vuelta a quien declaró.
 *
 * @package CRM_Energitel
 * @since 1.20.184
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * true si el usuario actual puede actuar sobre incidencias de esta
 * instalación: jefe/crm_admin (cualquiera), o un instalador asignado a ella.
 * Mismo criterio que ya usa crm_inst_ajax_declarar_extra().
 */
function crm_inst_incidencia_usuario_puede($instalacion_id) {
    if (crm_inst_current_user_can_manage()) {
        return true;
    }
    if (!current_user_can('crm_inst_edit_own')) {
        return false;
    }
    global $wpdb;
    $asignado = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
        (int) $instalacion_id, get_current_user_id()
    ));
    return $asignado > 0;
}

/**
 * Abre una incidencia nueva — desde el panel del instalador o desde la
 * ficha (jefe/crm_admin). Si la abre un instalador, avisa a los jefes; si la
 * abre un jefe/crm_admin, avisa a los instaladores asignados a esa
 * instalación (para que se enteren en el terreno de algo detectado desde
 * oficina).
 */
add_action('wp_ajax_crm_inst_declarar_incidencia', 'crm_inst_ajax_declarar_incidencia');
function crm_inst_ajax_declarar_incidencia() {
    if (!is_user_logged_in() || !check_ajax_referer('crm_inst_holded', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $instalacion_id = (int) ($_POST['instalacion_id'] ?? 0);
    $titulo         = sanitize_text_field(wp_unslash($_POST['titulo'] ?? ''));
    $descripcion    = sanitize_textarea_field(wp_unslash($_POST['descripcion'] ?? ''));

    if ($instalacion_id <= 0 || $titulo === '') {
        wp_send_json_error(['message' => 'Escribe al menos un título.']);
    }
    if (!crm_inst_incidencia_usuario_puede($instalacion_id)) {
        wp_send_json_error(['message' => 'No tienes acceso a esta instalación.'], 403);
    }

    $foto_ruta = '';
    if (!empty($_FILES['foto']['name']) && function_exists('crm_handle_secure_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $subida = crm_handle_secure_upload($_FILES['foto'], 'incidencia');
        if (is_wp_error($subida)) {
            wp_send_json_error(['message' => 'No se pudo subir la foto: ' . $subida->get_error_message()]);
        }
        $foto_ruta = $subida['url'];
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $wpdb->insert(crm_inst_table_incidencias(), [
        'instalacion_id' => $instalacion_id,
        'titulo'         => $titulo,
        'descripcion'    => $descripcion !== '' ? $descripcion : null,
        'foto_ruta'      => $foto_ruta !== '' ? $foto_ruta : null,
        'estado'         => 'abierta',
        'declarado_por'  => $user_id,
        'declarado_en'   => current_time('mysql'),
    ]);
    $incidencia_id = (int) $wpdb->insert_id;

    crm_inst_log_action($instalacion_id, 'incidencia', 'incidencia_abierta', 'Incidencia abierta: "' . $titulo . '".');

    $url_ficha  = add_query_arg('id', $instalacion_id, home_url('/instalacion/'));
    $es_manager = crm_inst_current_user_can_manage();

    if (!$es_manager) {
        // La abrió un instalador — avisa a jefes, mismo criterio que
        // partida extra/cierre.
        if (function_exists('crm_notificar_jefes_instalaciones')) {
            $cliente_nombre = $wpdb->get_var($wpdb->prepare(
                "SELECT c.cliente_nombre FROM " . crm_inst_table_instalaciones() . " i LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id WHERE i.id = %d",
                $instalacion_id
            ));
            crm_notificar_jefes_instalaciones(
                'incidencia_abierta',
                'Nueva incidencia: "' . $titulo . '" en ' . ($cliente_nombre ?: ('instalación ' . crm_inst_id_visible($instalacion_id))),
                $url_ficha
            );
        }
    } else {
        // La abrió un jefe/crm_admin — avisa a los instaladores asignados.
        $instaladores = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d",
            $instalacion_id
        ));
        foreach ($instaladores as $instalador_id) {
            if (function_exists('crm_notificar')) {
                crm_notificar((int) $instalador_id, 'incidencia_abierta', 'Se abrió una incidencia: "' . $titulo . '".', home_url('/panel-instalador/'));
            }
        }
    }

    wp_send_json_success(['id' => $incidencia_id, 'estado' => 'abierta']);
}

/**
 * Cambia el estado de una incidencia (abierta → en_curso → resuelta) o
 * reabre una ya resuelta. Puede hacerlo quien la declaró (si sigue teniendo
 * acceso a la instalación) o cualquier jefe/crm_admin — una incidencia no es
 * una aprobación de otra persona, cualquiera de los dos lados puede
 * actualizarla. Pasar a 'resuelta' exige una nota de qué se hizo.
 */
add_action('wp_ajax_crm_inst_actualizar_incidencia', 'crm_inst_ajax_actualizar_incidencia');
function crm_inst_ajax_actualizar_incidencia() {
    if (!is_user_logged_in() || !check_ajax_referer('crm_inst_holded', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $incidencia_id = (int) ($_POST['incidencia_id'] ?? 0);
    $nuevo_estado  = sanitize_key(wp_unslash($_POST['estado'] ?? ''));
    $resolucion    = sanitize_textarea_field(wp_unslash($_POST['resolucion'] ?? ''));

    if ($incidencia_id <= 0 || !in_array($nuevo_estado, ['abierta', 'en_curso', 'resuelta'], true)) {
        wp_send_json_error(['message' => 'Datos no válidos.']);
    }

    global $wpdb;
    $incidencia = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . crm_inst_table_incidencias() . " WHERE id = %d",
        $incidencia_id
    ), ARRAY_A);
    if (!$incidencia) {
        wp_send_json_error(['message' => 'Incidencia no encontrada.']);
    }
    if (!crm_inst_incidencia_usuario_puede((int) $incidencia['instalacion_id'])) {
        wp_send_json_error(['message' => 'No tienes acceso a esta instalación.'], 403);
    }
    if ($nuevo_estado === 'resuelta' && $resolucion === '') {
        wp_send_json_error(['message' => 'Escribe qué se hizo para resolverla.']);
    }

    $update = ['estado' => $nuevo_estado];
    if ($nuevo_estado === 'resuelta') {
        $update['resuelta_por'] = get_current_user_id();
        $update['resuelta_en']  = current_time('mysql');
        $update['resolucion']   = $resolucion;
    } else {
        // Reabrir o volver a "en_curso" limpia el cierre anterior, si lo
        // hubiera (p.ej. se reabrió porque no quedó bien resuelta).
        $update['resuelta_por'] = null;
        $update['resuelta_en']  = null;
    }
    $wpdb->update(crm_inst_table_incidencias(), $update, ['id' => $incidencia_id]);

    $instalacion_id = (int) $incidencia['instalacion_id'];
    crm_inst_log_action(
        $instalacion_id, 'incidencia', 'incidencia_' . $nuevo_estado,
        'Incidencia "' . $incidencia['titulo'] . '" pasó a "' . $nuevo_estado . '"' . ($resolucion !== '' ? ': ' . $resolucion : '') . '.'
    );

    // Avisa a quien la declaró, si fue otra persona la que acaba de
    // actualizarla (igual que partida extra: quien la abrió quiere saber
    // qué pasó con ella).
    $declarante_id = (int) $incidencia['declarado_por'];
    if ($declarante_id > 0 && $declarante_id !== get_current_user_id()) {
        $mensaje = 'Tu incidencia "' . $incidencia['titulo'] . '" pasó a "' . $nuevo_estado . '".';
        if (function_exists('crm_notificar')) {
            crm_notificar($declarante_id, 'incidencia_' . $nuevo_estado, $mensaje, home_url('/panel-instalador/'));
        }
        if ($nuevo_estado === 'resuelta' && function_exists('crm_inst_email_instalador')) {
            crm_inst_email_instalador($declarante_id, 'Incidencia resuelta', $mensaje . ' ' . $resolucion, home_url('/panel-instalador/'));
        }
    }

    wp_send_json_success(['id' => $incidencia_id, 'estado' => $nuevo_estado]);
}
