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
 * Formato real verificado contra DOS exportaciones del mismo lote de leads
 * (2026-09-30): LeadKit tiene una opción "básica" (leads-30-09-2026.csv, 9
 * columnas: ID, Date, Nombre, la pregunta de la campaña, Estado,
 * nextActivity, Responsable, Etiquetas, notes — casi todas vacías en la
 * práctica) y otra "con todos los datos" (leads-30-09-2026 (1).csv, 41
 * columnas, con teléfono/email/dirección/población/provincia/CP de verdad).
 * v1.20.150 solo se había probado contra la básica — de ahí que el usuario
 * reportara "solo llega el nombre": no es que el importador los perdiera,
 * es que esa exportación no los trae. crm_leadkit_csv_columnas_fijas()
 * ahora también reconoce las columnas de la exportación completa.
 *
 * El campo "Date" viene envuelto en comillas literales dentro del propio
 * valor (p.ej. '"2026-09-23T06:58:40.000Z"'), fruto de cómo LeadKit exporta
 * el CSV — basta con trim($valor, '"') antes de parsear la fecha. Las
 * preguntas de campaña (cambian de nombre según la campaña, es texto libre
 * de un formulario) NO se mapean por nombre fijo — se guardan tal cual, con
 * su propio encabezado, dentro de lead_meta.respuestas para no perder el
 * dato aunque cambie de un CSV a otro. "Población" aparece DOS veces en la
 * exportación completa (municipio y localidad dentro del municipio) — la
 * primera se usa para el campo fijo, la segunda va a _variables.
 *
 * v1.20.168 — FIX real de parseo: un mensaje de cliente con un salto de
 * línea real dentro (p.ej. "Más información" escrito en varias líneas)
 * partía la fila en dos fragmentos si el CSV se dividía por saltos de línea
 * ANTES de parsear cada "línea" — ahora se usa fgetcsv() sobre un stream en
 * memoria, que sí respeta los saltos de línea dentro de un campo
 * entrecomillado (CSV válido, no un error del exportador).
 *
 * Todos los leads entran como origen_lead='placassolares' (el proveedor,
 * ver crm-plugin.php) pero comparten cola con 'lead_mk' — ver
 * crm_leads_mk_origenes() en includes/leads-mk-shortcode.php. La columna
 * real "Fuente" del CSV (algunos leads son de Aerotermia.es o Luz.es, no
 * solo Placassolares.es) se guarda en lead_meta.respuestas mezclada con el
 * resto de columnas variables — origen_lead no se diferencia por ahora,
 * pendiente de decidir con el usuario si conviene más adelante.
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
 *
 * v1.20.168: ampliado tras verificar contra la exportación "con todos los
 * datos" de LeadKit (antes solo se había probado la básica, que no trae
 * teléfono/email/dirección en absoluto — ver `leads-30-09-2026 (1).csv`
 * frente a `leads-30-09-2026.csv`, mismo lead, dos exportaciones distintas).
 * "Población" aparece DOS veces en esa exportación (municipio y localidad
 * dentro del municipio) — crm_leadkit_csv_parsear() se queda con la
 * primera para el campo fijo y manda la segunda a _variables, no se pierde.
 */
