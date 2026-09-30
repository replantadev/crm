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
 * Construye el ZIP en un archivo temporal y devuelve su ruta local, o
 * WP_Error si no hay nada que empaquetar o falla algo. Separada de la parte
 * HTTP (cabeceras/exit) para poder probarla de forma aislada.
 *
 * @param int $instalacion_id
 * @return string|WP_Error Ruta local del .zip temporal.
 */
function crm_inst_construir_zip_documentos($instalacion_id) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('sin_zip', 'Esta instalación de WordPress no tiene soporte de ZIP (extensión PHP "zip"). Contacta con tu proveedor de hosting.');
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

    if (empty($archivos)) {
        return new WP_Error('sin_documentos', 'Esta instalación todavía no tiene documentos descargables.');
    }

    $tmp_zip = wp_tempnam('crm-inst-doc-' . $instalacion_id);
    $zip = new ZipArchive();
    if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_fallo', 'No se pudo generar el ZIP.');
    }

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
