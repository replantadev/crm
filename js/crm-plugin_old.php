<?php
/*
Plugin Name: CRM Básico
Description: Plugin para gestionar clientes con roles de comercial y administrador CRM.
Version: 1.6
Author: Luis Javier
*/

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}
// Incluir el archivo de acceso
require_once plugin_dir_path(__FILE__) . 'acceso.php';
require_once plugin_dir_path(__FILE__) . 'shortcodes.php';

add_action('wp_enqueue_scripts', 'crm_enqueue_styles');
function crm_enqueue_styles()
{
    wp_enqueue_style('crm-styles', plugin_dir_url(__FILE__) . 'crm-styles.css', time(), true);
}
add_action('wp_enqueue_scripts', 'crm_enqueue_scripts');
function crm_enqueue_scripts()
{
    // Condicionar la carga del script
    if (is_page(['alta-de-clientes',  'editar-cliente'])) {
        //lo cargo en el form para pasar estado
    }
    if (is_page(['mis-altas-de-cliente']) || is_page(['resumen'])) {
        // Encolar jQuery primero, porque DataTables depende de jQuery
        wp_enqueue_script('jquery');

        // Encolar DataTables (JavaScript y CSS)
        wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', ['jquery'], null, true);
        wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css');

        // Encolar el script personalizado para inicializar DataTables
        wp_enqueue_script('crm-lista-altas-js', plugin_dir_url(__FILE__) . 'js/crm-lista-altas2.js', ['jquery', 'datatables-js'], time(), true);

        // Pasar datos al script (como nonce y URL de AJAX)
        wp_localize_script('crm-lista-altas-js', 'crmData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('crm_obtener_clientes_nonce'),
            'user_id' => get_current_user_id(),
        ]);
    }
    if (is_page('todas-las-altas-de-cliente')) {
        // Encolar DataTables (JavaScript y CSS)
        wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', ['jquery'], null, true);
        wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css');
        wp_enqueue_script('todas-las-altas-js', plugin_dir_url(__FILE__) . 'js/todas-las-altasv2.js', ['jquery', 'datatables-js'], time(), true);

        wp_localize_script('todas-las-altas-js', 'crmData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('crm_obtener_clientes_nonce'),
        ]);
    }
}

add_action('wp_enqueue_scripts', 'crm_enqueue_chartjs');
function crm_enqueue_chartjs()
{
    // Verificar si se está cargando una página con shortcodes que usan Chart.js
    global $post;
    if (
        is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'crm_clientes_por_interes') ||
        has_shortcode($post->post_content, 'crm_clientes_por_estado')
    ) {

        // Registrar y encolar Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            null,
            true
        );
    }
}


/**
 * Estados y colores por interés/sector
 */
function crm_get_estados_sector()
{
    return [
        'borrador'            => ['label' => 'Borrador',            'color' => '#B0B7C3'],
        'enviado'             => ['label' => 'Enviado',             'color' => '#2AA8F2'],
        'presupuesto_aceptado' => ['label' => 'Presupuesto Aceptado', 'color' => '#25C685'],
        'contratos_generados' => ['label' => 'Contratos Generados', 'color' => '#007bff'],
        'contratos_firmados'  => ['label' => 'Contratos Firmados',  'color' => '#7048E8'],
    ];
}


function crm_get_colores_sectores()
{
    return [
        'energia'            => '#FF6B6B',
        'alarmas'            => '#FFB400',
        'telecomunicaciones' => '#00B8D9',
        'seguros'            => '#6DD400',
        'renovables'         => '#38B24A',
    ];
}

/**
 * Orden de estados para comparación
 */
function crm_get_orden_estados()
{
    /* añadimos el nuevo nivel antes de firmados */
    return [
        'borrador',
        'enviado',
        'presupuesto_aceptado',
        'contratos_generados',
        'contratos_firmados'
    ];
}

/**
 * Renderiza badges de estado por sector/interés.
 */
function crm_render_estado_badges($estado_por_sector = [], $sectores = [])
{
    $out = '';
    foreach ($sectores as $sector) {
        $estado = $estado_por_sector[$sector] ?? 'borrador';
        $label  = ucfirst($sector) . ': ' . ucfirst(str_replace('_', ' ', $estado));
        $out   .= '<span class="crm-badge estado ' . $estado . '">' . $label . '</span>';
    }
    return $out;
}

/**
 * Devuelve el estado global de un cliente basado en el array estado_por_sector.
 */
function crm_calcula_estado_global($estado_por_sector)
{
    $orden = crm_get_orden_estados();
    $min_idx = 99;

    foreach ($estado_por_sector as $estado) {
        $idx = array_search($estado, $orden);
        if ($idx !== false && $idx < $min_idx) $min_idx = $idx;
    }
    return $orden[$min_idx] ?? 'borrador';
}


// Crear shortcode para el formulario de alta de cliente

add_shortcode('crm_alta_cliente', 'crm_formulario_alta_cliente');
function crm_formulario_alta_cliente()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para acceder al formulario.</p>";
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    // Obtener cliente por ID si existe
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    $client_data = $client_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A) : null;

    // Determinar estado actual
    $estado_actual = isset($client_data) && isset($client_data['estado']) ? $client_data['estado'] : 'borrador';
    $estado_por_sector = isset($client_data['estado_por_sector'])
        ? maybe_unserialize($client_data['estado_por_sector'])
        : [];
    $facturas = isset($client_data['facturas']) ? maybe_unserialize($client_data['facturas']) : [];
    $presupuestos = isset($client_data['presupuesto']) ? maybe_unserialize($client_data['presupuesto']) : [];
    $contratos_firmados = isset($client_data['contratos_firmados']) ? maybe_unserialize($client_data['contratos_firmados']) : [];
    $intereses = isset($client_data['intereses']) ? maybe_unserialize($client_data['intereses']) : [];
    /* ---- NUEVO: cargar contratos generados ---- */
    $contratos_generados = isset($client_data['contratos_generados'])
        ? maybe_unserialize($client_data['contratos_generados'])
        : [];
    $contratos_generados = is_array($contratos_generados) ? $contratos_generados : [];


    // Asegurar que `facturas`, `presupuestos`, `contratos_firmados`, e `intereses` sean arrays
    $facturas = is_array($facturas) ? $facturas : [];
    $presupuestos = is_array($presupuestos) ? $presupuestos : [];
    $contratos_firmados = is_array($contratos_firmados) ? $contratos_firmados : [];
    $intereses = is_array($intereses) ? $intereses : [];

    // Encolar el script JavaScript y localizar datos
    wp_enqueue_script('crm-scriptv2', plugins_url('/js/crm-scriptv7.js', __FILE__), array('jquery'), '1.0', true);
    wp_localize_script('crm-scriptv2', 'crmData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('crm_alta_cliente_nonce'),
        'is_admin' => current_user_can('crm_admin'),
        'current_estado' => $estado_actual,
    ));

    ob_start();
