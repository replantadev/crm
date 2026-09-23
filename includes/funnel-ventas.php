<?php
/**
 * Funnel de ventas — [crm_funnel_ventas], reunión con cliente 2026-09-22
 * punto 6. Propuesta acordada con el usuario:
 *  - Cuenta TODOS los clientes (no solo leads de marketing), con el origen
 *    como filtro más — para poder comparar canales entre sí.
 *  - Se ve por sector, con selector (cada sector tiene su propio ritmo de
 *    venta — mezclarlos daría un número sin sentido).
 *  - Vive en el frontend (crm_admin trabaja desde ahí, no wp-admin).
 *
 * No añade ninguna tabla ni estado nuevo — es un informe de solo lectura
 * sobre datos que ya existen: el lifecycle de leads (lead_mk_status/user_id,
 * includes/leads-mk-shortcode.php) para las 3 primeras fases, y el pipeline
 * por sector ya establecido (crm_get_orden_estados(), crm-plugin.php) para
 * el resto. Cada fase cuenta "llegó AL MENOS hasta aquí" (convención
 * estándar de funnel: las barras nunca pueden crecer de una fase a la
 * siguiente), no "está ahora mismo exactamente aquí".
 *
 * Simplificación consciente: "cancelado" es un estado GLOBAL del cliente
 * (crm_calcula_estado_global()/forzado a mano), no existe un "cancelado"
 * por sector en el modelo de datos actual — el conteo de perdidos de un
 * sector es "de los interesados en este sector, cuántos están
 * globalmente cancelados", una aproximación razonable pero no perfecta si
 * un cliente cancela por un motivo de OTRO sector distinto al filtrado.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sectores con etiqueta legible — mismo listado que ya usa
 * includes/leads-mk-shortcode.php, repetido aquí porque el proyecto no
 * centraliza esto en un solo sitio (no lo invento de nuevo: mismos slugs
 * que crm_get_valid_sectores()).
 *
 * @return array<string,string>
 */
function crm_funnel_sectores_label() {
    return [
        'energia'            => 'Energía',
        'alarmas'            => 'Alarmas',
        'telecomunicaciones' => 'Telecomunicaciones',
        'seguros'            => 'Seguros',
        'renovables'         => 'Renovables',
    ];
}

/**
 * Orígenes de lead con etiqueta — mismo listado que crm-plugin.php
 * (crm_handle_ajax_request()) + 'todos' para el filtro.
 *
 * @return array<string,string>
 */
function crm_funnel_origenes_label() {
    return [
        'todos'          => 'Todos los orígenes',
        'directo'        => 'Directo (alta manual)',
        'lead_mk'        => 'Lead Marketing (Meta/Google)',
        'placassolares'  => 'Lead comprado (placassolares.es)',
        'contacto_frio'  => 'Contacto frío',
        'referido'       => 'Referido / recomendación',
        'web'            => 'Web / formulario',
    ];
}

/**
 * Calcula el funnel para un sector + filtros dados.
 *
 * @param array $filtros {
 *     @type string $sector       Slug de sector (obligatorio, ver crm_get_valid_sectores()).
 *     @type string $origen       'todos' o un origen_lead concreto.
 *     @type int    $comercial_id 0 = todos.
 *     @type string $fecha_desde  'Y-m-d' o ''.
 *     @type string $fecha_hasta  'Y-m-d' o ''.
 * }
 * @return array{fases:array<int,array{clave:string,label:string,total:int,pct:float}>, cancelados:int, total_pool:int}
 */
