<?php
/**
 * Menú "Ventas" (v1.20.96) — presupuestos de Holded (todos, con estado y
 * cliente) y un resumen de ventas por mes. Solo lectura contra Holded,
 * mismo perfil de riesgo que la Fase 6 (doble check de facturación).
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Todos los presupuestos de Holded, cacheados 15 min (mismo patrón que
 * crm_holded_get_products_cached() / crm_holded_get_contacts_cached()).
 *
 * @return array|WP_Error
 */
function crm_holded_get_estimates_cached() {
    $cache_key = 'crm_holded_estimates_all';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }
    if (!function_exists('crm_holded_search_estimates')) {
        return new WP_Error('crm_holded_no_disponible', 'Módulo de Holded no disponible.');
    }
    $estimates = crm_holded_search_estimates('', 20); // hasta ~1000 presupuestos.
    if (is_wp_error($estimates)) {
        return $estimates;
    }
    set_transient($cache_key, $estimates, 15 * MINUTE_IN_SECONDS);
    return $estimates;
}

/**
 * Mapa contact_id (Holded) => ['id'=>client_id, 'nombre'=>...] para poder
 * enlazar cada presupuesto a la ficha de cliente del CRM cuando exista.
 *
 * @return array<string,array{id:int,nombre:string}>
 */
function crm_ventas_mapa_clientes_por_contact_id() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, cliente_nombre, holded_contact_id FROM {$wpdb->prefix}crm_clients WHERE holded_contact_id IS NOT NULL AND holded_contact_id != ''",
        ARRAY_A
    );
    $mapa = [];
    foreach ((array) $rows as $r) {
        $mapa[$r['holded_contact_id']] = ['id' => (int) $r['id'], 'nombre' => $r['cliente_nombre']];
    }
    return $mapa;
}

/**
 * [crm_ventas_presupuestos] — todos los presupuestos de Holded, con estado
 * y cliente (enlazado a su ficha del CRM si ya está sincronizado).
 */
add_shortcode('crm_ventas_presupuestos', 'crm_ventas_presupuestos_widget');
function crm_ventas_presupuestos_widget() {
    if (!current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }

    $estimates = crm_holded_get_estimates_cached();
    if (is_wp_error($estimates)) {
        return '<p style="color:#991b1b;">No se pudo consultar Holded: ' . esc_html($estimates->get_error_message()) . '</p>';
    }

    // Más recientes primero.
    usort($estimates, function ($a, $b) {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    $clientes = crm_ventas_mapa_clientes_por_contact_id();

    ob_start();
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Presupuestos (Holded)</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo count($estimates); ?> presupuestos</span>
            </div>
        </div>
        <div class="widget-content-compact">
            <div class="table-responsive-compact">
                <table class="crm-table-compact">
                    <thead>
                        <tr><th>Nº</th><th>Cliente</th><th>Fecha</th><th>Importe</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($estimates)): ?>
                            <tr><td colspan="5">Sin presupuestos.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($estimates as $e):
                            $contact_id = (string) ($e['contact_id'] ?? '');
                            $cliente    = $clientes[$contact_id] ?? null;
                            $aprobado   = empty($e['draft']);
                        ?>
                            <tr>
                                <td><?php echo esc_html($e['document_number'] ?? ''); ?></td>
                                <td>
                                    <?php if ($cliente): ?>
                                        <a href="<?php echo esc_url(home_url('/editar-cliente/?client_id=' . $cliente['id'])); ?>"><?php echo esc_html($cliente['nombre']); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($e['contact_name'] ?? '—'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(!empty($e['date']) ? date_i18n('d/m/Y', strtotime((string) $e['date'])) : '—'); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) str_replace(',', '.', (string) ($e['total'] ?? 0)), 2)); ?> <?php echo esc_html($e['currency'] ?? '€'); ?></td>
                                <td>
                                    <span class="status-badge status-inst-<?php echo $aprobado ? 'lista' : 'pendiente'; ?>"><?php echo $aprobado ? 'Aprobado' : 'Pendiente'; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * [crm_ventas_resumen] — presupuestos generados/aprobados e importe
 * aprobado por mes, últimos 6 meses (a partir de los mismos presupuestos de
 * Holded que la vista anterior, sin ninguna llamada adicional).
 */
