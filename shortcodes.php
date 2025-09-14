<?php
// Shortcode para clientes por interés
add_shortcode('crm_clientes_por_interes', 'crm_clientes_por_interes_widget');
function crm_clientes_por_interes_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }

    global $wpdb;
    $current_user_id = get_current_user_id();
    $table_name = $wpdb->prefix . "crm_clients";

    // Si el usuario es 'crm_admin', obtener estadísticas generales
    if (current_user_can('crm_admin')) {
        // Obtener distribución de intereses de todos los clientes
        $clientes = $wpdb->get_results("SELECT intereses FROM $table_name", ARRAY_A);
    } else {
        // Obtener distribución de intereses solo para el usuario comercial
        $clientes = $wpdb->get_results($wpdb->prepare("SELECT intereses FROM $table_name WHERE user_id = %d", $current_user_id), ARRAY_A);
    }

    // Inicializar los intereses
    $intereses = ['energia' => 0, 'alarmas' => 0, 'telecomunicaciones' => 0, 'seguros' => 0];
    foreach ($clientes as $cliente) {
        $cliente_intereses = maybe_unserialize($cliente['intereses']);
        foreach ($cliente_intereses as $interes) {
            if (isset($intereses[$interes])) {
                $intereses[$interes]++;
            }
        }
    }

    // Contar el total de clientes para cada interés
    $total_clientes = count($clientes);

    // Generar HTML y datos para Chart.js
    ob_start();
    ?>
    <div class="widget clientes-por-interes">
        <h3>Clientes por Interés (Total: <?php echo $total_clientes; ?>)</h3>
        <canvas id="clientesPorInteresChart"></canvas>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById('clientesPorInteresChart').getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: [
                            'Energía (<?php echo $intereses['energia']; ?>)',
                            'Alarmas (<?php echo $intereses['alarmas']; ?>)',
                            'Telecomunicaciones (<?php echo $intereses['telecomunicaciones']; ?>)',
                            'Seguros (<?php echo $intereses['seguros']; ?>)'
                        ],
                        datasets: [{
                            data: [<?php echo implode(',', array_values($intereses)); ?>],
                            backgroundColor: ['#4caf50', '#f44336', '#2196f3', '#ff9800'],
                        }]
                    },
                });
            });
        </script>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode para rendimiento comercial
add_shortcode('crm_rendimiento_comercial', 'crm_rendimiento_comercial_widget');
function crm_rendimiento_comercial_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }

    global $wpdb;
    $current_user_id = get_current_user_id();
    $table_name = $wpdb->prefix . "crm_clients";

    // Si el usuario es 'crm_admin', obtener estadísticas generales
    if (current_user_can('crm_admin')) {
        // Estadísticas generales para todos los usuarios
        $total_clientes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $enviados = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE estado = 'enviado'");
        $pendientes = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE estado = 'pendiente_revision'");
        $firmados = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE estado = 'contratos_firmados'");

        // Total de usuarios comerciales
        $total_comerciales = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name");
    } else {
        // Estadísticas solo para el usuario comercial
        $total_clientes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $current_user_id));
        $enviados = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND estado = 'enviado'", $current_user_id));
        $pendientes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND estado = 'pendiente_revision'", $current_user_id));
        $firmados = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND estado = 'contratos_firmados'", $current_user_id));
    }

    // Generar HTML
    ob_start();
    ?>
    <div class="widget rendimiento-comercial">
        <h3><?php echo current_user_can('crm_admin') ? 'Rendimiento General' : 'Rendimiento del Comercial'; ?></h3>
        <?php if (current_user_can('crm_admin')): ?>
            <ul>
                <li><strong>Total de Comerciales:</strong> <?php echo $total_comerciales; ?></li>
                <li><strong>Total de Clientes:</strong> <?php echo $total_clientes; ?></li>
                <li><strong>Enviados:</strong> <?php echo $enviados; ?></li>
                <li><strong>Pendientes de Revisión:</strong> <?php echo $pendientes; ?></li>
                <li><strong>Contratos Firmados:</strong> <?php echo $firmados; ?></li>
            
                <li><a href="/resumen">Ver Resumen de Comerciales</a></li>
            </ul>
        <?php else: ?>
            <ul>
                <li><strong>Total de Clientes:</strong> <?php echo $total_clientes; ?></li>
                <li><strong>Enviados:</strong> <?php echo $enviados; ?></li>
                <li><strong>Pendientes de Revisión:</strong> <?php echo $pendientes; ?></li>
                <li><strong>Contratos Firmados:</strong> <?php echo $firmados; ?></li>
            </ul>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode para clientes por estado
