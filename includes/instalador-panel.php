<?php
/**
 * Fase 4 — Panel del instalador.
 *
 * Dos páginas propias ("Mis instalaciones" y "Calendario"), con marca propia
 * (Ecovolt hoy, configurable en Ajustes para el día que haya más de un
 * cliente del CRM) — DENTRO del mismo App Shell que usa el resto de roles
 * (`includes/app-shell.php`): misma topbar/menú, solo que en variante clara
 * y con el logo/color del instalador en vez del logo genérico del CRM (ver
 * `crm_app_shell_render_topbar()`). Antes esta página vivía 100% aparte y
 * ocultaba la topbar entera, por lo que el instalador se quedaba sin ningún
 * menú/logout — v1.20.46 lo corrige metiéndola en el sistema de menús.
 *
 * Entrega de esta fase: panel + calendario. El checklist de ejecución y el
 * cierre con soporte offline quedan para una entrega aparte (ver
 * PLAN_instalaciones.md, Fase 4) — es una pieza de ingeniería distinta
 * (cola de sincronización) y no debía bloquear tener ya el calendario.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Branding del panel, configurado en Ajustes.
 */
function crm_inst_panel_get_settings() {
    return [
        'brand' => (string) get_option('crm_instalador_panel_brand', 'Ecovolt'),
        'color' => (string) get_option('crm_instalador_panel_color', '#15803d'),
        'logo'  => (string) get_option('crm_instalador_panel_logo_url', CRM_PLUGIN_URL . 'img/ecovolt-logo.jpg'),
    ];
}

/**
 * true si el usuario actual puede ver el panel: instalador (solo lo suyo) o
 * cualquiera con capacidades elevadas del módulo (para poder revisarlo sin
 * necesitar una cuenta de instalador de prueba). Usa `crm_inst_view_own`,
 * que ya tienen ambos grupos desde la Fase 1.
 */
function crm_inst_current_user_can_view_own() {
    return current_user_can('crm_inst_view_own');
}

/**
 * Normaliza texto para el buscador del panel (minúsculas, sin acentos) —
 * mismo criterio que crm_holded_normalizar_texto(), reutilizado si está
 * disponible para no duplicar el criterio de normalización en dos sitios.
 */
function crm_inst_panel_normalizar_busqueda($texto) {
    if (function_exists('crm_holded_normalizar_texto')) {
        return crm_holded_normalizar_texto($texto);
    }
    $texto = mb_strtolower((string) $texto);
    if (function_exists('remove_accents')) {
        $texto = remove_accents($texto);
    }
    return trim($texto);
}

/**
 * v1.20.82: dropzone de foto reutilizable (cierre por categoría, foto de
 * justificación de la extra al declarar y al editar) — un cuadro de borde
 * discontinuo con icono, "toca o arrastra una foto", y que al elegir/soltar
 * un archivo se convierte en la miniatura de esa foto. El input real cubre
 * toda la caja de forma invisible (opacity:0), así que el drag&drop nativo
 * del navegador y el tap para abrir cámara/galería siguen funcionando igual
 * que con un `<input type="file">` normal — esto es solo el envoltorio visual.
 *
 * @param string $input_class  Clase CSS del input real (la que ya lee el JS existente).
 * @param string $input_attrs  Atributos extra ya escapados (p.ej. data-categoria="...").
 * @param string $existing_url URL de una foto ya subida, si la hay (modo edición).
 */
function crm_inst_panel_render_foto_dropzone($input_class, $input_attrs = '', $existing_url = '') {
    ob_start();
    ?>
    <div class="crm-panel-inst-dropzone<?php echo $existing_url !== '' ? ' has-photo' : ''; ?>">
        <input type="file" class="<?php echo esc_attr($input_class); ?>" accept="image/*" capture="environment" <?php echo $input_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
        <div class="crm-panel-inst-dropzone-empty" style="<?php echo $existing_url !== '' ? 'display:none;' : ''; ?>">
            <?php echo function_exists('crm_icon') ? crm_icon('file-text', 22) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span>Toca o arrastra una foto aquí</span>
        </div>
        <div class="crm-panel-inst-dropzone-preview" style="<?php echo $existing_url !== '' ? '' : 'display:none;'; ?>">
            <?php if ($existing_url !== '') : ?><img src="<?php echo esc_url($existing_url); ?>" alt=""><?php endif; ?>
            <span class="crm-panel-inst-dropzone-cambiar">Toca para cambiar</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Añade tipo/subtipo/materiales/enlace de mapa a una fila cruda de
 * instalación — común entre las visitas con fecha y las que no la tienen
 * todavía.
 */
function crm_inst_panel_format_visita_row(array $row) {
    global $wpdb;
    $tipos    = crm_instalaciones_tipos();
    $subtipos = crm_instalaciones_subtipos();
    $estados  = crm_instalaciones_estados();
    $estado   = $row['estado'] ?? '';

    // v1.20.48: solo las líneas de Holded son "materiales" de verdad — las
    // partidas extra (origen='extra') se muestran aparte, con su propio
    // estado de validación (ver bloque `extras` más abajo).
    // v1.20.60: se añade `estado` para que el instalador vea, por línea, si
    // ya está en almacén (recibido) o todavía hay que pedirla — no está
    // "en almacén para esta obra" hasta que se marca recibido, aunque exista
    // stock general del producto (ver crm_inst_panel_render_card()).
    $materiales = $wpdb->get_results($wpdb->prepare(
        "SELECT descripcion, unidades, estado, holded_product_id FROM " . crm_inst_table_trabajos() . " WHERE instalacion_id = %d AND origen = 'holded' ORDER BY id ASC",
        (int) $row['instalacion_id']
    ), ARRAY_A);

    $extras = $wpdb->get_results($wpdb->prepare(
        "SELECT id, descripcion, precio_unitario, estado, cliente_notificado_en,
                horas, materiales_usados, holded_product_id, observaciones, justificacion_ruta
         FROM " . crm_inst_table_trabajos() . " WHERE instalacion_id = %d AND origen = 'extra' ORDER BY id DESC",
        (int) $row['instalacion_id']
    ), ARRAY_A);

    $fotos_cierre = [];
    if (!empty($row['cierre_estado'])) {
        $fotos_cierre = $wpdb->get_results($wpdb->prepare(
            "SELECT ruta, categoria FROM " . crm_inst_table_documentos() . " WHERE instalacion_id = %d AND tipo = 'foto' ORDER BY id ASC",
            (int) $row['instalacion_id']
        ), ARRAY_A);
    }

    $maps_url = '';
    if (!empty($row['lat']) && !empty($row['lng'])) {
        $maps_url = 'https://www.google.com/maps?q=' . $row['lat'] . ',' . $row['lng'];
    } elseif (!empty($row['direccion_instalacion'])) {
        $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($row['direccion_instalacion']);
    }

    return [
        'instalacion_id' => (int) $row['instalacion_id'],
        'cliente_nombre' => $row['cliente_nombre'] ?: '(sin cliente)',
        'telefono'       => $row['telefono'] ?? '',
        'cliente_email'  => $row['email_cliente'] ?? '',
        'cliente_telefono' => $row['telefono'] ?? '',
        'direccion'      => $row['direccion_instalacion'] ?? '',
        'maps_url'       => $maps_url,
        'tipo_label'     => $tipos[$row['tipo_instalacion']] ?? $row['tipo_instalacion'],
        'subtipo_instalacion' => $row['subtipo_instalacion'] ?? null,
        'subtipo_label'  => function_exists('crm_inst_subtipo_label') ? crm_inst_subtipo_label($row['subtipo_instalacion'] ?? '') : ($subtipos[$row['subtipo_instalacion']] ?? null),
        'fecha_cita'     => $row['fecha_cita'] ?? null,
        'materiales'     => $materiales,
        'extras'         => $extras,
        'cierre_estado'  => $row['cierre_estado'] ?? '',
        'cierre_fotos'   => $fotos_cierre,
        'estado'         => $estado,
        'estado_label'   => $estados[$estado] ?? $estado,
        'estado_color'   => function_exists('crm_instalaciones_estado_color') ? crm_instalaciones_estado_color($estado) : '#6b7280',
        'proveedor_pedido_estado' => $row['proveedor_pedido_estado'] ?? '',
        'checklist_confirmado_en' => $row['checklist_confirmado_en'] ?? null,
    ];
}

/**
 * Visitas de este instalador que ya tienen fecha en `crm_instalacion_agenda`
 * (Fase 1, sin usar hasta esta fase), ordenadas por fecha.
 */
function crm_inst_panel_get_visitas_con_fecha($user_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT a.instalacion_id, a.fecha_cita, i.tipo_instalacion, i.subtipo_instalacion,
                i.direccion_instalacion, i.lat, i.lng, i.cierre_estado, i.estado,
                i.proveedor_pedido_estado, i.checklist_confirmado_en, c.cliente_nombre, c.telefono, c.email_cliente
         FROM " . crm_inst_table_agenda() . " a
         INNER JOIN " . crm_inst_table_instalaciones() . " i ON i.id = a.instalacion_id
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         WHERE a.instalador_id = %d AND i.estado NOT IN ('finalizada', 'cancelada')
         ORDER BY a.fecha_cita ASC",
        (int) $user_id
    ), ARRAY_A);

    return array_map('crm_inst_panel_format_visita_row', $rows);
}

/**
 * Instalaciones de este instalador ya marcadas como `finalizada` — historial
 * de lo que ha hecho, no solo lo que tiene pendiente/programado.
 */
function crm_inst_panel_get_realizadas($user_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT i.id AS instalacion_id, i.tipo_instalacion, i.subtipo_instalacion,
                i.direccion_instalacion, i.lat, i.lng, i.estado,
                i.proveedor_pedido_estado, i.checklist_confirmado_en, c.cliente_nombre, c.telefono, c.email_cliente,
                a.fecha_cita
         FROM " . crm_inst_table_instaladores() . " pi
         INNER JOIN " . crm_inst_table_instalaciones() . " i ON i.id = pi.instalacion_id
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         LEFT JOIN " . crm_inst_table_agenda() . " a ON a.instalacion_id = i.id AND a.instalador_id = pi.user_id
         WHERE pi.user_id = %d AND i.estado = 'finalizada'
         ORDER BY i.fecha_cierre DESC, i.fecha_creacion DESC",
        (int) $user_id
    ), ARRAY_A);

    return array_map('crm_inst_panel_format_visita_row', $rows);
}

/**
 * Instalaciones asignadas a este instalador (tabla pivote de la Fase 1) que
 * todavía no tienen ninguna fecha de visita puesta.
 */
