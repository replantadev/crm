<?php
/**
 * Manejo seguro de subidas y borrados de archivos del CRM.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tamaño máximo de subida (10 MB).
 */
function crm_uploads_max_size() {
    return 10 * 1024 * 1024;
}

/**
 * Valida y sube un archivo recibido por AJAX.
 * Devuelve un array con `url` y `name` o un WP_Error.
 *
 * @param array  $file Estructura $_FILES['file'].
 * @param string $tipo factura|presupuesto|contrato_firmado.
 * @return array|WP_Error
 */
function crm_handle_secure_upload(array $file, $tipo) {
    if (empty($file) || !isset($file['error'])) {
        return new WP_Error('crm_upload_invalid', __('Petición de subida inválida.', 'crm-basico'));
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('crm_upload_php_error', sprintf(
            /* translators: %d: PHP UPLOAD_ERR_* code */
            __('Error de subida del archivo (código %d).', 'crm-basico'),
            (int) $file['error']
        ));
    }
    if (!isset($file['size']) || (int) $file['size'] <= 0) {
        return new WP_Error('crm_upload_empty', __('El archivo está vacío.', 'crm-basico'));
    }
    if ((int) $file['size'] > crm_uploads_max_size()) {
        return new WP_Error('crm_upload_too_big', __('El archivo excede el tamaño permitido de 10 MB.', 'crm-basico'));
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return new WP_Error('crm_upload_not_uploaded', __('Archivo no válido.', 'crm-basico'));
    }

    // Validar extensión y MIME real (no se confía en $file['type'])
    $allowed   = crm_get_allowed_upload_types();
    $filename  = sanitize_file_name($file['name'] ?? '');
    $checked   = wp_check_filetype_and_ext($file['tmp_name'], $filename, $allowed);

    if (empty($checked['ext']) || empty($checked['type']) || !in_array($checked['type'], $allowed, true)) {
        return new WP_Error('crm_upload_bad_type', __('Tipo de archivo no permitido. Solo JPEG, PNG y PDF.', 'crm-basico'));
    }

    // Reescribir el nombre saneado y la extensión real detectada para evitar
    // double-extensions tipo "documento.php.jpg".
    $safe_name = wp_unique_filename(
        wp_get_upload_dir()['path'],
        preg_replace('/\.[^.]+$/', '', $filename) . '.' . $checked['ext']
    );

    $overrides = [
        'test_form' => false,
        'mimes'     => $allowed,
        'unique_filename_callback' => static function () use ($safe_name) {
            return $safe_name;
        },
    ];

    // Forzar el nombre saneado en $_FILES temporal para que wp_handle_upload lo respete
    $local = $file;
    $local['name'] = $safe_name;

    $up = wp_handle_upload($local, $overrides);
    if (!empty($up['error'])) {
        return new WP_Error('crm_upload_failed', $up['error']);
    }
    if (empty($up['url'])) {
        return new WP_Error('crm_upload_failed', __('No se pudo procesar la subida.', 'crm-basico'));
    }

    return [
        'url'  => esc_url_raw($up['url']),
        'name' => sanitize_text_field(basename($up['file'])),
        'tipo' => $tipo,
    ];
}

/**
 * Borra de forma segura un archivo subido a uploads.
 * Solo permite borrar dentro del directorio de uploads del sitio.
 *
 * @param string $url
 * @return bool
 */
function crm_delete_uploaded_file_by_url($url) {
    if (!is_string($url) || $url === '') {
        return false;
    }
    if (!crm_is_uploads_url($url)) {
        return false;
    }
    $upload   = wp_get_upload_dir();
    $relative = ltrim(str_replace($upload['baseurl'], '', $url), '/\\');
    if ($relative === '' || strpos($relative, '..') !== false) {
        return false;
    }
    $path = trailingslashit($upload['basedir']) . $relative;
    $real = realpath($path);
    $base = realpath($upload['basedir']);
    if (!$real || !$base || strpos($real, $base) !== 0) {
        return false;
    }
    if (!file_exists($real)) {
        return false;
    }
    return @unlink($real);
}