add_shortcode('crm_clientes_por_estado', 'crm_clientes_por_estado_widget');
function crm_clientes_por_estado_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }

    global $wpdb;
    $current_user_id = get_current_user_id();
    $table_name = $wpdb->prefix . "crm_clients";

    // Inicializar los estados
    $estados = [
        'borrador' => 0,
        'enviado' => 0,
        'pendiente_revision' => 0,
        'aceptado' => 0,
        'presupuesto_enviado' => 0,
        'contratos_enviados' => 0,
        'contratos_firmados' => 0,
    ];

    // Si el usuario es 'crm_admin', obtener estadísticas generales
    if (current_user_can('crm_admin')) {
        // Obtener distribución de estados de todos los clientes
        $clientes = $wpdb->get_results("SELECT estado FROM $table_name", ARRAY_A);
    } else {
        // Obtener distribución de estados solo para el usuario comercial
        $clientes = $wpdb->get_results($wpdb->prepare("SELECT estado FROM $table_name WHERE user_id = %d", $current_user_id), ARRAY_A);
    }

    // Contabilizar los estados
    foreach ($clientes as $cliente) {
        if (isset($estados[$cliente['estado']])) {
            $estados[$cliente['estado']]++;
        }
    }

    // Generar HTML y datos para Chart.js
    ob_start();
    ?>
    <div class="widget clientes-por-estado">
        <h3>Clientes por Estado</h3>
        <canvas id="clientesPorEstadoChart"></canvas>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById('clientesPorEstadoChart').getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: [
                            'Borrador (<?php echo $estados['borrador']; ?>)',
                            'Enviado (<?php echo $estados['enviado']; ?>)',
                            'Pendiente de Revisión (<?php echo $estados['pendiente_revision']; ?>)',
                            'Aceptado (<?php echo $estados['aceptado']; ?>)',
                            'Presupuesto Enviado (<?php echo $estados['presupuesto_enviado']; ?>)',
                            'Contratos Enviados (<?php echo $estados['contratos_enviados']; ?>)',
                            'Contratos Firmados (<?php echo $estados['contratos_firmados']; ?>)'
                        ],
                        datasets: [{
                            data: [<?php echo implode(',', array_values($estados)); ?>],
                            backgroundColor: ['#6c757d', '#007bff', '#fd7e14', '#28a745', '#6f42c1', '#17a2b8', '#155724'],
                            borderColor: ['#6c757d', '#007bff', '#fd7e14', '#28a745', '#6f42c1', '#17a2b8', '#155724'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false // Esto elimina la leyenda general arriba del gráfico
                            }
                        },
                        scales: {
                            x: {
                                ticks: {
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            y: {
                                ticks: {
                                    beginAtZero: true,
                                    font: {
                                        size: 12
                                    }
                                }
                            }
                        }
                    }
                });
            });
        </script>
    </div>
    <?php
    return ob_get_clean();
}



