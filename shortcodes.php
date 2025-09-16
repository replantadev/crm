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
    
    // Procesar actualizaciones de configuración
    if (isset($_POST['update_crm_settings']) && wp_verify_nonce($_POST['crm_nonce'], 'crm_admin_settings')) {
        $settings = [
            'admin_notifications' => isset($_POST['admin_notifications']),
            'comercial_notifications' => isset($_POST['comercial_notifications']),
            'test_mode' => isset($_POST['test_mode']),
            'log_retention_days' => intval($_POST['log_retention_days'])
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
    
    ob_start();
    ?>
    
    <style>
    .crm-admin-panel {
        max-width: 1200px;
        margin: 20px auto;
        padding: 30px;
        background: linear-gradient(135deg, rgb(255, 255, 255) 0%, rgb(249, 250, 251) 100%);
        border-radius: 16px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .crm-admin-panel h2 {
        margin: 0 0 30px 0;
        font-size: 28px;
        font-weight: 700;
        background: linear-gradient(135deg, rgb(15, 23, 42) 0%, rgb(51, 65, 85) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        display: flex;
        align-items: center;
        border-bottom: 2px solid rgb(226, 232, 240);
        padding-bottom: 20px;
    }
    
    .crm-panel-section {
        margin-bottom: 40px;
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        border: 1px solid rgb(229, 231, 235);
        transition: all 0.3s ease;
    }
    
    .crm-panel-section:hover {
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        transform: translateY(-2px);
    }
    
    .crm-panel-section h3 {
        margin: 0 0 20px 0;
        font-size: 20px;
        font-weight: 600;
        margin: 0;
        color: rgb(30, 41, 59);
        cursor: pointer;
        user-select: none;
    }
    
    .crm-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-top: 24px;
    }
    
    .crm-stat-card {
        background: linear-gradient(135deg, rgb(15, 23, 42) 0%, rgb(30, 41, 59) 50%, rgb(51, 65, 85) 100%);
        color: white;
        padding: 24px;
        border-radius: 12px;
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .crm-stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(147, 197, 253, 0.1) 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .crm-stat-card:hover::before {
        opacity: 1;
    }
    
    .crm-stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
    }
    
    .crm-stat-card h4 {
        margin: 0 0 8px 0;
        font-size: 32px;
        font-weight: 700;
        position: relative;
        z-index: 1;
        color: white;
    }
    
    .crm-stat-card p {
        margin: 0;
        opacity: 0.9;
        font-size: 14px;
        font-weight: 500;
        position: relative;
        z-index: 1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .crm-log-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    .crm-log-table th, .crm-log-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid rgb(241, 245, 249);
        font-size: 14px;
    }
    
    .crm-log-table th {
        background: linear-gradient(135deg, rgb(15, 23, 42) 0%, rgb(30, 41, 59) 100%);
        color: white;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 12px;
    }
    
    .crm-log-table tbody tr {
        transition: background-color 0.2s ease;
    }
    
    .crm-log-table tbody tr:hover {
        background: rgb(248, 250, 252);
    }
    
    .crm-log-table tbody tr:nth-child(even) {
        background: rgba(248, 250, 252, 0.5);
    }
    
    .crm-action-type {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: 1px solid transparent;
    }
    
    .action-cliente_creado { 
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); 
        color: #065f46; 
        border-color: #10b981;
    }
    .action-cliente_actualizado { 
        background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); 
        color: #1e40af; 
        border-color: #3b82f6;
    }
    .action-sectores_enviados { 
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); 
        color: #92400e; 
        border-color: #f59e0b;
    }
    .action-email_enviado { 
        background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%); 
        color: #be185d; 
        border-color: #ec4899;
    }
    .action-test_email_enviado { 
        background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); 
        color: #3730a3; 
        border-color: #6366f1;
    }
    
    .crm-btn {
        background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(37, 99, 235) 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        margin: 6px 8px 6px 0;
        font-weight: 500;
        font-size: 14px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
    }
    
    .crm-btn:hover {
        background: linear-gradient(135deg, rgb(37, 99, 235) 0%, rgb(29, 78, 216) 100%);
        color: white;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
    }
    
    .crm-btn-danger {
        background: linear-gradient(135deg, rgb(239, 68, 68) 0%, rgb(220, 38, 38) 100%);
        box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
    }
    
    .crm-btn-danger:hover {
        background: linear-gradient(135deg, rgb(220, 38, 38) 0%, rgb(185, 28, 28) 100%);
        box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
    }
    
    .crm-btn-secondary {
        background: linear-gradient(135deg, rgb(71, 85, 105) 0%, rgb(51, 65, 85) 100%);
        box-shadow: 0 2px 4px rgba(71, 85, 105, 0.2);
    }
    
    .crm-btn-secondary:hover {
        background: linear-gradient(135deg, rgb(51, 65, 85) 0%, rgb(30, 41, 59) 100%);
        box-shadow: 0 4px 8px rgba(71, 85, 105, 0.3);
    }
    
    .notice {
        padding: 16px 20px;
        border-radius: 8px;
        margin: 20px 0;
        font-weight: 500;
        border-left: 4px solid;
    }
    
    .notice-success {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
        border-color: #10b981;
    }
    
    .notice-error {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
        border-color: #ef4444;
    }
    
    .crm-settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .crm-settings-grid label {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        background: rgb(248, 250, 252);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .crm-settings-grid label:hover {
        background: rgb(241, 245, 249);
    }
    
    @media (max-width: 768px) {
        .crm-admin-panel {
            margin: 10px;
            padding: 20px;
        }
        
        .crm-settings-grid {
            grid-template-columns: 1fr;
        }
        
        .crm-stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
        
        .crm-log-table {
            font-size: 12px;
        }
        
        .crm-log-table th, .crm-log-table td {
            padding: 8px 10px;
        }
    }
    </style>
    
    <div class="crm-admin-panel">
        <h2><img src="<?php echo get_site_icon_url(); ?>" alt="Logo" style="width: 24px; height: 24px; border-radius: 4px; vertical-align: middle; margin-right: 8px;"> Panel de Control Energitel CRM</h2>
        
        <!-- Estadísticas Generales -->
        <div class="crm-panel-section">
            <h3>📊 Estadísticas del Sistema</h3>
            <?php
            global $wpdb;
            $clients_table = $wpdb->prefix . 'crm_clients';
            
            // Obtener emails enviados de tablas mensuales
            $available_months = crm_get_available_log_months();
            $emails_sent = 0;
            
            foreach ($available_months as $month_data) {
                $table_name = $month_data['table'];
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE action_type IN ('email_enviado', 'test_email_enviado', 'notificacion_comercial_enviada', 'notificacion_admin_enviada')");
                $emails_sent += (int) $count;
            }
            
            $stats = [
                'total_clients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $clients_table"),
                'clients_today' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $clients_table WHERE DATE(creado_en) = CURDATE()"),
                'emails_sent' => $emails_sent,
                'active_comercials' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $clients_table WHERE user_id IS NOT NULL")
            ];
            ?>
            <div class="crm-stats-grid">
                <div class="crm-stat-card">
                    <h4><?php echo $stats['total_clients']; ?></h4>
                    <p>Total Clientes</p>
                </div>
                <div class="crm-stat-card">
                    <h4><?php echo $stats['clients_today']; ?></h4>
                    <p>Clientes Hoy</p>
                </div>
                <div class="crm-stat-card">
                    <h4><?php echo $stats['emails_sent']; ?></h4>
                    <p>Emails Enviados</p>
                </div>
                <div class="crm-stat-card">
                    <h4><?php echo $stats['active_comercials']; ?></h4>
                    <p>Comerciales Activos</p>
                </div>
            </div>
        </div>
        
        <!-- Configuración de Emails -->
        <div class="crm-panel-section">
            <h3>📧 Configuración de Notificaciones</h3>
            <form method="post">
                <?php wp_nonce_field('crm_admin_settings', 'crm_nonce'); ?>
                <div class="crm-settings-grid">
                    <label>
                        <input type="checkbox" name="admin_notifications" <?php checked($settings['admin_notifications']); ?>>
                        <span>Notificaciones a administradores</span>
                    </label>
                    <label>
                        <input type="checkbox" name="comercial_notifications" <?php checked($settings['comercial_notifications']); ?>>
                        <span>Notificaciones a comerciales</span>
                    </label>
                    <label>
                        <input type="checkbox" name="test_mode" <?php checked($settings['test_mode']); ?>>
                        <span>Modo de prueba</span>
                    </label>
                    <label>
                        <span>Retención de logs (días):</span>
                        <input type="number" name="log_retention_days" value="<?php echo $settings['log_retention_days']; ?>" min="1" max="365" style="width: 80px; padding: 5px;">
                    </label>
                </div>
                <p style="margin-top: 20px;">
                    <button type="submit" name="update_crm_settings" class="crm-btn">💾 Guardar Configuración</button>
                    <button type="button" id="test-email-btn" class="crm-btn">📧 Enviar Email de Prueba</button>
                    <button type="button" id="clean-logs-btn" class="crm-btn crm-btn-danger">🧹 Limpiar Logs Antiguos</button>
                </p>
            </form>
        </div>
        
        <!-- Log de Actividades -->
        <div class="crm-panel-section">
            <h3>📋 Registro de Actividades</h3>
            
            <!-- Selector de mes -->
            <div style="margin-bottom: 15px;">
                <label for="month-selector" style="color: rgb(51, 65, 85); font-weight: 600;">📅 Consultar mes:</label>
                <select id="month-selector" style="margin-left: 10px; padding: 8px; border: 1px solid rgb(203, 213, 225); border-radius: 6px; background: white;">
                    <?php
                    $available_months = crm_get_available_log_months();
                    $current_month = current_time('Y_m');
                    
                    if (empty($available_months)) {
                        echo '<option value="' . $current_month . '">' . date('F Y') . '</option>';
                    }
                    
                    foreach ($available_months as $month_data) {
                        $month = $month_data['value'];
                        $month_label = $month_data['label'];
                        $selected = ($month === $current_month) ? 'selected' : '';
                        echo '<option value="' . $month . '" ' . $selected . '>' . $month_label . '</option>';
                    }
                    ?>
                </select>
                <button id="load-month-logs" class="crm-btn" style="margin-left: 10px; padding: 8px 15px; font-size: 14px;">🔄 Cargar</button>
            </div>
            
            <div id="activity-logs-container">
            <?php
            // Obtener logs del mes actual
            $logs = crm_get_logs_by_month($current_month, 50);
            
            if (empty($logs)) {
                // Registrar acceso al panel para el mes actual
                crm_log_action('panel_consultado', 'Panel de administración consultado');
                crm_log_action('sistema_inicializado', 'Sistema de logs mensuales inicializado para ' . date('F Y'));
                
                // Recargar logs después de la inicialización
                $logs = crm_get_logs_by_month($current_month, 50);
            }
            
            if (empty($logs)) {
                echo '<div style="padding: 20px; text-align: center; background: rgb(248, 250, 252); border-radius: 8px; border: 2px dashed rgb(203, 213, 225);">';
                echo '<p style="margin: 0; color: rgb(71, 85, 105);">📝 No hay actividades registradas este mes.</p>';
                echo '<p style="margin: 10px 0 0 0; font-size: 14px; color: rgb(100, 116, 139);">Las actividades aparecerán aquí cuando uses el sistema.</p>';
                echo '</div>';
            } else {
            ?>
            <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);">
                <table class="crm-log-table">
                    <thead>
                        <tr>
                            <th>👤 Usuario</th>
                            <th>🔹 Acción</th>
                            <th>📝 Detalles</th>
                            <th>🕐 Fecha</th>
                            <th>🌐 IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['user_name']); ?></td>
                        <td>
                            <span class="crm-action-type action-<?php echo esc_attr($log['action_type']); ?>">
                                <?php echo esc_html(crm_get_action_label($log['action_type'])); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log['details']); ?></td>
                        <td>
                            <small>
                                <?php 
                                $fecha = new DateTime($log['created_at']);
                                echo $fecha->format('d/m/Y H:i');
                                ?>
                            </small>
                            <?php if ($log['client_id']): ?>
                                <br><small style="color: rgb(100, 116, 139);">Cliente #<?php echo $log['client_id']; ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($log['ip_address']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php } ?>
            </div> <!-- Cierre del activity-logs-container -->
        </div>
        
        <!-- Herramientas del Sistema -->
        <div class="crm-panel-section">
            <h3>🔧 Herramientas del Sistema</h3>
            <div class="crm-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="/todas-las-altas-de-cliente/" class="crm-btn">📋 Ver Todos los Clientes</a>
                    <a href="/resumen/" class="crm-btn crm-btn-secondary">📊 Resumen de Comerciales</a>
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <button type="button" id="export-data-btn" class="crm-btn">📁 Exportar Datos</button>
                    <button type="button" id="backup-system-btn" class="crm-btn crm-btn-secondary">💾 Backup Sistema</button>
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <button type="button" id="optimize-db-btn" class="crm-btn crm-btn-secondary">⚡ Optimizar BD</button>
                    <button type="button" id="system-info-btn" class="crm-btn crm-btn-secondary">ℹ️ Info Sistema</button>
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <button type="button" id="generate-sample-logs-btn" class="crm-btn" style="background: linear-gradient(135deg, rgb(34, 197, 94) 0%, rgb(22, 163, 74) 100%);">🧪 Generar Logs Prueba</button>
                    <button type="button" id="clear-all-logs-btn" class="crm-btn crm-btn-danger">🗑️ Limpiar Todos</button>
                </div>
            </div>
            
            <!-- Panel de información del sistema -->
            <div id="system-info-panel" style="display: none; margin-top: 20px; padding: 20px; background: rgb(248, 250, 252); border-radius: 8px; border-left: 4px solid rgb(59, 130, 246);">
                <h4 style="margin-top: 0; color: rgb(15, 23, 42);">📊 Información del Sistema</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <strong>WordPress:</strong> <?php echo get_bloginfo('version'); ?><br>
                        <strong>PHP:</strong> <?php echo PHP_VERSION; ?><br>
                        <strong>MySQL:</strong> <?php echo $wpdb->db_version(); ?>
                    </div>
                    <div>
                        <strong>Memoria Límite:</strong> <?php echo ini_get('memory_limit'); ?><br>
                        <strong>Tiempo Ejecución:</strong> <?php echo ini_get('max_execution_time'); ?>s<br>
                        <strong>Upload Max:</strong> <?php echo ini_get('upload_max_filesize'); ?>
                    </div>
                    <div>
                        <strong>Tema Activo:</strong> <?php echo wp_get_theme()->get('Name'); ?><br>
                        <strong>Plugins Activos:</strong> <?php echo count(get_option('active_plugins')); ?><br>
                        <strong>CRM Version:</strong> <?php echo CRM_PLUGIN_VERSION; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Panel de Monitoreo en Tiempo Real -->
        <div class="crm-panel-section">
            <h3>📈 Monitoreo en Tiempo Real</h3>
            <div id="real-time-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                <div style="text-align: center; padding: 15px; background: linear-gradient(135deg, rgb(248, 250, 252) 0%, rgb(241, 245, 249) 100%); border-radius: 8px;">
                    <div style="font-size: 24px; font-weight: bold; color: rgb(15, 23, 42);" id="users-online">0</div>
                    <div style="font-size: 12px; color: rgb(71, 85, 105);">Usuarios Online</div>
                </div>
                <div style="text-align: center; padding: 15px; background: linear-gradient(135deg, rgb(248, 250, 252) 0%, rgb(241, 245, 249) 100%); border-radius: 8px;">
                    <div style="font-size: 24px; font-weight: bold; color: rgb(15, 23, 42);" id="memory-usage">0%</div>
                    <div style="font-size: 12px; color: rgb(71, 85, 105);">Uso de Memoria</div>
                </div>
                <div style="text-align: center; padding: 15px; background: linear-gradient(135deg, rgb(248, 250, 252) 0%, rgb(241, 245, 249) 100%); border-radius: 8px;">
                    <div style="font-size: 24px; font-weight: bold; color: rgb(15, 23, 42);" id="db-size">0 MB</div>
                    <div style="font-size: 12px; color: rgb(71, 85, 105);">Tamaño BD</div>
                </div>
                <div style="text-align: center; padding: 15px; background: linear-gradient(135deg, rgb(248, 250, 252) 0%, rgb(241, 245, 249) 100%); border-radius: 8px;">
                    <div style="font-size: 24px; font-weight: bold; color: rgb(15, 23, 42);" id="last-activity">-</div>
                    <div style="font-size: 12px; color: rgb(71, 85, 105);">Última Actividad</div>
                </div>
            </div>
            <div style="margin-top: 15px; text-align: center;">
                <button type="button" id="refresh-monitoring" class="crm-btn crm-btn-secondary">🔄 Actualizar</button>
                <button type="button" id="auto-refresh-toggle" class="crm-btn">⏰ Auto-refresh: OFF</button>
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

