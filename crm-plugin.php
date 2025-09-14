<?php
/*
Plugin Name: CRM Básico
Plugin URI: https://github.com/replantadev/crm/
Description: Plugin para gestionar clientes con roles de comercial y administrador CRM. Incluye actualizaciones automáticas desde GitHub.
Version: 1.7.5
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
define('CRM_PLUGIN_VERSION', '1.7.5');
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
    
    // Hook para limpiar archivos duplicados después de actualizar
    $updateChecker->addFilter('upgrader_process_complete', function() {
        // Función de limpieza automática de archivos duplicados
        crm_cleanup_duplicate_files();
        
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

// Función de limpieza automática de archivos duplicados
function crm_cleanup_duplicate_files() {
    $plugin_dir = CRM_PLUGIN_PATH;
    $files_to_remove = [
        'crm_plugin.php',           // Archivo duplicado anterior
        'js/crm-plugin_old.php',   // Archivo mal ubicado
        'js/crm-scriptv7_bk.js'    // Backup innecesario
    ];
    
    foreach ($files_to_remove as $file) {
        $file_path = $plugin_dir . $file;
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
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

        // Los datos ahora se definen directamente en el JavaScript inline de la tabla
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
 * Convierte nombres de estados técnicos a nombres legibles
 */
function crm_get_estado_label($estado) {
    $labels = [
        'borrador' => 'Borrador',
        'enviado' => 'Enviado',
        'pendiente_revision' => 'Pendiente Revisión',
        'aceptado' => 'Aceptado',
        'presupuesto_enviado' => 'Presupuesto Enviado',
        'presupuesto_generado' => 'Presupuesto Generado',
        'presupuesto_aceptado' => 'Presupuesto Aceptado',
        'contratos_firmados' => 'Contratos Firmados',
        'contratos_generados' => 'Contratos Generados',
        'cliente_convertido' => 'Cliente Convertido',
        'reunion_inicial' => 'Reunión Inicial',
        'cancelado' => 'Cancelado'
    ];
    
    return $labels[$estado] ?? ucfirst(str_replace('_', ' ', $estado));
}

/**
 * Convierte nombres de acciones técnicos a nombres legibles
 */
