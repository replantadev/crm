<?php
// Shortcode para clientes por interés
add_shortcode('crm_clientes_por_interes', 'crm_clientes_por_interes_widget');
function crm_clientes_por_interes_widget()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }

    global $wpdb;
    $table = $wpdb->prefix . "crm_clients";

    // 1) Contar intereses por sector
    $sectores = array_keys(crm_get_colores_sectores());
    $counts   = array_fill_keys($sectores, 0);
    $rows     = $wpdb->get_col("SELECT intereses FROM $table");

    foreach ($rows as $raw) {
        $ints = maybe_unserialize($raw);
        if (!is_array($ints)) continue;
        foreach ($ints as $s) {
            if (isset($counts[$s])) $counts[$s]++;
        }
    }

    // Prepara datos para JS
    $labels = array_map('ucfirst', $sectores);
    $data   = array_values($counts);
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
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function(ctx) {
                  return ctx.label + ': ' + ctx.formattedValue;
                }
              }
            }
          }
        }
      });
    });
    </script>

    <?php
    return ob_get_clean();
}

// PANEL DE CONTROL ENERGITEL CRM
add_shortcode('crm_admin_panel', 'crm_admin_panel_widget');
function crm_admin_panel_widget() {
    if (!current_user_can('crm_admin')) {
        return "<p>No tienes permiso para acceder al panel de administración.</p>";
    }
    
    // Procesar actualizaciones de configuración
    if (isset($_POST['update_crm_settings']) && wp_verify_nonce($_POST['crm_nonce'], 'crm_admin_settings')) {
        $settings = array();
        $settings['admin_notifications'] = isset($_POST['admin_notifications']);
        $settings['comercial_notifications'] = isset($_POST['comercial_notifications']);
        $settings['test_mode'] = isset($_POST['test_mode']);
        $settings['log_retention_days'] = intval($_POST['log_retention_days']);
        if ($settings['log_retention_days'] <= 0) {
            $settings['log_retention_days'] = 30;
        }
        update_option('crm_email_settings', $settings);
        echo '<div class="notice notice-success"><p>Configuración actualizada correctamente.</p></div>';
    }
    
    $default_settings = array(
        'admin_notifications' => true,
        'comercial_notifications' => true,
        'test_mode' => false,
        'log_retention_days' => 30
    );
    $settings = get_option('crm_email_settings', $default_settings);
    
    // Obtener estadísticas del sistema
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
            <!-- Estadísticas Principales -->
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
            
            <!-- Configuraciones Rápidas -->
            <div class="admin-quick-settings">
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('crm_admin_settings', 'crm_nonce'); ?>
                    <div class="settings-row-compact">
                        <label class="setting-toggle">
                            <input type="checkbox" name="admin_notifications" <?php checked($settings['admin_notifications']); ?>>
                            <span class="toggle-label">Notificaciones Admin</span>
                        </label>
                        <label class="setting-toggle">
                            <input type="checkbox" name="comercial_notifications" <?php checked($settings['comercial_notifications']); ?>>
                            <span class="toggle-label">Notificaciones Comercial</span>
                        </label>
                        <label class="setting-toggle">
                            <input type="checkbox" name="test_mode" <?php checked($settings['test_mode']); ?>>
                            <span class="toggle-label">Modo Prueba</span>
                        </label>
                    </div>
                    <div class="admin-actions">
                        <button type="submit" name="update_crm_settings" class="btn-compact btn-primary">Guardar</button>
                        <button type="button" id="test-email-btn" class="btn-compact">Test Email</button>
                        <button type="button" id="clean-logs-btn" class="btn-compact btn-danger">Limpiar Logs</button>
                    </div>
                </form>
            </div>
            
            <!-- Herramientas Rápidas -->
            <div class="admin-tools-grid">
                <a href="/todas-las-altas-de-cliente/" class="tool-link">Ver Clientes</a>
                <a href="/resumen/" class="tool-link">Resumen</a>
                <button type="button" id="export-data-btn" class="tool-link">Exportar</button>
                <button type="button" id="system-info-btn" class="tool-link">Info Sistema</button>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Test de email
        var testEmailBtn = document.getElementById('test-email-btn');
        if (testEmailBtn) {
            testEmailBtn.addEventListener('click', function() {
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Enviando...';
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=crm_test_email&nonce=<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    alert(data.success ? 'Email enviado correctamente' : 'Error al enviar email');
                    btn.disabled = false;
                    btn.textContent = 'Test Email';
                })
                .catch(function(e) {
                    alert('Error: ' + e.message);
                    btn.disabled = false;
                    btn.textContent = 'Test Email';
                });
            });
        }
        
        // Limpiar logs
        var cleanLogsBtn = document.getElementById('clean-logs-btn');
        if (cleanLogsBtn) {
            cleanLogsBtn.addEventListener('click', function() {
                if (!confirm('¿Eliminar logs antiguos?')) return;
                
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Limpiando...';
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=crm_clean_logs&nonce=<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    alert(data.success ? 'Logs eliminados correctamente' : 'Error al eliminar logs');
                    if (data.success) {
                        setTimeout(function() { location.reload(); }, 1000);
                    }
                    btn.disabled = false;
                    btn.textContent = 'Limpiar Logs';
                });
            });
        }
        
        // Exportar datos
        var exportBtn = document.getElementById('export-data-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                window.open('<?php echo admin_url('admin-ajax.php'); ?>?action=crm_export_data&nonce=<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>', '_blank');
            });
        }
        
        // Info del sistema
        var systemInfoBtn = document.getElementById('system-info-btn');
        if (systemInfoBtn) {
            systemInfoBtn.addEventListener('click', function() {
                alert('WordPress: <?php echo get_bloginfo('version'); ?>\nPHP: <?php echo PHP_VERSION; ?>\nMemoria: <?php echo ini_get('memory_limit'); ?>');
            });
        }
    });
    </script>

    <?php
    return ob_get_clean();
}

// CLIENTES RECIENTES WIDGET
add_shortcode('crm_clientes_recientes', 'crm_clientes_recientes_widget');
function crm_clientes_recientes_widget()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }
    global $wpdb;
    $current = get_current_user_id();
    $table   = $wpdb->prefix . "crm_clients";

    if (current_user_can('crm_admin')) {
        $rows = $wpdb->get_results("SELECT cliente_nombre, empresa, estado, creado_en FROM $table ORDER BY creado_en DESC LIMIT 5", ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT cliente_nombre, empresa, estado, creado_en
              FROM $table
             WHERE user_id=%d
             ORDER BY creado_en DESC
             LIMIT 5
        ", $current), ARRAY_A);
    }

    ob_start(); ?>
    
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

// CLIENTES POR ESTADO WIDGET
add_shortcode('crm_clientes_por_estado', 'crm_clientes_por_estado_widget');
function crm_clientes_por_estado_widget()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }
    global $wpdb;
    $table = $wpdb->prefix . "crm_clients";

    $sectores = array_keys(crm_get_colores_sectores());
    $estados  = array_keys(crm_get_estados_sector());

    // Inicializar matriz de conteos
    $matrix = array();
    foreach ($sectores as $s) {
        foreach ($estados as $e) {
            if (!isset($matrix[$e])) {
                $matrix[$e] = array();
            }
            $matrix[$e][$s] = 0;
        }
    }

    // Recolectar
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

    // Prepara datasets
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
            'label'           => $label,
            'data'            => $data_points,
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
