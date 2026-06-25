<?php
/*
Plugin Name: CRM Energitel Avanzado
Plugin URI: https://github.com/replantadev/crm/
Description: Plugin avanzado para gestionar clientes con roles, panel de administración completo, sistema de logs, herramientas de backup y exportación, monitoreo en tiempo real y funcionalidades offline.
Version: 1.20.7
Author: Luis Javier
Author URI: https://github.com/replantadev
Update URI: https://github.com/replantadev/crm/
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 8.0
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
define('CRM_PLUGIN_VERSION', '1.20.7');
define('CRM_PLUGIN_FILE', __FILE__);
define('CRM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CRM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Definir constante para GitHub token si no existe (para repositorios privados)
if (!defined('CRM_GITHUB_TOKEN')) {
    define('CRM_GITHUB_TOKEN', ''); // Dejar vacío para repositorios públicos
}

// Cargar autoload de Composer (Plugin Update Checker, etc.)
if (file_exists(CRM_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once CRM_PLUGIN_PATH . 'vendor/autoload.php';
}

// Sistema de actualizaciones desde GitHub (PUC v5).
require_once CRM_PLUGIN_PATH . 'includes/updater.php';

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

// Helpers internos (seguridad, roles, uploads, logger). Se cargan ANTES que el resto.
require_once CRM_PLUGIN_PATH . 'includes/security.php';
require_once CRM_PLUGIN_PATH . 'includes/roles.php';
require_once CRM_PLUGIN_PATH . 'includes/data.php';
require_once CRM_PLUGIN_PATH . 'includes/uploads-handler.php';
require_once CRM_PLUGIN_PATH . 'includes/logger.php';
require_once CRM_PLUGIN_PATH . 'includes/notes.php';
require_once CRM_PLUGIN_PATH . 'includes/duplicates.php';
require_once CRM_PLUGIN_PATH . 'includes/leads-sheets.php';
require_once CRM_PLUGIN_PATH . 'includes/notifications.php';
require_once CRM_PLUGIN_PATH . 'includes/leads-mk-shortcode.php';
// v1.19.0 — Sistema de diseño v2 (iconos, badges, app shell)
require_once CRM_PLUGIN_PATH . 'includes/icons.php';
require_once CRM_PLUGIN_PATH . 'includes/ui-helpers.php';
require_once CRM_PLUGIN_PATH . 'includes/app-shell.php';
// v1.20.0 — Sistema de visitas
require_once CRM_PLUGIN_PATH . 'includes/visitas.php';// v1.20.2 — Shortcode frontend de Mi agenda + bloqueo wp-admin
require_once CRM_PLUGIN_PATH . 'includes/shortcode-agenda.php';
require_once CRM_PLUGIN_PATH . 'includes/admin-lockdown.php';
// v1.20.3 — Integración Google Calendar (link + feed iCal personal)
require_once CRM_PLUGIN_PATH . 'includes/gcal-sync.php';
// Incluir archivos del plugin
require_once CRM_PLUGIN_PATH . 'acceso.php';
require_once CRM_PLUGIN_PATH . 'shortcodes.php';

// Página de administración WP (menú "CRM"). Solo en wp-admin.
if (is_admin()) {
    require_once CRM_PLUGIN_PATH . 'includes/admin-page.php';
    require_once CRM_PLUGIN_PATH . 'includes/agenda-page.php';
}

// Incluir páginas de ayuda
if (file_exists(CRM_PLUGIN_PATH . 'includes/guia-comerciales.php')) {
    require_once CRM_PLUGIN_PATH . 'includes/guia-comerciales.php';
}
if (file_exists(CRM_PLUGIN_PATH . 'includes/guia-admin.php')) {
    require_once CRM_PLUGIN_PATH . 'includes/guia-admin.php';
}

// Cargar text-domain para internacionalización futura
add_action('plugins_loaded', function () {
    load_plugin_textdomain('crm-basico', false, dirname(plugin_basename(CRM_PLUGIN_FILE)) . '/languages');
});

add_action('wp_enqueue_scripts', 'crm_enqueue_styles');
function crm_enqueue_styles()
{
    wp_enqueue_style('crm-styles', CRM_PLUGIN_URL . 'crm-styles.css', [], CRM_PLUGIN_VERSION);
    // v1.19.0 — sistema de diseño v2 (cargado siempre, después del CSS legacy)
    wp_enqueue_style('crm-design-v2', CRM_PLUGIN_URL . 'assets/css/crm-design-v2.css', ['crm-styles'], CRM_PLUGIN_VERSION);
    
    // Cargar estilos para documentación en páginas que contengan los shortcodes de guías
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'crm_guia_comerciales') || 
        has_shortcode($post->post_content, 'crm_guia_admin'))) {
        wp_enqueue_style('crm-docs-styles', CRM_PLUGIN_URL . 'css/crm-docs-styles.css', [], CRM_PLUGIN_VERSION);
        
        // Añadir Google Fonts para mejor tipografía
        wp_enqueue_style('crm-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', [], null);
    }
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
        'borrador'            => ['label' => 'Sin enviar',          'color' => '#B0B7C3'],
        'enviado'             => ['label' => 'Enviado',             'color' => '#2AA8F2'],
        'presupuesto_generado' => ['label' => 'Presupuesto Generado', 'color' => '#FF9500'],
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
        'presupuesto_generado',
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
        'borrador' => 'Sin enviar',
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
 * Estados de decisión por sector (v1.19.2). Reflejan por qué un sector no
 * avanza al cierre aunque ya tenga presupuesto. Son ortogonales al `estado`.
 *
 * @return array<string, array{label:string, color:string, icon:string}>
 */
function crm_get_decisiones_sector() {
    return [
        ''                       => ['label' => 'Sin bloqueo',          'color' => 'neutral', 'icon' => 'check-circle'],
        'pendiente_financiacion' => ['label' => 'Pendiente financiación','color' => 'warn',    'icon' => 'scales'],
        'pendiente_competencia'  => ['label' => 'Pendiente competencia', 'color' => 'violet',  'icon' => 'magnifying'],
        'decision_pendiente'     => ['label' => 'Decisión pendiente',    'color' => 'accent',  'icon' => 'pencil'],
        'pendiente_visita'       => ['label' => 'Pendiente visita',      'color' => 'accent',  'icon' => 'map-pin'],
    ];
}

/**
 * Renderiza un badge para un estado de decisión.
 *
 * @return string HTML
 */
function crm_render_decision_badge($valor, $size = 'sm') {
    if ($valor === '' || $valor === null) {
        return '';
    }
    $map = crm_get_decisiones_sector();
    if (!isset($map[$valor])) {
        return '';
    }
    $cfg = $map[$valor];
    $icon = function_exists('crm_icon') ? crm_icon($cfg['icon'], $size === 'md' ? 14 : 12) : '';
    return sprintf(
        '<span class="crm-pill crm-pill--%1$s crm-pill--%2$s">%3$s%4$s</span>',
        esc_attr($cfg['color']),
        esc_attr($size),
        $icon,
        esc_html($cfg['label'])
    );
}

/**
 * Devuelve las 52 provincias oficiales de España (nombres INE).
 *
 * @deprecated Usa `crm_get_provincias_oficiales()` que además devuelve códigos INE.
 * @return string[]
 */
function crm_get_provincias_espana() {
    $names = [];
    foreach (crm_get_provincias_oficiales() as $p) {
        $names[] = $p['name'];
    }
    return $names;
}

/**
 * Valida si una provincia es válida (acepta nombres oficiales y aliases legacy).
 */
function crm_validate_provincia($provincia) {
    return crm_es_provincia_oficial($provincia);
}

/**
 * Valida población según la provincia (básico)
 */
