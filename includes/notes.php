<?php
/**
 * CRM Energitel - Notas históricas por cliente.
 *
 * Sistema de notas que actúa como bitácora del cliente:
 *  - Notas manuales escritas por comerciales o admin.
 *  - Auto-notas generadas por cambios de estado, subida de archivos
 *    y reasignación de comercial (NO por cada edición de campo).
 *  - Búsqueda full-text (FTS) sobre el texto de la nota.
 *  - Timeline vertical que se renderiza dentro de la ficha del cliente.
 *
 * @package CRM_Energitel
 * @since   1.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ============================================================
 * Constantes
 * ============================================================ */

if (!defined('CRM_NOTES_TABLE')) {
    define('CRM_NOTES_TABLE', 'crm_notes'); // se prefija con wp_ en runtime
}

if (!defined('CRM_NOTE_MAX_LEN')) {
    define('CRM_NOTE_MAX_LEN', 5000);
}

/**
 * Tipos de nota válidos.
 *
 * @return string[]
 */
function crm_notes_get_tipos() {
    return [
        'manual',
        'state_change',
        'file_upload',
        'file_remove',
        'assignment',
        'presupuesto_aceptado',
        'contrato_generado',
        'sistema',
    ];
}

/* ============================================================
 * Tabla
 * ============================================================ */

/**
 * Devuelve el nombre completo (con prefijo) de la tabla de notas.
 */
function crm_notes_table_name() {
    global $wpdb;
    return $wpdb->prefix . CRM_NOTES_TABLE;
}

/**
 * Crea la tabla `wp_crm_notes` si no existe, e instala el índice FULLTEXT.
 *
 * Es idempotente: se puede llamar en cada init del plugin sin coste
 * (consulta única `SHOW TABLES LIKE` y `SHOW INDEX`).
 */
function crm_notes_install_table() {
    global $wpdb;
    $table = crm_notes_table_name();
    $charset = $wpdb->get_charset_collate();

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) {
        // Usamos CREATE TABLE manual (no dbDelta) porque dbDelta no maneja
        // FULLTEXT de forma fiable y puede dejar índices duplicados.
        $sql = "CREATE TABLE `$table` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT(11) UNSIGNED NOT NULL,
            sector VARCHAR(50) DEFAULT NULL,
            estado VARCHAR(50) DEFAULT NULL,
            tipo VARCHAR(40) NOT NULL DEFAULT 'manual',
            autor_id BIGINT(20) UNSIGNED DEFAULT NULL,
            autor_name VARCHAR(255) DEFAULT NULL,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            texto TEXT NOT NULL,
            attachment_url VARCHAR(500) DEFAULT NULL,
            source_action VARCHAR(100) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY client_sector (client_id, sector),
            KEY tipo (tipo),
            KEY fecha (fecha),
            FULLTEXT KEY ft_texto (texto)
        ) ENGINE=InnoDB $charset";
        // Suprimimos errores: si el host no permite FULLTEXT en InnoDB
        // (MySQL < 5.6) reintentamos con MyISAM como fallback.
        $wpdb->hide_errors();
        $ok = $wpdb->query($sql);
        $wpdb->show_errors();
        if ($ok === false) {
            $sql_fallback = str_replace('ENGINE=InnoDB', 'ENGINE=MyISAM', $sql);
            $wpdb->query($sql_fallback);
        }
        if (function_exists('error_log')) {
            error_log("CRM: tabla $table creada");
        }
        return;
    }

    // Tabla ya existente — comprobar índice FULLTEXT.
    $has_ft = $wpdb->get_var($wpdb->prepare(
        "SHOW INDEX FROM `$table` WHERE Key_name = %s",
        'ft_texto'
    ));
    if (!$has_ft) {
        $wpdb->hide_errors();
        $wpdb->query("ALTER TABLE `$table` ADD FULLTEXT KEY ft_texto (texto)");
        $wpdb->show_errors();
    }
}

/* ============================================================
 * CRUD básico
 * ============================================================ */

/**
 * Inserta una nota.
 *
 * @param array $args {
 *     @type int    $client_id      ID del cliente (requerido).
 *     @type string $texto          Contenido (requerido). Se sanea.
 *     @type string $tipo           Tipo de nota (manual por defecto).
 *     @type string $sector         Sector relacionado (opcional).
 *     @type string $estado         Estado del sector al momento (opcional).
 *     @type int    $autor_id       ID del autor (defecto: usuario actual).
 *     @type string $autor_name     Nombre del autor (defecto: display_name).
 *     @type string $attachment_url URL del archivo asociado (opcional).
 *     @type string $source_action  Acción origen (auto-notas).
 * }
 * @return int|false ID de la nota creada o false en error.
 */
