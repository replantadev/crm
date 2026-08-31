<?php
/**
 * Servicio de geocodificación (Nominatim / OpenStreetMap).
 *
 * Genérico a propósito: no sabe nada de instalaciones ni de clientes, solo
 * convierte una dirección en lat/lng. Quien llama decide cuándo y dónde
 * guardar el resultado (ver `crm_inst_run_geocode()` en instalaciones.php).
 * Reutilizable el día que se geocodifiquen también las visitas.
 *
 * Política de uso de Nominatim (https://operations.osmfoundation.org/policies/nominatim/):
 * - Máximo 1 petición/segundo — aquí solo se llama una vez por dirección
 *   nueva desde un evento de WP-Cron, nunca en bulk.
 * - Hay que identificarse con un User-Agent que permita contactar en caso
 *   de abuso — de ahí el campo de contacto en Ajustes.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CRM_NOMINATIM_ENDPOINT', 'https://nominatim.openstreetmap.org/search');

/**
 * Email de contacto configurado en Ajustes, para el User-Agent de Nominatim.
 */
function crm_geo_get_contact_email() {
    return trim((string) get_option('crm_nominatim_contact_email', ''));
}

/**
 * User-Agent que exige la política de uso de Nominatim.
 */
function crm_geo_build_user_agent() {
    $app   = 'CRM-Energitel/' . (defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '1.0');
    $email = crm_geo_get_contact_email();
    return $email !== '' ? "{$app} ({$email})" : $app;
}

/**
 * Geocodifica una dirección en texto libre.
 *
 * @param string $address
 * @return array{lat: float, lng: float}|WP_Error
 */
function crm_geo_geocode_address($address) {
    $address = trim((string) $address);
    if ($address === '') {
        return new WP_Error('crm_geo_empty_address', 'Dirección vacía.');
    }

    $url = add_query_arg([
        'format' => 'json',
        'q'      => $address,
        'limit'  => 1,
    ], CRM_NOMINATIM_ENDPOINT);

    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'User-Agent' => crm_geo_build_user_agent(),
            'Accept'     => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('crm_geo_http_error', 'Nominatim devolvió HTTP ' . $code);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body[0]['lat']) || empty($body[0]['lon'])) {
        return new WP_Error('crm_geo_not_found', 'No se encontraron coordenadas para esa dirección.');
    }

    return [
        'lat' => (float) $body[0]['lat'],
        'lng' => (float) $body[0]['lon'],
    ];
}
