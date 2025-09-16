<?php
add_shortcode('crm_clientes_por_interes', 'crm_clientes_por_interes_widget');
function crm_clientes_por_interes_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }

    global $wpdb;
    $table = $wpdb->prefix . "crm_clients";
    $sectores = array_keys(crm_get_colores_sectores());
    $counts = array_fill_keys($sectores, 0);
    $rows = $wpdb->get_col("SELECT intereses FROM $table");

    foreach ($rows as $raw) {
        $ints = maybe_unserialize($raw);
        if (!is_array($ints)) continue;
        foreach ($ints as $s) {
            if (isset($counts[$s])) $counts[$s]++;
        }
    }

    $labels = array_map('ucfirst', $sectores);
    $data = array_values($counts);
    $colors = array_values(crm_get_colores_sectores());

    ob_start();
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Clientes por Interés</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo array_sum($data); ?> total</span>
            </div>
        </div>
        <div class="widget-content-compact">
            <div class="chart-container-compact">
                <canvas id="chart-clientes-interes"></canvas>
            </div>
            <div class="chart-legend-compact">
                <?php foreach ($labels as $i => $label): ?>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: <?php echo $colors[$i]; ?>"></div>
                        <span class="legend-label"><?php echo $label; ?></span>
                        <span class="legend-value"><?php echo $data[$i]; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
      const ctx = document.getElementById('chart-clientes-interes').getContext('2d');
      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: <?php echo json_encode($labels); ?>,
          datasets: [{
            data: <?php echo json_encode($data); ?>,
            backgroundColor: <?php echo json_encode($colors); ?>,
            borderWidth: 0,
            cutout: '65%'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          }
        }
      });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('crm_admin_panel', 'crm_admin_panel_widget');