function crm_notes_add($args) {
    global $wpdb;

    $defaults = [
        'client_id'      => 0,
        'texto'          => '',
        'tipo'           => 'manual',
        'sector'         => null,
        'estado'         => null,
        'autor_id'       => null,
        'autor_name'     => null,
        'attachment_url' => null,
        'source_action'  => null,
    ];
    $a = array_merge($defaults, (array) $args);

    $client_id = (int) $a['client_id'];
    if ($client_id <= 0) {
        return false;
    }

    // Sanitización defensiva del texto. wp_kses_post permite HTML básico
    // (negritas, links) pero filtra scripts. Cortamos por longitud máxima.
    $texto = is_string($a['texto']) ? trim($a['texto']) : '';
    if ($texto === '') {
        return false;
    }
    $texto = wp_kses_post($texto);
    if (function_exists('mb_substr')) {
        $texto = mb_substr($texto, 0, CRM_NOTE_MAX_LEN, 'UTF-8');
    } else {
        $texto = substr($texto, 0, CRM_NOTE_MAX_LEN);
    }

    $tipo = sanitize_key((string) $a['tipo']);
    if (!in_array($tipo, crm_notes_get_tipos(), true)) {
        $tipo = 'manual';
    }

    $sector = $a['sector'] !== null ? sanitize_key((string) $a['sector']) : null;
    if ($sector === '') {
        $sector = null;
    }
    $estado = $a['estado'] !== null ? sanitize_key((string) $a['estado']) : null;
    if ($estado === '') {
        $estado = null;
    }

    $autor_id = $a['autor_id'] !== null ? (int) $a['autor_id'] : get_current_user_id();
    $autor_name = $a['autor_name'];
    if (!$autor_name) {
        $u = $autor_id > 0 ? get_userdata($autor_id) : null;
        $autor_name = $u ? $u->display_name : '';
    }
    $autor_name = sanitize_text_field((string) $autor_name);

    $attachment_url = $a['attachment_url'] !== null
        ? esc_url_raw((string) $a['attachment_url'])
        : null;
    if ($attachment_url === '') {
        $attachment_url = null;
    }

    $source_action = $a['source_action'] !== null
        ? sanitize_text_field((string) $a['source_action'])
        : null;

    $row = [
        'client_id'      => $client_id,
        'sector'         => $sector,
        'estado'         => $estado,
        'tipo'           => $tipo,
        'autor_id'       => $autor_id > 0 ? $autor_id : null,
        'autor_name'     => $autor_name !== '' ? $autor_name : null,
        'fecha'          => current_time('mysql'),
        'texto'          => $texto,
        'attachment_url' => $attachment_url,
        'source_action'  => $source_action,
    ];
    $formats = ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'];

    $ok = $wpdb->insert(crm_notes_table_name(), $row, $formats);
    if ($ok === false) {
        if (function_exists('error_log')) {
            error_log('CRM notes: insert failed: ' . $wpdb->last_error);
        }
        return false;
    }
    return (int) $wpdb->insert_id;
}

/**
 * Devuelve las notas de un cliente, ordenadas de más reciente a más antigua.
 *
 * @param int      $client_id
 * @param array    $args {
 *     @type string|null $sector  Filtrar por sector exacto.
 *     @type string|null $tipo    Filtrar por tipo.
 *     @type int         $limit   Máx. resultados (defecto 500).
 *     @type int         $offset  Offset paginación.
 * }
 * @return array Lista de notas (asociativas).
 */
function crm_notes_get_for_client($client_id, $args = []) {
    global $wpdb;
    $client_id = (int) $client_id;
    if ($client_id <= 0) {
        return [];
    }

    $args = array_merge([
        'sector' => null,
        'tipo'   => null,
        'limit'  => 500,
        'offset' => 0,
    ], (array) $args);

    $table = crm_notes_table_name();
    $where = ['client_id = %d'];
    $params = [$client_id];
    if (!empty($args['sector'])) {
        $where[]  = 'sector = %s';
        $params[] = sanitize_key((string) $args['sector']);
    }
    if (!empty($args['tipo'])) {
        $where[]  = 'tipo = %s';
        $params[] = sanitize_key((string) $args['tipo']);
    }
    $limit  = max(1, min(2000, (int) $args['limit']));
    $offset = max(0, (int) $args['offset']);
    $params[] = $limit;
    $params[] = $offset;

    $sql = "SELECT * FROM `$table` WHERE " . implode(' AND ', $where)
         . " ORDER BY fecha DESC, id DESC LIMIT %d OFFSET %d";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
}

