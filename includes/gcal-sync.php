<?php
/**
 * Integración con Google Calendar para visitas del CRM.
 *
 * Dos mecanismos complementarios:
 *
 * 1) Enlace "Añadir a Google Calendar" por visita (URL TEMPLATE oficial).
 *    Sin OAuth: abre el wizard de creación de evento con todos los datos
 *    pre-rellenados. Útil para guardar una visita puntual.
 *
 * 2) Feed iCal personal por usuario (?crm_ical=1&token=XYZ).
 *    El usuario suscribe la URL en Google Calendar (Otros calendarios →
 *    Suscribirse a un calendario por URL). Google la sincroniza cada
 *    pocas horas en automático. Cualquier cambio en el CRM se refleja.
 *    El token es un secreto por usuario (user_meta), regenerable.
 *
 * @package CRM
 * @since 1.20.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Devuelve (creando si hace falta) el token iCal personal del usuario.
 */
function crm_gcal_get_user_token($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return '';
    }
    $token = (string) get_user_meta($user_id, 'crm_ical_token', true);
    if ($token === '' || strlen($token) < 24) {
        $token = wp_generate_password(32, false, false);
        update_user_meta($user_id, 'crm_ical_token', $token);
    }
    return $token;
}

/**
 * Regenera el token (invalida la suscripción anterior).
 */
function crm_gcal_regenerate_user_token($user_id) {
    delete_user_meta((int) $user_id, 'crm_ical_token');
    return crm_gcal_get_user_token($user_id);
}

/**
 * URL absoluta del feed iCal personal del usuario.
 */
function crm_gcal_get_user_feed_url($user_id) {
    $token = crm_gcal_get_user_token($user_id);
    if ($token === '') {
        return '';
    }
    return add_query_arg([
        'crm_ical' => '1',
        'uid'      => (int) $user_id,
        'token'    => $token,
    ], home_url('/'));
}

/**
 * Genera el "Añadir a Google Calendar" para una visita concreta.
 *
 * Acepta el array de visita (con client_id, fecha_visita, duracion_min, lugar, notas)
 * y opcionalmente un nombre de cliente para incluir en el título.
 */
