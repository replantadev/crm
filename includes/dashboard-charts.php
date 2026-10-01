<?php
/**
 * Gráficos del panel de control (2026-10-01) — el usuario pidió sustituir
 * las tarjetas de solo-números del dashboard por gráficos de verdad.
 *
 * Reutiliza los datos ya calculados en otros sitios del plugin (nunca
 * repite una query de negocio): estado global del cliente (columna
 * `estado`, ya la mantiene crm_calcula_estado_global() en cada guardado),
 * intereses por sector (misma fuente que crm_clientes_por_interes_widget(),
 * shortcodes.php), resumen mensual de Holded (crm_ventas_resumen_mensual_datos(),
 * includes/ventas.php) y cartera por comercial (crm_equipo_listar_usuarios(),
 * includes/equipo-gestion.php).
 *
 * Paleta: los colores de crm_get_colores_sectores()/crm_get_estados_sector()
 * (crm-plugin.php) NO se reutilizan aquí a propósito — se comprobaron con el
 * validador de paletas del skill de dataviz y fallan varios checks reales
 * (contraste, separación para daltonismo). Los de abajo sí están validados
 * (`node validate_palette.js`, todo en verde) — ver detalle en cada función.
 *
 * @package CRM_Energitel
 * @since 1.20.180
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pipeline de venta como rampa ordinal de un solo tono (azul), más gris
 * neutro para "sin enviar" (no es progreso en el embudo, es "todavía no
 * entró"). Validado: 5 pasos de azul pasan lightness-monotone + hueco
 * mínimo entre pasos + contraste del extremo claro (`--ordinal`); el gris
 * es el token "muted" ya usado en el resto del CRM.
 *
 * @return array<int,array{key:string,label:string,value:int,color:string}>
 */
function crm_dashboard_graficos_datos_estado() {
    $colores = [
        'borrador'             => '#898781',
        'enviado'              => '#86b6ef',
        'presupuesto_generado' => '#5598e7',
        'presupuesto_aceptado' => '#2a78d6',
        'contratos_generados'  => '#1c5cab',
        'contratos_firmados'   => '#104281',
    ];
    $labels = [];
    foreach (crm_get_estados_sector() as $k => $info) {
        $labels[$k] = $info['label'];
    }

    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $rows  = $wpdb->get_results("SELECT estado, COUNT(*) AS total FROM {$table} GROUP BY estado", ARRAY_A);
    $counts = array_fill_keys(array_keys($colores), 0);
    foreach ((array) $rows as $r) {
        if (isset($counts[$r['estado']])) {
            $counts[$r['estado']] = (int) $r['total'];
        }
    }

    $out = [];
    foreach ($colores as $key => $color) {
        $out[] = [
            'key'   => $key,
            'label' => $labels[$key] ?? ucfirst($key),
            'value' => $counts[$key],
            'color' => $color,
        ];
    }
    return $out;
}

/**
 * Clientes por sector de interés — misma fuente de datos que
 * crm_clientes_por_interes_widget() (shortcodes.php), pero en barra
 * horizontal en vez de donut: con 5 categorías un donut obliga a comparar
 * TODOS los pares de color a la vez (no solo los adyacentes) y el validador
 * exige entonces recortar a 3 series — una barra con etiqueta propia por
 * fila no tiene ese problema. Validado: 5 colores categóricos, todo en
 * verde salvo el aviso de contraste (mitigado con el valor en texto junto a
 * cada barra, igual que ya hacían los dos widgets de Chart.js existentes).
 *
 * @return array<int,array{key:string,label:string,value:int,color:string}>
 */
function crm_dashboard_graficos_datos_sector() {
    $colores = [
        'energia'            => '#2a78d6',
        'alarmas'            => '#eb6834',
        'telecomunicaciones' => '#1baf7a',
        'seguros'            => '#eda100',
        'renovables'         => '#e87ba4',
    ];
    $labels = [
        'energia'            => 'Energía',
        'alarmas'            => 'Alarmas',
        'telecomunicaciones' => 'Telecomunicaciones',
        'seguros'            => 'Seguros',
        'renovables'         => 'Renovables',
    ];

    $counts = array_fill_keys(array_keys($colores), 0);
    $rows = crm_widget_select_clientes_field('intereses');
    foreach ((array) $rows as $raw) {
        $ints = maybe_unserialize($raw);
        if (!is_array($ints)) {
            continue;
        }
        foreach ($ints as $s) {
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }
    }

    $out = [];
    foreach ($colores as $key => $color) {
        $out[] = ['key' => $key, 'label' => $labels[$key], 'value' => $counts[$key], 'color' => $color];
    }
    usort($out, function ($a, $b) { return $b['value'] <=> $a['value']; });
    return $out;
}

