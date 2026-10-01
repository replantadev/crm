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
 * v1.20.169 — el CSV mezcla leads de más de un proveedor real en la
 * columna "Fuente" (Placassolares.es, Aerotermia.es, Luz.es — a petición
 * del usuario tras detectarlo en un CSV real). crm_leadkit_csv_origen_desde_fuente()
 * traduce esa columna a origen_lead='placassolares'/'aerotermia'/'luz'
 * (ver crm-plugin.php para las etiquetas); todos comparten cola con
 * 'lead_mk' — ver crm_leads_mk_origenes() en includes/leads-mk-shortcode.php.
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
 * origen_lead posibles para un lead importado de CSV de LeadKit — uno por
 * cada proveedor real visto en la columna "Fuente" (v1.20.169). Única
 * fuente de verdad de esta lista: la usan tanto el dedupe
 * (crm_leadkit_csv_ya_existe()) como el mapeo Fuente→origen_lead
 * (crm_leadkit_csv_origen_desde_fuente()), para que nunca queden
 * desincronizadas entre sí.
 *
 * @return string[]
 */
function crm_leadkit_csv_origenes_posibles() {
    return ['placassolares', 'aerotermia', 'luz'];
}

/**
 * Traduce el valor real de la columna "Fuente" del CSV a un origen_lead del
 * CRM — devuelve null si no coincide con ninguno de los proveedores
 * conocidos (en vez de adivinar), para que el llamador decida qué hacer con
 * una Fuente nueva en vez de asignarla en silencio a un proveedor que no es
 * (v1.20.171 — a petición del usuario: reconocer un proveedor nuevo de
 * verdad "sería lo apropiado", pero decidirlo solo, sin que nadie lo vea,
 * puede meter ruido de una fuente puntual/typo tan fácil como un proveedor
 * real recurrente. Avisar y que decida una persona es el término medio).
 *
 * Para dar de alta un proveedor nuevo DE VERDAD (confirmado, no puntual):
 * 1) Añadir aquí el patrón que lo identifica.
 * 2) crm_leadkit_csv_origenes_posibles() — añadir el slug.
 * 3) crm-plugin.php — $origenes (desplegable de la ficha) y $origenes_validos
 *    (validación server-side).
 * 4) includes/funnel-ventas.php — crm_funnel_origenes_label() y $origenes_con_cola.
 * 5) includes/leads-mk-shortcode.php — crm_leads_mk_origenes() y $origen_labels.
 *
 * @param string $fuente_raw Valor tal cual de la columna "Fuente" (p.ej. "Placassolares.es").
 * @return string|null Slug de origen_lead, o null si no se reconoce.
 */
function crm_leadkit_csv_origen_desde_fuente($fuente_raw) {
    $f = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $fuente_raw), 'UTF-8') : strtolower(trim((string) $fuente_raw));
    if ($f === '') {
        return null;
    }
    if (strpos($f, 'placassolares') !== false) {
        return 'placassolares';
    }
    if (strpos($f, 'aerotermia') !== false) {
        return 'aerotermia';
    }
    if (strpos($f, 'luz.es') !== false || $f === 'luz') {
        return 'luz';
    }
    return null;
}

/**
 * Busca un cliente ya importado con este ID de LeadKit (dedupe). Búsqueda
 * por texto sobre el JSON de lead_meta — sin depender de funciones JSON de
 * MySQL que puede que el hosting no tenga. Busca entre TODOS los orígenes
 * posibles de un lead de LeadKit (v1.20.169: antes solo miraba
 * 'placassolares' — con varias Fuentes posibles, un lead guardado como
 * 'aerotermia' habría pasado el dedupe como si nunca se hubiera importado).
 *
 * @param string $leadkit_id
 * @return int client_id, o 0 si no existe.
 */
function crm_leadkit_csv_buscar_client_id($leadkit_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $origenes = crm_leadkit_csv_origenes_posibles();
    $placeholders = implode(',', array_fill(0, count($origenes), '%s'));
    $args = array_merge($origenes, ['%"leadkit_id":"' . $wpdb->esc_like($leadkit_id) . '"%']);
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE origen_lead IN ($placeholders) AND lead_meta LIKE %s LIMIT 1",
        $args
    ));
}

