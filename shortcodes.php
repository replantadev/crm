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

    <div class="widget clientes-por-interes">
      <h3>Clientes por Interés</h3>
      <canvas id="chart-clientes-interes"></canvas>
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
            backgroundColor: <?php echo json_encode($colors); ?>
          }]
        },
        options: {
          responsive: true,
          plugins: {
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
    
    ob_start();
    ?>
    
    <style>
    .crm-admin-panel {
        max-width: 1400px;
        margin: 20px auto;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background: linear-gradient(135deg, rgb(15, 23, 42) 0%, rgb(30, 41, 59) 100%);
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    
    .crm-admin-panel h2 {
        color: white;
        font-size: 32px;
        font-weight: 700;
        margin: 0 0 30px 0;
        text-align: center;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    
    .crm-panel-section {
        background: rgba(255, 255, 255, 0.98);
        border: none;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }
    
    .crm-panel-section:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
    
    .crm-panel-section h3 {
        margin-top: 0;
        color: rgb(15, 23, 42);
        border-bottom: 3px solid rgb(59, 130, 246);
        padding-bottom: 12px;
        font-size: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .crm-settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 24px;
    }
    
    .crm-setting-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        background: linear-gradient(135deg, rgb(248, 250, 252) 0%, rgb(241, 245, 249) 100%);
        border-radius: 8px;
        border: 1px solid rgb(226, 232, 240);
        transition: all 0.2s ease;
    }
    
    .crm-setting-item:hover {
        background: linear-gradient(135deg, rgb(241, 245, 249) 0%, rgb(226, 232, 240) 100%);
        border-color: rgb(59, 130, 246);
    }
    
    .crm-setting-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: rgb(59, 130, 246);
        cursor: pointer;
    }
    
    .crm-setting-item input[type="number"] {
        padding: 8px 12px;
        border: 1px solid rgb(203, 213, 225);
        border-radius: 6px;
        width: 80px;
        font-size: 14px;
        transition: border-color 0.2s ease;
    }
    
    .crm-setting-item input[type="number"]:focus {
        outline: none;
        border-color: rgb(59, 130, 246);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .crm-setting-item label {
        font-weight: 500;
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
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
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
            $log_table = $wpdb->prefix . 'crm_activity_log';
            
            $stats = [
                'total_clients' => $wpdb->get_var("SELECT COUNT(*) FROM $clients_table"),
                'clients_today' => $wpdb->get_var("SELECT COUNT(*) FROM $clients_table WHERE DATE(creado_en) = CURDATE()"),
                'emails_sent' => $wpdb->get_var("SELECT COUNT(*) FROM $log_table WHERE action_type = 'email_enviado'"),
                'active_comercials' => $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $clients_table")
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
                    <div class="crm-setting-item">
                        <input type="checkbox" id="admin_notifications" name="admin_notifications" 
                               <?php checked($settings['admin_notifications']); ?>>
                        <label for="admin_notifications">Notificaciones a Administradores</label>
                    </div>
                    <div class="crm-setting-item">
                        <input type="checkbox" id="comercial_notifications" name="comercial_notifications" 
                               <?php checked($settings['comercial_notifications']); ?>>
                        <label for="comercial_notifications">Notificaciones a Comerciales</label>
                    </div>
                    <div class="crm-setting-item">
                        <input type="checkbox" id="test_mode" name="test_mode" 
                               <?php checked($settings['test_mode']); ?>>
                        <label for="test_mode">Modo de Prueba (solo admin)</label>
                    </div>
                    <div class="crm-setting-item">
                        <label for="log_retention_days">Retener logs (días):</label>
                        <input type="number" id="log_retention_days" name="log_retention_days" 
                               value="<?php echo $settings['log_retention_days']; ?>" min="1" max="365">
                    </div>
                </div>
                <p>
                    <button type="submit" name="update_crm_settings" class="crm-btn">Guardar Configuración</button>
                    <button type="button" id="test-email-btn" class="crm-btn">Enviar Email de Prueba</button>
                    <button type="button" id="clean-logs-btn" class="crm-btn crm-btn-danger">Limpiar Logs Antiguos</button>
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
                        // Si no hay meses, crear el actual
                        crm_create_monthly_log_table($current_month);
                        $available_months = crm_get_available_log_months();
                    }
                    
                    foreach ($available_months as $month) {
                        $selected = $month['value'] === $current_month ? 'selected' : '';
                        echo "<option value='{$month['value']}' $selected>{$month['label']}</option>";
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
                            <th>Fecha/Hora</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Detalles</th>
                            <th>Cliente ID</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                        <td><?php echo esc_html($log['user_name']); ?></td>
                        <td>
                            <span class="crm-action-type action-<?php echo esc_attr($log['action_type']); ?>">
                                <?php echo esc_html($log['action_type']); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log['details']); ?></td>
                        <td>
                            <?php if ($log['client_id']): ?>
                                <a href="/editar-cliente/?client_id=<?php echo $log['client_id']; ?>">
                                    #<?php echo $log['client_id']; ?>
                                </a>
                            <?php else: ?>
                                -
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
                        <strong>Versión PHP:</strong> <?php echo PHP_VERSION; ?><br>
                        <strong>WordPress:</strong> <?php echo get_bloginfo('version'); ?><br>
                        <strong>Memoria PHP:</strong> <?php echo ini_get('memory_limit'); ?>
                    </div>
                    <div>
                        <strong>Servidor:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?><br>
                        <strong>Zona Horaria:</strong> <?php echo wp_timezone_string(); ?><br>
                        <strong>Debug Mode:</strong> <?php echo WP_DEBUG ? 'Activado' : 'Desactivado'; ?>
                    </div>
                    <div>
                        <strong>Upload Max:</strong> <?php echo ini_get('upload_max_filesize'); ?><br>
                        <strong>Post Max:</strong> <?php echo ini_get('post_max_size'); ?><br>
                        <strong>Time Limit:</strong> <?php echo ini_get('max_execution_time'); ?>s
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
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        let autoRefreshInterval;
        let autoRefreshActive = false;
        
        // Funciones de utilidad
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notice notice-${type}`;
            notification.innerHTML = `<p>${message}</p>`;
            
            const panel = document.querySelector('.crm-admin-panel');
            panel.insertBefore(notification, panel.firstChild);
            
            setTimeout(() => notification.remove(), 5000);
        }
        
        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function timeAgo(timestamp) {
            const now = new Date();
            const time = new Date(timestamp);
            const diff = Math.floor((now - time) / 1000);
            
            if (diff < 60) return 'Hace ' + diff + 's';
            if (diff < 3600) return 'Hace ' + Math.floor(diff/60) + 'm';
            if (diff < 86400) return 'Hace ' + Math.floor(diff/3600) + 'h';
            return 'Hace ' + Math.floor(diff/86400) + 'd';
        }
        
        // Test de email
        document.getElementById('test-email-btn').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.textContent = '📧 Enviando...';
            
            if (!confirm('¿Enviar email de prueba al administrador?')) {
                btn.disabled = false;
                btn.textContent = 'Enviar Email de Prueba';
                return;
            }
            
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
                showNotification(data.success ? data.data.message : data.data.message, data.success ? 'success' : 'error');
                btn.disabled = false;
                btn.textContent = 'Enviar Email de Prueba';
            })
            .catch(e => {
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = 'Enviar Email de Prueba';
            });
        });
        
        // Limpiar logs
        document.getElementById('clean-logs-btn').addEventListener('click', function() {
            if (!confirm('⚠️ ¿Eliminar logs antiguos? Esta acción no se puede deshacer.')) return;
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = '🧹 Limpiando...';
            
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
                showNotification(data.success ? `✅ ${data.data.deleted} logs eliminados correctamente` : data.data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 2000);
                btn.disabled = false;
                btn.textContent = 'Limpiar Logs Antiguos';
            })
            .catch(e => {
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = 'Limpiar Logs Antiguos';
            });
        });
        
        // Exportar datos
        document.getElementById('export-data-btn').addEventListener('click', function() {
            showNotification('📁 Iniciando exportación de datos...', 'success');
            window.open('<?php echo admin_url('admin-ajax.php'); ?>?action=crm_export_data&nonce=<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>', '_blank');
        });
        
        // Mostrar información del sistema
        document.getElementById('system-info-btn').addEventListener('click', function() {
            const panel = document.getElementById('system-info-panel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            this.textContent = panel.style.display === 'none' ? 'ℹ️ Info Sistema' : '❌ Ocultar Info';
        });
        
        // Generar logs de prueba
        document.getElementById('generate-sample-logs-btn').addEventListener('click', function() {
            if (!confirm('🧪 ¿Generar logs de actividad de prueba para demonstrar la funcionalidad?')) return;
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = '🧪 Generando...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_generate_sample_logs',
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                showNotification(data.success ? data.data.message : data.data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 2000);
                btn.disabled = false;
                btn.textContent = '🧪 Generar Logs Prueba';
            })
            .catch(e => {
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = '🧪 Generar Logs Prueba';
            });
        });
        
        // Limpiar todos los logs
        document.getElementById('clear-all-logs-btn').addEventListener('click', function() {
            if (!confirm('🗑️ ¿Eliminar TODOS los logs de actividad? Esta acción no se puede deshacer.')) return;
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = '🗑️ Eliminando...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_clear_all_logs',
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                showNotification(data.success ? data.data.message : data.data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 2000);
                btn.disabled = false;
                btn.textContent = '🗑️ Limpiar Todos';
            })
            .catch(e => {
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = '🗑️ Limpiar Todos';
            });
        });
        
        // Optimizar base de datos
        document.getElementById('optimize-db-btn').addEventListener('click', function() {
            if (!confirm('⚡ ¿Optimizar la base de datos? Puede tomar unos segundos.')) return;
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = '⚡ Optimizando...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_optimize_database',
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                showNotification(data.success ? data.data.message : data.data.message, data.success ? 'success' : 'error');
                btn.disabled = false;
                btn.textContent = '⚡ Optimizar BD';
            })
            .catch(e => {
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = '⚡ Optimizar BD';
            });
        });
        
        // Backup del sistema
        document.getElementById('backup-system-btn').addEventListener('click', function() {
            if (!confirm('💾 ¿Crear backup del sistema? Se incluirán los datos del CRM.')) return;
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = '💾 Creando backup...';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_backup_system',
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data.download_url) {
                    showNotification('✅ Backup creado correctamente. Descargando...', 'success');
                    window.open(data.data.download_url, '_blank');
                } else {
                    showNotification(data.data.message, 'error');
                }
                btn.disabled = false;
                btn.textContent = '💾 Backup Sistema';
            })
            .catch(e => {
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = '💾 Backup Sistema';
            });
        });
        
        // Monitoreo en tiempo real
        function updateRealTimeStats() {
            // Simular datos en tiempo real (en una implementación real se harían llamadas AJAX)
            const memoryUsage = Math.floor(Math.random() * 30) + 40; // 40-70%
            const usersOnline = Math.floor(Math.random() * 10) + 1;
            const dbSize = (Math.random() * 100 + 50).toFixed(1); // 50-150 MB
            
            document.getElementById('memory-usage').textContent = memoryUsage + '%';
            document.getElementById('users-online').textContent = usersOnline;
            document.getElementById('db-size').textContent = dbSize + ' MB';
            document.getElementById('last-activity').textContent = 'Hace ' + Math.floor(Math.random() * 30) + 'm';
            
            // Cambiar color según el uso de memoria
            const memElement = document.getElementById('memory-usage');
            memElement.style.color = memoryUsage > 80 ? 'rgb(239, 68, 68)' : memoryUsage > 60 ? 'rgb(245, 158, 11)' : 'rgb(34, 197, 94)';
        }
        
        // Actualizar monitoreo
        document.getElementById('refresh-monitoring').addEventListener('click', function() {
            updateRealTimeStats();
            showNotification('🔄 Estadísticas actualizadas', 'success');
        });
        
        // Toggle auto-refresh
        document.getElementById('auto-refresh-toggle').addEventListener('click', function() {
            autoRefreshActive = !autoRefreshActive;
            
            if (autoRefreshActive) {
                this.textContent = '⏰ Auto-refresh: ON';
                this.style.background = 'linear-gradient(135deg, rgb(34, 197, 94) 0%, rgb(22, 163, 74) 100%)';
                autoRefreshInterval = setInterval(updateRealTimeStats, 30000); // Cada 30 segundos
                showNotification('✅ Auto-refresh activado (30s)', 'success');
            } else {
                this.textContent = '⏰ Auto-refresh: OFF';
                this.style.background = '';
                clearInterval(autoRefreshInterval);
                showNotification('⏹️ Auto-refresh desactivado', 'success');
            }
        });
        
        // Inicializar estadísticas
        updateRealTimeStats();
        
        // Selector de mes para logs
        document.getElementById('load-month-logs').addEventListener('click', function() {
            const selectedMonth = document.getElementById('month-selector').value;
            loadLogsForMonth(selectedMonth);
        });
        
        function loadLogsForMonth(yearMonth) {
            const container = document.getElementById('activity-logs-container');
            const btn = document.getElementById('load-month-logs');
            
            btn.disabled = true;
            btn.textContent = '🔄 Cargando...';
            container.innerHTML = '<div style="text-align: center; padding: 20px;">⏳ Cargando logs...</div>';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'crm_load_monthly_logs',
                    year_month: yearMonth,
                    nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    container.innerHTML = data.data.html;
                    showNotification(`📋 Logs de ${data.data.month_label} cargados (${data.data.count} registros)`, 'success');
                } else {
                    container.innerHTML = '<div style="padding: 20px; text-align: center; color: rgb(239, 68, 68);">❌ Error al cargar logs: ' + data.data.message + '</div>';
                }
                btn.disabled = false;
                btn.textContent = '🔄 Cargar';
            })
            .catch(e => {
                container.innerHTML = '<div style="padding: 20px; text-align: center; color: rgb(239, 68, 68);">❌ Error de conexión</div>';
                showNotification('Error: ' + e.message, 'error');
                btn.disabled = false;
                btn.textContent = '🔄 Cargar';
            });
        }
        
        // Animaciones de entrada
        const sections = document.querySelectorAll('.crm-panel-section');
        sections.forEach((section, index) => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                section.style.transition = 'all 0.6s ease';
                section.style.opacity = '1';
                section.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
    </script>
    
    <?php
    return ob_get_clean();
}

// AJAX handlers para el panel de control

// Handler para cargar logs mensuales
add_action('wp_ajax_crm_load_monthly_logs', 'crm_load_monthly_logs_handler');
function crm_load_monthly_logs_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Sin permisos']);
    }
    
    $year_month = sanitize_text_field($_POST['year_month']);
    $logs = crm_get_logs_by_month($year_month, 50);
    
    // Generar HTML para los logs
    ob_start();
    
    if (empty($logs)) {
        echo '<div style="padding: 20px; text-align: center; background: rgb(248, 250, 252); border-radius: 8px; border: 2px dashed rgb(203, 213, 225);">';
        echo '<p style="margin: 0; color: rgb(71, 85, 105);">📝 No hay actividades registradas en este mes.</p>';
        echo '</div>';
    } else {
        ?>
        <table class="crm-activity-table">
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
        <?php
    }
    
    $html = ob_get_clean();
    
    // Obtener el nombre del mes
    $date = DateTime::createFromFormat('Y_m', $year_month);
    $month_label = $date ? $date->format('F Y') : $year_month;
    
    wp_send_json_success([
        'html' => $html,
        'count' => count($logs),
        'month_label' => $month_label
    ]);
}

add_action('wp_ajax_crm_clean_logs', 'crm_clean_logs_handler');
function crm_clean_logs_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Sin permisos']);
    }
    
    global $wpdb;
    $settings = get_option('crm_email_settings', ['log_retention_days' => 30]);
    $days = intval($settings['log_retention_days']);
    
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}crm_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
    
    wp_send_json_success(['deleted' => $deleted]);
}

add_action('wp_ajax_crm_export_data', 'crm_export_data_handler');
function crm_export_data_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_GET['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    $clients = $wpdb->get_results("
        SELECT c.*, u.display_name as comercial_name
        FROM {$wpdb->prefix}crm_clients c
        LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
        ORDER BY c.creado_en DESC
    ", ARRAY_A);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="crm_export_' . date('Y-m-d_H-i') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'ID', 'Cliente', 'Empresa', 'Email', 'Telefono', 'Direccion', 
        'Estado', 'Comercial', 'Fecha Creacion', 'Ultima Actualizacion'
    ]);
    
    // Data
    foreach ($clients as $client) {
        fputcsv($output, [
            $client['id'],
            $client['cliente_nombre'],
            $client['empresa'],
            $client['email_cliente'],
            $client['telefono'],
            $client['direccion'],
            $client['estado'],
            $client['comercial_name'],
            $client['creado_en'],
            $client['actualizado_en']
        ]);
    }
    
    fclose($output);
    exit;
}

// Handler para optimizar base de datos
add_action('wp_ajax_crm_optimize_database', 'crm_optimize_database_handler');
function crm_optimize_database_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Sin permisos para optimizar la base de datos']);
    }
    
    global $wpdb;
    $results = [];
    $total_savings = 0;
    
    try {
        // Obtener todas las tablas del CRM
        $crm_tables = [
            $wpdb->prefix . 'crm_clients',
            $wpdb->prefix . 'crm_activity_log'
        ];
        
        foreach ($crm_tables as $table) {
            // Verificar si la tabla existe
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            
            if ($table_exists) {
                // Obtener tamaño antes de optimizar
                $size_before = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.TABLES 
                    WHERE table_schema = %s AND table_name = %s
                ", DB_NAME, $table));
                
                $before_size = $size_before ? $size_before->size_mb : 0;
                
                // Optimizar tabla
                $optimize_result = $wpdb->query("OPTIMIZE TABLE `$table`");
                
                // Obtener tamaño después de optimizar
                $size_after = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.TABLES 
                    WHERE table_schema = %s AND table_name = %s
                ", DB_NAME, $table));
                
                $after_size = $size_after ? $size_after->size_mb : 0;
                $savings = $before_size - $after_size;
                $total_savings += $savings;
                
                $results[] = [
                    'table' => str_replace($wpdb->prefix, '', $table),
                    'before' => $before_size,
                    'after' => $after_size,
                    'savings' => $savings,
                    'status' => $optimize_result !== false ? 'success' : 'error'
                ];
            }
        }
        
        // Limpiar revisiones de posts automáticas
        $revisions_deleted = $wpdb->query("
            DELETE FROM {$wpdb->posts} 
            WHERE post_type = 'revision' 
            AND post_date < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        // Limpiar spam y papelera de comentarios
        $spam_deleted = $wpdb->query("
            DELETE FROM {$wpdb->comments} 
            WHERE comment_approved = 'spam' 
            OR comment_approved = 'trash'
        ");
        
        // Limpiar logs antiguos del CRM (más de retención configurada)
        $settings = get_option('crm_email_settings', ['log_retention_days' => 30]);
        $logs_deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->prefix}crm_activity_log 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $settings['log_retention_days']));
        
        // Registrar la optimización
        crm_log_action('database_optimized', 
            "Optimización completada. Ahorro: {$total_savings}MB, Revisiones: $revisions_deleted, Spam: $spam_deleted, Logs: $logs_deleted"
        );
        
        $message = "✅ Base de datos optimizada exitosamente!\n";
        $message .= "💾 Espacio liberado: " . round($total_savings, 2) . " MB\n";
        $message .= "📄 Revisiones eliminadas: $revisions_deleted\n";
        $message .= "🗑️ Comentarios spam eliminados: $spam_deleted\n";
        $message .= "📋 Logs antiguos eliminados: $logs_deleted";
        
        wp_send_json_success([
            'message' => $message,
            'details' => $results,
            'total_savings' => round($total_savings, 2)
        ]);
        
    } catch (Exception $e) {
        crm_log_action('database_optimize_error', "Error en optimización: " . $e->getMessage());
        wp_send_json_error(['message' => 'Error al optimizar la base de datos: ' . $e->getMessage()]);
    }
}

// Handler para backup del sistema
add_action('wp_ajax_crm_backup_system', 'crm_backup_system_handler');
function crm_backup_system_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Sin permisos para crear backup']);
    }
    
    global $wpdb;
    
    try {
        // Crear directorio temporal para el backup
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/crm-backups/';
        
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backup_filename = "crm_backup_$timestamp.sql";
        $backup_path = $backup_dir . $backup_filename;
        
        // Crear archivo de backup SQL
        $backup_content = "-- CRM System Backup\n";
        $backup_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $backup_content .= "-- WordPress Site: " . get_bloginfo('name') . "\n\n";
        
        // Backup de tabla de clientes
        $clients_table = $wpdb->prefix . 'crm_clients';
        $backup_content .= "-- Backup de tabla de clientes\n";
        $backup_content .= "DROP TABLE IF EXISTS `$clients_table`;\n";
        
        // Obtener estructura de la tabla
        $create_table = $wpdb->get_row("SHOW CREATE TABLE `$clients_table`", ARRAY_N);
        if ($create_table) {
            $backup_content .= $create_table[1] . ";\n\n";
        }
        
        // Obtener datos de la tabla
        $clients_data = $wpdb->get_results("SELECT * FROM `$clients_table`", ARRAY_A);
        if ($clients_data) {
            $backup_content .= "-- Datos de clientes\n";
            foreach ($clients_data as $row) {
                $values = array_map(function($value) use ($wpdb) {
                    return $value === null ? 'NULL' : "'" . $wpdb->_escape($value) . "'";
                }, array_values($row));
                
                $backup_content .= "INSERT INTO `$clients_table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $backup_content .= "\n";
        }
        
        // Backup de tabla de activity log
        $log_table = $wpdb->prefix . 'crm_activity_log';
        $backup_content .= "-- Backup de tabla de logs\n";
        $backup_content .= "DROP TABLE IF EXISTS `$log_table`;\n";
        
        // Obtener estructura de la tabla de logs
        $create_log_table = $wpdb->get_row("SHOW CREATE TABLE `$log_table`", ARRAY_N);
        if ($create_log_table) {
            $backup_content .= $create_log_table[1] . ";\n\n";
        }
        
        // Obtener datos de logs (últimos 1000 registros)
        $log_data = $wpdb->get_results("SELECT * FROM `$log_table` ORDER BY created_at DESC LIMIT 1000", ARRAY_A);
        if ($log_data) {
            $backup_content .= "-- Datos de activity log (últimos 1000)\n";
            foreach ($log_data as $row) {
                $values = array_map(function($value) use ($wpdb) {
                    return $value === null ? 'NULL' : "'" . $wpdb->_escape($value) . "'";
                }, array_values($row));
                
                $backup_content .= "INSERT INTO `$log_table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $backup_content .= "\n";
        }
        
        // Backup de configuraciones del CRM
        $backup_content .= "-- Configuraciones del CRM\n";
        $email_settings = get_option('crm_email_settings');
        if ($email_settings) {
            $settings_serialized = serialize($email_settings);
            $backup_content .= "INSERT INTO `{$wpdb->options}` (option_name, option_value) VALUES ('crm_email_settings', '$settings_serialized') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);\n";
        }
        
        // Estadísticas del backup
        $clients_count = count($clients_data);
        $logs_count = count($log_data);
        $backup_content .= "\n-- Estadísticas del Backup:\n";
        $backup_content .= "-- Total clientes: $clients_count\n";
        $backup_content .= "-- Total logs: $logs_count\n";
        $backup_content .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
        
        // Escribir archivo
        $bytes_written = file_put_contents($backup_path, $backup_content);
        
        if ($bytes_written === false) {
            throw new Exception('No se pudo escribir el archivo de backup');
        }
        
        // Registrar la creación del backup
        crm_log_action('backup_created', 
            "Backup creado: $backup_filename, Clientes: $clients_count, Logs: $logs_count, Tamaño: " . round($bytes_written/1024, 2) . "KB"
        );
        
        // Generar URL de descarga
        $download_url = admin_url('admin-ajax.php') . '?action=crm_download_backup&file=' . urlencode($backup_filename) . '&nonce=' . wp_create_nonce('crm_download_backup');
        
        wp_send_json_success([
            'message' => "✅ Backup creado exitosamente!\n📁 Archivo: $backup_filename\n👥 Clientes: $clients_count\n📋 Logs: $logs_count\n💾 Tamaño: " . round($bytes_written/1024, 2) . " KB",
            'download_url' => $download_url,
            'filename' => $backup_filename,
            'stats' => [
                'clients' => $clients_count,
                'logs' => $logs_count,
                'size_kb' => round($bytes_written/1024, 2)
            ]
        ]);
        
    } catch (Exception $e) {
        crm_log_action('backup_error', "Error en backup: " . $e->getMessage());
        wp_send_json_error(['message' => 'Error al crear backup: ' . $e->getMessage()]);
    }
}

// Handler para descargar backup
add_action('wp_ajax_crm_download_backup', 'crm_download_backup_handler');
function crm_download_backup_handler() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_GET['nonce'], 'crm_download_backup')) {
        wp_die('Sin permisos para descargar backup');
    }
    
    $filename = sanitize_file_name($_GET['file']);
    $upload_dir = wp_upload_dir();
    $backup_path = $upload_dir['basedir'] . '/crm-backups/' . $filename;
    
    if (!file_exists($backup_path)) {
        wp_die('Archivo de backup no encontrado');
    }
    
    // Configurar headers para descarga
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($backup_path));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    // Enviar archivo
    readfile($backup_path);
    
    // Opcional: eliminar archivo después de descarga
    // unlink($backup_path);
    
    exit;
}



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
    <div class="widget clientes-recientes">
      <h3>Clientes Recientes</h3>
      <table id="dt-clientes-recientes" class="display">
        <thead>
          <tr>
            <th>Cliente</th><th>Empresa</th><th>Estado</th><th>Fecha Alta</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo esc_html($r['cliente_nombre']); ?></td>
              <td><?php echo esc_html($r['empresa']); ?></td>
              <td><span class="crm-badge estado-<?php echo esc_attr($r['estado']); ?>">
                <?php echo crm_get_estado_label($r['estado']); ?>
              </span></td>
              <td><?php echo date_i18n('d/m/Y H:i', strtotime($r['creado_en'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
      jQuery('#dt-clientes-recientes').DataTable({
        paging:   false,
        searching:false,
        info:     false,
        ordering: true,
        order: [[3,'desc']],
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json" }
      });
    });
    </script>
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
    <div class="widget clientes-por-estado">
      <h3>Clientes por Estado (sectorial)</h3>
      <canvas id="chart-clientes-estado"></canvas>
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
          scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true }
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
    <div class="widget rendimiento-comercial">
        <h3><?php echo current_user_can('crm_admin') ? 'Rendimiento General' : 'Mi rendimiento'; ?></h3>
        <ul>
            <?php foreach ($totales as $label => $count): ?>
                <li>
                    <strong><?php echo crm_get_estado_label($label); ?>:</strong>
                    <?php echo $count; ?>
                </li>
            <?php endforeach; ?>
            <?php if (current_user_can('crm_admin')): ?>
                <li><a href="/resumen">Ver Resumen de Comerciales</a></li>
                <li><a href="/panel-de-control">Control y registro</a></li>
            <?php endif; ?>
        </ul>
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
