<?php
/**
 * Cliente de la API de Holded (v2).
 *
 * Wrapper único de peticiones HTTP + funciones concretas por recurso.
 * La clave de API se guarda en el option `crm_holded_api_key` (Ajustes,
 * solo accesible por el administrador web vía wp-admin).
 *
 * Notas de verificación (2026-08-19), probadas contra la cuenta real:
 * - Auth correcta: header `Authorization: Bearer <clave>` sobre
 *   https://api.holded.com/api/v2/... El esquema `key: <clave>` de la API
 *   de facturación v1 devuelve 400 "Invalid key" con esta clave — no usar.
 * - El parámetro documentado `approval_status=approved` en /estimates NO
 *   filtra nada (verificado: mismos resultados con y sin él). La fecha de
 *   aprobación real vive en el campo `approved_at` del detalle de cada
 *   presupuesto (`GET /estimates/{id}`), no en el listado.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CRM_HOLDED_API_BASE', 'https://api.holded.com/api/v2');

/**
 * Clave de API de Holded configurada en Ajustes.
 */
function crm_holded_get_api_key() {
    return trim((string) get_option('crm_holded_api_key', ''));
}

/**
 * true si hay una clave de API configurada.
 */
function crm_holded_is_configured() {
    return crm_holded_get_api_key() !== '';
}

/**
 * Petición genérica a la API de Holded.
 *
 * @param string $method   Verbo HTTP.
 * @param string $endpoint Ruta relativa a CRM_HOLDED_API_BASE (sin barra inicial).
 * @param array  $args     ['query' => array, 'body' => mixed]
 * @return array|WP_Error  Cuerpo JSON decodificado, o WP_Error.
 */
function crm_holded_request($method, $endpoint, array $args = []) {
    $api_key = crm_holded_get_api_key();
    if ($api_key === '') {
        return new WP_Error('crm_holded_no_key', 'No hay clave de API de Holded configurada en Ajustes.');
    }

    $url = CRM_HOLDED_API_BASE . '/' . ltrim($endpoint, '/');
    if (!empty($args['query']) && is_array($args['query'])) {
        $url = add_query_arg($args['query'], $url);
    }

    $response = wp_remote_request($url, [
        'method'  => strtoupper($method),
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ],
        'body' => isset($args['body']) ? wp_json_encode($args['body']) : null,
    ]);

    if (is_wp_error($response)) {
        crm_holded_log_error($endpoint, $response->get_error_message());
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $message = is_array($body) && !empty($body['info']) ? $body['info'] : ('Error HTTP ' . $code);
        crm_holded_log_error($endpoint, $message, $code);
        return new WP_Error('crm_holded_http_error', $message, ['status' => $code]);
    }

    return is_array($body) ? $body : [];
}

/**
 * Registra un fallo de la API de Holded en el log general del plugin.
 */
function crm_holded_log_error($endpoint, $message, $http_code = null) {
    if (function_exists('crm_log_action')) {
        crm_log_action(
            'holded_api_error',
            sprintf('Holded API [%s]: %s', $endpoint, $message),
            null,
            null,
            'error',
            ['endpoint' => $endpoint, 'http_code' => $http_code]
        );
    }
}

/**
 * Una "página" de presupuestos siguiendo el cursor real de Holded.
 *
 * ⚠️ v1.20.55 — CORRECCIÓN: el parámetro `?page=N` que se asumía documentado
 * NO funciona — verificado empíricamente el 2026-08-23 pidiendo `page=1` y
 * `page=2` reales: devuelven byte a byte la misma respuesta. La paginación
 * real de este endpoint es por cursor: la respuesta trae `cursor` (string
 * opaco) y `has_more` (bool); para pedir el siguiente lote hay que mandar
 * ese valor de vuelta como `?cursor=<valor>`, no incrementar un número de
 * página. La nota anterior en memoria ("paginación con page=N confirmada")
 * era incorrecta — probablemente una coincidencia de caché en aquella
 * verificación. Este bug hacía que las búsquedas de presupuestos repro-
 * dujeran el mismo primer lote de 50 una y otra vez (contactos duplicados
 * en los resultados) y nunca llegaran a los presupuestos más antiguos
 * (p.ej. no encontraba "R.O.R. Operador de Transportes", de mayo de 2026).
 *
 * @param string|null $cursor Cursor devuelto por la página anterior, o null para la primera.
 * @return array{items:array,cursor:?string,has_more:bool}|WP_Error
 */