function crm_funnel_calcular(array $filtros) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';

    $sector       = sanitize_key($filtros['sector'] ?? '');
    $origen       = sanitize_key($filtros['origen'] ?? 'todos');
    $comercial_id = (int) ($filtros['comercial_id'] ?? 0);
    $fecha_desde  = sanitize_text_field($filtros['fecha_desde'] ?? '');
    $fecha_hasta  = sanitize_text_field($filtros['fecha_hasta'] ?? '');

    if (!in_array($sector, crm_get_valid_sectores(), true)) {
        return new WP_Error('crm_funnel_sector_invalido', 'Sector no válido.');
    }

    $where = ['1=1'];
    $args  = [];
    if ($origen !== 'todos' && isset(crm_funnel_origenes_label()[$origen])) {
        $where[] = 'origen_lead = %s';
        $args[]  = $origen;
    }
    if ($comercial_id > 0) {
        $where[] = 'user_id = %d';
        $args[]  = $comercial_id;
    }
    if ($fecha_desde !== '') {
        $where[] = 'fecha >= %s';
        $args[]  = $fecha_desde . ' 00:00:00';
    }
    if ($fecha_hasta !== '') {
        $where[] = 'fecha <= %s';
        $args[]  = $fecha_hasta . ' 23:59:59';
    }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT id, origen_lead, user_id, lead_mk_status, intereses, estado_por_sector, estado
            FROM $table WHERE $where_sql";
    $rows = empty($args)
        ? $wpdb->get_results($sql, ARRAY_A)
        : $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

    $total_pool     = count($rows);
    $asignados      = 0;
    $trabajados     = 0;
    $interesados    = 0; // Entraron en la etapa "por sector" (intereses contiene $sector).
    $cancelados     = 0;
    $orden_estados  = crm_get_orden_estados(); // borrador..contratos_firmados
    $conteo_por_estado = array_fill_keys($orden_estados, 0);

    // Orígenes cuyo lifecycle de "asignado/trabajado" tiene sentido de
    // verdad (los que pasan por la cola de leads) — el resto (alta directa
    // de un comercial, referido, web) nace ya "asignado y trabajado", no
    // tiene sentido contarlos como pendientes de ese paso.
    $origenes_con_cola = ['lead_mk', 'placassolares'];

    foreach ($rows as $r) {
        $es_origen_cola = in_array($r['origen_lead'], $origenes_con_cola, true);
        $tiene_comercial = (int) ($r['user_id'] ?? 0) > 0;

        if (!$es_origen_cola || $tiene_comercial) {
            $asignados++;
        }
        $lead_mk_trabajado = sanitize_key((string) ($r['lead_mk_status'] ?? '')) === 'trabajado';
        if (!$es_origen_cola || $lead_mk_trabajado) {
            $trabajados++;
        }

        $intereses = maybe_unserialize($r['intereses'] ?? '');
        $intereses = is_array($intereses) ? $intereses : [];
        if (!in_array($sector, $intereses, true)) {
            continue; // No llegó a la etapa de este sector — no cuenta en las fases de pipeline.
        }
        $interesados++;

        if ($r['estado'] === 'cancelado') {
            $cancelados++;
            continue; // Un cancelado no "llegó" a ningún estado de pipeline por sector.
        }

        $eps = maybe_unserialize($r['estado_por_sector'] ?? '');
        $eps = is_array($eps) ? $eps : [];
        $estado_sector = sanitize_key((string) ($eps[$sector] ?? 'borrador'));
        $idx = array_search($estado_sector, $orden_estados, true);
        if ($idx === false) {
            $idx = 0; // Estado desconocido/legacy → tratar como el primer escalón.
        }
        // "Llegó al menos hasta aquí": suma 1 a este y a todos los anteriores.
        for ($i = 0; $i <= $idx; $i++) {
            $conteo_por_estado[$orden_estados[$i]]++;
        }
    }

    $labels_estado = crm_get_estados_sector();
    $fases = [
        ['clave' => 'captados',  'label' => 'Leads captados',  'total' => $total_pool],
        ['clave' => 'asignados', 'label' => 'Asignados',       'total' => $asignados],
        ['clave' => 'trabajados','label' => 'Trabajados',      'total' => $trabajados],
        ['clave' => 'interesados','label' => 'Interesados en ' . (crm_funnel_sectores_label()[$sector] ?? $sector), 'total' => $interesados],
    ];
    foreach ($orden_estados as $estado_key) {
        if ($estado_key === 'borrador') {
            continue; // Ya cubierto por "Interesados" (borrador = recién entrado al sector).
        }
        $fases[] = [
            'clave' => $estado_key,
            'label' => $labels_estado[$estado_key]['label'] ?? $estado_key,
            'total' => $conteo_por_estado[$estado_key],
        ];
    }
    // La última fase (contratos_firmados) es "Cliente convertido" en este sector.
    $fases[count($fases) - 1]['label'] = 'Cliente convertido';

    $base = $total_pool > 0 ? $total_pool : 1;
    foreach ($fases as &$f) {
        $f['pct'] = round(($f['total'] / $base) * 100, 1);
    }
    unset($f);

    return [
        'fases'      => $fases,
        'cancelados' => $cancelados,
        'total_pool' => $total_pool,
    ];
}