add_action('wp_footer', 'crm_admin_panel_scripts');
function crm_admin_panel_scripts() {
    if (!current_user_can('crm_admin') || !is_page()) return;
    ?>
    <script>
    // Definir ajaxurl para WordPress frontend
    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
    
    jQuery(document).ready(function($) {
        var autoRefreshInterval;
        var autoRefreshEnabled = false;
        
        // Función para actualizar monitoreo en tiempo real
        function updateMonitoring() {
            $.post(ajaxurl, {
                action: 'crm_get_monitoring_data',
                nonce: '<?php echo wp_create_nonce("crm_monitoring"); ?>'
            }, function(response) {
                if (response.success) {
                    $('#users-online').text(response.data.users_online || '0');
                    $('#memory-usage').text(response.data.memory_usage || '0%');
                    $('#db-size').text(response.data.db_size || '0 MB');
                    $('#last-activity').text(response.data.last_activity || '-');
                }
            });
        }
        
        // Botones del panel
        $('#test-email-btn').on('click', function() {
            var btn = $(this);
            btn.text('Enviando...');
            
            $.post(ajaxurl, {
                action: 'crm_send_test_email',
                nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
            }, function(response) {
                alert(response.data || 'Email de prueba enviado');
                btn.text('📧 Enviar Email de Prueba');
            });
        });
        
        $('#clean-logs-btn').on('click', function() {
            if (confirm('¿Estás seguro de limpiar los logs antiguos?')) {
                $.post(ajaxurl, {
                    action: 'crm_clean_old_logs',
                    nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
                }, function(response) {
                    alert(response.data || 'Logs limpiados');
                    location.reload();
                });
            }
        });
        
        $('#export-data-btn').on('click', function() {
            window.open(ajaxurl + '?action=crm_export_data&nonce=<?php echo wp_create_nonce("crm_admin_actions"); ?>', '_blank');
        });
        
        $('#backup-system-btn').on('click', function() {
            var btn = $(this);
            btn.text('Creando backup...');
            
            $.post(ajaxurl, {
                action: 'crm_create_backup',
                nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
            }, function(response) {
                alert(response.data || 'Backup creado');
                btn.text('💾 Backup Sistema');
            });
        });
        
        $('#optimize-db-btn').on('click', function() {
            var btn = $(this);
            btn.text('Optimizando...');
            
            $.post(ajaxurl, {
                action: 'crm_optimize_database',
                nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
            }, function(response) {
                alert(response.data || 'Base de datos optimizada');
                btn.text('⚡ Optimizar BD');
            });
        });
        
        $('#system-info-btn').on('click', function() {
            $('#system-info-panel').slideToggle();
        });
        
        $('#generate-sample-logs-btn').on('click', function() {
            if (confirm('¿Generar logs de prueba para el mes actual?')) {
                $.post(ajaxurl, {
                    action: 'crm_generate_sample_logs',
                    nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
                }, function(response) {
                    alert(response.data || 'Logs de prueba generados');
                    location.reload();
                });
            }
        });
        
        $('#clear-all-logs-btn').on('click', function() {
            if (confirm('¿ELIMINAR TODOS LOS LOGS? Esta acción no se puede deshacer.')) {
                $.post(ajaxurl, {
                    action: 'crm_clear_all_logs',
                    nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
                }, function(response) {
                    alert(response.data || 'Todos los logs eliminados');
                    location.reload();
                });
            }
        });
        
        $('#load-month-logs').on('click', function() {
            var month = $('#month-selector').val();
            loadMonthLogs(month);
        });
        
        function loadMonthLogs(month) {
            $('#activity-logs-container').html('<div style="text-align: center; padding: 20px;">Cargando...</div>');
            
            $.post(ajaxurl, {
                action: 'crm_load_month_logs',
                month: month,
                nonce: '<?php echo wp_create_nonce("crm_admin_actions"); ?>'
            }, function(response) {
                if (response.success) {
                    $('#activity-logs-container').html(response.data.html);
                } else {
                    $('#activity-logs-container').html('<div style="text-align: center; padding: 20px; color: red;">Error al cargar logs</div>');
                }
            });
        }
        
        $('#refresh-monitoring').on('click', function() {
            updateMonitoring();
        });
        
        $('#auto-refresh-toggle').on('click', function() {
            var btn = $(this);
            if (autoRefreshEnabled) {
                clearInterval(autoRefreshInterval);
                autoRefreshEnabled = false;
                btn.text('⏰ Auto-refresh: OFF');
            } else {
                autoRefreshInterval = setInterval(updateMonitoring, 30000); // Cada 30 segundos
                autoRefreshEnabled = true;
                btn.text('⏰ Auto-refresh: ON');
                updateMonitoring(); // Actualizar inmediatamente
            }
        });
        
        // Actualizar monitoreo al cargar
        updateMonitoring();
    });
    </script>
    <?php
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

// Handlers AJAX para el panel de administración
add_action('wp_ajax_crm_send_test_email', 'crm_ajax_send_test_email');
function crm_ajax_send_test_email() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    $user = wp_get_current_user();
    $to = $user->user_email;
    $subject = 'Test Email - CRM Energitel';
    $message = 'Este es un email de prueba enviado desde el panel de administración del CRM.';
    
    $sent = wp_mail($to, $subject, $message);
    
    if ($sent) {
        crm_log_action('test_email_enviado', 'Email de prueba enviado a ' . $to);
        wp_send_json_success('Email de prueba enviado correctamente a ' . $to);
    } else {
        wp_send_json_error('Error al enviar el email de prueba');
    }
}