/**
 * Evolución mensual de presupuestos (Holded), últimos 6 meses — reutiliza
 * crm_ventas_resumen_mensual_datos() (includes/ventas.php), la misma fuente
 * que ya alimenta la tabla de "Resumen de ventas". 2 series (ambas
 * recuentos, mismo eje — nunca eje dual con el importe en €, por eso el
 * importe no entra en este gráfico). Validado: azul + naranja (slots 1-2
 * categóricos), separación de sobra incluso sin paleta especial.
 *
 * @return array{labels:string[],generados:int[],aprobados:int[]}|null
 */
function crm_dashboard_graficos_datos_ventas_mensuales() {
    if (!function_exists('crm_ventas_resumen_mensual_datos')) {
        return null;
    }
    $meses = crm_ventas_resumen_mensual_datos();
    if (is_wp_error($meses)) {
        return null;
    }
    $labels = $generados = $aprobados = [];
    foreach ($meses as $m) {
        $labels[]    = ucfirst($m['label']);
        $generados[] = $m['generados'];
        $aprobados[] = $m['aprobados'];
    }
    return ['labels' => $labels, 'generados' => $generados, 'aprobados' => $aprobados];
}

/**
 * Cartera (nº de clientes asignados) por comercial activo — misma fuente
 * que la página "Equipo" (crm_equipo_listar_usuarios(), includes/equipo-gestion.php).
 * Un solo color: es UNA métrica repetida por entidad con nombre propio, no
 * identidades distintas — colorear cada barra de un color distinto sería
 * "color por rango" (antipatrón: el color debería seguir a la entidad, no
 * a su posición en la clasificación).
 *
 * @return array<int,array{label:string,value:int}>
 */
function crm_dashboard_graficos_datos_cartera() {
    if (!function_exists('crm_equipo_listar_usuarios')) {
        return [];
    }
    $comerciales = crm_equipo_listar_usuarios('comercial');
    $out = [];
    foreach ($comerciales as $c) {
        if (!empty($c['ko'])) {
            continue; // de baja — no aporta nada a un vistazo de cartera activa.
        }
        $out[] = ['label' => $c['nombre'], 'value' => (int) ($c['cartera'] ?? 0)];
    }
    usort($out, function ($a, $b) { return $b['value'] <=> $a['value']; });
    return $out;
}

/**
 * Pinta los 4 gráficos en una rejilla de 2 columnas, justo debajo de las
 * tarjetas de "Estadísticas del Sistema" en /panel-de-control/. Llamada
 * desde crm_admin_panel_widget() (shortcodes.php).
 */
