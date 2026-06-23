<?php
/**
 * Sincronización de leads desde Google Sheets (Meta Lead Ads export).
 *
 * Carga el JSON de Service Account de una opción guardada, firma un JWT RS256,
 * obtiene un access_token con scope https://www.googleapis.com/auth/spreadsheets.readonly
 * y descarga el rango configurado de la hoja para volcar filas nuevas como
 * clientes con origen_lead = 'lead_mk' y user_id = NULL.
 *
 * Opciones (wp_options):
 *   crm_leads_sheets_settings = [
 *      'enabled'       => bool,
 *      'spreadsheet_id'=> string,
 *      'range'         => string (ej. 'Hoja1!A:Z'),
 *      'sa_json'       => string (JSON bruto de la Service Account, almacenado
 *                                  encriptado con AUTH_KEY si está disponible),
 *      'last_sync'     => int (timestamp),
 *      'last_status'   => string,
 *      'last_count'    => int,
 *      'cursor_ids'    => array<string> (ids del Sheet ya importados, últimos 5000),
 *   ]
 */

if (!defined('ABSPATH')) {
    exit;
}

const CRM_LEADS_SHEETS_OPTION    = 'crm_leads_sheets_settings';
const CRM_LEADS_SHEETS_CRON_HOOK = 'crm_leads_sheets_sync_cron';
const CRM_LEADS_SHEETS_CURSOR_MAX = 5000;

/* -------------------------------------------------------------------------
 * Opciones / settings
 * ------------------------------------------------------------------------- */

function crm_leads_sheets_get_settings() {
    $defaults = [
        'enabled'        => false,
        'spreadsheet_id' => '',
        'range'          => 'A:Z',
        'sa_json'        => '',
        'last_sync'      => 0,
        'last_status'    => '',
        'last_count'     => 0,
        'cursor_ids'     => [],
        'notify_admin'   => true,
    ];
    $opt = get_option(CRM_LEADS_SHEETS_OPTION, []);
    if (!is_array($opt)) {
        $opt = [];
    }
    $settings = array_merge($defaults, $opt);

    // Descifrar SA JSON si está cifrado
    if (!empty($settings['sa_json']) && strpos($settings['sa_json'], 'enc:') === 0) {
        $settings['sa_json'] = crm_leads_sheets_decrypt(substr($settings['sa_json'], 4));
    }
    return $settings;
}

function crm_leads_sheets_save_settings(array $patch) {
    $current = crm_leads_sheets_get_settings();
    $merged  = array_merge($current, $patch);

    // Cifrar SA JSON si AUTH_KEY existe
    if (!empty($merged['sa_json']) && strpos($merged['sa_json'], 'enc:') !== 0) {
        $enc = crm_leads_sheets_encrypt($merged['sa_json']);
        if ($enc !== false) {
            $merged['sa_json'] = 'enc:' . $enc;
        }
    }
    // Limitar tamaño del cursor
    if (!empty($merged['cursor_ids']) && is_array($merged['cursor_ids'])) {
        $merged['cursor_ids'] = array_slice(array_values(array_unique($merged['cursor_ids'])), -CRM_LEADS_SHEETS_CURSOR_MAX);
    }
    update_option(CRM_LEADS_SHEETS_OPTION, $merged, false);
}

/* -------------------------------------------------------------------------
 * Cifrado simétrico ligero (AUTH_KEY como secreto)
 * ------------------------------------------------------------------------- */

function crm_leads_sheets_cipher_key() {
    if (defined('AUTH_KEY') && AUTH_KEY) {
        return substr(hash('sha256', AUTH_KEY, true), 0, 32);
    }
    return false;
}

function crm_leads_sheets_encrypt($plain) {
    $key = crm_leads_sheets_cipher_key();
    if (!$key || !function_exists('openssl_encrypt')) {
        return false;
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return false;
    }
    return base64_encode($iv . $cipher);
}