function crm_get_action_label($action) {
    $labels = [
        'cliente_creado' => 'Cliente Creado',
        'cliente_actualizado' => 'Cliente Actualizado',
        'cliente_eliminado' => 'Cliente Eliminado',
        'archivo_subido' => 'Archivo Subido',
        'archivo_eliminado' => 'Archivo Eliminado',
        'sectores_enviados' => 'Sectores Enviados',
        'test_email_enviado' => 'Test Email Enviado',
        'test_email_error' => 'Error Test Email',
        'backup_created' => 'Backup Creado',
        'database_optimized' => 'BD Optimizada',
        'logs_prueba_generados' => 'Logs de Prueba',
        'panel_consultado' => 'Panel Consultado',
        'sistema_inicializado' => 'Sistema Iniciado',
        'debug_sector_save' => 'Debug Guardado'
    ];
    
    return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/**
 * Validador de teléfono español (fijo y móvil)
 */
function crm_validate_spanish_phone($phone) {
    // Limpiar el número de espacios, guiones y paréntesis
    $clean_phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    
    // Patrones para números españoles
    $patterns = [
        // Móviles: 6XX XXX XXX o 7XX XXX XXX
        '/^(\+34)?[67]\d{8}$/',
        // Fijos: 9XX XXX XXX (Madrid, Barcelona, Valencia, etc.)
        '/^(\+34)?9\d{8}$/',
        // Fijos: 8XX XXX XXX (números especiales)
        '/^(\+34)?8\d{8}$/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $clean_phone)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Formatea un número de teléfono español
 */
function crm_format_spanish_phone($phone) {
    $clean_phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    
    // Quitar +34 si existe
    $clean_phone = preg_replace('/^\+34/', '', $clean_phone);
    
    if (strlen($clean_phone) === 9) {
        // Formatear como XXX XXX XXX
        return substr($clean_phone, 0, 3) . ' ' . substr($clean_phone, 3, 3) . ' ' . substr($clean_phone, 6, 3);
    }
    
    return $phone; // Devolver original si no se puede formatear
}

/**
 * Renderiza badges de estado por sector/interés.
 */
function crm_render_estado_badges($estado_por_sector = [], $sectores = [])
{
    $out = '';
    foreach ($sectores as $sector) {
        $estado = $estado_por_sector[$sector] ?? 'borrador';
        $estado_label = crm_get_estado_label($estado);
        $sector_label = ucfirst($sector);
        $out .= '<span class="crm-badge estado ' . $estado . '">' . $sector_label . ': ' . $estado_label . '</span>';
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
    $client_id   = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    $client_data = $client_id
        ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A)
        : null;

    $estado_actual        = $client_data['estado']              ?? 'borrador';
    $estado_por_sector    = maybe_unserialize($client_data['estado_por_sector']   ?? []);
    $facturas             = maybe_unserialize($client_data['facturas']            ?? []);
    $presupuestos         = maybe_unserialize($client_data['presupuesto']         ?? []);
    $contratos_firmados   = maybe_unserialize($client_data['contratos_firmados']  ?? []);
    $contratos_generados  = maybe_unserialize($client_data['contratos_generados'] ?? []);
    $intereses            = maybe_unserialize($client_data['intereses']           ?? []);

    // Arrays asegurados
    $sectores      = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    $facturas            = is_array($facturas)           ? $facturas           : [];
    $presupuestos        = is_array($presupuestos)       ? $presupuestos       : [];
    $contratos_firmados  = is_array($contratos_firmados) ? $contratos_firmados : [];
    $contratos_generados = is_array($contratos_generados) ? $contratos_generados : [];
    $intereses           = is_array($intereses)          ? $intereses          : [];
    $estado_por_sector   = is_array($estado_por_sector)  ? $estado_por_sector  : [];

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

    <div class="crm-form-container">
        <div class="crm-header">
            <h2><img src="<?php echo get_site_icon_url(); ?>" alt="Logo" class="crm-logo"> Energitel CRM - Alta de Cliente</h2>
            
            <!-- Estado actual -->
            <?php if($client_id): ?>
                <div style="text-align: center; margin-top: 16px;">
                    <span class="estado <?php echo $estado_actual; ?>"><?php echo crm_get_estado_label($estado_actual); ?></span>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="crm-form-content">

    <script>
    // Validación de teléfono en tiempo real
    document.addEventListener('DOMContentLoaded', function() {
        const phoneInput = document.getElementById('telefono');
        if (phoneInput) {
            phoneInput.addEventListener('input', function() {
                const value = this.value.replace(/\s/g, '');
                const isValid = /^(\+34)?[6789]\d{8}$/.test(value);
                
                if (isValid) {
                    this.classList.add('valid');
                    this.classList.remove('invalid');
                } else if (value.length > 0) {
                    this.classList.add('invalid');
                    this.classList.remove('valid');
                } else {
                    this.classList.remove('valid', 'invalid');
                }
            });
            
            // Formatear teléfono al perder el foco
            phoneInput.addEventListener('blur', function() {
                let value = this.value.replace(/[\s\-\(\)]/g, '');
                value = value.replace(/^\+34/, '');
                
                if (value.length === 9) {
                    this.value = value.substring(0, 3) + ' ' + value.substring(3, 6) + ' ' + value.substring(6);
                }
            });
        }
        
        // Autocompletado de poblaciones
        const poblacionInput = document.getElementById('poblacion');
        const provinciaSelect = document.getElementById('provincia');
        const suggestionsDiv = document.getElementById('poblacion-suggestions');
        let currentSelection = -1;
        
        if (poblacionInput && provinciaSelect && suggestionsDiv) {
            poblacionInput.addEventListener('input', debounce(function(e) {
                const query = e.target.value ? e.target.value.trim() : '';
                const provincia = provinciaSelect.value;
                
                if (query.length >= 2 && provincia) {
                    searchMunicipalities(query, provincia);
                } else {
                    hideSuggestions();
                }
            }, 300));
            
            poblacionInput.addEventListener('keydown', function(e) {
                const suggestions = suggestionsDiv.querySelectorAll('.autocomplete-suggestion');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentSelection = Math.min(currentSelection + 1, suggestions.length - 1);
                    updateSelection(suggestions);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentSelection = Math.max(currentSelection - 1, -1);
                    updateSelection(suggestions);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentSelection >= 0 && suggestions[currentSelection]) {
                        selectSuggestion(suggestions[currentSelection].textContent);
                    }
                } else if (e.key === 'Escape') {
                    hideSuggestions();
                }
            });
            
            document.addEventListener('click', function(e) {
                if (!poblacionInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                    hideSuggestions();
                }
            });
        }
        
        function searchMunicipalities(query, provincia) {
            // Lista estática de municipios de León para demo
            const leonMunicipalities = [
                'León', 'Ponferrada', 'San Andrés del Rabanedo', 'Villaquilambre', 'Astorga',
                'La Bañeza', 'Valencia de Don Juan', 'Sahagún', 'Villablino', 'Bembibre',
                'Cacabelos', 'Toral de los Guzmanes', 'Mansilla de las Mulas', 'Boñar',
                'Riaño', 'Puente de Domingo Flórez', 'Villafranca del Bierzo', 'Cistierna',
                'La Robla', 'Santa María del Monte de Cea', 'Gradefes', 'Carrizo',
                'Benavides', 'Quintana del Castillo', 'Santas Martas', 'Chozas de Abajo',
                'Valverde de la Virgen', 'Cuadros', 'Sariegos', 'Carrocera'
            ];
            
            const filtered = leonMunicipalities.filter(municipality => 
                municipality.toLowerCase().includes(query.toLowerCase())
            ).slice(0, 10);
            
            showSuggestions(filtered);
        }
        
        function showSuggestions(suggestions) {
            currentSelection = -1;
            suggestionsDiv.innerHTML = '';
            
            if (suggestions.length === 0) {
                hideSuggestions();
                return;
            }
            
            suggestions.forEach(suggestion => {
                const div = document.createElement('div');
                div.className = 'autocomplete-suggestion';
                div.textContent = suggestion;
                div.addEventListener('click', () => selectSuggestion(suggestion));
                suggestionsDiv.appendChild(div);
            });
            
            suggestionsDiv.style.display = 'block';
        }
        
        function hideSuggestions() {
            suggestionsDiv.style.display = 'none';
            currentSelection = -1;
        }
        
        function selectSuggestion(suggestion) {
            poblacionInput.value = suggestion;
            hideSuggestions();
            poblacionInput.focus();
        }
        
        function updateSelection(suggestions) {
            suggestions.forEach((suggestion, index) => {
                suggestion.classList.toggle('selected', index === currentSelection);
            });
        }
        
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    });
    </script>

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
                <p>Estado: <strong class="estado <?php echo $estado_actual; ?>"><?php echo crm_get_estado_label($estado_actual); ?></strong></p>
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
                    <input type="tel" 
                           name="telefono" 
                           id="telefono" 
                           placeholder="Teléfono (ej: 987 123 456)" 
                           value="<?php echo esc_attr($client_data['telefono'] ?? ''); ?>"
                           pattern="^(\+34\s?)?[6789]\d{2}\s?\d{3}\s?\d{3}$"
                           title="Ingrese un teléfono español válido (móvil: 6XX/7XX XXX XXX, fijo: 9XX XXX XXX)"
                           maxlength="15">
                    <small class="phone-help">Móvil: 6XX/7XX XXX XXX | Fijo: 9XX XXX XXX</small>
                </div>
                <div class="form-group half-width">
                    <input type="email" 
                           name="email_cliente" 
                           placeholder="Email del cliente" 
                           value="<?php echo esc_attr($client_data['email_cliente'] ?? ''); ?>"
                           required
                           pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$"
                           title="Ingrese un email válido (ejemplo: usuario@dominio.com)"
                           maxlength="100">
                </div>
                <div class="form-group half-width">
                    <select name="provincia" id="provincia" required class="form-select">
                        <option value="" disabled>Seleccionar Provincia</option>
                        <option value="León" <?php echo isset($client_data['provincia']) && $client_data['provincia'] === 'León' ? 'selected' : (!isset($client_data['provincia']) ? 'selected' : ''); ?>>León</option>
                        <option value="Madrid">Madrid</option>
                        <option value="Barcelona">Barcelona</option>
                        <option value="Valencia">Valencia</option>
                        <option value="Sevilla">Sevilla</option>
                        <option value="Zaragoza">Zaragoza</option>
                        <option value="Málaga">Málaga</option>
                        <option value="Murcia">Murcia</option>
                        <option value="Palma">Palma</option>
                        <option value="Las Palmas">Las Palmas</option>
                        <option value="Bilbao">Bilbao</option>
                        <option value="Alicante">Alicante</option>
                        <option value="Córdoba">Córdoba</option>
                        <option value="Valladolid">Valladolid</option>
                        <option value="Vigo">Vigo</option>
                        <option value="Gijón">Gijón</option>
                        <option value="Hospitalet">Hospitalet</option>
                        <option value="Coruña">Coruña</option>
                        <option value="Vitoria">Vitoria</option>
                        <option value="Granada">Granada</option>
                        <option value="Elche">Elche</option>
                        <option value="Oviedo">Oviedo</option>
                        <option value="Badalona">Badalona</option>
                        <option value="Cartagena">Cartagena</option>
                        <option value="Terrassa">Terrassa</option>
                        <option value="Jerez">Jerez</option>
                        <option value="Sabadell">Sabadell</option>
                        <option value="Móstoles">Móstoles</option>
                        <option value="Alcalá de Henares">Alcalá de Henares</option>
                        <option value="Pamplona">Pamplona</option>
                        <option value="Fuenlabrada">Fuenlabrada</option>
                        <option value="Almería">Almería</option>
                        <option value="Leganés">Leganés</option>
                        <option value="Santander">Santander</option>
                        <option value="Burgos">Burgos</option>
                        <option value="Castellón">Castellón</option>
                        <option value="Getafe">Getafe</option>
                        <option value="Alcorcón">Alcorcón</option>
                        <option value="Albacete">Albacete</option>
                        <option value="Logroño">Logroño</option>
                        <option value="Badajoz">Badajoz</option>
                        <option value="Salamanca">Salamanca</option>
                        <option value="Huelva">Huelva</option>
                        <option value="Marbella">Marbella</option>
                        <option value="Tarragona">Tarragona</option>
                        <option value="Lleida">Lleida</option>
                        <option value="Jaén">Jaén</option>
                        <option value="Ourense">Ourense</option>
                        <option value="Reus">Reus</option>
                        <option value="Torrelavega">Torrelavega</option>
                        <option value="Elda">Elda</option>
                        <option value="Mérida">Mérida</option>
                    </select>
                </div>
                <div class="form-group half-width">
                    <div class="autocomplete-container">
                        <input type="text" 
                               name="poblacion" 
                               id="poblacion" 
                               placeholder="Población (empiece a escribir...)" 
                               value="<?php echo esc_attr($client_data['poblacion'] ?? ''); ?>"
                               class="form-input-autocomplete"
                               autocomplete="off">
                        <div id="poblacion-suggestions" class="autocomplete-suggestions"></div>
                        <small class="population-help">Escriba el nombre del municipio</small>
                    </div>
                </div>
                <div class="form-group half-width">
                    <select name="tipo" required>
                        <option value="" disabled <?php echo !isset($client_data['tipo']) || empty($client_data['tipo']) ? 'selected' : ''; ?>>Tipo</option>
                        <option value="Residencial" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'Residencial' ? 'selected' : ''; ?>>Residencial</option>
                        <option value="Autónomo" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'Autónomo' ? 'selected' : ''; ?>>Autónomo</option>
                        <option value="Empresa" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'Empresa' ? 'selected' : ''; ?>>Empresa</option>
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

                foreach ($sectores as $sector) {
                    $checked = in_array($sector, $intereses) ? 'checked' : '';
                    echo "<input type='checkbox' id='interes-{$sector}' name='intereses[]' value='{$sector}' {$checked}>";
                    echo "<label for='interes-{$sector}'>" . ucfirst($sector) . "</label>";
                }
                ?>
            </div>
        </div>

        <!-- ——— Cards por Sector ——— -->
        <div class="crm-cards-sectores">
            <?php foreach ($sectores as $sector):
                $secLabel   = ucfirst($sector);
                $estado_sec = $estado_por_sector[$sector] ?? 'borrador';
                $filesF     = $facturas[$sector]       ?? [];
                $filesP     = $presupuestos[$sector]   ?? [];
                $filesCF    = $contratos_firmados[$sector] ?? [];
                $genChecked = in_array($sector, $contratos_generados) ? 'checked' : '';
            ?>
                <div class="crm-card sector-card sector-<?php echo esc_attr($sector); ?>" style="display:none;">
                    <div class="card-header">
                        <h4 style="display:inline-block; margin-right:0.5em;">
                            <?php echo esc_html($secLabel); ?>
                            <small>(<?php echo esc_html(str_replace('_', ' ', $estado_sec)); ?>)</small>
                        </h4>

                        <?php if (current_user_can('crm_admin')): ?>
                            <button
                                type="button"
                                class="remove-interest-btn"
                                data-sector="<?php echo esc_attr($sector); ?>"
                                title="Quitar interés <?php echo esc_attr($secLabel); ?>"
                                style="background:none;border:none;color:#c00;font-size:1.2em;line-height:1;cursor:pointer; position: absolute;
    right: 0px;
    top: 0px;">×</button>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <!-- Facturas -->
                        <div class="upload-section facturas">
                            <strong>Facturas:</strong>
                            <?php foreach ($filesF as $url): ?>
                                <div class="uploaded-file">
                                    <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(basename($url)); ?></a>
                                    <button type="button" class="remove-file-btn" data-url="<?php echo esc_attr($url); ?>" data-tipo="factura">×</button>
                                    <input type="hidden" name="facturas[<?php echo esc_attr($sector); ?>][]" value="<?php echo esc_attr($url); ?>">
                                </div>
                            <?php endforeach; ?>
                            <input type="file" class="upload-input" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="factura" multiple>
                            <button type="button" class="upload-btn" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="factura">Subir factura</button>
                        </div>

                        <!-- Presupuestos -->
                        <div class="upload-section presupuestos">
                            <strong>Presupuestos:</strong>
                            <?php foreach ($filesP as $url): ?>
                                <div class="uploaded-file">
                                    <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(basename($url)); ?></a>
                                    <button type="button" class="remove-file-btn" data-url="<?php echo esc_attr($url); ?>" data-tipo="presupuesto">×</button>
                                    <input type="hidden" name="presupuesto[<?php echo esc_attr($sector); ?>][]" value="<?php echo esc_attr($url); ?>">
                                </div>
                            <?php endforeach; ?>
                            <input type="file" class="upload-input" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="presupuesto" multiple>
                            <button type="button" class="upload-btn" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="presupuesto">Subir presupuesto</button>
                        </div>

                        <!-- Botón sectorial para Comerciales -->
                        <?php if (! current_user_can('crm_admin')): ?>
                            <div class="send-sector-wrapper">
                                <button type="button"
                                    class="send-sector-btn crm-submit-btn enviar-btn"
                                    data-sector="<?php echo esc_attr($sector); ?>">
                                    Enviar <?php echo esc_html($secLabel); ?>
                                </button>
                                <span class="last-sent" data-sector="<?php echo esc_attr($sector); ?>">
                                    <?php
                                    // si tienes ya guardado en DB
                                    $fechas = maybe_unserialize($client_data['fecha_envio_por_sector'] ?? []);
                                    $users  = maybe_unserialize($client_data['usuario_envio_por_sector'] ?? []);
                                    if (! empty($fechas[$sector])) {
                                        echo 'Último envío: ' . esc_html($fechas[$sector])
                                            . ' por '       . esc_html($users[$sector] ?? '');
                                    }
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>


                        <!-- Controles Admin -->
                        <?php if (current_user_can('crm_admin')): ?>
                            <div class="admin-controls">
                                <label>
                                    <input type="checkbox" name="contratos_generados[]" value="<?php echo esc_attr($sector); ?>" <?php echo $genChecked; ?>>
                                    Marcar contrato generado
                                </label>
                                <div class="upload-section contratos-firmados">
                                    <strong>Contratos Firmados:</strong>
                                    <?php foreach ($filesCF as $url): ?>
                                        <div class="uploaded-file">
                                            <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(basename($url)); ?></a>
                                            <button type="button" class="remove-file-btn" data-url="<?php echo esc_attr($url); ?>" data-tipo="contrato_firmado">×</button>
                                            <input type="hidden" name="contratos_firmados[<?php echo esc_attr($sector); ?>][]" value="<?php echo esc_attr($url); ?>">
                                        </div>
                                    <?php endforeach; ?>
                                    <input type="file" class="upload-input" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="contrato_firmado" multiple>
                                    <button type="button" class="upload-btn" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="contrato_firmado">Subir contrato firmado</button>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>


        <!-- ——— Acciones Globales ——— -->
        <div class="crm-global-actions">
            <button type="submit" name="crm_guardar_cliente" class="crm-btn">Guardar borrador</button>

            <?php if (current_user_can('crm_admin')): ?>
                <button type="submit" name="crm_enviar_cliente" class="crm-btn enviar-btn">Enviar para revisión</button>
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

    <script>
    // Validación de email en tiempo real
    document.addEventListener('DOMContentLoaded', function() {
        const emailInput = document.querySelector('input[name="email_cliente"]');
        const form = document.querySelector('#crm-alta-form');
        
        if (emailInput) {
            // Validación en tiempo real mientras el usuario escribe
            emailInput.addEventListener('input', function() {
                validateEmail(this);
            });
            
            // Validación al perder el foco
            emailInput.addEventListener('blur', function() {
                validateEmail(this);
            });
        }
        
        // Validación al enviar el formulario
        if (form) {
            form.addEventListener('submit', function(e) {
                const email = emailInput.value.trim();
                
                if (email && !isValidEmail(email)) {
                    e.preventDefault();
                    showEmailError('Por favor, ingrese un email válido (ejemplo: usuario@dominio.com)');
                    emailInput.focus();
                    return false;
                }
                
                // Limpiar cualquier error anterior
                clearEmailError();
            });
        }
        
        function validateEmail(input) {
            const email = input.value.trim();
            
            if (email && !isValidEmail(email)) {
                input.style.borderColor = '#dc3545';
                input.style.backgroundColor = '#fff5f5';
                showEmailError('Formato de email inválido');
            } else {
                input.style.borderColor = email ? '#28a745' : '';
                input.style.backgroundColor = '';
                clearEmailError();
            }
        }
        
        function isValidEmail(email) {
            // Expresión regular más robusta para validar email
            const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
            return emailRegex.test(email);
        }
        
        function showEmailError(message) {
            clearEmailError(); // Limpiar error anterior
            
            const errorDiv = document.createElement('div');
            errorDiv.className = 'email-error-message';
            errorDiv.style.cssText = `
                color: #dc3545;
                font-size: 14px;
                margin-top: 5px;
                padding: 8px 12px;
                background: #fff5f5;
                border: 1px solid #f5c6cb;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            errorDiv.innerHTML = `<span style="color: #dc3545;">⚠️</span> ${message}`;
            
            emailInput.parentNode.appendChild(errorDiv);
        }
        
        function clearEmailError() {
            const existingError = document.querySelector('.email-error-message');
            if (existingError) {
                existingError.remove();
            }
        }
    });
    </script>
        </div> <!-- /crm-form-content -->
    </div> <!-- /crm-form-container -->
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
function crm_handle_ajax_request($estado_inicial)
{
    global $wpdb;
    $table      = $wpdb->prefix . 'crm_clients';
    $sectores   = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    $client_id  = intval($_POST['client_id'] ?? 0);
    $is_update  = $client_id > 0;
    $client     = $is_update
        ? (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $client_id), ARRAY_A)
        : [];

    // seguridad
    if (! wp_verify_nonce($_POST['crm_nonce'] ?? '', 'crm_alta_cliente_nonce')) {
        wp_send_json_error(['message' => 'Error de seguridad']);
    }


    // normalizar singular/plural
    foreach (['factura' => 'facturas', 'presupuestos' => 'presupuesto', 'contrato_firmado' => 'contratos_firmados'] as $sing => $plu) {
        if (isset($_POST[$sing]) && ! isset($_POST[$plu])) {
            $_POST[$plu] = $_POST[$sing];
        }
    }

    // helper: procesar un campo de archivos y fusionar con los existentes
    $handle_files = function ($field_name, $existing_serialized) use ($sectores) {
        $existing = maybe_unserialize($existing_serialized ?: 'a:0:{}');
        if (! is_array($existing)) {
            $existing = [];
        }
        $new_urls = [];

        // 1) archivos server
        if (! empty($_FILES[$field_name]['name']) && is_array($_FILES[$field_name]['name'])) {
            foreach ($_FILES[$field_name]['name'] as $sector => $names) {
                if (! is_array($names)) {
                    continue;
                }
                foreach ($names as $i => $name) {
                    if ($_FILES[$field_name]['error'][$sector][$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $file = [
                        'name'     => $name,
                        'type'     => $_FILES[$field_name]['type'][$sector][$i],
                        'tmp_name' => $_FILES[$field_name]['tmp_name'][$sector][$i],
                        'error'    => $_FILES[$field_name]['error'][$sector][$i],
                        'size'     => $_FILES[$field_name]['size'][$sector][$i],
                    ];
                    $up = wp_handle_upload($file, ['test_form' => false]);
                    if (! empty($up['url'])) {
                        $new_urls[$sector][] = $up['url'];
                    }
                }
            }
        }

        // 2) URLs recibidas en POST
        foreach ((array) ($_POST[$field_name] ?? []) as $sector => $urls) {
            $new_urls[$sector] = array_merge($new_urls[$sector] ?? [], (array)$urls);
        }

        // 3) fusionar existente + nuevas
        foreach ($new_urls as $s => $urls) {
            $existing[$s] = array_unique(array_merge($existing[$s] ?? [], $urls));
        }

        return $existing;
    };

    // Procesar facturas, presupuestos y contratos firmados
    $facturas_existentes   = $handle_files('facturas',          $client['facturas'] ?? '');
    $presu_existentes      = $handle_files('presupuesto',       $client['presupuesto'] ?? '');
    $contratos_existentes  = $handle_files('contratos_firmados', $client['contratos_firmados'] ?? '');

    // contratos generados
    $contratos_generados = array_map('sanitize_text_field', (array) ($_POST['contratos_generados'] ?? []));

    // ————— Gestión de envío por sector mejorada —————
    $fechas_envio = maybe_unserialize($client['fecha_envio_por_sector'] ?? 'a:0:{}');
    $users_envio  = maybe_unserialize($client['usuario_envio_por_sector'] ?? 'a:0:{}');
    
    // Asegurar que son arrays
    if (!is_array($fechas_envio)) $fechas_envio = [];
    if (!is_array($users_envio)) $users_envio = [];
    
    $env_sectores = (array) ($_POST['enviar_sector'] ?? []);
    
    // Solo actualizar fecha y usuario para sectores que se están enviando AHORA
    foreach ($env_sectores as $s) {
        $fechas_envio[$s] = current_time('d/m/Y H:i');
        $users_envio[$s]  = wp_get_current_user()->display_name;
    }
    // ————— Reconstruyo intereses —————
    // 1) Los que vienen marcados en el POST (checkboxes)
    $intereses_post = array_map('sanitize_text_field', (array) ($_POST['intereses'] ?? []));
    
    // 2) Aquellos que ya tienen ficheros subidos (para preservar sectores con archivos)
    $file_sectors = array_unique(array_merge(
        array_keys($facturas_existentes),
        array_keys($presu_existentes),
        array_keys($contratos_existentes)
    ));
    
    // 3) Preservar intereses que ya existen en el cliente (para no perder sectores anteriores)
    $intereses_existentes = maybe_unserialize($client['intereses'] ?? []);
    if (!is_array($intereses_existentes)) {
        $intereses_existentes = [];
    }
    
    // 4) Combino todos los intereses y elimino duplicados
    $intereses = array_unique(array_merge($intereses_post, $file_sectors, $intereses_existentes));

    // ————— Gestión de estado por sector mejorada —————
    $is_send     = current_filter() === 'wp_ajax_crm_enviar_cliente_ajax';
    $forzar      = current_user_can('crm_admin') && ! empty($_POST['forzar_estado']);
    $old_estado  = maybe_unserialize($client['estado_por_sector'] ?? 'a:0:{}');
    if (!is_array($old_estado)) {
        $old_estado = [];
    }
    $new_estado  = $old_estado; // Preservamos los estados existentes

    foreach ($intereses as $s) {
        $e = $old_estado[$s] ?? 'borrador'; // Estado actual del sector
        $estado_forzado = false;

        // 1) Si se está enviando este sector específicamente
        if ($is_send && in_array($s, $env_sectores, true) && $e === 'borrador' && !$forzar) {
            $e = 'enviado';
        }

        // 2) Admin forzando estado específico
        if ($forzar && isset($_POST["estado_{$s}"])) {
            $e = sanitize_text_field($_POST["estado_{$s}"]);
            $estado_forzado = true;
        }

        // Solo aplicar lógica automática si no se ha forzado el estado
        if (!$estado_forzado) {
            // 3) Lógica automática de transición: SOLO enviado -> presupuesto_aceptado
            // El borrador permanece como borrador aunque tenga presupuestos
            if (
                $e === 'enviado'
                && !empty($presu_existentes[$s])
            ) {
                $e = 'presupuesto_aceptado';
            }

            // 4) Lógica automática: presupuesto_aceptado -> contratos_generados
            if ($e === 'presupuesto_aceptado' && in_array($s, $contratos_generados, true)) {
                $e = 'contratos_generados';
            }

            // 5) flujo contratos firmados
            if ($e === 'contratos_generados' && ! empty($contratos_existentes[$s])) {
                $e = 'contratos_firmados';
            }
        }

        $new_estado[$s] = $e;
    }

    // estado global
      if ($forzar && isset($_POST['estado'])) {
        $estado = sanitize_text_field($_POST['estado']);
    } else {
        // Siempre calcular el estado global basándose en los estados por sector
        $estado = crm_calcula_estado_global($new_estado);
        
        // Si se está enviando y el estado calculado es 'borrador', forzar a 'enviado'
        if ($is_send && ! $forzar && $estado === 'borrador') {
            $estado = 'enviado';
        }
    }

    // Validación específica del teléfono
    $telefono = sanitize_text_field($_POST['telefono']);
    if (!empty($telefono) && !crm_validate_spanish_phone($telefono)) {
        wp_send_json_error(['message' => 'El número de teléfono no es válido. Formato esperado para móviles: 6XX XXX XXX o 7XX XXX XXX. Para fijos: 9XX XXX XXX']);
    }

    // Validación específica del email
    $email_cliente = sanitize_email($_POST['email_cliente']);
    if (!empty($email_cliente) && !is_email($email_cliente)) {
        wp_send_json_error(['message' => 'El email del cliente no tiene un formato válido. Por favor, ingrese un email correcto (ejemplo: usuario@dominio.com)']);
    }

    // preparar array de guardado
    $data = [
        'delegado'                  => sanitize_text_field($_POST['delegado']),
        'user_id'                   => $client['user_id'] ?? get_current_user_id(),
        'email_comercial'           => sanitize_email($_POST['email_comercial']),
        'cliente_nombre'            => sanitize_text_field($_POST['cliente_nombre']),
        'empresa'                   => sanitize_text_field($_POST['empresa']),
        'direccion'                 => sanitize_text_field($_POST['direccion']),
        'telefono'                  => $telefono,
        'email_cliente'             => $email_cliente,
        'poblacion'                 => sanitize_text_field($_POST['poblacion']),
        'provincia'                 => sanitize_text_field($_POST['provincia']), // Agregar provincia que faltaba
        'area'                      => sanitize_text_field($_POST['area']),
        'tipo'                      => sanitize_text_field($_POST['tipo']),
        'comentarios'               => sanitize_textarea_field($_POST['comentarios']), // Usar función específica para textarea
        'intereses' => maybe_serialize($intereses),

        'facturas'                  => maybe_serialize($facturas_existentes),
        'presupuesto'               => maybe_serialize($presu_existentes),
        'contratos_firmados'        => maybe_serialize($contratos_existentes),
        'contratos_generados'       => maybe_serialize($contratos_generados),
        'estado'                    => $estado,
        'estado_por_sector'         => maybe_serialize($new_estado),
        'fecha_envio_por_sector'    => maybe_serialize($fechas_envio),
        'usuario_envio_por_sector'  => maybe_serialize($users_envio),
        'editado_por'               => get_current_user_id(),
        'actualizado_en'            => current_time('mysql'),
    ];

    if ($estado === 'enviado') {
        $data['enviado_por']   = get_current_user_id();
        $data['fecha_enviado'] = current_time('mysql');
    }

    if ($is_update) {
        $wpdb->update($table, $data, ['id' => $client_id]);
    } else {
        $data['creado_por'] = get_current_user_id();
        $data['creado_en']  = current_time('mysql');
        $wpdb->insert($table, $data);
        $client_id = $wpdb->insert_id;
    }

    // ========== NOTIFICACIONES POR EMAIL ==========
    
    // 1) Admin enviando a comercial
    if (current_user_can('crm_admin') && $is_update && !empty($data['email_comercial'])) {
        $cambios_realizados = [];
        
        // Detectar cambios en el estado
        if ($client['estado'] !== $estado) {
            $cambios_realizados[] = "Estado cambiado de '{$client['estado']}' a '{$estado}'";
        }
        
        // Detectar si se forzó un estado específico
        if ($forzar && isset($_POST['estado'])) {
            $cambios_realizados[] = "Estado forzado por administrador a: " . crm_get_estado_label($estado);
        }
        
        // Detectar cambios en sectores enviados
        if (!empty($env_sectores)) {
            $cambios_realizados[] = "Sectores procesados: " . implode(', ', $env_sectores);
        }
        
        // Detectar nuevos archivos
        foreach (['facturas', 'presupuesto', 'contratos_firmados'] as $tipo) {
            $existentes = maybe_unserialize($client[$tipo] ?? 'a:0:{}');
            $nuevos = $tipo === 'facturas' ? $facturas_existentes : 
                     ($tipo === 'presupuesto' ? $presu_existentes : $contratos_existentes);
            
            foreach ($nuevos as $sector => $archivos) {
                $archivos_previos = $existentes[$sector] ?? [];
                $archivos_nuevos = array_diff($archivos, $archivos_previos);
                if (!empty($archivos_nuevos)) {
                    $tipo_label = $tipo === 'presupuesto' ? 'presupuestos' : $tipo;
                    $cambios_realizados[] = "Nuevos {$tipo_label} en {$sector}: " . count($archivos_nuevos) . " archivo(s)";
                }
            }
        }
        
        if (!empty($cambios_realizados)) {
            crm_enviar_notificacion_admin_a_comercial($client_id, $data['email_comercial'], $cambios_realizados);
        }
    }
    
    // 2) Comercial enviando sectores a admin
    if (!current_user_can('crm_admin') && !empty($env_sectores) && $is_send) {
        $email_settings = get_option('crm_email_settings', ['admin_notifications' => true]);
        if ($email_settings['admin_notifications']) {
            crm_enviar_notificacion_comercial_a_admin($client_id, $env_sectores);
        }
        crm_log_action('sectores_enviados', "Sectores enviados: " . implode(', ', $env_sectores), $client_id);
    }

    $redirect = current_user_can('crm_admin')
        ? home_url("/todas-las-altas-de-cliente/?status=success&id={$client_id}&estado={$estado}")
        : home_url("/mis-altas-de-cliente/?status=success&id={$client_id}&estado={$estado}");

    // Log detallado de la acción con información de estado por sector
    $current_user = wp_get_current_user();
    $action_details = [];
    
    if ($is_update) {
        $action_details[] = "Cliente actualizado: {$data['cliente_nombre']}";
        $action_details[] = "Email: {$data['email_cliente']}";
        $action_details[] = "Teléfono: {$data['telefono']}";
        $action_details[] = "Estado global: {$estado}";
        $action_details[] = "Delegado: {$data['delegado']}";
        
        // Información detallada de estados por sector
        if (!empty($new_estado)) {
            $estados_sector = [];
            foreach ($new_estado as $sector => $estado_sector) {
                $estados_sector[] = "{$sector}:{$estado_sector}";
            }
            $action_details[] = "Estados por sector: " . implode(', ', $estados_sector);
        }
        
        if (!empty($env_sectores)) {
            $action_details[] = "Sectores enviados: " . implode(', ', $env_sectores);
        }
        if (!empty($cambios_realizados)) {
            $action_details[] = "Cambios en archivos: " . implode(', ', $cambios_realizados);
        }
        crm_log_action('cliente_actualizado', implode(' | ', $action_details), $client_id, $current_user->ID);
    } else {
        $action_details[] = "Cliente creado: {$data['cliente_nombre']}";
        $action_details[] = "Email: {$data['email_cliente']}";
        $action_details[] = "Teléfono: {$data['telefono']}";
        $action_details[] = "Estado inicial: {$estado}";
        $action_details[] = "Delegado asignado: {$data['delegado']}";
        $action_details[] = "Creado por: {$current_user->display_name}";
        $action_details[] = "Intereses iniciales: " . implode(', ', $intereses);
        crm_log_action('cliente_creado', implode(' | ', $action_details), $client_id, $current_user->ID);
    }

    wp_send_json_success(['redirect_url' => $redirect]);
}

/**
 * Función de debugging para el flujo de guardado por sector
 */
function crm_debug_sector_save($client_id, $action, $data) {
    if (!current_user_can('crm_admin')) return; // Solo para debug de admin
    
    $debug_info = [
        'timestamp' => current_time('Y-m-d H:i:s'),
        'action' => $action,
        'client_id' => $client_id,
        'user' => wp_get_current_user()->display_name,
        'data' => $data
    ];
    
    crm_log_action('debug_sector_save', json_encode($debug_info), $client_id);
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

    // Verificar si el directorio de subidas es escribable
    if (!is_writable($upload_dir['basedir'])) {
        wp_send_json_error(['message' => 'Error en el servidor. El directorio de subidas no es escribable.']);
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

    // Log de la subida de archivo
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : null;
    $current_user = wp_get_current_user();
    $log_details = "Archivo subido: {$unique_file_name} | Sector: {$sector} | Tipo: {$tipo} | Tamaño: " . round($file['size'] / 1024, 2) . " KB";
    crm_log_action('archivo_subido', $log_details, $client_id, $current_user->ID);

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
            // Log de la eliminación de archivo
            $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : null;
            $current_user = wp_get_current_user();
            $file_name = basename($file_path);
            $log_details = "Archivo eliminado: {$file_name} | Tipo: {$tipo}";
            crm_log_action('archivo_eliminado', $log_details, $client_id, $current_user->ID);
            
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
    <div class="crm-table-container">
        <div class="crm-table-header">
            <h3><img src="<?php echo get_site_icon_url(); ?>" alt="Logo" class="crm-logo-small"> Mis Clientes - Energitel CRM</h3>
            <p class="table-subtitle">Gestión de clientes y seguimiento de estados</p>
        </div>
        
        <div class="table-responsive">
            <table id="crm-lista-altas" class="crm-table-material">
                <thead>
                    <tr>
                        <th class="th-id">#</th>
                        <th class="th-fecha">Fecha</th>
                        <th class="th-cliente">Cliente</th>
                        <th class="th-empresa">Empresa</th>
                        <th class="th-contacto">Contacto</th>
                        <th class="th-intereses">Intereses</th>
                        <th class="th-estado">Estado/Sector</th>
                        <th class="th-updated">Actualizado</th>
                        <th class="th-acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <script>
    // Definir crmData directamente en el script
    window.crmData = {
        ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('crm_obtener_clientes_nonce'); ?>',
        user_id: <?php echo get_current_user_id(); ?>
    };
    
    jQuery(document).ready(function($) {
        console.log('Energitel CRM - Inicializando tabla de clientes...');
        
        // Verificar si la tabla ya está inicializada y destruirla si es necesario
        if ($.fn.DataTable.isDataTable('#crm-lista-altas')) {
            console.log('DataTable ya existe, destruyendo...');
            $('#crm-lista-altas').DataTable().destroy();
            $('#crm-lista-altas tbody').empty();
        }
        
        // Verificar que crmData existe
        if (typeof crmData === 'undefined') {
            console.error('crmData no está definido');
            return;
        }
        
        try {
            var table = $('#crm-lista-altas').DataTable({
            "processing": true,
            "serverSide": false,
            "ajax": {
                "url": crmData.ajaxurl,
                "type": "POST",
                "data": function(d) {
                    d.action = 'crm_obtener_altas';
                    d.nonce = crmData.nonce;
                    d.user_id = crmData.user_id;
                },
                "error": function(xhr, error, code) {
                    console.error('Error en AJAX DataTables:', error, code);
                    console.error('Respuesta del servidor:', xhr.responseText);
                    alert('Error al cargar los datos: ' + error);
                }
            },
            "columns": [
                { 
                    "data": "id",
                    "className": "text-center",
                    "width": "60px"
                },
                { 
                    "data": "fecha",
                    "render": function(data, type, row) {
                        if (type === 'display' || type === 'type') {
                            const date = new Date(data);
                            return date.toLocaleDateString('es-ES', {
                                day: '2-digit',
                                month: '2-digit',
                                year: '2-digit'
                            });
                        }
                        return data;
                    }
                },
                { 
                    "data": "cliente_nombre",
                    "render": function(data, type, row) {
                        return '<strong>' + data + '</strong>';
                    }
                },
                { 
                    "data": "empresa",
                    "render": function(data, type, row) {
                        return data || '<em>Sin empresa</em>';
                    }
                },
                { 
                    "data": "email_cliente",
                    "render": function(data, type, row) {
                        if (data) {
                            return '<a href="mailto:' + data + '" class="email-link">' + data + '</a>';
                        }
                        return '<em>Sin email</em>';
                    }
                },
                { 
                    "data": "intereses",
                    "render": function(data, type, row) {
                        if (Array.isArray(data) && data.length > 0) {
                            return data.slice(0, 2).join(', ') + (data.length > 2 ? '...' : '');
                        }
                        return '<em>Sin intereses</em>';
                    }
                },
                { 
                    "data": "estado_por_sector",
                    "render": function(data, type, row) {
                        if (data && typeof data === 'object' && Object.keys(data).length > 0) {
                            let badges = '';
                            Object.entries(data).forEach(([sector, estado]) => {
                                badges += '<span class="estado-badge ' + estado + '">' + 
                                         sector + ': ' + getEstadoLabel(estado) + '</span> ';
                            });
                            return badges;
                        }
                        return '<span class="estado-badge ' + row.estado + '">' + getEstadoLabel(row.estado) + '</span>';
                    }
                },
                { 
                    "data": "actualizado_en",
                    "render": function(data, type, row) {
                        if (type === 'display' || type === 'type') {
                            const date = new Date(data);
                            return date.toLocaleDateString('es-ES', {
                                day: '2-digit',
                                month: '2-digit'
                            });
                        }
                        return data;
                    }
                },
                { 
                    "data": "id",
                    "orderable": false,
                    "className": "text-center",
                    "render": function(data, type, row) {
                        const editUrl = window.location.origin + '/editar-cliente/?id=' + data;
                        return '<div class="action-buttons">' +
                               '<a href="' + editUrl + '" class="action-btn edit" title="Editar cliente">✏️</a>' +
                               '<button class="action-btn delete" onclick="eliminarCliente(' + data + ')" title="Eliminar cliente">🗑️</button>' +
                               '</div>';
                    }
                }
            ],
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
            },
            "pageLength": 15,
            "responsive": true,
            "dom": '<"top"fl>rt<"bottom"ip><"clear">',
            "order": [[ 7, "desc" ]], // Ordenar por fecha actualizada
            "columnDefs": [
                { "targets": [8], "orderable": false }
            ]
        });

        // Función para obtener etiquetas de estado
        function getEstadoLabel(estado) {
            const labels = {
                'borrador': 'Borrador',
                'enviado': 'Enviado',
                'presupuesto_aceptado': 'Presupuesto Aceptado',
                'contratos_generados': 'Contratos Generados',
                'contratos_firmados': 'Contratos Firmados'
            };
            return labels[estado] || estado;
        }

        // Función para eliminar cliente
        window.eliminarCliente = function(clienteId) {
            if (confirm('¿Estás seguro de que quieres eliminar este cliente?')) {
                $.ajax({
                    url: crmData.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'crm_borrar_cliente',
                        nonce: crmData.nonce,
                        client_id: clienteId
                    },
                    success: function(response) {
                        if (response.success) {
                            table.ajax.reload();
                            alert('Cliente eliminado correctamente');
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('Error al conectar con el servidor');
                    }
                });
            }
        };
        
        } catch (error) {
            console.error('Error inicializando DataTable:', error);
        }
    });
    </script>


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

    // Debug logging
    error_log("CRM Debug: Usuario $user_id solicitando datos. Encontrados " . count($clientes) . " registros");
    if (empty($clientes)) {
        error_log("CRM Debug: No se encontraron clientes para user_id $user_id");
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

        wp_send_json_success([
            'data' => $clientes,  // DataTables espera que los datos estén en 'data'
            'user_name' => $user_name
        ]);
    } else {
        wp_send_json_success(['data' => [], 'user_name' => $user_name]); // DataTables espera 'data' vacío
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

    // Obtener datos del cliente antes de eliminarlo para el log
    $client_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A);
    
    $deleted = $wpdb->delete($table_name, ['id' => $client_id]);

    if ($deleted) {
        // Log de la eliminación del cliente
        $current_user = wp_get_current_user();
        $log_details = "Cliente eliminado: {$client_data['cliente_nombre']} | Email: {$client_data['cliente_email']} | Teléfono: {$client_data['cliente_telefono']} | Estado: {$client_data['estado']}";
        crm_log_action('cliente_eliminado', $log_details, $client_id, $current_user->ID);
        
        wp_send_json_success(['message' => 'Cliente eliminado correctamente.']);
    } else {
        wp_send_json_error(['message' => 'Error al eliminar el cliente.']);
    }
}

// Sistema de notificaciones por email del CRM
/**
 * Genera una plantilla HTML para emails del CRM
 */
function crm_get_email_template($args = []) {
    $defaults = [
        'titulo' => 'Notificación CRM',
        'contenido' => '',
        'pie' => ''
    ];
    
    $args = wp_parse_args($args, $defaults);
    
    $site_name = get_option('blogname');
    $site_url = home_url();
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$args['titulo']}</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: #007cba; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; margin: -20px -20px 20px -20px; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin: 0; font-size: 24px;'>{$args['titulo']}</h1>
                <p style='margin: 10px 0 0 0; opacity: 0.9;'>Energitel CRM - {$site_name}</p>
            </div>
            
            <div class='content'>
                {$args['contenido']}
            </div>
            
            <div class='footer'>
                <p>{$args['pie']}</p>
                <p>© " . date('Y') . " <a href='{$site_url}'>{$site_name}</a> - Energitel CRM</p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Envía notificación por email al comercial cuando un admin le envía una ficha
 */
function crm_enviar_notificacion_admin_a_comercial($client_id, $comercial_email, $cambios_realizados = []) {
    if (empty($comercial_email) || !is_email($comercial_email)) {
        error_log("CRM Email: Email de comercial inválido: " . $comercial_email);
        return false;
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    
    $cliente = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $client_id), ARRAY_A);
    if (!$cliente) {
        error_log("CRM Email: Cliente no encontrado con ID: " . $client_id);
        return false;
    }
    
    $admin_user = wp_get_current_user();
    $cliente_url = home_url("/editar-cliente/?client_id={$client_id}");
    $tabla_url = home_url("/mis-altas-de-cliente/");
    
    $subject = "🔔 Actualización de cliente: " . $cliente['cliente_nombre'];
    
    $cambios_html = '';
    if (!empty($cambios_realizados)) {
        $cambios_html = '<h3 style="color: #333; font-size: 16px;">Cambios realizados:</h3><ul>';
        foreach ($cambios_realizados as $cambio) {
            $cambios_html .= '<li style="margin: 5px 0;">' . esc_html($cambio) . '</li>';
        }
        $cambios_html .= '</ul>';
    }
    
    $message = crm_get_email_template([
        'titulo' => 'Actualización de Cliente',
        'contenido' => "
            <p>Hola <strong>{$cliente['delegado']}</strong>,</p>
            
            <p>El administrador <strong>{$admin_user->display_name}</strong> ha actualizado la ficha del cliente:</p>
            
            <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #333; margin-top: 0;'>📋 {$cliente['cliente_nombre']}</h3>
                <p><strong>Empresa:</strong> {$cliente['empresa']}</p>
                <p><strong>Email:</strong> {$cliente['email_cliente']}</p>
                <p><strong>Estado actual:</strong> <span style='background: #007cba; color: white; padding: 3px 8px; border-radius: 4px;'>" . crm_get_estado_label($cliente['estado']) . "</span></p>
            </div>
            
            {$cambios_html}
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$cliente_url}' style='background: #007cba; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📝 Editar Cliente</a>
                <a href='{$tabla_url}' style='background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📊 Mis Clientes</a>
            </div>
        ",
        'pie' => "Esta notificación ha sido enviada automáticamente por Energitel CRM."
    ]);
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . $admin_user->user_email
    ];
    
    $result = wp_mail($comercial_email, $subject, $message, $headers);
    
    if ($result) {
        error_log("CRM Email: Notificación enviada exitosamente a comercial: " . $comercial_email);
    } else {
        error_log("CRM Email: Error al enviar notificación a comercial: " . $comercial_email);
    }
    
    return $result;
}

/**
 * Envía notificación a administradores cuando un comercial envía un interés
 */
function crm_enviar_notificacion_comercial_a_admin($client_id, $sectores_enviados = []) {
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    
    $cliente = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $client_id), ARRAY_A);
    if (!$cliente) return false;
    
    $comercial = wp_get_current_user();
    $cliente_url = home_url("/editar-cliente/?client_id={$client_id}");
    $tabla_admin_url = home_url("/todas-las-altas-de-cliente/");
    
    // Obtener datos deserializados
    $facturas = maybe_unserialize($cliente['facturas'] ?? []);
    $presupuestos = maybe_unserialize($cliente['presupuesto'] ?? []);
    $intereses = maybe_unserialize($cliente['intereses'] ?? []);
    $estado_por_sector = maybe_unserialize($cliente['estado_por_sector'] ?? []);
    
    $subject = "Energitel CRM - Nuevo envío de cliente: " . $cliente['cliente_nombre'] . " (" . implode(', ', $sectores_enviados) . ")";
    
    // Construir información de sectores enviados
    $sectores_html = '';
    foreach ($sectores_enviados as $sector) {
        $estado_sector = $estado_por_sector[$sector] ?? 'borrador';
        $facturas_sector = $facturas[$sector] ?? [];
        $presupuestos_sector = $presupuestos[$sector] ?? [];
        
        $sectores_html .= "
            <div style='background: #f8f9fa; border-left: 4px solid " . crm_get_colores_sectores()[$sector] . "; padding: 15px; margin: 10px 0;'>
                <h4 style='color: " . crm_get_colores_sectores()[$sector] . "; margin-top: 0;'>📁 " . ucfirst($sector) . "</h4>
                <p><strong>Estado:</strong> <span style='background: " . crm_get_colores_sectores()[$sector] . "; color: white; padding: 2px 8px; border-radius: 4px;'>" . crm_get_estado_label($estado_sector) . "</span></p>
        ";
        
        if (!empty($facturas_sector)) {
            $sectores_html .= "<p><strong>📄 Facturas (" . count($facturas_sector) . "):</strong></p><ul>";
            foreach ($facturas_sector as $factura) {
                $nombre_archivo = basename(parse_url($factura, PHP_URL_PATH));
                $sectores_html .= "<li><a href='{$factura}' style='color: #007cba;'>{$nombre_archivo}</a></li>";
            }
            $sectores_html .= "</ul>";
        }
        
        if (!empty($presupuestos_sector)) {
            $sectores_html .= "<p><strong>💰 Presupuestos (" . count($presupuestos_sector) . "):</strong></p><ul>";
            foreach ($presupuestos_sector as $presupuesto) {
                $nombre_archivo = basename(parse_url($presupuesto, PHP_URL_PATH));
                $sectores_html .= "<li><a href='{$presupuesto}' style='color: #007cba;'>{$nombre_archivo}</a></li>";
            }
            $sectores_html .= "</ul>";
        }
        
        $sectores_html .= "</div>";
    }
    
    $message = crm_get_email_template([
        'titulo' => 'Nuevo Envío de Cliente',
        'contenido' => "
            <p>Estimado equipo administrativo,</p>
            
            <p>El comercial <strong>{$comercial->display_name}</strong> ha enviado un cliente para revisión:</p>
            
            <div style='background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #1976d2; margin-top: 0;'>👤 {$cliente['cliente_nombre']}</h3>
                <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>
                    <div>
                        <p><strong>📧 Email:</strong> {$cliente['email_cliente']}</p>
                        <p><strong>🏢 Empresa:</strong> {$cliente['empresa']}</p>
                        <p><strong>📞 Teléfono:</strong> {$cliente['telefono']}</p>
                    </div>
                    <div>
                        <p><strong>📍 Dirección:</strong> {$cliente['direccion']}</p>
                        <p><strong>🏙️ Población:</strong> {$cliente['poblacion']}</p>
                        <p><strong>🏷️ Tipo:</strong> {$cliente['tipo']}</p>
                    </div>
                </div>
                <p><strong>💬 Comentarios:</strong> {$cliente['comentarios']}</p>
            </div>
            
            <h3 style='color: #333;'>📋 Sectores enviados:</h3>
            {$sectores_html}
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$cliente_url}' style='background: #1976d2; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📝 Revisar Cliente</a>
                <a href='{$tabla_admin_url}' style='background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📊 Todas las Altas</a>
            </div>
        ",
        'pie' => "Enviado por: {$comercial->display_name} ({$comercial->user_email})"
    ]);
    
    // Obtener emails de administradores CRM
    $admin_users = get_users(['role' => 'crm_admin']);
    if (empty($admin_users)) {
        error_log("CRM Email: No se encontraron usuarios con rol crm_admin");
        return false;
    }
    
    $admin_emails = array_map(function($user) { return $user->user_email; }, $admin_users);
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . $comercial->user_email
    ];
    
    $success = true;
    $emails_enviados = 0;
    foreach ($admin_emails as $email) {
        if (wp_mail($email, $subject, $message, $headers)) {
            $emails_enviados++;
        } else {
            $success = false;
            error_log("CRM Email: Error al enviar notificación a admin: " . $email);
        }
    }
    
    error_log("CRM Email: Notificaciones enviadas a {$emails_enviados} de " . count($admin_emails) . " administradores");
    
    return $success;
}

/**
 * Función de prueba para testear el sistema de emails
 */
add_action('wp_ajax_crm_test_email', 'crm_test_email_function');
function crm_test_email_function() {
    if (!current_user_can('crm_admin')) {
        wp_send_json_error(['message' => 'No tienes permisos para probar emails']);
    }
    
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'crm_obtener_clientes_nonce')) {
        wp_send_json_error(['message' => 'Error de seguridad']);
    }
    
    $admin_email = get_option('admin_email');
    $test_message = crm_get_email_template([
        'titulo' => 'Prueba de Energitel CRM',
        'contenido' => '
            <p>¡Hola!</p>
            <p>Este es un email de prueba del sistema de notificaciones CRM.</p>
            <p>Si recibes este mensaje, significa que el sistema de emails está funcionando correctamente.</p>
            <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #1976d2; margin-top: 0;">🧪 Test completado</h3>
                <p>Fecha y hora: ' . current_time('d/m/Y H:i:s') . '</p>
            </div>
        ',
        'pie' => 'Test enviado por: ' . wp_get_current_user()->display_name
    ]);
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . $admin_email . '>'
    ];
    
    $result = wp_mail($admin_email, 'Test de Energitel CRM', $test_message, $headers);
    
    if ($result) {
        crm_log_action('test_email_enviado', "Email de prueba enviado a: $admin_email");
        wp_send_json_success(['message' => 'Email de prueba enviado correctamente a: ' . $admin_email]);
    } else {
        crm_log_action('test_email_error', "Error al enviar email de prueba a: $admin_email");
        wp_send_json_error(['message' => 'Error al enviar el email de prueba']);
    }
}

// Sistema de configuración de emails
add_option('crm_email_settings', [
    'admin_notifications' => true,
    'comercial_notifications' => true,
    'test_mode' => false
]);

/**
 * Registra una acción en el log del CRM con organización mensual
 */
function crm_log_action($action_type, $details, $client_id = null, $user_id = null) {
    global $wpdb;
    
    $user_id = $user_id ?: get_current_user_id();
    $user = get_userdata($user_id);
    
    // Crear tabla del mes actual si no existe
    $current_month = current_time('Y_m');
    $table_name = $wpdb->prefix . 'crm_activity_log_' . $current_month;
    
    crm_create_monthly_log_table($current_month);
    
    $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'user_name' => $user ? $user->display_name : 'Sistema',
            'action_type' => $action_type,
            'details' => $details,
            'client_id' => $client_id,
            'created_at' => current_time('mysql'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]
    );
}

/**
 * Crear tabla de log mensual si no existe
 */
function crm_create_monthly_log_table($year_month) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'crm_activity_log_' . $year_month;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        user_name varchar(255) NOT NULL,
        action_type varchar(100) NOT NULL,
        details text NOT NULL,
        client_id bigint(20) DEFAULT NULL,
        created_at datetime NOT NULL,
        ip_address varchar(45) NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY action_type (action_type),
        KEY client_id (client_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Obtener todos los meses disponibles de logs
 */
function crm_get_available_log_months() {
    global $wpdb;
    
    $tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}crm_activity_log_%'", ARRAY_N);
    $months = [];
    
    foreach ($tables as $table) {
        $table_name = $table[0];
        if (preg_match('/_log_(\d{4}_\d{2})$/', $table_name, $matches)) {
            $year_month = $matches[1];
            $date = DateTime::createFromFormat('Y_m', $year_month);
            if ($date) {
                $months[] = [
                    'value' => $year_month,
                    'label' => $date->format('F Y'),
                    'table' => $table_name
                ];
            }
        }
    }
    
    // Ordenar por fecha descendente (más reciente primero)
    usort($months, function($a, $b) {
        return strcmp($b['value'], $a['value']);
    });
    
    return $months;
}

/**
 * Obtener logs de un mes específico
 */
function crm_get_logs_by_month($year_month = null, $limit = 50, $offset = 0) {
    global $wpdb;
    
    if (!$year_month) {
        $year_month = current_time('Y_m');
    }
    
    $table_name = $wpdb->prefix . 'crm_activity_log_' . $year_month;
    
    // Verificar si la tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        return [];
    }
    
    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $table_name 
        ORDER BY created_at DESC 
        LIMIT %d OFFSET %d
    ", $limit, $offset), ARRAY_A);
    
    return $logs ?: [];
}

/**
 * Generar actividades de ejemplo para el log
 */
function crm_generate_sample_activities() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'crm_activity_log';
    
    // Verificar si ya existen actividades
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    if ($count > 0) {
        return; // Ya hay actividades, no generar más
    }
    
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID ?: 1;
    $user_name = $current_user->display_name ?: 'Admin';
    
    $sample_activities = [
        [
            'action_type' => 'cliente_creado',
            'details' => 'Cliente creado: Empresa ABC S.L. | Email: contacto@empresaabc.com | Teléfono: 612345678 | Estado inicial: presupuesto_enviado | Delegado asignado: ' . $user_name,
            'client_id' => 1
        ],
        [
            'action_type' => 'archivo_subido',
            'details' => 'Archivo subido: presupuesto_abc_2024.pdf | Sector: presupuestos | Tipo: presupuesto | Tamaño: 245.67 KB',
            'client_id' => 1
        ],
        [
            'action_type' => 'cliente_actualizado',
            'details' => 'Cliente actualizado: Empresa ABC S.L. | Email: contacto@empresaabc.com | Estado: cliente_convertido | Sectores enviados: presupuestos, contratos',
            'client_id' => 1
        ],
        [
            'action_type' => 'sectores_enviados',
            'details' => 'Sectores enviados: presupuestos, contratos',
            'client_id' => 1
        ],
        [
            'action_type' => 'test_email_enviado',
            'details' => 'Email de prueba enviado a: admin@tudominio.com',
            'client_id' => null
        ]
    ];
    
    foreach ($sample_activities as $activity) {
        $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'user_name' => $user_name,
                'action_type' => $activity['action_type'],
                'details' => $activity['details'],
                'client_id' => $activity['client_id'],
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . rand(1, 48) . ' hours')),
                'ip_address' => '127.0.0.1'
            ]
        );
    }
}

/**
 * Crear tabla de log de actividades
 */
function crm_create_activity_log_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'crm_activity_log';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        user_name varchar(255) NOT NULL,
        action_type varchar(100) NOT NULL,
        details text NOT NULL,
        client_id mediumint(9) NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        ip_address varchar(45) NOT NULL,
        PRIMARY KEY (id),
        INDEX idx_user_id (user_id),
        INDEX idx_action_type (action_type),
        INDEX idx_client_id (client_id),
        INDEX idx_created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Crear tabla al activar el plugin
function crm_plugin_activation() {
    crm_create_activity_log_table();
    crm_migrate_to_monthly_logs();
    crm_generate_sample_activities();
}

/**
 * Migrar logs existentes al sistema mensual
 */
function crm_migrate_to_monthly_logs() {
    global $wpdb;
    
    $old_table = $wpdb->prefix . 'crm_activity_log';
    
    // Verificar si existe la tabla antigua
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$old_table'");
    if (!$table_exists) {
        return; // No hay tabla antigua para migrar
    }
    
    // Obtener todos los logs de la tabla antigua
    $old_logs = $wpdb->get_results("SELECT * FROM $old_table ORDER BY created_at", ARRAY_A);
    
    if (empty($old_logs)) {
        return; // No hay logs para migrar
    }
    
    // Agrupar por mes y migrar
    $logs_by_month = [];
    foreach ($old_logs as $log) {
        $date = new DateTime($log['created_at']);
        $month_key = $date->format('Y_m');
        
        if (!isset($logs_by_month[$month_key])) {
            $logs_by_month[$month_key] = [];
        }
        
        $logs_by_month[$month_key][] = $log;
    }
    
    // Crear tablas mensuales y migrar datos
    foreach ($logs_by_month as $month => $logs) {
        crm_create_monthly_log_table($month);
        $monthly_table = $wpdb->prefix . 'crm_activity_log_' . $month;
        
        foreach ($logs as $log) {
            $wpdb->insert($monthly_table, [
                'user_id' => $log['user_id'],
                'user_name' => $log['user_name'],
                'action_type' => $log['action_type'],
                'details' => $log['details'],
                'client_id' => $log['client_id'],
                'created_at' => $log['created_at'],
                'ip_address' => $log['ip_address'] ?? '0.0.0.0'
            ]);
        }
    }
    
    // Renombrar tabla antigua como backup
    $backup_table = $old_table . '_backup_' . date('Y_m_d');
    $wpdb->query("RENAME TABLE $old_table TO $backup_table");
}

register_activation_hook(__FILE__, 'crm_plugin_activation');