// Shortcode para clientes recientes
add_shortcode('crm_clientes_recientes', 'crm_clientes_recientes_widget');
function crm_clientes_recientes_widget() {
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver este widget.</p>";
    }

    global $wpdb;
    $current_user_id = get_current_user_id();
    $table_name = $wpdb->prefix . "crm_clients";
    //$user_role = get_userdata($current_user_id)->roles[0]; // Obtener el rol del usuario

    // Si el usuario es 'crm_admin', obtener los últimos 5 clientes generales
    if (current_user_can('crm_admin')) {
        $clientes = $wpdb->get_results("SELECT cliente_nombre, empresa, estado, creado_en FROM $table_name ORDER BY creado_en DESC LIMIT 5", ARRAY_A);
    } else {
        // Si es comercial, obtener solo los últimos 5 clientes de ese usuario
        $clientes = $wpdb->get_results($wpdb->prepare("SELECT cliente_nombre, empresa, estado, creado_en FROM $table_name WHERE user_id = %d ORDER BY creado_en DESC LIMIT 5", $current_user_id), ARRAY_A);
    }

    // Generar HTML
    ob_start();
    ?>
    <div class="widget clientes-recientes">
        <h3>Clientes Recientes</h3>
        <ul>
            <?php foreach ($clientes as $cliente): ?>
                <li class="recientes">
                    <div class="info">
                        <strong><?php echo esc_html($cliente['cliente_nombre']); ?></strong><br><small><em><?php echo esc_html($cliente['empresa']); ?></em></small>
                    </div>
                    <div class="infodos">
                        <span class="estado <?php echo esc_attr($cliente['estado']); ?>"><?php echo ucfirst(str_replace('_', ' ', $cliente['estado'])); ?>
                            <br>
                            <span class="estado-fecha"><?php echo date("d-m-Y", strtotime($cliente['creado_en'])); ?></span></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
    return ob_get_clean();
}

























add_shortcode('crm_comerciales_estadisticas', 'crm_comerciales_estadisticas');
function crm_comerciales_estadisticas()
{
    if (!current_user_can('crm_admin')) {
        return "<p>No tienes permiso para ver esta sección.</p>";
    }

    ob_start();
    ?>
    <table id="crm-comerciales-estadisticas" class="crm-table material-design">
        <thead>
            <tr>
                <th>#</th>
                <th>Comercial</th>
                <th>Número de Altas</th>
                <th>Última Alta</th>
                <th>Borradores</th>
                <th>Enviados</th>
                <th>En Revisión</th>
                <th>Aceptados</th>
                <th>Presupuestos Enviados</th>
                <th>Contratos Enviados</th>
                <th>Contratos Firmados</th>
            </tr>
        </thead>
        <tbody>
            <!-- Los datos serán añadidos dinámicamente -->
        </tbody>
    </table>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const comercialesTabla = jQuery("#crm-comerciales-estadisticas");

            // Inicializar tabla principal
            jQuery.ajax({
                url: crmData.ajaxurl,
                method: "POST",
                data: {
                    action: "crm_obtener_comerciales_estadisticas",
                    nonce: crmData.nonce
                },
                success: function (response) {
                    if (response.success) {
                        const comerciales = response.data;
                        comerciales.forEach((comercial, index) => {
                            comercialesTabla.find("tbody").append(`
                                <tr>
                                    <td>${index + 1}</td>
                                    <td><a href="/mis-altas-de-cliente/?user_id=${comercial.id}">${comercial.nombre}</a></td>
                                    <td>${comercial.numero_altas}</td>
                                    <td data-order="${comercial.ultima_alta}">${formatDate(comercial.ultima_alta) || '-'}</td>
                                    <td>${comercial.borradores || 0}</td>
                                    <td>${comercial.enviados || 0}</td>
                                    <td>${comercial.pendiente_revision || 0}</td>
                                    <td>${comercial.aceptados || 0}</td>
                                    <td>${comercial.presupuestos_enviados || 0}</td>
                                    <td>${comercial.contratos_enviados || 0}</td>
                                    <td>${comercial.contratos_firmados || 0}</td>
                                </tr>
                            `);
                        });
                        comercialesTabla.DataTable({
                    columnDefs: [
                        {
                            targets: 3, // Índice de la columna "Última Edición"
                            type: "datetime",
                        },
                    ],
                    order: [[3, "desc"]], // Ordenar por la columna "Última Edición" en orden descendente
                    pageLength: 50, 
                    language: {
                        url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json",
                    },
                });
                    } else {
                        alert("No se encontraron comerciales.");
                    }
                },
                error: function () {
                    alert("Error al cargar los datos.");
                }
            });
        });
        function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString("es-ES", { dateStyle: "short"});
    }
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
