function crm_validate_poblacion($poblacion, $provincia = null) {
    // Validación básica: no vacío, longitud razonable, caracteres válidos
    if (empty($poblacion) || strlen($poblacion) < 2 || strlen($poblacion) > 100) {
        return false;
    }
    
    // Solo caracteres alfanuméricos, espacios, guiones, apostrofes y acentos
    if (!preg_match('/^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ\s\-\'\.]+$/', $poblacion)) {
        return false;
    }
    
    return true;
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
        'interes_eliminado' => 'Interés Eliminado',
        'test_email_enviado' => 'Test Email Enviado',
        'test_email_error' => 'Error Test Email',
        'notificacion_comercial_enviada' => 'Email a Comercial',
        'notificacion_comercial_error' => 'Error Email Comercial',
        'notificacion_admin_enviada' => 'Email a Admin',
        'notificacion_admin_error' => 'Error Email Admin',
        'backup_created' => 'Backup Creado',
        'database_optimized' => 'BD Optimizada',
        'logs_prueba_generados' => 'Logs de Prueba',
        'logs_limpiados' => 'Logs Limpiados',
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
function crm_render_estado_badges($estado_por_sector = [], $sectores = [], $fechas_envio = [])
{
    $out = '';
    foreach ($sectores as $sector) {
        $estado = $estado_por_sector[$sector] ?? 'borrador';
        $estado_label = crm_get_estado_label($estado);
        $sector_label = ucfirst($sector);
        $sent_class = isset($fechas_envio[$sector]) ? ' sent-indicator' : '';
        $out .= '<span class="crm-badge estado ' . $estado . $sent_class . '">' . $sector_label . ': ' . $estado_label . '</span>';
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
    $estado_por_sector    = crm_safe_unserialize_array($client_data['estado_por_sector']   ?? '');
    $facturas             = crm_safe_unserialize_array($client_data['facturas']            ?? '');
    $presupuestos         = crm_safe_unserialize_array($client_data['presupuesto']         ?? '');
    $contratos_firmados   = crm_safe_unserialize_array($client_data['contratos_firmados']  ?? '');
    $contratos_generados  = crm_safe_unserialize_array($client_data['contratos_generados'] ?? '');
    $intereses            = crm_safe_unserialize_array($client_data['intereses']           ?? '');
    $presupuestos_aceptados = crm_safe_unserialize_array($client_data['presupuestos_aceptados'] ?? '');
    $estimado_consumo     = crm_safe_unserialize_array($client_data['estimado_consumo']    ?? '');
    $entrada_vigor_por_sector = crm_safe_unserialize_array($client_data['entrada_vigor_por_sector'] ?? '');
    $decision_por_sector  = crm_safe_unserialize_array($client_data['decision_por_sector'] ?? '');

    // Arrays asegurados
    $sectores      = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    $facturas            = is_array($facturas)           ? $facturas           : [];
    $presupuestos        = is_array($presupuestos)       ? $presupuestos       : [];
    $contratos_firmados  = is_array($contratos_firmados) ? $contratos_firmados : [];
    $contratos_generados = is_array($contratos_generados) ? $contratos_generados : [];
    $intereses           = is_array($intereses)          ? $intereses          : [];
    $estado_por_sector   = is_array($estado_por_sector)  ? $estado_por_sector  : [];
    $presupuestos_aceptados = is_array($presupuestos_aceptados) ? $presupuestos_aceptados : [];
    $estimado_consumo    = is_array($estimado_consumo)   ? $estimado_consumo   : [];
    $entrada_vigor_por_sector = is_array($entrada_vigor_por_sector) ? $entrada_vigor_por_sector : [];
    $decision_por_sector = is_array($decision_por_sector) ? $decision_por_sector : [];

    // Encolar el script JavaScript y localizar datos
    wp_enqueue_script('crm-municipios', CRM_PLUGIN_URL . 'js/municipios-spain.js', array(), CRM_PLUGIN_VERSION, true);
    wp_enqueue_script('crm-uploads',  CRM_PLUGIN_URL . 'js/crm-uploads.js',  array(), CRM_PLUGIN_VERSION, true);
    wp_enqueue_script('crm-scriptv2', CRM_PLUGIN_URL . 'js/crm-scriptv7.js', array('jquery', 'crm-municipios', 'crm-uploads'), CRM_PLUGIN_VERSION, true);
    wp_enqueue_script('crm-notes', CRM_PLUGIN_URL . 'js/crm-notes.js', array('jquery'), CRM_PLUGIN_VERSION, true);
    wp_enqueue_script('crm-duplicates', CRM_PLUGIN_URL . 'js/crm-duplicates.js', array(), CRM_PLUGIN_VERSION, true);
    // v1.19.0 — Tabs de sectores (vertical desktop / horizontal mobile)
    wp_enqueue_script('crm-sector-tabs', CRM_PLUGIN_URL . 'js/crm-sector-tabs.js', array('crm-scriptv2'), CRM_PLUGIN_VERSION, true);

    // Pasar al loader de municipios la URL del bundle JSON (asset estático
    // generado desde el diccionario oficial del INE con tools/build-municipios.ps1).
    wp_localize_script('crm-municipios', 'crmMunicipiosData', array(
        'url' => CRM_PLUGIN_URL . 'assets/data/municipios-es.json',
    ));

    wp_localize_script('crm-scriptv2', 'crmData', array(
        'ajaxurl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('crm_alta_cliente_nonce'),
        'is_admin'       => current_user_can('crm_admin'),
        'current_estado' => $estado_actual,
        'pluginUrl'      => CRM_PLUGIN_URL,
    ));

    ob_start();
?>

    <div class="crm-form-container">
        <div class="crm-header">
            <div class="crm-header-main">
                <div class="crm-header-left">
                    <img src="<?php echo get_site_icon_url(); ?>" alt="Logo" class="crm-logo">
                    <span class="crm-title">
                        Energitel CRM - 
                        <?php if($client_id): ?>
                            Editando: <span style="color: #007cba; font-weight: 600;"><?php echo esc_html($client_data['cliente_nombre'] ?? 'Cliente'); ?></span>
                        <?php else: ?>
                            Alta de Cliente
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="crm-header-right">
                    <!-- Estado principal -->
                    <?php if($client_id): ?>
                        <span class="estado <?php echo $estado_actual; ?>"><?php echo crm_get_estado_label($estado_actual); ?></span>
                    <?php endif; ?>
                    
                    <!-- Solo para comerciales - información condensada -->
                    <?php if(!crm_user_is_admin()): ?>
                        <div class="crm-header-comercial-info">
                            <span class="comercial-asignado">👤 <?php 
                                if($client_id && isset($client_data['delegado'])) {
                                    echo esc_html($client_data['delegado']);
                                } else {
                                    echo esc_html(wp_get_current_user()->display_name);
                                }
                            ?></span>
                            <?php if($estado_actual === 'borrador'): ?>
                                <span class="borrador-info">Sin enviar</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Badges de estado por sector - solo para comerciales -->
            <?php if(!crm_user_is_admin() && $client_id): ?>
                <div class="crm-header-badges">
                    <?php
                    $sectores = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
                    $fechas_envio = maybe_unserialize($client_data['fecha_envio_por_sector'] ?? []);
                    echo crm_render_estado_badges($estado_por_sector, $sectores, $fechas_envio);
                    ?>
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
            // Sistema único basado en el bundle INE (assets/data/municipios-es.json).
            if (!window.CRM_Municipios) {
                hideSuggestions();
                return;
            }
            Promise.resolve(window.CRM_Municipios.buscarMunicipios(query, provincia))
                .then(function (municipalities) {
                    showSuggestions(municipalities || []);
                })
                .catch(function () {
                    hideSuggestions();
                });
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

        <!-- Datos del Comercial - Solo visible para administradores -->
        <?php if(current_user_can('crm_admin')): ?>
        <div class="crm-section inicio">
            <div class="half-width">
                <p><a class="atras" href="/todas-las-altas-de-cliente/">&larr; Regresar atrás</a></p>
                <p>Estado: <strong class="estado <?php echo $estado_actual; ?>"><?php echo crm_get_estado_label($estado_actual); ?></strong></p>
                <?php if ($estado_actual === 'borrador'): ?>
                    <p><small>Esta ficha aún no se ha enviado al administrador para revisión.</small></p>
                <?php endif; ?>
                <?php
                // en crm_formulario_alta_cliente(), antes de ob_start():
                $sectores = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
                $fechas_envio = maybe_unserialize($client_data['fecha_envio_por_sector'] ?? []);
                echo '<div class="crm-badges-estado">';
                echo crm_render_estado_badges($estado_por_sector, $sectores, $fechas_envio);
                echo '</div>';
                ?>
            </div>
            <div class="half-width">
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

                <label for="estado">Estado global:</label>
                <select name="estado" id="estado">
                    <option value="" disabled <?php selected($estado_actual, ''); ?>>Selecciona un estado</option>
                    <?php foreach (crm_get_estados_sector() as $val => $arr): ?>
                        <option value="<?php echo esc_attr($val) ?>" <?php selected($estado_actual, $val) ?>>
                            <?php echo esc_html($arr['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php
                $origen_actual = isset($client_data['origen_lead']) ? (string) $client_data['origen_lead'] : 'directo';
                $cliente_activo = !empty($client_data['es_cliente_activo']);
                $origenes = [
                    'directo'        => 'Directo (alta manual)',
                    'lead_mk'        => 'Lead Marketing (campaña Meta/Google)',
                    'contacto_frio'  => 'Contacto frío',
                    'referido'       => 'Referido / recomendación',
                    'web'            => 'Web / formulario',
                ];
                ?>
                <label for="origen_lead">Origen del lead:</label>
                <select name="origen_lead" id="origen_lead">
                    <?php foreach ($origenes as $v => $lbl): ?>
                        <option value="<?php echo esc_attr($v); ?>" <?php selected($origen_actual, $v); ?>><?php echo esc_html($lbl); ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="crm-inline-check" for="es_cliente_activo">
                    <input type="checkbox" name="es_cliente_activo" id="es_cliente_activo" value="1" <?php checked($cliente_activo); ?>>
                    Cliente activo (ya contratado / en cartera)
                </label>
            </div>
        </div>
        <?php else: ?>
        <!-- Para comerciales: campos ocultos con sus datos -->
        <input type="hidden" name="delegado" value="<?php echo esc_attr(isset($client_data['delegado']) ? $client_data['delegado'] : wp_get_current_user()->display_name); ?>">
        <input type="hidden" name="email_comercial" value="<?php echo esc_attr(isset($client_data['email_comercial']) ? $client_data['email_comercial'] : wp_get_current_user()->user_email); ?>">
        <input type="hidden" name="origen_lead" value="<?php echo esc_attr($client_data['origen_lead'] ?? 'directo'); ?>">
        <input type="hidden" name="es_cliente_activo" value="<?php echo !empty($client_data['es_cliente_activo']) ? '1' : '0'; ?>">
        <?php endif; ?>

        <!-- Datos del Cliente -->
        <div class="crm-section datos">
            <h3>Datos del Cliente</h3>
            <div class="datos-container">
                <div class="form-group full-width">
                    <div class="crm-field">
                        <input type="text" name="cliente_nombre" id="cliente_nombre" placeholder=" " required value="<?php echo esc_attr($client_data['cliente_nombre'] ?? ''); ?>">
                        <label for="cliente_nombre">Nombre del Cliente</label>
                    </div>
                </div>
                <div class="form-group full-width">
                    <div class="crm-field">
                        <input type="text" name="empresa" id="empresa" placeholder=" " value="<?php echo esc_attr($client_data['empresa'] ?? ''); ?>">
                        <label for="empresa">Empresa</label>
                    </div>
                </div>
                <div class="form-group full-width">
                    <div class="crm-field">
                        <input type="text" name="direccion" id="direccion" placeholder=" " value="<?php echo esc_attr($client_data['direccion'] ?? ''); ?>">
                        <label for="direccion">Dirección</label>
                    </div>
                </div>
                <div class="form-group half-width">
                    <div class="crm-field">
                        <input type="tel" 
                               name="telefono" 
                               id="telefono" 
                               placeholder=" " 
                               value="<?php echo esc_attr($client_data['telefono'] ?? ''); ?>"
                               pattern="^(\+34\s?)?[6789]\d{2}\s?\d{3}\s?\d{3}$"
                               title="Ingrese un teléfono español válido (móvil: 6XX/7XX XXX XXX, fijo: 9XX XXX XXX)"
                               maxlength="15">
                        <label for="telefono">Teléfono</label>
                    </div>
                    <small class="phone-help">Móvil: 6XX/7XX XXX XXX · Fijo: 9XX XXX XXX</small>
                </div>
                <div class="form-group half-width">
                    <div class="crm-field">
                        <input type="email" 
                               name="email_cliente" 
                               id="email_cliente" 
                               placeholder=" " 
                               value="<?php echo esc_attr($client_data['email_cliente'] ?? ''); ?>"
                               required
                               pattern="[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$"
                               title="Ingrese un email válido (ejemplo: usuario@dominio.com)"
                               maxlength="100">
                        <label for="email_cliente">Email del cliente</label>
                    </div>
                </div>
                <div class="form-group half-width">
                    <div class="crm-field">
                        <select name="provincia" id="provincia" required class="form-select<?php echo !empty($client_data['provincia']) ? ' has-value' : ''; ?>">
                            <option value="" disabled <?php echo !isset($client_data['provincia']) || empty($client_data['provincia']) ? 'selected' : ''; ?>>Seleccionar provincia</option>
                        <?php
                        // Fuente única de verdad: includes/data.php (52 provincias INE
                        // oficiales). El nombre guardado en BD puede usar formas
                        // antiguas; lo normalizamos antes de marcar el selected para
                        // que cargue correctamente en edición.
                        $provincia_guardada = $client_data['provincia'] ?? '';
                        $provincia_actual   = crm_normalize_provincia($provincia_guardada !== '' ? $provincia_guardada : 'León');

                        // Ordenar alfabéticamente por nombre (collation español sin acentos).
                        $provincias_oficiales = crm_get_provincias_oficiales();
                        usort($provincias_oficiales, function ($a, $b) {
                            $strip = function ($s) {
                                if (class_exists('Normalizer')) {
                                    $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
                                    $s = preg_replace('/\p{M}+/u', '', $s);
                                }
                                return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
                            };
                            return strcmp($strip($a['name']), $strip($b['name']));
                        });

                        foreach ($provincias_oficiales as $prov) {
                            $selected = ($provincia_actual === $prov['name']) ? 'selected' : '';
                            echo "<option value='" . esc_attr($prov['name']) . "' $selected>" . esc_html($prov['name']) . "</option>";
                        }
                        ?>
                    </select>
                        <label for="provincia">Provincia</label>
                    </div>
                    <small class="province-help">Seleccione la provincia oficial</small>
                </div>
                <div class="form-group half-width">
                    <div class="autocomplete-container">
                        <div class="crm-field">
                            <input type="text" 
                                   name="poblacion" 
                                   id="poblacion" 
                                   placeholder=" " 
                                   value="<?php echo esc_attr($client_data['poblacion'] ?? ''); ?>"
                                   class="form-input-autocomplete"
                                   autocomplete="off">
                            <label for="poblacion">Población</label>
                        </div>
                        <div id="poblacion-suggestions" class="autocomplete-suggestions"></div>
                        <small class="population-help">Escriba el nombre del municipio</small>
                    </div>
                </div>
                <div class="form-group half-width">
                    <div class="crm-field">
                        <select name="tipo" id="tipo" required class="<?php echo !empty($client_data['tipo']) ? 'has-value' : ''; ?>">
                            <option value="" disabled <?php echo !isset($client_data['tipo']) || empty($client_data['tipo']) ? 'selected' : ''; ?>>Selecciona tipo</option>
                            <option value="Residencial" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'Residencial' ? 'selected' : ''; ?>>Residencial</option>
                            <option value="Autónomo" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'Autónomo' ? 'selected' : ''; ?>>Autónomo</option>
                            <option value="Empresa" <?php echo isset($client_data['tipo']) && $client_data['tipo'] === 'Empresa' ? 'selected' : ''; ?>>Empresa</option>
                        </select>
                        <label for="tipo">Tipo de cliente</label>
                    </div>
                </div>
                <div class="form-group full-width">
                    <div class="crm-field">
                        <textarea name="comentarios" id="comentarios" placeholder=" "><?php echo esc_textarea($client_data['comentarios'] ?? ''); ?></textarea>
                        <label for="comentarios">Comentarios</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Intereses del Cliente -->
        <div class="crm-section intereses">
            <h3>Intereses del Cliente</h3>
            <div class="intereses-container">
                <?php
                $sector_labels_intereses = [
                    'luz'                => 'Luz',
                    'gas'                => 'Gas',
                    'telecomunicaciones' => 'Telecom',
                    'alarmas'            => 'Alarmas',
                    'agua'               => 'Agua',
                    'asesoria'           => 'Asesoría',
                ];
                foreach ($sectores as $sector) {
                    $checked = in_array($sector, $intereses, true) ? 'checked' : '';
                    $label   = $sector_labels_intereses[$sector] ?? ucfirst($sector);
                    $icon    = function_exists('crm_icon') ? crm_icon(crm_icon_for_sector($sector), 14) : '';
                    echo "<input type='checkbox' id='interes-{$sector}' name='intereses[]' value='" . esc_attr($sector) . "' {$checked}>";
                    echo "<label for='interes-{$sector}'>{$icon}<span>" . esc_html($label) . "</span></label>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
                $estimado_sec = isset($estimado_consumo[$sector]) && is_array($estimado_consumo[$sector])
                    ? $estimado_consumo[$sector]
                    : ['rango' => '', 'valor' => '', 'unidad' => ''];
                $opt_sec      = crm_get_estimado_opciones()[$sector] ?? null;
            ?>
                <div class="crm-card sector-card sector-<?php echo esc_attr($sector); ?>" style="display:none;">
                    <div class="card-header">
                        <h4 style="display:inline-flex; align-items:center; gap:8px; margin-right:0.5em;">
                            <?php echo function_exists('crm_icon') ? crm_icon(crm_icon_for_sector($sector), 18) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <span><?php echo esc_html($secLabel); ?></span>
                            <?php echo function_exists('crm_badge') ? crm_badge($estado_sec, 'sm') : ('<small>(' . esc_html(str_replace('_', ' ', $estado_sec)) . ')</small>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php
                            // v1.19.2 — badge de estado de decisión (si hay)
                            $decision_actual = isset($decision_por_sector[$sector]) ? (string) $decision_por_sector[$sector] : '';
                            echo crm_render_decision_badge($decision_actual, 'sm'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </h4>

                        <?php if (crm_user_is_admin()): ?>
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
                            <strong><?php echo ($sector === 'alarmas') ? 'Facturas/Documentación:' : 'Facturas:'; ?></strong>
                            <?php foreach ($filesF as $url): ?>
                                <div class="uploaded-file">
                                    <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(basename($url)); ?></a>
                                    <button type="button" class="remove-file-btn" data-url="<?php echo esc_attr($url); ?>" data-tipo="factura">×</button>
                                    <input type="hidden" name="facturas[<?php echo esc_attr($sector); ?>][]" value="<?php echo esc_attr($url); ?>">
                                </div>
                            <?php endforeach; ?>
                            <input type="file" class="upload-input" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="factura" multiple>
                            <button type="button" class="upload-btn" data-sector="<?php echo esc_attr($sector); ?>" data-tipo="factura"><?php echo ($sector === 'alarmas') ? 'Subir documentación' : 'Subir factura'; ?></button>
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
                            
                            <!-- Checkbox presupuesto aceptado (visible si hay presupuestos o comercial) -->
                            <?php if (!crm_user_is_admin()):
                                $presupuesto_aceptado_checked = isset($presupuestos_aceptados[$sector]) ? 'checked' : '';
                                $show_checkbox = !empty($filesP) ? 'block' : 'none';
                            ?>
                                <div class="presupuesto-aceptado-section" 
                                     style="margin-top: 10px; padding: 8px; background: #f8f9fa; border-radius: 4px; display: <?php echo $show_checkbox; ?>;" 
                                     data-sector="<?php echo esc_attr($sector); ?>">
                                    <label style="display: flex; align-items: center; font-weight: 500; color: #2c5282;">
                                        <input type="checkbox" 
                                               class="presupuesto-aceptado-checkbox" 
                                               name="presupuesto_aceptado[<?php echo esc_attr($sector); ?>]" 
                                               data-sector="<?php echo esc_attr($sector); ?>"
                                               <?php echo $presupuesto_aceptado_checked; ?>
                                               style="margin-right: 8px;">
                                        <span>✓ Cliente ha aceptado este presupuesto</span>
                                    </label>
                                    <small style="color: #666; margin-left: 24px; display: block;">
                                        Marca esta casilla cuando el cliente confirme que acepta la propuesta
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- v1.19.2 — Estado de decisión (comercial + admin) -->
                        <div class="decision-section" data-sector="<?php echo esc_attr($sector); ?>" style="margin-top:14px; padding:10px 12px; background:#fffdf6; border:1px solid #f1e5b8; border-radius:6px;">
                            <label for="decision-<?php echo esc_attr($sector); ?>" style="display:block; font-weight:600; color:#7a5a00; margin-bottom:4px;">
                                <?php echo function_exists('crm_icon') ? crm_icon('clock', 14) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                Estado de decisión
                            </label>
                            <small style="color:#666; display:block; margin-bottom:6px;">
                                Indica por qué este sector no avanza (bloqueo o pendiente). Es independiente del estado principal.
                            </small>
                            <select name="decision[<?php echo esc_attr($sector); ?>]"
                                    id="decision-<?php echo esc_attr($sector); ?>"
                                    class="decision-select"
                                    style="width:100%; max-width:340px;">
                                <?php foreach (crm_get_decisiones_sector() as $dec_val => $dec_cfg): ?>
                                    <option value="<?php echo esc_attr($dec_val); ?>" <?php selected($decision_actual, $dec_val); ?>>
                                        <?php echo esc_html($dec_cfg['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Estimado de consumo del cliente (por sector) -->
                        <?php if ($opt_sec): ?>
                        <div class="estimado-consumo-section" data-sector="<?php echo esc_attr($sector); ?>">
                            <strong>Estimado de consumo:</strong>
                            <p class="estimado-help">
                                Indica un rango aproximado <em>y/o</em> el valor exacto si lo conoces.
                                Sirve como alternativa a la factura para poder enviar el sector al administrador.
                            </p>
                            <div class="estimado-row">
                                <div class="estimado-field estimado-rango">
                                    <label for="estimado-rango-<?php echo esc_attr($sector); ?>">Rango</label>
                                    <select name="estimado[<?php echo esc_attr($sector); ?>][rango]"
                                            id="estimado-rango-<?php echo esc_attr($sector); ?>"
                                            class="estimado-rango-select">
                                        <option value="">— Seleccionar rango —</option>
                                        <?php foreach ($opt_sec['rangos'] as $r): ?>
                                            <option value="<?php echo esc_attr($r['id']); ?>"
                                                <?php selected(($estimado_sec['rango'] ?? ''), $r['id']); ?>>
                                                <?php echo esc_html($r['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="estimado-field estimado-valor">
                                    <label for="estimado-valor-<?php echo esc_attr($sector); ?>">Valor exacto</label>
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           inputmode="decimal"
                                           name="estimado[<?php echo esc_attr($sector); ?>][valor]"
                                           id="estimado-valor-<?php echo esc_attr($sector); ?>"
                                           placeholder="<?php echo esc_attr($opt_sec['placeholder_valor']); ?>"
                                           value="<?php echo esc_attr($estimado_sec['valor'] ?? ''); ?>">
                                </div>
                                <div class="estimado-field estimado-unidad">
                                    <label for="estimado-unidad-<?php echo esc_attr($sector); ?>">Unidad</label>
                                    <select name="estimado[<?php echo esc_attr($sector); ?>][unidad]"
                                            id="estimado-unidad-<?php echo esc_attr($sector); ?>">
                                        <option value="">— Unidad —</option>
                                        <?php foreach ($opt_sec['unidades'] as $u): ?>
                                            <option value="<?php echo esc_attr($u['id']); ?>"
                                                <?php selected(($estimado_sec['unidad'] ?? ''), $u['id']); ?>>
                                                <?php echo esc_html($u['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php
                        // v1.18: entrada en vigor por sector (solo admin)
                        $ev_actual = isset($entrada_vigor_por_sector[$sector]) ? (string) $entrada_vigor_por_sector[$sector] : '';
                        if (crm_user_is_admin()):
                        ?>
                        <div class="entrada-vigor-section" data-sector="<?php echo esc_attr($sector); ?>">
                            <label for="entrada-vigor-<?php echo esc_attr($sector); ?>">
                                <strong>Entrada en vigor:</strong>
                                <small>Fecha desde la que el servicio queda activo (solo informativa).</small>
                            </label>
                            <input type="date"
                                   id="entrada-vigor-<?php echo esc_attr($sector); ?>"
                                   name="entrada_vigor[<?php echo esc_attr($sector); ?>]"
                                   value="<?php echo esc_attr($ev_actual); ?>">
                        </div>
                        <?php elseif ($ev_actual !== ''): ?>
                        <div class="entrada-vigor-readonly">
                            <strong>Entrada en vigor:</strong>
                            <span><?php echo esc_html($ev_actual); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Botón sectorial para Comerciales -->
                        <?php if (! crm_user_is_admin()):
                            $show_button = isset($presupuestos_aceptados[$sector]) ? 'block' : 'none';
                        ?>
                            <div class="send-sector-wrapper" style="display: <?php echo $show_button; ?>;" data-sector="<?php echo esc_attr($sector); ?>">
                                <button type="button"
                                    class="send-sector-btn crm-submit-btn enviar-btn"
                                    data-sector="<?php echo esc_attr($sector); ?>"
                                    title="Confirmar que el cliente ha aprobado el presupuesto y notificar al administrador">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3 20L21 12L3 4V10L16 12L3 14V20Z" fill="currentColor"/>
                                    </svg>
                                    Presupuesto Aprobado <br> Enviar a Admin
                                </button>
                                <small class="sector-send-help">
                                    Al hacer clic confirmas que el cliente ha aprobado el presupuesto de <?php echo esc_html($secLabel); ?>
                                </small>
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
                        <?php if (crm_user_is_admin()): ?>
                            <div class="admin-controls">
                                <label>
                                    <input type="checkbox" name="contratos_generados[]" value="<?php echo esc_attr($sector); ?>" <?php echo $genChecked; ?>>
                                    Marcar contrato generado
                                </label>
                                
                                <!-- Admin: Control de presupuesto aceptado -->
                                <?php 
                                    $admin_presupuesto_checked = isset($presupuestos_aceptados[$sector]) ? 'checked' : '';
                                    $can_toggle_presupuesto = !$genChecked; // Solo se puede desmarcar si no hay contratos generados
                                ?>
                                <label class="admin-presupuesto-control" style="margin-top: 8px; display: flex; align-items: center;">
                                    <input type="checkbox" 
                                           name="admin_presupuesto_aceptado[<?php echo esc_attr($sector); ?>]" 
                                           <?php echo $admin_presupuesto_checked; ?>
                                           <?php echo !$can_toggle_presupuesto ? 'disabled' : ''; ?>
                                           style="margin-right: 8px;">
                                    <span style="color: <?php echo isset($presupuestos_aceptados[$sector]) ? '#10b981' : '#6b7280'; ?>;">
                                        <?php echo isset($presupuestos_aceptados[$sector]) ? '✓ Presupuesto aceptado por cliente' : 'Sin aceptación de presupuesto'; ?>
                                    </span>
                                </label>
                                <?php if (!$can_toggle_presupuesto): ?>
                                    <small style="color: #9ca3af; margin-left: 24px; font-style: italic;">
                                        No se puede modificar (contrato ya generado)
                                    </small>
                                <?php endif; ?>
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


        <!-- ——— Historial y notas (v1.17.0) ——— -->
        <?php if ($client_id && function_exists('crm_notes_render_block')): ?>
            <div class="crm-section crm-section-notes">
                <?php echo crm_notes_render_block((int) $client_id); ?>
            </div>
        <?php endif; ?>


        <!-- ——— Acciones Globales ——— -->
        <div class="crm-global-actions">
            <?php if (crm_user_is_admin()): ?>
                <button type="submit" name="crm_guardar_cliente" class="crm-btn">
                    <i class="fas fa-save" style="margin-right: 8px;"></i>Guardar ficha
                </button>
                <button type="submit" name="crm_guardar_notificar" class="crm-btn enviar-btn">
                    <i class="fas fa-paper-plane" style="margin-right: 8px;"></i>Guardar y notificar comercial
                </button>
            <?php else: ?>
                <button type="submit" name="crm_guardar_cliente" class="crm-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17,21 17,13 7,13 7,21"></polyline>
                        <polyline points="7,3 7,8 15,8"></polyline>
                    </svg>
                    Guardar ficha
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
    // v1.20.0 — Bloque de visitas (fuera del form principal para evitar anidación de <form>)
    if ($client_id && function_exists('crm_render_visitas_box')) {
        crm_render_visitas_box((int) $client_id);
    }
    ?>

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

        // Control de visibilidad del botón "Enviar a Admin" basado en checkbox presupuesto aceptado
        function toggleSendSectorButton() {
            document.querySelectorAll('.presupuesto-aceptado-checkbox').forEach(function(checkbox) {
                const sector = checkbox.dataset.sector;
                const sendWrapper = document.querySelector('.send-sector-wrapper[data-sector="' + sector + '"]');
                
                if (sendWrapper) {
                    if (checkbox.checked) {
                        sendWrapper.style.display = 'block';
                    } else {
                        sendWrapper.style.display = 'none';
                    }
                }
            });
        }

        // Mostrar checkbox cuando se sube un presupuesto
        function showPresupuestoCheckbox(sector) {
            const checkboxSection = document.querySelector('.presupuesto-aceptado-section[data-sector="' + sector + '"]');
            if (checkboxSection) {
                checkboxSection.style.display = 'block';
            }
        }

        // Ejecutar al cargar la página
        toggleSendSectorButton();

        // Añadir listeners a los checkboxes
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('presupuesto-aceptado-checkbox')) {
                toggleSendSectorButton();
            }
        });

        // Listener global para detectar subida de presupuestos
        document.addEventListener('CRM_FILE_UPLOADED', function(e) {
            if (e.detail && e.detail.tipo === 'presupuesto' && e.detail.sector) {
                showPresupuestoCheckbox(e.detail.sector);
            }
        });
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
add_action('wp_ajax_crm_guardar_notificar_ajax', 'crm_guardar_notificar_ajax');
add_action('wp_ajax_crm_sync_offline_data', 'crm_sync_offline_data_ajax');

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

// Función para enviar por sector (usada por botones "Enviar a Admin" de cada sector)
function crm_enviar_cliente_ajax()
{
    try {
        $estado = isset($_POST['estado_formulario']) ? sanitize_text_field($_POST['estado_formulario']) : 'enviado';
        crm_handle_ajax_request($estado);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Función para guardar y notificar comercial (solo admin)
function crm_guardar_notificar_ajax()
{
    try {
        if (!current_user_can('crm_admin')) {
            wp_send_json_error(['message' => 'No tienes permisos para esta acción']);
            return;
        }
        
        $estado = isset($_POST['estado_formulario']) ? sanitize_text_field($_POST['estado_formulario']) : 'actualizado';
        crm_handle_ajax_request($estado, true); // true indica que se debe enviar notificación
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Función para sincronizar datos guardados offline
function crm_sync_offline_data_ajax()
{
    try {
        // Verificar permisos básicos
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Usuario no autenticado']);
            return;
        }

        // Verificar que sea una sincronización offline
        if (!isset($_POST['offline_sync']) || $_POST['offline_sync'] !== '1') {
            wp_send_json_error(['message' => 'Solicitud de sincronización inválida']);
            return;
        }

        // Log para debugging
        crm_debug_log('Offline Sync: procesando petición para usuario ' . get_current_user_id());

        // Determinar el estado basado en los datos recibidos
        $estado = 'enviado'; // Por defecto, los datos offline se marcan como enviados
        
        // Si es admin, puede tener estado específico
        if (current_user_can('crm_admin') && isset($_POST['estado'])) {
            $estado = sanitize_text_field($_POST['estado']);
        }

        // Procesar usando la función principal
        crm_handle_ajax_request($estado, false);
        
    } catch (Exception $e) {
        crm_debug_log('Offline Sync error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Error en sincronización: ' . $e->getMessage()]);
    }
}

// Función principal para manejar la lógica AJAX
function crm_handle_ajax_request($estado_inicial, $enviar_notificacion = false)
{
    global $wpdb;
    $table      = $wpdb->prefix . 'crm_clients';

    $sectores   = crm_get_valid_sectores();
    $client_id  = intval($_POST['client_id'] ?? 0);
    $is_update  = $client_id > 0;
    $client     = $is_update
        ? (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $client_id), ARRAY_A)
        : [];

    // Debug: Log incoming data
    crm_debug_log('AJAX cliente: is_update=' . ($is_update ? '1' : '0') . ' client_id=' . (int) $client_id);

    // seguridad
    if (! wp_verify_nonce($_POST['crm_nonce'] ?? '', 'crm_alta_cliente_nonce')) {
        crm_debug_log('Nonce inválido en alta/edición de cliente.');
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
        $existing  = crm_safe_unserialize_array($existing_serialized);
        if (! is_array($existing)) {
            $existing = [];
        }
        $new_urls  = [];

        // 1) archivos server
        if (! empty($_FILES[$field_name]['name']) && is_array($_FILES[$field_name]['name'])) {
            foreach ($_FILES[$field_name]['name'] as $sector => $names) {
                $sector = sanitize_key($sector);
                if (!in_array($sector, $sectores, true) || !is_array($names)) {
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
                    $secure = crm_handle_secure_upload($file, $field_name);
                    if (!is_wp_error($secure) && !empty($secure['url'])) {
                        $new_urls[$sector][] = $secure['url'];
                    }
                }
            }
        }

        // 2) URLs recibidas en POST (solo aceptamos URLs ya dentro de uploads)
        foreach ((array) ($_POST[$field_name] ?? []) as $sector => $urls) {
            $sector = sanitize_key($sector);
            if (!in_array($sector, $sectores, true)) {
                continue;
            }
            $clean = crm_filter_uploads_urls((array) $urls);
            if (!empty($clean)) {
                $new_urls[$sector] = array_merge($new_urls[$sector] ?? [], $clean);
            }
        }

        // 3) fusionar existente + nuevas (solo claves de sectores válidos)
        foreach ($new_urls as $s => $urls) {
            if (!in_array($s, $sectores, true)) {
                continue;
            }
            $prev          = isset($existing[$s]) && is_array($existing[$s]) ? $existing[$s] : [];
            $existing[$s]  = array_values(array_unique(array_merge($prev, $urls)));
        }

        // Sanear cualquier clave huérfana que no esté en la whitelist
        $existing = array_intersect_key($existing, array_flip($sectores));

        return $existing;
    };

    // Procesar facturas, presupuestos y contratos firmados
    $facturas_existentes   = $handle_files('facturas',          $client['facturas'] ?? '');
    $presu_existentes      = $handle_files('presupuesto',       $client['presupuesto'] ?? '');
    $contratos_existentes  = $handle_files('contratos_firmados', $client['contratos_firmados'] ?? '');

    // contratos generados (whitelist de sectores)
    $contratos_generados = crm_sanitize_sectores_list((array) ($_POST['contratos_generados'] ?? []));

    // ————— Gestión de envío por sector mejorada —————
    $fechas_envio = crm_safe_unserialize_array($client['fecha_envio_por_sector'] ?? '');
    $users_envio  = crm_safe_unserialize_array($client['usuario_envio_por_sector'] ?? '');

    // Asegurar que son arrays
    if (!is_array($fechas_envio)) {
        $fechas_envio = [];
    }
    if (!is_array($users_envio)) {
        $users_envio = [];
    }

    $env_sectores = crm_sanitize_sectores_list((array) ($_POST['enviar_sector'] ?? []));

    // Sanitizar el estimado de consumo aquí (necesario antes del loop de
    // estado para poder validar "factura O estimado" al enviar un sector).
    $estimado_in  = (array) ($_POST['estimado'] ?? []);
    $estimado_out = [];
    foreach ($estimado_in as $sector_key => $vals) {
        $sector_key = sanitize_key($sector_key);
        $clean = crm_sanitize_estimado_sector($sector_key, (array) $vals);
        if ($clean !== null) {
            $estimado_out[$sector_key] = $clean;
        }
    }

    // —— Validación v1.17: para enviar un sector al admin debe haber al
    // menos una factura subida O un estimado de consumo informado en
    // ese sector. Sin alguno de los dos no tiene sentido enviarlo.
    // (El admin sí puede forzar el estado con `forzar_estado`).
    $is_send_now = current_filter() === 'wp_ajax_crm_enviar_cliente_ajax';
    $forzar_now  = current_user_can('crm_admin') && ! empty($_POST['forzar_estado']);
    if ($is_send_now && !$forzar_now && !empty($env_sectores)) {
        $faltantes = [];
        foreach ($env_sectores as $s_env) {
            $tiene_factura  = !empty($facturas_existentes[$s_env]);
            $tiene_estimado = isset($estimado_out[$s_env])
                && (!empty($estimado_out[$s_env]['rango'])
                    || (isset($estimado_out[$s_env]['valor']) && $estimado_out[$s_env]['valor'] !== null));
            if (!$tiene_factura && !$tiene_estimado) {
                $faltantes[] = ucfirst($s_env);
            }
        }
        if (!empty($faltantes)) {
            wp_send_json_error([
                'message' => 'Para enviar al administrador necesitas adjuntar una factura o indicar el estimado de consumo en: '
                    . implode(', ', $faltantes) . '.',
                'sectores_faltantes' => $faltantes,
            ]);
        }
    }

    // Solo actualizar fecha y usuario para sectores que se están enviando AHORA
    foreach ($env_sectores as $s) {
        $fechas_envio[$s] = current_time('d/m/Y H:i');
        $users_envio[$s]  = wp_get_current_user()->display_name;
    }
    // ————— Reconstruyo intereses —————
    // 1) Los que vienen marcados en el POST (checkboxes), filtrados por whitelist
    $intereses_post = crm_sanitize_sectores_list((array) ($_POST['intereses'] ?? []));
    
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
    $old_estado  = crm_safe_unserialize_array($client['estado_por_sector'] ?? '');
    if (!is_array($old_estado)) {
        $old_estado = [];
    }
    // Solo aceptar claves de sectores válidos para evitar inyectar columnas raras
    $old_estado = array_intersect_key($old_estado, array_flip($sectores));
    $new_estado  = $old_estado; // Preservamos los estados existentes

    // Sanitizar arrays de aceptación por sector recibidos por POST
    $admin_acept_post   = current_user_can('crm_admin') && isset($_POST['admin_presupuesto_aceptado'])
        ? crm_sanitize_sector_map((array) $_POST['admin_presupuesto_aceptado'])
        : [];
    $comercial_acept_post = isset($_POST['presupuesto_aceptado'])
        ? crm_sanitize_sector_map((array) $_POST['presupuesto_aceptado'])
        : [];

    // Si admin está gestionando presupuestos, necesitamos procesar TODOS los sectores posibles
    $sectores_a_procesar = $intereses;
    if (!empty($admin_acept_post)) {
        $existing_presupuestos = crm_safe_unserialize_array($client['presupuestos_aceptados'] ?? '');
        if (is_array($existing_presupuestos)) {
            $sectores_a_procesar = array_unique(array_merge($intereses, array_keys($existing_presupuestos), array_keys($old_estado)));
        }
    }
    $sectores_a_procesar = array_values(array_intersect($sectores_a_procesar, $sectores));

    foreach ($sectores_a_procesar as $s) {
        $e = $old_estado[$s] ?? 'borrador'; // Estado actual del sector
        $estado_forzado = false;

        // 1) Si se está enviando este sector específicamente
        if ($is_send && in_array($s, $env_sectores, true) && $e === 'borrador' && !$forzar) {
            $e = 'enviado';
        }

        // 1.5) Si comercial marcó "presupuesto aceptado" para este sector
        if (!current_user_can('crm_admin') && isset($comercial_acept_post[$s]) && !$forzar) {
            $e = 'presupuesto_aceptado';
        }

        // 1.6) Si admin marcó "presupuesto aceptado" para este sector
        if (current_user_can('crm_admin') && isset($admin_acept_post[$s]) && !$forzar) {
            $e = 'presupuesto_aceptado';
        }

        // 1.7) Si admin DESMARCÓ un presupuesto que estaba aceptado - revertir al estado lógico anterior
        if (current_user_can('crm_admin') && !isset($admin_acept_post[$s]) && $e === 'presupuesto_aceptado' && !$forzar) {
            // Determinar el estado correcto sin la aceptación del presupuesto
            if (!empty($presu_existentes[$s])) {
                $e = 'presupuesto_generado'; // Tiene presupuesto subido
            } else {
                $e = 'enviado'; // No tiene presupuesto, volver a enviado
            }
        }

        // 2) Admin forzando estado específico
        if ($forzar && isset($_POST["estado_{$s}"])) {
            $e = sanitize_text_field($_POST["estado_{$s}"]);
            $estado_forzado = true;
        }

        // Solo aplicar lógica automática si no se ha forzado el estado
        if (!$estado_forzado) {
            // 3) Lógica automática de transición: enviado con presupuesto -> presupuesto_generado
            if (
                $e === 'enviado'
                && !empty($presu_existentes[$s])
            ) {
                $e = 'presupuesto_generado';
            }

            // 4) Lógica automática: presupuesto_aceptado -> contratos_generados
            if ($e === 'presupuesto_aceptado' && in_array($s, $contratos_generados, true)) {
                $e = 'contratos_generados';
            }

            // 5) Estado terminal: si hay contratos firmados subidos para este sector,
            //    el flujo está completo y el estado es 'contratos_firmados', sobrescribiendo
            //    cualquier paso intermedio o reversión previa (regla 1.7). Tener un
            //    contrato firmado físico implica que el presupuesto fue aceptado aunque
            //    el admin no haya vuelto a marcar la checkbox al editar.
            if (!empty($contratos_existentes[$s])) {
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

    // Validación específica de la provincia
    $provincia = sanitize_text_field($_POST['provincia']);
    if (!empty($provincia) && !crm_validate_provincia($provincia)) {
        wp_send_json_error(['message' => 'La provincia seleccionada no es válida. Por favor, seleccione una provincia oficial de España.']);
    }
    // Normalizar a la forma INE oficial para que la BD quede consistente
    // y los lookups contra el bundle de municipios funcionen siempre.
    if (!empty($provincia)) {
        $provincia = crm_normalize_provincia($provincia);
    }

    // Validación específica de la población
    $poblacion = sanitize_text_field($_POST['poblacion']);
    if (!empty($poblacion) && !crm_validate_poblacion($poblacion, $provincia)) {
        wp_send_json_error(['message' => 'El nombre de la población no es válido. Use solo letras, espacios y guiones. Mínimo 2 caracteres.']);
    }

    // Procesar presupuestos aceptados (combinando comerciales y admin)
    $presupuestos_aceptados_final = [];
    
    if (current_user_can('crm_admin') && isset($_POST['admin_presupuesto_aceptado'])) {
        // Admin está gestionando - usar sus controles
        $admin_input = (array)$_POST['admin_presupuesto_aceptado'];
        
        // Obtener los existentes
        $existing_presupuestos = maybe_unserialize($client['presupuestos_aceptados'] ?? '');
        if (!is_array($existing_presupuestos)) $existing_presupuestos = [];
        
        // Obtener todos los sectores posibles (activos + los que tenían presupuestos antes)
        $all_sectors = array_unique(array_merge($intereses, array_keys($existing_presupuestos)));
        
        foreach ($all_sectors as $sector) {
            // Un sector se considera "contratado" si está en la lista de contratos
            // generados (checkbox) o si ya tiene contratos firmados subidos.
            // En ambos casos el presupuesto aceptado es un prerrequisito implícito
            // y no se puede revocar.
            $tiene_contrato = in_array($sector, $contratos_generados, true)
                || !empty($contratos_existentes[$sector]);

            if (isset($admin_input[$sector])) {
                // Admin marcó este sector
                $presupuestos_aceptados_final[$sector] = true;
            } elseif ($tiene_contrato && isset($existing_presupuestos[$sector])) {
                // Tiene contrato y estaba marcado antes - conservar (no se puede desmarcar)
                $presupuestos_aceptados_final[$sector] = true;
            } elseif (!empty($contratos_existentes[$sector])) {
                // Tiene contratos firmados subidos pero nunca se marcó como aceptado
                // (caso típico: admin sube directamente firmados sin pasar por la
                // checkbox de aceptación). Forzamos la marca para mantener consistencia
                // con el nuevo estado terminal 'contratos_firmados'.
                $presupuestos_aceptados_final[$sector] = true;
            }
            // Si no está marcado por admin y no tiene contrato, se desmarca/no se incluye
        }
    } else {
        // No es admin o no está gestionando - usar el método existente (comerciales)
        $presupuestos_aceptados_final = (array)($_POST['presupuesto_aceptado'] ?? []);
    }

    // $estimado_out ya se sanitizó arriba (justo después de $env_sectores)
    // para poder validar "factura O estimado" al enviar un sector.

    // v1.18: origen del lead + flag cliente activo. Solo el admin puede modificarlos
    // libremente; el comercial mantiene el valor previo a través del input hidden.
    $origen_in = isset($_POST['origen_lead']) ? sanitize_key($_POST['origen_lead']) : '';
    $origenes_validos = ['directo', 'lead_mk', 'contacto_frio', 'referido', 'web'];
    if (!in_array($origen_in, $origenes_validos, true)) {
        $origen_in = $client['origen_lead'] ?? 'directo';
    }
    $cliente_activo_in = !empty($_POST['es_cliente_activo']) ? 1 : 0;
    if (!current_user_can('crm_admin')) {
        // Comerciales no pueden tocar el flag de "cliente activo"
        $cliente_activo_in = isset($client['es_cliente_activo']) ? (int) $client['es_cliente_activo'] : 0;
    }

    // v1.18: entrada_vigor por sector (solo admin)
    $entrada_vigor_out = isset($client['entrada_vigor_por_sector'])
        ? (maybe_unserialize($client['entrada_vigor_por_sector']) ?: [])
        : [];
    if (current_user_can('crm_admin') && isset($_POST['entrada_vigor']) && is_array($_POST['entrada_vigor'])) {
        $sectores_validos_ev = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
        foreach ($_POST['entrada_vigor'] as $sec_ev => $fecha_ev) {
            $sec_ev = sanitize_key($sec_ev);
            if (!in_array($sec_ev, $sectores_validos_ev, true)) {
                continue;
            }
            $fecha_ev = trim((string) $fecha_ev);
            if ($fecha_ev === '') {
                unset($entrada_vigor_out[$sec_ev]);
                continue;
            }
            // Aceptar Y-m-d
            $dt = DateTime::createFromFormat('Y-m-d', $fecha_ev);
            if ($dt && $dt->format('Y-m-d') === $fecha_ev) {
                $entrada_vigor_out[$sec_ev] = $fecha_ev;
            }
        }
    }

    // v1.19.2: estado de decisión por sector (comercial Y admin)
    $decision_out = isset($client['decision_por_sector'])
        ? (maybe_unserialize($client['decision_por_sector']) ?: [])
        : [];
    if (!is_array($decision_out)) {
        $decision_out = [];
    }
    if (isset($_POST['decision']) && is_array($_POST['decision'])) {
        $decisiones_validas = array_keys(crm_get_decisiones_sector());
        $sectores_validos_dec = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
        foreach ((array) $_POST['decision'] as $sec_dec => $val_dec) {
            $sec_dec = sanitize_key($sec_dec);
            $val_dec = sanitize_text_field(wp_unslash((string) $val_dec));
            if (!in_array($sec_dec, $sectores_validos_dec, true)) {
                continue;
            }
            if ($val_dec === '' || !in_array($val_dec, $decisiones_validas, true)) {
                unset($decision_out[$sec_dec]);
                continue;
            }
            $decision_out[$sec_dec] = $val_dec;
        }
    }

    // v1.18: aviso de duplicado (no bloquea, solo se loguea en notas si es nuevo)
    $dup_warning = null;
    if (!$is_update && function_exists('crm_find_duplicate_clients')) {
        $dups_pre = crm_find_duplicate_clients($telefono, $email_cliente, 0, 3);
        if (!empty($dups_pre)) {
            $dup_warning = $dups_pre;
        }
    }

    // v1.20.3: hardening — un comercial NO puede cambiar delegado/email_comercial/
    // origen_lead/es_cliente_activo de un cliente existente, ni asignárselo a otro
    // en altas (siempre se le asigna a sí mismo).
    $is_admin_save = current_user_can('crm_admin');
    if (!$is_admin_save) {
        $self = wp_get_current_user();
        if ($is_update) {
            // Update: preservar SIEMPRE los valores ya guardados
            $forced_delegado        = (string) ($client['delegado'] ?? $self->display_name);
            $forced_email_comercial = (string) ($client['email_comercial'] ?? $self->user_email);
            $forced_origen          = (string) ($client['origen_lead'] ?? 'directo');
            $forced_activo          = !empty($client['es_cliente_activo']) ? 1 : 0;
        } else {
            // Alta nueva: asignarse a sí mismo siempre
            $forced_delegado        = (string) $self->display_name;
            $forced_email_comercial = (string) $self->user_email;
            $forced_origen          = sanitize_text_field($_POST['origen_lead'] ?? 'directo');
            $forced_activo          = !empty($_POST['es_cliente_activo']) ? 1 : 0;
        }
        $_POST['delegado']          = $forced_delegado;
        $_POST['email_comercial']   = $forced_email_comercial;
        $_POST['origen_lead']       = $forced_origen;
        $_POST['es_cliente_activo'] = $forced_activo;
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
        'poblacion'                 => $poblacion,
        'provincia'                 => $provincia, // Usar variable validada
        'tipo'                      => sanitize_text_field($_POST['tipo']),
        'comentarios'               => sanitize_textarea_field($_POST['comentarios']), // Usar función específica para textarea
        'intereses' => maybe_serialize($intereses),

        'facturas'                  => maybe_serialize($facturas_existentes),
        'presupuesto'               => maybe_serialize($presu_existentes),
        'contratos_firmados'        => maybe_serialize($contratos_existentes),
        'contratos_generados'       => maybe_serialize($contratos_generados),
        'presupuestos_aceptados'    => maybe_serialize($presupuestos_aceptados_final),
        'estimado_consumo'          => maybe_serialize($estimado_out),
        'estado'                    => $estado,
        'estado_por_sector'         => maybe_serialize($new_estado),
        'fecha_envio_por_sector'    => maybe_serialize($fechas_envio),
        'usuario_envio_por_sector'  => maybe_serialize($users_envio),
        'origen_lead'               => $origen_in,
        'es_cliente_activo'         => $cliente_activo_in,
        'entrada_vigor_por_sector'  => maybe_serialize($entrada_vigor_out),
        'decision_por_sector'       => maybe_serialize($decision_out),
        'editado_por'               => get_current_user_id(),
        'actualizado_en'            => current_time('mysql'),
        'actualizado_por'           => get_current_user_id(),
    ];

    if ($estado === 'enviado') {
        $data['enviado_por']   = get_current_user_id();
        $data['fecha_enviado'] = current_time('mysql');
    }

    if ($is_update) {
        // Auditoría: registrar antes de actualizar
        if (function_exists('crm_debug_sector_save')) {
            crm_debug_sector_save($client_id, 'before_update', ['old' => $client, 'new' => $data]);
        }

        $result = $wpdb->update($table, $data, ['id' => $client_id]);
        if ($result === false) {
            error_log('CRM: error actualizando cliente ' . (int) $client_id . ': ' . $wpdb->last_error);
            wp_send_json_error(['message' => 'Error al actualizar cliente en la base de datos: ' . $wpdb->last_error]);
        }

        // Auditoría: registrar después de actualizar
        if (function_exists('crm_debug_sector_save')) {
            crm_debug_sector_save($client_id, 'after_update', $data);
        }
    } else {
        $data['creado_por'] = get_current_user_id();
        $data['creado_en']  = current_time('mysql');
        $result = $wpdb->insert($table, $data);
        if ($result === false) {
            error_log('CRM: error insertando nuevo cliente: ' . $wpdb->last_error);
            wp_send_json_error(['message' => 'Error al crear cliente en la base de datos: ' . $wpdb->last_error]);
        }
        $client_id = $wpdb->insert_id;
        if (!$client_id) {
            error_log('CRM: insert_id no devuelto al crear cliente.');
            wp_send_json_error(['message' => 'Error: No se pudo obtener el ID del cliente creado']);
        }
        // Auditoría: registrar creación
        if (function_exists('crm_debug_sector_save')) {
            crm_debug_sector_save($client_id, 'created', $data);
        }
    }

    // ========== AUTO-NOTAS (bitácora del cliente) ==========
    // Solo registramos auto-notas en updates (en el insert inicial todo
    // sería "estado borrador → borrador"). Las notas manuales se gestionan
    // por su propio endpoint AJAX (crm_note_add).
    if (function_exists('crm_notes_add') && $is_update) {
        // 1) Cambios de estado por sector.
        foreach ($new_estado as $s => $e_new) {
            $e_old = $old_estado[$s] ?? 'borrador';
            if ($e_old !== $e_new) {
                crm_notes_log_state_change($client_id, $s, $e_old, $e_new);
            }
        }

        // 2) Archivos nuevos (factura/presupuesto/contrato_firmado).
        $tipos_archivo = [
            'facturas'           => 'factura',
            'presupuesto'        => 'presupuesto',
            'contratos_firmados' => 'contrato_firmado',
        ];
        $maps_nuevos = [
            'facturas'           => $facturas_existentes,
            'presupuesto'        => $presu_existentes,
            'contratos_firmados' => $contratos_existentes,
        ];
        foreach ($tipos_archivo as $col => $tipo_label) {
            $previo = maybe_unserialize($client[$col] ?? 'a:0:{}');
            $previo = is_array($previo) ? $previo : [];
            foreach ((array) $maps_nuevos[$col] as $sec => $urls) {
                if (!is_array($urls)) {
                    continue;
                }
                $previas = isset($previo[$sec]) && is_array($previo[$sec]) ? $previo[$sec] : [];
                $nuevas  = array_values(array_diff($urls, $previas));
                foreach ($nuevas as $url) {
                    crm_notes_log_file_upload($client_id, $sec, $tipo_label, $url);
                }
                $borradas = array_values(array_diff($previas, $urls));
                foreach ($borradas as $url) {
                    crm_notes_add([
                        'client_id'     => $client_id,
                        'sector'        => $sec,
                        'tipo'          => 'file_remove',
                        'texto'         => sprintf(
                            'Eliminado %s de %s: %s',
                            esc_html($tipo_label),
                            ucfirst($sec),
                            esc_html(basename($url))
                        ),
                        'source_action' => 'auto_file_remove',
                    ]);
                }
            }
        }

        // 3) Reasignación de comercial (solo si admin la cambió).
        $uid_old = (int) ($client['user_id'] ?? 0);
        $uid_new = (int) ($data['user_id']   ?? 0);
        if ($uid_old !== $uid_new) {
            crm_notes_log_assignment($client_id, $uid_old, $uid_new);
            // v1.18: notificar por email al nuevo comercial
            if ($uid_new > 0 && function_exists('crm_notif_assignment_send')) {
                crm_notif_assignment_send($client_id, $uid_new);
            }
        }
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

    // Enviar notificación por email si se solicitó
    if ($enviar_notificacion && $is_update) {
        crm_enviar_notificacion_comercial($client_id, $data, $action_details);
    }

    $response = ['redirect_url' => $redirect];
    if (!empty($dup_warning)) {
        $response['duplicates'] = $dup_warning;
        $response['message']    = 'Cliente guardado. Posible duplicado detectado.';
    }
    wp_send_json_success($response);
}

/**
 * Envía notificación por email al comercial sobre actualizaciones de admin
 */
function crm_enviar_notificacion_comercial($client_id, $client_data, $action_details)
{
    global $wpdb;
    
    // Obtener el comercial del cliente
    $comercial_id = isset($client_data['user_id']) ? intval($client_data['user_id']) : 0;
    if (!$comercial_id) {
        return false;
    }
    
    $comercial = get_user_by('ID', $comercial_id);
    if (!$comercial) {
        return false;
    }
    
    $admin_user = wp_get_current_user();
    $cliente_nombre = isset($client_data['cliente_nombre']) ? (string) $client_data['cliente_nombre'] : 'Cliente';

    // Pre-escapar para evitar XSS en el cuerpo HTML del email
    $cn  = esc_html($cliente_nombre);
    $ce  = esc_html($client_data['email_cliente'] ?? '');
    $ct  = esc_html($client_data['telefono'] ?? '');
    $cd  = esc_html($client_data['delegado'] ?? '');
    $com = esc_html($comercial->display_name);
    $adm = esc_html($admin_user->display_name);

    // Construir el mensaje
    $subject = sprintf('🔔 Actualización de Cliente: %s', $cliente_nombre);

    $message = "
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background: #007cba; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .detail-box { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #007cba; }
        .footer { padding: 15px; text-align: center; color: #666; font-size: 12px; }
        .btn { display: inline-block; background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='header'>
        <h2>📋 Actualización de Cliente CRM</h2>
    </div>
    
    <div class='content'>
        <p>Hola <strong>{$com}</strong>,</p>
        
        <p>El administrador <strong>{$adm}</strong> ha actualizado la ficha de uno de tus clientes:</p>
        
        <div class='detail-box'>
            <h3>👤 Cliente: {$cn}</h3>
            <p><strong>📧 Email:</strong> {$ce}</p>
            <p><strong>📞 Teléfono:</strong> {$ct}</p>
            <p><strong>🏢 Delegado:</strong> {$cd}</p>
        </div>
        
        <div class='detail-box'>
            <h3>📝 Detalles de la Actualización:</h3>
            <ul>";

    foreach ((array) $action_details as $detail) {
        $message .= '<li>' . esc_html((string) $detail) . '</li>';
    }

    $client_url = home_url('/editar-cliente/?client_id=' . (int) $client_id);

    $message .= "
            </ul>
        </div>
        
        <p style='text-align: center;'>
            <a href='" . esc_url($client_url) . "' class='btn'>📝 Ver Cliente</a>
        </p>
        
        <p><em>Esta notificación se envía automáticamente cuando el administrador actualiza los datos de tus clientes.</em></p>
    </div>
    
    <div class='footer'>
        <p>Sistema CRM Energitel | " . esc_html(get_bloginfo('name')) . "</p>
    </div>
</body>
</html>";
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    ];
    
    return wp_mail($comercial->user_email, $subject, $message, $headers);
}

/**
 * Función de debugging para el flujo de guardado por sector.
 *
 * Está DESACTIVADA por defecto en producción para no escribir PII en los
 * logs de actividad. Para habilitarla puntualmente (p.ej. al investigar
 * una incidencia de máquina de estados), añadir en `wp-config.php`:
 *
 *     define('CRM_DEBUG_SECTOR_SAVE', true);
 *
 * Aunque esté habilitada, sólo se persiste un subconjunto de campos
 * relacionados con la máquina de estados (estado, estado_por_sector,
 * presupuestos_aceptados, contratos_generados, contratos_firmados, fechas).
 * Cualquier campo con datos personales (nombre, email, teléfono, dirección,
 * comentarios, etc.) se descarta antes de guardar.
 */
function crm_debug_sector_save($client_id, $action, $data) {
    if (!defined('CRM_DEBUG_SECTOR_SAVE') || !CRM_DEBUG_SECTOR_SAVE) {
        return;
    }
    if (!current_user_can('crm_admin')) {
        return;
    }

    // Lista blanca de claves seguras (sin PII) relacionadas con el flujo de estado.
    $safe_keys = [
        'estado',
        'estado_por_sector',
        'presupuestos_aceptados',
        'presupuesto',
        'contratos_generados',
        'contratos_firmados',
        'facturas',
        'fecha_envio_por_sector',
        'usuario_envio_por_sector',
        'intereses',
        'tipo',
        'enviado_por',
        'fecha_enviado',
        'editado_por',
        'actualizado_en',
        'actualizado_por',
    ];

    $sanitize = function ($payload) use ($safe_keys) {
        if (!is_array($payload)) {
            return null;
        }
        $out = [];
        foreach ($safe_keys as $k) {
            if (array_key_exists($k, $payload)) {
                $out[$k] = $payload[$k];
            }
        }
        return $out;
    };

    $clean = [];
    if (is_array($data) && (isset($data['old']) || isset($data['new']))) {
        $clean['old'] = isset($data['old']) ? $sanitize($data['old']) : null;
        $clean['new'] = isset($data['new']) ? $sanitize($data['new']) : null;
    } else {
        $clean = $sanitize($data);
    }

    $debug_info = [
        'timestamp' => current_time('Y-m-d H:i:s'),
        'action'    => $action,
        'client_id' => $client_id,
        'user_id'   => get_current_user_id(),
        'data'      => $clean,
    ];

    crm_log_action('debug_sector_save', wp_json_encode($debug_info), $client_id);
}



/**
 * Función genérica para subir archivos via AJAX.
 *
 * @param string $tipo Tipo de archivo a subir (factura, presupuesto, contrato_firmado).
 */
function crm_subir_archivo_generico($tipo)
{
    // Permisos
    if (!current_user_can('crm_admin') && !current_user_can('comercial')) {
        wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción.']);
        return;
    }

    // Validar nonce y parámetros
    if (
        !isset($_POST['sector'], $_FILES['file'], $_POST['nonce']) ||
        !wp_verify_nonce($_POST['nonce'], 'crm_alta_cliente_nonce')
    ) {
        wp_send_json_error(['message' => 'Solicitud no válida. Error de seguridad o datos faltantes.']);
        return;
    }

    // Sector contra whitelist
    $sector = sanitize_key($_POST['sector']);
    if (!crm_is_valid_sector($sector)) {
        wp_send_json_error(['message' => 'Sector no válido.']);
        return;
    }

    // Subida hardened (valida MIME real, extensión, tamaño, usa wp_handle_upload)
    $result = crm_handle_secure_upload($_FILES['file'], $tipo);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
        return;
    }

    // Log
    $client_id    = isset($_POST['client_id']) ? intval($_POST['client_id']) : null;
    $current_user = wp_get_current_user();
    $size_kb      = isset($_FILES['file']['size']) ? round((int) $_FILES['file']['size'] / 1024, 2) : 0;
    $log_details  = sprintf(
        'Archivo subido: %s | Sector: %s | Tipo: %s | Tamaño: %s KB',
        $result['name'],
        $sector,
        $tipo,
        $size_kb
    );
    crm_log_action('archivo_subido', $log_details, $client_id, $current_user->ID);

    wp_send_json_success([
        'sector' => $sector,
        'url'    => esc_url_raw($result['url']),
        'name'   => $result['name'],
    ]);
}

/**
 * Función genérica para eliminar archivos via AJAX.
 *
 * @param string $tipo Tipo de archivo a eliminar (factura, presupuesto, contrato_firmado).
 */
function crm_eliminar_archivo_generico($tipo)
{
    crm_debug_log('Eliminar archivo solicitado: tipo=' . $tipo);

    // Permisos
    if (!current_user_can('crm_admin') && !current_user_can('comercial')) {
        wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción.']);
        return;
    }

    if (!isset($_POST['url'], $_POST['nonce'])) {
        wp_send_json_error(['message' => 'URL o nonce faltantes en la solicitud.']);
        return;
    }

    if (!wp_verify_nonce($_POST['nonce'], 'crm_alta_cliente_nonce')) {
        wp_send_json_error(['message' => 'Nonce no válido.']);
        return;
    }

    $file_url = esc_url_raw(wp_unslash($_POST['url']));
    if (!crm_is_uploads_url($file_url)) {
        wp_send_json_error(['message' => 'URL de archivo no válida.']);
        return;
    }

    if (crm_delete_uploaded_file_by_url($file_url)) {
        $client_id    = isset($_POST['client_id']) ? intval($_POST['client_id']) : null;
        $current_user = wp_get_current_user();
        $file_name    = sanitize_file_name(basename(parse_url($file_url, PHP_URL_PATH)));
        $log_details  = sprintf('Archivo eliminado: %s | Tipo: %s', $file_name, $tipo);
        crm_log_action('archivo_eliminado', $log_details, $client_id, $current_user->ID);

        wp_send_json_success(['message' => ucfirst($tipo) . ' eliminado correctamente.']);
        return;
    }

    wp_send_json_error(['message' => ucfirst($tipo) . ' no encontrado o no se pudo eliminar.']);
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
    if ($requested_user_id && current_user_can('crm_admin') && $requested_user_id !== $current_user_id) {
        // Admin viendo otro comercial
        $user_id = $requested_user_id;
    } else {
        // Comercial viendo sus propias altas
        $user_id = $current_user_id;
    }

    // Obtener información del comercial si es diferente del usuario actual
    $comercial_name = '';
    if ($user_id !== $current_user_id) {
        $comercial = get_userdata($user_id);
        if ($comercial) {
            $comercial_name = $comercial->display_name;
        }
    }

    // Obtener los datos de los clientes
    $clientes = $wpdb->get_results($wpdb->prepare("
        SELECT id, fecha, cliente_nombre, empresa, direccion, poblacion, email_cliente, estado, actualizado_en, facturas, presupuesto, 
    contratos_firmados, intereses, estado_por_sector, reenvios, fecha_envio_por_sector, usuario_envio_por_sector
        FROM $table_name
        WHERE user_id = %d
        ORDER BY actualizado_en DESC
    ", $user_id), ARRAY_A);

    if (empty($clientes)) {
        $empty_message = $comercial_name ? "No hay clientes registrados para {$comercial_name}." : "No hay clientes registrados.";
        return "<p>{$empty_message}</p>";
    }

    // Construir la tabla en HTML
    ob_start();
?>
    <div class="crm-table-container">
        <div class="crm-table-header">
            <?php if ($comercial_name): ?>
                <h3><img src="<?php echo get_site_icon_url(); ?>" alt="Logo" class="crm-logo-small"> Clientes de <?php echo esc_html($comercial_name); ?> - Energitel CRM</h3>
                <p class="table-subtitle">Visualizando clientes y seguimiento de estados de <?php echo esc_html($comercial_name); ?></p>
            <?php else: ?>
                <h3><img src="<?php echo get_site_icon_url(); ?>" alt="Logo" class="crm-logo-small"> Mis Clientes - Energitel CRM</h3>
                <p class="table-subtitle">Gestión de clientes y seguimiento de estados</p>
            <?php endif; ?>
        </div>
        
        <div class="table-responsive">
            <table id="crm-lista-altas" class="crm-table-material">
                <thead>
                    <tr>
                        <th class="th-id">#</th>
                        <th class="th-fecha">Fecha</th>
                        <th class="th-cliente">Cliente</th>
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
        user_id: <?php echo $user_id; ?>
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
                "dataSrc": function(json) {
                    console.log('DataTable - Respuesta AJAX recibida:', json);
                    if (json.success && json.data && json.data.data) {
                        console.log('DataTable - Datos encontrados:', json.data.data.length, 'registros');
                        return json.data.data;  // Acceder al array dentro de data.data
                    } else {
                        console.log('DataTable - No hay datos o error:', json);
                        return [];
                    }
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
                        let html = '<div class="cliente-info">';
                        html += '<strong>' + data + '</strong>';
                        if (row.empresa) {
                            html += '<br><span class="empresa-name" style="color: #666; font-size: 13px;">' + row.empresa + '</span>';
                        }
                        if (row.email_cliente) {
                            html += '<br><a href="mailto:' + row.email_cliente + '" class="email-link" style="color: #007cba; font-size: 12px;">' + row.email_cliente + '</a>';
                        }
                        html += '</div>';
                        return html;
                    },
                    "width": "250px"
                },
                { 
                    "data": "intereses",
                    "render": function(data, type, row) {
                        if (Array.isArray(data) && data.length > 0) {
                            let badges = '';
                            const colors = {
                                'energia': '#28a745',
                                'alarmas': '#ffc107', 
                                'telecomunicaciones': '#17a2b8',
                                'teleco': '#17a2b8',
                                'seguros': '#dc3545',
                                'renovables': '#6f42c1'
                            };
                            data.slice(0, 3).forEach(function(interes) {
                                const color = colors[interes.toLowerCase()] || '#6c757d';
                                badges += '<span class="badge-interes" style="background-color: ' + color + '; color: white; padding: 2px 6px; border-radius: 3px; margin: 1px; font-size: 11px; display: inline-block;">' + interes + '</span> ';
                            });
                            if (data.length > 3) badges += '<span class="badge-interes" style="background-color: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; margin: 1px; font-size: 11px;">+' + (data.length - 3) + '</span>';
                            return badges;
                        }
                        return '<em style="color: #999;">Sin intereses</em>';
                    },
                    "width": "150px"
                },
                { 
                    "data": "estado_por_sector",
                    "render": function(data, type, row) {
                        if (data && typeof data === 'object' && Object.keys(data).length > 0) {
                            let badges = '';
                            // Obtener datos de envío - pueden venir como objeto o string serializado
                            let fechasEnvio = {};
                            try {
                                if (row.fecha_envio_por_sector) {
                                    if (typeof row.fecha_envio_por_sector === 'object') {
                                        fechasEnvio = row.fecha_envio_por_sector;
                                    } else {
                                        // Fallback: si viene como string, intentar parsear
                                        fechasEnvio = {};
                                    }
                                }
                            } catch(e) {
                                console.warn('Error processing fecha_envio_por_sector:', e);
                                fechasEnvio = {};
                            }
                            
                            Object.entries(data).forEach(([sector, estado]) => {
                                const hasSentToAdmin = fechasEnvio[sector] ? ' sent-indicator' : '';
                                badges += '<span class="estado-badge ' + estado + hasSentToAdmin + '">' + 
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
                            if (data) {
                                const date = new Date(data);
                                const day = date.getDate().toString().padStart(2, '0');
                                const month = (date.getMonth() + 1).toString().padStart(2, '0');
                                const hours = date.getHours().toString().padStart(2, '0');
                                const minutes = date.getMinutes().toString().padStart(2, '0');
                                return day + '/' + month + ' ' + hours + ':' + minutes + 'h';
                            }
                        }
                        return data || '-';
                    },
                    "width": "100px"
                },
                { 
                    "data": "id",
                    "orderable": false,
                    "className": "text-center",
                    "render": function(data, type, row) {
                        const editUrl = window.location.origin + '/editar-cliente/?client_id=' + data;
                        return '<div class="action-buttons">' +
                               '<a href="' + editUrl + '" class="action-btn edit" title="Editar cliente" style="background: #007cba; color: white; padding: 4px 8px; border-radius: 3px; text-decoration: none; font-size: 12px;">✏️</a>' +
                               '</div>';
                    },
                    "width": "80px"
                }
            ],
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
            },
            "pageLength": 15,
            "responsive": true,
            "dom": '<"top"fl>rt<"bottom"ip><"clear">',
            "order": [[ 5, "desc" ]], // Ordenar por fecha actualizada (nueva posición)
            "columnDefs": [
                { "targets": [6], "orderable": false }  // Columna de acciones no ordenable
            ]
        });

        // Función para obtener etiquetas de estado
        function getEstadoLabel(estado) {
            const labels = {
                'borrador': 'Sin enviar',
                'enviado': 'Enviado',
                'presupuesto_generado': 'Presupuesto Generado',
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
          facturas, presupuesto, contratos_firmados, contratos_generados, intereses, estado_por_sector, reenvios, fecha_envio_por_sector, usuario_envio_por_sector
   FROM $table_name
   WHERE user_id = %d
   ORDER BY actualizado_en DESC
", $user_id), ARRAY_A);

    crm_debug_log('crm_obtener_altas: usuario ' . $user_id . ' → ' . count($clientes) . ' registros');
    if (empty($clientes)) {
        crm_debug_log('crm_obtener_altas: sin resultados para user_id ' . $user_id);
    }


    if (!empty($clientes)) {
        foreach ($clientes as &$cliente) {
            // Siempre array (deserialización segura, sin instanciar clases)
            $cliente['facturas']            = crm_safe_unserialize_array($cliente['facturas']);
            $cliente['presupuesto']         = crm_safe_unserialize_array($cliente['presupuesto']);
            $cliente['contratos_firmados']  = crm_safe_unserialize_array($cliente['contratos_firmados']);
            $cliente['contratos_generados'] = crm_safe_unserialize_array($cliente['contratos_generados']);
            $cliente['intereses']           = crm_safe_unserialize_array($cliente['intereses']);

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

            // Deserializar fecha_envio_por_sector
            $feps = $cliente['fecha_envio_por_sector'];
            if (is_array($feps)) {
                $cliente['fecha_envio_por_sector'] = $feps;
            } elseif (is_string($feps) && !empty($feps)) {
                $tmp = maybe_unserialize($feps);
                if (is_array($tmp)) {
                    $cliente['fecha_envio_por_sector'] = $tmp;
                } else {
                    $decoded = json_decode($feps, true);
                    $cliente['fecha_envio_por_sector'] = is_array($decoded) ? $decoded : [];
                }
            } else {
                $cliente['fecha_envio_por_sector'] = [];
            }

            // Deserializar usuario_envio_por_sector
            $ueps = $cliente['usuario_envio_por_sector'];
            if (is_array($ueps)) {
                $cliente['usuario_envio_por_sector'] = $ueps;
            } elseif (is_string($ueps) && !empty($ueps)) {
                $tmp = maybe_unserialize($ueps);
                if (is_array($tmp)) {
                    $cliente['usuario_envio_por_sector'] = $tmp;
                } else {
                    $decoded = json_decode($ueps, true);
                    $cliente['usuario_envio_por_sector'] = is_array($decoded) ? $decoded : [];
                }
            } else {
                $cliente['usuario_envio_por_sector'] = [];
            }
        }

        wp_send_json_success([
            'data' => $clientes,      // Para DataTables AJAX embebido
            'clientes' => $clientes,  // Para JavaScript externo
            'user_name' => $user_name
        ]);
    } else {
        wp_send_json_success([
            'data' => [],             // Para DataTables AJAX embebido
            'clientes' => [],         // Para JavaScript externo
            'user_name' => $user_name
        ]);
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



    // Obtener el estado actual del cliente
    $estado_actual = !empty($client_data['estado']) ? $client_data['estado'] : 'borrador';
    
    // Reutilizamos el formulario de alta cliente con los datos del cliente cargados
    ob_start();
?>
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
    <div class="crm-table-container">
        <div class="crm-table-header">
            <h3><img src="<?php echo get_site_icon_url(); ?>" alt="Logo" class="crm-logo-small"> Todas las Altas - Energitel CRM</h3>
            <p class="table-subtitle">Gestión completa del equipo comercial</p>
        </div>
        
        <div class="table-responsive">
            <table id="crm-todas-las-altas" class="crm-table-material">
                <thead>
                    <tr>
                        <th class="th-id">#</th>
                        <th class="th-fecha">Fecha</th>
                        <th class="th-cliente">Cliente</th>
                        <th class="th-comercial">Comercial</th>
                        <th class="th-estado">Estado</th>
                        <th class="th-docs">Documentos</th>
                        <th class="th-updated">Última Edición</th>
                        <th class="th-acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Los datos serán añadidos dinámicamente por AJAX -->
                </tbody>
            </table>
        </div>
    </div>

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

    if (!check_ajax_referer('crm_obtener_clientes_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Error de seguridad.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "crm_clients";

    $clientes = $wpdb->get_results("
        SELECT c.id, c.fecha, c.user_id, c.cliente_nombre, c.empresa, c.direccion, c.poblacion, c.intereses, c.email_cliente, c.facturas, c.presupuesto, c.contratos_generados, c.contratos_firmados, c.estado, c.estado_por_sector, c.reenvios, c.actualizado_en, c.actualizado_por, c.fecha_envio_por_sector, c.usuario_envio_por_sector, c.origen_lead, c.es_cliente_activo, u.display_name AS comercial, u2.display_name AS actualizado_por_nombre
        FROM $table_name c
        LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
        LEFT JOIN {$wpdb->users} u2 ON c.actualizado_por = u2.ID
        ORDER BY c.actualizado_en DESC
    ", ARRAY_A);

    if (! empty($wpdb->last_error)) {
        error_log('CRM – MySQL error en crm_obtener_todas_altas: ' . $wpdb->last_error);
    }

    if (!empty($clientes)) {
        foreach ($clientes as &$cliente) {
            // Deserialización segura (bloquea PHP Object Injection)
            $cliente['facturas']            = crm_safe_unserialize_array($cliente['facturas']);
            $cliente['presupuesto']         = crm_safe_unserialize_array($cliente['presupuesto']);
            $cliente['contratos_firmados']  = crm_safe_unserialize_array($cliente['contratos_firmados']);
            $cliente['contratos_generados'] = crm_safe_unserialize_array($cliente['contratos_generados']);
            $cliente['intereses']           = crm_safe_unserialize_array($cliente['intereses']);

            // Estado por sector: puede ser array, JSON, string, o vacío
            $cliente['estado_por_sector']           = crm_safe_unserialize_array($cliente['estado_por_sector'] ?? '');
            $cliente['fecha_envio_por_sector']      = crm_safe_unserialize_array($cliente['fecha_envio_por_sector'] ?? '');
            $cliente['usuario_envio_por_sector']    = crm_safe_unserialize_array($cliente['usuario_envio_por_sector'] ?? '');
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

    // Obtener datos del cliente antes de eliminarlo (para purgar archivos y log)
    $client_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A);
    if (!$client_data) {
        wp_send_json_error(['message' => 'Cliente no encontrado.']);
        return;
    }

    // Purgar archivos asociados antes de borrar el registro
    $bundles = [
        crm_safe_unserialize_array($client_data['facturas']           ?? []),
        crm_safe_unserialize_array($client_data['presupuesto']        ?? []),
        crm_safe_unserialize_array($client_data['contratos_firmados'] ?? []),
    ];
    foreach ($bundles as $bundle) {
        foreach ($bundle as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $url) {
                if (is_string($url) && crm_is_uploads_url($url)) {
                    crm_delete_uploaded_file_by_url($url);
                }
            }
        }
    }

    $deleted = $wpdb->delete($table_name, ['id' => $client_id], ['%d']);

    if ($deleted) {
        $current_user = wp_get_current_user();
        $log_details  = sprintf(
            'Cliente eliminado: %s | Email: %s | Teléfono: %s | Estado: %s',
            $client_data['cliente_nombre'] ?? '',
            $client_data['email_cliente']  ?? '',
            $client_data['telefono']       ?? '',
            $client_data['estado']         ?? ''
        );
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
        <title>" . esc_html($args['titulo']) . "</title>
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
                <h1 style='margin: 0; font-size: 24px;'>" . esc_html($args['titulo']) . "</h1>
                <p style='margin: 10px 0 0 0; opacity: 0.9;'>Energitel CRM - " . esc_html($site_name) . "</p>
            </div>
            
            <div class='content'>
                {$args['contenido']}
            </div>
            
            <div class='footer'>
                <p>" . esc_html($args['pie']) . "</p>
                <p>© " . esc_html(date('Y')) . " <a href='" . esc_url($site_url) . "'>" . esc_html($site_name) . "</a> - Energitel CRM</p>
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
        crm_debug_log('Email: comercial sin email válido (id=' . (int) $client_id . ').');
        return false;
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    
    $cliente = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $client_id), ARRAY_A);
    if (!$cliente) {
        crm_debug_log('Email: cliente no encontrado al notificar (id=' . (int) $client_id . ').');
        return false;
    }
    
    $admin_user = wp_get_current_user();
    $cliente_url = home_url("/editar-cliente/?client_id={$client_id}");
    $tabla_url = home_url("/mis-altas-de-cliente/");

    $cliente_nombre  = esc_html($cliente['cliente_nombre'] ?? '');
    $cliente_empresa = esc_html($cliente['empresa'] ?? '');
    $cliente_email_h = esc_html($cliente['email_cliente'] ?? '');
    $cliente_delegado = esc_html($cliente['delegado'] ?? '');
    $admin_display   = esc_html($admin_user->display_name);
    $estado_label    = esc_html(crm_get_estado_label($cliente['estado'] ?? ''));

    $subject = sprintf('🔔 Actualización de cliente: %s', $cliente['cliente_nombre'] ?? '');

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
            <p>Hola <strong>{$cliente_delegado}</strong>,</p>
            
            <p>El administrador <strong>{$admin_display}</strong> ha actualizado la ficha del cliente:</p>
            
            <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #333; margin-top: 0;'>📋 {$cliente_nombre}</h3>
                <p><strong>Empresa:</strong> {$cliente_empresa}</p>
                <p><strong>Email:</strong> {$cliente_email_h}</p>
                <p><strong>Estado actual:</strong> <span style='background: #007cba; color: white; padding: 3px 8px; border-radius: 4px;'>{$estado_label}</span></p>
            </div>
            
            {$cambios_html}
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . esc_url($cliente_url) . "' style='background: #007cba; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📝 Editar Cliente</a>
                <a href='" . esc_url($tabla_url) . "' style='background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📊 Mis Clientes</a>
            </div>
        ",
        'pie' => 'Esta notificación ha sido enviada automáticamente por Energitel CRM.'
    ]);
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . $admin_user->user_email
    ];
    
    $result = wp_mail($comercial_email, $subject, $message, $headers);
    
    if ($result) {
        crm_log_action('notificacion_comercial_enviada', "Notificación enviada a comercial: {$comercial_email} - Cliente: {$cliente['cliente_nombre']}", $client_id);
        crm_debug_log('Email: notificación enviada al comercial (cliente ' . (int) $client_id . ').');
    } else {
        crm_log_action('notificacion_comercial_error', "Error al enviar notificación a comercial: {$comercial_email} - Cliente: {$cliente['cliente_nombre']}", $client_id);
        error_log('CRM Email: error al enviar notificación al comercial (cliente ' . (int) $client_id . ').');
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
    
    // Obtener datos deserializados (deserialización segura)
    $facturas          = crm_safe_unserialize_array($cliente['facturas'] ?? []);
    $presupuestos      = crm_safe_unserialize_array($cliente['presupuesto'] ?? []);
    $estado_por_sector = crm_safe_unserialize_array($cliente['estado_por_sector'] ?? []);

    // Pre-escapar campos de cliente para evitar XSS en el cuerpo del email
    $cn = esc_html($cliente['cliente_nombre'] ?? '');
    $ce = esc_html($cliente['email_cliente']  ?? '');
    $cm = esc_html($cliente['empresa']        ?? '');
    $ct = esc_html($cliente['telefono']       ?? '');
    $cd = esc_html($cliente['direccion']      ?? '');
    $cp = esc_html($cliente['poblacion']      ?? '');
    $cti = esc_html($cliente['tipo']          ?? '');
    $cc = esc_html($cliente['comentarios']    ?? '');
    $coml = esc_html($comercial->display_name);
    $come = esc_html($comercial->user_email);

    $sectores_validos  = crm_sanitize_sectores_list($sectores_enviados);

    $subject = sprintf(
        'Energitel CRM - Nuevo envío de cliente: %s (%s)',
        $cliente['cliente_nombre'] ?? '',
        implode(', ', $sectores_validos)
    );

    $colores = crm_get_colores_sectores();

    // Construir información de sectores enviados
    $sectores_html = '';
    foreach ($sectores_validos as $sector) {
        $estado_sector       = $estado_por_sector[$sector] ?? 'borrador';
        $facturas_sector     = is_array($facturas[$sector] ?? null) ? $facturas[$sector] : [];
        $presupuestos_sector = is_array($presupuestos[$sector] ?? null) ? $presupuestos[$sector] : [];
        $color               = esc_attr($colores[$sector] ?? '#999');
        $sector_label        = esc_html(ucfirst($sector));
        $estado_label        = esc_html(crm_get_estado_label($estado_sector));

        $sectores_html .= "
            <div style='background: #f8f9fa; border-left: 4px solid {$color}; padding: 15px; margin: 10px 0;'>
                <h4 style='color: {$color}; margin-top: 0;'>📁 {$sector_label}</h4>
                <p><strong>Estado:</strong> <span style='background: {$color}; color: white; padding: 2px 8px; border-radius: 4px;'>{$estado_label}</span></p>
        ";

        if (!empty($facturas_sector)) {
            $sectores_html .= '<p><strong>📄 Facturas (' . count($facturas_sector) . '):</strong></p><ul>';
            foreach ($facturas_sector as $factura) {
                if (!is_string($factura) || !crm_is_uploads_url($factura)) {
                    continue;
                }
                $nombre_archivo = esc_html(basename(parse_url($factura, PHP_URL_PATH)));
                $sectores_html .= "<li><a href='" . esc_url($factura) . "' style='color: #007cba;'>{$nombre_archivo}</a></li>";
            }
            $sectores_html .= '</ul>';
        }

        if (!empty($presupuestos_sector)) {
            $sectores_html .= '<p><strong>💰 Presupuestos (' . count($presupuestos_sector) . '):</strong></p><ul>';
            foreach ($presupuestos_sector as $presupuesto) {
                if (!is_string($presupuesto) || !crm_is_uploads_url($presupuesto)) {
                    continue;
                }
                $nombre_archivo = esc_html(basename(parse_url($presupuesto, PHP_URL_PATH)));
                $sectores_html .= "<li><a href='" . esc_url($presupuesto) . "' style='color: #007cba;'>{$nombre_archivo}</a></li>";
            }
            $sectores_html .= '</ul>';
        }

        $sectores_html .= '</div>';
    }

    $message = crm_get_email_template([
        'titulo' => 'Nuevo Envío de Cliente',
        'contenido' => "
            <p>Estimado equipo administrativo,</p>
            
            <p>El comercial <strong>{$coml}</strong> ha enviado un cliente para revisión:</p>
            
            <div style='background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: #1976d2; margin-top: 0;'>👤 {$cn}</h3>
                <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>
                    <div>
                        <p><strong>📧 Email:</strong> {$ce}</p>
                        <p><strong>🏢 Empresa:</strong> {$cm}</p>
                        <p><strong>📞 Teléfono:</strong> {$ct}</p>
                    </div>
                    <div>
                        <p><strong>📍 Dirección:</strong> {$cd}</p>
                        <p><strong>🏙️ Población:</strong> {$cp}</p>
                        <p><strong>🏷️ Tipo:</strong> {$cti}</p>
                    </div>
                </div>
                <p><strong>💬 Comentarios:</strong> {$cc}</p>
            </div>
            
            <h3 style='color: #333;'>📋 Sectores enviados:</h3>
            {$sectores_html}
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . esc_url($cliente_url) . "' style='background: #1976d2; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📝 Revisar Cliente</a>
                <a href='" . esc_url($tabla_admin_url) . "' style='background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 0 10px;'>📊 Todas las Altas</a>
            </div>
        ",
        'pie' => "Enviado por: {$coml} ({$come})"
    ]);
    
    // Obtener emails de administradores CRM
    $admin_users = get_users(['role' => 'crm_admin']);
    if (empty($admin_users)) {
        crm_debug_log('Email: no hay usuarios con rol crm_admin.');
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
            crm_log_action('notificacion_admin_enviada', "Notificación enviada a admin: {$email} - Cliente: {$cliente['cliente_nombre']} - Sectores: " . implode(', ', $sectores_enviados), $client_id);
        } else {
            $success = false;
            crm_log_action('notificacion_admin_error', "Error al enviar notificación a admin: {$email} - Cliente: {$cliente['cliente_nombre']}", $client_id);
            error_log('CRM Email: error al enviar notificación a admin (cliente ' . (int) $client_id . ').');
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
        return;
    }

    if (!check_ajax_referer('crm_obtener_clientes_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Error de seguridad']);
        return;
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
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
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

// El sistema de logs (crm_log_action, crm_create_monthly_log_table,
// crm_get_available_log_months) vive ahora en includes/logger.php.

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
/**
 * Crear tabla de clientes si no existe
 */
function crm_create_clients_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'crm_clients';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        delegado varchar(255) DEFAULT '',
        user_id bigint(20) UNSIGNED DEFAULT NULL,
        email_comercial varchar(255) DEFAULT '',
        fecha datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cliente_nombre varchar(255) NOT NULL,
        empresa varchar(255) NOT NULL,
        direccion varchar(255) DEFAULT NULL,
        telefono varchar(15) DEFAULT NULL,
        email_cliente varchar(255) DEFAULT NULL,
        poblacion varchar(100) DEFAULT '',
        provincia varchar(100) DEFAULT 'León',
        area varchar(10) DEFAULT NULL,
        tipo varchar(50) DEFAULT '',
        comentarios text,
        intereses longtext,
        facturas longtext,
        presupuesto longtext,
        contratos longtext,
        contratos_generados longtext,
        contratos_firmados longtext,
        firmado enum('energia', 'alarmas', 'teleco', 'seguros', 'ninguno') DEFAULT 'ninguno',
        enviado_por bigint(20) UNSIGNED DEFAULT NULL,
        fecha_enviado datetime DEFAULT NULL,
        editado_por bigint(20) UNSIGNED DEFAULT NULL,
        creado_por bigint(20) UNSIGNED NOT NULL,
        creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en datetime DEFAULT NULL,
        estado enum('borrador', 'enviado', 'pendiente_revision', 'presupuesto_aceptado', 'contratos_generados', 'contratos_firmados') NOT NULL DEFAULT 'enviado',
        estado_por_sector longtext,
        fecha_envio_por_sector text NOT NULL,
        usuario_envio_por_sector text NOT NULL,
        presupuestos_aceptados text DEFAULT NULL,
        estimado_consumo longtext DEFAULT NULL,
        origen_lead varchar(32) NOT NULL DEFAULT 'directo',
        es_cliente_activo tinyint(1) NOT NULL DEFAULT 0,
        entrada_vigor_por_sector longtext DEFAULT NULL,
        decision_por_sector longtext DEFAULT NULL,
        lead_meta longtext DEFAULT NULL,
        reenvios int(11) DEFAULT 0,
        PRIMARY KEY (id),
        KEY cliente_nombre (cliente_nombre),
        KEY telefono (telefono),
        KEY email_comercial (email_comercial),
        KEY creado_por (creado_por),
        KEY estado (estado),
        KEY origen_lead (origen_lead),
        KEY es_cliente_activo (es_cliente_activo),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    error_log("CRM: Tabla $table_name creada");
}

/**
 * Actualizar estructura de tabla de clientes
 */
function crm_update_clients_table_structure() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'crm_clients';
    
    error_log("CRM: Iniciando migración de tabla $table_name - versión " . CRM_PLUGIN_VERSION);
    
    // Verificar si la tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    if (!$table_exists) {
        error_log("CRM: Tabla $table_name no existe, creando...");
        crm_create_clients_table();
        return;
    }
    
    // Verificar si la columna provincia existe
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'provincia'");
    
    if (empty($column_exists)) {
        // Agregar columna provincia
        $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN provincia VARCHAR(100) DEFAULT 'León' AFTER poblacion");
        error_log("CRM: Agregando columna 'provincia' - Resultado: " . ($result !== false ? 'OK' : 'ERROR: ' . $wpdb->last_error));
    }
    
    // Verificar y actualizar la columna tipo si es ENUM con valores antiguos
    $tipo_column = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'tipo'");
    
    if (!empty($tipo_column)) {
        $column_definition = $tipo_column[0]->Type;
        error_log("CRM: Definición actual de columna 'tipo': " . $column_definition);
        
        // Si la columna tipo es ENUM con valores A, B, C, cambiarla a VARCHAR
        if (strpos($column_definition, "enum('A','B','C')") !== false || 
            strpos($column_definition, "enum('A', 'B', 'C')") !== false) {
            
            error_log("CRM: Detectada columna tipo como ENUM con valores A,B,C - Iniciando migración");
            
            // Primero, actualizar los valores existentes
            $update_a = $wpdb->query("UPDATE $table_name SET tipo = 'Residencial' WHERE tipo = 'A'");
            $update_b = $wpdb->query("UPDATE $table_name SET tipo = 'Autónomo' WHERE tipo = 'B'");
            $update_c = $wpdb->query("UPDATE $table_name SET tipo = 'Empresa' WHERE tipo = 'C'");
            
            error_log("CRM: Valores actualizados - A→Residencial: $update_a, B→Autónomo: $update_b, C→Empresa: $update_c");
            
            // Cambiar la columna a VARCHAR
            $alter_result = $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN tipo VARCHAR(50) DEFAULT ''");
            error_log("CRM: Cambio de ENUM a VARCHAR - Resultado: " . ($alter_result !== false ? 'OK' : 'ERROR: ' . $wpdb->last_error));
            
            if ($alter_result !== false) {
                error_log("CRM: Migración de columna 'tipo' completada exitosamente");
            }
        } else {
            error_log("CRM: Columna 'tipo' ya está en el formato correcto: " . $column_definition);
        }
    } else {
        error_log("CRM: Columna 'tipo' no encontrada, agregando...");
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN tipo VARCHAR(50) DEFAULT ''");
    }
    
    // Verificar otras columnas que podrían faltar
    $required_columns = [
        'poblacion' => "VARCHAR(100) DEFAULT ''",
        'provincia' => "VARCHAR(100) DEFAULT 'León'",
        'comentarios' => "TEXT",
        'actualizado_por' => "BIGINT(20) DEFAULT NULL",
        'presupuestos_aceptados' => "TEXT DEFAULT NULL",
        'estimado_consumo' => "LONGTEXT DEFAULT NULL",
        'origen_lead' => "VARCHAR(32) NOT NULL DEFAULT 'directo'",
        'es_cliente_activo' => "TINYINT(1) NOT NULL DEFAULT 0",
        'entrada_vigor_por_sector' => "LONGTEXT DEFAULT NULL",
        'decision_por_sector' => "LONGTEXT DEFAULT NULL",
        'lead_meta' => "LONGTEXT DEFAULT NULL",
    ];
    
    foreach ($required_columns as $column => $definition) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
        
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
            error_log("CRM: Columna '$column' agregada a la tabla $table_name");
        }
    }

    // v1.18: permitir leads sin asignar (user_id / delegado / email_comercial pueden ser NULL/vacíos)
    $cols_to_relax = [
        'user_id'         => "BIGINT(20) UNSIGNED DEFAULT NULL",
        'delegado'        => "VARCHAR(255) DEFAULT ''",
        'email_comercial' => "VARCHAR(255) DEFAULT ''",
    ];
    foreach ($cols_to_relax as $col => $definition) {
        $col_info = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE '$col'");
        if ($col_info && stripos($col_info->Null, 'NO') === 0 && stripos($col_info->Default ?? '', 'NULL') === false) {
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN $col $definition");
            error_log("CRM: Columna '$col' relajada a permitir vacío/NULL");
        }
    }

    // v1.18: índices nuevos
    $existing_indexes = $wpdb->get_col("SHOW INDEX FROM $table_name", 2); // Key_name
    $needed_indexes = [
        'origen_lead'       => "ALTER TABLE $table_name ADD INDEX origen_lead (origen_lead)",
        'es_cliente_activo' => "ALTER TABLE $table_name ADD INDEX es_cliente_activo (es_cliente_activo)",
        'user_id'           => "ALTER TABLE $table_name ADD INDEX user_id (user_id)",
    ];
    foreach ($needed_indexes as $idx => $sql_idx) {
        if (!in_array($idx, $existing_indexes, true)) {
            $wpdb->query($sql_idx);
            error_log("CRM: Índice '$idx' creado en $table_name");
        }
    }

    error_log("CRM: Migración de tabla completada");
}

function crm_plugin_activation() {
    crm_install_roles();
    crm_create_activity_log_table();
    crm_migrate_to_monthly_logs();
    crm_create_clients_table();
    crm_update_clients_table_structure(); // Actualizar estructura de tabla de clientes
    if (function_exists('crm_notes_install_table')) {
        crm_notes_install_table();
    }
    if (function_exists('crm_visitas_install_table')) {
        crm_visitas_install_table();
    }
    crm_protect_backup_directory();
    if (function_exists('crm_logger_schedule_cron')) {
        crm_logger_schedule_cron();
    }
    update_option('crm_plugin_version', CRM_PLUGIN_VERSION, false);
    update_option('crm_roles_installed_version', CRM_PLUGIN_VERSION, false);
    update_option('crm_notes_installed_version', CRM_PLUGIN_VERSION, false);
    update_option('crm_visitas_installed_version', CRM_PLUGIN_VERSION, false);
}

/**
 * Hook de desactivación: revoca capacidades del rol administrator pero
 * conserva los roles `crm_admin` y `comercial` para no perder asignaciones.
 */
function crm_plugin_deactivation() {
    crm_remove_admin_caps_from_wp_admin();
    delete_option('crm_roles_installed_version');
    wp_clear_scheduled_hook('crm_daily_maintenance');
    if (function_exists('crm_logger_unschedule_cron')) {
        crm_logger_unschedule_cron();
    }
}
register_deactivation_hook(__FILE__, 'crm_plugin_deactivation');

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

/**
 * AJAX handler para obtener datos de monitoreo en tiempo real
 */
add_action('wp_ajax_crm_get_monitoring_data', 'crm_ajax_get_monitoring_data');
function crm_ajax_get_monitoring_data() {
    if (!current_user_can('crm_admin') || !wp_verify_nonce($_POST['nonce'], 'crm_monitoring')) {
        wp_die('Sin permisos');
    }
    
    global $wpdb;
    
    // Obtener usuarios online (últimos 5 minutos)
    $users_online = get_transient('crm_users_online');
    if ($users_online === false) {
        $users_online = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'session_tokens' 
            AND meta_value != ''
        ");
        set_transient('crm_users_online', $users_online, 300); // Cache por 5 minutos
    }
    
    // Obtener uso de memoria
    $memory_usage = '0%';
    if (function_exists('memory_get_usage')) {
        $memory_used = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit) {
            $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
            $percentage = round(($memory_used / $memory_limit_bytes) * 100, 1);
            $memory_usage = $percentage . '%';
        }
    }
    
    // Obtener tamaño de la base de datos CRM
    $db_size = get_transient('crm_db_size');
    if ($db_size === false) {
        $tables = ['crm_clients'];
        $total_size = 0;
        
        // Agregar tablas de logs mensuales
        $log_tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}crm_activity_log_%'", ARRAY_N);
        foreach ($log_tables as $table) {
            $tables[] = str_replace($wpdb->prefix, '', $table[0]);
        }
        
        foreach ($tables as $table) {
            $table_size = $wpdb->get_var("
                SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) 
                FROM information_schema.TABLES 
                WHERE table_schema = '" . DB_NAME . "' 
                AND table_name = '{$wpdb->prefix}{$table}'
            ");
            $total_size += (float) $table_size;
        }
        
        $db_size = round($total_size, 2) . ' MB';
        set_transient('crm_db_size', $db_size, 3600); // Cache por 1 hora
    }
    
    // Obtener última actividad
    $available_months = crm_get_available_log_months();
    $last_activity = '-';
    
    if (!empty($available_months)) {
        $latest_table = $available_months[0]['table'];
        $last_log = $wpdb->get_row("
            SELECT user_name, action_type, created_at 
            FROM $latest_table 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        if ($last_log) {
            $time_diff = human_time_diff(strtotime($last_log->created_at), current_time('timestamp'));
            $last_activity = $last_log->user_name . ' - ' . $time_diff . ' ago';
        }
    }
    
    wp_send_json_success([
        'users_online' => $users_online,
        'memory_usage' => $memory_usage,
        'db_size' => $db_size,
        'last_activity' => $last_activity
    ]);
}

register_activation_hook(__FILE__, 'crm_plugin_activation');

// También ejecutar al cargar el plugin para actualizaciones
add_action('plugins_loaded', function() {
    $current_version = get_option('crm_plugin_version', '0.0.0');
    if (version_compare($current_version, CRM_PLUGIN_VERSION, '<')) {
        crm_update_clients_table_structure();
        if (function_exists('crm_visitas_install_table')) {
            crm_visitas_install_table();
        }
        update_option('crm_plugin_version', CRM_PLUGIN_VERSION);
    }
});

/**
 * AJAX handler para quitar un interés/sector de un cliente
 */
add_action('wp_ajax_crm_quitar_interes', 'crm_ajax_quitar_interes');
function crm_ajax_quitar_interes() {
    // Verificar permisos
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Debes iniciar sesión para realizar esta acción.']);
    }
    
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'crm_alta_cliente_nonce')) {
        wp_send_json_error(['message' => 'Error de seguridad.']);
    }
    
    $client_id = intval($_POST['client_id'] ?? 0);
    $sector = sanitize_text_field($_POST['sector'] ?? '');
    
    if (!$client_id || !$sector) {
        wp_send_json_error(['message' => 'Datos incompletos.']);
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'crm_clients';
    
    // Obtener el cliente
    $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $client_id), ARRAY_A);
    
    if (!$client) {
        wp_send_json_error(['message' => 'Cliente no encontrado.']);
    }
    
    // Verificar permisos de edición
    if (!current_user_can('crm_admin') && intval($client['user_id']) !== get_current_user_id()) {
        wp_send_json_error(['message' => 'No tienes permisos para editar este cliente.']);
    }
    
    // Obtener intereses actuales
    $intereses_actuales = maybe_unserialize($client['intereses'] ?? []);
    if (!is_array($intereses_actuales)) {
        $intereses_actuales = [];
    }
    
    // Remover el sector de la lista de intereses
    $intereses_actuales = array_diff($intereses_actuales, [$sector]);
    
    // Obtener datos de archivos
    $facturas = maybe_unserialize($client['facturas'] ?? []);
    $presupuestos = maybe_unserialize($client['presupuesto'] ?? []);
    $contratos_firmados = maybe_unserialize($client['contratos_firmados'] ?? []);
    
    if (!is_array($facturas)) $facturas = [];
    if (!is_array($presupuestos)) $presupuestos = [];
    if (!is_array($contratos_firmados)) $contratos_firmados = [];
    
    // Eliminar archivos del sector
    $archivos_eliminados = 0;
    
    // Eliminar facturas del sector
    if (isset($facturas[$sector])) {
        foreach ($facturas[$sector] as $file_url) {
            if (crm_delete_file_from_url($file_url)) {
                $archivos_eliminados++;
            }
        }
        unset($facturas[$sector]);
    }
    
    // Eliminar presupuestos del sector
    if (isset($presupuestos[$sector])) {
        foreach ($presupuestos[$sector] as $file_url) {
            if (crm_delete_file_from_url($file_url)) {
                $archivos_eliminados++;
            }
        }
        unset($presupuestos[$sector]);
    }
    
    // Eliminar contratos firmados del sector
    if (isset($contratos_firmados[$sector])) {
        foreach ($contratos_firmados[$sector] as $file_url) {
            if (crm_delete_file_from_url($file_url)) {
                $archivos_eliminados++;
            }
        }
        unset($contratos_firmados[$sector]);
    }
    
    // Obtener estado por sector y limpiarlo
    $estado_por_sector = maybe_unserialize($client['estado_por_sector'] ?? []);
    if (!is_array($estado_por_sector)) {
        $estado_por_sector = [];
    }
    
    // Remover el estado del sector
    if (isset($estado_por_sector[$sector])) {
        unset($estado_por_sector[$sector]);
    }
    
    // Actualizar la base de datos
    $updated = $wpdb->update(
        $table_name,
        [
            'intereses' => serialize($intereses_actuales),
            'facturas' => serialize($facturas),
            'presupuesto' => serialize($presupuestos),
            'contratos_firmados' => serialize($contratos_firmados),
            'estado_por_sector' => serialize($estado_por_sector),
            'actualizado_en' => current_time('mysql')
        ],
        ['id' => $client_id]
    );
    
    if ($updated === false) {
        wp_send_json_error(['message' => 'Error al actualizar la base de datos.']);
    }
    
    // Registrar la acción en el log
    $current_user = wp_get_current_user();
    $log_details = "Interés eliminado: {$sector} | Cliente: {$client['cliente_nombre']} | Archivos eliminados: {$archivos_eliminados}";
    crm_log_action('interes_eliminado', $log_details, $client_id, $current_user->ID);
    
    wp_send_json_success([
        'message' => "Interés '{$sector}' eliminado correctamente" . ($archivos_eliminados > 0 ? " ({$archivos_eliminados} archivos eliminados)" : "")
    ]);
}

/**
 * Función auxiliar para eliminar un archivo desde su URL.
 * Solo borra archivos que estén dentro del directorio de uploads del sitio.
 */
function crm_delete_file_from_url($file_url) {
    if (empty($file_url) || !is_string($file_url)) {
        return false;
    }
    return crm_delete_uploaded_file_by_url($file_url);
}

// JavaScript para hacer dinámico el texto de los checkboxes de admin
add_action('wp_footer', 'crm_admin_checkbox_dynamic_text');
function crm_admin_checkbox_dynamic_text() {
    if (!current_user_can('crm_admin')) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Manejar checkboxes de admin para presupuesto aceptado
        const adminCheckboxes = document.querySelectorAll('input[name^="admin_presupuesto_aceptado"]');
        
        adminCheckboxes.forEach(function(checkbox) {
            if (checkbox.disabled) return; // Skip disabled checkboxes
            
            const span = checkbox.nextElementSibling;
            if (!span || span.tagName !== 'SPAN') return;
            
            // Guardar textos originales
            const textChecked = '✓ Presupuesto aceptado por cliente';
            const textUnchecked = 'Sin aceptación de presupuesto';
            const colorChecked = '#10b981';
            const colorUnchecked = '#6b7280';
            
            // Función para actualizar el texto y color
            function updateText() {
                if (checkbox.checked) {
                    span.textContent = textChecked;
                    span.style.color = colorChecked;
                } else {
                    span.textContent = textUnchecked;
                    span.style.color = colorUnchecked;
                }
            }
            
            // Escuchar cambios en el checkbox
            checkbox.addEventListener('change', updateText);
            
            // Inicializar el estado correcto
            updateText();
        });
    });
    </script>
    <?php
}