function crm_inst_panel_get_asignadas_sin_fecha($user_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT i.id AS instalacion_id, i.tipo_instalacion, i.subtipo_instalacion,
                i.direccion_instalacion, i.lat, i.lng, i.cierre_estado, i.estado,
                i.proveedor_pedido_estado, i.checklist_confirmado_en, c.cliente_nombre, c.telefono, c.email_cliente
         FROM " . crm_inst_table_instaladores() . " pi
         INNER JOIN " . crm_inst_table_instalaciones() . " i ON i.id = pi.instalacion_id
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         LEFT JOIN " . crm_inst_table_agenda() . " a ON a.instalacion_id = i.id
         WHERE pi.user_id = %d AND a.id IS NULL AND i.estado NOT IN ('finalizada', 'cancelada')
         ORDER BY i.fecha_creacion DESC",
        (int) $user_id
    ), ARRAY_A);

    return array_map('crm_inst_panel_format_visita_row', $rows);
}

/**
 * v1.20.79: texto corto de ayuda para el icono "i" de cada tarjeta — en qué
 * punto está la instalación y qué toca hacer a continuación. Pensado para
 * que un instalador nuevo no tenga que interpretar el estado de la tarjeta
 * él solo (checklist, materiales, extras, cierre son varias piezas a la vez).
 */
function crm_inst_panel_siguiente_paso_texto(array $v) {
    if ($v['estado'] === 'finalizada') {
        return 'Instalación completada. No queda ninguna acción pendiente.';
    }
    if ($v['estado'] === 'cancelada') {
        return 'Instalación cancelada.';
    }
    if (!empty($v['cierre_estado'])) {
        if ($v['cierre_estado'] === 'declarado') {
            return 'Cierre enviado — esperando que tu jefe de instalaciones lo apruebe.';
        }
        if ($v['cierre_estado'] === 'rechazado') {
            return 'Tu jefe rechazó el cierre anterior. Corrígelo y vuelve a declararlo cuando puedas.';
        }
    }
    if (!empty($v['checklist_confirmado_en'])) {
        $extras_sin_resolver = 0;
        foreach ($v['extras'] as $ex) {
            if (in_array($ex['estado'], ['pendiente', 'enviado_cliente'], true)) {
                $extras_sin_resolver++;
            }
        }
        $texto = 'Materiales y plan de seguridad confirmados. Cuando termines el trabajo, declara el cierre con las fotos que se piden.';
        if ($extras_sin_resolver > 0) {
            $texto .= ' Tienes ' . $extras_sin_resolver . ' partida(s) extra sin resolver todavía.';
        }
        return $texto;
    }
    $materiales_sin_recibir = 0;
    foreach ($v['materiales'] as $m) {
        if ($m['estado'] !== 'recibido') {
            $materiales_sin_recibir++;
        }
    }
    if ($materiales_sin_recibir > 0) {
        return 'Todavía faltan ' . $materiales_sin_recibir . ' material(es) por recibir en almacén. En cuanto lleguen todos podrás confirmar el checklist para empezar.';
    }
    return 'Todos los materiales están en almacén. Confirma el checklist de materiales y plan de seguridad para poder empezar a trabajar.';
}

/**
 * Una tarjeta de visita/instalación asignada.
 *
 * @param bool $permitir_cierre Si se muestra el botón/formulario de cierre.
 *        false en el historial de "Realizadas" — ya está finalizada, no
 *        tiene sentido volver a declarar el cierre desde ahí.
 */
