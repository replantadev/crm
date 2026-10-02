<?php
/**
 * Acta de entrega de instalación, en PDF.
 *
 * Hueco real del presupuesto original del módulo ("Propuesta Ecovolt -
 * instalaciones", 10/08/2026): "Generación y descarga del acta de entrega en
 * PDF" nunca se construyó. Repaso 2026-09-30: primer hueco a cerrar.
 *
 * Usa dompdf (Composer, vendor/) — genera el PDF a partir de HTML/CSS
 * normal, así que se reutiliza el mismo HTML que ya pinta la ficha en vez de
 * posicionar nada a mano (la alternativa habría sido TCPDF, mucho más manual
 * para algo con tablas y fotos). Se guarda como documento real de la
 * instalación (`crm_instalacion_documentos`, tipo='acta' — columna que ya
 * existía en el esquema desde la Fase 1, sin usar hasta ahora) para quedar
 * descargable en cualquier momento, no solo justo al cerrar.
 *
 * @package CRM_Energitel
 * @since 1.20.167
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convierte una URL propia (uploads o del propio plugin) en una ruta de
 * archivo local — dompdf embebe imágenes por ruta `file://` de forma mucho
 * más fiable y rápida que pidiéndolas por HTTP (y evita depender de que el
 * propio servidor pueda hacerse una petición a sí mismo). Si la URL no es
 * reconocible como local, se descarta (mejor sin foto que un PDF que nunca
 * termina de generarse esperando una petición de red).
 *
 * @param string $url
 * @return string|null
 */
function crm_inst_acta_ruta_local($url) {
    $url = (string) $url;
    if ($url === '') {
        return null;
    }
    $upload_dir = wp_upload_dir();
    if (strpos($url, $upload_dir['baseurl']) === 0) {
        return $upload_dir['basedir'] . substr($url, strlen($upload_dir['baseurl']));
    }
    if (defined('CRM_PLUGIN_URL') && defined('CRM_PLUGIN_PATH') && strpos($url, CRM_PLUGIN_URL) === 0) {
        return CRM_PLUGIN_PATH . substr($url, strlen(CRM_PLUGIN_URL));
    }
    return null;
}

/**
 * `src` listo para un `<img>` dentro del HTML que le pasamos a dompdf, o ''
 * si la imagen no se puede resolver a un archivo local real.
 */
function crm_inst_acta_img_src($url) {
    $local = crm_inst_acta_ruta_local($url);
    if ($local && file_exists($local)) {
        return 'file:///' . str_replace('\\', '/', $local);
    }
    return '';
}

/**
 * HTML del acta — separado de la generación del PDF en sí para poder
 * revisarlo/depurarlo (basta con hacer `echo` de esta función) sin tener que
 * generar un PDF cada vez.
 *
 * @param array $data     Lo que devuelve crm_inst_get_instalacion_data().
 * @param array $branding ['nombre', 'logo', 'color'] — crm_mail_marca_config().
 * @return string
 */