function crm_holded_get_estimates_page($cursor = null) {
    $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
    $cache_key = 'crm_holded_estimates_' . ($cursor ? md5($cursor) : 'first');

    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $query = $cursor ? ['cursor' => $cursor] : [];
    $result = crm_holded_request('GET', 'estimates', ['query' => $query]);
    if (is_wp_error($result)) {
        return $result;
    }

    $page = [
        'items'    => isset($result['items']) && is_array($result['items']) ? $result['items'] : [],
        'cursor'   => isset($result['cursor']) && is_string($result['cursor']) ? $result['cursor'] : null,
        'has_more' => !empty($result['has_more']),
    ];
    set_transient($cache_key, $page, 5 * MINUTE_IN_SECONDS);

    return $page;
}

/**
 * Compatibilidad: primera página de presupuestos como array plano de items
 * (usado por el botón "Probar conexión" de Ajustes, que solo necesita un
 * conteo). El parámetro `$page` se ignora — solo existe por compatibilidad
 * de firma, ver `crm_holded_get_estimates_page()` para paginación real.
 *
 * @param int $page Ignorado.
 * @return array|WP_Error
 */
function crm_holded_get_estimates($page = 1) {
    $result = crm_holded_get_estimates_page(null);
    if (is_wp_error($result)) {
        return $result;
    }
    return $result['items'];
}

/**
 * Detalle de un presupuesto concreto (incluye `approved_at`, `lines`, etc.).
 * Sin caché: se pide justo antes de crear la instalación, para no operar
 * con un dato de aprobación desactualizado.
 *
 * @param string $estimate_id
 * @return array|WP_Error
 */
function crm_holded_get_estimate($estimate_id) {
    $estimate_id = sanitize_text_field((string) $estimate_id);
    if ($estimate_id === '') {
        return new WP_Error('crm_holded_invalid_id', 'ID de presupuesto no válido.');
    }

    return crm_holded_request('GET', 'estimates/' . rawurlencode($estimate_id));
}

/**
 * PDF de un presupuesto (bytes crudos, no JSON — verificado contra la cuenta
 * real: `GET /estimates/{id}/pdf` devuelve `application/pdf` directamente).
 * No reutiliza crm_holded_request() porque esa función siempre intenta
 * decodificar la respuesta como JSON.
 *
 * @param string $estimate_id
 * @return string|WP_Error Contenido binario del PDF.
 */
function crm_holded_get_estimate_pdf($estimate_id) {
    $estimate_id = sanitize_text_field((string) $estimate_id);
    if ($estimate_id === '') {
        return new WP_Error('crm_holded_invalid_id', 'ID de presupuesto no válido.');
    }
    $api_key = crm_holded_get_api_key();
    if ($api_key === '') {
        return new WP_Error('crm_holded_no_key', 'No hay clave de API de Holded configurada en Ajustes.');
    }

    $url = CRM_HOLDED_API_BASE . '/estimates/' . rawurlencode($estimate_id) . '/pdf';
    $response = wp_remote_get($url, [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Accept'        => 'application/pdf',
        ],
    ]);

    if (is_wp_error($response)) {
        crm_holded_log_error('estimates/pdf', $response->get_error_message());
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        crm_holded_log_error('estimates/pdf', 'HTTP ' . $code, $code);
        return new WP_Error('crm_holded_http_error', 'No se pudo descargar el PDF (HTTP ' . $code . ').');
    }

    return wp_remote_retrieve_body($response);
}

/**
 * Detalle de un contacto de Holded (nombre, email, teléfono, dirección de
 * facturación...). Sin caché: se usa solo en el momento de crear la
 * instalación, no en listados.
 *
 * @param string $contact_id
 * @return array|WP_Error
 */
