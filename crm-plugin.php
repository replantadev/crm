<?php
/*
Plugin Name: CRM Básico
Plugin URI: https://github.com/replantadev/crm/
Description: Plugin para gestionar clientes con roles de comercial y administrador CRM. Incluye actualizaciones automáticas desde GitHub.
Version: 1.6.2
Author: Luis Javier
Author URI: https://github.com/replantadev
Update URI: https://github.com/replantadev/crm/
Requires at least: 5.0
Tested up to: 6.3
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: crm-basico
Domain Path: /languages
Network: false
*/

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('CRM_PLUGIN_VERSION', '1.6.2');
define('CRM_PLUGIN_FILE', __FILE__);
define('CRM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CRM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Definir constante para GitHub token si no existe (para repositorios privados)
if (!defined('CRM_GITHUB_TOKEN')) {
    define('CRM_GITHUB_TOKEN', ''); // Dejar vacío para repositorios públicos
}

// Actualizaciones automáticas desde GitHub
if (file_exists(CRM_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once CRM_PLUGIN_PATH . 'vendor/autoload.php';
}

if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/replantadev/crm/',
        CRM_PLUGIN_FILE,
        'crm-basico'
    );
    
    // Si el repositorio es privado y hay token, usarlo para autenticación
    if (defined('CRM_GITHUB_TOKEN') && !empty(CRM_GITHUB_TOKEN)) {
        $updateChecker->setAuthentication(CRM_GITHUB_TOKEN);
    }
    
    // Configurar la rama principal
    $updateChecker->setBranch('master');
    
    // Hook para limpiar caché después de actualizar
    $updateChecker->addFilter('upgrader_process_complete', function() {
        // Limpiar caché de WordPress después de actualizar
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Limpiar opciones transitorias del plugin
        delete_transient('crm_plugin_cache');
        
        // Forzar regeneración de archivos estáticos
        update_option('crm_last_update', time());
    });
}

// Incluir archivos del plugin
require_once CRM_PLUGIN_PATH . 'acceso.php';
require_once CRM_PLUGIN_PATH . 'shortcodes.php';

add_action('wp_enqueue_scripts', 'crm_enqueue_styles');
function crm_enqueue_styles()
{
    wp_enqueue_style('crm-styles', CRM_PLUGIN_URL . 'crm-styles.css', [], CRM_PLUGIN_VERSION);
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

        // Encolar el script personalizado para inicializar DataTables con versión
        wp_enqueue_script('crm-lista-altas-js', CRM_PLUGIN_URL . 'js/crm-lista-altas2.js', ['jquery', 'datatables-js'], CRM_PLUGIN_VERSION, true);

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
        wp_enqueue_script('todas-las-altas-js', CRM_PLUGIN_URL . 'js/todas-las-altasv2.js', ['jquery', 'datatables-js'], CRM_PLUGIN_VERSION, true);

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



// Crear shortcode para el formulario de alta de cliente

// Encolar y localizar el script JavaScript en el shortcode
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

    $facturas = isset($client_data['facturas']) ? maybe_unserialize($client_data['facturas']) : [];
    $presupuestos = isset($client_data['presupuesto']) ? maybe_unserialize($client_data['presupuesto']) : [];
    $contratos_firmados = isset($client_data['contratos_firmados']) ? maybe_unserialize($client_data['contratos_firmados']) : [];
    $intereses = isset($client_data['intereses']) ? maybe_unserialize($client_data['intereses']) : [];

    // Asegurar que `facturas`, `presupuestos`, `contratos_firmados`, e `intereses` sean arrays
    $facturas = is_array($facturas) ? $facturas : [];
    $presupuestos = is_array($presupuestos) ? $presupuestos : [];
    $contratos_firmados = is_array($contratos_firmados) ? $contratos_firmados : [];
    $intereses = is_array($intereses) ? $intereses : [];

    // Encolar el script JavaScript y localizar datos
    wp_enqueue_script('crm-scriptv2', CRM_PLUGIN_URL . 'js/crm-scriptv7.js', array('jquery'), CRM_PLUGIN_VERSION, true);
    wp_localize_script('crm-scriptv2', 'crmData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('crm_alta_cliente_nonce'),
        'is_admin' => current_user_can('crm_admin'),
        'current_estado' => $estado_actual,
    ));

    ob_start();