function crm_admin_panel_widget() {
    if (!current_user_can('crm_admin')) {
        return "<p>No tienes permiso para acceder al panel de administración.</p>";
    }
    
    global $wpdb;
    $clients_table = $wpdb->prefix . 'crm_clients';
    $log_table = $wpdb->prefix . 'crm_activity_log';
    
    $stats = array();
    $stats['total_clients'] = $wpdb->get_var("SELECT COUNT(*) FROM $clients_table");
    $stats['clients_today'] = $wpdb->get_var("SELECT COUNT(*) FROM $clients_table WHERE DATE(creado_en) = CURDATE()");
    $stats['emails_sent'] = $wpdb->get_var("SELECT COUNT(*) FROM $log_table WHERE action_type = 'email_enviado'");
    $stats['active_comercials'] = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $clients_table");

    ob_start();
    ?>
    <div class="crm-widget-compact admin-panel-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Panel de Control CRM</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo $stats['total_clients']; ?> clientes</span>
            </div>
        </div>
        <div class="widget-content-compact">
            <div class="stats-row-compact">
                <div class="stat-item-compact">
                    <div class="stat-value"><?php echo $stats['total_clients']; ?></div>
                    <div class="stat-label">Total Clientes</div>
                </div>
                <div class="stat-item-compact">
                    <div class="stat-value"><?php echo $stats['clients_today']; ?></div>
                    <div class="stat-label">Hoy</div>
                </div>
                <div class="stat-item-compact">
                    <div class="stat-value"><?php echo $stats['emails_sent']; ?></div>
                    <div class="stat-label">Emails</div>
                </div>
                <div class="stat-item-compact">
                    <div class="stat-value"><?php echo $stats['active_comercials']; ?></div>
                    <div class="stat-label">Comerciales</div>
                </div>
            </div>
            <div class="admin-tools-grid">
                <a href="/todas-las-altas-de-cliente/" class="tool-link">Ver Clientes</a>
                <a href="/resumen/" class="tool-link">Resumen</a>
                <button type="button" class="tool-link">Exportar</button>
                <button type="button" class="tool-link">Info Sistema</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('crm_clientes_recientes', 'crm_clientes_recientes_widget');
function crm_clientes_recientes_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }
    global $wpdb;
    $current = get_current_user_id();
    $table = $wpdb->prefix . "crm_clients";

    if (current_user_can('crm_admin')) {
        $rows = $wpdb->get_results("SELECT cliente_nombre, empresa, estado, creado_en FROM $table ORDER BY creado_en DESC LIMIT 5", ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT cliente_nombre, empresa, estado, creado_en FROM $table WHERE user_id=%d ORDER BY creado_en DESC LIMIT 5", $current), ARRAY_A);
    }

    ob_start(); 
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Clientes Recientes</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo count($rows); ?> recientes</span>
            </div>
        </div>
        <div class="widget-content-compact">
            <div class="table-responsive-compact">
                <table class="crm-table-compact">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Empresa</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="client-name-cell"><?php echo esc_html($r['cliente_nombre']); ?></td>
                            <td class="company-cell"><?php echo esc_html($r['empresa']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($r['estado']); ?>">
                                    <?php echo crm_get_estado_label($r['estado']); ?>
                                </span>
                            </td>
                            <td class="date-cell"><?php echo date_i18n('d/m', strtotime($r['creado_en'])); ?></td>
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

add_shortcode('crm_clientes_por_estado', 'crm_clientes_por_estado_widget');
function crm_clientes_por_estado_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }
    global $wpdb;
    $table = $wpdb->prefix . "crm_clients";

    $sectores = array_keys(crm_get_colores_sectores());
    $estados = array_keys(crm_get_estados_sector());

    $matrix = array();
    foreach ($sectores as $s) {
        foreach ($estados as $e) {
            if (!isset($matrix[$e])) {
                $matrix[$e] = array();
            }
            $matrix[$e][$s] = 0;
        }
    }

    $rows = $wpdb->get_col("SELECT estado_por_sector FROM $table");
    foreach ($rows as $raw) {
        $eps = maybe_unserialize($raw);
        if (!is_array($eps)) continue;
        foreach ($eps as $s => $e) {
            if (isset($matrix[$e][$s])) {
                $matrix[$e][$s]++;
            }
        }
    }

    $datasets = array();
    foreach ($estados as $e) {
        $estado_info = crm_get_estados_sector();
        $label = $estado_info[$e]['label'];
        $color = $estado_info[$e]['color'];
        $data_points = array();
        foreach ($sectores as $s) {
            $data_points[] = $matrix[$e][$s];
        }
        $datasets[] = array(
            'label' => $label,
            'data' => $data_points,
            'backgroundColor' => $color
        );
    }

    ob_start();
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Clientes por Estado</h3>
            <div class="widget-stats-compact">
                <?php 
                $total = 0;
                foreach ($matrix as $estado_data) {
                    $total += array_sum($estado_data);
                }
                ?>
                <span class="total-count"><?php echo $total; ?> total</span>
            </div>
        </div>
        <div class="widget-content-compact">
            <div class="chart-container-compact">
                <canvas id="chart-clientes-estado"></canvas>
            </div>
            <div class="chart-summary-compact">
                <div class="summary-grid">
                    <?php foreach ($datasets as $dataset): ?>
                        <div class="summary-item">
                            <div class="summary-color" style="background-color: <?php echo $dataset['backgroundColor']; ?>"></div>
                            <span class="summary-label"><?php echo $dataset['label']; ?></span>
                            <span class="summary-value"><?php echo array_sum($dataset['data']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
      var ctx = document.getElementById('chart-clientes-estado').getContext('2d');
      var sectores_labels = <?php echo json_encode(array_map('ucfirst', $sectores)); ?>;
      var datasets_data = <?php echo json_encode($datasets); ?>;
      
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: sectores_labels,
          datasets: datasets_data
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            x: { stacked: true },
            y: { 
              stacked: true,
              beginAtZero: true,
              ticks: { precision: 0 }
            }
          },
          plugins: {
            legend: { display: false }
          }
        }
      });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('crm_rendimiento_comercial', 'crm_rendimiento_comercial_widget');