add_action('wp_ajax_crm_clean_old_logs', 'crm_ajax_clean_old_logs');
function crm_ajax_clean_old_logs() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    $settings = get_option('crm_email_settings', ['log_retention_days' => 30]);
    $days = intval($settings['log_retention_days']);
    
    $available_months = crm_get_available_log_months();
    $total_deleted = 0;
    $tables_processed = 0;
    
    foreach ($available_months as $month_data) {
        $table_name = $month_data['table'];
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM $table_name 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days));
        
        $total_deleted += (int) $deleted;
        $tables_processed++;
    }
    
    crm_log_action('logs_limpiados', "Eliminados $total_deleted logs antiguos de $tables_processed tablas (>$days días)");
    wp_send_json_success("Se eliminaron $total_deleted logs antiguos de $tables_processed tablas");
}

add_action('wp_ajax_crm_export_data', 'crm_ajax_export_data');
function crm_ajax_export_data() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_GET['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    $clients_table = $wpdb->prefix . 'crm_clients';
    
    $clients = $wpdb->get_results("SELECT * FROM $clients_table ORDER BY creado_en DESC", ARRAY_A);
    
    // Configurar headers para Excel XLS
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=crm_export_' . date('Y-m-d') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Iniciar la tabla HTML para Excel
    echo "\xEF\xBB\xBF"; // BOM para UTF-8
    echo '<table border="1">';
    
    if (!empty($clients)) {
        // Escribir encabezados personalizados
        echo '<tr>';
        $headers = [
            'ID', 'Delegado', 'Usuario ID', 'Email Comercial', 'Fecha Creación',
            'Cliente', 'Empresa', 'Dirección', 'Teléfono', 'Email Cliente',
            'Población', 'Provincia', 'Área', 'Tipo', 'Comentarios',
            'Intereses', 'Facturas', 'Presupuestos', 'Contratos', 'Contratos Generados',
            'Contratos Firmados', 'Estado', 'Estados por Sector', 'Fecha Envío por Sector',
            'Usuario Envío por Sector', 'Reenvíos', 'Actualizado'
        ];
        
        foreach ($headers as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
        
        // Escribir datos formateados
        foreach ($clients as $client) {
            echo '<tr>';
            
            // Formatear campos básicos
            echo '<td>' . htmlspecialchars($client['id']) . '</td>';
            echo '<td>' . htmlspecialchars($client['delegado']) . '</td>';
            echo '<td>' . htmlspecialchars($client['user_id']) . '</td>';
            echo '<td>' . htmlspecialchars($client['email_comercial']) . '</td>';
            echo '<td>' . htmlspecialchars($client['fecha']) . '</td>';
            echo '<td>' . htmlspecialchars($client['cliente_nombre']) . '</td>';
            echo '<td>' . htmlspecialchars($client['empresa']) . '</td>';
            echo '<td>' . htmlspecialchars($client['direccion']) . '</td>';
            echo '<td>' . htmlspecialchars($client['telefono']) . '</td>';
            echo '<td>' . htmlspecialchars($client['email_cliente']) . '</td>';
            echo '<td>' . htmlspecialchars($client['poblacion']) . '</td>';
            echo '<td>' . htmlspecialchars($client['provincia']) . '</td>';
            echo '<td>' . htmlspecialchars($client['area']) . '</td>';
            echo '<td>' . htmlspecialchars($client['tipo']) . '</td>';
            echo '<td>' . htmlspecialchars($client['comentarios']) . '</td>';
            
            // Formatear campos serializados como listas legibles
            echo '<td>' . crm_format_array_field($client['intereses']) . '</td>';
            echo '<td>' . crm_format_files_field($client['facturas']) . '</td>';
            echo '<td>' . crm_format_files_field($client['presupuesto']) . '</td>';
            echo '<td>' . crm_format_files_field($client['contratos']) . '</td>';
            echo '<td>' . crm_format_array_field($client['contratos_generados']) . '</td>';
            echo '<td>' . crm_format_files_field($client['contratos_firmados']) . '</td>';
            echo '<td>' . htmlspecialchars($client['estado']) . '</td>';
            echo '<td>' . crm_format_estado_sector_field($client['estado_por_sector']) . '</td>';
            echo '<td>' . crm_format_array_field($client['fecha_envio_por_sector']) . '</td>';
            echo '<td>' . crm_format_array_field($client['usuario_envio_por_sector']) . '</td>';
            echo '<td>' . htmlspecialchars($client['reenvios']) . '</td>';
            echo '<td>' . htmlspecialchars($client['actualizado_en']) . '</td>';
            
            echo '</tr>';
        }
    }
    
    echo '</table>';
    
    crm_log_action('datos_exportados', 'Exportados ' . count($clients) . ' clientes a Excel XLS');
    exit;
}

/**
 * Formatear campos de array para Excel
 */
function crm_format_array_field($field) {
    if (empty($field)) {
        return '-';
    }
    
    $data = maybe_unserialize($field);
    if (!is_array($data)) {
        return htmlspecialchars($field);
    }
    
    if (empty($data)) {
        return '-';
    }
    
    return htmlspecialchars(implode(', ', $data));
}

/**
 * Formatear campos de archivos para Excel
 */
function crm_format_files_field($field) {
    if (empty($field)) {
        return '-';
    }
    
    $data = maybe_unserialize($field);
    if (!is_array($data)) {
        return htmlspecialchars($field);
    }
    
    if (empty($data)) {
        return '-';
    }
    
    $files_list = [];
    foreach ($data as $sector => $files) {
        if (is_array($files) && !empty($files)) {
            $file_names = array_map(function($url) {
                return basename(parse_url($url, PHP_URL_PATH));
            }, $files);
            $files_list[] = $sector . ': ' . implode(', ', $file_names);
        }
    }
    
    return htmlspecialchars(implode(' | ', $files_list));
}

/**
 * Formatear estados por sector para Excel
 */
function crm_format_estado_sector_field($field) {
    if (empty($field)) {
        return '-';
    }
    
    $data = maybe_unserialize($field);
    if (!is_array($data)) {
        return htmlspecialchars($field);
    }
    
    if (empty($data)) {
        return '-';
    }
    
    $estados_list = [];
    foreach ($data as $sector => $estado) {
        $estados_list[] = $sector . ': ' . $estado;
    }
    
    return htmlspecialchars(implode(', ', $estados_list));
}

add_action('wp_ajax_crm_create_backup', 'crm_ajax_create_backup');
function crm_ajax_create_backup() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    
    // Crear directorio de backups si no existe
    $backup_dir = ABSPATH . 'wp-content/crm-backups/';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }
    
    // Nombre del archivo
    $filename = 'crm_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backup_dir . $filename;
    
    // Obtener tablas CRM
    $tables = ['crm_clients'];
    $sql_content = '';
    
    // Agregar tablas de logs mensuales
    $available_months = crm_get_available_log_months();
    foreach ($available_months as $month_data) {
        $table_name_only = str_replace($wpdb->prefix, '', $month_data['table']);
        $tables[] = $table_name_only;
    }
    
    foreach ($tables as $table) {
        $full_table = $wpdb->prefix . $table;
        
        // Estructura de la tabla
        $create_table = $wpdb->get_var("SHOW CREATE TABLE $full_table", 1);
        if ($create_table) {
            $sql_content .= "DROP TABLE IF EXISTS `$full_table`;\n";
            $sql_content .= $create_table . ";\n\n";
            
            // Datos de la tabla
            $rows = $wpdb->get_results("SELECT * FROM $full_table", ARRAY_A);
            foreach ($rows as $row) {
                $values = array_map(function($value) use ($wpdb) {
                    return is_null($value) ? 'NULL' : "'" . $wpdb->_escape($value) . "'";
                }, array_values($row));
                
                $sql_content .= "INSERT INTO `$full_table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql_content .= "\n";
        }
    }
    
    file_put_contents($filepath, $sql_content);
    
    crm_log_action('backup_creado', 'Backup creado: ' . $filename);
    wp_send_json_success('Backup creado exitosamente: ' . $filename);
}

