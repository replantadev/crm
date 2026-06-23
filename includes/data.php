<?php
/**
 * CRM Energitel - Datos territoriales canónicos (provincias).
 *
 * Mantiene una única fuente de verdad PHP de las 52 provincias españolas
 * con su código INE y nombre oficial, y un mapa de equivalencias para
 * normalizar valores legacy (los formularios antiguos guardaban
 * "Vizcaya", "La Coruña", etc. que no coinciden con la nomenclatura
 * oficial usada por el INE y por el bundle `assets/data/municipios-es.json`).
 *
 * @package CRM_Energitel
 * @since   1.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lista oficial de las 52 provincias.
 *
 * @return array<int,array{code:string,name:string}>
 */
function crm_get_provincias_oficiales() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [
        ['code' => '01', 'name' => 'Álava'],
        ['code' => '02', 'name' => 'Albacete'],
        ['code' => '03', 'name' => 'Alicante'],
        ['code' => '04', 'name' => 'Almería'],
        ['code' => '05', 'name' => 'Ávila'],
        ['code' => '06', 'name' => 'Badajoz'],
        ['code' => '07', 'name' => 'Illes Balears'],
        ['code' => '08', 'name' => 'Barcelona'],
        ['code' => '09', 'name' => 'Burgos'],
        ['code' => '10', 'name' => 'Cáceres'],
        ['code' => '11', 'name' => 'Cádiz'],
        ['code' => '12', 'name' => 'Castellón'],
        ['code' => '13', 'name' => 'Ciudad Real'],
        ['code' => '14', 'name' => 'Córdoba'],
        ['code' => '15', 'name' => 'A Coruña'],
        ['code' => '16', 'name' => 'Cuenca'],
        ['code' => '17', 'name' => 'Girona'],
        ['code' => '18', 'name' => 'Granada'],
        ['code' => '19', 'name' => 'Guadalajara'],
        ['code' => '20', 'name' => 'Gipuzkoa'],
        ['code' => '21', 'name' => 'Huelva'],
        ['code' => '22', 'name' => 'Huesca'],
        ['code' => '23', 'name' => 'Jaén'],
        ['code' => '24', 'name' => 'León'],
        ['code' => '25', 'name' => 'Lleida'],
        ['code' => '26', 'name' => 'La Rioja'],
        ['code' => '27', 'name' => 'Lugo'],
        ['code' => '28', 'name' => 'Madrid'],
        ['code' => '29', 'name' => 'Málaga'],
        ['code' => '30', 'name' => 'Murcia'],
        ['code' => '31', 'name' => 'Navarra'],
        ['code' => '32', 'name' => 'Ourense'],
        ['code' => '33', 'name' => 'Asturias'],
        ['code' => '34', 'name' => 'Palencia'],
        ['code' => '35', 'name' => 'Las Palmas'],
        ['code' => '36', 'name' => 'Pontevedra'],
        ['code' => '37', 'name' => 'Salamanca'],
        ['code' => '38', 'name' => 'Santa Cruz de Tenerife'],
        ['code' => '39', 'name' => 'Cantabria'],
        ['code' => '40', 'name' => 'Segovia'],
        ['code' => '41', 'name' => 'Sevilla'],
        ['code' => '42', 'name' => 'Soria'],
        ['code' => '43', 'name' => 'Tarragona'],
        ['code' => '44', 'name' => 'Teruel'],
        ['code' => '45', 'name' => 'Toledo'],
        ['code' => '46', 'name' => 'Valencia'],
        ['code' => '47', 'name' => 'Valladolid'],
        ['code' => '48', 'name' => 'Bizkaia'],
        ['code' => '49', 'name' => 'Zamora'],
        ['code' => '50', 'name' => 'Zaragoza'],
        ['code' => '51', 'name' => 'Ceuta'],
        ['code' => '52', 'name' => 'Melilla'],
    ];
    return $cache;
}

/**
 * Mapa de equivalencias (nombre legacy o popular -> nombre oficial).
 *
 * @return array<string,string>
 */
function crm_get_provincias_aliases() {
    return [
        // Valores antiguos del select que ya están en BD.
        'islas baleares'  => 'Illes Balears',
        'guipuzcoa'       => 'Gipuzkoa',
        'guipúzcoa'       => 'Gipuzkoa',
        'vizcaya'         => 'Bizkaia',
        'la coruña'       => 'A Coruña',
        'la coruna'       => 'A Coruña',
        'coruña'          => 'A Coruña',
        'orense'          => 'Ourense',
        // Variantes habituales.
        'baleares'        => 'Illes Balears',
        'gipuzcoa'        => 'Gipuzkoa',
        'bizcaia'         => 'Bizkaia',
    ];
}