?>
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
            <div id="presupuestos-container">
                <?php
                foreach ($sectores as $sector) {
                    $is_visible = in_array($sector, $intereses) ? 'block' : 'none';
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
                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <?php if (current_user_can('crm_admin') && in_array($estado_actual, ['presupuesto_aceptado', 'contratos_generados',  'contratos_firmados'])): ?>
            <!-- Contratos Generados -->
            <div class="crm-section contratos-generados">
                <h3>Contratos Generados</h3>
                <div class="contratos-generados-container">
                    <?php
                    // Asegurar que $contratos_generados es un array
                    $contratos_generados = isset($client_data['contratos_generados']) ? maybe_unserialize($client_data['contratos_generados']) : [];
                    $contratos_generados = is_array($contratos_generados) ? $contratos_generados : [];

                    foreach ($sectores as $sector) {
                        $checked = in_array($sector, $contratos_generados) ? 'checked' : '';
                        echo "<input type='checkbox' id='contrato-generado-{$sector}' name='contratos_generados[]' value='{$sector}' {$checked}>";
                        echo "<label for='contrato-generado-{$sector}'>" . ucfirst($sector) . "</label>";
                    }
                    ?>
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
                <select name="estado" id="estado" required>
                    <option value="" disabled <?php echo empty($estado_actual) ? 'selected' : ''; ?>>Selecciona un estado</option>
                    <option value="borrador" <?php selected($estado_actual, 'borrador'); ?>>Borrador</option>
                    <option value="enviado" <?php selected($estado_actual, 'enviado'); ?>>Enviado</option>
                    <option value="presupuesto_generado" <?php selected($estado_actual, 'presupuesto_generado'); ?>>Presupuesto Generado</option>
                    <option value="presupuesto_aceptado" <?php selected($estado_actual, 'presupuesto_aceptado'); ?>>Presupuesto Aceptado</option>
                    <option value="contratos_firmados" <?php selected($estado_actual, 'contratos_firmados'); ?>>Contratos Firmados</option>
                </select>
            </div>
        <?php endif; ?>

        <!-- Botón de Enviar -->

        <div class="crm-section enviar">
            <!-- Borrador o pendiente de revisión -->
            <?php if ($estado_actual === 'borrador' || $estado_actual === 'pendiente_revision'): ?>
                <button type="submit" name="crm_guardar_cliente" class="crm-submit-btn">
                    Guardar como borrador
                </button>
                <button type="submit" name="crm_enviar_cliente" class="crm-submit-btn enviar-btn">
                    Enviar para revisión
                </button>

                <!-- Ya se envió -->
            <?php elseif ($estado_actual === 'enviado'): ?>
                <p>Este cliente ya fue enviado. Puedes modificar los datos y volver a enviarlo si es necesario.</p>
                <button type="submit" name="crm_enviar_cliente" class="crm-submit-btn">
                    Guardar y volver a enviar
                </button>

                <!-- Presupuesto generado -->
            <?php elseif ($estado_actual === 'presupuesto_generado'): ?>
                <p>El presupuesto ya está generado. ¿Deseas marcarlo como aceptado?</p>
                <button type="submit"
                    name="crm_marcar_presupuesto_aceptado"
                    class="crm-submit-btn"
                    style="background-color: green; color: #fff;">
                    Marcar como Aceptado
                </button>

                <!-- Presupuesto aceptado -->
            <?php elseif ($estado_actual === 'presupuesto_aceptado'): ?>
                <p>El presupuesto ha sido aceptado. Puedes subir contratos o cambiar el estado.</p>
            <?php endif; ?>

            <!-- Botón especial "Guardar como (select)" — por defecto oculto -->
            <?php if (current_user_can('crm_admin')): ?>
                <button type="submit"
                    name="crm_guardar_como_estado"
                    id="admin-custom-button"
                    class="crm-submit-btn"
                    style="display: none; margin-left: 10px; background-color: #666; color:#fff;">
                    <!-- El texto se seteará dinámicamente por JS -->
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
    global $wpdb;
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

    if (isset($_FILES['factura']) && is_array($_FILES['factura']['name'])) {
        foreach ($_FILES['factura']['name'] as $sector => $files) {
            if (!is_array($files)) continue;

            foreach ($files as $key => $name) {
                if ($_FILES['factura']['error'][$sector][$key] === UPLOAD_ERR_OK && !empty($_FILES['factura']['tmp_name'][$sector][$key])) {
                    $upload = wp_handle_upload([
                        'name'     => $name,
                        'type'     => $_FILES['facturas']['type'][$sector][$key],
                        'tmp_name' => $_FILES['facturas']['tmp_name'][$sector][$key],
                        'error'    => $_FILES['facturas']['error'][$sector][$key],
                        'size'     => $_FILES['facturas']['size'][$sector][$key],
                    ], ['test_form' => false]);

                    if (!isset($upload['error'])) {
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

    if (!empty($_POST['factura'])) {
        foreach ($_POST['factura'] as $sector => $files) {
            if (!isset($facturas_existentes[$sector])) {
                $facturas_existentes[$sector] = [];
            }
            $facturas_existentes[$sector] = array_unique(array_merge($facturas_existentes[$sector], $files));
        }
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
                    $upload = wp_handle_upload([
                        'name'     => $name,
                        'type'     => $_FILES['presupuesto']['type'][$sector][$key],
                        'tmp_name' => $_FILES['presupuesto']['tmp_name'][$sector][$key],
                        'error'    => $_FILES['presupuesto']['error'][$sector][$key],
                        'size'     => $_FILES['presupuesto']['size'][$sector][$key],
                    ], ['test_form' => false]);

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

    if (!empty($_POST['presupuesto'])) {
        foreach ($_POST['presupuesto'] as $sector => $files) {
            if (!isset($presupuestos_existentes[$sector])) {
                $presupuestos_existentes[$sector] = [];
            }
            $presupuestos_existentes[$sector] = array_unique(array_merge($presupuestos_existentes[$sector], $files));
        }
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
                    $upload = wp_handle_upload([
                        'name'     => $name,
                        'type'     => $_FILES['contratos_firmados']['type'][$sector][$key],
                        'tmp_name' => $_FILES['contratos_firmados']['tmp_name'][$sector][$key],
                        'error'    => $_FILES['contratos_firmados']['error'][$sector][$key],
                        'size'     => $_FILES['contratos_firmados']['size'][$sector][$key],
                    ], ['test_form' => false]);

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

    if (!empty($_POST['contratos_firmados'])) {
        foreach ($_POST['contratos_firmados'] as $sector => $files) {
            if (!isset($contratos_firmados_existentes[$sector])) {
                $contratos_firmados_existentes[$sector] = [];
            }
            $contratos_firmados_existentes[$sector] = array_unique(array_merge($contratos_firmados_existentes[$sector], $files));
        }
    }


    // *** Procesar Contratos Generados ***
    $contratos_generados = isset($_POST['contratos_generados']) ? maybe_serialize($_POST['contratos_generados']) : '';

    if (current_user_can('crm_admin') && isset($_POST['estado'])) {
        $estado = sanitize_text_field($_POST['estado']);
        error_log("Admin ha seleccionado en el <select> el estado: $estado");
    }
    // 1) Ver si el usuario es admin y marcó “forzar_estado”
    if (
        current_user_can('crm_admin')
        && isset($_POST['forzar_estado'])           // si el checkbox está marcado
        && $_POST['forzar_estado'] == '1'           // y su valor es '1'
        && isset($_POST['estado'])                  // y existe el <select>
    ) {
        $estado = sanitize_text_field($_POST['estado']);
        error_log("Admin ha forzado el estado manualmente a: $estado");
    }

    // 2) Ahora continuamos con el FLUJO:
    // Determinar estado basado en flujo
    if ($estado === 'enviado' && !empty($presupuestos_nuevos)) {
        $estado = 'presupuesto_generado';
    } elseif ($estado === 'presupuesto_generado' && isset($_POST['presupuesto_aceptado'])) {

        $estado = 'presupuesto_aceptado';
    } elseif ($estado === 'presupuesto_aceptado' && !empty($contratos_firmados_nuevos)) {
        $estado = 'contratos_firmados';
    }

    if (isset($_POST['crm_marcar_presupuesto_aceptado'])) {
        // Sobrescribimos el $estado directamente
        $estado = 'presupuesto_aceptado';
        // error_log("Se ha forzado estado a presupuesto_aceptado por botón en el formulario.");
    }
    if (!isset($_POST['crm_marcar_presupuesto_aceptado'])) {
        if ($estado === 'enviado' && !empty($presupuestos_nuevos)) {
            $estado = 'presupuesto_generado';
        } elseif ($estado === 'presupuesto_generado' && isset($_POST['presupuesto_aceptado'])) {
            $estado = 'presupuesto_aceptado';
        } elseif ($estado === 'presupuesto_aceptado' && !empty($contratos_firmados_nuevos)) {
            $estado = 'contratos_firmados';
        }
    }
    // *** Lógica Basada en el Estado Actual ***
    switch ($estado) {
        case 'borrador':
            // No se requiere validación adicional
            error_log("Estado: Guardando como borrador.");
            break;

        case 'presupuesto_generado':
            if (empty($presupuestos_existentes)) {
                wp_send_json_error(['message' => 'No hay presupuestos subidos para generar el presupuesto.']);
                exit;
            }
            error_log("Estado: Presupuesto generado.");
            break;

        case 'presupuesto_aceptado':
            if (empty($presupuestos_existentes)) {
                wp_send_json_error(['message' => 'No hay presupuestos subidos para aceptar.']);
                exit;
            }
            error_log("Estado: Presupuesto aceptado.");
            break;

        case 'contratos_firmados':
            if (empty($contratos_firmados_existentes)) {
                wp_send_json_error(['message' => 'No hay contratos firmados subidos.']);
                exit;
            }
            error_log("Estado: Contratos firmados.");
            break;

        case 'enviado':
            if (empty($facturas_existentes)) {
                wp_send_json_error(['message' => 'No hay facturas subidas para enviar el cliente.']);
                exit;
            }
            error_log("Estado: Cliente enviado.");
            break;

        default:
            // Manejar estados desconocidos
            error_log("Estado desconocido: " . $estado);
            wp_send_json_error(['message' => 'Estado desconocido.']);
            exit;
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
        'contratos_generados'  => $contratos_generados,
        'estado'               => $estado,
        'editado_por'          => get_current_user_id(),
        'actualizado_en'       => current_time('mysql'),
    ];

    if ($estado === 'enviado') {
        $data['enviado_por'] = get_current_user_id();
        $data['fecha_enviado'] = current_time('mysql');
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
 * Marca el presupuesto como aceptado forzando el estado a 'presupuesto_aceptado',
 * y luego llama a la función principal crm_handle_ajax_request().
 */
add_action('wp_ajax_crm_marcar_presupuesto_aceptado', 'crm_marcar_presupuesto_aceptado_ajax');

function crm_marcar_presupuesto_aceptado_ajax() {
    try {
        // Forzamos el estado a 'presupuesto_aceptado'.
        $estado = 'presupuesto_aceptado';
        
        // Llamamos a la lógica principal para procesar la solicitud,
        // pasar archivos, guardar en BD, etc.
        crm_handle_ajax_request($estado);

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
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
        SELECT id, fecha, cliente_nombre, empresa, email_cliente, estado, actualizado_en, facturas, presupuesto, 
    contratos_firmados, intereses
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
    <table id="crm-lista-altas" class="crm-table material-design">
        <thead>
            <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Nombre</th>
                <th>Empresa</th>
                <th>Email</th>
                <th>Facturas</th>
                <th>Presupuestos</th> <!-- Agregar la columna de presupuestos -->
                <th>Contratos</th> <!-- Agregar la columna de contratos firmados -->
                <th>Intereses</th>
                <th>Estado</th>
                <th>Última Edición</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>

        </tbody>
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
          facturas, presupuesto, contratos_firmados, contratos_generados, intereses
   FROM $table_name
   WHERE user_id = %d
   ORDER BY actualizado_en DESC
", $user_id), ARRAY_A);


    if (!empty($clientes)) {
        foreach ($clientes as &$cliente) {
            $cliente['facturas'] = maybe_unserialize($cliente['facturas']);
            //$cliente['contratos'] = maybe_unserialize($cliente['contratos']);
            $cliente['intereses'] = maybe_unserialize($cliente['intereses']);
            // **AÑADE** la línea que faltaba:
            $cliente['presupuesto'] = maybe_unserialize($cliente['presupuesto']);
            $cliente['contratos_firmados'] = maybe_unserialize($cliente['contratos_firmados']);
            $cliente['contratos_generados'] = maybe_unserialize($cliente['contratos_generados']);
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
                <th>Nombre</th>
                <th>Empresa</th>
                <th>Email</th>
                <th>Comercial</th>
                <th>Intereses</th>
                <th>Estado</th>
                <th>Presupuestos</th>
                <th>Contratos Generados</th>
                <th>Contratos Firmados</th>
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
        SELECT c.id, c.fecha, c.user_id, c.cliente_nombre, c.empresa, c.intereses, c.email_cliente, c.presupuesto, c.contratos_generados, c.contratos_firmados, c.estado, c.actualizado_en, u.display_name AS comercial
        FROM $table_name c
        LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
        ORDER BY c.actualizado_en DESC
    ", ARRAY_A);

    if (!empty($clientes)) {
        foreach ($clientes as &$cliente) {
            $cliente['presupuesto'] = maybe_unserialize($cliente['presupuesto']);
            $cliente['contratos_generados'] = maybe_unserialize($cliente['contratos_generados']);
            $cliente['contratos_firmados'] = maybe_unserialize($cliente['contratos_firmados']);
            $cliente['intereses'] = maybe_unserialize($cliente['intereses']);
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
