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
 * Mapa contact_id (Holded) => ['id'=>client_id, 'nombre'=>..., 'user_id'=>...]
 * para poder enlazar cada presupuesto a la ficha de cliente del CRM cuando
 * exista. `user_id` (v1.20.127) es el comercial dueño del cliente en el
 * CRM — el único camino real que existe hoy para resolver un presupuesto de
 * Holded a una cuenta de WordPress notificable (el mapa
 * `crm_holded_usuarios_mapa` de Ajustes solo da un nombre, no una cuenta).
 *
 * @return array<string,array{id:int,nombre:string,user_id:int}>
 */
function crm_ventas_mapa_clientes_por_contact_id() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, cliente_nombre, holded_contact_id, user_id FROM {$wpdb->prefix}crm_clients WHERE holded_contact_id IS NOT NULL AND holded_contact_id != ''",
        ARRAY_A
    );
    $mapa = [];
    foreach ((array) $rows as $r) {
        $mapa[$r['holded_contact_id']] = ['id' => (int) $r['id'], 'nombre' => $r['cliente_nombre'], 'user_id' => (int) $r['user_id']];
    }
    return $mapa;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 7 · presupuesto estancado (v1.20.127): avisa al comercial dueño del
// cliente cuando un presupuesto lleva demasiado sin moverse. Confirmado con
// el usuario 2026-09-20: "estancado" = lleva X días sin cambiar de estado
// (umbral configurable, por defecto 7) — cubre también el caso más
// específico de "sigue enviado sin respuesta", que es un caso particular de
// éste. LIMITACIÓN REAL: la lista de /estimates de Holded no trae una fecha
// de "última actualización", solo `date` (fecha de creación) — no hay forma
// de saber si un presupuesto llevaba ya tiempo en un estado y cambió a otro
// reciente sin traerse el detalle de cada uno (caro, ~1000 llamadas). Por
// eso el reloj de "estancado" es días desde la CREACIÓN, no desde el último
// cambio real — en la práctica es el mismo dato para el caso típico
// (presupuesto que nunca se aprobó), pero uno que SÍ cambió de estado y
// volvió a quedarse quieto no se detectaría hasta que Holded exponga esa
// fecha en el listado.
// ──────────────────────────────────────────────────────────────────────────────

function crm_ventas_aviso_estancados_schedule_cron() {
    if (!wp_next_scheduled('crm_ventas_aviso_estancados_cron_hourly')) {
        wp_schedule_event(time() + 900, 'hourly', 'crm_ventas_aviso_estancados_cron_hourly');
    }
}
add_action('init', function () {
    if (!wp_doing_cron() && !wp_next_scheduled('crm_ventas_aviso_estancados_cron_hourly')) {
        crm_ventas_aviso_estancados_schedule_cron();
    }
}, 20);

add_action('crm_ventas_aviso_estancados_cron_hourly', 'crm_ventas_aviso_estancados_run');
/**
 * Recorre los presupuestos cacheados, marca como "estancado" el que lleva
 * más de N días creado y sigue en borrador (`draft`), y avisa UNA VEZ al
 * comercial dueño del cliente (si se puede resolver a una cuenta real) —
 * dedup vía un option con los IDs ya avisados, no se repite aunque siga
 * estancado en pasadas futuras.
 */