function crm_leads_sheets_decrypt($enc) {
    $key = crm_leads_sheets_cipher_key();
    if (!$key || !function_exists('openssl_decrypt')) {
        return '';
    }
    $bin = base64_decode($enc, true);
    if ($bin === false || strlen($bin) < 17) {
        return '';
    }
    $iv = substr($bin, 0, 16);
    $cipher = substr($bin, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain ?: '';
}

/* -------------------------------------------------------------------------
 * Auth Service Account (JWT RS256 → access_token)
 * ------------------------------------------------------------------------- */

/**
 * Devuelve un access_token válido (cacheado en transient).
 * @param array $sa Service Account decodificado
 * @return string|WP_Error
 */
function crm_leads_sheets_get_access_token(array $sa) {
    if (empty($sa['client_email']) || empty($sa['private_key'])) {
        return new WP_Error('crm_sheets_bad_sa', 'Service Account JSON inválido (faltan client_email / private_key)');
    }
    $cache_key = 'crm_sheets_tok_' . substr(md5($sa['client_email']), 0, 12);
    $cached = get_transient($cache_key);
    if ($cached) {
        return $cached;
    }

    $now = time();
    $payload = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ];
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];

    $b64 = function ($s) {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    };
    $signing_input = $b64(wp_json_encode($header)) . '.' . $b64(wp_json_encode($payload));

    $signature = '';
    $pkey = openssl_pkey_get_private($sa['private_key']);
    if (!$pkey) {
        return new WP_Error('crm_sheets_bad_key', 'No se pudo leer private_key del SA');
    }
    $ok = openssl_sign($signing_input, $signature, $pkey, 'sha256WithRSAEncryption');
    if (!$ok) {
        return new WP_Error('crm_sheets_sign', 'Fallo firmando JWT');
    }
    $jwt = $signing_input . '.' . $b64($signature);

    $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body'    => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ],
    ]);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($body['access_token'])) {
        return new WP_Error('crm_sheets_token', 'Google rechazó el JWT: HTTP ' . $code . ' · ' . wp_json_encode($body));
    }
    set_transient($cache_key, $body['access_token'], max(60, ((int) ($body['expires_in'] ?? 3600)) - 60));
    return $body['access_token'];
}

/* -------------------------------------------------------------------------
 * Pull + import
 * ------------------------------------------------------------------------- */

/**
 * Ejecuta una sincronización. Devuelve array con métricas o WP_Error.
 *
 * @param bool $force Si true, ignora cursor_ids y reimporta todo (solo para diagnóstico).
 */
function crm_leads_sheets_run_sync($force = false) {
    $s = crm_leads_sheets_get_settings();
    if (empty($s['enabled'])) {
        return new WP_Error('crm_sheets_disabled', 'Sincronización deshabilitada');
    }
    if (empty($s['spreadsheet_id']) || empty($s['sa_json'])) {
        return new WP_Error('crm_sheets_unconfigured', 'Falta configurar spreadsheet_id o Service Account');
    }
    $sa = json_decode($s['sa_json'], true);
    if (!is_array($sa)) {
        return new WP_Error('crm_sheets_bad_json', 'Service Account JSON no se puede parsear');
    }

    $token = crm_leads_sheets_get_access_token($sa);
    if (is_wp_error($token)) {
        crm_leads_sheets_save_settings(['last_status' => 'error: ' . $token->get_error_message()]);
        return $token;
    }

    $range = $s['range'] ?: 'A:Z';
    $url = sprintf(
        'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s?valueRenderOption=FORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING',
        rawurlencode($s['spreadsheet_id']),
        rawurlencode($range)
    );
    $resp = wp_remote_get($url, [
        'timeout' => 30,
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);
    if (is_wp_error($resp)) {
        crm_leads_sheets_save_settings(['last_status' => 'error: ' . $resp->get_error_message()]);
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || !isset($body['values'])) {
        $msg = 'HTTP ' . $code . ' · ' . (is_array($body) ? wp_json_encode($body) : '');
        crm_leads_sheets_save_settings(['last_status' => 'error: ' . $msg]);
        return new WP_Error('crm_sheets_http', $msg);
    }

    $rows = $body['values'];
    if (count($rows) < 2) {
        crm_leads_sheets_save_settings([
            'last_sync'   => time(),
            'last_status' => 'ok: hoja vacía',
            'last_count'  => 0,
        ]);
        return ['inserted' => 0, 'skipped' => 0, 'dupes' => 0];
    }

    // Primera fila = cabeceras
    $headers = array_map(function ($h) { return strtolower(trim((string) $h)); }, $rows[0]);
    $cursor  = $force ? [] : (array) ($s['cursor_ids'] ?? []);
    $inserted = 0;
    $skipped  = 0;
    $dupes    = 0;
    $new_ids  = [];

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $assoc = [];
        foreach ($headers as $idx => $h) {
            $assoc[$h] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
        }
        $lead_id = $assoc['id'] ?? '';
        if ($lead_id === '') {
            $skipped++;
            continue;
        }
        if (in_array((string) $lead_id, $cursor, true)) {
            $skipped++;
            continue;
        }
        $result = crm_leads_sheets_insert_row($assoc);
        if ($result === 'dup') {
            $dupes++;
            $new_ids[] = (string) $lead_id;
        } elseif ($result === 'ok') {
            $inserted++;
            $new_ids[] = (string) $lead_id;
        } else {
            $skipped++;
        }
    }

    crm_leads_sheets_save_settings([
        'last_sync'   => time(),
        'last_status' => sprintf('ok: %d nuevos, %d dup, %d omitidos', $inserted, $dupes, $skipped),
        'last_count'  => $inserted,
        'cursor_ids'  => array_merge($cursor, $new_ids),
    ]);

    return ['inserted' => $inserted, 'skipped' => $skipped, 'dupes' => $dupes];
}

/**
 * Inserta una fila como lead en wp_crm_clients (origen_lead='lead_mk', user_id=NULL).
 * Devuelve 'ok' | 'dup' | 'err'.
 */