function crm_gcal_get_event_url($visita, $cliente_nombre = '') {
    if (!is_array($visita) || empty($visita['fecha_visita'])) {
        return '';
    }
    $tz_string = (string) get_option('timezone_string');
    try {
        $tz = $tz_string !== '' ? new DateTimeZone($tz_string) : new DateTimeZone('UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    try {
        $start = new DateTime($visita['fecha_visita'], $tz);
    } catch (Exception $e) {
        return '';
    }
    $duracion = isset($visita['duracion_min']) ? max(5, (int) $visita['duracion_min']) : 60;
    $end = clone $start;
    $end->modify('+' . $duracion . ' minutes');

    // Google Calendar acepta la fecha en UTC con sufijo Z (recomendado).
    $start_utc = clone $start;
    $start_utc->setTimezone(new DateTimeZone('UTC'));
    $end_utc = clone $end;
    $end_utc->setTimezone(new DateTimeZone('UTC'));

    $title = 'Visita';
    if ($cliente_nombre !== '') {
        $title .= ': ' . $cliente_nombre;
    }
    if (!empty($visita['sector'])) {
        $title .= ' (' . ucfirst($visita['sector']) . ')';
    }

    $details_parts = [];
    if (!empty($visita['notas'])) {
        $details_parts[] = wp_strip_all_tags((string) $visita['notas']);
    }
    if (!empty($visita['client_id'])) {
        $ficha = add_query_arg('client_id', (int) $visita['client_id'], home_url('/alta-de-cliente/'));
        $details_parts[] = 'Ficha CRM: ' . $ficha;
    }
    $details = implode("\n\n", array_filter($details_parts));

    return add_query_arg([
        'action'   => 'TEMPLATE',
        'text'     => rawurlencode($title),
        'dates'    => $start_utc->format('Ymd\THis\Z') . '/' . $end_utc->format('Ymd\THis\Z'),
        'details'  => rawurlencode($details),
        'location' => rawurlencode((string) ($visita['lugar'] ?? '')),
    ], 'https://calendar.google.com/calendar/render');
}

/* =========================================================================
 * Feed iCal personal
 * ========================================================================= */

add_action('init', 'crm_gcal_register_ical_endpoint');
function crm_gcal_register_ical_endpoint() {
    add_rewrite_endpoint('crm_ical', EP_ROOT);
}

add_action('template_redirect', 'crm_gcal_serve_ical_feed', 1);
function crm_gcal_serve_ical_feed() {
    if (empty($_GET['crm_ical'])) {
        return;
    }
    $user_id = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
    $token   = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

    if ($user_id <= 0 || $token === '') {
        status_header(400);
        exit('Bad request');
    }

    $expected = (string) get_user_meta($user_id, 'crm_ical_token', true);
    if ($expected === '' || !hash_equals($expected, $token)) {
        status_header(403);
        exit('Forbidden');
    }

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="crm-visitas.ics"');

    echo crm_gcal_build_ical_for_user($user_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

/**
 * Construye el contenido iCal con todas las visitas (programadas y futuras
 * realizadas dentro de los últimos 60 días) del usuario indicado.
 */
function crm_gcal_build_ical_for_user($user_id) {
    global $wpdb;
    $table = function_exists('crm_visitas_table') ? crm_visitas_table() : ($wpdb->prefix . 'crm_visitas');
    $clients_table = $wpdb->prefix . 'crm_clients';

    $desde = date('Y-m-d', strtotime('-60 days', current_time('timestamp'))) . ' 00:00:00';
    $sql = $wpdb->prepare(
        "SELECT v.*, c.cliente_nombre, c.empresa, c.direccion
         FROM {$table} v
         LEFT JOIN {$clients_table} c ON c.id = v.client_id
         WHERE v.comercial_id = %d AND v.fecha_visita >= %s
         ORDER BY v.fecha_visita ASC",
        $user_id,
        $desde
    );
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

    $site = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $now_utc = gmdate('Ymd\THis\Z');

    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//CRM Energitel//Visitas//ES';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:PUBLISH';
    $lines[] = 'X-WR-CALNAME:Mis visitas CRM';
    $lines[] = 'X-WR-TIMEZONE:' . (string) get_option('timezone_string', 'UTC');

    $tz_string = (string) get_option('timezone_string');
    try {
        $tz = $tz_string !== '' ? new DateTimeZone($tz_string) : new DateTimeZone('UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $utc = new DateTimeZone('UTC');

    foreach ($rows as $v) {
        try {
            $start = new DateTime($v['fecha_visita'], $tz);
        } catch (Exception $e) {
            continue;
        }
        $end = clone $start;
        $end->modify('+' . max(5, (int) $v['duracion_min']) . ' minutes');
        $start->setTimezone($utc);
        $end->setTimezone($utc);

        $uid = 'visita-' . (int) $v['id'] . '@' . $site;
        $summary = 'Visita';
        if (!empty($v['cliente_nombre'])) {
            $summary .= ': ' . $v['cliente_nombre'];
        }
        if (!empty($v['empresa'])) {
            $summary .= ' (' . $v['empresa'] . ')';
        }

        $desc_parts = [];
        if (!empty($v['notas'])) {
            $desc_parts[] = wp_strip_all_tags((string) $v['notas']);
        }
        if (!empty($v['client_id'])) {
            $desc_parts[] = 'Ficha: ' . add_query_arg('client_id', (int) $v['client_id'], home_url('/alta-de-cliente/'));
        }
        if (!empty($v['estado'])) {
            $desc_parts[] = 'Estado: ' . $v['estado'];
        }
        $description = implode('\\n\\n', array_map('crm_gcal_escape_ical', $desc_parts));

        $location = $v['lugar'] ?: ($v['direccion'] ?? '');

        $status = 'CONFIRMED';
        if ($v['estado'] === 'cancelada') {
            $status = 'CANCELLED';
        } elseif ($v['estado'] === 'no_show') {
            $status = 'TENTATIVE';
        }

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid;
        $lines[] = 'DTSTAMP:' . $now_utc;
        $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
        $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
        $lines[] = 'SUMMARY:' . crm_gcal_escape_ical($summary);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . $description;
        }
        if ($location !== '') {
            $lines[] = 'LOCATION:' . crm_gcal_escape_ical($location);
        }
        $lines[] = 'STATUS:' . $status;
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    // iCalendar requiere CRLF (\r\n) entre líneas.
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Escapa caracteres especiales según RFC 5545.
 */
function crm_gcal_escape_ical($text) {
    $text = (string) $text;
    $text = str_replace(["\\", "\n", "\r", ",", ";"], ["\\\\", "\\n", "", "\\,", "\\;"], $text);
    return $text;
}

/* =========================================================================
 * Shortcode: instrucciones + URL de feed para el usuario actual
 * ========================================================================= */

add_shortcode('crm_mi_gcal', 'crm_shortcode_mi_gcal');
function crm_shortcode_mi_gcal() {
    if (!is_user_logged_in()) {
        return '<p>Inicia sesión para configurar tu sincronización con Google Calendar.</p>';
    }
    $uid = get_current_user_id();
    $feed = crm_gcal_get_user_feed_url($uid);

    ob_start();
    ?>
    <div class="crm-gcal-card" style="padding:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; max-width:680px;">
        <h3 style="margin:0 0 8px;">Sincronizar mis visitas con Google Calendar</h3>
        <p style="margin:0 0 12px; color:#475569;">
            Copia esta URL y añádela en Google Calendar &rarr; <em>Otros calendarios</em> &rarr;
            <em>Suscribirse a un calendario por URL</em>. Tus visitas aparecerán
            automáticamente y se actualizarán cada pocas horas.
        </p>
        <div style="display:flex; gap:8px; align-items:center;">
            <input type="text" readonly value="<?php echo esc_attr($feed); ?>"
                   id="crm-ical-feed-url"
                   style="flex:1; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:12px;">
            <button type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('crm-ical-feed-url').value); this.textContent='Copiado';"
                    class="crm-btn">Copiar</button>
        </div>
        <p style="margin:14px 0 0; font-size:13px; color:#64748b;">
            ¿Sospechas que alguien tiene tu URL? Pide a un administrador que regenere tu token
            (o usa el botón de tu perfil).
        </p>
    </div>
    <?php
    return ob_get_clean();
}

/* =========================================================================
 * Acción: regenerar token desde el perfil del usuario (admin-post).
 * ========================================================================= */

add_action('admin_post_crm_gcal_regenerate_token', 'crm_gcal_handle_regenerate_token');
function crm_gcal_handle_regenerate_token() {
    if (!is_user_logged_in()) {
        wp_die('No autorizado.', '', ['response' => 403]);
    }
    check_admin_referer('crm_gcal_regenerate');
    $uid = get_current_user_id();
    crm_gcal_regenerate_user_token($uid);
    $redirect = wp_get_referer() ?: home_url('/');
    wp_safe_redirect(add_query_arg('crm_gcal', 'regenerated', $redirect));
    exit;
}
