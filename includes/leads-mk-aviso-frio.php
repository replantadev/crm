<?php
/**
 * Agente comercial, Fase 1 · aviso de "lead frío" (2026-10-01).
 *
 * Hasta ahora `lead_mk_touched_at` (includes/leads-mk-shortcode.php) se
 * escribía en cada asignación/reseteo pero nadie lo leía — un lead asignado
 * podía quedarse sin tocar indefinidamente sin que nadie se enterase. Mismo
 * patrón que el aviso de "presupuesto estancado" (includes/ventas.php,
 * v1.20.127): cron por hora, umbral configurable, dedup por option para
 * avisar una sola vez por lead y por fase (aviso al comercial / escalado a
 * crm_admin).
 *
 * "Sin tocar" se mide desde `actualizado_en`: al asignar, el propio AJAX de
 * asignación (`crm_lead_assign_ajax`) ya pone `actualizado_en` a la fecha de
 * asignación y resetea `lead_mk_touched_at` a NULL — así que para un lead
 * "asignado" que todavía no se ha trabajado, `actualizado_en` SÍ representa
 * la última vez que pasó algo con él (nada más lo toca hasta que el
 * comercial abre y guarda su ficha, que es cuando se marca "trabajado").
 *
 * @package CRM_Energitel
 * @since 1.20.173
 */

if (!defined('ABSPATH')) {
    exit;
}

function crm_leads_mk_aviso_frio_schedule_cron() {
    if (!wp_next_scheduled('crm_leads_mk_aviso_frio_cron_hourly')) {
        wp_schedule_event(time() + 900, 'hourly', 'crm_leads_mk_aviso_frio_cron_hourly');
    }
}
add_action('init', function () {
    if (!wp_doing_cron() && !wp_next_scheduled('crm_leads_mk_aviso_frio_cron_hourly')) {
        crm_leads_mk_aviso_frio_schedule_cron();
    }
}, 20);

add_action('crm_leads_mk_aviso_frio_cron_hourly', 'crm_leads_mk_aviso_frio_run');
/**
 * Recorre los leads MK asignados y sin tocar, avisa al comercial dueño al
 * pasar el primer umbral y escala a crm_admin al pasar el doble — cada caso
 * una sola vez (dedup vía option, igual que crm_ventas_aviso_estancados_run()).
 */
function crm_leads_mk_aviso_frio_run() {
    if (!function_exists('crm_leads_mk_origenes_in_sql')) {
        return;
    }

    $horas_aviso    = max(1, (int) get_option('crm_leads_mk_aviso_frio_horas', 48));
    $horas_escalado = max($horas_aviso + 1, (int) get_option('crm_leads_mk_aviso_frio_escalar_horas', $horas_aviso * 2));

    global $wpdb;
    $table    = $wpdb->prefix . 'crm_clients';
    $origenes = crm_leads_mk_origenes_in_sql();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, cliente_nombre, user_id, delegado, actualizado_en
         FROM {$table}
         WHERE {$origenes['sql']}
           AND user_id > 0
           AND (lead_mk_status IS NULL OR lead_mk_status != 'trabajado')
           AND lead_mk_touched_at IS NULL
           AND actualizado_en IS NOT NULL",
        $origenes['args']
    ), ARRAY_A);

    // Importante: NO se devuelve aquí si $rows viene vacío — hay que seguir
    // hasta la limpieza de más abajo, que es precisamente la que borra del
    // option los leads que ya no cumplen la consulta (se trabajaron, se
    // reasignaron...). Devolver antes dejaría el option creciendo para
    // siempre con leads ya irrelevantes.
    $rows = (array) $rows;

    $estado = get_option('crm_leads_mk_frio_avisados', []);
    if (!is_array($estado)) {
        $estado = [];
    }

    $ahora = current_time('timestamp');
    foreach ($rows as $row) {
        $lead_id      = (int) $row['id'];
        $asignado_ts  = strtotime((string) $row['actualizado_en']);
        if ($asignado_ts === false) {
            continue;
        }
        $horas_sin_tocar = ($ahora - $asignado_ts) / HOUR_IN_SECONDS;
        if ($horas_sin_tocar < $horas_aviso) {
            continue;
        }

        $info = $estado[$lead_id] ?? [];
        $url  = add_query_arg('client_id', $lead_id, home_url('/alta-de-cliente/'));

        if (empty($info['avisado'])) {
            $comercial = get_userdata((int) $row['user_id']);
            if ($comercial) {
                $mensaje = 'El lead ' . $row['cliente_nombre'] . ' lleva más de ' . $horas_aviso . ' horas asignado sin que lo hayas tocado.';
                if (function_exists('crm_notificar')) {
                    crm_notificar((int) $row['user_id'], 'lead_mk_frio', $mensaje, $url);
                }
                if (!empty($comercial->user_email)) {
                    $body = '<p>' . esc_html($mensaje) . '</p><p><a href="' . esc_url($url) . '">Ver lead</a></p>'
                        . '<p style="color:#666;font-size:12px">Aviso automático del CRM.</p>';
                    wp_mail($comercial->user_email, '[CRM] Lead sin tocar', $body, ['Content-Type: text/html; charset=UTF-8']);
                }
                if (function_exists('crm_log_action')) {
                    crm_log_action('lead_mk_frio_avisado', $mensaje . ' Avisado: ' . $comercial->display_name . '.', $lead_id, 0, 'info');
                }
            }
            $info['avisado'] = current_time('mysql');
        }

        if (empty($info['escalado']) && $horas_sin_tocar >= $horas_escalado) {
            $jefes = get_users(['role' => 'crm_admin', 'fields' => 'ID']);
            $mensaje_escalado = 'El lead ' . $row['cliente_nombre'] . ' (asignado a ' . ($row['delegado'] ?: 'sin nombre') . ') lleva más de ' . $horas_escalado . ' horas sin tocarse.';
            foreach ($jefes as $jefe_id) {
                if (function_exists('crm_notificar')) {
                    crm_notificar((int) $jefe_id, 'lead_mk_frio_escalado', $mensaje_escalado, $url);
                }
            }
            if (function_exists('crm_log_action')) {
                crm_log_action('lead_mk_frio_escalado', $mensaje_escalado, $lead_id, 0, 'notice');
            }
            $info['escalado'] = current_time('mysql');
        }

        $estado[$lead_id] = $info;
    }

    // Limpieza: fuera los leads que ya no cumplen la consulta (se trabajaron,
    // se reasignaron o se pasaron a frío) — si no, el option crecería para
    // siempre con leads que ya no son relevantes.
    $ids_actuales = array_map(function ($r) { return (int) $r['id']; }, $rows);
    foreach (array_keys($estado) as $lead_id) {
        if (!in_array((int) $lead_id, $ids_actuales, true)) {
            unset($estado[$lead_id]);
        }
    }

    update_option('crm_leads_mk_frio_avisados', $estado, false);
}