/**
 * Extrae y valida de una fila ya parseada los campos que van a columnas
 * reales de wp_crm_clients — compartido entre la inserción de un lead nuevo
 * y la reparación de uno ya existente (v1.20.170), para no mantener la
 * misma validación duplicada en dos sitios.
 *
 * @param array $fila
 * @return array{telefono:string, email:string, provincia:string, codigo_postal:string,
 *               direccion:string, poblacion:string, comentarios:string, tipo:string,
 *               origen_lead:string, fuente_raw:string}
 */
function crm_leadkit_csv_extraer_campos(array $fila) {
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

    // v1.20.169 — a petición del usuario: el CSV trae leads de más de un
    // proveedor mezclados en la columna "Fuente" (que hasta ahora se
    // guardaba solo dentro de lead_meta.respuestas, sin diferenciarlos).
    // "Fuente" es una columna variable (no fija), así que se lee de
    // _variables igual que cualquier otra — su valor real no cambia por
    // reconocerlo aquí, solo decide a qué origen_lead se asigna el cliente.
    //
    // v1.20.171 — si no se reconoce (proveedor nuevo, typo, o un valor
    // puntual), NO se asigna en silencio a 'placassolares' sin que nadie se
    // entere: se guarda ahí igual (hace falta algún origen_lead válido para
    // que el lead no quede invisible), pero se marca fuente_reconocida=false
    // para que crm_leadkit_csv_ajax_importar() lo agregue y lo avise en el
    // resultado — decide una persona si merece darlo de alta de verdad.
    $fuente_raw       = (string) ($fila['_variables']['Fuente'] ?? '');
    $origen_detectado = crm_leadkit_csv_origen_desde_fuente($fuente_raw);
    $fuente_reconocida = $origen_detectado !== null;
    $origen_lead = $fuente_reconocida ? $origen_detectado : 'placassolares';

    return compact('telefono', 'email', 'provincia', 'codigo_postal', 'direccion', 'poblacion', 'comentarios', 'tipo', 'origen_lead', 'fuente_raw', 'fuente_reconocida');
}

/**
 * Añade el texto nuevo (lo que trae el CSV en "Más información") al texto de
 * comentarios ya existente, sin pisarlo ni duplicarlo — a diferencia del
 * resto de campos (teléfono, email, dirección...), un comentario no es un
 * dato "de una sola verdad": el mismo lead puede escribir un mensaje nuevo
 * en una campaña posterior y el anterior sigue siendo válido, no hay que
 * elegir uno (v1.20.182, a petición del usuario — "súper importante").
 *
 * Si el texto nuevo ya está contenido literalmente en el actual (reimportar
 * el mismo CSV dos veces, por ejemplo) no se repite.
 *
 * @param string $actual
 * @param string $nuevo
 * @return string
 */
function crm_leadkit_csv_fusionar_comentarios($actual, $nuevo) {
    $actual = trim((string) $actual);
    $nuevo  = trim((string) $nuevo);
    if ($nuevo === '') {
        return $actual;
    }
    if ($actual === '') {
        return $nuevo;
    }
    if (mb_strpos($actual, $nuevo) !== false) {
        return $actual;
    }
    $fecha = function_exists('date_i18n') ? date_i18n('d/m/Y') : date('d/m/Y');
    return $actual . "\n\n— Nuevo mensaje del lead (LeadKit, {$fecha}):\n" . $nuevo;
}

/**
 * v1.20.170 — a petición del usuario: reimportar el mismo CSV solo
 * detectaba duplicados y los saltaba, así que los leads que habían entrado
 * antes (v1.20.150-168) solo con el nombre se quedaban así para siempre —
 * nadie los "arreglaba" reimportando. Rellena SOLO los campos que sigan
 * vacíos en la ficha ya existente con lo que traiga el CSV ahora — igual
 * que la sincro de Holded con el código postal: nunca pisa un dato que ya
 * hubiera (a mano o de una importación anterior). Los comentarios son la
 * única excepción: se fusionan (ver crm_leadkit_csv_fusionar_comentarios()),
 * nunca se descartan por no estar vacíos (v1.20.182).
 *
 * @param int   $client_id
 * @param array $campos Lo que devuelve crm_leadkit_csv_extraer_campos().
 * @return array{actualizado:bool, asignado:bool} `asignado` = si la ficha ya
 *         tenía comercial (user_id) antes de esta reparación, para que el
 *         resumen de la importación pueda avisar de cuáles siguen sin asignar.
 */