/**
 * Búsqueda full-text de notas.
 *
 * Si el servidor no soporta FTS o la búsqueda devuelve vacío, hace
 * fallback a `LIKE` para no quedarse mudo.
 *
 * @param string   $query
 * @param int|null $client_id Acotar a un cliente (opcional).
 * @param int      $limit
 * @return array
 */
function crm_notes_search($query, $client_id = null, $limit = 50) {
    global $wpdb;
    $query = trim((string) $query);
    if ($query === '') {
        return [];
    }
    $table = crm_notes_table_name();
    $limit = max(1, min(500, (int) $limit));

    $where_extra = '';
    $params_extra = [];
    if ($client_id !== null && (int) $client_id > 0) {
        $where_extra = ' AND client_id = %d';
        $params_extra[] = (int) $client_id;
    }

    // 1) Intento FULLTEXT en boolean mode (mejor relevancia y soporta + y -).
    $boolean_query = crm_notes_build_boolean_query($query);
    $sql_ft = "SELECT *, MATCH(texto) AGAINST(%s IN BOOLEAN MODE) AS score
               FROM `$table`
               WHERE MATCH(texto) AGAINST(%s IN BOOLEAN MODE)$where_extra
               ORDER BY score DESC, fecha DESC
               LIKE_PLACEHOLDER";
    // Hack: no podemos meter LIMIT como %d después de variable concatenada
    // sin reparar el orden de parámetros, así que reconstruimos manualmente.
    $params = array_merge([$boolean_query, $boolean_query], $params_extra, [$limit]);
    $sql_ft = str_replace('LIKE_PLACEHOLDER', 'LIMIT %d', $sql_ft);

    $wpdb->hide_errors();
    $rows = $wpdb->get_results($wpdb->prepare($sql_ft, $params), ARRAY_A);
    $wpdb->show_errors();

    if (is_array($rows) && !empty($rows)) {
        return $rows;
    }

    // 2) Fallback LIKE (case-insensitive en collation utf8mb4_unicode_ci).
    $like = '%' . $wpdb->esc_like($query) . '%';
    $sql_like = "SELECT * FROM `$table` WHERE texto LIKE %s$where_extra
                 ORDER BY fecha DESC LIMIT %d";
    $params2 = array_merge([$like], $params_extra, [$limit]);
    $rows = $wpdb->get_results($wpdb->prepare($sql_like, $params2), ARRAY_A);
    return is_array($rows) ? $rows : [];
}

/**
 * Construye una query boolean-mode segura a partir de texto libre.
 *
 * Estrategia: añadir `+` a cada palabra (>=3 chars) para forzar AND, y
 * usar `*` al final para prefix-match. Las palabras cortas se descartan
 * porque InnoDB tiene `innodb_ft_min_token_size = 3` por defecto.
 */
function crm_notes_build_boolean_query($q) {
    $q = preg_replace('/[+\-><()~*\"@]+/u', ' ', $q);
    $tokens = preg_split('/\s+/u', trim($q));
    $clean  = [];
    foreach ($tokens as $t) {
        if (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') >= 3 : strlen($t) >= 3) {
            $clean[] = '+' . $t . '*';
        }
    }
    if (empty($clean)) {
        // Sin tokens válidos: devolvemos algo que no matchee, para forzar el
        // fallback LIKE.
        return '+__nomatch__';
    }
    return implode(' ', $clean);
}

/**
 * Elimina una nota (solo crm_admin o autor original).
 *
 * @param int $note_id
 * @return bool
 */
function crm_notes_delete($note_id) {
    global $wpdb;
    $note_id = (int) $note_id;
    if ($note_id <= 0) {
        return false;
    }
    $table = crm_notes_table_name();
    $row = $wpdb->get_row($wpdb->prepare("SELECT autor_id FROM `$table` WHERE id = %d", $note_id), ARRAY_A);
    if (!$row) {
        return false;
    }
    $uid = get_current_user_id();
    if (!current_user_can('crm_admin') && (int) $row['autor_id'] !== $uid) {
        return false;
    }
    return (bool) $wpdb->delete($table, ['id' => $note_id], ['%d']);
}