function crm_dashboard_graficos_render() {
    $estado   = crm_dashboard_graficos_datos_estado();
    $sector   = crm_dashboard_graficos_datos_sector();
    $ventas   = crm_dashboard_graficos_datos_ventas_mensuales();
    $cartera  = crm_dashboard_graficos_datos_cartera();
    ?>
    <style>
    .crm-dash-charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
        gap: 20px;
        margin-top: 24px;
    }
    .crm-dash-chart-card {
        background: #fcfcfb;
        border: 1px solid rgba(11,11,11,0.10);
        border-radius: 12px;
        padding: 20px;
    }
    .crm-dash-chart-card h4 {
        margin: 0 0 4px;
        font-size: 15px;
        font-weight: 600;
        color: #0b0b0b;
    }
    .crm-dash-chart-card .crm-dash-chart-sub {
        margin: 0 0 14px;
        font-size: 12px;
        color: #898781;
    }
    .crm-dash-chart-canvas-wrap { position: relative; height: 220px; }
    .crm-dash-chart-canvas-wrap.is-tall { height: 260px; }
    .crm-dash-chart-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 6px 16px;
        margin-top: 12px;
        font-size: 12px;
        color: #52514e;
    }
    .crm-dash-chart-legend span.dot {
        display: inline-block;
        width: 8px; height: 8px;
        border-radius: 50%;
        margin-right: 6px;
    }
    .crm-dash-chart-legend b { color: #0b0b0b; font-variant-numeric: tabular-nums; }
    .crm-dash-chart-empty { color: #898781; font-size: 13px; padding: 40px 0; text-align: center; }
    </style>

    <div class="crm-dash-charts-grid">

        <div class="crm-dash-chart-card">
            <h4>Clientes por estado del pipeline</h4>
            <p class="crm-dash-chart-sub">De "sin enviar" a "contratos firmados" — <?php echo (int) array_sum(array_column($estado, 'value')); ?> clientes en total.</p>
            <div class="crm-dash-chart-canvas-wrap"><canvas id="crm-dash-chart-estado"></canvas></div>
        </div>

        <div class="crm-dash-chart-card">
            <h4>Clientes por sector de interés</h4>
            <p class="crm-dash-chart-sub">Un cliente puede tener más de un sector, así que la suma puede superar el total de clientes.</p>
            <div class="crm-dash-chart-canvas-wrap"><canvas id="crm-dash-chart-sector"></canvas></div>
        </div>

        <div class="crm-dash-chart-card">
            <h4>Presupuestos por mes (Holded)</h4>
            <p class="crm-dash-chart-sub">Generados vs. aprobados, últimos 6 meses.</p>
            <?php if ($ventas === null): ?>
                <div class="crm-dash-chart-empty">No se pudo consultar Holded ahora mismo.</div>
            <?php else: ?>
                <div class="crm-dash-chart-canvas-wrap"><canvas id="crm-dash-chart-ventas"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="crm-dash-chart-card">
            <h4>Cartera por comercial</h4>
            <p class="crm-dash-chart-sub">Clientes asignados a cada comercial activo ahora mismo.</p>
            <?php if (empty($cartera)): ?>
                <div class="crm-dash-chart-empty">No hay comerciales activos.</div>
            <?php else: ?>
                <div class="crm-dash-chart-canvas-wrap is-tall"><canvas id="crm-dash-chart-cartera"></canvas></div>
            <?php endif; ?>
        </div>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') { return; }

        Chart.defaults.font.family = "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";
        Chart.defaults.color = '#52514e';

        var estado = <?php echo wp_json_encode($estado); ?>;
        new Chart(document.getElementById('crm-dash-chart-estado').getContext('2d'), {
            type: 'bar',
            data: {
                labels: estado.map(function (e) { return e.label; }),
                datasets: [{
                    data: estado.map(function (e) { return e.value; }),
                    backgroundColor: estado.map(function (e) { return e.color; }),
                    borderRadius: 4,
                    barPercentage: 0.7,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.parsed.x + ' cliente(s)'; } } }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e0d9' } },
                    y: { grid: { display: false } }
                }
            }
        });

        var sector = <?php echo wp_json_encode($sector); ?>;
        new Chart(document.getElementById('crm-dash-chart-sector').getContext('2d'), {
            type: 'bar',
            data: {
                labels: sector.map(function (s) { return s.label; }),
                datasets: [{
                    data: sector.map(function (s) { return s.value; }),
                    backgroundColor: sector.map(function (s) { return s.color; }),
                    borderRadius: 4,
                    barPercentage: 0.7,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.parsed.x + ' cliente(s)'; } } }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e0d9' } },
                    y: { grid: { display: false } }
                }
            }
        });

        <?php if ($ventas !== null): ?>
        var ventas = <?php echo wp_json_encode($ventas); ?>;
        new Chart(document.getElementById('crm-dash-chart-ventas').getContext('2d'), {
            type: 'line',
            data: {
                labels: ventas.labels,
                datasets: [
                    {
                        label: 'Generados',
                        data: ventas.generados,
                        borderColor: '#2a78d6',
                        backgroundColor: '#2a78d6',
                        borderWidth: 2,
                        pointRadius: 4,
                        tension: 0.25,
                    },
                    {
                        label: 'Aprobados',
                        data: ventas.aprobados,
                        borderColor: '#eb6834',
                        backgroundColor: '#eb6834',
                        borderWidth: 2,
                        pointRadius: 4,
                        tension: 0.25,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e0d9' } },
                    x: { grid: { display: false } }
                }
            }
        });
        <?php endif; ?>

        <?php if (!empty($cartera)): ?>
        var cartera = <?php echo wp_json_encode($cartera); ?>;
        new Chart(document.getElementById('crm-dash-chart-cartera').getContext('2d'), {
            type: 'bar',
            data: {
                labels: cartera.map(function (c) { return c.label; }),
                datasets: [{
                    data: cartera.map(function (c) { return c.value; }),
                    backgroundColor: '#2a78d6',
                    borderRadius: 4,
                    barPercentage: 0.6,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.parsed.x + ' cliente(s)'; } } }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e0d9' } },
                    y: { grid: { display: false } }
                }
            }
        });
        <?php endif; ?>
    });
    </script>
    <?php
}