function crm_ventas_aviso_estancados_run() {
    $hoy = current_time('Y-m-d');
    if (get_option('crm_ventas_aviso_estancados_last_run', '') === $hoy) {
        return;
    }
    update_option('crm_ventas_aviso_estancados_last_run', $hoy, false);

    if (!function_exists('crm_holded_get_estimates_cached')) {
        return;
    }
    $estimates = crm_holded_get_estimates_cached();
    if (is_wp_error($estimates) || empty($estimates)) {
        return;
    }

    $dias_umbral = max(1, (int) get_option('crm_ventas_aviso_estancados_dias', 7));
    $limite_ts   = current_time('timestamp') - ($dias_umbral * DAY_IN_SECONDS);
    $clientes    = crm_ventas_mapa_clientes_por_contact_id();
    $avisados    = get_option('crm_ventas_estancados_avisados', []);
    if (!is_array($avisados)) {
        $avisados = [];
    }

    foreach ($estimates as $e) {
        if (empty($e['draft'])) {
            continue; // Ya aprobado/rechazado — no está estancado.
        }
        $doc_id = (string) ($e['id'] ?? $e['document_number'] ?? '');
        if ($doc_id === '' || isset($avisados[$doc_id])) {
            continue;
        }
        $fecha_ts = !empty($e['date']) ? strtotime((string) $e['date']) : false;
        if ($fecha_ts === false || $fecha_ts > $limite_ts) {
            continue; // Todavía no llega al umbral de días.
        }

        $contact_id = (string) ($e['contact_id'] ?? '');
        $cliente    = $clientes[$contact_id] ?? null;
        $comercial_id = $cliente['user_id'] ?? 0;
        $cliente_nombre = $cliente['nombre'] ?? ($e['contact_name'] ?? 'cliente sin nombre');
        $mensaje = 'Presupuesto ' . ($e['document_number'] ?? $doc_id) . ' de ' . $cliente_nombre . ' lleva más de ' . $dias_umbral . ' días sin aprobarse.';
        $url = $cliente ? add_query_arg('client_id', $cliente['id'], home_url('/editar-cliente/')) : '';

        if ($comercial_id > 0) {
            $user = get_userdata($comercial_id);
            if ($user) {
                if (function_exists('crm_notificar')) {
                    crm_notificar($comercial_id, 'presupuesto_estancado', $mensaje, $url);
                }
                if (!empty($user->user_email)) {
                    $body = '<p>' . esc_html($mensaje) . '</p>';
                    if ($url !== '') {
                        $body .= '<p><a href="' . esc_url($url) . '">Ver ficha del cliente</a></p>';
                    }
                    $body .= '<p style="color:#666;font-size:12px">Aviso automático del CRM.</p>';
                    wp_mail($user->user_email, '[CRM] Presupuesto estancado', $body, ['Content-Type: text/html; charset=UTF-8']);
                }
                // v1.20.128: antes solo quedaba anotado el caso "sin comercial
                // resoluble" — el caso normal (sí se avisó a alguien) no
                // dejaba ningún rastro consultable desde la ficha del cliente.
                if (function_exists('crm_log_action')) {
                    crm_log_action('presupuesto_estancado_avisado', $mensaje . ' Avisado: ' . $user->display_name . '.', $cliente['id'] ?? null, 0, 'info');
                }
            }
        } elseif (function_exists('crm_log_action')) {
            // Sin comercial resoluble: se registra igualmente para que quede
            // visible en Logs, en vez de perderse en silencio.
            crm_log_action('presupuesto_estancado_sin_comercial', $mensaje, $cliente['id'] ?? null, 0, 'notice');
        }

        $avisados[$doc_id] = current_time('mysql');
    }

    update_option('crm_ventas_estancados_avisados', $avisados, false);
}

/**
 * Ajustes de "Presupuesto estancado" para el FRONTEND (`/panel-de-control/`)
 * — mismo patrón ya usado para email/WhatsApp/notificaciones de
 * instalaciones: crm_admin no puede entrar a wp-admin, así que cualquier
 * ajuste operativo necesita también su versión aquí.
 */
function crm_ventas_settings_render() {
    if (!current_user_can('crm_admin')) {
        return;
    }
    $nonce_action = 'crm_ventas_settings_guardar';
    if (isset($_POST['crm_ventas_settings_guardar']) && wp_verify_nonce($_POST['crm_ventas_nonce'] ?? '', $nonce_action)) {
        update_option('crm_ventas_aviso_estancados_dias', max(1, (int) ($_POST['crm_ventas_aviso_estancados_dias'] ?? 7)), false);
        echo '<div style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:10px;font-size:13px;">Configuración guardada.</div>';
    }
    $dias = (int) get_option('crm_ventas_aviso_estancados_dias', 7);
    ?>
    <div class="crm-mail-canal" style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
        <h4 style="margin:0 0 8px;">Presupuesto estancado</h4>
        <p style="font-size:12.5px;color:#6b7280;margin:0 0 10px;">Avisa al comercial dueño del cliente (in-app + email) cuando un presupuesto de Holded lleva demasiados días creado y sigue sin aprobarse. Solo puede avisar si el cliente en el CRM tiene un comercial asignado (campo "Comercial" de la ficha) — si no, queda anotado en Logs sin avisar a nadie.</p>
        <form method="post">
            <?php wp_nonce_field($nonce_action, 'crm_ventas_nonce'); ?>
            <input type="hidden" name="crm_ventas_settings_guardar" value="1">
            <p>
                Avisar cuando lleve más de
                <input type="number" min="1" step="1" name="crm_ventas_aviso_estancados_dias" value="<?php echo esc_attr($dias); ?>" style="width:60px;">
                días sin aprobarse
            </p>
            <p><button type="submit" class="crm-btn">Guardar</button></p>
        </form>
    </div>
    <?php
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
        'detalle' => 'Presupuestos (todos los de Holded, con estado y cliente enlazado) y Resumen (generados/aprobados/importe por mes). Confirmado 2026-09-17: la topbar del CRM ya se ve bien en las 2 páginas (antes mostraban el header de Astra, v1.20.108). Sigue sin confirmar el contenido en sí — los datos de presupuestos/resumen con carga real de Holded (puede tardar la primera vez con muchos presupuestos). v1.20.127: `crm_ventas_mapa_clientes_por_contact_id()` ahora también resuelve el comercial (user_id) de cada cliente — lo usa el nuevo aviso de "presupuesto estancado" (ver Fase 7 en el roadmap de instalaciones).',
    ];
    return $fases;
});
