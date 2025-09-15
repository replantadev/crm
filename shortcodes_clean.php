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

// Shortcode para clientes recientes
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


// ————————————————————————————————————————————————————————————————
// 1) CLIENTES POR ESTADO (POR SECTOR)
// ————————————————————————————————————————————————————————————————
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

// ————————————————————————————————————————————————————————————————
// 2) RENDIMIENTO COMERCIAL (DETAILED POR ESTADO-POR-SECTOR)
// ————————————————————————————————————————————————————————————————
add_shortcode('crm_rendimiento_comercial', 'crm_rendimiento_comercial_widget');
function crm_rendimiento_comercial_widget()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }
    global $wpdb;
    $uid   = get_current_user_id();
    $table = $wpdb->prefix . "crm_clients";

    // inicializo
    $totales = [
        'borrador'             => 0,
        'presupuesto_aceptado' => 0,
        'contratos_generados'  => 0,
        'contratos_firmados'   => 0,
    ];

    // cojo sólo los registros pertinentes
    if (current_user_can('crm_admin')) {
        $rows = $wpdb->get_results("SELECT estado_por_sector FROM $table", ARRAY_A);
    } else {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT estado_por_sector FROM $table WHERE user_id = %d", $uid),
            ARRAY_A
        );
    }
    // cuento igual que antes
    foreach ($rows as $r) {
        $eps = maybe_unserialize($r['estado_por_sector']);
        if (!is_array($eps)) continue;
        foreach ($eps as $st) {
            if (isset($totales[$st])) {
                $totales[$st]++;
            }
        }
    }

    // vuelco en HTML
    ob_start(); ?>
    <div class="crm-dashboard-compact">
        <div class="dashboard-header-compact">
            <h3 class="dashboard-title-compact">
                <?php echo current_user_can('crm_admin') ? 'Rendimiento General' : 'Mi Rendimiento'; ?>
            </h3>
        </div>

        <div class="stats-row-compact">
            <?php 
            $labels = [
                'borrador' => 'Borrador',
                'presupuesto_aceptado' => 'Presup. Aceptado', 
                'contratos_generados' => 'Contratos Gen.',
                'contratos_firmados' => 'Contratos Firm.'
            ];
            
            $total = array_sum($totales);
            
            foreach ($totales as $key => $count): 
                $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
            ?>
                <div class="stat-compact estado-<?php echo $key; ?>">
                    <div class="stat-label-compact"><?php echo $labels[$key]; ?></div>
                    <div class="stat-value-compact"><?php echo $count; ?></div>
                    <div class="stat-percent-compact"><?php echo $percentage; ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (current_user_can('crm_admin')): ?>
            <div class="dashboard-actions-compact">
                <a href="/resumen" class="action-link-compact">Ver Resumen de Comerciales</a>
                <a href="/panel-de-control" class="action-link-compact">Control y Registro</a>
            </div>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}

// ————————————————————————————————————————————————————————————————
// 3) ESTADÍSTICAS POR COMERCIAL (POR ESTADO-POR-SECTOR)
// ————————————————————————————————————————————————————————————————
add_shortcode('crm_comerciales_estadisticas', 'crm_comerciales_estadisticas');
function crm_comerciales_estadisticas()
{
    if (!current_user_can('crm_admin')) {
        return "<p>No tienes permiso para ver esta sección.</p>";
    }
    global $wpdb;
    $table = $wpdb->prefix . "crm_clients";

    // primero sacamos todos los user_id que tengan clientes
    $users = $wpdb->get_col("SELECT DISTINCT user_id FROM $table");

    ob_start(); ?>
    <table id="crm-comerciales-estadisticas" class="crm-table material-design">
        <thead>
            <tr>
                <th>#</th>
                <th>Comercial</th>
                <th>Borrador</th>
                <th>Presup.Acept.</th>
                <th>Contr.Gen.</th>
                <th>Contr.Firm.</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $i => $uid):
                $tot = ['borrador' => 0, 'presupuesto_aceptado' => 0, 'contratos_generados' => 0, 'contratos_firmados' => 0];
                // recupero sus registros
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT estado_por_sector FROM $table WHERE user_id=%d", $uid),
                    ARRAY_A
                );
                foreach ($rows as $r) {
                    $eps = maybe_unserialize($r['estado_por_sector']);
                    if (is_array($eps)) {
                        foreach ($eps as $st) {
                            if (isset($tot[$st])) $tot[$st]++;
                        }
                    }
                }
            ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td>
                        <a href="<?php echo home_url("/mis-altas-de-cliente/?user_id={$uid}"); ?>">
                            <?php echo esc_html(get_userdata($uid)->display_name); ?>
                        </a>
                    </td>
                    <td><?php echo $tot['borrador']; ?></td>
                    <td><?php echo $tot['presupuesto_aceptado']; ?></td>
                    <td><?php echo $tot['contratos_generados']; ?></td>
                    <td><?php echo $tot['contratos_firmados']; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
        jQuery(document).ready(function($) {
            // Añadir clase a la tabla
            jQuery('#crm-comerciales-estadisticas').DataTable({
                pageLength: 20,
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
                },
                order: [
                    [0, 'asc']
                ]
            });
        });
    </script>