/* ============================================================
 * Auto-notas (event-driven)
 * ============================================================ */

/**
 * Auto-nota: cambio de estado de un sector.
 *
 * @param int    $client_id
 * @param string $sector
 * @param string $estado_old
 * @param string $estado_new
 */
function crm_notes_log_state_change($client_id, $sector, $estado_old, $estado_new) {
    if ($estado_old === $estado_new) {
        return;
    }
    $label_old = function_exists('crm_get_estado_label')
        ? crm_get_estado_label($estado_old) : $estado_old;
    $label_new = function_exists('crm_get_estado_label')
        ? crm_get_estado_label($estado_new) : $estado_new;

    $texto = sprintf(
        'Estado de %s: <em>%s</em> → <strong>%s</strong>',
        ucfirst((string) $sector),
        esc_html((string) $label_old),
        esc_html((string) $label_new)
    );
    crm_notes_add([
        'client_id'     => $client_id,
        'sector'        => $sector,
        'estado'        => $estado_new,
        'tipo'          => 'state_change',
        'texto'         => $texto,
        'source_action' => 'auto_state_change',
    ]);
}

/**
 * Auto-nota: archivo subido para un sector.
 */
function crm_notes_log_file_upload($client_id, $sector, $tipo_archivo, $url) {
    $nombre  = basename((string) $url);
    $tipo_label = [
        'factura'           => 'factura',
        'presupuesto'       => 'presupuesto',
        'contrato_firmado'  => 'contrato firmado',
    ][$tipo_archivo] ?? $tipo_archivo;
    $texto = sprintf(
        'Subido %s para %s: <a href="%s" target="_blank" rel="noopener">%s</a>',
        esc_html((string) $tipo_label),
        ucfirst((string) $sector),
        esc_url((string) $url),
        esc_html($nombre)
    );
    crm_notes_add([
        'client_id'      => $client_id,
        'sector'         => $sector,
        'tipo'           => 'file_upload',
        'texto'          => $texto,
        'attachment_url' => $url,
        'source_action'  => 'auto_file_upload',
    ]);
}

/**
 * Auto-nota: reasignación de comercial.
 */
function crm_notes_log_assignment($client_id, $user_old_id, $user_new_id) {
    if ((int) $user_old_id === (int) $user_new_id) {
        return;
    }
    $u_old = $user_old_id ? get_userdata($user_old_id) : null;
    $u_new = $user_new_id ? get_userdata($user_new_id) : null;
    $name_old = $u_old ? $u_old->display_name : '—';
    $name_new = $u_new ? $u_new->display_name : '—';
    $texto = sprintf(
        'Comercial asignado: <em>%s</em> → <strong>%s</strong>',
        esc_html($name_old),
        esc_html($name_new)
    );
    crm_notes_add([
        'client_id'     => $client_id,
        'tipo'          => 'assignment',
        'texto'         => $texto,
        'source_action' => 'auto_assignment',
    ]);
}

/* ============================================================
 * AJAX
 * ============================================================ */

add_action('wp_ajax_crm_note_add',    'crm_notes_ajax_add');
add_action('wp_ajax_crm_note_list',   'crm_notes_ajax_list');
add_action('wp_ajax_crm_note_search', 'crm_notes_ajax_search');
add_action('wp_ajax_crm_note_delete', 'crm_notes_ajax_delete');

/**
 * Comprueba si el usuario actual puede acceder a las notas del cliente
 * indicado. Admins (crm_admin o manage_options) pueden todo; comerciales
 * sólo a sus propios clientes.
 *
 * @param int $client_id
 * @return bool
 */
function crm_notes_user_can_access_client($client_id) {
    if (!is_user_logged_in()) {
        return false;
    }
    if (current_user_can('crm_admin') || current_user_can('manage_options')) {
        return true;
    }
    global $wpdb;
    $owner = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}crm_clients WHERE id = %d",
        (int) $client_id
    ));
    return $owner > 0 && $owner === get_current_user_id();
}

/**
 * AJAX: añadir nota manual.
 */
