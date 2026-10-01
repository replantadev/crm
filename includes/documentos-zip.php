<?php
/**
 * Descarga conjunta en ZIP de toda la documentación de una instalación.
 *
 * Hueco real del presupuesto original del módulo ("Propuesta Ecovolt -
 * instalaciones"): no había forma de bajarse fotos de cierre + acta en un
 * solo paso, había que abrir archivo por archivo. Repaso 2026-09-30, cierre
 * del hueco #2.
 *
 * Se genera al vuelo (no se persiste como documento aparte): recorre toda la
 * tabla crm_instalacion_documentos de la instalación sea cual sea su `tipo`,
 * así que si en el futuro se usan los tipos hoy sin poblar (presupuesto,
 * albarán, certificado) entran solos sin tocar este archivo.
 *
 * @package CRM_Energitel
 * @since 1.20.172
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Memoria en texto plano de la instalación (datos de cliente, instalación,
 * instaladores, materiales/trabajos entregados y cierre) — pedida por el
 * usuario 2026-10-01: el ZIP solo bajaba fotos/acta, sin ningún resumen de
 * los datos en sí. Reutiliza crm_inst_get_instalacion_data() como única
 * fuente de datos (mismo criterio que el acta de entrega en PDF) y el mismo
 * filtro de "materiales realmente entregados" que usa esa acta.
 *
 * @param array $data Lo que devuelve crm_inst_get_instalacion_data().
 * @return string
 */
function crm_inst_zip_memoria_texto(array $data) {
    $lineas = [];
    $lineas[] = 'MEMORIA DE INSTALACIÓN ' . $data['id_visible'];
    $lineas[] = 'Generado: ' . date_i18n('d/m/Y H:i');
    $lineas[] = '';
    $lineas[] = '== CLIENTE ==';
    $lineas[] = 'Nombre: ' . $data['cliente_nombre'];
    $lineas[] = 'Teléfono: ' . ($data['telefono'] ?: '—');
    $lineas[] = 'Email: ' . ($data['email_cliente'] ?: '—');
    $lineas[] = 'Dirección de instalación: ' . ($data['direccion_instalacion'] ?: '—');
    $lineas[] = '';
    $lineas[] = '== INSTALACIÓN ==';
    $lineas[] = 'Tipo: ' . $data['tipo_instalacion_label'] . ' (' . $data['subtipo_instalacion_label'] . ')';
    $lineas[] = 'Estado: ' . $data['estado_label'];
    $lineas[] = 'Fecha de alta: ' . ($data['fecha_creacion'] ? date_i18n('d/m/Y H:i', strtotime($data['fecha_creacion'])) : '—');
    $lineas[] = 'Duración estimada: ' . $data['duracion_dias'] . ' día(s)';
    $lineas[] = '';
    $lineas[] = '== INSTALADOR(ES) ==';
    if (empty($data['instaladores'])) {
        $lineas[] = 'Sin instalador asignado.';
    } else {
        foreach ($data['instaladores'] as $inst) {
            $lineas[] = '- ' . $inst['display_name'] . ' (' . $inst['rol_en_proyecto'] . ')'
                . (!empty($inst['horas_declaradas']) ? ' — ' . $inst['horas_declaradas'] . ' h declaradas' : '');
        }
    }
    $lineas[] = '';
    $lineas[] = '== MATERIALES / TRABAJOS ENTREGADOS ==';
    $materiales_entregados = array_filter($data['materiales'], function ($m) {
        return $m['origen'] === 'extra' ? $m['estado'] === 'aprobado' : !empty($m['montado_en']);
    });
    if (empty($materiales_entregados)) {
        $lineas[] = 'Sin materiales/trabajos registrados como entregados.';
    } else {
        foreach ($materiales_entregados as $m) {
            $lineas[] = sprintf('- %s (%s x %s€ = %s€)', $m['descripcion'], $m['unidades'], number_format($m['precio_unitario'], 2, ',', '.'), number_format($m['importe'], 2, ',', '.'));
        }
    }
    $lineas[] = '';
    $lineas[] = '== CIERRE ==';
    $lineas[] = 'Estado: ' . ($data['cierre']['estado'] ?: 'sin cerrar');
    if ($data['cierre']['estado'] !== '') {
        $lineas[] = 'Conformidad del cliente: ' . ($data['cierre']['conformidad'] ? 'Sí' : 'No');
        $lineas[] = 'Observaciones: ' . ($data['cierre']['observaciones'] ?: '—');
        if ($data['cierre']['declarado_por_nombre']) {
            $lineas[] = 'Declarado por: ' . $data['cierre']['declarado_por_nombre'] . ($data['cierre']['declarado_en'] ? ' el ' . date_i18n('d/m/Y H:i', strtotime($data['cierre']['declarado_en'])) : '');
        }
        if ($data['cierre']['validado_por_nombre']) {
            $lineas[] = 'Validado por: ' . $data['cierre']['validado_por_nombre'] . ($data['cierre']['validado_en'] ? ' el ' . date_i18n('d/m/Y H:i', strtotime($data['cierre']['validado_en'])) : '');
        }
    }

    return implode("\n", $lineas) . "\n";
}