<?php
    return ob_get_clean();
}

add_action('wp_ajax_crm_obtener_comerciales_estadisticas', 'crm_obtener_comerciales_estadisticas');
function crm_obtener_comerciales_estadisticas()
{
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No tienes permiso para realizar esta acción.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    $comerciales = $wpdb->get_results("
        SELECT u.ID as id, u.display_name as nombre,
               COUNT(c.id) as numero_altas,
               MAX(c.actualizado_en) as ultima_alta,
               SUM(CASE WHEN c.estado = 'borrador' THEN 1 ELSE 0 END) as borradores,
               SUM(CASE WHEN c.estado = 'enviado' THEN 1 ELSE 0 END) as enviados,
               SUM(CASE WHEN c.estado = 'pendiente_revision' THEN 1 ELSE 0 END) as pendiente_revision,
               SUM(CASE WHEN c.estado = 'aceptado' THEN 1 ELSE 0 END) as aceptados,
               SUM(CASE WHEN c.estado = 'presupuesto_enviado' THEN 1 ELSE 0 END) as presupuestos_enviados,
               SUM(CASE WHEN c.estado = 'contratos_enviados' THEN 1 ELSE 0 END) as contratos_enviados,
               SUM(CASE WHEN c.estado = 'contratos_firmados' THEN 1 ELSE 0 END) as contratos_firmados
        FROM {$wpdb->users} u
        LEFT JOIN $table_name c ON u.ID = c.user_id
        WHERE u.ID IN (SELECT DISTINCT user_id FROM $table_name)
        GROUP BY u.ID, u.display_name
        ORDER BY numero_altas DESC
    ", ARRAY_A);

    if (!empty($comerciales)) {
        wp_send_json_success($comerciales);
    } else {
        wp_send_json_error(['message' => 'No se encontraron comerciales.']);
    }
}

// Handler para generar logs de prueba
add_action('wp_ajax_crm_generate_sample_logs', 'crm_generate_sample_logs_handler');
function crm_generate_sample_logs_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Sin permisos para generar logs de prueba']);
    }
    
    global $wpdb;
    $log_table = $wpdb->prefix . 'crm_activity_log';
    $current_user = wp_get_current_user();
    $user_name = $current_user->display_name ?: 'Administrador';
    $user_id = get_current_user_id();
    
    // Array de actividades de ejemplo
    $sample_activities = [
        ['type' => 'cliente_creado', 'details' => 'Cliente creado: Empresa ABC S.L. | Email: contacto@abc.com | Teléfono: 612345678 | Estado inicial: presupuesto_enviado | Delegado asignado: ' . $user_name, 'client_id' => 101],
        ['type' => 'archivo_subido', 'details' => 'Archivo subido: presupuesto_ABC_2024.pdf | Sector: presupuestos | Tipo: presupuesto | Tamaño: 245.67 KB', 'client_id' => 101],
        ['type' => 'sectores_enviados', 'details' => 'Sectores enviados: energía, alarmas para cliente Empresa ABC S.L.', 'client_id' => 101],
        ['type' => 'cliente_actualizado', 'details' => 'Cliente actualizado: Empresa XYZ Corp | Email: info@xyz.com | Estado: cliente_convertido | Cambios en archivos: Nuevos contratos en contratos: 1 archivo(s)', 'client_id' => 102],
        ['type' => 'archivo_subido', 'details' => 'Archivo subido: contrato_firmado_XYZ.pdf | Sector: contratos | Tipo: contrato_firmado | Tamaño: 1.2 MB', 'client_id' => 102],
        ['type' => 'cliente_creado', 'details' => 'Cliente creado: TechSolutions Ltd | Email: admin@techsol.com | Teléfono: 987654321 | Estado inicial: reunion_inicial | Delegado asignado: ' . $user_name, 'client_id' => 103],
        ['type' => 'archivo_subido', 'details' => 'Archivo subido: factura_marzo_2024.pdf | Sector: facturas | Tipo: factura | Tamaño: 89.34 KB', 'client_id' => 103],
        ['type' => 'cliente_actualizado', 'details' => 'Cliente actualizado: TechSolutions Ltd | Estado: presupuesto_enviado | Sectores enviados: presupuestos', 'client_id' => 103],
        ['type' => 'archivo_eliminado', 'details' => 'Archivo eliminado: borrador_presupuesto.pdf | Tipo: presupuesto', 'client_id' => 103],
        ['type' => 'test_email_enviado', 'details' => 'Email de prueba enviado a: admin@tudominio.com', 'client_id' => null],
        ['type' => 'database_optimized', 'details' => 'Base de datos optimizada - 2.5MB liberados', 'client_id' => null],
        ['type' => 'backup_created', 'details' => 'Backup del sistema creado: crm_backup_2025-09-04.sql', 'client_id' => null],
        ['type' => 'cliente_eliminado', 'details' => 'Cliente eliminado: Empresa Demo | Email: demo@demo.com | Teléfono: 000000000 | Estado: cancelado', 'client_id' => 999],
        ['type' => 'logs_prueba_generados', 'details' => 'Se generaron logs de actividad de prueba para demonstrar el sistema', 'client_id' => null],
        ['type' => 'panel_accedido', 'details' => 'Panel de administración consultado', 'client_id' => null]
    ];
    
    $inserted = 0;
    foreach ($sample_activities as $i => $activity) {
        // Crear timestamps variados (últimas 2 horas)
        $timestamp = date('Y-m-d H:i:s', time() - (120 * 60) + ($i * 10 * 60)); // Distribuir en las últimas 2 horas
        
        $result = $wpdb->insert($log_table, [
            'user_id' => $user_id,
            'user_name' => $user_name,
            'action_type' => $activity['type'],
            'details' => $activity['details'],
            'client_id' => $activity['client_id'],
            'created_at' => $timestamp,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        if ($result) $inserted++;
    }
    
    // Registrar la generación de logs de prueba
    crm_log_action('logs_prueba_generados', "Se generaron $inserted logs de actividad de prueba");
    
    wp_send_json_success([
        'message' => "✅ Se generaron $inserted logs de actividad de prueba exitosamente!",
        'inserted' => $inserted
    ]);
}

// Handler para limpiar todos los logs
add_action('wp_ajax_crm_clear_all_logs', 'crm_clear_all_logs_handler');
function crm_clear_all_logs_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Sin permisos para limpiar logs']);
    }
    
    global $wpdb;
    $log_table = $wpdb->prefix . 'crm_activity_log';
    
    // Contar logs antes de eliminar
    $count_before = $wpdb->get_var("SELECT COUNT(*) FROM $log_table");
    
    // Eliminar todos los logs
    $deleted = $wpdb->query("TRUNCATE TABLE $log_table");
    
    if ($deleted !== false) {
        wp_send_json_success([
            'message' => "✅ Todos los logs han sido eliminados exitosamente. ($count_before registros eliminados)",
            'deleted' => $count_before
        ]);
    } else {
        wp_send_json_error(['message' => 'Error al eliminar los logs']);
    }
}
