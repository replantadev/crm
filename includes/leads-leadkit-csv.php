<?php
/**
 * Importación manual de leads desde un CSV exportado por LeadKit
 * (leadskit.app o similar) — reunión con cliente 2026-09-22, punto 7.
 *
 * A diferencia de la sincronización de Google Sheets (includes/leads-sheets.php,
 * automática por cron), esta es una subida manual puntual: crm_admin descarga
 * el CSV de LeadKit y lo sube desde el botón "Importar leads de LeadKit (CSV)"
 * en la página de Asignación de leads.
 *
 * Formato real verificado contra un CSV de ejemplo (leads-23-09-2026.csv):
 * cabeceras "ID","Date","Nombre","<pregunta de la campaña, variable>","Estado",
 * "nextActivity","Responsable","Etiquetas","notes" — el campo "Date" viene
 * envuelto en comillas literales dentro del propio valor (p.ej.
 * '"2026-09-23T06:58:40.000Z"'), fruto de cómo LeadKit exporta el CSV;
 * str_getcsv() ya lo devuelve así y basta con trim($valor, '"') antes de
 * parsear la fecha. La columna de la "pregunta" cambia de nombre según la
 * campaña (es texto libre de un formulario), así que NO se mapea por nombre
 * fijo — se guarda tal cual, con su propio encabezado, dentro de
 * lead_meta.respuestas para no perder el dato aunque cambie de un CSV a otro.
 *
 * Todos los leads entran como origen_lead='placassolares' (el proveedor,
 * ver crm-plugin.php) pero comparten cola con 'lead_mk' — ver
 * crm_leads_mk_origenes() en includes/leads-mk-shortcode.php.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cabeceras del CSV de LeadKit que ya tienen un mapeo fijo conocido — el
 * resto de columnas (variables según la campaña) se guardan igualmente,
 * dentro de lead_meta.respuestas, sin descartarlas.
 */
function crm_leadkit_csv_columnas_fijas() {
    return ['id', 'date', 'nombre', 'estado', 'nextactivity', 'responsable', 'etiquetas', 'notes'];
}

/**
 * Parsea el contenido crudo de un CSV de LeadKit y devuelve las filas ya
 * como arrays asociativos (clave = cabecera en minúsculas, tal cual venía).
 *
 * @param string $contenido
 * @return array<int,array<string,string>>
 */
function crm_leadkit_csv_parsear($contenido) {
    // LeadKit exporta con BOM UTF-8 delante — quitarlo si está, si no
    // str_getcsv() se come el primer carácter de la primera cabecera.
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    $lineas = preg_split('/\r\n|\r|\n/', $contenido);
    $lineas = array_values(array_filter($lineas, function ($l) { return trim($l) !== ''; }));
    if (count($lineas) < 2) {
        return [];
    }

    $cabeceras = array_map(function ($h) { return trim($h); }, str_getcsv($lineas[0]));
    $cabeceras_norm = array_map(function ($h) { return strtolower(trim($h)); }, $cabeceras);
    $fijas = crm_leadkit_csv_columnas_fijas();

    $filas = [];
    for ($i = 1; $i < count($lineas); $i++) {
        $campos = str_getcsv($lineas[$i]);
        $fila = ['_variables' => []];
        foreach ($cabeceras_norm as $idx => $h) {
            if ($h === '') {
                continue;
            }
            $valor = isset($campos[$idx]) ? trim((string) $campos[$idx]) : '';
            $valor = trim($valor, '"');
            if (in_array($h, $fijas, true)) {
                $fila[$h] = $valor;
            } elseif ($valor !== '') {
                // Columna variable (pregunta de la campaña) — se guarda con
                // su cabecera ORIGINAL (acentos/mayúsculas tal cual venía),
                // no la normalizada, para no perder el texto real.
                $fila['_variables'][$cabeceras[$idx]] = $valor;
            }
        }
        $filas[] = $fila;
    }
    return $filas;
}

/**
 * Comprueba si ya existe un lead importado con este ID de LeadKit (dedupe).
 * Búsqueda por texto sobre el JSON de lead_meta — sin depender de funciones
 * JSON de MySQL que puede que el hosting no tenga.
 *
 * @param string $leadkit_id
 * @return bool
 */
function crm_leadkit_csv_ya_existe($leadkit_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $encontrado = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE origen_lead = 'placassolares' AND lead_meta LIKE %s LIMIT 1",
        '%"leadkit_id":"' . $wpdb->esc_like($leadkit_id) . '"%'
    ));
    return $encontrado > 0;
}

