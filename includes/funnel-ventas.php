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

    $sql = "SELECT id, cliente_nombre, origen_lead, user_id, lead_mk_status, intereses, estado_por_sector, estado
            FROM $table WHERE $where_sql";
    $rows = empty($args)
        ? $wpdb->get_results($sql, ARRAY_A)
        : $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

    $total_pool    = count($rows);
    $orden_estados = crm_get_orden_estados(); // borrador..contratos_firmados (6 valores, índices 0-5)

    // v1.20.153 — a petición del usuario ("que les sirva de verdad"): cada
    // fila se reduce a UNA "etapa actual" (0=captado sin asignar .. 8=cliente
    // convertido), en vez de solo sumar booleanos sueltos como antes. De ahí
    // salen a la vez: (a) los totales acumulados de cada barra ("llegó al
    // menos hasta aquí", sumando desde su índice hacia atrás) y (b) la lista
    // de quién está EXACTAMENTE parado en cada etapa ahora mismo — el
    // drill-down, para poder abrir la ficha de quien lleva tiempo sin
    // moverse, no solo ver un número.
    //
    // Orígenes cuyo lifecycle de "asignado/trabajado" tiene sentido de
    // verdad (los que pasan por la cola de leads) — el resto (alta directa
    // de un comercial, referido, web) nace ya "asignado y trabajado".
    $origenes_con_cola = ['lead_mk', 'placassolares'];

    $etapas_clave = ['captados', 'asignados', 'trabajados', 'interesados', 'enviado', 'presupuesto_generado', 'presupuesto_aceptado', 'contratos_generados', 'contratos_firmados'];
    $por_etapa = array_fill_keys($etapas_clave, []); // clave => [ ['id'=>,'nombre'=>], ... ] (exactamente en esa etapa, solo casos vivos)
    $reach_hasta = array_fill(0, count($etapas_clave), 0); // índice numérico => cuántos llegaron (vivos o cancelados) al menos hasta ahí
    $cancelados_lista = [];

    foreach ($rows as $r) {
        $cliente = ['id' => (int) $r['id'], 'nombre' => $r['cliente_nombre'] ?: ('Cliente #' . $r['id'])];
        $es_origen_cola  = in_array($r['origen_lead'], $origenes_con_cola, true);
        $tiene_comercial = (int) ($r['user_id'] ?? 0) > 0;
        $lead_mk_trabajado = sanitize_key((string) ($r['lead_mk_status'] ?? '')) === 'trabajado';

        $idx = 0; // captados
        if (!$es_origen_cola || $tiene_comercial) {
            $idx = 1; // asignados
        }
        if (!$es_origen_cola || $lead_mk_trabajado) {
            $idx = 2; // trabajados
        }

        $intereses = maybe_unserialize($r['intereses'] ?? '');
        $intereses = is_array($intereses) ? $intereses : [];
        $es_cancelado = $r['estado'] === 'cancelado';
        $interesado_en_sector = in_array($sector, $intereses, true);

        if ($interesado_en_sector) {
            $eps = maybe_unserialize($r['estado_por_sector'] ?? '');
            $eps = is_array($eps) ? $eps : [];
            $estado_sector = sanitize_key((string) ($eps[$sector] ?? 'borrador'));
            $estado_idx = array_search($estado_sector, $orden_estados, true);
            if ($estado_idx === false) {
                $estado_idx = 0; // Estado desconocido/legacy → primer escalón.
            }
            // 'interesados' (índice 3 de $etapas_clave) = borrador (índice 0
            // de $orden_estados); a partir de ahí, 1 a 1.
            $idx = 3 + $estado_idx;
        }

        // v1.20.153 fix: un cancelado SÍ cuenta en las barras acumuladas
        // hasta el punto al que llegó (por eso $reach_hasta se rellena
        // siempre, cancelado o no) — lo único que cambia es que no aparece
        // en el drill-down de "quién está aquí ahora" ($por_etapa), porque
        // ya no es un caso vivo/accionable, y se apunta aparte en
        // $cancelados_lista. Antes de este fix, un cancelado desaparecía
        // también de "Leads captados", lo cual infla artificialmente la
        // caída de esa primera barra.
        $reach_hasta[$idx] = ($reach_hasta[$idx] ?? 0) + 1;
        if ($es_cancelado && $interesado_en_sector) {
            $cancelados_lista[] = $cliente;
        } else {
            $por_etapa[$etapas_clave[$idx]][] = $cliente;
        }
    }

    $labels_estado = crm_get_estados_sector();
    $labels_fase = [
        'captados'    => 'Leads captados',
        'asignados'   => 'Asignados',
        'trabajados'  => 'Trabajados',
        'interesados' => 'Interesados en ' . (crm_funnel_sectores_label()[$sector] ?? $sector),
        'enviado'              => $labels_estado['enviado']['label'] ?? 'Enviado',
        'presupuesto_generado' => $labels_estado['presupuesto_generado']['label'] ?? 'Presupuesto Generado',
        'presupuesto_aceptado' => $labels_estado['presupuesto_aceptado']['label'] ?? 'Presupuesto Aceptado',
        'contratos_generados'  => $labels_estado['contratos_generados']['label'] ?? 'Contratos Generados',
        'contratos_firmados'   => 'Cliente convertido',
    ];

    // Totales ACUMULADOS ("llegó al menos hasta aquí") = suma de esta etapa
    // y todas las que vienen después en la secuencia.
    $fases = [];
    $total_anterior = null;
    foreach ($etapas_clave as $i => $clave) {
        $acumulado = 0;
        for ($j = $i; $j < count($etapas_clave); $j++) {
            $acumulado += $reach_hasta[$j];
        }
        $clientes_aqui = $por_etapa[$clave];
        $fases[] = [
            'clave'       => $clave,
            'label'       => $labels_fase[$clave],
            'total'       => $acumulado,
            'pct'         => round(($acumulado / max($total_pool, 1)) * 100, 1),
            // % respecto a la etapa anterior — esto es lo que de verdad dice
            // DÓNDE se pierde gente, a diferencia del % sobre el total.
            'pct_desde_anterior' => $total_anterior === null ? null : ($total_anterior > 0 ? round(($acumulado / $total_anterior) * 100, 1) : 0.0),
            // Quién está EXACTAMENTE aquí ahora mismo (no más allá) — el
            // drill-down: hasta 50, para no mandar listas enormes de golpe.
            'clientes'    => array_slice($clientes_aqui, 0, 50),
            'clientes_total' => count($clientes_aqui),
        ];
        $total_anterior = $acumulado;
    }

    return [
        'fases'            => $fases,
        'cancelados'       => count($cancelados_lista),
        'cancelados_lista' => array_slice($cancelados_lista, 0, 50),
        'total_pool'       => $total_pool,
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
    .crm-funnel-fase-barra-wrap { flex: 1; background: #f3f4f6; border-radius: 6px; overflow: hidden; height: 26px; cursor: pointer; }
    .crm-funnel-fase-barra { height: 100%; background: linear-gradient(90deg, #191919, #4b5563); display: flex; align-items: center; padding-left: 8px; color: #fff; font-size: 12px; font-weight: 600; white-space: nowrap; transition: width .3s ease; }
    .crm-funnel-fase-num { width: 130px; text-align: right; font-size: 13px; color: #111827; font-weight: 600; flex-shrink: 0; }
    .crm-funnel-fase-num small { display: block; font-weight: 400; color: #6b7280; font-size: 11px; }
    .crm-funnel-conector { margin: 0 0 0 190px; padding: 2px 0 2px 10px; font-size: 11.5px; color: #b91c1c; }
    .crm-funnel-conector.crm-funnel-conector--ok { color: #065f46; }
    .crm-funnel-clientes { display: none; margin: 4px 0 10px 190px; padding: 8px 10px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 12.5px; }
    .crm-funnel-clientes.is-open { display: block; }
    .crm-funnel-clientes a { color: #191919; text-decoration: none; display: inline-block; margin: 2px 8px 2px 0; }
    .crm-funnel-clientes a:hover { text-decoration: underline; }
    .crm-funnel-clientes .crm-funnel-clientes-vacio { color: #9ca3af; }
    .crm-funnel-cancelados { margin-top: 10px; padding: 10px 12px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; font-size: 13px; color: #991b1b; cursor: pointer; }
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
                        var fichaUrl = <?php echo wp_json_encode(home_url('/editar-cliente/')); ?>;
                        var listaClientes = function (clientes, total) {
                            if (!clientes.length) {
                                return '<span class="crm-funnel-clientes-vacio">Nadie parado exactamente en esta etapa ahora mismo.</span>';
                            }
                            var out = clientes.map(function (c) {
                                return '<a href="' + fichaUrl + '?client_id=' + c.id + '" target="_blank" rel="noopener">' + escapeHtml(c.nombre) + '</a>';
                            }).join('');
                            if (total > clientes.length) {
                                out += '<br><small>… y ' + (total - clientes.length) + ' más (afina los filtros para verlos todos).</small>';
                            }
                            return out;
                        };

                        d.fases.forEach(function (f, i) {
                            if (i > 0) {
                                var prev = d.fases[i - 1];
                                var caida = f.pct_desde_anterior;
                                var esOk = caida === null || caida >= 70;
                                html += '<div class="crm-funnel-conector' + (esOk ? ' crm-funnel-conector--ok' : '') + '">' +
                                    '↓ de "' + prev.label + '" a "' + f.label + '": ' + (caida === null ? '—' : caida + '%') +
                                    '</div>';
                            }
                            html += '<div class="crm-funnel-fase">' +
                                '<div class="crm-funnel-fase-label">' + f.label + '</div>' +
                                '<div class="crm-funnel-fase-barra-wrap" data-fase="' + f.clave + '"><div class="crm-funnel-fase-barra" style="width:' + Math.max(f.pct, 2) + '%;">' + (f.pct >= 12 ? f.pct + '%' : '') + '</div></div>' +
                                '<div class="crm-funnel-fase-num">' + f.total + ' · ' + f.pct + '%<small>' + f.clientes_total + ' aquí ahora</small></div>' +
                                '</div>' +
                                '<div class="crm-funnel-clientes" id="crm-funnel-clientes-' + f.clave + '">' + listaClientes(f.clientes, f.clientes_total) + '</div>';
                        });
                        html += '<div class="crm-funnel-cancelados" id="crm-funnel-cancelados-toggle">⚠ ' + d.cancelados + ' cliente(s) cancelado(s) entre los interesados en este sector (con estos filtros) — pulsa para ver.</div>' +
                            '<div class="crm-funnel-clientes" id="crm-funnel-clientes-cancelados" style="margin-left:0;">' + listaClientes(d.cancelados_lista, d.cancelados) + '</div>';
                    }
                    resultado.innerHTML = html;

                    resultado.querySelectorAll('.crm-funnel-fase-barra-wrap').forEach(function (el) {
                        el.addEventListener('click', function () {
                            var panel = document.getElementById('crm-funnel-clientes-' + el.getAttribute('data-fase'));
                            if (panel) { panel.classList.toggle('is-open'); }
                        });
                    });
                    var cancToggle = document.getElementById('crm-funnel-cancelados-toggle');
                    if (cancToggle) {
                        cancToggle.addEventListener('click', function () {
                            document.getElementById('crm-funnel-clientes-cancelados').classList.toggle('is-open');
                        });
                    }
                });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
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
        'estado'  => 'hecho',
        'detalle' => 'Reunión con cliente 2026-09-22, punto 6. Shortcode [crm_funnel_ventas], página /ventas-funnel/ autocreada y en el menú "Ventas" (v1.20.152). Cuenta todos los clientes (no solo leads de marketing), filtrable por sector/origen/comercial/fecha de alta. v1.20.153, a petición del usuario tras verlo "un poco simple": % de caída entre etapas + drill-down (clic en una barra abre la lista de clientes en esa etapa). Bug real encontrado y corregido con un script de prueba antes de desplegar: los clientes cancelados quedaban excluidos de TODAS las barras acumuladas, deflactando incluso "Leads captados". Confirmado por el usuario: "esa primera versión ya es sólida".',
    ];
    return $fases;
});