add_action('wp_ajax_crm_optimize_database', 'crm_ajax_optimize_database');
function crm_ajax_optimize_database() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    
    // Optimizar tablas CRM
    $tables = ['crm_clients'];
    $optimized = 0;
    
    // Agregar tablas de logs mensuales
    $available_months = crm_get_available_log_months();
    foreach ($available_months as $month_data) {
        $table_name_only = str_replace($wpdb->prefix, '', $month_data['table']);
        $tables[] = $table_name_only;
    }
    
    foreach ($tables as $table) {
        $full_table = $wpdb->prefix . $table;
        $result = $wpdb->query("OPTIMIZE TABLE $full_table");
        if ($result) $optimized++;
    }
    
    crm_log_action('bd_optimizada', "Optimizadas $optimized tablas CRM");
    wp_send_json_success("Base de datos optimizada - $optimized tablas procesadas");
}

add_action('wp_ajax_crm_generate_sample_logs', 'crm_ajax_generate_sample_logs');
function crm_ajax_generate_sample_logs() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    // Generar logs de prueba
    $sample_actions = [
        'cliente_creado' => 'Cliente de prueba creado',
        'email_enviado' => 'Email promocional enviado',
        'sectores_enviados' => 'Sectores enviados por comercial',
        'cliente_actualizado' => 'Datos de cliente actualizados'
    ];
    
    $generated = 0;
    foreach ($sample_actions as $action => $detail) {
        for ($i = 0; $i < 3; $i++) {
            crm_log_action($action, $detail . ' #' . ($i + 1));
            $generated++;
        }
    }
    
    wp_send_json_success("Generados $generated logs de prueba");
}

