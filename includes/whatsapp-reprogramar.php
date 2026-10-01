<?php
/**
 * Reprogramado de cita por WhatsApp con franjas concretas (2026-10-01).
 *
 * Hueco real del presupuesto original del módulo: hasta ahora, cuando el
 * cliente respondía "Necesito cambiar" a la plantilla de confirmación de
 * cita, el CRM solo avisaba al jefe para que le llamara y reagendara a
 * mano (ver crm_whatsapp_webhook_procesar_mensaje(), includes/whatsapp-api.php)
 * — el cliente no podía fijar él mismo una fecha/hora concreta.
 *
 * Decisión de diseño (no es capricho, es una limitación real de la API de
 * WhatsApp Business): un mensaje de plantilla (`type: template`) no puede
 * llevar botones con TEXTO dinámico — el texto de cada botón de respuesta
 * rápida se fija al crear y aprobar la plantilla en Meta Business Manager,
 * no se puede variar por envío. Por eso aquí el cuerpo del mensaje lista las
 * franjas en texto ("1) Martes 10:00 · 2) Miércoles 10:00 · 3) Miércoles
 * 16:00"), los 3 botones de la plantilla son fijos ("Opción 1"/"Opción
 * 2"/"Opción 3" con payload opcion_1/opcion_2/opcion_3), y aquí se recuerda
 * QUÉ fecha concreta representa cada "Opción N" para ESTA cita en un
 * transient (vive 3 días, tiempo de sobra para que el cliente responda).
 *
 * Franjas candidatas: próximos días laborables (L-V) a las 10:00 y las
 * 16:00, descartando cualquiera que choque con otra cita ya agendada del
 * MISMO instalador (no existía ningún chequeo de solape para instaladores
 * hasta ahora — crm_instalacion_agenda no lo tenía, a diferencia de
 * crm_visitas que sí lo tiene para comerciales, ver
 * crm_visita_check_conflicts() en includes/visitas.php). No hay duración
 * guardada por cita, así que se asume una duración fija razonable
 * (CRM_INST_DURACION_VISITA_HORAS) para el chequeo de solape.
 *
 * @package CRM_Energitel
 * @since 1.20.177
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CRM_INST_DURACION_VISITA_HORAS')) {
    define('CRM_INST_DURACION_VISITA_HORAS', 2);
}

/**
 * Hasta $num_franjas fechas/horas candidatas para un instalador, en días
 * laborables (lunes a viernes) a las 10:00 y 16:00, empezando al día
 * siguiente de $desde_ts, descartando las que choquen con otra cita ya
 * agendada de ESE instalador (y opcionalmente con una fecha ya propuesta
 * que no se quiere repetir, vía $excluir_fechas_mysql).
 *
 * @param int         $instalador_id
 * @param int         $num_franjas
 * @param int|null    $desde_ts            Timestamp de referencia (por defecto, ahora).
 * @param string[]    $excluir_fechas_mysql Fechas 'Y-m-d H:i:s' a no ofrecer (p.ej. la cita actual).
 * @return string[] Fechas candidatas en formato 'Y-m-d H:i:s', orden cronológico.
 */