function crm_inst_panel_render_card(array $v, $con_fecha, $permitir_cierre = true) {
    // v1.20.80: blob normalizado (sin acentos, minúsculas) para el buscador
    // dinámico del panel — decide qué tarjetas se muestran al escribir. Los
    // <span class="crm-panel-search-target"> de abajo son los que además se
    // resaltan en amarillo dentro de cada tarjeta visible.
    $search_piezas = [ $v['cliente_nombre'], $v['direccion'], $v['tipo_label'], (string) $v['subtipo_label'] ];
    foreach ( $v['materiales'] as $m ) {
        $search_piezas[] = $m['descripcion'];
    }
    foreach ( $v['extras'] as $ex ) {
        $search_piezas[] = $ex['descripcion'];
    }
    $search_blob = crm_inst_panel_normalizar_busqueda( implode( ' ', $search_piezas ) );

    $meta_texto = $v['tipo_label'] . ( $v['subtipo_label'] ? ' — ' . $v['subtipo_label'] : '' );

    ob_start();
    ?>
    <div class="crm-panel-inst-card" data-search="<?php echo esc_attr($search_blob); ?>" style="border-left-color: <?php echo esc_attr($v['estado_color']); ?>;">
        <div class="crm-panel-inst-info-wrap">
            <button type="button" class="crm-panel-inst-info-btn" aria-label="Información de esta instalación">i</button>
            <div class="crm-panel-inst-info-pop"><?php echo esc_html(crm_inst_panel_siguiente_paso_texto($v)); ?></div>
        </div>
        <?php if ($con_fecha && !empty($v['fecha_cita'])) : ?>
            <div class="crm-panel-inst-fecha">
                <?php echo function_exists('crm_icon') ? crm_icon('calendar', 14) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo esc_html(date_i18n('l d/m/Y H:i', strtotime($v['fecha_cita']))); ?>
            </div>
        <?php endif; ?>
        <h4>
            <span class="crm-panel-search-target" data-original="<?php echo esc_attr($v['cliente_nombre']); ?>"><?php echo esc_html($v['cliente_nombre']); ?></span>
            <?php if ($v['estado_label'] !== '') : ?>
                <span class="crm-panel-inst-estado-badge" style="background: <?php echo esc_attr($v['estado_color']); ?>;"><?php echo esc_html($v['estado_label']); ?></span>
            <?php endif; ?>
        </h4>
        <div class="crm-panel-inst-direccion">
            <span class="crm-panel-search-target" data-original="<?php echo esc_attr($v['direccion'] ?: 'Sin dirección registrada'); ?>"><?php echo esc_html($v['direccion'] ?: 'Sin dirección registrada'); ?></span>
            <?php if ($v['maps_url'] !== '') : ?> — <a href="<?php echo esc_url($v['maps_url']); ?>" target="_blank" rel="noopener noreferrer">Ver en mapa</a><?php endif; ?>
        </div>
        <div class="crm-panel-inst-meta">
            <span class="crm-panel-search-target" data-original="<?php echo esc_attr($meta_texto); ?>"><?php echo esc_html($meta_texto); ?></span>
            <?php if (!empty($v['telefono'])) : ?> · <a href="tel:<?php echo esc_attr($v['telefono']); ?>"><?php echo esc_html($v['telefono']); ?></a><?php endif; ?>
        </div>
        <?php if (!empty($v['materiales'])) : ?>
            <div class="crm-panel-inst-materiales">
                <strong>Materiales:</strong>
                <ul>
                    <?php foreach ($v['materiales'] as $m) :
                        // v1.20.60: "en almacén" para ESTA obra = ya marcado
                        // recibido en la ficha, no si Holded tiene stock
                        // general del producto — eso es otra cosa.
                        if ($m['estado'] === 'recibido') {
                            $badge_class = 'ok';
                            $badge_text  = 'En almacén, listo';
                        } elseif (in_array($v['proveedor_pedido_estado'], ['enviado', 'confirmado'], true)) {
                            $badge_class = 'bajo';
                            $badge_text  = 'Pedido al proveedor';
                        } else {
                            $badge_class = 'sin';
                            $badge_text  = 'Pendiente de pedir';
                        }
                    ?>
                        <li>
                            <?php echo esc_html($m['unidades']); ?> × <span class="crm-panel-search-target" data-original="<?php echo esc_attr($m['descripcion']); ?>"><?php echo esc_html($m['descripcion']); ?></span>
                            <span class="crm-panel-inst-extra-badge crm-panel-inst-extra-badge-<?php echo esc_attr($badge_class === 'ok' ? 'aprobado' : ($badge_class === 'bajo' ? 'pendiente' : 'rechazado')); ?>"><?php echo esc_html($badge_text); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php
        $extra_estado_labels = [
            'pendiente'       => 'Pendiente de enviar al cliente',
            'enviado_cliente' => 'Enviado al cliente — esperando respuesta',
            'aprobado'        => 'Aprobado',
            'rechazado'       => 'Rechazado',
        ];
        ?>
        <?php if (!empty($v['extras'])) : ?>
            <div class="crm-panel-inst-extras">
                <strong>Partidas extra:</strong>
                <ul>
                    <?php foreach ($v['extras'] as $ex) : ?>
                        <li>
                            <div class="crm-panel-inst-extra-summary">
                                <span class="crm-panel-search-target" data-original="<?php echo esc_attr($ex['descripcion']); ?>"><?php echo esc_html($ex['descripcion']); ?></span> — <?php echo esc_html(number_format_i18n((float) $ex['precio_unitario'], 2)); ?> €
                                <span class="crm-panel-inst-extra-badge crm-panel-inst-extra-badge-<?php echo esc_attr($ex['estado']); ?>"><?php echo esc_html($extra_estado_labels[$ex['estado']] ?? ucfirst($ex['estado'])); ?></span>
                                <?php if ($ex['estado'] === 'pendiente') : ?>
                                    <button type="button" class="crm-panel-inst-extra-editar-toggle">Editar</button>
                                    <button type="button" class="crm-btn crm-panel-inst-extra-enviar-cliente-btn" data-trabajo-id="<?php echo (int) $ex['id']; ?>" data-cliente-email="<?php echo esc_attr($v['cliente_email']); ?>" data-cliente-telefono="<?php echo esc_attr($v['cliente_telefono']); ?>">Enviar al cliente para validar</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($ex['estado'] === 'pendiente') : ?>
                                <div class="crm-panel-inst-extra-edit-form" style="display:none;">
                                    <input type="text" class="crm-panel-inst-extra-desc" value="<?php echo esc_attr($ex['descripcion']); ?>" placeholder="Descripción (ej. desplazamiento extra)">
                                    <input type="number" class="crm-panel-inst-extra-horas" value="<?php echo esc_attr($ex['horas'] ?? ''); ?>" placeholder="Horas (si procede)" step="0.5" min="0">
                                    <div class="crm-panel-inst-extra-materiales-wrap">
                                        <input type="text" class="crm-panel-inst-extra-materiales" value="<?php echo esc_attr($ex['materiales_usados'] ?? ''); ?>" <?php echo !empty($ex['holded_product_id']) ? 'data-holded-product-id="' . esc_attr($ex['holded_product_id']) . '"' : ''; ?> placeholder="Materiales usados — busca en el inventario o escribe libre" autocomplete="off">
                                        <ul class="crm-panel-inst-extra-materiales-resultados" style="display:none;"></ul>
                                    </div>
                                    <input type="number" class="crm-panel-inst-extra-importe" value="<?php echo esc_attr($ex['precio_unitario']); ?>" placeholder="Importe €" step="0.01" min="0">
                                    <textarea class="crm-panel-inst-extra-observaciones" placeholder="Observaciones (opcional)" rows="2"><?php echo esc_textarea($ex['observaciones'] ?? ''); ?></textarea>
                                    <label class="crm-panel-inst-extra-foto-label"><?php echo empty($ex['justificacion_ruta']) ? 'Foto de justificación (obligatoria)' : 'Foto de justificación (toca para cambiarla, o déjala así para conservarla)'; ?></label>
                                    <?php echo crm_inst_panel_render_foto_dropzone('crm-panel-inst-extra-foto', '', $ex['justificacion_ruta'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <button type="button" class="crm-btn crm-panel-inst-extra-guardar-edicion" data-trabajo-id="<?php echo (int) $ex['id']; ?>" data-tenia-foto="<?php echo empty($ex['justificacion_ruta']) ? '0' : '1'; ?>">Guardar cambios</button>
                                    <button type="button" class="crm-panel-inst-extra-editar-cancelar">Cancelar</button>
                                    <span class="crm-panel-inst-extra-edit-msg"></span>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="crm-panel-inst-extra-form-wrap">
            <button type="button" class="crm-panel-inst-extra-toggle">+ Declarar partida extra</button>
            <div class="crm-panel-inst-extra-form" style="display:none;">
                <input type="text" class="crm-panel-inst-extra-desc" placeholder="Descripción (ej. desplazamiento extra)">
                <input type="number" class="crm-panel-inst-extra-horas" placeholder="Horas (si procede)" step="0.5" min="0">
                <div class="crm-panel-inst-extra-materiales-wrap">
                    <input type="text" class="crm-panel-inst-extra-materiales" placeholder="Materiales usados — busca en el inventario o escribe libre" autocomplete="off">
                    <ul class="crm-panel-inst-extra-materiales-resultados" style="display:none;"></ul>
                </div>
                <input type="number" class="crm-panel-inst-extra-importe" placeholder="Importe €" step="0.01" min="0">
                <textarea class="crm-panel-inst-extra-observaciones" placeholder="Observaciones (opcional)" rows="2"></textarea>
                <label class="crm-panel-inst-extra-foto-label">Foto de justificación (obligatoria)</label>
                <?php echo crm_inst_panel_render_foto_dropzone('crm-panel-inst-extra-foto'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <button type="button" class="crm-btn crm-panel-inst-extra-guardar" data-instalacion-id="<?php echo (int) $v['instalacion_id']; ?>">Enviar</button>
                <span class="crm-panel-inst-extra-msg"></span>
            </div>
        </div>
        <?php if (!empty($v['cierre_fotos'])) :
            $categorias_labels_card = function_exists('crm_inst_categorias_fotos_cierre') ? crm_inst_categorias_fotos_cierre($v['subtipo_instalacion']) : [];
        ?>
            <div class="crm-panel-inst-cierre-fotos">
                <?php foreach ($v['cierre_fotos'] as $foto) :
                    $cat_label_card = !empty($foto['categoria']) ? ($categorias_labels_card[$foto['categoria']] ?? ucfirst(str_replace('_', ' ', $foto['categoria']))) : '';
                ?>
                    <a href="<?php echo esc_url($foto['ruta']); ?>" target="_blank" rel="noopener noreferrer">
                        <img src="<?php echo esc_url($foto['ruta']); ?>" alt="<?php echo esc_attr($cat_label_card ?: 'Foto del cierre'); ?>">
                        <?php if ($cat_label_card !== '') : ?><span><?php echo esc_html($cat_label_card); ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($permitir_cierre) : ?>
            <?php if (empty($v['checklist_confirmado_en'])) :
                // v1.20.73: no dejar abrir/confirmar el checklist si todavía hay
                // materiales de Holded sin marcar "Recibido" en oficina — antes
                // el instalador podía confirmar igualmente aunque le faltase
                // material real, sin que nada se lo impidiera.
                $materiales_sin_recibir = 0;
                foreach ($v['materiales'] as $m) {
                    if ($m['estado'] !== 'recibido') {
                        $materiales_sin_recibir++;
                    }
                }
            ?>
                <?php if ($materiales_sin_recibir > 0) : ?>
                    <div class="crm-panel-inst-checklist-form-wrap">
                        <button type="button" class="crm-panel-inst-checklist-toggle" disabled>Confirmar materiales y plan de seguridad</button>
                        <p class="crm-panel-inst-checklist-bloqueado">Todavía hay <?php echo (int) $materiales_sin_recibir; ?> material(es) sin recibir en almacén — no puedes confirmar hasta que estén todos. Avisa a tu jefe de instalaciones si crees que ya deberían estar.</p>
                    </div>
                <?php else : ?>
                <?php
                    // v1.20.88: el material sale de un almacén concreto de
                    // Holded (Ecovolt opera con más de uno) — el instalador
                    // lo elige justo antes de confirmar, ya que es el
                    // momento del albarán de salida real. Solo tiene sentido
                    // pedirlo si hay de verdad alguna línea enganchada a un
                    // producto real (si no, no se va a generar ningún
                    // albarán y no hay nada que elegir).
                    $tiene_materiales_holded = false;
                    foreach ($v['materiales'] as $m) {
                        if (!empty($m['holded_product_id'])) {
                            $tiene_materiales_holded = true;
                            break;
                        }
                    }
                    $almacenes_holded = ($tiene_materiales_holded && function_exists('crm_holded_get_warehouses_cached')) ? crm_holded_get_warehouses_cached() : [];
                    if (is_wp_error($almacenes_holded)) {
                        $almacenes_holded = [];
                    }
                ?>
                <div class="crm-panel-inst-checklist-form-wrap">
                    <button type="button" class="crm-panel-inst-checklist-toggle">Confirmar materiales y plan de seguridad</button>
                    <div class="crm-panel-inst-checklist-form" style="display:none;">
                        <label class="crm-panel-inst-cierre-check">
                            <input type="checkbox" class="crm-panel-inst-checklist-materiales">
                            Confirmo que tengo en mi poder todos los materiales de esta instalación
                        </label>
                        <label class="crm-panel-inst-cierre-check">
                            <input type="checkbox" class="crm-panel-inst-checklist-seguridad">
                            He leído y acepto el <a href="<?php echo esc_url(home_url('/plan-de-seguridad/')); ?>" target="_blank" rel="noopener noreferrer">plan de seguridad y prevención</a>
                        </label>
                        <?php if (!empty($almacenes_holded)) : ?>
                            <label class="crm-panel-inst-cierre-check" style="flex-direction:column; align-items:flex-start; gap:4px;">
                                Almacén del que sale el material
                                <select class="crm-panel-inst-checklist-almacen">
                                    <?php foreach ($almacenes_holded as $almacen) : ?>
                                        <option value="<?php echo esc_attr($almacen['id']); ?>" <?php selected(!empty($almacen['default'])); ?>><?php echo esc_html($almacen['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <button type="button" class="crm-btn crm-panel-inst-checklist-guardar" data-instalacion-id="<?php echo (int) $v['instalacion_id']; ?>">Confirmar</button>
                        <span class="crm-panel-inst-checklist-msg"></span>
                    </div>
                </div>
                <?php endif; ?>
            <?php else : ?>
            <?php if ($v['cierre_estado'] === 'declarado') : ?>
                <div class="crm-panel-inst-cierre-badge crm-panel-inst-cierre-badge-pendiente">Cierre declarado — pendiente de que tu jefe lo apruebe.</div>
            <?php else : ?>
                <?php if ($v['cierre_estado'] === 'rechazado') : ?>
                    <div class="crm-panel-inst-cierre-badge crm-panel-inst-cierre-badge-rechazado">Tu jefe rechazó el cierre anterior — puedes volver a declararlo.</div>
                <?php endif; ?>
                <?php $categorias_cierre = function_exists('crm_inst_categorias_fotos_cierre') ? crm_inst_categorias_fotos_cierre($v['subtipo_instalacion']) : []; ?>
                <div class="crm-panel-inst-cierre-form-wrap">
                    <button type="button" class="crm-panel-inst-cierre-toggle">✓ Marcar como realizada</button>
                    <div class="crm-panel-inst-cierre-form" style="display:none;">
                        <label class="crm-panel-inst-cierre-check">
                            <input type="checkbox" class="crm-panel-inst-cierre-conformidad">
                            Confirmo que la instalación se realizó conforme a lo acordado
                        </label>
                        <textarea class="crm-panel-inst-cierre-observaciones" placeholder="Observaciones (opcional)" rows="2"></textarea>
                        <?php if (!empty($categorias_cierre)) : ?>
                            <div class="crm-panel-inst-cierre-foto-cats">
                                <?php foreach ($categorias_cierre as $cat_key => $cat_label) : ?>
                                    <div class="crm-panel-inst-cierre-foto-cat-slot">
                                        <label><?php echo esc_html($cat_label); ?></label>
                                        <?php echo crm_inst_panel_render_foto_dropzone('crm-panel-inst-cierre-foto-cat', 'data-categoria="' . esc_attr($cat_key) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <input type="file" class="crm-panel-inst-cierre-fotos-input" accept="image/*" multiple>
                            <small>Hasta 6 fotos.</small>
                        <?php endif; ?>
                        <button type="button" class="crm-btn crm-panel-inst-cierre-guardar" data-instalacion-id="<?php echo (int) $v['instalacion_id']; ?>">Enviar cierre</button>
                        <span class="crm-panel-inst-cierre-msg"></span>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; // fin del else (checklist ya confirmado) ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * CSS compartido entre "Mis instalaciones" y "Calendario" — mismas tarjetas,
 * mismo color de marca (variable `--crm-panel-accent`), para que ambas vistas
 * se vean como parte de una sola sección del CRM.
 */
function crm_inst_panel_shared_css(array $settings) {
    ob_start();
    ?>
    .crm-panel-inst-wrap {
        --crm-panel-accent: <?php echo esc_html($settings['color'] ?: '#15803d'); ?>;
        max-width: 640px; margin: 0 auto; padding: 16px 4px; font-family: system-ui, -apple-system, sans-serif;
    }
    .crm-panel-inst-section-title { font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; margin:22px 0 10px; }
    .crm-panel-inst-section-title:first-child { margin-top:0; }
    .crm-panel-inst-buscador-wrap { display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:9px 12px; margin-bottom:14px; color:#9ca3af; }
    .crm-panel-inst-buscador-wrap:focus-within { border-color:var(--crm-panel-accent); color:var(--crm-panel-accent); }
    .crm-panel-inst-buscador-wrap input { flex:1; border:none; outline:none; font-size:14px; font-family:inherit; color:#1f2937; background:transparent; }
    #crm-panel-inst-buscador-limpiar { display:flex; align-items:center; justify-content:center; width:20px; height:20px; padding:0; border:none; border-radius:50%; background:#f3f4f6; color:#6b7280; cursor:pointer; flex-shrink:0; }
    #crm-panel-inst-buscador-limpiar:hover { background:#e5e7eb; color:#374151; }
    .crm-panel-inst-buscador-sin-resultados { color:#9ca3af; font-size:13px; text-align:center; padding:8px 0; }
    .crm-panel-inst-card mark, .crm-panel-inst-info-pop mark { background:#fef08a; color:#1f2937; border-radius:2px; padding:0 1px; }
    .crm-panel-inst-card { position:relative; background:#fff; border:1px solid #e5e7eb; border-left:4px solid var(--crm-panel-accent); border-radius:8px; padding:14px 16px; margin-bottom:12px; }
    .crm-panel-inst-info-wrap { position:absolute; top:10px; right:10px; z-index:3; }
    .crm-panel-inst-info-btn { width:20px; height:20px; line-height:18px; padding:0; border-radius:50%; border:1px solid #d1d5db; background:#f9fafb; color:#6b7280; font-size:12px; font-weight:700; font-style:italic; font-family:Georgia,serif; cursor:pointer; text-align:center; }
    .crm-panel-inst-info-btn:hover, .crm-panel-inst-info-btn.is-open { border-color:var(--crm-panel-accent); color:var(--crm-panel-accent); }
    .crm-panel-inst-info-pop { display:none; position:absolute; top:26px; right:0; width:220px; max-width:70vw; background:#1f2937; color:#f3f4f6; font-size:12px; line-height:1.5; padding:10px 12px; border-radius:8px; box-shadow:0 6px 16px rgba(0,0,0,.25); z-index:4; }
    .crm-panel-inst-info-pop.is-open { display:block; }
    .crm-panel-inst-card h4 { margin:0 0 4px; padding-right:28px; font-size:15px; color:#1f2937; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .crm-panel-inst-estado-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#fff; }
    .crm-panel-inst-fecha { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:700; color:var(--crm-panel-accent); margin-bottom:6px; }
    .crm-panel-inst-direccion { font-size:13px; color:#374151; margin-bottom:4px; }
    .crm-panel-inst-direccion a { color:var(--crm-panel-accent); text-decoration:none; font-weight:600; }
    .crm-panel-inst-meta { font-size:13px; color:#374151; }
    .crm-panel-inst-meta a { color:var(--crm-panel-accent); text-decoration:none; }
    .crm-panel-inst-materiales { font-size:12px; color:#6b7280; margin-top:8px; }
    .crm-panel-inst-materiales ul { margin:4px 0 0; padding-left:18px; }
    .crm-panel-inst-extras { font-size:12px; color:#6b7280; margin-top:8px; }
    .crm-panel-inst-extras ul { margin:4px 0 0; padding-left:18px; }
    .crm-panel-inst-extra-badge { display:inline-block; padding:1px 7px; border-radius:9px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:4px; }
    .crm-panel-inst-extra-badge-pendiente { background:#fef3c7; color:#92400e; }
    .crm-panel-inst-extra-badge-enviado_cliente { background:#dbeafe; color:#1e40af; }
    .crm-panel-inst-extra-badge-aprobado { background:#d1fae5; color:#065f46; }
    .crm-panel-inst-extra-badge-rechazado { background:#fee2e2; color:#991b1b; }
    .crm-panel-inst-extra-summary { display:flex; flex-wrap:wrap; align-items:center; gap:4px; }
    .crm-panel-inst-extra-editar-toggle { background:none; border:1px solid #d1d5db; color:#374151; font-size:11px; font-weight:600; cursor:pointer; padding:5px 9px; border-radius:6px; margin-left:6px; }
    .crm-panel-inst-extra-editar-cancelar { background:none; border:none; color:#6b7280; font-size:12px; text-decoration:underline; cursor:pointer; padding:0; }
    .crm-panel-inst-extra-edit-msg { font-size:12px; }
    .crm-panel-inst-extra-enviar-cliente-btn { display:block; margin-top:6px; background:none; border:1px solid var(--crm-panel-accent); color:var(--crm-panel-accent); font-size:11px; font-weight:600; cursor:pointer; padding:5px 9px; border-radius:6px; }
    .crm-panel-inst-extra-enviar-msg { font-size:11px; margin-left:6px; }
    .crm-panel-inst-extra-form-wrap { margin-top:10px; }
    .crm-panel-inst-extra-toggle { background:none; border:1px solid #d1d5db; color:var(--crm-panel-accent); font-size:12px; font-weight:600; cursor:pointer; padding:6px 10px; border-radius:6px; }
    .crm-panel-inst-extra-form, .crm-panel-inst-extra-edit-form { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; align-items:flex-start; padding:8px; background:#f9fafb; border-radius:8px; }
    .crm-panel-inst-extra-form input[type="text"], .crm-panel-inst-extra-edit-form input[type="text"] { flex:1; min-width:160px; }
    .crm-panel-inst-extra-form input[type="number"], .crm-panel-inst-extra-edit-form input[type="number"] { width:110px; }
    .crm-panel-inst-extra-form textarea, .crm-panel-inst-extra-edit-form textarea { flex:1 1 100%; min-width:220px; resize:vertical; font-family:inherit; padding:6px 8px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; }
    .crm-panel-inst-extra-materiales-wrap { position:relative; flex:1; min-width:160px; }
    .crm-panel-inst-extra-materiales-wrap input[type="text"] { width:100%; box-sizing:border-box; }
    .crm-panel-inst-extra-materiales-resultados { position:absolute; z-index:5; top:100%; left:0; right:0; margin:2px 0 0; padding:4px 0; list-style:none; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,.1); max-height:180px; overflow-y:auto; }
    .crm-panel-inst-extra-materiales-resultados li { padding:6px 10px; font-size:12.5px; cursor:pointer; }
    .crm-panel-inst-extra-materiales-resultados li:hover { background:#f3f4f6; }
    .crm-panel-inst-extra-foto-label { flex:1 1 100%; font-size:12.5px; font-weight:600; color:#374151; margin-bottom:-2px; }
    .crm-panel-inst-extra-msg { font-size:12px; }
    .crm-panel-modal-backdrop { position:fixed; inset:0; background:rgba(17,24,39,.5); display:flex; align-items:center; justify-content:center; z-index:9999; padding:16px; }
    .crm-panel-modal { background:#fff; border-radius:10px; padding:20px; max-width:380px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,.2); }
    .crm-panel-modal h3 { margin:0 0 12px; font-size:15px; color:#1f2937; }
    .crm-panel-modal label { display:block; font-size:12.5px; font-weight:600; color:#374151; margin:10px 0 4px; }
    .crm-panel-modal input[type="email"], .crm-panel-modal input[type="tel"] { width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-family:inherit; font-size:13px; }
    .crm-panel-modal-canal-tabs { display:flex; gap:8px; }
    .crm-panel-modal-canal-btn { flex:1; padding:8px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer; background:#fff; border:1px solid #e5e7eb; color:#374151; }
    .crm-panel-modal-canal-btn.is-selected { background:#d1fae5; border-color:#15803d; color:#065f46; }
    .crm-panel-modal-canal-panel { margin-top:4px; }
    .crm-panel-modal-whats-row { margin-top:10px; font-size:12px; color:#9ca3af; }
    .crm-panel-modal-actions { display:flex; gap:8px; margin-top:16px; }
    .crm-panel-modal-actions button { flex:1; padding:10px; border-radius:6px; font-weight:600; cursor:pointer; font-size:13px; }
    .crm-panel-modal-enviar { background:#15803d; color:#fff; border:none; }
    .crm-panel-modal-cancelar { background:#fff; color:#374151; border:1px solid #e5e7eb; }
    .crm-panel-modal-msg { font-size:12px; margin-top:8px; }
    .crm-panel-inst-cierre-fotos { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
    .crm-panel-inst-cierre-fotos a { display:flex; flex-direction:column; align-items:center; gap:3px; text-decoration:none; }
    .crm-panel-inst-cierre-fotos img { width:64px; height:64px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; }
    .crm-panel-inst-cierre-fotos span { font-size:9px; color:#9ca3af; max-width:64px; text-align:center; }
    .crm-panel-inst-cierre-foto-cats { display:flex; flex-direction:column; gap:10px; }
    .crm-panel-inst-cierre-foto-cat-slot label { font-size:12.5px; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
    .crm-panel-inst-dropzone { position:relative; flex:1 1 100%; min-height:76px; display:flex; align-items:center; justify-content:center; border:2px dashed #d1d5db; border-radius:10px; background:#fafafa; overflow:hidden; cursor:pointer; transition:border-color .15s ease, background .15s ease; }
    .crm-panel-inst-dropzone:hover { border-color:var(--crm-panel-accent); background:#f7fdf9; }
    .crm-panel-inst-dropzone.is-dragover { border-color:var(--crm-panel-accent); background:#f0fdf4; }
    .crm-panel-inst-dropzone.has-photo { border-style:solid; }
    .crm-panel-inst-dropzone input[type="file"] { position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer; }
    .crm-panel-inst-dropzone-empty { display:flex; flex-direction:column; align-items:center; gap:4px; color:#9ca3af; font-size:12px; padding:14px 10px; text-align:center; pointer-events:none; }
    .crm-panel-inst-dropzone-preview { position:relative; width:100%; height:76px; pointer-events:none; }
    .crm-panel-inst-dropzone-preview img { width:100%; height:100%; object-fit:cover; display:block; }
    .crm-panel-inst-dropzone-cambiar { position:absolute; left:0; right:0; bottom:0; background:rgba(17,24,39,.6); color:#fff; font-size:10.5px; font-weight:600; text-align:center; padding:4px 0; }
    .crm-panel-inst-cierre-badge { margin-top:10px; padding:8px 10px; border-radius:6px; font-size:12px; font-weight:600; }
    .crm-panel-inst-cierre-badge-pendiente { background:#fef3c7; color:#92400e; }
    .crm-panel-inst-cierre-badge-rechazado { background:#fee2e2; color:#991b1b; }
    .crm-panel-inst-cierre-form-wrap { margin-top:10px; }
    .crm-panel-inst-cierre-toggle { background:none; border:1px solid var(--crm-panel-accent); color:var(--crm-panel-accent); font-size:12px; font-weight:700; cursor:pointer; padding:6px 10px; border-radius:6px; }
    .crm-panel-inst-cierre-form { display:flex; flex-direction:column; gap:8px; margin-top:8px; padding:10px; background:#f9fafb; border-radius:8px; }
    .crm-panel-inst-cierre-check { font-size:12.5px; color:#374151; display:flex; align-items:flex-start; gap:6px; }
    .crm-panel-inst-cierre-form textarea { resize:vertical; font-family:inherit; padding:6px 8px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; }
    .crm-panel-inst-cierre-form small { color:#9ca3af; font-size:11px; margin-top:-4px; }
    .crm-panel-inst-cierre-msg { font-size:12px; }
    .crm-panel-inst-checklist-form-wrap { margin-top:10px; }
    .crm-panel-inst-checklist-toggle { background:none; border:1px solid var(--crm-panel-accent); color:var(--crm-panel-accent); font-size:12px; font-weight:700; cursor:pointer; padding:6px 10px; border-radius:6px; }
    .crm-panel-inst-checklist-toggle:disabled { opacity:.55; cursor:not-allowed; border-color:#d1d5db; color:#9ca3af; }
    .crm-panel-inst-checklist-bloqueado { font-size:12px; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:8px 10px; margin-top:8px; }
    .crm-panel-inst-checklist-form { display:flex; flex-direction:column; gap:8px; margin-top:8px; padding:10px; background:#f9fafb; border-radius:8px; }
    .crm-panel-inst-checklist-form a { color:var(--crm-panel-accent); font-weight:600; }
    .crm-panel-inst-checklist-msg { font-size:12px; }
    .crm-panel-inst-empty { color:#9ca3af; font-size:13px; }
    <?php
    return ob_get_clean();
}

/**
 * JS compartido entre "Mis instalaciones" y "Calendario" para el formulario
 * de "Declarar partida extra" que vive dentro de cada tarjeta
 * (`crm_inst_panel_render_card()`). Delegado a nivel de `document` porque las
 * tarjetas del calendario se clonan dentro del modal desde un <template> —
 * un binding directo por ID no las alcanzaría.
 */
function crm_inst_panel_extras_js($nonce) {
    ob_start();
    ?>
    <script>
    (function () {
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce   = <?php echo wp_json_encode($nonce); ?>;
        // v1.20.87: si hay credenciales de WhatsApp Business configuradas Y
        // una plantilla para este aviso, el modal deja enviar por ese canal
        // de verdad; si no, sigue bloqueado con el aviso de siempre.
        var whatsappDisponible = <?php echo ( function_exists('crm_whatsapp_configurado') && crm_whatsapp_configurado() && trim((string) get_option('crm_whatsapp_template_validar_extra', '')) !== '' ) ? 'true' : 'false'; ?>;

        // v1.20.74: compresión en el móvil antes de subir las fotos del
        // cierre (redimensiona al lado más largo y reexporta en JPEG) — sin
        // librerías externas, solo <canvas>. Si la imagen no se puede
        // decodificar (pasa a veces con HEIC de iPhone en algunos
        // navegadores) o el resultado no pesa menos, se sube el archivo
        // original: nunca se pierde la foto por un fallo de compresión.
        function comprimirImagen(file) {
            return new Promise(function (resolve) {
                if (!file || !/^image\//.test(file.type)) {
                    resolve(file);
                    return;
                }
                var img = new Image();
                var url = URL.createObjectURL(file);
                img.onload = function () {
                    URL.revokeObjectURL(url);
                    var maxDim = 1920;
                    var w = img.naturalWidth, h = img.naturalHeight;
                    if (!w || !h) {
                        resolve(file);
                        return;
                    }
                    if (w > maxDim || h > maxDim) {
                        if (w >= h) { h = Math.round(h * maxDim / w); w = maxDim; }
                        else { w = Math.round(w * maxDim / h); h = maxDim; }
                    }
                    var canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, w, h);
                    canvas.toBlob(function (blob) {
                        resolve((blob && blob.size < file.size) ? blob : file);
                    }, 'image/jpeg', 0.8);
                };
                img.onerror = function () {
                    URL.revokeObjectURL(url);
                    resolve(file);
                };
                img.src = url;
            });
        }

        // Si se comprimió, el blob resultante es JPEG aunque el original
        // fuera PNG/HEIC — hay que renombrar la extensión o la validación de
        // tipo real del servidor lo rechaza. Compartido entre el cierre y las
        // partidas extra (las dos suben fotos comprimidas).
        function nombreSubida(original, blob) {
            if (blob === original) {
                return original.name;
            }
            return (original.name.replace(/\.[^.]+$/, '') || 'foto') + '.jpg';
        }

        // Modal de confirmación antes de enviar una partida extra al cliente
        // — muestra el email/WhatsApp que ya tenemos en su ficha, pero deja
        // corregirlos por si están desactualizados. El WhatsApp se guarda de
        // contacto para cuando ese canal esté activo (todavía no envía nada,
        // solo el email es funcional hoy).
        function abrirModalEnviarCliente(trabajoId, emailDefault, telDefault) {
            var backdrop = document.createElement('div');
            backdrop.className = 'crm-panel-modal-backdrop';
            backdrop.innerHTML =
                '<div class="crm-panel-modal">' +
                    '<h3>Enviar al cliente para validar</h3>' +
                    '<div class="crm-panel-modal-canal-tabs">' +
                        '<button type="button" class="crm-panel-modal-canal-btn" data-canal="email">Email</button>' +
                        '<button type="button" class="crm-panel-modal-canal-btn" data-canal="whatsapp">WhatsApp</button>' +
                    '</div>' +
                    '<div class="crm-panel-modal-canal-panel" data-canal-panel="email">' +
                        '<label>Email del cliente</label>' +
                        '<input type="email" class="crm-panel-modal-email" placeholder="email@cliente.com">' +
                    '</div>' +
                    '<div class="crm-panel-modal-canal-panel" data-canal-panel="whatsapp" style="display:none;">' +
                        '<label>WhatsApp del cliente</label>' +
                        '<input type="tel" class="crm-panel-modal-whats" placeholder="Número de WhatsApp">' +
                        '<p class="crm-panel-modal-whats-row">El envío por WhatsApp todavía no está activo — el número queda anotado para cuando lo esté, pero no se envía nada por aquí. Cambia a Email para notificar ahora.</p>' +
                    '</div>' +
                    '<div class="crm-panel-modal-actions">' +
                        '<button type="button" class="crm-panel-modal-cancelar">Cancelar</button>' +
                        '<button type="button" class="crm-panel-modal-enviar crm-panel-modal-enviar-email">Enviar por email</button>' +
                    '</div>' +
                    '<p class="crm-panel-modal-msg"></p>' +
                '</div>';
            document.body.appendChild(backdrop);
            backdrop.querySelector('.crm-panel-modal-email').value = emailDefault;
            backdrop.querySelector('.crm-panel-modal-whats').value = telDefault;

            var canalActivo = 'email';
            var canalBtns = backdrop.querySelectorAll('.crm-panel-modal-canal-btn');
            var enviarBtn = backdrop.querySelector('.crm-panel-modal-enviar');
            var canalLabels = { email: 'Enviar por email', whatsapp: 'Enviar por WhatsApp' };

            function seleccionarCanal(canal) {
                canalActivo = canal;
                canalBtns.forEach(function (b) {
                    b.classList.toggle('is-selected', b.getAttribute('data-canal') === canal);
                });
                backdrop.querySelectorAll('.crm-panel-modal-canal-panel').forEach(function (p) {
                    p.style.display = p.getAttribute('data-canal-panel') === canal ? 'block' : 'none';
                });
                enviarBtn.textContent = canalLabels[canal];
            }
            canalBtns.forEach(function (b) {
                b.addEventListener('click', function () { seleccionarCanal(b.getAttribute('data-canal')); });
            });
            seleccionarCanal('email');

            function cerrar() { backdrop.remove(); }
            backdrop.addEventListener('click', function (e) { if (e.target === backdrop) { cerrar(); } });
            backdrop.querySelector('.crm-panel-modal-cancelar').addEventListener('click', cerrar);
            enviarBtn.addEventListener('click', function () {
                var btn = this;
                var email = backdrop.querySelector('.crm-panel-modal-email').value.trim();
                var whats = backdrop.querySelector('.crm-panel-modal-whats').value.trim();
                var mMsg = backdrop.querySelector('.crm-panel-modal-msg');

                if (canalActivo === 'whatsapp' && !whatsappDisponible) {
                    mMsg.style.color = '#991b1b';
                    mMsg.textContent = 'El envío por WhatsApp todavía no está activo — cambia a Email para notificar ahora.';
                    return;
                }
                if (canalActivo === 'whatsapp' && !whats) {
                    mMsg.style.color = '#991b1b';
                    mMsg.textContent = 'Indica un número de WhatsApp para poder enviarlo.';
                    return;
                }
                if (!email) {
                    mMsg.style.color = '#991b1b';
                    mMsg.textContent = 'Indica un email para poder enviarlo.';
                    return;
                }
                btn.disabled = true;
                backdrop.querySelector('.crm-panel-modal-cancelar').disabled = true;
                mMsg.style.color = '#6b7280';
                mMsg.textContent = 'Enviando...';
                var body = new URLSearchParams();
                body.set('action', 'crm_inst_enviar_extra_cliente');
                body.set('nonce', nonce);
                body.set('trabajo_id', trabajoId);
                body.set('email', email);
                body.set('whatsapp', whats);
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) {
                            btn.disabled = false;
                            backdrop.querySelector('.crm-panel-modal-cancelar').disabled = false;
                            mMsg.style.color = '#991b1b';
                            mMsg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                            return;
                        }
                        location.reload();
                    })
                    .catch(function () {
                        btn.disabled = false;
                        backdrop.querySelector('.crm-panel-modal-cancelar').disabled = false;
                        mMsg.style.color = '#991b1b';
                        mMsg.textContent = 'Error de conexión.';
                    });
            });
        }

        // Previsualización + compresión inmediata al elegir cada foto
        // (categorías del cierre y justificación de partida extra), para que
        // el instalador vea de un vistazo que la captura se hizo bien.
        document.addEventListener('change', function (e) {
            var input = e.target.closest && (e.target.closest('.crm-panel-inst-cierre-foto-cat') || e.target.closest('.crm-panel-inst-extra-foto'));
            if (!input || !input.files || !input.files[0]) {
                return;
            }
            var file = input.files[0];
            input._compressedBlob = null;
            var zone = input.closest('.crm-panel-inst-dropzone');
            comprimirImagen(file).then(function (blob) {
                input._compressedBlob = blob;
                if (zone) {
                    zone.classList.add('has-photo');
                    var vacio = zone.querySelector('.crm-panel-inst-dropzone-empty');
                    var preview = zone.querySelector('.crm-panel-inst-dropzone-preview');
                    if (vacio) { vacio.style.display = 'none'; }
                    if (preview) {
                        preview.style.display = '';
                        var img = preview.querySelector('img');
                        if (!img) {
                            img = document.createElement('img');
                            preview.insertBefore(img, preview.firstChild);
                        }
                        img.src = URL.createObjectURL(blob);
                    }
                }
            });
        });

        // Realce visual (no funcional — el navegador ya asigna el archivo
        // soltado al input de forma nativa) al arrastrar una foto sobre una
        // dropzone, para que se note que ahí se puede soltar.
        document.addEventListener('dragover', function (e) {
            var zone = e.target.closest && e.target.closest('.crm-panel-inst-dropzone');
            if (zone) { e.preventDefault(); zone.classList.add('is-dragover'); }
        });
        document.addEventListener('dragleave', function (e) {
            var zone = e.target.closest && e.target.closest('.crm-panel-inst-dropzone');
            if (zone) { zone.classList.remove('is-dragover'); }
        });
        document.addEventListener('drop', function (e) {
            var zone = e.target.closest && e.target.closest('.crm-panel-inst-dropzone');
            if (zone) { zone.classList.remove('is-dragover'); }
        });

        // Autocomplete de materiales del inventario de Holded al declarar una
        // partida extra — busca según se escribe (debounce 300ms) y al elegir
        // una opción guarda su ID en el propio input (data-holded-product-id)
        // para engancharla de verdad, igual que una línea de presupuesto.
        var materialSearchTimer = null;
        document.addEventListener('input', function (e) {
            var input = e.target.closest && e.target.closest('.crm-panel-inst-extra-materiales');
            if (!input) {
                return;
            }
            input.removeAttribute('data-holded-product-id');
            var lista = input.closest('.crm-panel-inst-extra-materiales-wrap').querySelector('.crm-panel-inst-extra-materiales-resultados');
            var q = input.value.trim();
            clearTimeout(materialSearchTimer);
            if (q.length < 2) {
                lista.style.display = 'none';
                lista.innerHTML = '';
                return;
            }
            materialSearchTimer = setTimeout(function () {
                var body = new URLSearchParams();
                body.set('action', 'crm_inst_buscar_material_holded');
                body.set('nonce', nonce);
                body.set('q', q);
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        lista.innerHTML = '';
                        var items = (resp.success && resp.data && resp.data.items) ? resp.data.items : [];
                        if (!items.length) {
                            lista.style.display = 'none';
                            return;
                        }
                        items.forEach(function (item) {
                            var li = document.createElement('li');
                            li.textContent = item.name;
                            li.addEventListener('click', function () {
                                input.value = item.name;
                                input.setAttribute('data-holded-product-id', item.id);
                                lista.style.display = 'none';
                                lista.innerHTML = '';
                            });
                            lista.appendChild(li);
                        });
                        lista.style.display = 'block';
                    })
                    .catch(function () { lista.style.display = 'none'; });
            }, 300);
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.crm-panel-inst-extra-materiales-wrap')) {
                document.querySelectorAll('.crm-panel-inst-extra-materiales-resultados').forEach(function (l) { l.style.display = 'none'; });
            }
        });

        // Buscador dinámico de "Mis instalaciones": filtra tarjetas por
        // cliente, calle, tipo o materiales (sin acentos/mayúsculas) y
        // resalta en amarillo la coincidencia dentro de la tarjeta. Todo en
        // el propio navegador — sin AJAX, ya que las tarjetas de un
        // instalador caben perfectamente en una sola página.
        var buscadorInput = document.getElementById('crm-panel-inst-buscador');
        if (buscadorInput) {
            function normalizarBusqueda(s) {
                return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
            }
            function escaparHtml(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function resaltar(original, queryNorm) {
                if (!queryNorm) { return escaparHtml(original); }
                var normalizado = normalizarBusqueda(original);
                var idx = normalizado.indexOf(queryNorm);
                if (idx === -1) { return escaparHtml(original); }
                return escaparHtml(original.slice(0, idx)) + '<mark>' + escaparHtml(original.slice(idx, idx + queryNorm.length)) + '</mark>' + escaparHtml(original.slice(idx + queryNorm.length));
            }
            function actualizarSeccionesBusqueda() {
                document.querySelectorAll('.crm-panel-inst-section-title').forEach(function (title) {
                    var el = title.nextElementSibling;
                    var algunaVisible = false;
                    var hayTarjetas = false;
                    while (el && !el.classList.contains('crm-panel-inst-section-title')) {
                        if (el.classList.contains('crm-panel-inst-card')) {
                            hayTarjetas = true;
                            if (el.style.display !== 'none') { algunaVisible = true; }
                        }
                        el = el.nextElementSibling;
                    }
                    title.style.display = (hayTarjetas && !algunaVisible) ? 'none' : '';
                });
            }
            var buscadorLimpiar = document.getElementById('crm-panel-inst-buscador-limpiar');
            function aplicarBusqueda() {
                var queryNorm = normalizarBusqueda(buscadorInput.value.trim());
                var totalVisibles = 0;
                document.querySelectorAll('.crm-panel-inst-card').forEach(function (card) {
                    var blob = card.getAttribute('data-search') || '';
                    var visible = queryNorm === '' || blob.indexOf(queryNorm) !== -1;
                    card.style.display = visible ? '' : 'none';
                    if (visible) { totalVisibles++; }
                });
                document.querySelectorAll('.crm-panel-search-target').forEach(function (el) {
                    el.innerHTML = resaltar(el.getAttribute('data-original') || el.textContent, queryNorm);
                });
                actualizarSeccionesBusqueda();
                var sinResultados = document.querySelector('.crm-panel-inst-buscador-sin-resultados');
                if (sinResultados) {
                    sinResultados.style.display = (queryNorm !== '' && totalVisibles === 0) ? 'block' : 'none';
                }
                if (buscadorLimpiar) {
                    buscadorLimpiar.style.display = buscadorInput.value !== '' ? 'flex' : 'none';
                }
            }
            buscadorInput.addEventListener('input', aplicarBusqueda);
            if (buscadorLimpiar) {
                buscadorLimpiar.addEventListener('click', function () {
                    buscadorInput.value = '';
                    aplicarBusqueda();
                    buscadorInput.focus();
                });
            }
        }

        // Icono "i" de cada tarjeta: toggle no invasivo (tap para abrir/cerrar,
        // se cierra solo al tocar fuera) — nada de hover, para que funcione
        // igual de bien en móvil que en escritorio.
        document.addEventListener('click', function (e) {
            var infoBtn = e.target.closest && e.target.closest('.crm-panel-inst-info-btn');
            document.querySelectorAll('.crm-panel-inst-info-pop.is-open').forEach(function (pop) {
                if (!infoBtn || pop !== infoBtn.parentElement.querySelector('.crm-panel-inst-info-pop')) {
                    pop.classList.remove('is-open');
                    var otherBtn = pop.parentElement.querySelector('.crm-panel-inst-info-btn');
                    if (otherBtn) { otherBtn.classList.remove('is-open'); }
                }
            });
            if (infoBtn) {
                var pop = infoBtn.parentElement.querySelector('.crm-panel-inst-info-pop');
                var abrir = !pop.classList.contains('is-open');
                pop.classList.toggle('is-open', abrir);
                infoBtn.classList.toggle('is-open', abrir);
            }
        });

        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('.crm-panel-inst-extra-toggle');
            if (toggle) {
                var form = toggle.closest('.crm-panel-inst-extra-form-wrap').querySelector('.crm-panel-inst-extra-form');
                form.style.display = form.style.display === 'none' ? 'flex' : 'none';
                return;
            }

            var editarToggle = e.target.closest('.crm-panel-inst-extra-editar-toggle');
            if (editarToggle) {
                var editForm = editarToggle.closest('li').querySelector('.crm-panel-inst-extra-edit-form');
                editForm.style.display = editForm.style.display === 'none' ? 'flex' : 'none';
                return;
            }

            var editarCancelar = e.target.closest('.crm-panel-inst-extra-editar-cancelar');
            if (editarCancelar) {
                editarCancelar.closest('.crm-panel-inst-extra-edit-form').style.display = 'none';
                return;
            }

            var guardarEdicion = e.target.closest('.crm-panel-inst-extra-guardar-edicion');
            if (guardarEdicion) {
                var eWrap = guardarEdicion.closest('.crm-panel-inst-extra-edit-form');
                var eDesc = eWrap.querySelector('.crm-panel-inst-extra-desc').value.trim();
                var eHoras = eWrap.querySelector('.crm-panel-inst-extra-horas').value;
                var eMaterialesInput = eWrap.querySelector('.crm-panel-inst-extra-materiales');
                var eMateriales = eMaterialesInput.value.trim();
                var eHoldedProductId = eMaterialesInput.getAttribute('data-holded-product-id') || '';
                var eImporte = eWrap.querySelector('.crm-panel-inst-extra-importe').value;
                var eObs = eWrap.querySelector('.crm-panel-inst-extra-observaciones').value.trim();
                var eFotoInput = eWrap.querySelector('.crm-panel-inst-extra-foto');
                var eMsg = eWrap.querySelector('.crm-panel-inst-extra-edit-msg');
                var eTrabajoId = guardarEdicion.getAttribute('data-trabajo-id');
                var eTeniaFoto = guardarEdicion.getAttribute('data-tenia-foto') === '1';

                if (!eDesc || !eImporte || parseFloat(eImporte) <= 0) {
                    eMsg.style.color = '#991b1b';
                    eMsg.textContent = 'Rellena descripción e importe.';
                    return;
                }
                if (!eTeniaFoto && (!eFotoInput.files || !eFotoInput.files[0])) {
                    eMsg.style.color = '#991b1b';
                    eMsg.textContent = 'Adjunta una foto que justifique la partida extra.';
                    return;
                }

                guardarEdicion.disabled = true;
                eMsg.style.color = '#6b7280';
                eMsg.textContent = 'Guardando...';

                var eFotoLista = (eFotoInput.files && eFotoInput.files[0])
                    ? (eFotoInput._compressedBlob ? Promise.resolve(eFotoInput._compressedBlob) : comprimirImagen(eFotoInput.files[0]))
                    : Promise.resolve(null);

                eFotoLista.then(function (eFotoBlob) {
                    var eBody = new FormData();
                    eBody.append('action', 'crm_inst_editar_extra');
                    eBody.append('nonce', nonce);
                    eBody.append('trabajo_id', eTrabajoId);
                    eBody.append('descripcion', eDesc);
                    eBody.append('horas', eHoras);
                    eBody.append('materiales_usados', eMateriales);
                    eBody.append('holded_product_id', eHoldedProductId);
                    eBody.append('importe', eImporte);
                    eBody.append('observaciones', eObs);
                    if (eFotoBlob) {
                        eBody.append('justificacion', eFotoBlob, nombreSubida(eFotoInput.files[0], eFotoBlob));
                    }
                    return fetch(ajaxurl, { method: 'POST', body: eBody });
                })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) {
                            guardarEdicion.disabled = false;
                            eMsg.style.color = '#991b1b';
                            eMsg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                            return;
                        }
                        location.reload();
                    })
                    .catch(function () {
                        guardarEdicion.disabled = false;
                        eMsg.style.color = '#991b1b';
                        eMsg.textContent = 'Error de conexión.';
                    });
                return;
            }

            var checklistToggle = e.target.closest('.crm-panel-inst-checklist-toggle');
            if (checklistToggle) {
                var cklForm = checklistToggle.closest('.crm-panel-inst-checklist-form-wrap').querySelector('.crm-panel-inst-checklist-form');
                cklForm.style.display = cklForm.style.display === 'none' ? 'flex' : 'none';
                return;
            }

            var checklistGuardar = e.target.closest('.crm-panel-inst-checklist-guardar');
            if (checklistGuardar) {
                var cklWrap = checklistGuardar.closest('.crm-panel-inst-checklist-form-wrap');
                var materialesOk = cklWrap.querySelector('.crm-panel-inst-checklist-materiales').checked;
                var seguridadOk = cklWrap.querySelector('.crm-panel-inst-checklist-seguridad').checked;
                var cklAlmacenSelect = cklWrap.querySelector('.crm-panel-inst-checklist-almacen');
                var cklAlmacenId = cklAlmacenSelect ? cklAlmacenSelect.value : '';
                var cklMsg = cklWrap.querySelector('.crm-panel-inst-checklist-msg');
                var cklInstalacionId = checklistGuardar.getAttribute('data-instalacion-id');

                if (!materialesOk || !seguridadOk) {
                    cklMsg.style.color = '#991b1b';
                    cklMsg.textContent = 'Marca las dos casillas para poder confirmar.';
                    return;
                }
                if (cklAlmacenSelect && !cklAlmacenId) {
                    cklMsg.style.color = '#991b1b';
                    cklMsg.textContent = 'Selecciona el almacén del que sale el material.';
                    return;
                }

                checklistGuardar.disabled = true;
                cklMsg.style.color = '#6b7280';
                cklMsg.textContent = 'Enviando...';

                var cklBody = new URLSearchParams();
                cklBody.set('action', 'crm_inst_confirmar_checklist');
                cklBody.set('nonce', nonce);
                cklBody.set('instalacion_id', cklInstalacionId);
                cklBody.set('materiales_ok', '1');
                cklBody.set('seguridad_ok', '1');
                cklBody.set('warehouse_id', cklAlmacenId);

                fetch(ajaxurl, { method: 'POST', body: cklBody })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) {
                            checklistGuardar.disabled = false;
                            cklMsg.style.color = '#991b1b';
                            cklMsg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                            return;
                        }
                        location.reload();
                    })
                    .catch(function () {
                        checklistGuardar.disabled = false;
                        cklMsg.style.color = '#991b1b';
                        cklMsg.textContent = 'Error de conexión.';
                    });
                return;
            }

            var cierreToggle = e.target.closest('.crm-panel-inst-cierre-toggle');
            if (cierreToggle) {
                var cierreForm = cierreToggle.closest('.crm-panel-inst-cierre-form-wrap').querySelector('.crm-panel-inst-cierre-form');
                cierreForm.style.display = cierreForm.style.display === 'none' ? 'flex' : 'none';
                return;
            }

            var cierreGuardar = e.target.closest('.crm-panel-inst-cierre-guardar');
            if (cierreGuardar) {
                var cWrap = cierreGuardar.closest('.crm-panel-inst-cierre-form-wrap');
                var conforme = cWrap.querySelector('.crm-panel-inst-cierre-conformidad').checked;
                var cObs = cWrap.querySelector('.crm-panel-inst-cierre-observaciones').value.trim();
                var cMsg = cWrap.querySelector('.crm-panel-inst-cierre-msg');
                var cInstalacionId = cierreGuardar.getAttribute('data-instalacion-id');
                var catInputs = cWrap.querySelectorAll('.crm-panel-inst-cierre-foto-cat');
                var fallbackInput = cWrap.querySelector('.crm-panel-inst-cierre-fotos-input');

                if (!conforme) {
                    cMsg.style.color = '#991b1b';
                    cMsg.textContent = 'Marca la casilla de conformidad para poder cerrar.';
                    return;
                }

                if (catInputs.length) {
                    // v1.20.74: una foto obligatoria por categoría (inversor,
                    // placas... según el subtipo) — validamos que estén todas
                    // antes de comprimir/enviar nada.
                    for (var ci = 0; ci < catInputs.length; ci++) {
                        if (!catInputs[ci].files || !catInputs[ci].files[0]) {
                            cMsg.style.color = '#991b1b';
                            cMsg.textContent = 'Falta la foto: ' + catInputs[ci].closest('.crm-panel-inst-cierre-foto-cat-slot').querySelector('label').textContent + '.';
                            return;
                        }
                    }
                } else if (fallbackInput && fallbackInput.files.length > 6) {
                    cMsg.style.color = '#991b1b';
                    cMsg.textContent = 'Máximo 6 fotos.';
                    return;
                }

                cierreGuardar.disabled = true;
                cMsg.style.color = '#6b7280';
                cMsg.textContent = 'Preparando fotos...';

                var cBody = new FormData();
                cBody.append('action', 'crm_inst_cerrar_instalacion');
                cBody.append('nonce', nonce);
                cBody.append('instalacion_id', cInstalacionId);
                cBody.append('conformidad', '1');
                cBody.append('observaciones', cObs);

                var preparar;
                if (catInputs.length) {
                    preparar = Promise.all(Array.prototype.map.call(catInputs, function (input) {
                        var original = input.files[0];
                        var listo = input._compressedBlob ? Promise.resolve(input._compressedBlob) : comprimirImagen(original);
                        return listo.then(function (blob) {
                            cBody.append('foto_' + input.getAttribute('data-categoria'), blob, nombreSubida(original, blob));
                        });
                    }));
                } else if (fallbackInput) {
                    preparar = Promise.all(Array.prototype.map.call(fallbackInput.files, function (file) {
                        return comprimirImagen(file).then(function (blob) {
                            cBody.append('fotos[]', blob, nombreSubida(file, blob));
                        });
                    }));
                } else {
                    preparar = Promise.resolve();
                }

                preparar.then(function () {
                    cMsg.textContent = 'Enviando...';
                    return fetch(ajaxurl, { method: 'POST', body: cBody });
                })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) {
                            cierreGuardar.disabled = false;
                            cMsg.style.color = '#991b1b';
                            cMsg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                            return;
                        }
                        location.reload();
                    })
                    .catch(function () {
                        cierreGuardar.disabled = false;
                        cMsg.style.color = '#991b1b';
                        cMsg.textContent = 'Error de conexión.';
                    });
                return;
            }

            var enviarCliente = e.target.closest('.crm-panel-inst-extra-enviar-cliente-btn');
            if (enviarCliente) {
                abrirModalEnviarCliente(
                    enviarCliente.getAttribute('data-trabajo-id'),
                    enviarCliente.getAttribute('data-cliente-email') || '',
                    enviarCliente.getAttribute('data-cliente-telefono') || ''
                );
                return;
            }

            var guardar = e.target.closest('.crm-panel-inst-extra-guardar');
            if (!guardar) {
                return;
            }
            var wrap = guardar.closest('.crm-panel-inst-extra-form-wrap');
            var desc = wrap.querySelector('.crm-panel-inst-extra-desc').value.trim();
            var horas = wrap.querySelector('.crm-panel-inst-extra-horas').value;
            var materialesInput = wrap.querySelector('.crm-panel-inst-extra-materiales');
            var materiales = materialesInput.value.trim();
            var holdedProductId = materialesInput.getAttribute('data-holded-product-id') || '';
            var importe = wrap.querySelector('.crm-panel-inst-extra-importe').value;
            var observaciones = wrap.querySelector('.crm-panel-inst-extra-observaciones').value.trim();
            var fotoInput = wrap.querySelector('.crm-panel-inst-extra-foto');
            var msg = wrap.querySelector('.crm-panel-inst-extra-msg');
            var instalacionId = guardar.getAttribute('data-instalacion-id');

            if (!desc || !importe || parseFloat(importe) <= 0) {
                msg.style.color = '#991b1b';
                msg.textContent = 'Rellena descripción e importe.';
                return;
            }
            if (!fotoInput.files || !fotoInput.files[0]) {
                msg.style.color = '#991b1b';
                msg.textContent = 'Adjunta una foto que justifique la partida extra.';
                return;
            }

            guardar.disabled = true;
            msg.style.color = '#6b7280';
            msg.textContent = 'Preparando foto...';

            var fotoOriginal = fotoInput.files[0];
            var fotoLista = fotoInput._compressedBlob ? Promise.resolve(fotoInput._compressedBlob) : comprimirImagen(fotoOriginal);

            fotoLista.then(function (fotoBlob) {
                msg.textContent = 'Enviando...';
                var body = new FormData();
                body.append('action', 'crm_inst_declarar_extra');
                body.append('nonce', nonce);
                body.append('instalacion_id', instalacionId);
                body.append('descripcion', desc);
                body.append('horas', horas);
                body.append('materiales_usados', materiales);
                body.append('holded_product_id', holdedProductId);
                body.append('importe', importe);
                body.append('observaciones', observaciones);
                body.append('justificacion', fotoBlob, nombreSubida(fotoOriginal, fotoBlob));
                return fetch(ajaxurl, { method: 'POST', body: body });
            })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        guardar.disabled = false;
                        msg.style.color = '#991b1b';
                        msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                        return;
                    }
                    location.reload();
                })
                .catch(function () {
                    guardar.disabled = false;
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Error de conexión.';
                });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('crm_inst_panel_instalador', 'crm_inst_shortcode_panel_instalador');
function crm_inst_shortcode_panel_instalador() {
    if (!is_user_logged_in()) {
        return '<p>Necesitas <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a> para acceder.</p>';
    }
    if (!crm_inst_current_user_can_view_own()) {
        return '<p>No tienes acceso a este panel.</p>';
    }

    $user_id    = get_current_user_id();
    $settings   = crm_inst_panel_get_settings();
    $nonce      = wp_create_nonce('crm_inst_holded');
    $con_fecha  = crm_inst_panel_get_visitas_con_fecha($user_id);
    $sin_fecha  = crm_inst_panel_get_asignadas_sin_fecha($user_id);
    $realizadas = crm_inst_panel_get_realizadas($user_id);

    ob_start();
    ?>
    <style>
    <?php echo crm_inst_panel_shared_css($settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </style>
    <div class="crm-panel-inst-wrap">
        <h2 style="display:flex; align-items:center; gap:10px; margin-top:0; font-size:19px; color:#1f2937;">
            <?php echo function_exists('crm_icon') ? crm_icon('map-pin', 20) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            Mis instalaciones
        </h2>

        <div class="crm-panel-inst-buscador-wrap">
            <?php echo function_exists('crm_icon') ? crm_icon('magnifying', 15) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <input type="text" id="crm-panel-inst-buscador" placeholder="Buscar por cliente, calle o material…" autocomplete="off">
            <button type="button" id="crm-panel-inst-buscador-limpiar" aria-label="Borrar búsqueda" style="display:none;"><?php echo function_exists('crm_icon') ? crm_icon('x', 13) : '×'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
        </div>
        <p class="crm-panel-inst-buscador-sin-resultados" style="display:none;">Sin resultados.</p>

        <div class="crm-panel-inst-section-title">Próximas visitas</div>
        <?php if (empty($con_fecha)) : ?>
            <p class="crm-panel-inst-empty">No tienes ninguna visita programada todavía.</p>
        <?php else : ?>
            <?php foreach ($con_fecha as $v) {
                echo crm_inst_panel_render_card($v, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } ?>
        <?php endif; ?>

        <div class="crm-panel-inst-section-title">Asignadas, sin fecha todavía</div>
        <?php if (empty($sin_fecha)) : ?>
            <p class="crm-panel-inst-empty">No hay ninguna instalación asignada sin fecha.</p>
        <?php else : ?>
            <?php foreach ($sin_fecha as $v) {
                echo crm_inst_panel_render_card($v, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } ?>
        <?php endif; ?>

        <div class="crm-panel-inst-section-title">Realizadas</div>
        <?php if (empty($realizadas)) : ?>
            <p class="crm-panel-inst-empty">Todavía no has completado ninguna instalación.</p>
        <?php else : ?>
            <?php foreach ($realizadas as $v) {
                echo crm_inst_panel_render_card($v, true, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } ?>
        <?php endif; ?>
    </div>
    <?php echo crm_inst_panel_extras_js($nonce); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
    return ob_get_clean();
}

/**
 * Shortcode [crm_inst_panel_calendario] — misma información que "Mis
 * instalaciones" (solo las visitas que ya tienen fecha), pero en vista de
 * calendario. Reutiliza FullCalendar igual que [crm_mi_agenda]
 * (includes/shortcode-agenda.php) para no reinventar esa pieza, y reutiliza
 * `crm_inst_panel_render_card()` para el detalle de cada visita (en vez de
 * duplicar el marcado en JS) — el detalle se pre-renderiza en PHP dentro de
 * un <template> oculto por instalación y el clic solo lo muestra en un modal,
 * así no hace falta abrir la ficha de instalaciones (que exige permisos de
 * gestión que un instalador puro no tiene).
 */
add_shortcode('crm_inst_panel_calendario', 'crm_inst_shortcode_panel_calendario');
function crm_inst_shortcode_panel_calendario() {
    if (!is_user_logged_in()) {
        return '<p>Necesitas <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a> para acceder.</p>';
    }
    if (!crm_inst_current_user_can_view_own()) {
        return '<p>No tienes acceso a este calendario.</p>';
    }

    $user_id   = get_current_user_id();
    $settings  = crm_inst_panel_get_settings();
    $nonce     = wp_create_nonce('crm_inst_holded');
    $con_fecha = crm_inst_panel_get_visitas_con_fecha($user_id);

    // v1.20.54: cada evento se pinta del color de SU estado (misma paleta que
    // las tarjetas y el resto del CRM — crm_instalaciones_estado_color()), no
    // todos del mismo verde de marca — así se distinguen de un vistazo.
    $eventos = [];
    foreach ($con_fecha as $v) {
        if (empty($v['fecha_cita'])) {
            continue;
        }
        try {
            $start = new DateTime($v['fecha_cita']);
            $end   = clone $start;
            $end->modify('+60 minutes');
        } catch (Exception $e) {
            continue;
        }
        $eventos[] = [
            'id'              => (int) $v['instalacion_id'],
            'title'           => $v['cliente_nombre'],
            'start'           => $start->format('Y-m-d\TH:i:s'),
            'end'             => $end->format('Y-m-d\TH:i:s'),
            'backgroundColor' => $v['estado_color'],
            'borderColor'     => $v['estado_color'],
        ];
    }
    $eventos_json = wp_json_encode($eventos);

    ob_start();
    ?>
    <style>
    <?php echo crm_inst_panel_shared_css($settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    .crm-panel-inst-wrap { max-width: 900px; }
    #crm-panel-inst-calendar { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px; }
    .crm-panel-inst-modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:99999; align-items:center; justify-content:center; padding:16px; }
    .crm-panel-inst-modal-overlay.is-open { display:flex; }
    .crm-panel-inst-modal { background:transparent; width:100%; max-width:420px; position:relative; }
    .crm-panel-inst-modal .crm-panel-inst-card { margin-bottom:0; }
    .crm-panel-inst-modal-close { position:absolute; top:-14px; right:-14px; width:30px; height:30px; border-radius:50%; border:1px solid #e5e7eb; background:#fff; cursor:pointer; font-size:16px; line-height:1; color:#1f2937; }
    .crm-panel-inst-leyenda { display:flex; flex-wrap:wrap; gap:12px; margin-top:10px; }
    .crm-panel-inst-leyenda-item { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; color:#6b7280; }
    .crm-panel-inst-leyenda-dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
    </style>
    <div class="crm-panel-inst-wrap">
        <h2 style="display:flex; align-items:center; gap:10px; margin-top:0; font-size:19px; color:#1f2937;">
            <?php echo function_exists('crm_icon') ? crm_icon('calendar', 20) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            Calendario
        </h2>

        <div id="crm-panel-inst-calendar"></div>

        <?php
        $estados_leyenda = ['pendiente', 'planificada', 'en_ejecucion', 'lista', 'bloqueada', 'reagendada'];
        $estados_labels  = crm_instalaciones_estados();
        ?>
        <div class="crm-panel-inst-leyenda">
            <?php foreach ($estados_leyenda as $est) : ?>
                <span class="crm-panel-inst-leyenda-item">
                    <span class="crm-panel-inst-leyenda-dot" style="background: <?php echo esc_attr(crm_instalaciones_estado_color($est)); ?>;"></span>
                    <?php echo esc_html($estados_labels[$est] ?? $est); ?>
                </span>
            <?php endforeach; ?>
        </div>

        <?php if (empty($con_fecha)) : ?>
            <p class="crm-panel-inst-empty" style="margin-top:14px;">No tienes ninguna visita programada todavía.</p>
        <?php endif; ?>

        <?php foreach ($con_fecha as $v) :
            if (empty($v['fecha_cita'])) {
                continue;
            }
        ?>
            <template id="crm-panel-inst-tpl-<?php echo (int) $v['instalacion_id']; ?>">
                <?php echo crm_inst_panel_render_card($v, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </template>
        <?php endforeach; ?>
    </div>

    <div class="crm-panel-inst-modal-overlay" id="crm-panel-inst-modal-overlay">
        <div class="crm-panel-inst-modal">
            <button type="button" class="crm-panel-inst-modal-close" id="crm-panel-inst-modal-close" aria-label="Cerrar">&times;</button>
            <div id="crm-panel-inst-modal-body"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script>
    (function () {
        var eventos = <?php echo $eventos_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
        var mountEl = document.getElementById('crm-panel-inst-calendar');
        var overlay = document.getElementById('crm-panel-inst-modal-overlay');
        var body    = document.getElementById('crm-panel-inst-modal-body');
        var closeBtn = document.getElementById('crm-panel-inst-modal-close');
        if (!mountEl || !window.FullCalendar) {
            return;
        }

        function openModal(instalacionId) {
            var tpl = document.getElementById('crm-panel-inst-tpl-' + instalacionId);
            if (!tpl) {
                return;
            }
            body.innerHTML = '';
            body.appendChild(tpl.content.cloneNode(true));
            overlay.classList.add('is-open');
        }
        function closeModal() {
            overlay.classList.remove('is-open');
            body.innerHTML = '';
        }
        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closeModal();
            }
        });

        var calendar = new FullCalendar.Calendar(mountEl, {
            initialView: 'dayGridMonth',
            locale: 'es',
            firstDay: 1,
            height: 'auto',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            buttonText: { today: 'Hoy', month: 'Mes', list: 'Lista' },
            events: eventos,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                openModal(info.event.id);
            }
        });
        calendar.render();
    })();
    </script>
    <?php echo crm_inst_panel_extras_js($nonce); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
    return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// "Mi perfil" — datos de contacto propios del instalador (hoy: WhatsApp).
// ──────────────────────────────────────────────────────────────────────────────

/**
 * El WhatsApp vive como user meta (`crm_whatsapp`) en vez de una tabla propia
 * — es un dato de perfil de USUARIO de WordPress, no de la instalación, y así
 * queda disponible igual para comercial/jefe el día que lo necesiten sin
 * rediseñar nada. Doble propósito: dato de contacto y futuro canal de
 * notificaciones (Fase 7/8, hoy bloqueada por no tener aún credenciales
 * WhatsApp/SMTP configuradas) — de momento solo se guarda, no se usa todavía
 * para enviar nada.
 */
add_shortcode('crm_inst_panel_perfil', 'crm_inst_shortcode_panel_perfil');
function crm_inst_shortcode_panel_perfil() {
    if (!is_user_logged_in()) {
        return '<p>Necesitas <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a> para acceder.</p>';
    }
    if (!crm_inst_current_user_can_view_own()) {
        return '<p>No tienes acceso a este panel.</p>';
    }

    $user      = wp_get_current_user();
    $settings  = crm_inst_panel_get_settings();
    $nonce     = wp_create_nonce('crm_inst_holded');
    $whatsapp  = (string) get_user_meta($user->ID, 'crm_whatsapp', true);

    ob_start();
    ?>
    <style>
    <?php echo crm_inst_panel_shared_css($settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    .crm-panel-inst-perfil-form { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:16px; max-width:360px; }
    .crm-panel-inst-perfil-form label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .crm-panel-inst-perfil-form input[type="text"] { width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:14px; margin-bottom:10px; }
    .crm-panel-inst-perfil-form small { display:block; color:#9ca3af; font-size:11px; margin:-6px 0 12px; }
    </style>
    <div class="crm-panel-inst-wrap">
        <h2 style="display:flex; align-items:center; gap:10px; margin-top:0; font-size:19px; color:#1f2937;">
            Mi perfil
        </h2>
        <div class="crm-panel-inst-perfil-form">
            <label for="crm-inst-perfil-nombre">Nombre</label>
            <input type="text" id="crm-inst-perfil-nombre" value="<?php echo esc_attr($user->display_name); ?>" disabled>
            <label for="crm-inst-perfil-whatsapp">WhatsApp</label>
            <input type="text" id="crm-inst-perfil-whatsapp" placeholder="+34 600 000 000" value="<?php echo esc_attr($whatsapp); ?>">
            <small>Se usará como dato de contacto y, más adelante, como canal de avisos.</small>
            <button type="button" class="crm-btn" id="crm-inst-perfil-guardar-btn">Guardar</button>
            <span id="crm-inst-perfil-msg" style="margin-left:8px; font-size:12px;"></span>
        </div>
    </div>
    <script>
    (function () {
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce   = <?php echo wp_json_encode($nonce); ?>;
        document.getElementById('crm-inst-perfil-guardar-btn').addEventListener('click', function () {
            var btn = this;
            var msg = document.getElementById('crm-inst-perfil-msg');
            var whatsapp = document.getElementById('crm-inst-perfil-whatsapp').value.trim();
            btn.disabled = true;
            msg.style.color = '#6b7280';
            msg.textContent = 'Guardando...';
            var body = new URLSearchParams();
            body.set('action', 'crm_inst_guardar_perfil');
            body.set('nonce', nonce);
            body.set('whatsapp', whatsapp);
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    btn.disabled = false;
                    if (!resp.success) {
                        msg.style.color = '#991b1b';
                        msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                        return;
                    }
                    msg.style.color = '#065f46';
                    msg.textContent = 'Guardado.';
                })
                .catch(function () {
                    btn.disabled = false;
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Error de conexión.';
                });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_crm_inst_guardar_perfil', 'crm_inst_ajax_guardar_perfil');
function crm_inst_ajax_guardar_perfil() {
    if (!is_user_logged_in() || !check_ajax_referer('crm_inst_holded', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $whatsapp = sanitize_text_field(wp_unslash($_POST['whatsapp'] ?? ''));
    if ($whatsapp !== '' && !preg_match('/^[0-9+\s()-]{6,20}$/', $whatsapp)) {
        wp_send_json_error(['message' => 'Ese número no parece válido — usa solo dígitos, espacios y el prefijo internacional.']);
    }

    // Siempre el usuario actual: nadie edita el perfil de otro desde aquí.
    update_user_meta(get_current_user_id(), 'crm_whatsapp', $whatsapp);

    wp_send_json_success(['whatsapp' => $whatsapp]);
}