function crm_leadkit_csv_columnas_fijas() {
    return [
        'id', 'date', 'nombre', 'estado', 'nextactivity', 'responsable', 'etiquetas', 'notes',
        'código postal', 'correo electrónico', 'número de teléfono', 'dirección',
        'más información', 'provincia', 'población', 'tipo de usuario',
    ];
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
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', (string) $contenido);

    // v1.20.168 — FIX real: antes se partía el contenido por saltos de
    // línea A MANO antes de parsear cada "línea" con str_getcsv(). Un campo
    // de texto libre con un salto de línea real dentro (frecuente: el
    // cliente escribe su mensaje en varias líneas — verificado en
    // "Más información" de un CSV real de LeadKit) es CSV válido si va
    // entrecomillado, pero partía esa fila en dos fragmentos rotos y
    // descuadraba las columnas de esa fila Y de las siguientes. fgetcsv()
    // sobre un stream en memoria sí respeta los saltos de línea dentro de
    // un campo entrecomillado — por eso se usa aquí en vez de dividir el
    // texto a mano.
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $contenido);
    rewind($stream);

    $cabeceras = fgetcsv($stream);
    if ($cabeceras === false || empty($cabeceras)) {
        fclose($stream);
        return [];
    }
    $cabeceras = array_map('trim', $cabeceras);
    $normalizar = function ($h) {
        $h = trim((string) $h);
        return function_exists('mb_strtolower') ? mb_strtolower($h, 'UTF-8') : strtolower($h);
    };
    $cabeceras_norm = array_map($normalizar, $cabeceras);
    $fijas = crm_leadkit_csv_columnas_fijas();

    $filas = [];
    while (($campos = fgetcsv($stream)) !== false) {
        if (count($campos) === 1 && trim((string) $campos[0]) === '') {
            continue; // línea en blanco (fgetcsv la devuelve como [null] o [''])
        }
        $fila = ['_variables' => []];
        $usadas_fijas = [];
        foreach ($cabeceras_norm as $idx => $h) {
            if ($h === '') {
                continue;
            }
            $valor = isset($campos[$idx]) ? trim((string) $campos[$idx]) : '';
            $valor = trim($valor, '"');
            if (in_array($h, $fijas, true) && !isset($usadas_fijas[$h])) {
                $fila[$h] = $valor;
                $usadas_fijas[$h] = true;
            } elseif ($valor !== '') {
                // Columna variable (pregunta de la campaña, u otra ocurrencia
                // de una cabecera duplicada) — se guarda con su cabecera
                // ORIGINAL (acentos/mayúsculas tal cual venía), no la
                // normalizada, para no perder el texto real. Si dos columnas
                // variables comparten el mismo nombre original, se
                // desambigua con el número de columna en vez de perder una.
                $clave = $cabeceras[$idx];
                if (isset($fila['_variables'][$clave])) {
                    $clave .= ' (col. ' . ($idx + 1) . ')';
                }
                $fila['_variables'][$clave] = $valor;
            }
        }
        $filas[] = $fila;
    }
    fclose($stream);
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

    // v1.20.168 — la exportación "con todos los datos" de LeadKit SÍ trae
    // teléfono/email/dirección/población/provincia/CP con nombre de columna
    // estable (antes se guardaban solo dentro de lead_meta.respuestas,
    // invisibles en la ficha, o se perdían del todo porque el insert los
    // ponía a mano como ''). Cada uno se valida con las MISMAS funciones que
    // ya usa el alta manual de cliente — si no es válido, se deja en blanco
    // en vez de bloquear todo el lead (mismo criterio best-effort de
    // siempre: mejor un dato de menos que descartar el lead entero).
    $telefono = (string) ($fila['número de teléfono'] ?? '');
    if ($telefono !== '' && function_exists('crm_validate_spanish_phone') && !crm_validate_spanish_phone($telefono)) {
        $telefono = '';
    }
    $email = sanitize_email((string) ($fila['correo electrónico'] ?? ''));
    if ($email !== '' && !is_email($email)) {
        $email = '';
    }
    $provincia = '';
    if (!empty($fila['provincia']) && function_exists('crm_normalize_provincia') && function_exists('crm_es_provincia_oficial')) {
        $candidata = crm_normalize_provincia($fila['provincia']);
        if (crm_es_provincia_oficial($candidata)) {
            $provincia = $candidata;
        }
    }
    $codigo_postal = (string) ($fila['código postal'] ?? '');
    if ($codigo_postal !== '' && function_exists('crm_validate_codigo_postal') && !crm_validate_codigo_postal($codigo_postal, $provincia)) {
        $codigo_postal = '';
    }
    $direccion   = sanitize_text_field((string) ($fila['dirección'] ?? ''));
    $poblacion   = sanitize_text_field((string) ($fila['población'] ?? ''));
    $comentarios = sanitize_textarea_field((string) ($fila['más información'] ?? ''));
    // "Tipo de usuario" solo se ha visto como "private" en la exportación
    // real verificada — se deja sin mapear (campo en blanco) cualquier
    // valor que no se reconozca, en vez de adivinar.
    $tipo = '';
    $tipo_usuario_raw = function_exists('mb_strtolower') ? mb_strtolower(trim((string) ($fila['tipo de usuario'] ?? '')), 'UTF-8') : strtolower(trim((string) ($fila['tipo de usuario'] ?? '')));
    if ($tipo_usuario_raw === 'private') {
        $tipo = 'Residencial';
    } elseif (in_array($tipo_usuario_raw, ['company', 'business', 'empresa'], true)) {
        $tipo = 'Empresa';
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
        'direccion'                 => substr($direccion, 0, 255),
        'telefono'                  => $telefono,
        'email_cliente'             => $email,
        'poblacion'                 => substr($poblacion, 0, 255),
        'provincia'                 => $provincia,
        'tipo'                      => $tipo,
        'comentarios'               => $comentarios,
        'codigo_postal'             => $codigo_postal,
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