function crm_inst_franjas_disponibles_instalador($instalador_id, $num_franjas = 3, $desde_ts = null, array $excluir_fechas_mysql = []) {
    $instalador_id = (int) $instalador_id;
    $num_franjas   = max(1, (int) $num_franjas);
    $desde_ts      = $desde_ts !== null ? (int) $desde_ts : current_time('timestamp');

    global $wpdb;
    $ocupadas_ts = [];
    if ($instalador_id > 0) {
        $ocupadas = $wpdb->get_col($wpdb->prepare(
            "SELECT fecha_cita FROM " . crm_inst_table_agenda() . " WHERE instalador_id = %d",
            $instalador_id
        ));
        foreach ((array) $ocupadas as $f) {
            $ts = strtotime((string) $f);
            if ($ts !== false) {
                $ocupadas_ts[] = $ts;
            }
        }
    }

    $excluir_ts = [];
    foreach ($excluir_fechas_mysql as $f) {
        $ts = strtotime((string) $f);
        if ($ts !== false) {
            $excluir_ts[] = $ts;
        }
    }

    $duracion_seg = CRM_INST_DURACION_VISITA_HORAS * HOUR_IN_SECONDS;
    $candidatas   = [];

    // Empieza mañana a las 00:00 del día de referencia y recorre días
    // laborables; límite de 30 días para no quedarse buscando para siempre
    // si el instalador está completamente saturado.
    $dia_ts = strtotime('tomorrow', $desde_ts);
    for ($dia = 0; $dia < 30 && count($candidatas) < $num_franjas; $dia++, $dia_ts = strtotime('+1 day', $dia_ts)) {
        $dia_semana = (int) date('N', $dia_ts); // 1=lunes ... 7=domingo
        if ($dia_semana >= 6) {
            continue; // fin de semana
        }
        foreach ([10, 16] as $hora) {
            if (count($candidatas) >= $num_franjas) {
                break;
            }
            $candidato_ts = $dia_ts + ($hora * HOUR_IN_SECONDS);

            $choca = false;
            foreach (array_merge($ocupadas_ts, $excluir_ts) as $ocupado_ts) {
                if (abs($ocupado_ts - $candidato_ts) < $duracion_seg) {
                    $choca = true;
                    break;
                }
            }
            if ($choca) {
                continue;
            }
            $candidatas[] = date('Y-m-d H:i:s', $candidato_ts);
        }
    }

    return $candidatas;
}

/**
 * Aplica la franja elegida: actualiza SOLO la fila de agenda concreta a la
 * que el cliente está respondiendo (no las de otros instaladores de la
 * misma instalación, si los hubiera — el cliente está reprogramando SU
 * cita, no la de todo el mundo). Vuelve a 'pendiente' y resetea
 * `recordatorio_enviado_en` para que el recordatorio del día antes
 * (crm_inst_aviso_calendario_run()) la mande a confirmar de nuevo con
 * normalidad para la fecha nueva — sin este reseteo no volvería a avisar a
 * nadie (mismo bug real que se encontró y arregló en
 * crm_inst_ajax_guardar_agenda(), v1.20.178).
 *
 * Además, avisa YA (sin esperar al día antes) al instalador y a los jefes
 * — mismo trío de canales que usa el reagendado manual desde la ficha
 * (crm_inst_ajax_guardar_agenda()): in-app + email + WhatsApp (reutilizando
 * la plantilla ya existente `crm_whatsapp_template_instalador_visita`, no
 * hace falta una plantilla nueva para esto). También actualiza
 * `cierre_previsto` si todavía no se había fijado a mano, igual que el
 * reagendado manual.
 *
 * @param int    $agenda_id
 * @param string $fecha_mysql
 * @param array  $contexto {instalacion_id:int, instalador_id:int, cliente_nombre:string}
 * @return bool
 */