function crm_leadkit_csv_reparar_fila($client_id, array $campos) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $actual = $wpdb->get_row($wpdb->prepare(
        "SELECT telefono, email_cliente, direccion, poblacion, provincia, codigo_postal, tipo, comentarios, user_id FROM $table WHERE id = %d",
        $client_id
    ), ARRAY_A);
    if (!$actual) {
        return ['actualizado' => false, 'asignado' => false];
    }
    $asignado = !empty($actual['user_id']);

    $mapa_columnas = [
        'telefono'      => $campos['telefono'],
        'email_cliente' => $campos['email'],
        'direccion'     => $campos['direccion'],
        'poblacion'     => $campos['poblacion'],
        'provincia'     => $campos['provincia'],
        'codigo_postal' => $campos['codigo_postal'],
        'tipo'          => $campos['tipo'],
    ];
    $update = [];
    foreach ($mapa_columnas as $columna => $valor_csv) {
        if ($valor_csv !== '' && empty($actual[$columna])) {
            $update[$columna] = $valor_csv;
        }
    }

    $comentarios_fusionados = crm_leadkit_csv_fusionar_comentarios($actual['comentarios'] ?? '', $campos['comentarios']);
    if ($comentarios_fusionados !== trim((string) ($actual['comentarios'] ?? ''))) {
        $update['comentarios'] = $comentarios_fusionados;
    }

    if (empty($update)) {
        return ['actualizado' => false, 'asignado' => $asignado];
    }

    $wpdb->update($table, $update, ['id' => $client_id]);

    if (function_exists('crm_notes_add')) {
        crm_notes_add([
            'client_id' => $client_id,
            'tipo'      => 'sistema',
            'texto'     => 'Datos completados al reimportar el CSV de LeadKit: ' . implode(', ', array_keys($update)) . '.',
            'autor_id'  => get_current_user_id(),
        ]);
    }
    return ['actualizado' => true, 'asignado' => $asignado];
}

/**
 * Inserta una fila del CSV como lead nuevo (origen_lead según la Fuente
 * real del CSV — placassolares/aerotermia/luz, ver
 * crm_leadkit_csv_origen_desde_fuente()), sin comercial asignado — entra en
 * la misma cola que los leads MK. Si el lead YA existe (mismo ID de
 * LeadKit), no se duplica: se intenta reparar con crm_leadkit_csv_reparar_fila().
 *
 * v1.20.171: devuelve un array en vez de un string suelto para poder
 * arrastrar también si la Fuente del CSV se reconoció o no — así
 * crm_leadkit_csv_ajax_importar() puede avisar de Fuentes nuevas/puntuales
 * sin tener que volver a calcular los campos por su cuenta.
 *
 * @param array $fila Fila ya parseada por crm_leadkit_csv_parsear().
 * @return array{resultado:string, fuente_raw:string, fuente_reconocida:bool, asignado:bool}
 *         resultado: 'ok'|'reparado'|'dup'|'err'. `asignado`: si el lead (ya
 *         existente) tiene comercial asignado — para el resumen de la
 *         importación (v1.20.182).
 */