/**
 * [crm_funnel_ventas] — pantalla de admin (frontend) con el funnel.
 */
add_shortcode('crm_funnel_ventas', 'crm_shortcode_funnel_ventas');
function crm_shortcode_funnel_ventas() {
    if (!current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }

    $sectores  = crm_funnel_sectores_label();
    $origenes  = crm_funnel_origenes_label();
    $comerciales = get_users(['role' => 'comercial', 'orderby' => 'display_name', 'order' => 'ASC']);
    $nonce = wp_create_nonce('crm_funnel_ventas');

    ob_start();
    ?>
    <style>
    .crm-funnel-wrap { display: flex; flex-direction: column; gap: 18px; }
    .crm-funnel-filtros { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; }
    .crm-funnel-filtros label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 3px; }
    .crm-funnel-filtros select, .crm-funnel-filtros input { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; }
    .crm-funnel-resultado { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; }
    .crm-funnel-fase { display: flex; align-items: center; gap: 12px; margin: 6px 0; }
    .crm-funnel-fase-label { width: 190px; font-size: 13px; color: #374151; flex-shrink: 0; }
    .crm-funnel-fase-barra-wrap { flex: 1; background: #f3f4f6; border-radius: 6px; overflow: hidden; height: 26px; }
    .crm-funnel-fase-barra { height: 100%; background: linear-gradient(90deg, #191919, #4b5563); display: flex; align-items: center; padding-left: 8px; color: #fff; font-size: 12px; font-weight: 600; white-space: nowrap; transition: width .3s ease; }
    .crm-funnel-fase-num { width: 110px; text-align: right; font-size: 13px; color: #111827; font-weight: 600; flex-shrink: 0; }
    .crm-funnel-cancelados { margin-top: 10px; padding: 10px 12px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; font-size: 13px; color: #991b1b; }
    .crm-funnel-msg { font-size: 13px; color: #6b7280; }
    </style>

    <div class="crm-funnel-wrap" data-nonce="<?php echo esc_attr($nonce); ?>">
        <h2>Funnel de ventas</h2>

        <div class="crm-funnel-filtros">
            <div>
                <label for="crm-funnel-sector">Sector</label>
                <select id="crm-funnel-sector">
                    <?php foreach ($sectores as $s => $label) : ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($s, 'renovables'); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="crm-funnel-origen">Origen</label>
                <select id="crm-funnel-origen">
                    <?php foreach ($origenes as $o => $label) : ?>
                        <option value="<?php echo esc_attr($o); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="crm-funnel-comercial">Comercial</label>
                <select id="crm-funnel-comercial">
                    <option value="0">Todos</option>
                    <?php foreach ($comerciales as $c) : ?>
                        <option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html($c->display_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="crm-funnel-desde">Alta desde</label>
                <input type="date" id="crm-funnel-desde">
            </div>
            <div>
                <label for="crm-funnel-hasta">Alta hasta</label>
                <input type="date" id="crm-funnel-hasta">
            </div>
            <div>
                <button type="button" class="crm-btn" id="crm-funnel-aplicar-btn">Aplicar</button>
            </div>
        </div>

        <div class="crm-funnel-resultado" id="crm-funnel-resultado">
            <p class="crm-funnel-msg">Cargando…</p>
        </div>
    </div>

    <script>
    (function () {
        var wrap = document.querySelector('.crm-funnel-wrap');
        var nonce = wrap.getAttribute('data-nonce');
        var resultado = document.getElementById('crm-funnel-resultado');
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

        function cargar() {
            resultado.innerHTML = '<p class="crm-funnel-msg">Cargando…</p>';
            var body = new URLSearchParams();
            body.set('action', 'crm_funnel_calcular_ajax');
            body.set('nonce', nonce);
            body.set('sector', document.getElementById('crm-funnel-sector').value);
            body.set('origen', document.getElementById('crm-funnel-origen').value);
            body.set('comercial_id', document.getElementById('crm-funnel-comercial').value);
            body.set('fecha_desde', document.getElementById('crm-funnel-desde').value);
            body.set('fecha_hasta', document.getElementById('crm-funnel-hasta').value);
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        resultado.innerHTML = '<p class="crm-funnel-msg" style="color:#991b1b;">' + ((resp.data && resp.data.message) || 'Error.') + '</p>';
                        return;
                    }
                    var d = resp.data;
                    var html = '';
                    if (d.total_pool === 0) {
                        html = '<p class="crm-funnel-msg">No hay clientes que cumplan estos filtros.</p>';
                    } else {
                        d.fases.forEach(function (f) {
                            html += '<div class="crm-funnel-fase">' +
                                '<div class="crm-funnel-fase-label">' + f.label + '</div>' +
                                '<div class="crm-funnel-fase-barra-wrap"><div class="crm-funnel-fase-barra" style="width:' + Math.max(f.pct, 2) + '%;">' + (f.pct >= 12 ? f.pct + '%' : '') + '</div></div>' +
                                '<div class="crm-funnel-fase-num">' + f.total + ' · ' + f.pct + '%</div>' +
                                '</div>';
                        });
                        html += '<div class="crm-funnel-cancelados">⚠ ' + d.cancelados + ' cliente(s) cancelado(s) entre los interesados en este sector (con estos filtros).</div>';
                    }
                    resultado.innerHTML = html;
                });
        }

        document.getElementById('crm-funnel-aplicar-btn').addEventListener('click', cargar);
        cargar();
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_crm_funnel_calcular_ajax', 'crm_funnel_ajax_calcular');
function crm_funnel_ajax_calcular() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_funnel_ventas', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $resultado = crm_funnel_calcular([
        'sector'       => sanitize_key($_POST['sector'] ?? ''),
        'origen'       => sanitize_key($_POST['origen'] ?? 'todos'),
        'comercial_id' => (int) ($_POST['comercial_id'] ?? 0),
        'fecha_desde'  => sanitize_text_field($_POST['fecha_desde'] ?? ''),
        'fecha_hasta'  => sanitize_text_field($_POST['fecha_hasta'] ?? ''),
    ]);

    if (is_wp_error($resultado)) {
        wp_send_json_error(['message' => $resultado->get_error_message()]);
    }
    wp_send_json_success($resultado);
}

/**
 * Roadmap (v1.20.151) — ver includes/flujos-page.php.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Ventas',
        'titulo'  => 'Funnel de ventas por sector (leads → cliente convertido)',
        'estado'  => 'en_pruebas',
        'detalle' => 'Reunión con cliente 2026-09-22, punto 6. Shortcode [crm_funnel_ventas] (frontend, crm_admin) — pendiente de añadirlo al contenido de alguna página (no se reescribe automáticamente una página existente, mismo criterio que el resto de shortcodes del CRM). Cuenta todos los clientes (no solo leads de marketing), filtrable por sector/origen/comercial/fecha de alta. Simplificación consciente: "cancelado" es un estado global del cliente, no existe un cancelado por sector en el modelo de datos actual.',
    ];
    return $fases;
});