function crm_rendimiento_comercial_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }
    global $wpdb;
    $uid = get_current_user_id();
    $table = $wpdb->prefix . "crm_clients";

    // Inicializar contadores
    $totales = array(
        'borrador' => 0,
        'presupuesto_aceptado' => 0,
        'contratos_generados' => 0,
        'contratos_firmados' => 0,
    );

    // Obtener registros pertinentes
    if (current_user_can('crm_admin')) {
        $rows = $wpdb->get_results("SELECT estado_por_sector FROM $table", ARRAY_A);
    } else {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT estado_por_sector FROM $table WHERE user_id = %d", $uid),
            ARRAY_A
        );
    }

    // Contar estados
    foreach ($rows as $r) {
        $eps = maybe_unserialize($r['estado_por_sector']);
        if (!is_array($eps)) continue;
        foreach ($eps as $st) {
            if (isset($totales[$st])) {
                $totales[$st]++;
            }
        }
    }

    ob_start(); 
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">
                <?php echo current_user_can('crm_admin') ? 'Rendimiento General' : 'Mi Rendimiento'; ?>
            </h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo array_sum($totales); ?> total</span>
            </div>
        </div>

        <div class="widget-content-compact">
            <div class="stats-grid-compact">
                <?php 
                $labels = array(
                    'borrador' => 'Borrador',
                    'presupuesto_aceptado' => 'Presup. Aceptado', 
                    'contratos_generados' => 'Contratos Gen.',
                    'contratos_firmados' => 'Contratos Firm.'
                );
                
                $total = array_sum($totales);
                
                foreach ($totales as $key => $count): 
                    $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                ?>
                    <div class="stat-item-compact estado-<?php echo $key; ?>">
                        <div class="stat-label"><?php echo $labels[$key]; ?></div>
                        <div class="stat-value"><?php echo $count; ?></div>
                        <div class="stat-percent"><?php echo $percentage; ?>%</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (current_user_can('crm_admin')): ?>
                <div class="widget-actions-compact">
                    <a href="/resumen" class="action-link-compact">Ver Resumen</a>
                    <a href="/panel-de-control" class="action-link-compact">Control</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('crm_comerciales_estadisticas', 'crm_comerciales_estadisticas_widget');
function crm_comerciales_estadisticas_widget() {
    if (!current_user_can('crm_admin')) {
        return "<p>No tienes permiso para ver esta sección.</p>";
    }
    global $wpdb;
    $table = $wpdb->prefix . "crm_clients";

    // Obtener todos los user_id que tengan clientes
    $users = $wpdb->get_col("SELECT DISTINCT user_id FROM $table");

    ob_start(); 
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Estadísticas por Comercial</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo count($users); ?> comerciales</span>
            </div>
        </div>
        
        <div class="widget-content-compact">
            <div class="table-responsive-compact">
                <table class="crm-table-compact" id="crm-comerciales-estadisticas">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Comercial</th>
                            <th>Borrador</th>
                            <th>Presup. Acept.</th>
                            <th>Contr. Gen.</th>
                            <th>Contr. Firm.</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $i => $uid):
                            $totales = array('borrador' => 0, 'presupuesto_aceptado' => 0, 'contratos_generados' => 0, 'contratos_firmados' => 0);
                            
                            // Recuperar registros del comercial
                            $rows = $wpdb->get_results(
                                $wpdb->prepare("SELECT estado_por_sector FROM $table WHERE user_id=%d", $uid),
                                ARRAY_A
                            );
                            
                            foreach ($rows as $r) {
                                $eps = maybe_unserialize($r['estado_por_sector']);
                                if (is_array($eps)) {
                                    foreach ($eps as $st) {
                                        if (isset($totales[$st])) {
                                            $totales[$st]++;
                                        }
                                    }
                                }
                            }
                            
                            $total_comercial = array_sum($totales);
                            $user_data = get_userdata($uid);
                        ?>
                            <tr>
                                <td class="text-center"><?php echo $i + 1; ?></td>
                                <td class="comercial-name-cell">
                                    <a href="<?php echo home_url("/mis-altas-de-cliente/?user_id={$uid}"); ?>" class="comercial-link">
                                        <?php echo esc_html($user_data->display_name); ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <span class="estado-badge estado-borrador"><?php echo $totales['borrador']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="estado-badge estado-presupuesto"><?php echo $totales['presupuesto_aceptado']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="estado-badge estado-contratos-gen"><?php echo $totales['contratos_generados']; ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="estado-badge estado-contratos-firm"><?php echo $totales['contratos_firmados']; ?></span>
                                </td>
                                <td class="text-center total-cell">
                                    <strong><?php echo $total_comercial; ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar DataTable si está disponible
        if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable) {
            jQuery('#crm-comerciales-estadisticas').DataTable({
                pageLength: 20,
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
                },
                order: [[6, 'desc']], // Ordenar por total descendente
                columnDefs: [
                    { orderable: false, targets: 0 }, // Desactivar orden en columna #
                    { className: "text-center", targets: [0, 2, 3, 4, 5, 6] }
                ]
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