function crm_leads_sheets_insert_row(array $assoc) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $nombre   = $assoc['nombre_y_apellidos'] ?? ($assoc['nombre'] ?? '');
    $email    = $assoc['correo_electrónico'] ?? ($assoc['correo_electronico'] ?? ($assoc['email'] ?? ''));
    $telefono = $assoc['número_de_teléfono'] ?? ($assoc['numero_de_telefono'] ?? ($assoc['telefono'] ?? ''));
    $fecha    = $assoc['created_time'] ?? '';

    if ($nombre === '' && $email === '' && $telefono === '') {
        return 'err';
    }

    // Dedupe
    if (function_exists('crm_has_duplicate_client') && crm_has_duplicate_client($telefono, $email, 0)) {
        return 'dup';
    }

    // Normalizar fecha a Y-m-d H:i:s
    $fecha_db = current_time('mysql');
    if ($fecha !== '') {
        $ts = strtotime($fecha);
        if ($ts) {
            $fecha_db = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    $lead_meta = [
        'lead_id'       => $assoc['id'] ?? '',
        'platform'      => $assoc['platform'] ?? '',
        'lead_status'   => $assoc['lead_status'] ?? '',
        'ad_id'         => $assoc['ad_id'] ?? '',
        'ad_name'       => $assoc['ad_name'] ?? '',
        'adset_id'      => $assoc['adset_id'] ?? '',
        'adset_name'    => $assoc['adset_name'] ?? '',
        'campaign_id'   => $assoc['campaign_id'] ?? '',
        'campaign_name' => $assoc['campaign_name'] ?? '',
        'form_id'       => $assoc['form_id'] ?? '',
        'form_name'     => $assoc['form_name'] ?? '',
        'is_organic'    => $assoc['is_organic'] ?? '',
        'imported_at'   => current_time('mysql'),
    ];

    $insert = [
        'delegado'                  => '',
        'user_id'                   => null,
        'email_comercial'           => '',
        'fecha'                     => $fecha_db,
        'cliente_nombre'            => substr($nombre, 0, 255),
        'empresa'                   => '',
        'direccion'                 => '',
        'telefono'                  => substr($telefono, 0, 15),
        'email_cliente'             => substr($email, 0, 255),
        'poblacion'                 => '',
        'provincia'                 => '',
        'tipo'                      => '',
        'comentarios'               => '',
        'intereses'                 => maybe_serialize([]),
        'estado'                    => 'borrador',
        'estado_por_sector'         => maybe_serialize([]),
        'fecha_envio_por_sector'    => '',
        'usuario_envio_por_sector'  => '',
        'creado_por'                => 0,
        'creado_en'                 => current_time('mysql'),
        'origen_lead'               => 'lead_mk',
        'es_cliente_activo'         => 0,
        'lead_meta'                 => wp_json_encode($lead_meta),
    ];

    $ok = $wpdb->insert($table, $insert);
    if ($ok === false) {
        error_log('CRM Sheets insert error: ' . $wpdb->last_error);
        return 'err';
    }
    $client_id = (int) $wpdb->insert_id;

    if (function_exists('crm_notes_add')) {
        crm_notes_add([
            'client_id'  => $client_id,
            'tipo'       => 'sistema',
            'texto'      => sprintf(
                'Lead importado desde Google Sheets (campaña: %s, anuncio: %s)',
                $lead_meta['campaign_name'] ?: '—',
                $lead_meta['ad_name'] ?: '—'
            ),
            'autor_id'   => 0,
            'autor_name' => 'Sync Google Sheets',
        ]);
    }
    return 'ok';
}

/* -------------------------------------------------------------------------
 * Cron horario
 * ------------------------------------------------------------------------- */

add_action('init', 'crm_leads_sheets_schedule_cron');
function crm_leads_sheets_schedule_cron() {
    if (!wp_next_scheduled(CRM_LEADS_SHEETS_CRON_HOOK)) {
        wp_schedule_event(time() + 600, 'hourly', CRM_LEADS_SHEETS_CRON_HOOK);
    }
}

add_action(CRM_LEADS_SHEETS_CRON_HOOK, 'crm_leads_sheets_cron_run');
function crm_leads_sheets_cron_run() {
    $s = crm_leads_sheets_get_settings();
    if (empty($s['enabled'])) {
        return;
    }
    crm_leads_sheets_run_sync(false);
}

function crm_leads_sheets_unschedule_cron() {
    wp_clear_scheduled_hook(CRM_LEADS_SHEETS_CRON_HOOK);
}

/* -------------------------------------------------------------------------
 * AJAX para botón "Sincronizar ahora" desde admin
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_crm_leads_sheets_sync_now', 'crm_leads_sheets_ajax_sync_now');
function crm_leads_sheets_ajax_sync_now() {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    check_ajax_referer('crm_leads_sheets', 'nonce');
    $result = crm_leads_sheets_run_sync(false);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    }
    wp_send_json_success($result);
}
