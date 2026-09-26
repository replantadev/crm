<?php
/**
 * Prueba de includes/ecovolt-leads-sync.php contra los criterios de
 * aceptación del documento CRM-LEADS-INTEGRATION.md del proyecto Ecovolt:
 * 401, 403, importación, duplicados (idempotencia por external_id), cursor,
 * paginación y errores parciales (de validación y de fallo real de BD).
 *
 * No requiere WordPress ni PHPUnit instalados — define stubs mínimos pero
 * fieles de las funciones de WP que el archivo usa (incluida una versión
 * simplificada de $wpdb->prepare()/get_var() que soporta específicamente la
 * consulta LIKE sobre lead_meta que usa crm_ecovolt_lead_ya_existe()) y una
 * cola de respuestas HTTP controlada por test en vez de red real.
 *
 * Ejecutar: php tests/ecovolt-leads-sync-test.php
 * (desde la raíz del plugin — usa require __DIR__ . '/../includes/...').
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__ . '/');

// ---- Stubs de WordPress -----------------------------------------------

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = []) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }

$GLOBALS['_options'] = [];
function get_option($k, $default = false) { return $GLOBALS['_options'][$k] ?? $default; }
function update_option($k, $v, $autoload = true) { $GLOBALS['_options'][$k] = $v; return true; }

function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function sanitize_email($v) { return trim((string) $v); }
function maybe_serialize($v) { return serialize($v); }
function wp_json_encode($v) { return json_encode($v); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function esc_url_raw($v) { return $v; }
function add_action($hook, $cb, $prio = 10, $args = 1) {}
function add_filter($hook, $cb, $prio = 10, $args = 1) {}
function wp_next_scheduled($hook) { return true; } // ya "programado": no intenta agendar de verdad en el test
function wp_schedule_event($ts, $recur, $hook) {}
function current_user_can($cap) { return true; }
function check_ajax_referer($a, $b, $c = true) { return true; }
function wp_send_json_error($d = null, $code = null) {}
function wp_send_json_success($d = null) {}

$GLOBALS['_log'] = [];
function crm_log_action($type, $msg, $a = null, $b = null, $level = 'info') {
    $GLOBALS['_log'][] = compact('type', 'msg', 'level');
}
$GLOBALS['_notes'] = [];
function crm_notes_add($args) { $GLOBALS['_notes'][] = $args; return true; }
function crm_find_duplicate_clients($tel, $email, $exclude = 0, $limit = 5) { return []; }

// Cola de respuestas HTTP controlada por cada test: cada llamada a
// wp_remote_get() consume la siguiente entrada de $GLOBALS['_http_queue'].
$GLOBALS['_http_queue'] = [];
$GLOBALS['_http_calls'] = [];
function wp_remote_get($url, $args = []) {
    $GLOBALS['_http_calls'][] = $url;
    if (empty($GLOBALS['_http_queue'])) {
        return new WP_Error('empty_queue', 'Test sin más respuestas encoladas.');
    }
    return array_shift($GLOBALS['_http_queue']);
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

function http_ok(array $items, $next_after_id, $has_more) {
    return ['code' => 200, 'body' => json_encode(['items' => $items, 'next_after_id' => $next_after_id, 'has_more' => $has_more])];
}
function http_error($code) { return ['code' => $code, 'body' => json_encode(['error' => 'x'])]; }

function lead(array $over = []) {
    return array_merge([
        'id' => 1, 'external_id' => 'ecovolt:1', 'created_at' => '2026-09-26T10:00:00+00:00',
        'name' => 'Cliente Prueba', 'email' => 'cliente@example.com', 'phone' => '600000000',
        'locality' => 'León', 'service' => ['key' => 'solar-home', 'label' => 'Fotovoltaica'],
        'start_preference' => ['key' => 'call', 'label' => 'Llamar'],
        'message' => 'Hola', 'source_url' => 'https://ecovolt.replanta.dev/',
        'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'solar_leon', 'content' => '', 'term' => ''],
        'consent' => ['accepted' => true, 'accepted_at' => '2026-09-26T10:00:00+00:00'],
        'source' => ['system' => 'ecovolt', 'channel' => 'website', 'form' => 'ecovolt_contact'],
    ], $over);
}

// ---- Fake $wpdb ---------------------------------------------------------

class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = [];
    public $fallar_insert_de = null; // external_id que debe fallar al insertar

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%s|%d/', function ($m) use ($args, &$i) {
            $v = $args[$i++] ?? '';
            // Mismo escape que MySQL real para un literal de cadena: comillas
            // simples y barras invertidas — las comillas dobles NO se
            // escapan (addslashes() sí lo haría, y rompería la búsqueda por
            // el JSON de lead_meta, que usa comillas dobles).
            $esc = str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v);
            return $m[0] === '%d' ? (string) (int) $v : "'" . $esc . "'";
        }, $sql);
    }

    public function esc_like($v) { return $v; }

    public function get_var($sql) {
        // Extrae el valor buscado en el LIKE (entre comillas) y comprueba
        // si algún lead_meta ya guardado lo contiene — suficiente fidelidad
        // para lo que crm_ecovolt_lead_ya_existe() necesita comprobar.
        if (preg_match("/LIKE '(.*)'/", $sql, $m)) {
            $needle = trim($m[1], '%');
            foreach ($this->rows as $row) {
                if (strpos($row['lead_meta'], $needle) !== false) {
                    return $row['id'];
                }
            }
        }
        return null;
    }

    public function insert($table, $data) {
        $external_id = null;
        $meta = json_decode($data['lead_meta'], true);
        $external_id = $meta['lead_id'] ?? null;
        if ($this->fallar_insert_de !== null && $external_id === $this->fallar_insert_de) {
            $this->last_error = 'fallo simulado';
            return false;
        }
        $this->insert_id = count($this->rows) + 1;
        $data['id'] = $this->insert_id;
        $this->rows[] = $data;
        return true;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();
$wpdb = $GLOBALS['wpdb'];

// ---- Credenciales configuradas (para que crm_ecovolt_leads_configurado() sea true) ----
update_option('crm_ecovolt_leads_usuario', 'tecnico');
update_option('crm_ecovolt_leads_app_password', 'xxxx xxxx xxxx xxxx');

require __DIR__ . '/../includes/ecovolt-leads-sync.php';

// ---- Tests ---------------------------------------------------------------

$fallos = [];
function check($label, $cond) {
    global $fallos;
    echo ($cond ? 'OK  ' : 'FAIL') . " - $label\n";
    if (!$cond) { $fallos[] = $label; }
}

// 1) 401
$GLOBALS['_http_queue'] = [http_error(401)];
$r = crm_ecovolt_leads_request(0, 50);
check('401 devuelve WP_Error crm_ecovolt_unauthorized', is_wp_error($r) && $r->get_error_code() === 'crm_ecovolt_unauthorized');

// 2) 403
$GLOBALS['_http_queue'] = [http_error(403)];
$r = crm_ecovolt_leads_request(0, 50);
check('403 devuelve WP_Error crm_ecovolt_forbidden', is_wp_error($r) && $r->get_error_code() === 'crm_ecovolt_forbidden');

// 3) Importación + paginación + cursor (2 páginas, 3 leads en total)
update_option('crm_ecovolt_leads_after_id', 0);
$GLOBALS['wpdb'] = new FakeWpdb(); $wpdb = $GLOBALS['wpdb'];
$GLOBALS['_http_calls'] = [];
$GLOBALS['_http_queue'] = [
    http_ok([lead(['id' => 10, 'external_id' => 'ecovolt:10']), lead(['id' => 11, 'external_id' => 'ecovolt:11'])], 11, true),
    http_ok([lead(['id' => 12, 'external_id' => 'ecovolt:12'])], 12, false),
];
$res = crm_ecovolt_leads_sync_run();
check('import: 3 procesados', $res['procesados'] === 3);
check('import: 3 importados', $res['importados'] === 3);
check('import: 0 errores', $res['errores'] === 0);
check('import: ok=true', $res['ok'] === true);
check('import: cursor avanza a 12', (int) get_option('crm_ecovolt_leads_after_id', 0) === 12);
check('import: 2 llamadas HTTP (paginación)', count($GLOBALS['_http_calls']) === 2);
check('import: nota de sistema creada', count($GLOBALS['_notes']) === 3);

// 4) Repetir el mismo lote no crea duplicados (idempotencia por external_id)
$GLOBALS['_http_calls'] = [];
$GLOBALS['_http_queue'] = [
    http_ok([lead(['id' => 10, 'external_id' => 'ecovolt:10']), lead(['id' => 11, 'external_id' => 'ecovolt:11'])], 11, true),
    http_ok([lead(['id' => 12, 'external_id' => 'ecovolt:12'])], 12, false),
];
$res2 = crm_ecovolt_leads_sync_run();
check('duplicados: 0 importados en la repetición', $res2['importados'] === 0);
check('duplicados: 3 ya existían', $res2['ya_existian'] === 3);
check('duplicados: sigue sin haber filas nuevas en BD', count($wpdb->rows) === 3);

// 5) Error a mitad de lote: un lead sin external_id no debe avanzar el cursor más allá de él, y para el resto del lote
update_option('crm_ecovolt_leads_after_id', 100);
$GLOBALS['wpdb'] = new FakeWpdb(); $wpdb = $GLOBALS['wpdb'];
$GLOBALS['_http_queue'] = [
    http_ok([
        lead(['id' => 101, 'external_id' => 'ecovolt:101']),
        lead(['id' => 102, 'external_id' => '']), // lead corrupto: sin external_id
        lead(['id' => 103, 'external_id' => 'ecovolt:103']),
    ], 103, false),
];
$res3 = crm_ecovolt_leads_sync_run();
check('error parcial: 1 procesado antes del fallo', $res3['procesados'] === 1);
check('error parcial: 1 importado antes del fallo', $res3['importados'] === 1);
check('error parcial: 1 error registrado', $res3['errores'] === 1);
check('error parcial: ok=false', $res3['ok'] === false);
check('error parcial: cursor se queda en 101, no en 102/103', (int) get_option('crm_ecovolt_leads_after_id', 0) === 101);
check('error parcial: el lead 103 (después del corrupto) no se procesó', count($wpdb->rows) === 1);

// 6) Fallo de inserción en BD (no de validación): tampoco debe avanzar el cursor
update_option('crm_ecovolt_leads_after_id', 200);
$GLOBALS['wpdb'] = new FakeWpdb(); $wpdb = $GLOBALS['wpdb'];
$wpdb->fallar_insert_de = 'ecovolt:202';
$GLOBALS['_http_queue'] = [
    http_ok([
        lead(['id' => 201, 'external_id' => 'ecovolt:201']),
        lead(['id' => 202, 'external_id' => 'ecovolt:202']),
        lead(['id' => 203, 'external_id' => 'ecovolt:203']),
    ], 203, false),
];
$res4 = crm_ecovolt_leads_sync_run();
check('fallo BD: 1 importado (el 201) antes del fallo', $res4['importados'] === 1);
check('fallo BD: 1 error', $res4['errores'] === 1);
check('fallo BD: cursor en 201, no en 202/203', (int) get_option('crm_ecovolt_leads_after_id', 0) === 201);

// 7) No configurado → no llama a la red
$GLOBALS['_options']['crm_ecovolt_leads_usuario'] = '';
$GLOBALS['_http_calls'] = [];
$res5 = crm_ecovolt_leads_sync_run();
check('sin configurar: no hace ninguna llamada HTTP', count($GLOBALS['_http_calls']) === 0);
check('sin configurar: ok=false', $res5['ok'] === false);

echo "\n";
if (empty($fallos)) {
    echo "TODOS LOS TESTS OK (" . 'sin fallos' . ")\n";
} else {
    echo count($fallos) . " FALLO(S): " . implode(' | ', $fallos) . "\n";
}
