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
    document.addEventListener("DOMContentLoaded", () => {
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
                label: ctx => `${ctx.label}: ${ctx.formattedValue}`
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

// ========== PANEL DE CONTROL ENERGITEL CRM ==========
add_shortcode('crm_admin_panel', 'crm_admin_panel_widget');
function crm_admin_panel_widget() {
    if (!current_user_can('crm_admin')) {
        return "<p>No tienes permiso para acceder al panel de administración.</p>";
    }
    
    // Procesar actualizaciones de configuración
    if (isset($_POST['update_crm_settings']) && wp_verify_nonce($_POST['crm_nonce'], 'crm_admin_settings')) {
        $settings = [
            'admin_notifications' => isset($_POST['admin_notifications']),
            'comercial_notifications' => isset($_POST['comercial_notifications']),
            'test_mode' => isset($_POST['test_mode']),
            'log_retention_days' => intval($_POST['log_retention_days'] ?? 30)
        ];
        update_option('crm_email_settings', $settings);
        echo '<div class="notice notice-success"><p>Configuración actualizada correctamente.</p></div>';
    }
    
    $settings = get_option('crm_email_settings', [
        'admin_notifications' => true,
        'comercial_notifications' => true,
        'test_mode' => false,
        'log_retention_days' => 30
    ]);
    
    
    // Obtener estadísticas del sistema
    global $wpdb;
    $clients_table = $wpdb->prefix . 'crm_clients';
    $log_table = $wpdb->prefix . 'crm_activity_log';
    
    $stats = [
        'total_clients' => $wpdb->get_var("SELECT COUNT(*) FROM $clients_table"),
        'clients_today' => $wpdb->get_var("SELECT COUNT(*) FROM $clients_table WHERE DATE(creado_en) = CURDATE()"),
        'emails_sent' => $wpdb->get_var("SELECT COUNT(*) FROM $log_table WHERE action_type = 'email_enviado'"),
        'active_comercials' => $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $clients_table")
    ];

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
        document.getElementById('test-email-btn')?.addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Enviando...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_test_email',
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? 'Email enviado correctamente' : 'Error al enviar email');
                btn.disabled = false;
                btn.textContent = 'Test Email';
            })
            .catch(e => {
                alert('Error: ' + e.message);
                btn.disabled = false;
                btn.textContent = 'Test Email';
            });
        });
        
        // Limpiar logs
        document.getElementById('clean-logs-btn')?.addEventListener('click', function() {
            if (!confirm('¿Eliminar logs antiguos?')) return;
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Limpiando...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_clean_logs',
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? 'Logs eliminados correctamente' : 'Error al eliminar logs');
                if (data.success) setTimeout(() => location.reload(), 1000);
                btn.disabled = false;
                btn.textContent = 'Limpiar Logs';
            });
        });
        
        // Exportar datos
        document.getElementById('export-data-btn')?.addEventListener('click', function() {
            window.open('<?php echo admin_url('admin-ajax.php'); ?>?action=crm_export_data&nonce=<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>', '_blank');
        });
        
        // Info del sistema
        document.getElementById('system-info-btn')?.addEventListener('click', function() {
            alert('WordPress: <?php echo get_bloginfo('version'); ?>\nPHP: <?php echo PHP_VERSION; ?>\nMemoria: <?php echo ini_get('memory_limit'); ?>');
        });
    });
    </script>

    <?php
    return ob_get_clean();
}

// ========== CLIENTES RECIENTES WIDGET ==========

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

// ========== CLIENTES POR ESTADO WIDGET ==========

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
    $matrix = [];
    foreach ($sectores as $s) {
        foreach ($estados as $e) {
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
    $datasets = [];
    foreach ($estados as $e) {
        $label = crm_get_estados_sector()[$e]['label'];
        $color = crm_get_estados_sector()[$e]['color'];
        $datasets[] = [
            'label'           => $label,
            'data'            => array_map(fn($s) => $matrix[$e][$s], $sectores),
            'backgroundColor' => $color
        ];
    }

    ob_start();
    ?>
    
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Clientes por Estado</h3>
            <div class="widget-stats-compact">
                <span class="total-count"><?php echo array_sum(array_map('array_sum', $matrix)); ?> total</span>
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
    document.addEventListener("DOMContentLoaded", () => {
      const ctx = document.getElementById('chart-clientes-estado').getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: <?php echo json_encode(array_map('ucfirst', $sectores)); ?>,
          datasets: <?php echo json_encode($datasets); ?>
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