function crm_leadkit_csv_insertar_fila(array $fila) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $leadkit_id = (string) ($fila['id'] ?? '');
    $nombre     = (string) ($fila['nombre'] ?? '');
    if ($leadkit_id === '' || $nombre === '') {
        return ['resultado' => 'err', 'fuente_raw' => '', 'fuente_reconocida' => true, 'asignado' => false];
    }

    $campos = crm_leadkit_csv_extraer_campos($fila);
    $meta_resultado = ['fuente_raw' => $campos['fuente_raw'], 'fuente_reconocida' => $campos['fuente_reconocida']];

    $client_id_existente = crm_leadkit_csv_buscar_client_id($leadkit_id);
    if ($client_id_existente > 0) {
        $reparo = crm_leadkit_csv_reparar_fila($client_id_existente, $campos);
        return ['resultado' => $reparo['actualizado'] ? 'reparado' : 'dup', 'asignado' => $reparo['asignado']] + $meta_resultado;
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
        'direccion'                 => substr($campos['direccion'], 0, 255),
        'telefono'                  => $campos['telefono'],
        'email_cliente'             => $campos['email'],
        'poblacion'                 => substr($campos['poblacion'], 0, 255),
        'provincia'                 => $campos['provincia'],
        'tipo'                      => $campos['tipo'],
        'comentarios'               => $campos['comentarios'],
        'codigo_postal'             => $campos['codigo_postal'],
        'intereses'                 => maybe_serialize([]),
        'estado'                    => 'borrador',
        'estado_por_sector'         => maybe_serialize([]),
        'fecha_envio_por_sector'    => '',
        'usuario_envio_por_sector'  => '',
        'creado_por'                => 0,
        'creado_en'                 => current_time('mysql'),
        'origen_lead'               => $campos['origen_lead'],
        'es_cliente_activo'         => 0,
        'lead_meta'                 => wp_json_encode($lead_meta),
    ];

    $ok = $wpdb->insert($table, $insert);
    if ($ok === false) {
        error_log('CRM LeadKit CSV insert error: ' . $wpdb->last_error);
        return ['resultado' => 'err', 'asignado' => false] + $meta_resultado;
    }
    $client_id = (int) $wpdb->insert_id;

    if (function_exists('crm_notes_add')) {
        $texto = 'Lead importado desde CSV de LeadKit (id ' . $leadkit_id . ', fuente ' . ($campos['fuente_raw'] ?: 'sin especificar') . ').';
        if (!$campos['fuente_reconocida']) {
            $texto .= ' ⚠ Fuente no reconocida — asignado a "placassolares" por defecto, revisar si es un proveedor nuevo.';
        }
        crm_notes_add([
            'client_id'  => $client_id,
            'tipo'       => 'sistema',
            'texto'      => $texto,
            'autor_id'   => get_current_user_id(),
        ]);
    }
    return ['resultado' => 'ok', 'asignado' => false] + $meta_resultado;
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

    $inserted  = 0;
    $reparados = 0;
    $dupes     = 0;
    $errors    = 0;
    // v1.20.182 — de los reparados/duplicados, cuántos YA tienen comercial
    // asignado — para que el resumen avise de cuáles siguen sin asignar en
    // vez de tener que abrir ficha por ficha para saberlo.
    $reparados_sin_asignar = 0;
    $dupes_sin_asignar     = 0;
    // v1.20.171 — Fuentes del CSV que no encajan con ningún proveedor
    // conocido: se cuentan aparte (agrupadas por el texto real de la
    // Fuente) para avisar en el resultado, sin necesidad de rebuscar en el
    // log o en cada ficha una por una.
    $fuentes_desconocidas = [];
    foreach ($filas as $fila) {
        $r = crm_leadkit_csv_insertar_fila($fila);
        if ($r['resultado'] === 'ok') {
            $inserted++;
        } elseif ($r['resultado'] === 'reparado') {
            $reparados++;
            if (empty($r['asignado'])) { $reparados_sin_asignar++; }
        } elseif ($r['resultado'] === 'dup') {
            $dupes++;
            if (empty($r['asignado'])) { $dupes_sin_asignar++; }
        } else {
            $errors++;
        }
        if (!$r['fuente_reconocida']) {
            $clave = $r['fuente_raw'] !== '' ? $r['fuente_raw'] : '(sin especificar)';
            $fuentes_desconocidas[$clave] = ($fuentes_desconocidas[$clave] ?? 0) + 1;
        }
    }

    if (function_exists('crm_log_action')) {
        $detalle_fuentes = !empty($fuentes_desconocidas)
            ? ' Fuentes no reconocidas (asignadas a "placassolares" por defecto): ' . implode(', ', array_map(
                function ($fuente, $n) { return $fuente . ' (' . $n . ')'; },
                array_keys($fuentes_desconocidas), $fuentes_desconocidas
            )) . '.'
            : '';
        crm_log_action(
            'leadkit_csv_importado',
            sprintf(
                'Importado CSV de LeadKit: %d nuevos, %d reparados (%d sin comercial), %d duplicados (%d sin comercial), %d con error (de %d filas).',
                $inserted, $reparados, $reparados_sin_asignar, $dupes, $dupes_sin_asignar, $errors, count($filas)
            ) . $detalle_fuentes,
            null, null, empty($fuentes_desconocidas) ? 'info' : 'notice'
        );
    }

    wp_send_json_success([
        'inserted'              => $inserted,
        'reparados'             => $reparados,
        'reparados_sin_asignar' => $reparados_sin_asignar,
        'dupes'                 => $dupes,
        'dupes_sin_asignar'     => $dupes_sin_asignar,
        'errors'                => $errors,
        'total'                 => count($filas),
        'fuentes_desconocidas'  => $fuentes_desconocidas,
    ]);
}