/**
 * Normaliza un nombre de provincia al oficial INE.
 *
 * Devuelve el nombre oficial si encuentra coincidencia (insensible a
 * mayúsculas y acentos); en caso contrario devuelve el input sin tocar
 * para no perder datos preexistentes.
 *
 * @param string $name
 * @return string
 */
function crm_normalize_provincia($name) {
    if (!is_string($name) || $name === '') {
        return '';
    }

    $name_trim   = trim($name);
    $name_lower  = function_exists('mb_strtolower')
        ? mb_strtolower($name_trim, 'UTF-8')
        : strtolower($name_trim);

    // 1) Match directo contra alias.
    $aliases = crm_get_provincias_aliases();
    if (isset($aliases[$name_lower])) {
        return $aliases[$name_lower];
    }

    // 2) Match insensible a acentos contra lista oficial.
    $strip = function ($s) {
        $s = is_string($s) ? $s : '';
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{M}+/u', '', $s);
        }
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    };
    $target = $strip($name_trim);
    foreach (crm_get_provincias_oficiales() as $p) {
        if ($strip($p['name']) === $target) {
            return $p['name'];
        }
    }

    return $name_trim;
}

/**
 * Comprueba si un valor coincide (tras normalizar) con una provincia oficial.
 *
 * @param string $name
 * @return bool
 */
function crm_es_provincia_oficial($name) {
    $norm = crm_normalize_provincia($name);
    foreach (crm_get_provincias_oficiales() as $p) {
        if ($p['name'] === $norm) {
            return true;
        }
    }
    return false;
}

/* ============================================================
 * Catálogo: estimado de consumo por sector
 * ============================================================ */

/**
 * Devuelve, por sector, los rangos predefinidos y las unidades válidas
 * para que el comercial pueda capturar el consumo del cliente como:
 *   - Un rango (select)
 *   - Y/o un valor exacto (número + unidad)
 *
 * Estructura:
 *   [
 *     'energia' => [
 *       'rangos'   => [['id' => '...', 'label' => '...'], ...],
 *       'unidades' => [['id' => '...', 'label' => '...'], ...],
 *       'placeholder_valor' => 'Ej: 4500',
 *     ],
 *     ...
 *   ]
 *
 * @return array<string,array{rangos:array,unidades:array,placeholder_valor:string}>
 */
function crm_get_estimado_opciones() {
    $no_se = ['id' => 'no_sabe', 'label' => 'No lo sé / pendiente'];

    return [
        'energia' => [
            'rangos' => [
                ['id' => 'kwh_lt_1000',    'label' => 'Menos de 1.000 kWh/año'],
                ['id' => 'kwh_1000_3000',  'label' => '1.000 – 3.000 kWh/año'],
                ['id' => 'kwh_3000_6000',  'label' => '3.000 – 6.000 kWh/año'],
                ['id' => 'kwh_6000_12000', 'label' => '6.000 – 12.000 kWh/año'],
                ['id' => 'kwh_gt_12000',   'label' => 'Más de 12.000 kWh/año'],
                ['id' => 'eur_50_100',     'label' => '50 – 100 €/mes'],
                ['id' => 'eur_100_200',    'label' => '100 – 200 €/mes'],
                ['id' => 'eur_200_500',    'label' => '200 – 500 €/mes'],
                ['id' => 'eur_gt_500',     'label' => 'Más de 500 €/mes'],
                $no_se,
            ],
            'unidades' => [
                ['id' => 'kwh_mes', 'label' => 'kWh/mes'],
                ['id' => 'kwh_ano', 'label' => 'kWh/año'],
                ['id' => 'eur_mes', 'label' => '€/mes'],
                ['id' => 'eur_ano', 'label' => '€/año'],
            ],
            'placeholder_valor' => 'Ej: 4500',
        ],
        'alarmas' => [
            'rangos' => [
                ['id' => 'eur_lt_30',  'label' => 'Menos de 30 €/mes'],
                ['id' => 'eur_30_50',  'label' => '30 – 50 €/mes'],
                ['id' => 'eur_50_100', 'label' => '50 – 100 €/mes'],
                ['id' => 'eur_gt_100', 'label' => 'Más de 100 €/mes'],
                $no_se,
            ],
            'unidades' => [
                ['id' => 'eur_mes', 'label' => '€/mes'],
                ['id' => 'eur_ano', 'label' => '€/año'],
            ],
            'placeholder_valor' => 'Ej: 45',
        ],
        'telecomunicaciones' => [
            'rangos' => [
                ['id' => 'eur_lt_40',  'label' => 'Menos de 40 €/mes'],
                ['id' => 'eur_40_70',  'label' => '40 – 70 €/mes'],
                ['id' => 'eur_70_100', 'label' => '70 – 100 €/mes'],
                ['id' => 'eur_gt_100', 'label' => 'Más de 100 €/mes'],
                $no_se,
            ],
            'unidades' => [
                ['id' => 'eur_mes', 'label' => '€/mes'],
                ['id' => 'eur_ano', 'label' => '€/año'],
            ],
            'placeholder_valor' => 'Ej: 65',
        ],
        'seguros' => [
            'rangos' => [
                ['id' => 'eur_lt_300',    'label' => 'Menos de 300 €/año'],
                ['id' => 'eur_300_600',   'label' => '300 – 600 €/año'],
                ['id' => 'eur_600_1500',  'label' => '600 – 1.500 €/año'],
                ['id' => 'eur_gt_1500',   'label' => 'Más de 1.500 €/año'],
                $no_se,
            ],
            'unidades' => [
                ['id' => 'eur_ano', 'label' => '€/año'],
                ['id' => 'eur_mes', 'label' => '€/mes'],
            ],
            'placeholder_valor' => 'Ej: 480',
        ],
        'renovables' => [
            'rangos' => [
                ['id' => 'kwp_lt_3',  'label' => 'Hasta 3 kWp'],
                ['id' => 'kwp_3_5',   'label' => '3 – 5 kWp'],
                ['id' => 'kwp_5_10',  'label' => '5 – 10 kWp'],
                ['id' => 'kwp_gt_10', 'label' => 'Más de 10 kWp'],
                $no_se,
            ],
            'unidades' => [
                ['id' => 'kwp',     'label' => 'kWp (potencia)'],
                ['id' => 'paneles', 'label' => 'nº paneles'],
            ],
            'placeholder_valor' => 'Ej: 4.5',
        ],
    ];
}