add_shortcode('crm_ventas_resumen', 'crm_ventas_resumen_widget');
function crm_ventas_resumen_widget() {
    if (!current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }

    $estimates = crm_holded_get_estimates_cached();
    if (is_wp_error($estimates)) {
        return '<p style="color:#991b1b;">No se pudo consultar Holded: ' . esc_html($estimates->get_error_message()) . '</p>';
    }

    $meses = [];
    for ($i = 5; $i >= 0; $i--) {
        $clave = date_i18n('Y-m', strtotime("-{$i} months"));
        $meses[$clave] = [
            'label'          => date_i18n('M Y', strtotime("-{$i} months")),
            'generados'      => 0,
            'aprobados'      => 0,
            'importe_aprobado' => 0.0,
        ];
    }

    foreach ($estimates as $e) {
        $fecha = (string) ($e['date'] ?? '');
        if ($fecha === '') {
            continue;
        }
        $clave = substr($fecha, 0, 7);
        if (!isset($meses[$clave])) {
            continue; // Fuera de la ventana de los últimos 6 meses.
        }
        $meses[$clave]['generados']++;
        if (empty($e['draft'])) {
            $meses[$clave]['aprobados']++;
            $meses[$clave]['importe_aprobado'] += (float) str_replace(',', '.', (string) ($e['total'] ?? 0));
        }
    }

    ob_start();
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Resumen de ventas (Holded)</h3>
        </div>
        <div class="widget-content-compact">
            <div class="table-responsive-compact">
                <table class="crm-table-compact">
                    <thead>
                        <tr><th>Mes</th><th>Presupuestos generados</th><th>Aprobados</th><th>Importe aprobado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($meses as $m): ?>
                            <tr>
                                <td><?php echo esc_html(ucfirst($m['label'])); ?></td>
                                <td><?php echo (int) $m['generados']; ?></td>
                                <td><?php echo (int) $m['aprobados']; ?></td>
                                <td><?php echo esc_html(number_format_i18n($m['importe_aprobado'], 2)); ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="crm-inst-field-info" style="margin-top:10px;">Calculado sobre los presupuestos que Holded devuelve hoy (máx. ~1000, cacheados 15 min) — no es una llamada en vivo por cada visita a esta página.</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * [crm_instaladores_estadisticas] — listado de instaladores con sus
 * instalaciones asignadas/completadas. Mismo estilo visual que
 * crm_comerciales_estadisticas_widget() (shortcodes.php), pensado para ir
 * justo debajo en la página "Equipo" (antes "Resumen").
 */
add_shortcode('crm_instaladores_estadisticas', 'crm_instaladores_estadisticas_widget');
function crm_instaladores_estadisticas_widget() {
    if (!current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }
    if (!function_exists('crm_inst_table_instaladores') || !function_exists('crm_inst_table_instalaciones')) {
        return '';
    }

    $instaladores = get_users(['role' => 'instalador', 'fields' => ['ID', 'display_name']]);

    global $wpdb;
    $tabla_pivote = crm_inst_table_instaladores();
    $tabla_inst   = crm_inst_table_instalaciones();

    ob_start();
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Estadísticas por Instalador</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo count($instaladores); ?> instaladores</span>
            </div>
        </div>
        <div class="widget-content-compact">
            <div class="table-responsive-compact">
                <table class="crm-table-compact">
                    <thead>
                        <tr><th>#</th><th>Instalador</th><th>Asignadas</th><th>Finalizadas</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($instaladores)): ?>
                            <tr><td colspan="4">Todavía no hay ningún usuario con el rol Instalador.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($instaladores as $i => $u):
                            $asignadas = (int) $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$tabla_pivote} WHERE user_id = %d",
                                $u->ID
                            ));
                            $finalizadas = (int) $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$tabla_pivote} p INNER JOIN {$tabla_inst} i ON i.id = p.instalacion_id WHERE p.user_id = %d AND i.estado = 'finalizada'",
                                $u->ID
                            ));
                        ?>
                            <tr>
                                <td><?php echo (int) ($i + 1); ?></td>
                                <td><?php echo esc_html($u->display_name); ?></td>
                                <td><?php echo $asignadas; ?></td>
                                <td><?php echo $finalizadas; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Roadmap del menú "Ventas" (v1.20.96) — ver includes/flujos-page.php.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Ventas',
        'titulo'  => 'Menú "Ventas": presupuestos de Holded y resumen mensual',
        'estado'  => 'en_pruebas',
        'detalle' => 'Presupuestos (todos los de Holded, con estado y cliente enlazado) y Resumen (generados/aprobados/importe por mes). Construido, sin confirmación del usuario todavía — incluida la carga de la primera visita, que puede tardar con muchos presupuestos en Holded.',
    ];
    return $fases;
});
