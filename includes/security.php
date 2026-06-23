<?php
/**
 * Helpers de seguridad y validación del CRM.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Devuelve la lista canónica de sectores válidos.
 *
 * @return string[]
 */
function crm_get_valid_sectores() {
    return ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
}

/**
 * Comprueba si un sector pertenece a la whitelist.
 */
function crm_is_valid_sector($sector) {
    return is_string($sector) && in_array($sector, crm_get_valid_sectores(), true);
}

/**
 * Sanitiza una lista de sectores aceptando solo los válidos.
 *
 * @param mixed $items
 * @return string[]
 */
function crm_sanitize_sectores_list($items) {
    if (!is_array($items)) {
        return [];
    }
    $items = array_map('sanitize_key', $items);
    $valid = crm_get_valid_sectores();
    return array_values(array_intersect($items, $valid));
}

/**
 * Sanitiza un map cuyas claves son sectores: filtra claves no válidas
 * y aplica una función de saneamiento al valor.
 *
 * @param mixed    $map
 * @param callable $value_sanitizer
 * @return array
 */
function crm_sanitize_sector_map($map, callable $value_sanitizer = null) {
    if (!is_array($map)) {
        return [];
    }
    $valid = crm_get_valid_sectores();
    $out = [];
    foreach ($map as $key => $value) {
        $key = sanitize_key((string) $key);
        if (!in_array($key, $valid, true)) {
            continue;
        }
        $out[$key] = $value_sanitizer ? call_user_func($value_sanitizer, $value) : $value;
    }
    return $out;
}

/**
 * Comprueba si una URL apunta dentro del directorio de uploads del sitio.
 */
function crm_is_uploads_url($url) {
    if (!is_string($url) || $url === '') {
        return false;
    }
    $upload = wp_get_upload_dir();
    if (empty($upload['baseurl'])) {
        return false;
    }
    // Compatibilidad http/https
    $base   = preg_replace('#^https?://#i', '//', $upload['baseurl']);
    $target = preg_replace('#^https?://#i', '//', $url);
    return strpos($target, $base) === 0 && strpos($target, '..') === false;
}

/**
 * Filtra una lista de URLs dejando solo las que pertenecen a uploads.
 */
function crm_filter_uploads_urls($urls) {
    if (!is_array($urls)) {
        return [];
    }
    $out = [];
    foreach ($urls as $url) {
        $url = is_string($url) ? esc_url_raw(trim($url)) : '';
        if ($url !== '' && crm_is_uploads_url($url)) {
            $out[] = $url;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Deserializa de forma segura un valor que puede venir como array,
 * JSON o cadena serializada PHP. Bloquea PHP Object Injection
 * impidiendo la creación de instancias de clases.
 *
 * @param mixed $value
 * @return array
 */
function crm_safe_unserialize_array($value) {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return [];
    }
    $trim = ltrim($value);
    // Intentar JSON primero
    if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    if (function_exists('is_serialized') && is_serialized($value)) {
        $unser = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($unser)) {
            return $unser;
        }
    }
    return [];
}

/**
 * Logger interno: solo escribe si WP_DEBUG y WP_DEBUG_LOG están activos.
 * Impide fugas de PII en producción.
 */
function crm_debug_log($message) {
    if (!(defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
        return;
    }
    if (is_array($message) || is_object($message)) {
        $message = wp_json_encode($message);
    }
    error_log('[CRM] ' . $message);
}

/**
 * Devuelve un array con copia de $_POST sin claves que contengan PII obvia,
 * para poder loguearlo si hace falta sin exponer datos personales.
 */
function crm_redact_post_for_log(array $post) {
    $sensitive = [
        'cliente_nombre', 'empresa', 'direccion', 'telefono', 'email_cliente',
        'poblacion', 'comentarios', 'crm_nonce', 'nonce', 'email_comercial',
    ];
    foreach ($sensitive as $key) {
        if (isset($post[$key])) {
            $post[$key] = '[REDACTED]';
        }
    }
    return $post;
}

/**
 * Lista canónica de tipos de archivo permitidos para subidas del CRM.
 *
 * Incluye los formatos que un iPad/iPhone produce por defecto al usar la
 * cámara desde Safari (HEIC/HEIF) y WebP, además de los clásicos JPEG/PNG
 * para escaneos y PDF para presupuestos/contratos.
 *
 * @return array<string,string> mime => extensión
 */
function crm_get_allowed_upload_types() {
    return [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
        'heic'         => 'image/heic',
        'heif'         => 'image/heif',
        'pdf'          => 'application/pdf',
    ];
}

/**
 * Devuelve la ruta del directorio de backups del CRM.
 */
function crm_get_backup_dir() {
    $dir = trailingslashit(WP_CONTENT_DIR) . 'crm-backups';
    return apply_filters('crm_backup_dir', $dir);
}

/**
 * Crea el directorio de backups y lo blinda contra acceso público.
 * Se llama desde activación y cada vez que se va a generar un backup.
 */
function crm_protect_backup_directory() {
    $dir = crm_get_backup_dir();
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    $index = trailingslashit($dir) . 'index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n");
    }

    $htaccess = trailingslashit($dir) . '.htaccess';
    if (!file_exists($htaccess)) {
        $rules  = "Order deny,allow\n";
        $rules .= "Deny from all\n";
        $rules .= "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n";
        @file_put_contents($htaccess, $rules);
    }

    $webconfig = trailingslashit($dir) . 'web.config';
    if (!file_exists($webconfig)) {
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<configuration>\n  <system.webServer>\n    <authorization>\n";
        $xml .= "      <deny users=\"*\" />\n";
        $xml .= "    </authorization>\n  </system.webServer>\n</configuration>\n";
        @file_put_contents($webconfig, $xml);
    }

    return true;
}