function crm_notes_ajax_add() {
    check_ajax_referer('crm_alta_cliente_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
    if ($client_id <= 0) {
        wp_send_json_error(['message' => 'Cliente inválido'], 400);
    }
    if (!crm_notes_user_can_access_client($client_id)) {
        wp_send_json_error(['message' => 'Sin permisos sobre este cliente'], 403);
    }
    $texto = isset($_POST['texto']) ? wp_unslash($_POST['texto']) : '';
    $sector = isset($_POST['sector']) ? sanitize_key($_POST['sector']) : null;

    $id = crm_notes_add([
        'client_id' => $client_id,
        'texto'     => $texto,
        'tipo'      => 'manual',
        'sector'    => $sector ?: null,
    ]);
    if (!$id) {
        wp_send_json_error(['message' => 'No se pudo guardar la nota (texto vacío o inválido).']);
    }
    $note = crm_notes_get_by_id($id);
    wp_send_json_success([
        'id'   => $id,
        'note' => $note,
        'html' => crm_notes_render_item($note),
    ]);
}

/**
 * AJAX: listar notas de un cliente.
 */
function crm_notes_ajax_list() {
    check_ajax_referer('crm_alta_cliente_nonce', 'nonce');
    $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
    if (!crm_notes_user_can_access_client($client_id)) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }
    $sector    = isset($_POST['sector']) ? sanitize_key($_POST['sector']) : null;
    $notes = crm_notes_get_for_client($client_id, ['sector' => $sector ?: null]);
    wp_send_json_success([
        'count' => count($notes),
        'html'  => crm_notes_render_timeline_html($notes),
    ]);
}

/**
 * AJAX: buscar notas (global o por cliente).
 */
function crm_notes_ajax_search() {
    check_ajax_referer('crm_alta_cliente_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    $q         = isset($_POST['q']) ? wp_unslash($_POST['q']) : '';
    $client_id = isset($_POST['client_id']) && (int) $_POST['client_id'] > 0
        ? (int) $_POST['client_id']
        : null;
    if ($client_id !== null && !crm_notes_user_can_access_client($client_id)) {
        wp_send_json_error(['message' => 'Sin permisos sobre este cliente'], 403);
    }
    // Búsqueda global solo para admin.
    if ($client_id === null && !current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'Búsqueda global solo para administradores'], 403);
    }
    $rows = crm_notes_search($q, $client_id, 100);
    wp_send_json_success([
        'count' => count($rows),
        'rows'  => $rows,
    ]);
}

/**
 * AJAX: borrar nota.
 */
function crm_notes_ajax_delete() {
    check_ajax_referer('crm_alta_cliente_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    $note_id = isset($_POST['note_id']) ? (int) $_POST['note_id'] : 0;
    if (!crm_notes_delete($note_id)) {
        wp_send_json_error(['message' => 'No autorizado o nota inexistente'], 403);
    }
    wp_send_json_success(['id' => $note_id]);
}

/* ============================================================
 * Render
 * ============================================================ */

/**
 * Devuelve una nota por id (helper para render tras insertar).
 */
function crm_notes_get_by_id($note_id) {
    global $wpdb;
    $note_id = (int) $note_id;
    if ($note_id <= 0) {
        return null;
    }
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `" . crm_notes_table_name() . "` WHERE id = %d", $note_id),
        ARRAY_A
    );
    return $row ?: null;
}

/**
 * Renderiza el bloque completo "Historial / Notas" para una ficha de cliente.
 *
 * Incluye el formulario de añadir nota (si el usuario está logueado) y la
 * lista (timeline vertical).
 *
 * @param int $client_id
 * @return string HTML
 */