/**
 * Inserta una fila del CSV como lead nuevo (origen_lead='placassolares',
 * sin comercial asignado — entra en la misma cola que los leads MK).
 *
 * @param array $fila Fila ya parseada por crm_leadkit_csv_parsear().
 * @return string 'ok'|'dup'|'err'
 */
function crm_leadkit_csv_insertar_fila(array $fila) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $leadkit_id = (string) ($fila['id'] ?? '');
    $nombre     = (string) ($fila['nombre'] ?? '');
    if ($leadkit_id === '' || $nombre === '') {
        return 'err';
    }
    if (crm_leadkit_csv_ya_existe($leadkit_id)) {
        return 'dup';
    }

    // La fecha ya viene sin las comillas literales (ver crm_leadkit_csv_parsear()).
    $fecha_db = current_time('mysql');
    if (!empty($fila['date'])) {
        $ts = strtotime((string) $fila['date']);
        if ($ts) {
            $fecha_db = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    $lead_meta = [
        'leadkit_id'    => $leadkit_id,
        'estado_leadkit'=> (string) ($fila['estado'] ?? ''),
        'responsable_leadkit' => (string) ($fila['responsable'] ?? ''),
        'etiquetas'     => (string) ($fila['etiquetas'] ?? ''),
        'notas_leadkit' => (string) ($fila['notes'] ?? ''),
        'respuestas'    => $fila['_variables'] ?? [],
        'imported_at'   => current_time('mysql'),
        'source'        => 'leadkit_csv',
    ];

    $insert = [
        'delegado'                  => '',
        'user_id'                   => null,
        'email_comercial'           => '',
        'fecha'                     => $fecha_db,
        'cliente_nombre'            => substr($nombre, 0, 255),
        'empresa'                   => '',
        'direccion'                 => '',
        'telefono'                  => '',
        'email_cliente'             => '',
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
        'origen_lead'               => 'placassolares',
        'es_cliente_activo'         => 0,
        'lead_meta'                 => wp_json_encode($lead_meta),
    ];

    $ok = $wpdb->insert($table, $insert);
    if ($ok === false) {
        error_log('CRM LeadKit CSV insert error: ' . $wpdb->last_error);
        return 'err';
    }
    $client_id = (int) $wpdb->insert_id;

    if (function_exists('crm_notes_add')) {
        crm_notes_add([
            'client_id'  => $client_id,
            'tipo'       => 'sistema',
            'texto'      => 'Lead importado desde CSV de LeadKit (id ' . $leadkit_id . ').',
            'autor_id'   => get_current_user_id(),
        ]);
    }
    return 'ok';
}

/**
 * AJAX: sube y procesa un CSV de LeadKit completo.
 */
add_action('wp_ajax_crm_leadkit_csv_importar', 'crm_leadkit_csv_ajax_importar');
function crm_leadkit_csv_ajax_importar() {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    check_ajax_referer('crm_leads_mk', 'nonce');

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        wp_send_json_error(['message' => 'No se recibió ningún archivo.']);
    }
    if (($_FILES['csv']['size'] ?? 0) > 5 * 1024 * 1024) {
        wp_send_json_error(['message' => 'El archivo es demasiado grande (máximo 5 MB).']);
    }

    $contenido = file_get_contents($_FILES['csv']['tmp_name']);
    if ($contenido === false) {
        wp_send_json_error(['message' => 'No se pudo leer el archivo.']);
    }

    $filas = crm_leadkit_csv_parsear($contenido);
    if (empty($filas)) {
        wp_send_json_error(['message' => 'El CSV está vacío o no tiene el formato esperado (cabeceras + filas).']);
    }

    $inserted = 0;
    $dupes    = 0;
    $errors   = 0;
    foreach ($filas as $fila) {
        $resultado = crm_leadkit_csv_insertar_fila($fila);
        if ($resultado === 'ok') {
            $inserted++;
        } elseif ($resultado === 'dup') {
            $dupes++;
        } else {
            $errors++;
        }
    }

    if (function_exists('crm_log_action')) {
        crm_log_action(
            'leadkit_csv_importado',
            sprintf('Importado CSV de LeadKit: %d nuevos, %d duplicados, %d con error (de %d filas).', $inserted, $dupes, $errors, count($filas)),
            null, null, 'info'
        );
    }

    wp_send_json_success([
        'inserted' => $inserted,
        'dupes'    => $dupes,
        'errors'   => $errors,
        'total'    => count($filas),
    ]);
}