function crm_holded_get_contact($contact_id) {
    $contact_id = sanitize_text_field((string) $contact_id);
    if ($contact_id === '') {
        return new WP_Error('crm_holded_invalid_id', 'ID de contacto no válido.');
    }

    return crm_holded_request('GET', 'contacts/' . rawurlencode($contact_id));
}

/**
 * Busca presupuestos por texto libre (nombre de contacto o nº de documento).
 * Recorre lotes de crm_holded_get_estimates_page() siguiendo el cursor real
 * hasta `$max_pages` o hasta que Holded indique `has_more: false` (fin del
 * listado real) — ver la nota de corrección en `crm_holded_get_estimates_page()`.
 *
 * No comprueba `approved_at` (no está en el listado) — quien llame a esto
 * debe pedir el detalle antes de dejar crear una instalación.
 *
 * @param string $query
 * @param int    $max_pages Límite de lotes a recorrer (50 items/lote aprox.).
 * @return array|WP_Error
 */
function crm_holded_search_estimates($query, $max_pages = 10) {
    $query   = trim((string) $query);
    $matches = [];
    $cursor  = null;

    for ($i = 0; $i < max(1, (int) $max_pages); $i++) {
        $page = crm_holded_get_estimates_page($cursor);
        if (is_wp_error($page)) {
            return $page;
        }
        if (empty($page['items'])) {
            break;
        }

        foreach ($page['items'] as $item) {
            if ($query === '' || crm_holded_estimate_matches_query($item, $query)) {
                $matches[] = $item;
            }
        }

        if (empty($page['has_more']) || empty($page['cursor'])) {
            break; // Última página real del listado de Holded.
        }
        $cursor = $page['cursor'];
    }

    return $matches;
}

/**
 * true si el presupuesto coincide con el texto buscado (contacto o nº doc).
 */