function crm_notes_render_block($client_id) {
    $client_id = (int) $client_id;
    if ($client_id <= 0) {
        return '';
    }
    $notes = crm_notes_get_for_client($client_id);

    ob_start();
    ?>
    <div class="crm-notes-block" data-client-id="<?php echo esc_attr($client_id); ?>">
        <div class="crm-notes-header">
            <h3>Historial y notas</h3>
            <div class="crm-notes-search">
                <input type="search" class="crm-notes-search-input"
                       placeholder="Buscar en notas..." aria-label="Buscar en notas">
                <button type="button" class="crm-notes-search-clear" title="Limpiar">&times;</button>
            </div>
        </div>

        <div class="crm-notes-add">
            <textarea class="crm-note-textarea" rows="2"
                      placeholder="Añadir una nota manual sobre este cliente..."
                      maxlength="<?php echo esc_attr(CRM_NOTE_MAX_LEN); ?>"></textarea>
            <div class="crm-note-add-row">
                <select class="crm-note-sector-select" aria-label="Sector relacionado">
                    <option value="">Sin sector específico</option>
                    <?php foreach (['energia','alarmas','telecomunicaciones','seguros','renovables'] as $s): ?>
                        <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html(ucfirst($s)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="crm-note-add-btn button button-primary">Añadir nota</button>
            </div>
        </div>

        <div class="crm-notes-timeline">
            <?php echo crm_notes_render_timeline_html($notes); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render del listado (timeline) a partir de un array de notas.
 */
function crm_notes_render_timeline_html($notes) {
    if (empty($notes)) {
        return '<p class="crm-notes-empty">Aún no hay notas para este cliente.</p>';
    }
    $out = '<ul class="crm-notes-list">';
    foreach ($notes as $n) {
        $out .= crm_notes_render_item($n);
    }
    $out .= '</ul>';
    return $out;
}

/**
 * Render de un único item de nota.
 *
 * @param array $n
 * @return string
 */
function crm_notes_render_item($n) {
    if (!is_array($n)) {
        return '';
    }
    $tipo   = (string) ($n['tipo'] ?? 'manual');
    $sector = (string) ($n['sector'] ?? '');
    $estado = (string) ($n['estado'] ?? '');
    $autor  = (string) ($n['autor_name'] ?? '');
    $fecha_raw = (string) ($n['fecha'] ?? '');
    $fecha_human = $fecha_raw
        ? human_time_diff(strtotime($fecha_raw), current_time('timestamp')) . ' atrás'
        : '';
    $fecha_full = $fecha_raw ? mysql2date('d/m/Y H:i', $fecha_raw) : '';
    // Para auto-notas guardamos HTML básico ya saneado; para manuales
    // confiamos en wp_kses_post hecho al insertar.
    $texto = (string) ($n['texto'] ?? '');
    $id    = (int) ($n['id'] ?? 0);

    $tipo_class  = 'crm-note--' . sanitize_html_class($tipo);
    $tipo_label  = crm_notes_get_tipo_label($tipo);

    $can_delete = is_user_logged_in() && (
        current_user_can('crm_admin') ||
        (int) ($n['autor_id'] ?? 0) === get_current_user_id()
    );

    $sector_badge = $sector !== ''
        ? '<span class="crm-note-badge crm-note-badge-sector crm-note-sector-' . esc_attr(sanitize_html_class($sector)) . '">' . esc_html(ucfirst($sector)) . '</span>'
        : '';
    $estado_badge = $estado !== ''
        ? '<span class="crm-note-badge crm-note-badge-estado">' . esc_html(str_replace('_', ' ', $estado)) . '</span>'
        : '';

    $delete_btn = $can_delete
        ? '<button type="button" class="crm-note-delete" data-note-id="' . esc_attr($id) . '" title="Eliminar nota">&times;</button>'
        : '';

    return '<li class="crm-note ' . esc_attr($tipo_class) . '" data-note-id="' . esc_attr($id) . '">'
         . '<div class="crm-note-header">'
         . '<span class="crm-note-tipo">' . esc_html($tipo_label) . '</span>'
         . $sector_badge . $estado_badge
         . '<span class="crm-note-fecha" title="' . esc_attr($fecha_full) . '">' . esc_html($fecha_human) . '</span>'
         . $delete_btn
         . '</div>'
         . '<div class="crm-note-body">' . $texto . '</div>'
         . '<div class="crm-note-footer">'
         . '<span class="crm-note-autor">' . esc_html($autor !== '' ? $autor : 'Sistema') . '</span>'
         . '</div>'
         . '</li>';
}

/**
 * Etiqueta legible para un tipo de nota.
 */
function crm_notes_get_tipo_label($tipo) {
    $map = [
        'manual'                => 'Nota',
        'state_change'          => 'Cambio de estado',
        'file_upload'           => 'Archivo subido',
        'file_remove'           => 'Archivo eliminado',
        'assignment'            => 'Asignación',
        'presupuesto_aceptado'  => 'Presupuesto aceptado',
        'contrato_generado'    => 'Contrato generado',
        'sistema'               => 'Sistema',
    ];
    return $map[$tipo] ?? ucfirst(str_replace('_', ' ', (string) $tipo));
}

/* ============================================================
 * Install hook
 * ============================================================ */

/**
 * Garantizar creación de tabla. Se llama tanto en activación como en init
 * (con flag de versión) para que upgrades silenciosos también la creen.
 */
function crm_notes_maybe_install() {
    $installed_for = get_option('crm_notes_installed_version');
    if ($installed_for === CRM_PLUGIN_VERSION) {
        return;
    }
    crm_notes_install_table();
    update_option('crm_notes_installed_version', CRM_PLUGIN_VERSION, false);
}
add_action('init', 'crm_notes_maybe_install', 5);
