<?php
/**
 * Notificaciones por email a comerciales cuando se les asigna un cliente/lead.
 *
 * Settings (option crm_notifications_settings):
 *  - assignment_enabled (bool, default true)
 *  - from_name  (string)
 *  - from_email (string)
 */

if (!defined('ABSPATH')) {
    exit;
}

const CRM_NOTIF_OPTION = 'crm_notifications_settings';

function crm_notif_get_settings() {
    $defaults = [
        'assignment_enabled' => true,
        'from_name'          => '',
        'from_email'         => '',
    ];
    $opt = get_option(CRM_NOTIF_OPTION, []);
    if (!is_array($opt)) {
        $opt = [];
    }
    return array_merge($defaults, $opt);
}

function crm_notif_save_settings(array $patch) {
    update_option(CRM_NOTIF_OPTION, array_merge(crm_notif_get_settings(), $patch), false);
}

/**
 * Envía un email al comercial al que se le asigna por primera vez (o se le
 * reasigna) un cliente / lead.
 *
 * @param int $client_id
 * @param int $new_user_id
 * @return bool|null null si está deshabilitado o el usuario no tiene email
 */
function crm_notif_assignment_send($client_id, $new_user_id) {
    if (!$new_user_id) {
        return null;
    }
    $settings = crm_notif_get_settings();
    if (empty($settings['assignment_enabled'])) {
        return null;
    }

    $user = get_userdata((int) $new_user_id);
    if (!$user || empty($user->user_email)) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $client_id), ARRAY_A);
    if (!$row) {
        return null;
    }

    $nombre   = $row['cliente_nombre'] ?: '(sin nombre)';
    $tel      = $row['telefono'] ?: '—';
    $email    = $row['email_cliente'] ?: '—';
    $origen   = $row['origen_lead'] ?: 'directo';
    $origen_label = [
        'directo'       => 'Directo',
        'lead_mk'       => 'Lead Marketing',
        'contacto_frio' => 'Contacto frío',
        'referido'      => 'Referido',
        'web'           => 'Web',
    ][$origen] ?? $origen;

    $lead_meta = !empty($row['lead_meta']) ? json_decode($row['lead_meta'], true) : null;
    $campaign  = is_array($lead_meta) && !empty($lead_meta['campaign_name']) ? $lead_meta['campaign_name'] : '';

    $edit_url = add_query_arg('client_id', (int) $client_id, home_url('/alta-de-cliente/'));

    $subject = sprintf('[CRM] Nuevo cliente asignado: %s', $nombre);

    $body  = "<p>Hola " . esc_html($user->display_name) . ",</p>";
    $body .= "<p>Se te ha asignado un nuevo cliente en el CRM:</p>";
    $body .= "<ul>";
    $body .= "<li><strong>Nombre:</strong> " . esc_html($nombre) . "</li>";
    $body .= "<li><strong>Teléfono:</strong> " . esc_html($tel) . "</li>";
    $body .= "<li><strong>Email:</strong> " . esc_html($email) . "</li>";
    $body .= "<li><strong>Origen:</strong> " . esc_html($origen_label) . "</li>";
    if ($campaign !== '') {
        $body .= "<li><strong>Campaña:</strong> " . esc_html($campaign) . "</li>";
    }
    $body .= "</ul>";
    $body .= "<p><a href=\"" . esc_url($edit_url) . "\">Abrir ficha del cliente</a></p>";
    $body .= "<p style=\"color:#666;font-size:12px\">Mensaje automático del CRM. No responder a este correo.</p>";

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    // El remitente lo gestiona el propio WordPress (o WP Mail SMTP si está instalado).
    // No forzamos cabecera From: para no interferir con la configuración SMTP del sitio.
    if (!empty($settings['from_email'])) {
        $from_name  = $settings['from_name'] ?: get_bloginfo('name');
        $headers[]  = sprintf('From: %s <%s>', $from_name, $settings['from_email']);
    }

    $sent = wp_mail($user->user_email, $subject, $body, $headers);

    if (function_exists('crm_notes_add')) {
        crm_notes_add([
            'client_id'    => (int) $client_id,
            'tipo'         => 'sistema',
            'texto'        => $sent
                ? sprintf('Notificación de asignación enviada a %s &lt;%s&gt;', esc_html($user->display_name), esc_html($user->user_email))
                : sprintf('FALLO al enviar notificación de asignación a %s &lt;%s&gt;', esc_html($user->display_name), esc_html($user->user_email)),
            'source_action' => 'assignment_email',
        ]);
    }

    return $sent;
}