/**
 * Construye el ZIP en un archivo temporal y devuelve su ruta local, o
 * WP_Error si falla algo. Separada de la parte HTTP (cabeceras/exit) para
 * poder probarla de forma aislada.
 *
 * @param int $instalacion_id
 * @return string|WP_Error Ruta local del .zip temporal.
 */
function crm_inst_construir_zip_documentos($instalacion_id) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('sin_zip', 'Esta instalación de WordPress no tiene soporte de ZIP (extensión PHP "zip"). Contacta con tu proveedor de hosting.');
    }

    $data = crm_inst_get_instalacion_data($instalacion_id);
    if (is_wp_error($data)) {
        return $data;
    }

    global $wpdb;
    $documentos = $wpdb->get_results($wpdb->prepare(
        "SELECT ruta, tipo, categoria FROM " . crm_inst_table_documentos() . " WHERE instalacion_id = %d ORDER BY tipo ASC, id ASC",
        $instalacion_id
    ), ARRAY_A);

    $archivos = [];
    foreach ((array) $documentos as $doc) {
        $local = crm_inst_acta_ruta_local($doc['ruta']);
        if ($local && file_exists($local)) {
            $archivos[] = [
                'local'     => $local,
                'tipo'      => $doc['tipo'],
                'categoria' => $doc['categoria'],
            ];
        }
    }

    $tmp_zip = wp_tempnam('crm-inst-doc-' . $instalacion_id);
    $zip = new ZipArchive();
    if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_fallo', 'No se pudo generar el ZIP.');
    }

    $zip->addFromString('memoria-instalacion.txt', crm_inst_zip_memoria_texto($data));

    $nombres_usados = [];
    foreach ($archivos as $archivo) {
        $ext    = pathinfo($archivo['local'], PATHINFO_EXTENSION) ?: 'bin';
        $base   = $archivo['tipo'] . (!empty($archivo['categoria']) ? '-' . sanitize_title($archivo['categoria']) : '');
        $nombre = $base . '.' . $ext;
        $i = 2;
        while (in_array($nombre, $nombres_usados, true)) {
            $nombre = $base . '-' . $i . '.' . $ext;
            $i++;
        }
        $nombres_usados[] = $nombre;
        $zip->addFile($archivo['local'], $nombre);
    }
    $zip->close();

    return $tmp_zip;
}

add_action('wp_ajax_crm_inst_descargar_zip', 'crm_inst_ajax_descargar_zip');
function crm_inst_ajax_descargar_zip() {
    if (!is_user_logged_in() || !check_ajax_referer('crm_inst_holded', 'nonce', false)) {
        wp_die('Sin permisos.', 'Sin permisos', ['response' => 403]);
    }

    $instalacion_id = (int) ($_GET['instalacion_id'] ?? 0);
    if ($instalacion_id <= 0) {
        wp_die('Instalación no válida.');
    }

    $tmp_zip = crm_inst_construir_zip_documentos($instalacion_id);
    if (is_wp_error($tmp_zip)) {
        wp_die(esc_html($tmp_zip->get_error_message()));
    }

    $nombre_descarga = 'instalacion-' . $instalacion_id . '-documentacion.zip';
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
    header('Content-Length: ' . filesize($tmp_zip));
    readfile($tmp_zip);
    @unlink($tmp_zip);
    exit;
}