add_action('wp_ajax_crm_clear_all_logs', 'crm_ajax_clear_all_logs');
function crm_ajax_clear_all_logs() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    
    // Obtener todas las tablas de logs mensuales
    $available_months = crm_get_available_log_months();
    $total_count = 0;
    $cleared_tables = 0;
    
    foreach ($available_months as $month_data) {
        $table_name = $month_data['table'];
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_count += (int) $count;
        
        $wpdb->query("TRUNCATE TABLE $table_name");
        $cleared_tables++;
    }
    
    // Registrar la limpieza
    crm_log_action('logs_limpiados', "Eliminados todos los logs de $cleared_tables tablas mensuales ($total_count registros)");
    
    wp_send_json_success("Todos los logs eliminados de $cleared_tables tablas ($total_count registros)");
}

add_action('wp_ajax_crm_load_month_logs', 'crm_ajax_load_month_logs');
function crm_ajax_load_month_logs() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_admin_actions')) {
        wp_die('Sin permisos');
    }
    
    $month = sanitize_text_field($_POST['month']);
    $logs = crm_get_logs_by_month($month, 50);
    
    if (empty($logs)) {
        $html = '<div style="padding: 20px; text-align: center; background: rgb(248, 250, 252); border-radius: 8px; border: 2px dashed rgb(203, 213, 225);">';
        $html .= '<p style="margin: 0; color: rgb(71, 85, 105);">📝 No hay actividades registradas en este mes.</p>';
        $html .= '</div>';
    } else {
        $html = '<div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);">';
        $html .= '<table class="crm-log-table">';
        $html .= '<thead><tr>';
        $html .= '<th>👤 Usuario</th><th>🔹 Acción</th><th>📝 Detalles</th><th>🕐 Fecha</th><th>🌐 IP</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($logs as $log) {
            $fecha = new DateTime($log['created_at']);
            $html .= '<tr>';
            $html .= '<td>' . esc_html($log['user_name']) . '</td>';
            $html .= '<td><span class="crm-action-type action-' . esc_attr($log['action_type']) . '">';
            $html .= esc_html(crm_get_action_label($log['action_type'])) . '</span></td>';
            $html .= '<td>' . esc_html($log['details']) . '</td>';
            $html .= '<td><small>' . $fecha->format('d/m/Y H:i') . '</small>';
            if ($log['client_id']) {
                $html .= '<br><small style="color: rgb(100, 116, 139);">Cliente #' . $log['client_id'] . '</small>';
            }
            $html .= '</td>';
            $html .= '<td>' . esc_html($log['ip_address']) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></div>';
    }
    
    wp_send_json_success(['html' => $html]);
}

// Función auxiliar para convertir memoria
function crm_convert_to_bytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value)-1]);
    $value = (int)$value;
    
    switch($last) {
        case 'g': $value *= 1024;
        case 'm': $value *= 1024;
        case 'k': $value *= 1024;
    }
    
    return $value;
}