/**
 * Sanea y valida el estimado de consumo enviado por POST para un sector.
 *
 * Devuelve un array con las claves rellenas o `null` si el comercial
 * no aportó información útil para ese sector.
 *
 * @param string $sector
 * @param array  $raw    Datos crudos para este sector (rango, valor, unidad).
 * @return array{rango:?string,valor:?float,unidad:?string}|null
 */
function crm_sanitize_estimado_sector($sector, $raw) {
    $opciones = crm_get_estimado_opciones();
    if (!isset($opciones[$sector]) || !is_array($raw)) {
        return null;
    }

    $rango_in  = isset($raw['rango'])  ? sanitize_text_field((string) $raw['rango'])  : '';
    $valor_in  = isset($raw['valor'])  ? trim((string) $raw['valor'])                  : '';
    $unidad_in = isset($raw['unidad']) ? sanitize_text_field((string) $raw['unidad']) : '';

    // Validar rango contra el catálogo.
    $rango = null;
    if ($rango_in !== '') {
        foreach ($opciones[$sector]['rangos'] as $r) {
            if ($r['id'] === $rango_in) {
                $rango = $rango_in;
                break;
            }
        }
    }

    // Valor numérico (admite coma decimal del usuario español).
    $valor = null;
    if ($valor_in !== '') {
        $valor_norm = str_replace(',', '.', $valor_in);
        if (is_numeric($valor_norm)) {
            $f = (float) $valor_norm;
            if ($f >= 0 && $f < 1000000) {
                $valor = $f;
            }
        }
    }

    // Unidad contra catálogo del sector.
    $unidad = null;
    if ($unidad_in !== '') {
        foreach ($opciones[$sector]['unidades'] as $u) {
            if ($u['id'] === $unidad_in) {
                $unidad = $unidad_in;
                break;
            }
        }
    }

    // Si no hay nada útil, no persistimos ruido en la BD.
    if ($rango === null && $valor === null) {
        return null;
    }

    return [
        'rango'  => $rango,
        'valor'  => $valor,
        'unidad' => $unidad,
    ];
}

/**
 * Devuelve el label legible de un rango/valor+unidad para mostrar al usuario.
 *
 * @param string $sector
 * @param array  $estimado Estructura validada (rango, valor, unidad).
 * @return string
 */
function crm_format_estimado($sector, $estimado) {
    if (!is_array($estimado)) {
        return '';
    }
    $opciones = crm_get_estimado_opciones();
    if (!isset($opciones[$sector])) {
        return '';
    }

    $partes = [];
    if (!empty($estimado['rango'])) {
        foreach ($opciones[$sector]['rangos'] as $r) {
            if ($r['id'] === $estimado['rango']) {
                $partes[] = $r['label'];
                break;
            }
        }
    }
    if (isset($estimado['valor']) && $estimado['valor'] !== null && !empty($estimado['unidad'])) {
        $unidad_label = $estimado['unidad'];
        foreach ($opciones[$sector]['unidades'] as $u) {
            if ($u['id'] === $estimado['unidad']) {
                $unidad_label = $u['label'];
                break;
            }
        }
        $valor_fmt = rtrim(rtrim(number_format((float) $estimado['valor'], 2, ',', '.'), '0'), ',');
        $partes[]  = $valor_fmt . ' ' . $unidad_label;
    }
    return implode(' · ', $partes);
}