function crm_holded_estimate_matches_query(array $item, $query) {
    $haystack = mb_strtolower(($item['contact_name'] ?? '') . ' ' . ($item['document_number'] ?? ''));
    return mb_strpos($haystack, mb_strtolower($query)) !== false;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 6 — Doble check de facturación (factura al cliente / compra al proveedor)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Detalle de una factura de venta (al cliente). Incluye `status`
 * (pending/partial/completed/cancelled/failed), `payments_pending`,
 * `payments_total` y `from` (documento origen: el presupuesto, si la
 * factura se generó a partir de uno).
 *
 * @param string $invoice_id
 * @return array|WP_Error
 */
function crm_holded_get_invoice($invoice_id) {
    $invoice_id = sanitize_text_field((string) $invoice_id);
    if ($invoice_id === '') {
        return new WP_Error('crm_holded_invalid_id', 'ID de factura no válido.');
    }
    return crm_holded_request('GET', 'invoices/' . rawurlencode($invoice_id));
}

/**
 * Busca, entre las facturas del contacto del cliente, la que se generó a
 * partir de un presupuesto concreto (comparando `from.id` con el
 * `holded_doc_id` guardado al crear la instalación) — así el lado "cliente"
 * del doble check de la Fase 6 se resuelve solo, sin que nadie tenga que
 * vincular nada a mano.
 *
 * @param string $contact_id  holded_contact_id del cliente.
 * @param string $estimate_id El mismo id que crm_instalaciones.holded_doc_id.
 * @param int    $max_pages   Límite de lotes a recorrer (normalmente con 1-2 sobra).
 * @return string|null ID de la factura encontrada, o null si no hay ninguna todavía.
 */
function crm_holded_find_invoice_from_estimate($contact_id, $estimate_id, $max_pages = 5) {
    $contact_id  = sanitize_text_field((string) $contact_id);
    $estimate_id = sanitize_text_field((string) $estimate_id);
    if ($contact_id === '' || $estimate_id === '') {
        return null;
    }

    $cursor = null;
    for ($i = 0; $i < max(1, (int) $max_pages); $i++) {
        $query = ['contact_id' => $contact_id];
        if ($cursor) {
            $query['cursor'] = $cursor;
        }
        $result = crm_holded_request('GET', 'invoices', ['query' => $query]);
        if (is_wp_error($result)) {
            return null; // Best-effort: si falla la búsqueda, no bloquear la ficha.
        }
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        foreach ($items as $item) {
            if (!empty($item['from']['id']) && (string) $item['from']['id'] === $estimate_id) {
                return (string) $item['id'];
            }
        }
        if (empty($result['has_more']) || empty($result['cursor'])) {
            break;
        }
        $cursor = $result['cursor'];
    }
    return null;
}

/**
 * Detalle de una compra/gasto (al proveedor). Mismos campos de pago que
 * crm_holded_get_invoice().
 *
 * @param string $purchase_id
 * @return array|WP_Error
 */
function crm_holded_get_purchase($purchase_id) {
    $purchase_id = sanitize_text_field((string) $purchase_id);
    if ($purchase_id === '') {
        return new WP_Error('crm_holded_invalid_id', 'ID de compra no válido.');
    }
    return crm_holded_request('GET', 'purchases/' . rawurlencode($purchase_id));
}

/**
 * Busca compras/gastos por texto libre (nombre de contacto o nº de
 * documento) — para que el jefe de instalaciones vincule a mano la compra
 * del proveedor que corresponde a esta instalación (no hay forma de
 * enlazarlo automáticamente: una compra en Holded no sabe de qué
 * instalación viene el material, a diferencia de una factura de venta que
 * sí guarda el presupuesto de origen).
 *
 * @param string $query
 * @param int    $max_pages
 * @return array|WP_Error
 */
function crm_holded_search_purchases($query, $max_pages = 5) {
    $query   = trim((string) $query);
    $matches = [];
    $cursor  = null;

    for ($i = 0; $i < max(1, (int) $max_pages); $i++) {
        $q = $cursor ? ['cursor' => $cursor] : [];
        $result = crm_holded_request('GET', 'purchases', ['query' => $q]);
        if (is_wp_error($result)) {
            return $result;
        }
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        foreach ($items as $item) {
            $haystack = mb_strtolower(($item['contact_name'] ?? '') . ' ' . ($item['document_number'] ?? ''));
            if ($query === '' || mb_strpos($haystack, mb_strtolower($query)) !== false) {
                $matches[] = $item;
            }
        }
        if (empty($result['has_more']) || empty($result['cursor'])) {
            break;
        }
        $cursor = $result['cursor'];
        if (count($matches) >= 20) {
            break; // Suficientes resultados para elegir, no hace falta agotar el listado.
        }
    }
    return $matches;
}

// ──────────────────────────────────────────────────────────────────────────────
// Catálogo de productos + stock (v1.20.57 — Fase 3, indicador de stock por línea)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Almacén principal contra el que se comprueba el stock ("Almacén Ecovolt").
 * Configurable en Ajustes por si algún día hay más de uno relevante.
 */
function crm_holded_warehouse_id() {
    $id = trim((string) get_option('crm_holded_warehouse_id', ''));
    return $id !== '' ? $id : '69c975f9f8c5399a6b07ac16'; // Ecovolt Renovables Almacén, confirmado 2026-08-19.
}

/**
 * Catálogo completo de productos de Holded, siguiendo el cursor real (ver
 * `crm_holded_get_estimates_page()`), cacheado 15 minutos. El catálogo es
 * pequeño (unas pocas decenas de productos como mucho), así que no hace
 * falta paginar hasta `$max_pages` de 10 como en presupuestos.
 *
 * @return array|WP_Error
 */
function crm_holded_get_products_cached() {
    $cache_key = 'crm_holded_products_all';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $productos = [];
    $cursor = null;
    for ($i = 0; $i < 5; $i++) {
        $query = $cursor ? ['cursor' => $cursor] : [];
        $result = crm_holded_request('GET', 'products', ['query' => $query]);
        if (is_wp_error($result)) {
            return $result;
        }
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $productos = array_merge($productos, $items);
        if (empty($result['has_more']) || empty($result['cursor'])) {
            break;
        }
        $cursor = $result['cursor'];
    }

    set_transient($cache_key, $productos, 15 * MINUTE_IN_SECONDS);
    return $productos;
}

// ──────────────────────────────────────────────────────────────────────────────
// Contactos (v1.20.67 — resumen y buscador/importador en el Panel)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Catálogo completo de contactos de Holded (clientes, proveedores, leads
 * mezclados — cada uno lleva su propio `type`), cacheado 30 minutos, siguiendo
 * el mismo cursor real que estimates/products (ver crm_holded_get_estimates_page()).
 * Verificado empíricamente 2026-08-23: mismo shape `{items,cursor,has_more}`.
 *
 * @return array|WP_Error
 */
