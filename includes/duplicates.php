<?php
/**
 * Detección de duplicados de clientes/leads.
 *
 * Estrategia v1.18:
 *  - Teléfono: se reduce a los últimos 9 dígitos (formato español).
 *  - Email: se normaliza con lowercase + trim.
 *  - La consulta de duplicado siempre limita por (telefono LIKE %suffix9) OR (email = ?),
 *    excluyendo opcionalmente un client_id ya conocido.
 *
 * Los duplicados NO se bloquean al guardar; se devuelven al llamador para
 * que decida si avisa, fusiona o sigue adelante.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normaliza un teléfono español a sus últimos 9 dígitos.
 * Acepta entradas con espacios, guiones, paréntesis, prefijo +34/0034/34.
 *
 * @param string|null $raw
 * @return string Cadena de 0..9 dígitos. '' si no hay dígitos.
 */
function crm_normalize_phone($raw) {
    if (!is_string($raw) && !is_numeric($raw)) {
        return '';
    }
    $digits = preg_replace('/\D+/', '', (string) $raw);
    if ($digits === '') {
        return '';
    }
    // Si empieza por 0034 → quitar
    if (strlen($digits) > 9 && strpos($digits, '0034') === 0) {
        $digits = substr($digits, 4);
    }
    // Si empieza por 34 y el total es 11 (34 + 9) → quitar
    if (strlen($digits) === 11 && strpos($digits, '34') === 0) {
        $digits = substr($digits, 2);
    }
    // Coger los últimos 9
    if (strlen($digits) > 9) {
        $digits = substr($digits, -9);
    }
    return $digits;
}

/**
 * Normaliza un email para comparación.
 *
 * @param string|null $raw
 * @return string '' si está vacío o el sanitize falla.
 */
function crm_normalize_email($raw) {
    if (!is_string($raw)) {
        return '';
    }
    $e = strtolower(trim($raw));
    if ($e === '' || !is_email($e)) {
        return '';
    }
    return $e;
}

/**
 * Busca clientes duplicados por teléfono o email.
 *
 * @param string $telefono   Teléfono crudo o normalizado.
 * @param string $email      Email crudo o normalizado.
 * @param int    $exclude_id ID de cliente a excluir (al editar).
 * @param int    $limit      Máx resultados.
 * @return array Lista de filas (id, cliente_nombre, telefono, email_cliente, origen_lead, user_id, fecha).
 */
function crm_find_duplicate_clients($telefono = '', $email = '', $exclude_id = 0, $limit = 5) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $phone = crm_normalize_phone($telefono);
    $mail  = crm_normalize_email($email);

    if ($phone === '' && $mail === '') {
        return [];
    }

    $where_parts = [];
    $params = [];

    if ($phone !== '') {
        // Buscar por sufijo de 9 dígitos
        $where_parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefono, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE %s";
        $params[] = '%' . $wpdb->esc_like($phone);
    }
    if ($mail !== '') {
        $where_parts[] = "LOWER(TRIM(email_cliente)) = %s";
        $params[] = $mail;
    }

    $where = '(' . implode(' OR ', $where_parts) . ')';
    if ($exclude_id > 0) {
        $where .= ' AND id != %d';
        $params[] = (int) $exclude_id;
    }

    $sql = "SELECT id, cliente_nombre, telefono, email_cliente, origen_lead, user_id, fecha
            FROM $table
            WHERE $where
            ORDER BY id DESC
            LIMIT %d";
    $params[] = (int) $limit;

    $prepared = $wpdb->prepare($sql, $params);
    return $wpdb->get_results($prepared, ARRAY_A) ?: [];
}

/**
 * Atajo booleano para flujos donde sólo importa "¿existe ya?".
 */
function crm_has_duplicate_client($telefono = '', $email = '', $exclude_id = 0) {
    $dups = crm_find_duplicate_clients($telefono, $email, $exclude_id, 1);
    return !empty($dups);
}

/**
 * Endpoint AJAX para que el formulario pueda avisar de duplicados en tiempo real.
 * Devuelve { count, dupes: [{id, nombre, telefono, email, origen, asignado}] }.
 */
add_action('wp_ajax_crm_check_duplicate', 'crm_ajax_check_duplicate');
function crm_ajax_check_duplicate() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autorizado'], 401);
    }
    check_ajax_referer('crm_alta_cliente_nonce', 'nonce');

    $telefono = isset($_POST['telefono']) ? sanitize_text_field(wp_unslash($_POST['telefono'])) : '';
    $email    = isset($_POST['email'])    ? sanitize_text_field(wp_unslash($_POST['email']))    : '';
    $exclude  = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;

    $dupes = crm_find_duplicate_clients($telefono, $email, $exclude, 5);

    // Filtrar info según permisos: comerciales solo ven duplicados que les pertenecen.
    $is_admin = current_user_can('crm_admin');
    $current_uid = get_current_user_id();
    $out = [];
    foreach ($dupes as $d) {
        $owner = (int) $d['user_id'];
        $is_visible = $is_admin || $owner === 0 || $owner === $current_uid;
        $out[] = [
            'id'        => (int) $d['id'],
            'nombre'    => $is_visible ? $d['cliente_nombre'] : '[no visible]',
            'telefono'  => $is_visible ? $d['telefono'] : '',
            'email'     => $is_visible ? $d['email_cliente'] : '',
            'origen'    => $d['origen_lead'] ?: 'directo',
            'asignado'  => $owner > 0,
            'editable'  => $is_visible,
        ];
    }

    wp_send_json_success([
        'count' => count($out),
        'dupes' => $out,
    ]);
}