function crm_inst_acta_entrega_html(array $data, array $branding) {
    $logo_src = crm_inst_acta_img_src($branding['logo'] ?? '');
    $color    = sanitize_hex_color($branding['color'] ?? '') ?: '#15803d';

    $materiales_entregados = array_filter($data['materiales'], function ($m) {
        if ($m['origen'] === 'extra') {
            return $m['estado'] === 'aprobado';
        }
        return !empty($m['montado_en']);
    });

    $categorias_fotos = function_exists('crm_inst_categorias_fotos_cierre')
        ? crm_inst_categorias_fotos_cierre($data['subtipo_instalacion'])
        : [];

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .cabecera { border-bottom: 2px solid <?php echo esc_attr($color); ?>; padding-bottom: 10px; margin-bottom: 16px; overflow: hidden; }
        .cabecera img { height: 36px; float: left; margin-right: 12px; }
        .cabecera h1 { font-size: 17px; margin: 0; color: <?php echo esc_attr($color); ?>; }
        .cabecera p { margin: 2px 0 0; color: #6b7280; font-size: 10px; }
        h2 { font-size: 13px; color: <?php echo esc_attr($color); ?>; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; margin: 16px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; font-size: 10.5px; }
        th { background: #f9fafb; color: #374151; }
        .datos-grid { width: 100%; }
        .datos-grid td { border: none; padding: 2px 6px 2px 0; vertical-align: top; }
        .datos-grid .etiqueta { color: #6b7280; width: 110px; }
        .fotos { margin-top: 6px; }
        .fotos .foto-item { display: inline-block; width: 31%; margin: 0 1% 8px 0; text-align: center; vertical-align: top; }
        .fotos img { width: 100%; max-height: 110px; object-fit: cover; border: 1px solid #e5e7eb; border-radius: 4px; }
        .fotos span { display: block; font-size: 9px; color: #6b7280; margin-top: 2px; }
        .conformidad-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 10px; margin-top: 4px; }
        .pie { margin-top: 24px; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
    </style>
    </head>
    <body>
        <div class="cabecera">
            <?php if ($logo_src !== '') : ?><img src="<?php echo esc_attr($logo_src); ?>"><?php endif; ?>
            <h1>Acta de entrega de instalación</h1>
            <p><?php echo esc_html($branding['nombre'] ?? ''); ?> — <?php echo esc_html(function_exists('crm_inst_id_visible') ? crm_inst_id_visible($data['id']) : ('#' . $data['id'])); ?></p>
        </div>

        <h2>Datos del cliente y de la instalación</h2>
        <table class="datos-grid">
            <tr><td class="etiqueta">Cliente</td><td><?php echo esc_html($data['cliente_nombre']); ?></td></tr>
            <tr><td class="etiqueta">Dirección</td><td><?php echo esc_html($data['direccion_instalacion'] ?: '—'); ?></td></tr>
            <tr><td class="etiqueta">Teléfono</td><td><?php echo esc_html($data['telefono'] ?: '—'); ?></td></tr>
            <tr><td class="etiqueta">Email</td><td><?php echo esc_html($data['email_cliente'] ?: '—'); ?></td></tr>
            <tr><td class="etiqueta">Tipo</td><td><?php echo esc_html($data['tipo_instalacion_label']); ?><?php echo $data['subtipo_instalacion_label'] ? ' — ' . esc_html($data['subtipo_instalacion_label']) : ''; ?></td></tr>
            <tr><td class="etiqueta">Fecha de cierre</td><td><?php echo esc_html($data['cierre']['declarado_en'] ? date_i18n('d/m/Y', strtotime($data['cierre']['declarado_en'])) : '—'); ?></td></tr>
        </table>

        <?php if (!empty($data['instaladores'])) : ?>
        <h2>Instalador(es)</h2>
        <table>
            <thead><tr><th>Nombre</th><th>Rol</th></tr></thead>
            <tbody>
            <?php foreach ($data['instaladores'] as $inst) : ?>
                <tr>
                    <td><?php echo esc_html($inst['display_name']); ?></td>
                    <td><?php echo esc_html($inst['rol_en_proyecto'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($materiales_entregados)) : ?>
        <h2>Materiales y trabajos entregados</h2>
        <table>
            <thead><tr><th>Descripción</th><th>Unidades</th><th>Origen</th></tr></thead>
            <tbody>
            <?php foreach ($materiales_entregados as $m) : ?>
                <tr>
                    <td><?php echo esc_html($m['descripcion']); ?></td>
                    <td><?php echo esc_html((string) $m['unidades']); ?></td>
                    <td><?php echo esc_html($m['origen'] === 'extra' ? 'Partida extra aprobada' : 'Presupuesto'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2>Conformidad</h2>
        <div class="conformidad-box">
            <?php if (!empty($data['cierre']['conformidad'])) : ?>
                <p style="margin:0;"><strong>El instalador confirma que la instalación se realizó conforme a lo acordado.</strong></p>
            <?php else : ?>
                <p style="margin:0;">Sin confirmación de conformidad registrada.</p>
            <?php endif; ?>
            <?php if (!empty($data['cierre']['observaciones'])) : ?>
                <p style="margin:6px 0 0;">Observaciones: <?php echo esc_html($data['cierre']['observaciones']); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($data['cierre']['fotos'])) : ?>
        <h2>Fotografías del cierre</h2>
        <div class="fotos">
            <?php foreach ($data['cierre']['fotos'] as $foto) :
                $src = crm_inst_acta_img_src($foto['ruta']);
                if ($src === '') { continue; }
                $cat_label = !empty($foto['categoria']) ? ($categorias_fotos[$foto['categoria']] ?? ucfirst(str_replace('_', ' ', $foto['categoria']))) : '';
            ?>
                <div class="foto-item">
                    <img src="<?php echo esc_attr($src); ?>">
                    <?php if ($cat_label !== '') : ?><span><?php echo esc_html($cat_label); ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="pie">
            Documento generado automáticamente por el CRM el <?php echo esc_html(date_i18n('d/m/Y H:i')); ?>.
            No sustituye a la factura ni a ningún certificado técnico de la instalación.
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Genera (o regenera) el PDF del acta de entrega de una instalación
 * finalizada y lo guarda como documento de la instalación.
 *
 * Best-effort a propósito: quien la llama (cierre automático, aprobación del
 * jefe, o el botón manual) decide qué hacer si falla — nunca debe impedir
 * que la instalación se cierre de verdad por un problema generando el PDF.
 *
 * @param int $instalacion_id
 * @return string|WP_Error URL del PDF generado, o WP_Error.
 */
function crm_inst_generar_acta_entrega($instalacion_id) {
    $instalacion_id = (int) $instalacion_id;

    if (!class_exists('Dompdf\\Dompdf')) {
        $autoload = CRM_PLUGIN_PATH . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }
    if (!class_exists('Dompdf\\Dompdf')) {
        return new WP_Error('crm_acta_sin_dompdf', 'No se pudo cargar la librería de generación de PDF (dompdf).');
    }
    if (!function_exists('crm_inst_get_instalacion_data')) {
        return new WP_Error('crm_acta_dependencia', 'Falta crm_inst_get_instalacion_data().');
    }

    $data = crm_inst_get_instalacion_data($instalacion_id);
    if (is_wp_error($data)) {
        return $data;
    }
    if (empty($data['cierre']) || $data['cierre']['estado'] !== 'aprobado') {
        return new WP_Error('crm_acta_no_finalizada', 'Solo se puede generar el acta de una instalación finalizada.');
    }

    global $wpdb;
    $marca = 'ecovolt';
    if (!empty($data['client_id']) && function_exists('crm_cliente_resolver_marca')) {
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT marca_comunicacion, intereses FROM {$wpdb->prefix}crm_clients WHERE id = %d",
            $data['client_id']
        ), ARRAY_A);
        if ($client) {
            $marca = crm_cliente_resolver_marca($client);
        }
    }
    $branding = function_exists('crm_mail_marca_config')
        ? crm_mail_marca_config($marca)
        : ['nombre' => 'Ecovolt', 'logo' => '', 'color' => '#15803d'];

    $html = crm_inst_acta_entrega_html($data, $branding);

    // Dompdf con fotos embebidas puede pedir más memoria/tiempo del límite
    // por defecto de algunos hostings — best-effort, sin romper nada si el
    // hosting no deja subirlo (@ silencia el aviso si está deshabilitado).
    @ini_set('memory_limit', '256M');
    @set_time_limit(60);

    try {
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'      => false, // las imágenes se embeben por ruta local (ver crm_inst_acta_img_src()), nunca por red.
            'isHtml5ParserEnabled' => true,
            'defaultFont'          => 'DejaVu Sans',
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf_contenido = $dompdf->output();
    } catch (\Throwable $e) {
        return new WP_Error('crm_acta_render_error', 'Fallo generando el PDF: ' . $e->getMessage());
    }

    $upload_dir = wp_upload_dir();
    $subdir     = '/crm-actas';
    if (!file_exists($upload_dir['basedir'] . $subdir)) {
        wp_mkdir_p($upload_dir['basedir'] . $subdir);
    }
    // v1.20.190 — versionado real: la columna `version` existe en el
    // esquema desde la Fase 1 pero nunca se rellenaba (se quedaba siempre en
    // 1, su default). Al regenerar el acta no se borra la fila/archivo
    // anterior (decisión del usuario: conservar historial) — simplemente
    // queda una fila con un número de versión mayor, y crm_inst_documentos_actuales()
    // (includes/instalaciones.php) se encarga de que solo la última cuente
    // como vigente en el ZIP y en la ficha.
    //
    // El nombre de archivo incluye la versión, no solo la marca de tiempo:
    // dos regeneraciones dentro del mismo segundo (doble clic, o dos
    // llamadas automáticas seguidas) generaban antes el MISMO nombre de
    // fichero y la versión nueva pisaba físicamente a la anterior en disco,
    // aunque la fila de la base de datos fuese distinta — rompiendo la
    // promesa de conservar el historial. Encontrado probando el archivado
    // automático dos veces seguidas en Local.
    $version_anterior = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(version) FROM " . crm_inst_table_documentos() . " WHERE instalacion_id = %d AND tipo = 'acta'",
        $instalacion_id
    ));
    $version = $version_anterior + 1;

    $nombre_fichero = 'acta-instalacion-' . $instalacion_id . '-v' . $version . '-' . gmdate('Ymd-His') . '.pdf';
    $ruta_absoluta  = $upload_dir['basedir'] . $subdir . '/' . $nombre_fichero;

    if (file_put_contents($ruta_absoluta, $pdf_contenido) === false) {
        return new WP_Error('crm_acta_no_guardado', 'No se pudo guardar el PDF generado en el servidor.');
    }
    $url = $upload_dir['baseurl'] . $subdir . '/' . $nombre_fichero;

    $wpdb->insert(crm_inst_table_documentos(), [
        'instalacion_id' => $instalacion_id,
        'tipo'           => 'acta',
        'ruta'           => $url,
        'subido_por'     => get_current_user_id(),
        'subido_en'      => current_time('mysql'),
        'version'        => $version,
    ]);

    if (function_exists('crm_inst_log_action')) {
        crm_inst_log_action($instalacion_id, 'instalacion', 'acta_generada', 'Acta de entrega generada en PDF.');
    }

    return $url;
}

/**
 * Botón manual "Generar/Regenerar acta" en la ficha — para instalaciones
 * finalizadas antes de que existiera esta función, o si hace falta
 * regenerarla tras corregir algún dato.
 */
add_action('wp_ajax_crm_inst_generar_acta', 'crm_inst_ajax_generar_acta');
function crm_inst_ajax_generar_acta() {
    if (!is_user_logged_in() || !current_user_can('crm_inst_close') || !check_ajax_referer('crm_inst_holded', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }
    $instalacion_id = (int) ($_POST['instalacion_id'] ?? 0);
    if ($instalacion_id <= 0) {
        wp_send_json_error(['message' => 'Instalación no válida.']);
    }
    $resultado = crm_inst_generar_acta_entrega($instalacion_id);
    if (is_wp_error($resultado)) {
        wp_send_json_error(['message' => $resultado->get_error_message()]);
    }
    wp_send_json_success(['url' => $resultado]);
}

/**
 * Roadmap (v1.20.167) — ver includes/flujos-page.php. Primer hueco cerrado
 * del repaso del presupuesto original del módulo (2026-09-30).
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Presupuesto original · huecos',
        'titulo'  => 'Acta de entrega en PDF al finalizar una instalación',
        'estado'  => 'en_pruebas',
        'detalle' => 'Del presupuesto original del módulo ("Propuesta Ecovolt - instalaciones", 10/08/2026): "Generación y descarga del acta de entrega en PDF" nunca se había construido. Se genera sola al finalizar (cierre directo o aprobación del jefe) con dompdf (Composer), reutilizando crm_inst_get_instalacion_data() para el contenido — cliente, dirección, instalador(es), materiales/trabajos realmente entregados (montados o partida extra aprobada), conformidad/observaciones y fotos del cierre (embebidas por ruta local, nunca por red). Se guarda como documento real de la instalación (tipo=\'acta\', columna del esquema desde la Fase 1, sin usar hasta ahora) — descargable en cualquier momento desde la ficha, con botón para regenerarla. Verificado con una prueba real (no solo lint): genera un PDF de verdad, con cabecera %PDF- válida, a partir de datos de ejemplo. Pendiente: confirmar el aspecto final con el usuario y probarlo contra una instalación real ya finalizada.',
    ];
    return $fases;
});