function crm_holded_get_contacts_cached() {
    $cache_key = 'crm_holded_contacts_all';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $contactos = [];
    $cursor = null;
    for ($i = 0; $i < 40; $i++) { // hasta ~2000 contactos
        $query = $cursor ? ['cursor' => $cursor] : [];
        $result = crm_holded_request('GET', 'contacts', ['query' => $query]);
        if (is_wp_error($result)) {
            return $result;
        }
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        $contactos = array_merge($contactos, $items);
        if (empty($result['has_more']) || empty($result['cursor'])) {
            break;
        }
        $cursor = $result['cursor'];
    }

    set_transient($cache_key, $contactos, 30 * MINUTE_IN_SECONDS);
    return $contactos;
}

/**
 * Busca contactos por texto libre (nombre, email o CIF/código).
 *
 * @param string $query
 * @param int    $max_results
 * @return array|WP_Error
 */
function crm_holded_search_contacts($query, $max_results = 50) {
    $contactos = crm_holded_get_contacts_cached();
    if (is_wp_error($contactos)) {
        return $contactos;
    }
    $query = mb_strtolower(trim((string) $query));
    if ($query === '') {
        return array_slice($contactos, 0, $max_results);
    }
    $matches = [];
    foreach ($contactos as $c) {
        $haystack = mb_strtolower(
            ($c['name'] ?? '') . ' ' . ($c['email'] ?? '') . ' ' . ($c['code'] ?? '')
        );
        if (mb_strpos($haystack, $query) !== false) {
            $matches[] = $c;
            if (count($matches) >= $max_results) {
                break;
            }
        }
    }
    return $matches;
}

/**
 * Resumen Holded para la tarjeta del Panel: clientes, presupuestos totales,
 * y cuántos de esos presupuestos ya tienen instalación creada en el CRM.
 *
 * No distingue "aprobados" de los demás — comprobar approved_at exige pedir
 * el detalle de CADA presupuesto (no está en el listado, ver nota en
 * crm_holded_get_estimates_page()), inviable para cientos de presupuestos en
 * una carga de página. "Sin instalación" cuenta TODOS los que no tienen
 * `holded_doc_id` emparejado, aprobados o no.
 *
 * Cacheado 30 minutos — recorre todo el catálogo de contactos y presupuestos.
 *
 * @return array|WP_Error
 */
function crm_holded_get_resumen_stats() {
    $cache_key = 'crm_holded_resumen_stats';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $contactos = crm_holded_get_contacts_cached();
    if (is_wp_error($contactos)) {
        return $contactos;
    }
    $total_clientes = count(array_filter($contactos, function ($c) {
        return ($c['type'] ?? '') === 'client';
    }));

    $estimates = crm_holded_search_estimates('', 40);
    if (is_wp_error($estimates)) {
        return $estimates;
    }
    $total_presupuestos = count($estimates);

    $con_instalacion = 0;
    if (!empty($estimates) && function_exists('crm_inst_table_instalaciones')) {
        global $wpdb;
        $doc_ids = array_map(function ($e) { return (string) $e['id']; }, $estimates);
        $placeholders = implode(',', array_fill(0, count($doc_ids), '%s'));
        $con_instalacion = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . crm_inst_table_instalaciones() . " WHERE holded_doc_id IN ($placeholders)",
            $doc_ids
        ));
    }

    $stats = [
        'total_clientes'      => $total_clientes,
        'total_presupuestos'  => $total_presupuestos,
        'con_instalacion'     => $con_instalacion,
        'sin_instalacion'     => max(0, $total_presupuestos - $con_instalacion),
    ];
    set_transient($cache_key, $stats, 30 * MINUTE_IN_SECONDS);
    return $stats;
}