?>

    <!-- ——— Plantillas ocultas para la generación dinámica de secciones ——— -->
    <div id="crm-templates" style="display:none;">
        <template id="tpl-factura">
            <div class="factura-sector" id="factura-{{sector}}">
                <h4>Facturas de {{sectorLabel}}</h4>
                <div class="uploaded-files" data-sector="{{sector}}">
                    <p>No hay facturas disponibles.</p>
                </div>
                <input type="file" name="facturas[{{sector}}][]" multiple data-sector="{{sector}}" class="upload-input">
                <button type="button" class="agregar-documento-btn" data-sector="{{sector}}" style="display:none;">Agregar Documento</button>
            </div>
        </template>

        <template id="tpl-presupuesto">
            <div class="presupuesto-sector" id="presupuesto-{{sector}}">
                <h4>Presupuestos para {{sectorLabel}}</h4>
                <div class="uploaded-files" data-sector="{{sector}}">
                    <p>No hay presupuestos disponibles.</p>
                </div>
                <input type="file" name="presupuesto[{{sector}}][]" multiple data-sector="{{sector}}" class="upload-input">
                <button type="button" class="agregar-documento-btn" data-sector="{{sector}}" style="display:none;">Agregar Documento</button>
                <?php if (current_user_can('comercial')): ?>
                    <button type="button" class="enviar-sector-btn enviar-{{sector}}" data-sector="{{sector}}" style="display:none;">
                        Enviar {{sectorLabel}}
                    </button>
                <?php endif; ?>
                <input type="hidden" name="estado_{{sector}}" value="borrador">
            </div>
        </template>

        <template id="tpl-contrato-firmado">
            <div class="contrato-firmado-sector" id="contrato-firmado-{{sector}}">
                <h4>Contrato firmado para {{sectorLabel}}</h4>
                <div class="uploaded-files" data-sector="{{sector}}">
                    <p>No hay contratos disponibles.</p>
                </div>
                <input type="file" name="contratos_firmados[{{sector}}][]" multiple data-sector="{{sector}}" class="upload-input">
                <button type="button" class="agregar-documento-btn" data-sector="{{sector}}" style="display:none;">Agregar Documento</button>
            </div>
        </template>
    </div>









    <form id="crm-alta-cliente-form" method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('crm_alta_cliente_nonce', 'crm_nonce'); ?>
        <input type="hidden" name="action" value="crm_guardar_cliente_ajax">
        <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
        <input type="hidden" name="estado_formulario" id="estado_formulario" value="<?php echo esc_attr($estado_actual); ?>">

        <!-- Datos del Comercial -->
        <div class="crm-section inicio">
            <div class="half-width">
                <?php if (current_user_can('crm_admin')): ?>
                    <p><a class="atras" href="/todas-las-altas-de-cliente/"> ⬅️Regresar Atrás</a></p>
                <?php endif; ?>
                <p>Estado: <strong class="estado <?php echo $estado_actual; ?>"><?php echo ucfirst(str_replace('_', ' ', $estado_actual)); ?></strong></p>
                <?php if ($estado_actual === 'borrador'): ?>
                    <p><small>Este cliente está guardado como borrador. Aún no se ha enviado para revisión.</small></p>
                <?php endif; ?>
                <?php
                // en crm_formulario_alta_cliente(), antes de ob_start():
                $sectores = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
                echo '<div class="crm-badges-estado">';
                echo crm_render_estado_badges($estado_por_sector, $sectores);
                echo '</div>';
                ?>
            </div>
            <div class="half-width">
                <?php if (current_user_can('crm_admin')): ?>
                    <label for="delegado">Comercial Asignado:</label>
                    <select name="delegado" id="delegado">
                        <?php
                        // Obtener todos los usuarios con el rol "comercial"
                        $comerciales = get_users(['role' => 'comercial']);
                        foreach ($comerciales as $comercial) {
                            $selected = (isset($client_data['delegado']) && $client_data['delegado'] === $comercial->display_name) ? 'selected' : '';
                            echo "<option value='" . esc_attr($comercial->display_name) . "' $selected>" . esc_html($comercial->display_name) . " (" . esc_html($comercial->user_email) . ")</option>";
                        }
                        ?>
                    </select>
                    <input type="hidden" name="email_comercial" id="email_comercial" value="<?php echo esc_attr($client_data['email_comercial'] ?? ''); ?>">
                <?php else: ?>
                    <small>El cliente está asignado a <?php echo esc_html($client_data['delegado'] ?? wp_get_current_user()->display_name); ?> (<?php echo esc_html($client_data['email_comercial'] ?? wp_get_current_user()->user_email); ?>)</small>
                    <input type="hidden" name="delegado" value="<?php echo esc_attr(isset($client_data['delegado']) ? $client_data['delegado'] : wp_get_current_user()->display_name); ?>">
                    <input type="hidden" name="email_comercial" value="<?php echo esc_attr(isset($client_data['email_comercial']) ? $client_data['email_comercial'] : wp_get_current_user()->user_email); ?>">
                <?php endif; ?>
                <?php if (current_user_can('crm_admin')): ?>

                    <label for="estado">Estado global:</label>
                    <select name="estado" id="estado">
                        <option value="" disabled <?php selected($estado_actual, ''); ?>>Selecciona un estado</option>
                        <?php foreach (crm_get_estados_sector() as $val => $arr): ?>
                            <option value="<?php echo esc_attr($val) ?>" <?php selected($estado_actual, $val) ?>>
                                <?php echo esc_html($arr['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                <?php endif; ?>


            </div>
        </div>

        <!-- Datos del Cliente -->
        <div class="crm-section datos">
            <h3>Datos del Cliente</h3>
            <div class="datos-container">
                <div class="form-group full-width">
                    <input type="text" name="cliente_nombre" placeholder="Nombre del Cliente" required value="<?php echo esc_attr($client_data['cliente_nombre'] ?? ''); ?>">
                </div>
                <div class="form-group full-width">
                    <input type="text" name="empresa" placeholder="Empresa" value="<?php echo esc_attr($client_data['empresa'] ?? ''); ?>">
                </div>
                <div class="form-group full-width">
                    <input type="text" name="direccion" placeholder="Dirección" value="<?php echo esc_attr($client_data['direccion'] ?? ''); ?>">
                </div>
                <div class="form-group half-width">
                    <input type="text" name="telefono" placeholder="Teléfono" value="<?php echo esc_attr($client_data['telefono'] ?? ''); ?>">
                </div>
                <div class="form-group half-width">
                    <input type="email" name="email_cliente" placeholder="Email" value="<?php echo esc_attr($client_data['email_cliente'] ?? ''); ?>">
                </div>
                <div class="form-group half-width">
                    <input type="text" name="poblacion" placeholder="Población" value="<?php echo esc_attr($client_data['poblacion'] ?? ''); ?>">
                </div>
                <div class="form-group half-width">
                    <input type="text" name="area" placeholder="Área" value="<?php echo esc_attr($client_data['area'] ?? ''); ?>">
                </div>
                <div class="form-group half-width">
                    <select name="tipo" required>
                        <option value="" disabled <?php echo !isset($client_data['tipo']) || empty($client_data['tipo']) ? 'selected' : ''; ?>>Tipo</option>
                        <option value="A" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'A' ? 'selected' : ''; ?>>TIPO A</option>
                        <option value="B" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'B' ? 'selected' : ''; ?>>TIPO B</option>
                        <option value="C" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'C' ? 'selected' : ''; ?>>TIPO C</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <textarea name="comentarios" placeholder="Comentarios"><?php echo esc_textarea($client_data['comentarios'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Intereses del Cliente -->
        <div class="crm-section intereses">
            <h3>Intereses del Cliente</h3>
            <div class="intereses-container">
                <?php
                $sectores = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
                $intereses = maybe_unserialize($client_data['intereses'] ?? []);
                foreach ($sectores as $sector) {
                    $checked = in_array($sector, $intereses) ? 'checked' : '';
                    echo "<input type='checkbox' id='interes-{$sector}' name='intereses[]' value='{$sector}' {$checked}>";
                    echo "<label for='interes-{$sector}'>" . ucfirst($sector) . "</label>";
                }
                ?>
            </div>
        </div>
        <?php
        $estado_por_sector = isset($client_data['estado_por_sector'])
            ? maybe_unserialize($client_data['estado_por_sector'])
            : [];


        ?>

        <!-- Facturas -->
        <div class="crm-section facturas">
            <h3>Facturas del Cliente</h3>
            <div id="facturas-container">
                <?php
                foreach ($sectores as $sector) {
                    $is_visible = in_array($sector, $intereses) ? 'block' : 'none';
                    echo "<div class='factura-sector' id='factura-{$sector}' style='display: {$is_visible};'>";
                    echo "<h4>Facturas de " . ucfirst($sector) . "</h4>";

                    echo "<div class='uploaded-files' data-sector='{$sector}'>";
                    if (!empty($facturas[$sector])) {
                        foreach ($facturas[$sector] as $file) {
                            echo "<div class='uploaded-file'>
                                <a href='" . esc_url($file) . "' target='_blank'>" . esc_html(basename($file)) . "</a>
                                <button type='button' class='remove-file' data-url='" . esc_url($file) . "'>X</button>
                                <input type='hidden' name='facturas[{$sector}][]' value='" . esc_url($file) . "'>
                              </div>";
                        }
                    } else {
                        echo "<p>No hay facturas disponibles.</p>";
                    }
                    echo "</div>";

                    echo "<input type='file' name='facturas[{$sector}][]' multiple data-sector='{$sector}' class='upload-input'>";
                    echo "<button type='button' class='agregar-documento-btn' data-sector='{$sector}' style='display: none;'>Agregar Documento</button>";
                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <!-- Presupuestos -->
        <div class="crm-section presupuestos">
            <h3>Presupuestos del Cliente</h3>
            <small><em>Sube los presupuestos aceptados por el cliente para cada sector de interés. El sistema notificará al administrador para que genere los contratos</em></small>
            <div id="presupuestos-container">
                <?php
                foreach ($sectores as $sector) {
                    $is_visible = in_array($sector, $intereses) ? 'block' : 'none';
                    $estado_actual_sector = $estado_por_sector[$sector] ?? 'borrador';

                    echo "<div class='presupuesto-sector' id='presupuesto-{$sector}' style='display: {$is_visible};'>";
                    echo "<h4>Presupuestos para " . ucfirst($sector) . "</h4>";

                    echo "<div class='uploaded-files' data-sector='{$sector}'>";
                    if (!empty($presupuestos[$sector])) {
                        foreach ($presupuestos[$sector] as $file) {
                            echo "<div class='uploaded-file'>
                                <a href='" . esc_url($file) . "' target='_blank'>" . esc_html(basename($file)) . "</a>
                                <button type='button' class='remove-file' data-url='" . esc_url($file) . "'>X</button>
                                <input type='hidden' name='presupuesto[{$sector}][]' value='" . esc_url($file) . "'>
                            </div>";
                        }
                    } else {
                        echo "<p>No hay presupuestos disponibles.</p>";
                    }
                    echo "</div>";

                    echo "<input type='file' name='presupuesto[{$sector}][]' multiple data-sector='{$sector}' class='upload-input'>";
                    echo "<button type='button' class='agregar-documento-btn' data-sector='{$sector}' style='display: none;'>Agregar Documento</button>";
                ?>

                    <?php if (current_user_can('comercial') && $estado_actual_sector === 'borrador') {
                    ?>

                        <!-- botón ENVIAR sector -->
                        <button type="button"
                            class="enviar-sector-btn enviar-<?php echo esc_attr($sector); ?>"
                            data-sector="<?php echo esc_attr($sector); ?>">
                            Enviar <?php echo ucfirst($sector); ?>
                        </button>
                    <?php } ?>



                    <!-- SIEMPRE enviamos el estado de este sector (para el admin o para mantener valor) -->
                    <input type="hidden"
                        name="estado_<?php echo esc_attr($sector); ?>"
                        value="<?php echo esc_attr($estado_actual_sector); ?>">
                <?php
                    echo '</div>';   //  cierro .presupuesto-sector
                }
                ?>
            </div>
        </div>

        <?php if (current_user_can('crm_admin') && in_array($estado_actual, ['presupuesto_aceptado', 'contratos_generados',  'contratos_firmados'])): ?>
            <!-- Contratos Generados -->
            <div class="crm-section contratos-generados">
                <h3>Contratos Generados</h3>

                <div class="chips-list">
                    <?php
                    $contratos_generados = is_array($contratos_generados) ? $contratos_generados : [];
                    foreach ($sectores as $sector) {
                        $estado_sector = $estado_por_sector[$sector] ?? 'borrador';
                        if (! in_array($estado_sector, ['presupuesto_aceptado', 'contratos_generados', 'contratos_firmados'])) {
                            continue;   // aún no procede
                        }

                        $checked = in_array($sector, $contratos_generados) ? 'checked' : '';
                    ?>
                        <label class="toggle-chip sector-<?php echo $sector; ?><?php echo $checked ? ' checked' : ''; ?>">
                            <input type="checkbox"
                                class="contrato-gen"
                                name="contratos_generados[]"
                                value="<?php echo esc_attr($sector); ?>"
                                <?php echo $checked; ?>>
                            <?php echo ucfirst($sector); ?>
                        </label>
                    <?php } ?>
                </div>
            </div>


            <!-- Contratos Firmados -->
            <div class="crm-section contratos-firmados">
                <h3>Contratos Firmados</h3>
                <div id="contratos-firmados-container" class="contratos-firmados-container">
                    <?php
                    // Asegurar que $contratos_firmados es un array multidimensional
                    $contratos_firmados = isset($client_data['contratos_firmados']) ? maybe_unserialize($client_data['contratos_firmados']) : [];
                    $contratos_firmados = is_array($contratos_firmados) ? $contratos_firmados : [];
                    foreach ($sectores as $sector) {
                        $is_visible = in_array($sector, $contratos_generados) ? 'block' : 'none';
                        echo "<div class='contrato-firmado-sector' id='contrato-firmado-{$sector}' style='display: {$is_visible};'>";
                        echo "<h4>Contrato firmado para " . ucfirst($sector) . "</h4>";
                        echo "<div class='uploaded-files' data-sector='{$sector}'>";
                        if (!empty($contratos_firmados[$sector])) {
                            foreach ($contratos_firmados[$sector] as $file) {
                                echo "<div class='uploaded-file'>
                                    <a href='" . esc_url($file) . "' target='_blank'>" . esc_html(basename($file)) . "</a>
                                    <button type='button' class='remove-file' data-url='" . esc_url($file) . "'>X</button>
                                    <input type='hidden' name='contratos_firmados[{$sector}][]' value='" . esc_url($file) . "'>
                                </div>";
                            }
                        } else {
                            echo "<p>No hay contratos disponibles.</p>";
                        }
                        echo "</div>";
                        echo "<input type='file' name='contratos_firmados[{$sector}][]' multiple data-sector='{$sector}' class='upload-input'>";
                        echo "<button type='button' class='agregar-documento-btn' data-sector='{$sector}' style='display: none;'>Agregar Documento</button>";
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Contratos y estado del proyecto -->
        <?php if (current_user_can('crm_admin')) : ?>
            <div class="crm-section estado">
                <label for="estado">Estado del Cliente:</label>
                <label style="display:inline-block;margin-right:10px;">
                    <input type="checkbox" name="forzar_estado" id="forzar_estado" value="1">
                    Forzar el estado del cliente al seleccionado aquí
                </label>
                <?php
                // Mostramos un selector de estado por cada interés marcado
                foreach ($sectores as $sector) {
                    if (in_array($sector, $intereses)) {
                        $estado_valor = isset($estado_por_sector[$sector]) ? $estado_por_sector[$sector] : 'borrador';
                ?>
                        <div class="crm-section estado-sector">
                            <label for="estado_<?php echo esc_attr($sector); ?>">
                                Estado para <?php echo ucfirst($sector); ?>:
                            </label>
                            <select name="estado_<?php echo esc_attr($sector); ?>" id="estado_<?php echo esc_attr($sector); ?>">
                                <option value="" disabled <?php selected($estado_valor, ''); ?>>Selecciona un estado</option>
                                <option value="borrador" <?php selected($estado_valor, 'borrador'); ?>>Borrador</option>
                                <option value="enviado" <?php selected($estado_valor, 'enviado'); ?>>Enviado</option>
                                <option value="presupuesto_aceptado" <?php selected($estado_valor, 'presupuesto_aceptado'); ?>>Presupuesto Aceptado</option>
                                <option value="contratos_generados" <?php selected($estado_valor, 'contratos_generados'); ?>>Contratos Generados</option>
                                <option value="contratos_firmados" <?php selected($estado_valor, 'contratos_firmados'); ?>>Contratos Firmados</option>
                            </select>

                        </div>
                <?php
                    }
                }
                ?>
            </div>
        <?php endif; ?>

        <!-- Botón de Enviar -->

        <div class="crm-section enviar">

            <?php
            /* ---------------- ESTADOS “tempranos” ---------------- */
            if (in_array($estado_actual, ['borrador', 'pendiente_revision'])): ?>

                <button type="submit" name="crm_guardar_cliente" class="crm-submit-btn">
                    Guardar como borrador
                </button>

                <button type="submit" name="crm_enviar_cliente" class="crm-submit-btn enviar-btn">
                    Enviar para revisión
                </button>

            <?php
            /* ---------------- YA ENVIADO, pero hay algún sector sin enviar -------------- */
            elseif ($estado_actual === 'enviado' && in_array('borrador', $estado_por_sector)): ?>

                <p>Ficha enviada. Aún quedan sectores en borrador, puedes completar
                    la información y volver a enviarla.</p>

                <button type="submit" name="crm_enviar_cliente" class="crm-submit-btn">
                    Guardar y volver a enviar
                </button>

            <?php endif; ?>


            <?php
            /* -------- Mensajes finos al COMERCIAL según cada sector -------- */
            if (current_user_can('comercial')) {
                foreach ($estado_por_sector as $sec => $est) {



                    if ($est === 'presupuesto_aceptado') {
                        echo "<p class='info-sector'>Presupuesto de <strong>" . ucfirst($sec) .
                            "</strong> aceptado ✓ &nbsp;•&nbsp; el administrador generará el contrato.</p>";
                    }

                    if ($est === 'contratos_generados') {
                        echo "<p class='info-sector'>Contrato de <strong>" . ucfirst($sec) .
                            "</strong> generado &nbsp;•&nbsp; pendiente de firma del cliente.</p>";
                    }
                    if ($est === 'contratos_firmados') {
                        echo "<p class='info-sector'>
                                    Contrato de <strong>" . ucfirst($sec) . "</strong> firmado ✓.
                                </p>";
                    }
                }
            }
            ?>

            <?php if (current_user_can('crm_admin')): ?>
                <button type="submit" name="crm_guardar_como_estado" id="admin-custom-button"
                    class="crm-submit-btn"
                    style="display:none;margin-left:10px;background:#666;color:#fff;">  Guardar como <?php echo ucfirst(str_replace('_',' ',$estado_actual)); ?>
                </button>
            <?php endif; ?>

        </div>




        <?php if (isset($_GET['success'])): ?>
            <div class="crm-notification success">
                <p>¡Los datos se han guardado correctamente!</p>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="crm-notification error">
                <p>Ocurrió un error al guardar los datos. Por favor, inténtalo de nuevo.</p>
            </div>
        <?php endif; ?>
    </form>
<?php
    return ob_get_clean();
}


// Registrar las acciones AJAX
add_action('wp_ajax_crm_guardar_cliente_ajax', 'crm_guardar_cliente_ajax');
add_action('wp_ajax_crm_enviar_cliente_ajax', 'crm_enviar_cliente_ajax');

// Función para guardar cliente (Guardar como borrador)
function crm_guardar_cliente_ajax()
{
    try {
        // Obtener el estado del formulario
        $estado = isset($_POST['estado_formulario']) ? sanitize_text_field($_POST['estado_formulario']) : 'borrador';
        crm_handle_ajax_request($estado);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Función para enviar cliente (Enviar para revisión o acción adicional)
function crm_enviar_cliente_ajax()
{
    try {
        $estado = isset($_POST['estado_formulario']) ? sanitize_text_field($_POST['estado_formulario']) : 'enviado';
        crm_handle_ajax_request($estado);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Función principal para manejar la lógica AJAX
function crm_handle_ajax_request($estado)
{
    /* ------------------------------------------------------------------ *
 *  Normalizar nombres de campos (alias singular ↔ plural)            *
 * ------------------------------------------------------------------ */
    if (isset($_POST['factura'])            && !isset($_POST['facturas'])) {
        $_POST['facturas']          = $_POST['factura'];
    }
    if (isset($_POST['presupuestos'])       && !isset($_POST['presupuesto'])) {
        $_POST['presupuesto']       = $_POST['presupuestos'];
    }
    if (isset($_POST['contrato_firmado'])   && !isset($_POST['contratos_firmados'])) {
        $_POST['contratos_firmados'] = $_POST['contrato_firmado'];
    }

    global $wpdb;
    $sectores = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    $table_name = $wpdb->prefix . "crm_clients";
    error_log('Iniciando crm_handle_ajax_request. Estado: ' . $estado);
    error_log('$_POST: ' . print_r($_POST, true));
    error_log('$_FILES: ' . print_r($_FILES, true));

    // Validar nonce
    if (!isset($_POST['crm_nonce']) || !wp_verify_nonce($_POST['crm_nonce'], 'crm_alta_cliente_nonce')) {
        wp_send_json_error(['message' => 'Error de seguridad. Tu sesión podría haber expirado. Por favor, recarga la página e inténtalo de nuevo.']);
        exit;
    }

    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : null;

    // Obtener datos existentes si es edición
    $client_data = $client_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A) : null;

    // *** Procesar Facturas ***
    $facturas_existentes = isset($client_data['facturas']) ? maybe_unserialize($client_data['facturas']) : [];
    $facturas_existentes = is_array($facturas_existentes) ? $facturas_existentes : [];
    $facturas_nuevas = [];

    // *** Procesar Facturas ***
    if (isset($_FILES['facturas']) && is_array($_FILES['facturas']['name'])) {
        foreach ($_FILES['facturas']['name'] as $sector => $files) {
            if (! is_array($files)) continue;
            foreach ($files as $key => $name) {
                if (
                    $_FILES['facturas']['error'][$sector][$key] === UPLOAD_ERR_OK
                    && ! empty($_FILES['facturas']['tmp_name'][$sector][$key])
                ) {
                    // — saca el array a una variable —
                    $file = [
                        'name'     => $name,
                        'type'     => $_FILES['facturas']['type'][$sector][$key],
                        'tmp_name' => $_FILES['facturas']['tmp_name'][$sector][$key],
                        'error'    => $_FILES['facturas']['error'][$sector][$key],
                        'size'     => $_FILES['facturas']['size'][$sector][$key],
                    ];
                    // — pásalo a wp_handle_upload() —
                    $upload = wp_handle_upload($file, ['test_form' => false]);
                    if (! isset($upload['error'])) {
                        $facturas_nuevas[$sector][] = $upload['url'];
                    } else {
                        error_log("Error al subir factura: " . $upload['error']);
                        wp_send_json_error(['message' => "Error al subir factura: {$upload['error']}"]);
                        exit;
                    }
                }
            }
        }
    }


    foreach ($facturas_nuevas as $sector => $files) {
        if (!isset($facturas_existentes[$sector])) {
            $facturas_existentes[$sector] = [];
        }
        $facturas_existentes[$sector] = array_unique(array_merge($facturas_existentes[$sector], $files));
    }

    // Recorremos TODOS los sectores posibles
    foreach ($sectores as $sector) {
        // Si en POST hay facturas para este sector
        if (isset($_POST['facturas'][$sector])) {
            if (!isset($facturas_existentes[$sector])) {
                $facturas_existentes[$sector] = [];
            }
            $facturas_existentes[$sector] = array_unique(array_merge($facturas_existentes[$sector], $_POST['facturas'][$sector]));
        }
        // Si NO viene nada en POST pero ya hay facturas guardadas, NO tocar ese sector
        // (no hace falta hacer nada aquí, ya que $facturas_existentes conserva el valor)
    }


    // *** Procesar Presupuestos ***
    $presupuestos_existentes = isset($client_data['presupuesto']) ? maybe_unserialize($client_data['presupuesto']) : [];
    $presupuestos_existentes = is_array($presupuestos_existentes) ? $presupuestos_existentes : [];
    $presupuestos_nuevos = [];

    if (isset($_FILES['presupuesto']) && is_array($_FILES['presupuesto']['name'])) {
        foreach ($_FILES['presupuesto']['name'] as $sector => $files) {
            if (!is_array($files)) continue;

            foreach ($files as $key => $name) {
                if ($_FILES['presupuesto']['error'][$sector][$key] === UPLOAD_ERR_OK && !empty($_FILES['presupuesto']['tmp_name'][$sector][$key])) {
                    $file = [
                        'name'     => $name,
                        'type'     => $_FILES['presupuesto']['type'][$sector][$key],
                        'tmp_name' => $_FILES['presupuesto']['tmp_name'][$sector][$key],
                        'error'    => $_FILES['presupuesto']['error'][$sector][$key],
                        'size'     => $_FILES['presupuesto']['size'][$sector][$key],
                    ];
                    $upload = wp_handle_upload($file, ['test_form' => false]);


                    if (!isset($upload['error'])) {
                        $presupuestos_nuevos[$sector][] = $upload['url'];
                    } else {
                        error_log("Error al subir presupuesto: " . $upload['error']);
                        wp_send_json_error(['message' => "Error al subir presupuesto: {$upload['error']}"]);
                        exit;
                    }
                }
            }
        }
    }

    foreach ($presupuestos_nuevos as $sector => $files) {
        if (!isset($presupuestos_existentes[$sector])) {
            $presupuestos_existentes[$sector] = [];
        }
        $presupuestos_existentes[$sector] = array_unique(array_merge($presupuestos_existentes[$sector], $files));
    }

    foreach ($sectores as $sector) {
        // Si en POST hay presupuestos para este sector
        if (isset($_POST['presupuesto'][$sector])) {
            if (!isset($presupuestos_existentes[$sector])) {
                $presupuestos_existentes[$sector] = [];
            }
            $presupuestos_existentes[$sector] = array_unique(array_merge($presupuestos_existentes[$sector], $_POST['presupuesto'][$sector]));
        }
        // Si NO viene nada en POST pero ya hay presupuestos guardados, NO tocar ese sector
        // (no hace falta hacer nada aquí, ya que $presupuestos_existentes conserva el valor)
    }




    // *** Procesar Contratos Firmados ***
    $contratos_firmados_existentes = isset($client_data['contratos_firmados']) ? maybe_unserialize($client_data['contratos_firmados']) : [];
    $contratos_firmados_existentes = is_array($contratos_firmados_existentes) ? $contratos_firmados_existentes : [];
    $contratos_firmados_nuevos = [];

    if (isset($_FILES['contratos_firmados']) && is_array($_FILES['contratos_firmados']['name'])) {
        foreach ($_FILES['contratos_firmados']['name'] as $sector => $files) {
            if (!is_array($files)) continue;

            foreach ($files as $key => $name) {
                if ($_FILES['contratos_firmados']['error'][$sector][$key] === UPLOAD_ERR_OK && !empty($_FILES['contratos_firmados']['tmp_name'][$sector][$key])) {

                    $file = [
                        'name'     => $name,
                        'type'     => $_FILES['contratos_firmados']['type'][$sector][$key],
                        'tmp_name' => $_FILES['contratos_firmados']['tmp_name'][$sector][$key],
                        'error'    => $_FILES['contratos_firmados']['error'][$sector][$key],
                        'size'     => $_FILES['contratos_firmados']['size'][$sector][$key],
                    ];
                    // Subir el archivo y manejar errores
                    $upload = wp_handle_upload($file, ['test_form' => false]);


                    if (!isset($upload['error'])) {
                        $contratos_firmados_nuevos[$sector][] = $upload['url'];
                    } else {
                        error_log("Error al subir contrato firmado: " . $upload['error']);
                        wp_send_json_error(['message' => "Error al subir contrato firmado: {$upload['error']}"]);
                        exit;
                    }
                }
            }
        }
    }

    foreach ($contratos_firmados_nuevos as $sector => $files) {
        if (!isset($contratos_firmados_existentes[$sector])) {
            $contratos_firmados_existentes[$sector] = [];
        }
        $contratos_firmados_existentes[$sector] = array_unique(array_merge($contratos_firmados_existentes[$sector], $files));
    }

    foreach ($sectores as $sector) {
        // Si en POST hay contratos firmados para este sector
        if (isset($_POST['contratos_firmados'][$sector])) {
            if (!isset($contratos_firmados_existentes[$sector])) {
                $contratos_firmados_existentes[$sector] = [];
            }
            $contratos_firmados_existentes[$sector] = array_unique(array_merge($contratos_firmados_existentes[$sector], $_POST['contratos_firmados'][$sector]));
        }
        // Si NO viene nada en POST pero ya hay contratos guardados, NO tocar ese sector
        // (no hace falta hacer nada aquí, ya que $contratos_firmados_existentes conserva el valor)
    }


    // *** Procesar Contratos Generados ***
    $contratos_generados = isset($client_data['contratos_generados'])
        ? maybe_unserialize($client_data['contratos_generados'])
        : [];

    $contratos_generados = is_array($contratos_generados) ? $contratos_generados : [];


    /* ----------  CONTRATOS GENERADOS  ---------- */
    /* (checkboxes solo envían los que están marcados) */
    $contratos_generados = isset($_POST['contratos_generados'])
        ? array_map('sanitize_text_field', (array) $_POST['contratos_generados'])
        : [];

    // ----- ¿Viene de “Enviar cliente”? y ¿Admin forzando estado? -----
    $is_send_action = current_filter() === 'wp_ajax_crm_enviar_cliente_ajax';
    $forzar        = current_user_can('crm_admin') && ! empty($_POST['forzar_estado']);

    // ----- Carga valores previos de estado por sector -----
    $estado_por_sector = [];
    if (! empty($client_data['estado_por_sector'])) {
        $tmp = maybe_unserialize($client_data['estado_por_sector']);
        $estado_por_sector = is_array($tmp) ? $tmp : [];
    }

    // helpers para el bucle
    $sectores_a_enviar = (array) ($_POST['enviar_sector']        ?? []);
    $contratos_gen     = (array) ($_POST['contratos_generados'] ?? []);

    foreach ($sectores as $sector) {
        if (empty($_POST['intereses']) || ! in_array($sector, $_POST['intereses'], true)) {
            continue;
        }
        $est = $estado_por_sector[$sector] ?? 'borrador';

        // 1) SI VIENE DEL “Enviar sector” ➜ lo marcamos enviado
        if (
            $is_send_action && ! $forzar
            && in_array($sector, $sectores_a_enviar, true)
            && $est === 'borrador'
        ) {
            $est = 'enviado';
        }

        // 2) ADMIN forzando manualmente (sólo si checkeó el “forzar_estado”)
        if (
            current_user_can('crm_admin')
            && ! empty($_POST['forzar_estado'])
            && isset($_POST["estado_{$sector}"])
        ) {
            $estado_por_sector[$sector] = sanitize_text_field($_POST["estado_{$sector}"]);
            continue;
        }


        // 3) Si tiene presupuesto → presupuesto_aceptado
        if (
            in_array($est, ['borrador', 'enviado'], true)
            && (
                ! empty($presupuestos_nuevos[$sector])
                || ! empty($presupuestos_existentes[$sector])
            )
        ) {
            $est = 'presupuesto_aceptado';
        }

        // Si admin marcó contratos generados…
        if ($est === 'presupuesto_aceptado' && in_array($sector, $contratos_gen, true)) {
            $est = 'contratos_generados';
        }

        // Si subió contrato firmado…
        if ($est === 'contratos_generados' && ! empty($contratos_firmados_nuevos[$sector])) {
            $est = 'contratos_firmados';
        }

        $estado_por_sector[$sector] = $est;
    }

    // ——— Una vez terminado el bucle de sectores, calculas global ———
    if ($is_send_action && ! $forzar) {
        // Comercial ha pulsado “Enviar cliente” → global siempre “enviado”
        $estado = 'enviado';
    } elseif (current_user_can('crm_admin') && $forzar && isset($_POST['estado'])) {
        // Admin con “forzar estado” → toma el <select name="estado">
        $estado = sanitize_text_field($_POST['estado']);
    } else {
        // Flujo normal → mínimo de todos los sectores
        $estado = crm_calcula_estado_global($estado_por_sector);
    }


    // Datos para guardar/actualizar
    $data = [
        'delegado'             => sanitize_text_field($_POST['delegado']),
        'user_id'              => $client_data['user_id'] ?? get_current_user_id(),
        'email_comercial'      => sanitize_email($_POST['email_comercial']),
        'cliente_nombre'       => sanitize_text_field($_POST['cliente_nombre']),
        'empresa'              => sanitize_text_field($_POST['empresa']),
        'direccion'            => sanitize_text_field($_POST['direccion']),
        'telefono'             => sanitize_text_field($_POST['telefono']),
        'email_cliente'        => sanitize_email($_POST['email_cliente']),
        'poblacion'            => sanitize_text_field($_POST['poblacion']),
        'area'                 => sanitize_text_field($_POST['area']),
        'tipo'                 => sanitize_text_field($_POST['tipo']),
        'comentarios'          => sanitize_text_field($_POST['comentarios']),
        'intereses'            => maybe_serialize($_POST['intereses'] ?? []),
        'facturas'             => maybe_serialize($facturas_existentes),
        'presupuesto'          => maybe_serialize($presupuestos_existentes),
        'contratos_firmados'   => maybe_serialize($contratos_firmados_existentes),
        'contratos_generados'  => maybe_serialize($contratos_generados),
        'estado'               => $estado,
        'estado_por_sector'   => maybe_serialize($estado_por_sector),
        'editado_por'          => get_current_user_id(),
        'actualizado_en'       => current_time('mysql'),
    ];

    if ($estado === 'enviado') {
        $data['enviado_por'] = get_current_user_id();
        $data['fecha_enviado'] = current_time('mysql');
        /* ⬇️  Borra estas 4 líneas ⬇️ */
        // if ($es_reenvio) {
        //     $data['reenvios'] = intval($client_data['reenvios'] ?? 0) + 1;
        // } else {
        //     $data['reenvios'] = 0;
        // }
    }

    if ($client_id) {
        $updated = $wpdb->update($table_name, $data, ['id' => $client_id]);
        if ($updated === false) {
            wp_send_json_error(['message' => 'Error al actualizar el cliente.']);
        }
    } else {
        $data['creado_por'] = get_current_user_id();
        $data['creado_en'] = current_time('mysql');
        $inserted = $wpdb->insert($table_name, $data);
        if ($inserted === false) {
            wp_send_json_error(['message' => 'Error al crear el cliente.']);
        }
        $client_id = $wpdb->insert_id;
    }

    $redirect_url = current_user_can('crm_admin')
        ? home_url("/todas-las-altas-de-cliente/?status=success&id=$client_id&estado=$estado")
        : home_url("/mis-altas-de-cliente/?status=success&id=$client_id&estado=$estado");

    wp_send_json_success([
        'message' => 'Cliente procesado correctamente.',
        'estado'  => $estado,
        'redirect_url' => $redirect_url,
    ]);
}


/**
 * Función genérica para subir archivos via AJAX.
 *
 * @param string $tipo Tipo de archivo a subir (factura, presupuesto, contrato_firmado).
 */
function crm_subir_archivo_generico($tipo)
{
    // Verificar permisos: ajustar según tus necesidades
    if (!current_user_can('crm_admin') && !current_user_can('comercial')) {
        wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción.']);
        exit;
    }

    // Validar la solicitud: verificar si los datos necesarios están presentes y el nonce es válido
    if (
        !isset($_POST['sector']) ||
        !isset($_FILES['file']) ||
        !isset($_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'crm_alta_cliente_nonce')
    ) {
        wp_send_json_error(['message' => 'Solicitud no válida. Error de seguridad o datos faltantes.']);
        exit;
    }

    // Sanitizar los datos recibidos
    $sector = sanitize_text_field($_POST['sector']);
    $file = $_FILES['file'];

    // Validar errores de subida
    if ($file['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => 'Error en la subida del archivo: ' . $file['error']]);
        exit;
    }

    // Comprobar el tamaño del archivo (10 MB máximo)
    $max_file_size = 10 * 1024 * 1024; // 10 MB
    if ($file['size'] > $max_file_size) {
        wp_send_json_error(['message' => 'El archivo excede el tamaño permitido de 10 MB.']);
        exit;
    }

    // Validar tipos de archivo permitidos
    $allowed_file_types = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($file['type'], $allowed_file_types)) {
        wp_send_json_error(['message' => 'Tipo de archivo no permitido. Solo se permiten JPEG, PNG y PDF.']);
        exit;
    }

    // Obtener la información del directorio de subida de WordPress
    $upload_dir = wp_upload_dir();

    // Verificar si el directorio de subidas es escribible
    if (!is_writable($upload_dir['basedir'])) {
        wp_send_json_error(['message' => 'Error en el servidor. El directorio de subidas no es escribible.']);
        exit;
    }

    // Asegurarse de que el nombre del archivo sea único
    $file_name = sanitize_file_name($file['name']);
    $unique_file_name = wp_unique_filename($upload_dir['path'], $file_name);

    // Establecer la ruta completa para guardar el archivo
    $file_path = $upload_dir['path'] . '/' . $unique_file_name;

    // Mover el archivo al directorio de subidas
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        wp_send_json_error(['message' => 'Error al mover el archivo al directorio de subidas.']);
        exit;
    }

    // Generar la URL del archivo subido
    $file_url = $upload_dir['url'] . '/' . $unique_file_name;

    // Respuesta exitosa
    wp_send_json_success([
        'sector' => $sector,
        'url'    => esc_url($file_url), // URL del archivo subido
        'name'   => sanitize_text_field(basename($file_url)) // Nombre del archivo
    ]);
}

/**
 * Función genérica para eliminar archivos via AJAX.
 *
 * @param string $tipo Tipo de archivo a eliminar (factura, presupuesto, contrato_firmado).
 */
function crm_eliminar_archivo_generico($tipo)
{
    error_log('POST recibido para eliminar ' . $tipo . ': ' . print_r($_POST, true)); // Registro de los datos recibidos

    // Verificar permisos: ajustar según tus necesidades
    if (!current_user_can('crm_admin') && !current_user_can('comercial')) {
        wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción.']);
        return;
    }

    if (!isset($_POST['url']) || !isset($_POST['nonce'])) {
        wp_send_json_error(['message' => 'URL o nonce faltantes en la solicitud.']);
        return;
    }

    if (!wp_verify_nonce($_POST['nonce'], 'crm_alta_cliente_nonce')) {
        wp_send_json_error(['message' => 'Nonce no válido.']);
        return;
    }

    $file_url = sanitize_text_field($_POST['url']);
    $upload_dir = wp_upload_dir();
    $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);

    if (file_exists($file_path)) {
        if (unlink($file_path)) {
            wp_send_json_success(['message' => ucfirst($tipo) . ' eliminado correctamente.']);
        } else {
            wp_send_json_error(['message' => 'Error al eliminar el ' . $tipo . '.']);
        }
    } else {
        wp_send_json_error(['message' => ucfirst($tipo) . ' no encontrado.']);
    }
}

// Registrar acciones AJAX para subir archivos
add_action('wp_ajax_crm_subir_factura', function () {
    crm_subir_archivo_generico('factura');
});

add_action('wp_ajax_crm_subir_presupuesto', function () {
    crm_subir_archivo_generico('presupuesto');
});

add_action('wp_ajax_crm_subir_contrato_firmado', function () {
    crm_subir_archivo_generico('contrato_firmado');
});

// Registrar acciones AJAX para eliminar archivos
add_action('wp_ajax_crm_eliminar_factura', function () {
    crm_eliminar_archivo_generico('factura');
});

add_action('wp_ajax_crm_eliminar_presupuesto', function () {
    crm_eliminar_archivo_generico('presupuesto');
});

add_action('wp_ajax_crm_eliminar_contrato_firmado', function () {
    crm_eliminar_archivo_generico('contrato_firmado');
});





add_shortcode('crm_lista_altas', 'crm_lista_altas');
function crm_lista_altas()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para ver esta sección.</p>";
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    $requested_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

    // Determinar si el usuario puede ver las altas de otro comercial
    $current_user_id = get_current_user_id();
    if ($requested_user_id && (!current_user_can('crm_admin') || $requested_user_id === $current_user_id)) {
        $user_id = $current_user_id;
    } else {
        $user_id = $requested_user_id ?: $current_user_id;
    }


    // Obtener los datos de los clientes
    $wpdb->flush(); // Limpiar cualquier caché previa del objeto
    $clientes = $wpdb->get_results($wpdb->prepare("
        SELECT id, fecha, cliente_nombre, empresa, direccion, poblacion, email_cliente, estado, actualizado_en, facturas, presupuesto, 
    contratos_firmados, intereses, estado_por_sector, reenvios
        FROM $table_name
        WHERE user_id = %d
        ORDER BY actualizado_en DESC
    ", $user_id), ARRAY_A);

    if (empty($clientes)) {
        return "<p>No hay clientes registrados.</p>";
    }

    // Construir la tabla en HTML
    ob_start();
?>
    <table id="crm-lista-altas" class="crm-table material-design ">
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Facturas</th>
                <th>Presupuestos</th>

                <th>Intereses</th>
                <th>Estado</th>
                <th>Estado / Sector</th>
                <th>Última Edición</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>


<?php
    return ob_get_clean();
}

add_action('wp_ajax_crm_obtener_altas', 'crm_obtener_altas');
function crm_obtener_altas()
{
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Debes iniciar sesión para acceder a esta funcionalidad.']);
        return;
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Permiso denegado.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";
    //$current_user_id = get_current_user_id();
    $requested_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;

    // Determinar si el usuario puede ver las altas de otro comercial
    $current_user_id = get_current_user_id();
    if ($requested_user_id && (!current_user_can('crm_admin') || $requested_user_id === $current_user_id)) {
        $user_id = $current_user_id;
    } else {
        $user_id = $requested_user_id ?: $current_user_id;
    }
    $user_info = get_userdata($user_id);
    $user_name = $user_info ? $user_info->display_name : 'Usuario desconocido';

    $clientes = $wpdb->get_results($wpdb->prepare("
   SELECT id, fecha, cliente_nombre, empresa, email_cliente, estado, actualizado_en, 
          facturas, presupuesto, contratos_firmados, contratos_generados, intereses, estado_por_sector, reenvios
   FROM $table_name
   WHERE user_id = %d
   ORDER BY actualizado_en DESC
", $user_id), ARRAY_A);


    if (!empty($clientes)) {
        foreach ($clientes as &$cliente) {
            // Siempre array
            $cliente['facturas']           = is_array($cliente['facturas'])           ? $cliente['facturas']           : (empty($cliente['facturas']) ? [] : maybe_unserialize($cliente['facturas']));
            $cliente['presupuesto']        = is_array($cliente['presupuesto'])        ? $cliente['presupuesto']        : (empty($cliente['presupuesto']) ? [] : maybe_unserialize($cliente['presupuesto']));
            $cliente['contratos_firmados'] = is_array($cliente['contratos_firmados']) ? $cliente['contratos_firmados'] : (empty($cliente['contratos_firmados']) ? [] : maybe_unserialize($cliente['contratos_firmados']));
            $cliente['contratos_generados'] = is_array($cliente['contratos_generados']) ? $cliente['contratos_generados'] : (empty($cliente['contratos_generados']) ? [] : maybe_unserialize($cliente['contratos_generados']));
            $cliente['intereses']          = is_array($cliente['intereses'])          ? $cliente['intereses']          : (empty($cliente['intereses']) ? [] : maybe_unserialize($cliente['intereses']));

            // Estado por sector: puede ser array, JSON, string, o vacío
            $eps = $cliente['estado_por_sector'];
            if (is_array($eps)) {
                $cliente['estado_por_sector'] = $eps;
            } elseif (is_string($eps) && !empty($eps)) {
                $tmp = maybe_unserialize($eps);
                if (is_array($tmp)) {
                    $cliente['estado_por_sector'] = $tmp;
                } else {
                    $decoded = json_decode($eps, true);
                    $cliente['estado_por_sector'] = is_array($decoded) ? $decoded : [];
                }
            } else {
                $cliente['estado_por_sector'] = [];
            }
        }

        wp_send_json_success([
            'clientes' => $clientes,
            'user_name' => $user_name  // Pasamos el nombre del usuario al frontend
        ]);
    } else {
        wp_send_json_success(['clientes' => [], 'user_name' => $user_name]); // Enviar el nombre del usuario
    }
}






add_shortcode('crm_editar_cliente', 'crm_editar_cliente');
function crm_editar_cliente()
{
    if (!is_user_logged_in()) {
        return "<p>Debes iniciar sesión para editar clientes.</p>";
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    // Verificar si hay un ID de cliente en la URL
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    if (!$client_id) {
        return "<p>ID de cliente no especificado.</p>";
    }

    // Obtener los datos del cliente
    $client_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A);

    if (!$client_data) {
        return "<p>Cliente no encontrado.</p>";
    }

    // Asegurarse de que el usuario tenga permiso para editar
    if (
        (empty($client_data['user_id']) || intval($client_data['user_id']) !== get_current_user_id()) &&
        !current_user_can('crm_admin')
    ) {
        return "<p>No tienes permisos para editar este cliente.</p>";
    }



    // Reutilizamos el formulario de alta cliente con los datos del cliente cargados
    ob_start();
?>
    <h2>Editar Cliente: <?php echo esc_html($client_data['cliente_nombre']); ?></h2>
    <?php echo do_shortcode('[crm_alta_cliente]'); ?>
<?php
    return ob_get_clean();
}


add_shortcode('todas_las_altas', 'crm_todas_las_altas');
function crm_todas_las_altas()
{
    if (!is_user_logged_in() || !current_user_can('crm_admin')) {
        return "<p>No tienes permiso para ver esta sección.</p>";
    }

    ob_start();
?>
    <table id="crm-todas-las-altas" class="crm-table material-design">
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Comercial</th>
                <th>Intereses</th>
                <th>Estado</th>
                <th>Documentos</th>
                <th>Última Edición</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <!-- Los datos serán añadidos dinámicamente por AJAX -->
        </tbody>
    </table>
    <style>
        label,
        select {
            font-size: 12px;
            padding: 2px;
            margin: 0px;
            line-height: 1em;
        }
    </style>

<?php
    return ob_get_clean();
}

add_action('wp_ajax_crm_obtener_todas_altas', 'crm_obtener_todas_altas');
function crm_obtener_todas_altas()
{
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No tienes permiso para realizar esta acción.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    $clientes = $wpdb->get_results("
        SELECT c.id, c.fecha, c.user_id, c.cliente_nombre, c.empresa, c.direccion, c.poblacion, c.intereses, c.email_cliente, c.facturas, c.presupuesto, c.contratos_generados, c.contratos_firmados, c.estado, c.estado_por_sector, c.reenvios, c.actualizado_en, u.display_name AS comercial
        FROM $table_name c
        LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
        ORDER BY c.actualizado_en DESC
    ", ARRAY_A);

    if (! empty($wpdb->last_error)) {
        error_log('CRM – MySQL error: ' . $wpdb->last_error);
    }

    if (!empty($clientes)) {
        foreach ($clientes as &$cliente) {
            // Siempre array
            $cliente['facturas']           = is_array($cliente['facturas'])           ? $cliente['facturas']           : (empty($cliente['facturas']) ? [] : maybe_unserialize($cliente['facturas']));
            $cliente['presupuesto']        = is_array($cliente['presupuesto'])        ? $cliente['presupuesto']        : (empty($cliente['presupuesto']) ? [] : maybe_unserialize($cliente['presupuesto']));
            $cliente['contratos_firmados'] = is_array($cliente['contratos_firmados']) ? $cliente['contratos_firmados'] : (empty($cliente['contratos_firmados']) ? [] : maybe_unserialize($cliente['contratos_firmados']));
            $cliente['contratos_generados'] = is_array($cliente['contratos_generados']) ? $cliente['contratos_generados'] : (empty($cliente['contratos_generados']) ? [] : maybe_unserialize($cliente['contratos_generados']));
            $cliente['intereses']          = is_array($cliente['intereses'])          ? $cliente['intereses']          : (empty($cliente['intereses']) ? [] : maybe_unserialize($cliente['intereses']));

            // Estado por sector: puede ser array, JSON, string, o vacío
            $eps = $cliente['estado_por_sector'];
            if (is_array($eps)) {
                $cliente['estado_por_sector'] = $eps;
            } elseif (is_string($eps) && !empty($eps)) {
                $tmp = maybe_unserialize($eps);
                if (is_array($tmp)) {
                    $cliente['estado_por_sector'] = $tmp;
                } else {
                    $decoded = json_decode($eps, true);
                    $cliente['estado_por_sector'] = is_array($decoded) ? $decoded : [];
                }
            } else {
                $cliente['estado_por_sector'] = [];
            }
        }

        wp_send_json_success($clientes);
    } else {
        wp_send_json_error(['message' => 'No se encontraron clientes.']);
    }
}

add_action('wp_ajax_crm_borrar_cliente', 'crm_borrar_cliente');
function crm_borrar_cliente()
{
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No tienes permiso para realizar esta acción.']);
        return;
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Error de seguridad.']);
        return;
    }

    $client_id = intval($_POST['client_id']);
    if (!$client_id) {
        wp_send_json_error(['message' => 'ID de cliente no válido.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    $deleted = $wpdb->delete($table_name, ['id' => $client_id]);

    if ($deleted) {
        wp_send_json_success(['message' => 'Cliente eliminado correctamente.']);
    } else {
        wp_send_json_error(['message' => 'Error al eliminar el cliente.']);
    }
}


// Helper para procesar archivos subidos
function process_uploaded_files($files, $existing_files = [])
{
    $processed_files = is_array($existing_files) ? $existing_files : [];

    if (!isset($files) || !is_array($files)) {
        return $processed_files;
    }

    foreach ($files as $sector => $file_group) {
        if (!isset($file_group['name']) || !is_array($file_group['name'])) {
            continue;
        }

        foreach ($file_group['name'] as $key => $name) {
            if (!empty($name) && $file_group['error'][$key] === UPLOAD_ERR_OK) {


                $file = [
                    'name' => $name,
                    'type' => $file_group['type'][$key],
                    'tmp_name' => $file_group['tmp_name'][$key],
                    'error' => $file_group['error'][$key],
                    'size' => $file_group['size'][$key],
                ];

                $upload = wp_handle_upload($file, ['test_form' => false]);
                if (!isset($upload['error']) && isset($upload['url'])) {
                    if (!isset($processed_files[$sector])) {
                        $processed_files[$sector] = [];
                    }
                    $processed_files[$sector][] = $upload['url'];
                }
            }
        }
    }

    return $processed_files;
}