function crm_inst_reprogramar_cita_agenda($agenda_id, $fecha_mysql, array $contexto = []) {
    global $wpdb;
    $ok = $wpdb->update(
        crm_inst_table_agenda(),
        ['fecha_cita' => $fecha_mysql, 'estado' => 'pendiente', 'recordatorio_enviado_en' => null],
        ['id' => (int) $agenda_id]
    );
    if ($ok === false) {
        return false;
    }

    $instalacion_id = (int) ($contexto['instalacion_id'] ?? 0);
    $instalador_id  = (int) ($contexto['instalador_id'] ?? 0);
    $cliente_nombre = (string) ($contexto['cliente_nombre'] ?? '');
    $timestamp      = strtotime($fecha_mysql);
    $fecha_label    = function_exists('date_i18n') ? date_i18n('d/m/Y H:i', $timestamp) : $fecha_mysql;
    $url_calendario = function_exists('home_url') ? home_url('/calendario-instalador/') : '';
    $url_ficha      = $instalacion_id > 0 && function_exists('add_query_arg') ? add_query_arg('id', $instalacion_id, home_url('/instalacion/')) : '';

    if ($instalador_id > 0) {
        $mensaje_visita = 'Visita reprogramada para ' . $fecha_label . ' — ' . ($cliente_nombre ?: 'cliente') . ' (el cliente eligió la fecha por WhatsApp).';
        if (function_exists('crm_notificar')) {
            crm_notificar($instalador_id, 'visita_reprogramada', $mensaje_visita, $url_calendario);
        }
        if (function_exists('crm_inst_email_instalador')) {
            crm_inst_email_instalador($instalador_id, 'Visita reprogramada', $mensaje_visita, $url_calendario);
        }
        if (function_exists('crm_inst_whatsapp_instalador')) {
            crm_inst_whatsapp_instalador(
                $instalador_id,
                'crm_whatsapp_template_instalador_visita',
                [$cliente_nombre ?: 'cliente', $fecha_label, $url_calendario],
                'Visita reprogramada (elegida por el cliente)'
            );
        }
    }

    if (function_exists('crm_notificar_jefes_instalaciones')) {
        crm_notificar_jefes_instalaciones('cita_reprogramada_cliente', 'El cliente reprogramó su visita al ' . $fecha_label . ' — ' . ($cliente_nombre ?: 'cliente') . '.', $url_ficha);
    }
    if (function_exists('crm_inst_aviso_enviar_email_jefes')) {
        crm_inst_aviso_enviar_email_jefes('Visita reprogramada por el cliente', 'El cliente reprogramó su visita al ' . $fecha_label . ' — ' . ($cliente_nombre ?: 'cliente') . '.', $url_ficha);
    }

    // Cierre previsto = fecha de la visita + 2 días laborables, solo si no
    // se había fijado ya a mano — mismo criterio que crm_inst_ajax_guardar_agenda().
    if ($instalacion_id > 0 && function_exists('crm_inst_table_instalaciones') && function_exists('crm_inst_sumar_dias_laborables')) {
        $cierre_previsto_actual = $wpdb->get_var($wpdb->prepare(
            "SELECT cierre_previsto FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
            $instalacion_id
        ));
        if (empty($cierre_previsto_actual)) {
            $wpdb->update(
                crm_inst_table_instalaciones(),
                ['cierre_previsto' => date('Y-m-d', crm_inst_sumar_dias_laborables($timestamp, 2))],
                ['id' => $instalacion_id]
            );
        }
    }

    return true;
}

/**
 * Calcula franjas para el instalador de $agenda_row, las recuerda en un
 * transient (clave = id de la fila de agenda) y envía al cliente la
 * plantilla con esas franjas en el cuerpo del mensaje.
 *
 * @param array $agenda_row  Debe traer al menos id, instalacion_id, fecha_cita, instalador_id.
 * @param array $cliente     Debe traer al menos id, cliente_nombre, telefono.
 * @return true|WP_Error
 */
function crm_whatsapp_ofrecer_franjas_cliente(array $agenda_row, array $cliente) {
    $template = trim((string) get_option('crm_whatsapp_template_reprogramar_visita_cliente', ''));
    if ($template === '') {
        return new WP_Error('crm_whatsapp_sin_plantilla_reprogramar', 'No hay plantilla configurada para ofrecer franjas de reprogramación.');
    }

    $franjas = crm_inst_franjas_disponibles_instalador(
        (int) ($agenda_row['instalador_id'] ?? 0),
        3,
        null,
        [(string) $agenda_row['fecha_cita']]
    );
    if (empty($franjas)) {
        return new WP_Error('crm_inst_sin_franjas', 'No se encontró ninguna franja libre para ofrecer.');
    }

    set_transient('crm_inst_whatsapp_franjas_' . (int) $agenda_row['id'], $franjas, 3 * DAY_IN_SECONDS);

    $franjas_texto = [];
    foreach ($franjas as $i => $f) {
        $franjas_texto[] = ($i + 1) . ') ' . date_i18n('l d/m', strtotime($f)) . ' a las ' . date_i18n('H:i', strtotime($f));
    }

    return crm_whatsapp_enviar_plantilla(
        $cliente['telefono'],
        $template,
        [$cliente['cliente_nombre'], implode(' · ', $franjas_texto)]
    );
}