/**
 * Busca un producto del catálogo por su ID exacto — usado cuando la línea de
 * presupuesto SÍ viene enganchada a un producto real (Holded rellena
 * `product_id` en la línea solo si al presupuestar se elige el producto del
 * desplegable, no si se escribe una descripción libre). Coincidencia 100%
 * fiable, a diferencia de `crm_holded_match_producto_por_nombre()`.
 *
 * @param string $product_id
 * @return array|null
 */
function crm_holded_get_product_by_id($product_id) {
    $product_id = trim((string) $product_id);
    if ($product_id === '') {
        return null;
    }
    $productos = crm_holded_get_products_cached();
    if (is_wp_error($productos) || empty($productos)) {
        return null;
    }
    foreach ($productos as $producto) {
        if (($producto['id'] ?? '') === $product_id) {
            return $producto;
        }
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 4 Entrega 2 (rediseño v1.20.88) — Albarán de salida real (waybill)
// ──────────────────────────────────────────────────────────────────────────────
// Sustituye el ajuste directo de stock (`PUT /products/{id}/stock`, nunca
// probado) por el mecanismo que la gestoría de Ecovolt confirmó como el
// correcto: un albarán de venta (documento `waybill` en la API de Holded)
// contra el cliente final, con las líneas de material retiradas. Verificado
// en vivo el recurso real `/api/v2/waybills` (spec OpenAPI 2026-08-30):
// crear exige `contact_id` + `items[]`; el stock solo se descuenta de verdad
// al **aprobar** el albarán (`POST /waybills/{id}/approve`), no al crearlo
// como borrador — por eso `crm_holded_crear_albaran_salida()` encadena las
// dos llamadas.

/**
 * Catálogo de almacenes de Holded, cacheado 30 min — para que el instalador
 * elija de cuál sale el material al confirmar el checklist (Ecovolt opera
 * con más de uno, confirmado 2026-08-30).
 *
 * @return array|WP_Error Lista de ['id','name','default'].
 */
function crm_holded_get_warehouses_cached() {
    $cache_key = 'crm_holded_warehouses_all';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $result = crm_holded_request('GET', 'warehouses');
    if (is_wp_error($result)) {
        return $result;
    }
    $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
    $almacenes = array_map(function ($w) {
        return [
            'id'      => $w['id'] ?? '',
            'name'    => $w['name'] ?? '',
            'default' => !empty($w['default']),
        ];
    }, $items);

    set_transient($cache_key, $almacenes, 30 * MINUTE_IN_SECONDS);
    return $almacenes;
}

/**
 * Busca proyectos de Holded por nombre o contacto — para vincular a mano,
 * una vez, el proyecto que corresponde a una instalación (sin campo de
 * búsqueda por texto en la API real, se recorre y filtra en cliente, mismo
 * patrón que crm_holded_search_purchases()).
 *
 * @param string $query
 * @param int    $max_pages
 * @return array|WP_Error
 */
function crm_holded_search_projects($query, $max_pages = 5) {
    $query   = trim((string) $query);
    $matches = [];
    $cursor  = null;

    for ($i = 0; $i < max(1, (int) $max_pages); $i++) {
        $q = $cursor ? ['cursor' => $cursor] : [];
        $result = crm_holded_request('GET', 'projects', ['query' => $q]);
        if (is_wp_error($result)) {
            return $result;
        }
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        foreach ($items as $item) {
            $haystack = mb_strtolower(($item['name'] ?? '') . ' ' . ($item['contact_name'] ?? ''));
            if ($query === '' || mb_strpos($haystack, mb_strtolower($query)) !== false) {
                $matches[] = $item;
            }
        }
        if (empty($result['has_more']) || empty($result['cursor'])) {
            break;
        }
        $cursor = $result['cursor'];
        if (count($matches) >= 20) {
            break;
        }
    }
    return $matches;
}

/**
 * Crea el albarán de salida de materiales en Holded y lo aprueba en el acto
 * (el stock solo se mueve de verdad al aprobar, no al crear el borrador).
 * Solo debe llamarse con líneas cuyo `product_id` esté confirmado — nunca a
 * partir de un match por nombre, que es una suposición y no debe poder tocar
 * inventario real.
 *
 * @param string $contact_id   holded_contact_id del cliente final.
 * @param string $warehouse_id Almacén del que sale el material.
 * @param array  $items        [['name'=>, 'product_id'=>, 'units'=>], ...]
 * @param string $project_id   Opcional — holded_project_id de la instalación.
 * @param string $descripcion  Texto libre del documento.
 * @return array|WP_Error ['id','document_number','aprobado','error_aprobacion']
 */
function crm_holded_crear_albaran_salida($contact_id, $warehouse_id, array $items, $project_id = '', $descripcion = '') {
    $contact_id   = trim((string) $contact_id);
    $warehouse_id = trim((string) $warehouse_id);
    $project_id   = trim((string) $project_id);
    if ($contact_id === '' || $warehouse_id === '' || empty($items)) {
        return new WP_Error('crm_holded_waybill_invalid', 'Datos del albarán no válidos (falta contacto, almacén o líneas).');
    }

    $lineas = array_map(function ($it) use ($project_id) {
        return [
            'name'       => (string) ($it['name'] ?? ''),
            'type'       => 'product',
            'product_id' => (string) ($it['product_id'] ?? ''),
            'units'      => (float) ($it['units'] ?? 0),
            'price'      => 0,
            'project_id' => $project_id !== '' ? $project_id : null,
        ];
    }, $items);

    $creado = crm_holded_request('POST', 'waybills', [
        'body' => [
            'contact_id'   => $contact_id,
            'description'  => $descripcion !== '' ? $descripcion : null,
            'date'         => current_time('Y-m-d'),
            'warehouse_id' => $warehouse_id,
            'items'        => $lineas,
        ],
    ]);
    if (is_wp_error($creado)) {
        return $creado;
    }
    $waybill_id = (string) ($creado['id'] ?? '');
    if ($waybill_id === '') {
        return new WP_Error('crm_holded_waybill_no_id', 'Holded no devolvió un ID de albarán.');
    }

    $aprobado         = true;
    $error_aprobacion = null;
    $aprobacion       = crm_holded_aprobar_albaran_salida($waybill_id);
    if (is_wp_error($aprobacion)) {
        $aprobado         = false;
        $error_aprobacion = $aprobacion->get_error_message();
    }

    return [
        'id'               => $waybill_id,
        'document_number'  => $creado['document_number'] ?? '',
        'aprobado'         => $aprobado,
        'error_aprobacion' => $error_aprobacion,
    ];
}

/**
 * Respaldo manual: reintenta aprobar un albarán de salida ya creado (sin
 * volver a crear el documento). Usado cuando la aprobación falló en el
 * primer intento de crm_holded_crear_albaran_salida().
 *
 * @param string $waybill_id
 * @return true|WP_Error
 */
function crm_holded_aprobar_albaran_salida($waybill_id) {
    $waybill_id = trim((string) $waybill_id);
    if ($waybill_id === '') {
        return new WP_Error('crm_holded_waybill_invalid', 'ID de albarán no válido.');
    }
    $resultado = crm_holded_request('POST', 'waybills/' . rawurlencode($waybill_id) . '/approve');
    if (is_wp_error($resultado)) {
        return $resultado;
    }
    delete_transient('crm_holded_products_all');
    return true;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 3 (v1.20.92) — Pedido de compra real en Holded al notificar al proveedor
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Crea un pedido de compra (documento `purchase-order`) en Holded contra el
 * contacto del proveedor — un pedido de compra es solo la intención de
 * comprar, no mueve stock ni dinero (a diferencia del albarán de salida o
 * una factura), así que se crea sin aprobar: el usuario revisa y aprueba a
 * mano en Holded si las líneas son correctas.
 *
 * @param string $contact_id  holded_contact_id del proveedor.
 * @param array  $items       [['name'=>, 'product_id'=>(opcional), 'units'=>], ...]
 * @param string $descripcion Texto libre del documento.
 * @return array|WP_Error ['id','document_number']
 */
function crm_holded_crear_pedido_compra($contact_id, array $items, $descripcion = '') {
    $contact_id = trim((string) $contact_id);
    if ($contact_id === '' || empty($items)) {
        return new WP_Error('crm_holded_po_invalid', 'Datos del pedido de compra no válidos (falta contacto o líneas).');
    }

    $lineas = array_map(function ($it) {
        $linea = [
            'name'  => (string) ($it['name'] ?? ''),
            'type'  => 'product',
            'units' => (float) ($it['units'] ?? 0),
        ];
        if (!empty($it['product_id'])) {
            $linea['product_id'] = (string) $it['product_id'];
        }
        return $linea;
    }, $items);

    $creado = crm_holded_request('POST', 'purchase-orders', [
        'body' => [
            'contact_id'  => $contact_id,
            'description' => $descripcion !== '' ? $descripcion : null,
            'date'        => current_time('Y-m-d'),
            'items'       => $lineas,
        ],
    ]);
    if (is_wp_error($creado)) {
        return $creado;
    }
    $po_id = (string) ($creado['id'] ?? '');
    if ($po_id === '') {
        return new WP_Error('crm_holded_po_no_id', 'Holded no devolvió un ID de pedido de compra.');
    }

    return [
        'id'              => $po_id,
        'document_number' => $creado['document_number'] ?? '',
    ];
}

/**
 * Normaliza texto para comparar nombres de línea vs nombres de producto
 * (minúsculas, sin acentos, sin puntuación).
 */
function crm_holded_normalizar_texto($texto) {
    $texto = mb_strtolower((string) $texto);
    if (function_exists('remove_accents')) {
        $texto = remove_accents($texto);
    }
    $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', trim((string) $texto));
    return $texto;
}

/**
 * Intenta emparejar la descripción de una línea de presupuesto con un
 * producto del catálogo de Holded — por NOMBRE, no por SKU/ID: verificado
 * empíricamente (2026-08-23) que ni las líneas de presupuesto ni los
 * productos del catálogo llevan SKU relleno todavía, así que hoy es la única
 * señal disponible. Es best-effort: si no hay un parecido razonable, no
 * devuelve nada — mejor "sin match" que un falso positivo/negativo de stock.
 *
 * @param string $descripcion
 * @return array{producto:array,confianza:float}|null
 */
function crm_holded_match_producto_por_nombre($descripcion) {
    $productos = crm_holded_get_products_cached();
    if (is_wp_error($productos) || empty($productos)) {
        return null;
    }

    $objetivo = crm_holded_normalizar_texto($descripcion);
    if ($objetivo === '') {
        return null;
    }

    $mejor = null;
    $mejor_pct = 0.0;
    foreach ($productos as $producto) {
        $nombre = crm_holded_normalizar_texto($producto['name'] ?? '');
        if ($nombre === '') {
            continue;
        }
        similar_text($objetivo, $nombre, $pct);
        if (strpos($nombre, $objetivo) !== false || strpos($objetivo, $nombre) !== false) {
            $pct = max($pct, 70.0);
        }
        if ($pct > $mejor_pct) {
            $mejor_pct = $pct;
            $mejor = $producto;
        }
    }

    if ($mejor && $mejor_pct >= 55.0) {
        return ['producto' => $mejor, 'confianza' => $mejor_pct];
    }
    return null;
}

/**
 * Stock de un producto en un almacén concreto — mira `stocks[]` (desglose
 * por almacén) y cae al campo agregado `stock` si no encuentra ese almacén.
 *
 * @param array  $producto
 * @param string $warehouse_id
 * @return float|null null si el producto no gestiona stock (`has_stock` falso).
 */
function crm_holded_producto_stock_en_almacen(array $producto, $warehouse_id) {
    if (empty($producto['has_stock'])) {
        return null;
    }
    if (!empty($producto['stocks']) && is_array($producto['stocks'])) {
        foreach ($producto['stocks'] as $fila) {
            if (($fila['warehouse_id'] ?? '') === $warehouse_id) {
                return (float) str_replace(',', '.', (string) $fila['stock']);
            }
        }
    }
    return isset($producto['stock']) ? (float) str_replace(',', '.', (string) $producto['stock']) : null;
}
