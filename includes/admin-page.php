<?php
/**
 * Página de administración del CRM (wp-admin).
 *
 * - Menú principal "CRM" con submenús:
 *     · Dashboard      (resumen + accesos rápidos)
 *     · Logs           (visor con filtros, paginación y exportación CSV)
 *     · Actualizaciones (versión instalada, último check, botón "Buscar ahora")
 *     · Ajustes        (retención de logs, páginas de login)
 *
 * Capacidad requerida: `crm_admin`.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Registro de menús
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'crm_register_admin_menu');
function crm_register_admin_menu() {
    $cap = 'crm_admin';

    add_menu_page(
        'CRM Energitel',
        'CRM',
        $cap,
        'crm-dashboard',
        'crm_admin_render_dashboard',
        'dashicons-businessperson',
        26
    );

    add_submenu_page('crm-dashboard', 'Dashboard', 'Dashboard', $cap, 'crm-dashboard', 'crm_admin_render_dashboard');
    add_submenu_page('crm-dashboard', 'Clientes', 'Clientes', $cap, 'crm-clientes', 'crm_admin_render_clientes');
    add_submenu_page('crm-dashboard', 'Logs', 'Logs', $cap, 'crm-logs', 'crm_admin_render_logs');
    add_submenu_page('crm-dashboard', 'Actualizaciones', 'Actualizaciones', $cap, 'crm-updates', 'crm_admin_render_updates');
    add_submenu_page('crm-dashboard', 'Leads MK', 'Leads MK', $cap, 'crm-leads-mk', 'crm_admin_render_leads_mk');
    add_submenu_page('crm-dashboard', 'Flujos', 'Flujos', $cap, 'crm-flujos', 'crm_flujos_render_admin');
    add_submenu_page('crm-dashboard', 'Ajustes', 'Ajustes', $cap, 'crm-settings', 'crm_admin_render_settings');
}

/**
 * Registra los ajustes (Settings API).
 */
add_action('admin_init', 'crm_register_admin_settings');
function crm_register_admin_settings() {
    register_setting('crm_settings', 'crm_logs_retention_days', [
        'type'              => 'integer',
        'default'           => 90,
        'sanitize_callback' => function ($v) { return max(7, (int) $v); },
    ]);
    register_setting('crm_settings', 'crm_logs_retention_months', [
        'type'              => 'integer',
        'default'           => 6,
        'sanitize_callback' => function ($v) { return max(1, (int) $v); },
    ]);
    register_setting('crm_settings', 'crm_login_page_id', [
        'type'              => 'integer',
        'default'           => 0,
        'sanitize_callback' => function ($v) { return max(0, (int) $v); },
    ]);
    register_setting('crm_settings', 'crm_post_login_page_id', [
        'type'              => 'integer',
        'default'           => 30,
        'sanitize_callback' => function ($v) { return max(0, (int) $v); },
    ]);
    // v1.20.26 — Clave de API de Holded. Deja el campo en blanco al guardar
    // para conservar la clave actual (nunca se imprime en el HTML del formulario).
    register_setting('crm_settings', 'crm_holded_api_key', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => function ($v) {
            $v = trim((string) $v);
            return $v === '' ? get_option('crm_holded_api_key', '') : $v;
        },
    ]);
    // v1.20.32 — Email de contacto para el User-Agent de Nominatim (geocodificación).
    // No es una clave secreta, así que sí se guarda y se muestra tal cual.
    register_setting('crm_settings', 'crm_nominatim_contact_email', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_email',
    ]);
    // v1.20.37 — Fase 3: email del proveedor al que se notifica material pendiente.
    register_setting('crm_settings', 'crm_proveedor_email', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_email',
    ]);
    // v1.20.92: contacto de Holded del proveedor — para poder crear un pedido
    // de compra real en Holded desde el mismo botón de "Notificar proveedor".
    register_setting('crm_settings', 'crm_proveedor_holded_contact_id', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('crm_settings', 'crm_proveedor_holded_contact_nombre', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    // v1.20.57 — contra qué almacén de Holded se comprueba el stock por línea.
    register_setting('crm_settings', 'crm_holded_warehouse_id', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    // v1.20.95 — sincronización periódica de clientes desde Holded.
    register_setting('crm_settings', 'crm_holded_sync_clientes_enabled', [
        'type'              => 'boolean',
        'default'           => false,
        'sanitize_callback' => function ($v) { return !empty($v); },
    ]);
    // v1.20.99 — la API de Holded no expone ningún endpoint para resolver el
    // ID de un comercial a su nombre (verificado: no hay /users ni /team),
    // así que se mantiene aquí a mano: una línea "id=Nombre" por comercial.
    register_setting('crm_settings', 'crm_holded_usuarios_mapa', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => function ($v) { return sanitize_textarea_field((string) $v); },
    ]);
    // v1.20.41 — Fase 4: branding del panel del instalador (hoy Ecovolt, pensado
    // para poder cambiar de marca el día que haya más de un cliente del CRM).
    register_setting('crm_settings', 'crm_instalador_panel_brand', [
        'type'              => 'string',
        'default'           => 'Ecovolt',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('crm_settings', 'crm_instalador_panel_color', [
        'type'              => 'string',
        'default'           => '#15803d',
        'sanitize_callback' => function ($v) { return sanitize_hex_color((string) $v) ?: '#15803d'; },
    ]);
    register_setting('crm_settings', 'crm_instalador_panel_logo_url', [
        'type'              => 'string',
        'default'           => CRM_PLUGIN_URL . 'img/ecovolt-logo.jpg',
        'sanitize_callback' => 'esc_url_raw',
    ]);
    // v1.20.48 — Fase 5: importe máximo que un instalador puede declarar en una
    // sola partida extra sin más trámite que la validación del jefe. Por encima
    // de esto tiene que hablarlo por otro canal (no está pensado como límite
    // duro de negocio, solo como aviso/freno ante errores de tecleo).
    register_setting('crm_settings', 'crm_inst_extra_max', [
        'type'              => 'number',
        'default'           => 500,
        'sanitize_callback' => function ($v) {
            $n = (float) str_replace(',', '.', (string) $v);
            return $n > 0 ? $n : 500;
        },
    ]);
    // v1.20.51 — Fase 4 Entrega 2: si el cierre que declara el instalador
    // (conformidad+observaciones+fotos) necesita validación del jefe antes de
    // pasar la instalación a "Finalizada", o si es definitivo al momento.
    register_setting('crm_settings', 'crm_inst_cierre_requiere_aprobacion', [
        'type'              => 'boolean',
        'default'           => false,
        'sanitize_callback' => function ($v) { return !empty($v); },
    ]);
    // v1.20.62 — aviso proactivo si quedan materiales sin recibir a pocos días
    // de la visita agendada (antes era puramente "pull": solo se veía si
    // alguien entraba a mirar el listado/ficha).
    register_setting('crm_settings', 'crm_inst_aviso_dias_antes', [
        'type'              => 'integer',
        'default'           => 3,
        'sanitize_callback' => function ($v) {
            $n = (int) $v;
            return $n >= 0 ? $n : 3;
        },
    ]);
    register_setting('crm_settings', 'crm_inst_aviso_hora', [
        'type'              => 'integer',
        'default'           => 9,
        'sanitize_callback' => function ($v) {
            $n = (int) $v;
            return ( $n >= 0 && $n <= 23 ) ? $n : 9;
        },
    ]);
    register_setting('crm_settings', 'crm_inst_aviso_canal_inapp', [
        'type'              => 'boolean',
        'default'           => true,
        'sanitize_callback' => function ($v) { return !empty($v); },
    ]);
    // v1.20.87 — Andamiaje de WhatsApp Business (Meta Cloud API). Sin
    // credenciales reales todavía; mismo patrón que crm_holded_api_key: el
    // token nunca se imprime de vuelta, dejar en blanco al guardar lo conserva.
    register_setting('crm_settings', 'crm_whatsapp_api_token', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => function ($v) {
            $v = trim((string) $v);
            return $v === '' ? get_option('crm_whatsapp_api_token', '') : $v;
        },
    ]);
    register_setting('crm_settings', 'crm_whatsapp_phone_number_id', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('crm_settings', 'crm_whatsapp_template_aviso_materiales', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('crm_settings', 'crm_whatsapp_template_validar_extra', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    // v1.20.115 — aviso de "cierre parcial" (instalación en_ejecucion) a jefes.
    register_setting('crm_settings', 'crm_whatsapp_template_en_ejecucion', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('crm_settings', 'crm_inst_aviso_canal_whatsapp', [
        'type'              => 'boolean',
        'default'           => false,
        'sanitize_callback' => function ($v) { return !empty($v); },
    ]);
    register_setting('crm_settings', 'crm_inst_aviso_canal_email', [
        'type'              => 'boolean',
        'default'           => true,
        'sanitize_callback' => function ($v) { return !empty($v); },
    ]);
    // v1.20.127 — Fase 7 · aviso de calendario (recordatorio día antes de visita).
    register_setting('crm_settings', 'crm_inst_aviso_calendario_hora', [
        'type'              => 'integer',
        'default'           => 9,
        'sanitize_callback' => function ($v) {
            $n = (int) $v;
            return ( $n >= 0 && $n <= 23 ) ? $n : 9;
        },
    ]);
    // v1.20.130 — avisar también al cliente por email en el momento de
    // agendar/reprogramar (antes solo se enteraba con el recordatorio del
    // día antes, v1.20.127).
    register_setting('crm_settings', 'crm_inst_aviso_cliente_visita_email', [
        'type'              => 'boolean',
        'default'           => false,
        'sanitize_callback' => function ($v) { return !empty($v); },
    ]);
    register_setting('crm_settings', 'crm_whatsapp_template_recordatorio_visita', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    // v1.20.127 — Fase 7 · presupuesto estancado.
    register_setting('crm_settings', 'crm_ventas_aviso_estancados_dias', [
        'type'              => 'integer',
        'default'           => 7,
        'sanitize_callback' => function ($v) {
            $n = (int) $v;
            return $n >= 1 ? $n : 7;
        },
    ]);
    // v1.20.129 — Fase 7 · WhatsApp a instalador.
    foreach ([
        'crm_whatsapp_template_instalador_asignado',
        'crm_whatsapp_template_instalador_visita',
        'crm_whatsapp_template_instalador_extra_resuelta',
        'crm_whatsapp_template_instalador_cierre_resuelto',
    ] as $opcion_plantilla_instalador) {
        register_setting('crm_settings', $opcion_plantilla_instalador, [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }
    // v1.20.130 — Fase 8: confirmación de cita por WhatsApp + webhook de entrada.
    register_setting('crm_settings', 'crm_whatsapp_template_confirmacion_visita_cliente', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('crm_settings', 'crm_whatsapp_webhook_verify_token', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    // Mismo patrón que el token de acceso: nunca se imprime de vuelta, un
    // valor vacío al guardar conserva el que ya hubiera.
    register_setting('crm_settings', 'crm_whatsapp_app_secret', [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => function ($v) {
            $v = trim((string) $v);
            return $v === '' ? get_option('crm_whatsapp_app_secret', '') : $v;
        },
    ]);
}

/* ---------------------------------------------------------------------------
 * Helpers de UI
 * ------------------------------------------------------------------------- */

function crm_admin_page_header($title) {
    echo '<div class="wrap crm-admin-wrap">';
    echo '<h1>' . esc_html($title) . '</h1>';
    crm_admin_render_nav();
}

function crm_admin_page_footer() {
    echo '</div>';
}

function crm_admin_render_nav() {
    $current = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
    $tabs = [
        'crm-dashboard' => 'Dashboard',
        'crm-clientes'  => 'Clientes',
        'crm-logs'      => 'Logs',
        'crm-updates'   => 'Actualizaciones',
        'crm-leads-mk'  => 'Leads MK',
        'crm-flujos'    => 'Flujos',
        'crm-notificaciones' => 'Notificaciones',
        'crm-email'     => 'Email',
        'crm-settings'  => 'Ajustes',
    ];
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $active = $current === $slug ? ' nav-tab-active' : '';
        $url    = esc_url(admin_url('admin.php?page=' . $slug));
        echo '<a class="nav-tab' . $active . '" href="' . $url . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
}

/* ---------------------------------------------------------------------------
 * Dashboard
 * ------------------------------------------------------------------------- */

function crm_admin_render_dashboard() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    global $wpdb;

    $clients_table = $wpdb->prefix . 'crm_clients';
    $total_clients = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$clients_table`");
    $months        = crm_get_available_log_months();
    $current_month = current_time('Y_m');
    $level_counts  = crm_logs_count_by_level([$current_month]);

    // v1.20.67 — el módulo de instalaciones no salía en ningún dashboard
    // general, solo en su propia página. Tarjeta ligera, no un listado
    // duplicado — para eso ya está /instalaciones/.
    $total_instalaciones = 0;
    $atencion_total      = 0;
    if (function_exists('crm_inst_table_instalaciones')) {
        $total_instalaciones = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . crm_inst_table_instalaciones());
    }
    if (function_exists('crm_inst_get_atencion_counts')) {
        $atencion_total = array_sum(crm_inst_get_atencion_counts());
    }

    $version          = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '?';
    $last_update_at   = (int) get_option('crm_last_update_at', 0);
    $last_check       = (int) get_option('crm_last_update_check', 0);
    $retention_days   = (int) get_option('crm_logs_retention_days', 90);
    $retention_months = (int) get_option('crm_logs_retention_months', 6);

    crm_admin_page_header('CRM · Dashboard');
    ?>
    <div class="crm-grid">
        <div class="crm-card">
            <h2>Clientes</h2>
            <p class="crm-metric"><?php echo number_format_i18n($total_clients); ?></p>
            <p class="description">Total registrados en el CRM</p>
        </div>
        <div class="crm-card">
            <h2>Versión</h2>
            <p class="crm-metric"><?php echo esc_html($version); ?></p>
            <p class="description">
                <?php if ($last_update_at): ?>
                    Última actualización: <?php echo esc_html(date_i18n('d/m/Y H:i', $last_update_at)); ?>
                <?php else: ?>
                    Sin actualizaciones registradas
                <?php endif; ?>
            </p>
        </div>
        <div class="crm-card">
            <h2>Logs</h2>
            <p class="crm-metric"><?php echo count($months); ?> meses</p>
            <p class="description">
                Retención: <?php echo (int) $retention_days; ?> días · <?php echo (int) $retention_months; ?> meses
            </p>
        </div>
        <div class="crm-card">
            <h2>Errores este mes</h2>
            <p class="crm-metric">
                <?php echo (int) (($level_counts['error'] ?? 0) + ($level_counts['critical'] ?? 0)); ?>
            </p>
            <p class="description">
                Warnings: <?php echo (int) ($level_counts['warning'] ?? 0); ?> ·
                Info: <?php echo (int) ($level_counts['info'] ?? 0); ?>
            </p>
        </div>
        <div class="crm-card">
            <h2>Instalaciones</h2>
            <p class="crm-metric"><?php echo number_format_i18n($total_instalaciones); ?></p>
            <p class="description">
                <?php if ($atencion_total > 0) : ?>
                    <span style="color:#92400e;font-weight:600;"><?php echo (int) $atencion_total; ?> avisos activos</span>
                <?php else : ?>
                    Sin avisos pendientes
                <?php endif; ?>
                — <a href="<?php echo esc_url(home_url('/instalaciones/')); ?>">ver listado</a>
            </p>
        </div>
    </div>

    <h2>Accesos rápidos</h2>
    <p>
        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=crm-logs')); ?>">Ver logs</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-updates')); ?>">Comprobar actualizaciones</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-settings')); ?>">Ajustes</a>
    </p>

    <h2>Niveles del mes actual</h2>
    <table class="widefat striped" style="max-width:480px">
        <thead><tr><th>Nivel</th><th>Entradas</th></tr></thead>
        <tbody>
            <?php foreach (crm_log_levels() as $level): ?>
                <tr>
                    <td><span class="crm-level crm-level-<?php echo esc_attr($level); ?>"><?php echo esc_html(ucfirst($level)); ?></span></td>
                    <td><?php echo (int) ($level_counts[$level] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    // v1.20.0 — KPIs de estado_decision por sector
    if (function_exists('crm_get_decisiones_sector')) {
        $rows = $wpdb->get_results("SELECT decision_por_sector FROM `$clients_table` WHERE decision_por_sector IS NOT NULL AND decision_por_sector <> ''", ARRAY_A);
        $counts = [];
        foreach ($rows as $r) {
            $arr = maybe_unserialize($r['decision_por_sector']);
            if (!is_array($arr)) continue;
            foreach ($arr as $sector => $dec) {
                if ($dec === '' || $dec === null) continue;
                $counts[$dec] = ($counts[$dec] ?? 0) + 1;
            }
        }
        $defs = crm_get_decisiones_sector();
        unset($defs['']);
        ?>
        <h2 style="margin-top:24px;">Decisiones pendientes (v1.20)</h2>
        <p class="description">Sectores con bloqueo o pendiente declarado por el comercial / admin.</p>
        <table class="widefat striped" style="max-width:600px">
            <thead><tr><th>Tipo de decisión</th><th style="width:120px;">Sectores</th></tr></thead>
            <tbody>
                <?php foreach ($defs as $key => $cfg): ?>
                    <tr>
                        <td><?php echo esc_html($cfg['label']); ?></td>
                        <td><strong><?php echo (int) ($counts[$key] ?? 0); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // KPIs de visitas
        if (function_exists('crm_visitas_count')) {
            $vis_hoy   = crm_visitas_count(['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d')]);
            $vis_sem   = crm_visitas_count(['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d', strtotime('+7 days'))]);
            $vis_prog  = crm_visitas_count(['estado' => 'programada']);
            ?>
            <h2 style="margin-top:24px;">Visitas (v1.20)</h2>
            <table class="widefat striped" style="max-width:600px">
                <thead><tr><th>Rango</th><th style="width:120px;">Visitas</th></tr></thead>
                <tbody>
                    <tr><td>Hoy</td><td><strong><?php echo (int) $vis_hoy; ?></strong></td></tr>
                    <tr><td>Próximos 7 días</td><td><strong><?php echo (int) $vis_sem; ?></strong></td></tr>
                    <tr><td>Total programadas</td><td><strong><?php echo (int) $vis_prog; ?></strong></td></tr>
                </tbody>
            </table>
            <p style="margin-top:8px;">
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-mi-agenda')); ?>">Abrir agenda</a>
            </p>
            <?php
        }
    }
    ?>
    <?php
    crm_admin_page_footer();
}

/* ---------------------------------------------------------------------------
 * Clientes (v1.20.132) — gestión y borrado en lote desde wp-admin, pedido
 * explícito del usuario para limpiar clientes de ejemplo antes de una
 * prueba real con el equipo. No existía ninguna pantalla de wp-admin para
 * clientes hasta ahora (solo un contador de solo lectura en Dashboard) — el
 * listado/borrado individual que sí existía en el frontend
 * ("Todos los clientes") tenía el botón de borrar roto (desajuste de clase
 * CSS/JS) y sin selección múltiple; esta pantalla nueva no depende de ese
 * código, es autocontenida.
 * ------------------------------------------------------------------------- */

function crm_admin_render_clientes() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'crm_clients';
    $clientes = $wpdb->get_results(
        "SELECT id, cliente_nombre, email_cliente, telefono, delegado, origen_lead, estado, fecha
         FROM $table ORDER BY id DESC",
        ARRAY_A
    );

    // Nº de instalaciones por cliente, para que el aviso de borrado sea
    // concreto (no un "esto puede borrar cosas" genérico).
    $instalaciones_por_cliente = [];
    if (function_exists('crm_inst_table_instalaciones')) {
        $filas = $wpdb->get_results(
            "SELECT client_id, COUNT(*) AS total FROM " . crm_inst_table_instalaciones() . " WHERE client_id IS NOT NULL GROUP BY client_id",
            ARRAY_A
        );
        foreach ((array) $filas as $f) {
            $instalaciones_por_cliente[(int) $f['client_id']] = (int) $f['total'];
        }
    }

    $nonce = wp_create_nonce('crm_obtener_clientes_nonce');

    crm_admin_page_header('CRM · Clientes');
    ?>
    <p class="description" style="max-width:820px;">
        Borrado <strong>permanente e irreversible</strong>: incluye instalaciones y todo lo colgado de ellas (agenda, documentos, partidas extra, registro de actividad), notas, visitas y ficheros subidos de cada cliente seleccionado. Pensado sobre todo para limpiar clientes de ejemplo antes de una prueba real — no hay forma de identificar automáticamente cuáles son de prueba, la selección es manual.
    </p>

    <p>
        <button type="button" class="button" id="crm-clientes-seleccionar-todos">Seleccionar todos los filtrados</button>
        <button type="button" class="button" id="crm-clientes-deseleccionar">Deseleccionar todo</button>
        <button type="button" class="button button-link-delete" id="crm-clientes-borrar-btn" disabled>
            Eliminar seleccionados (<span id="crm-clientes-contador">0</span>)
        </button>
        <span id="crm-clientes-msg" style="margin-left:8px;font-size:13px;"></span>
    </p>

    <!-- v1.20.134: mismo sistema de tabla que el resto del CRM
         (.crm-table-container/.crm-table-material, css/crm-styles.css) en
         vez de las clases nativas de wp-admin — para que esta pantalla se
         vea igual que las demás tablas de clientes, no distinta por vivir
         en wp-admin. -->
    <div class="crm-table-container">
        <div class="table-responsive">
            <table class="crm-table-material" id="crm-clientes-tabla">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="crm-clientes-check-all"></th>
                        <th style="width:50px;">ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th>Comercial</th>
                        <th>Origen</th>
                        <th>Estado</th>
                        <th>Instalaciones</th>
                        <th>Alta</th>
                    </tr>
                </thead>
                <tbody id="crm-clientes-tbody">
                    <?php foreach ($clientes as $c) :
                        $n_inst = $instalaciones_por_cliente[(int) $c['id']] ?? 0;
                    ?>
                        <tr>
                            <td><input type="checkbox" class="crm-cliente-check" value="<?php echo (int) $c['id']; ?>" data-nombre="<?php echo esc_attr($c['cliente_nombre']); ?>" data-inst="<?php echo (int) $n_inst; ?>"></td>
                            <td><?php echo (int) $c['id']; ?></td>
                            <td><?php echo esc_html($c['cliente_nombre']); ?></td>
                            <td><?php echo esc_html($c['email_cliente']); ?></td>
                            <td><?php echo esc_html($c['telefono']); ?></td>
                            <td><?php echo esc_html($c['delegado']); ?></td>
                            <td><?php echo esc_html($c['origen_lead']); ?></td>
                            <td><?php echo esc_html($c['estado']); ?></td>
                            <td><?php echo $n_inst > 0 ? (int) $n_inst : '—'; ?></td>
                            <td data-order="<?php echo esc_attr(!empty($c['fecha']) ? strtotime($c['fecha']) : 0); ?>"><?php echo esc_html(!empty($c['fecha']) ? date_i18n('d/m/Y H:i', strtotime($c['fecha'])) : '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (empty($clientes)) : ?>
        <p>No hay ningún cliente todavía.</p>
    <?php endif; ?>

    <script>
    jQuery(function ($) {
        var seleccionados = new Set();
        var tablaEl = document.getElementById('crm-clientes-tabla');
        var checkAll = document.getElementById('crm-clientes-check-all');
        var btnSeleccionarFiltrados = document.getElementById('crm-clientes-seleccionar-todos');
        var btnDeseleccionar = document.getElementById('crm-clientes-deseleccionar');
        var btnBorrar = document.getElementById('crm-clientes-borrar-btn');
        var contador  = document.getElementById('crm-clientes-contador');
        var msg       = document.getElementById('crm-clientes-msg');

        // v1.20.134: ordenable + paginable (DataTables, mismo patrón que
        // mis-altas-de-cliente/todas-las-altas-de-cliente). La casilla no se
        // puede ordenar; el resto sí, incluida "Alta" por su valor real
        // (data-order) en vez del texto formateado.
        var tabla = $(tablaEl).DataTable({
            pageLength: 25,
            order: [[1, 'desc']],
            columnDefs: [{ orderable: false, targets: 0 }],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
            }
        });

        // La selección se guarda por ID, no por casilla — así sobrevive a
        // cambiar de página/orden/filtro (DataTables saca las filas de otras
        // páginas del DOM visible).
        function actualizarContador() {
            contador.textContent = seleccionados.size;
            btnBorrar.disabled = seleccionados.size === 0;
        }
        function sincronizarCasillasVisibles() {
            tablaEl.querySelectorAll('.crm-cliente-check').forEach(function (cb) {
                cb.checked = seleccionados.has(cb.value);
            });
        }

        $(tablaEl).on('change', '.crm-cliente-check', function () {
            if (this.checked) { seleccionados.add(this.value); } else { seleccionados.delete(this.value); }
            actualizarContador();
        });
        tabla.on('draw', sincronizarCasillasVisibles);

        checkAll.addEventListener('change', function () {
            tablaEl.querySelectorAll('.crm-cliente-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
                if (checkAll.checked) { seleccionados.add(cb.value); } else { seleccionados.delete(cb.value); }
            });
            actualizarContador();
        });
        btnSeleccionarFiltrados.addEventListener('click', function () {
            // Todas las filas que cumplen el filtro/búsqueda actual, en
            // TODAS las páginas — no solo la página visible.
            tabla.rows({ search: 'applied' }).nodes().to$().find('.crm-cliente-check').each(function () {
                seleccionados.add(this.value);
            });
            sincronizarCasillasVisibles();
            actualizarContador();
        });
        btnDeseleccionar.addEventListener('click', function () {
            seleccionados.clear();
            checkAll.checked = false;
            sincronizarCasillasVisibles();
            actualizarContador();
        });

        btnBorrar.addEventListener('click', function () {
            if (seleccionados.size === 0) { return; }
            var idsSeleccionados = Array.from(seleccionados);
            var nombresPorId = {};
            var totalInst = 0;
            // tabla.rows().nodes() recorre TODAS las filas del dataset, no
            // solo las de la página actual — DataTables mantiene el resto en
            // memoria aunque no estén pintadas ahora mismo.
            tabla.rows().nodes().to$().find('.crm-cliente-check').each(function () {
                if (seleccionados.has(this.value)) {
                    nombresPorId[this.value] = this.getAttribute('data-nombre');
                    totalInst += parseInt(this.getAttribute('data-inst') || '0', 10);
                }
            });
            var nombres = idsSeleccionados.slice(0, 8).map(function (id) { return nombresPorId[id] || ('#' + id); }).join(', ') + (idsSeleccionados.length > 8 ? '…' : '');
            var aviso = 'Vas a eliminar PERMANENTEMENTE ' + idsSeleccionados.length + ' cliente(s):\n' + nombres;
            if (totalInst > 0) { aviso += '\n\nIncluye ' + totalInst + ' instalación(es) y todo lo colgado de ellas.'; }
            aviso += '\n\nEsto no se puede deshacer. ¿Seguro?';
            if (!window.confirm(aviso)) { return; }

            btnBorrar.disabled = true;
            msg.style.color = '#6b7280';
            msg.textContent = 'Eliminando…';

            var body = new URLSearchParams();
            body.set('action', 'crm_bulk_borrar_clientes');
            body.set('nonce', <?php echo wp_json_encode($nonce); ?>);
            idsSeleccionados.forEach(function (id) { body.append('client_ids[]', id); });

            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        msg.style.color = '#065f46';
                        msg.textContent = resp.data.message;
                        idsSeleccionados.forEach(function (id) {
                            var cb = tablaEl.querySelector('.crm-cliente-check[value="' + id + '"]');
                            if (cb) { tabla.row($(cb).closest('tr')).remove(); }
                            seleccionados.delete(id);
                        });
                        tabla.draw(false);
                        actualizarContador();
                    } else {
                        msg.style.color = '#991b1b';
                        msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error al eliminar.';
                        btnBorrar.disabled = false;
                    }
                })
                .catch(function () {
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Error de conexión.';
                    btnBorrar.disabled = false;
                });
        });
    });
    </script>
    <?php
    crm_admin_page_footer();
}

/* ---------------------------------------------------------------------------
 * Logs
 * ------------------------------------------------------------------------- */

function crm_admin_render_logs() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    $months_available = crm_get_available_log_months();
    $month_keys       = array_map(function ($m) { return $m['value']; }, $months_available);

    // Construir filtros desde GET (todo lectura, sin efectos secundarios).
    $selected_month = isset($_GET['month']) ? sanitize_text_field((string) $_GET['month']) : current_time('Y_m');
    if (!in_array($selected_month, $month_keys, true)) {
        $selected_month = !empty($month_keys) ? $month_keys[0] : current_time('Y_m');
    }
    $months_for_query = ($selected_month === '__all') ? $month_keys : [$selected_month];

    $filters = [
        'months'    => $months_for_query,
        'search'    => isset($_GET['search']) ? sanitize_text_field((string) $_GET['search']) : '',
        'action'    => isset($_GET['action_type']) ? sanitize_text_field((string) $_GET['action_type']) : '',
        'level'     => isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '',
        'user_id'   => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
        'client_id' => isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0,
        'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
        'date_to'   => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
        'page'      => isset($_GET['paged']) ? (int) $_GET['paged'] : 1,
        'per_page'  => isset($_GET['per_page']) ? (int) $_GET['per_page'] : 50,
    ];

    $result  = crm_logs_query($filters);
    $actions = crm_logs_distinct_actions($months_for_query);

    crm_admin_page_header('CRM · Logs');
    ?>
    <form method="get" class="crm-logs-filters">
        <input type="hidden" name="page" value="crm-logs">
        <p>
            <label>Mes:
                <select name="month">
                    <option value="__all"<?php selected($selected_month, '__all'); ?>>Todos los meses</option>
                    <?php foreach ($months_available as $m): ?>
                        <option value="<?php echo esc_attr($m['value']); ?>" <?php selected($selected_month, $m['value']); ?>>
                            <?php echo esc_html($m['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Acción:
                <select name="action_type">
                    <option value="">Todas</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?php echo esc_attr($a); ?>" <?php selected($filters['action'], $a); ?>>
                            <?php echo esc_html(function_exists('crm_get_action_label') ? crm_get_action_label($a) : $a); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Nivel:
                <select name="level">
                    <option value="">Cualquiera</option>
                    <?php foreach (crm_log_levels() as $lvl): ?>
                        <option value="<?php echo esc_attr($lvl); ?>" <?php selected($filters['level'], $lvl); ?>>
                            <?php echo esc_html(ucfirst($lvl)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Usuario ID:
                <input type="number" min="0" name="user_id" value="<?php echo (int) $filters['user_id']; ?>">
            </label>
            <label>Cliente ID:
                <input type="number" min="0" name="client_id" value="<?php echo (int) $filters['client_id']; ?>">
            </label>
            <label>Desde:
                <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
            </label>
            <label>Hasta:
                <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
            </label>
            <label>Por página:
                <select name="per_page">
                    <?php foreach ([25, 50, 100, 200] as $n): ?>
                        <option value="<?php echo $n; ?>" <?php selected($filters['per_page'], $n); ?>><?php echo $n; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Búsqueda:
                <input type="search" name="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Texto, usuario o acción" style="width:260px">
            </label>
            <button class="button button-primary">Filtrar</button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-logs')); ?>">Limpiar</a>

            <?php
            $export_url = wp_nonce_url(
                add_query_arg(array_merge(['action' => 'crm_export_logs_csv'], array_intersect_key($_GET, array_flip(['month','action_type','level','user_id','client_id','date_from','date_to','search']))), admin_url('admin-post.php')),
                'crm_export_logs_csv'
            );
            ?>
            <a class="button" href="<?php echo esc_url($export_url); ?>">Exportar CSV</a>
        </p>
    </form>

    <p class="description">
        Resultados: <strong><?php echo number_format_i18n($result['total']); ?></strong>
        · Página <?php echo (int) $result['page']; ?> de <?php echo max(1, (int) $result['pages']); ?>
    </p>

    <table class="widefat striped crm-logs-table">
        <thead>
            <tr>
                <th style="width:140px">Fecha</th>
                <th style="width:80px">Nivel</th>
                <th>Usuario</th>
                <th>Acción</th>
                <th>Detalles</th>
                <th style="width:80px">Cliente</th>
                <th style="width:120px">IP</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['rows'])): ?>
            <tr><td colspan="7"><em>Sin entradas para los filtros aplicados.</em></td></tr>
        <?php else: foreach ($result['rows'] as $row):
            $level = crm_normalize_log_level($row['level'] ?? 'info');
        ?>
            <tr>
                <td><?php echo esc_html(mysql2date('d/m/Y H:i:s', $row['created_at'])); ?></td>
                <td><span class="crm-level crm-level-<?php echo esc_attr($level); ?>"><?php echo esc_html(ucfirst($level)); ?></span></td>
                <td><?php echo esc_html($row['user_name'] ?: '—'); ?><br><small>#<?php echo (int) $row['user_id']; ?></small></td>
                <td><code><?php echo esc_html($row['action_type']); ?></code></td>
                <td>
                    <?php echo esc_html($row['details']); ?>
                    <?php if (!empty($row['context'])): ?>
                        <details class="crm-log-ctx">
                            <summary>contexto</summary>
                            <pre><?php echo esc_html($row['context']); ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
                <td><?php echo $row['client_id'] ? '#' . (int) $row['client_id'] : '—'; ?></td>
                <td><code><?php echo esc_html($row['ip_address']); ?></code></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php
    // Paginación
    if ($result['pages'] > 1) {
        $base = remove_query_arg('paged');
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%', $base),
            'format'    => '',
            'current'   => (int) $result['page'],
            'total'     => (int) $result['pages'],
            'prev_text' => '«',
            'next_text' => '»',
        ]);
        echo '</div></div>';
    }

    crm_admin_page_footer();
}

/**
 * Endpoint para exportar logs CSV vía admin-post.
 */
add_action('admin_post_crm_export_logs_csv', function () {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_export_logs_csv');

    $month_keys = array_map(function ($m) { return $m['value']; }, crm_get_available_log_months());
    $selected   = isset($_GET['month']) ? sanitize_text_field((string) $_GET['month']) : current_time('Y_m');
    $months     = ($selected === '__all') ? $month_keys : [$selected];

    crm_logs_export_csv([
        'months'    => $months,
        'search'    => isset($_GET['search']) ? sanitize_text_field((string) $_GET['search']) : '',
        'action'    => isset($_GET['action_type']) ? sanitize_text_field((string) $_GET['action_type']) : '',
        'level'     => isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '',
        'user_id'   => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
        'client_id' => isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0,
        'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
        'date_to'   => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
    ]);
});

/* ---------------------------------------------------------------------------
 * Actualizaciones
 * ------------------------------------------------------------------------- */

function crm_admin_render_updates() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    $version       = defined('CRM_PLUGIN_VERSION') ? CRM_PLUGIN_VERSION : '?';
    $last_check    = (int) get_option('crm_last_update_check', 0);
    $last_update   = (int) get_option('crm_last_update_at', 0);
    $nonce         = wp_create_nonce('crm_admin_actions');
    $github_set    = defined('CRM_GITHUB_TOKEN') && CRM_GITHUB_TOKEN ? 'configurado' : 'no configurado';
    $repo          = apply_filters('crm_update_repo_url', 'https://github.com/replantadev/crm/');
    $branch        = apply_filters('crm_update_branch', 'master');

    crm_admin_page_header('CRM · Actualizaciones');
    ?>
    <table class="form-table">
        <tr><th>Versión instalada</th><td><code><?php echo esc_html($version); ?></code></td></tr>
        <tr><th>Repositorio</th><td><a href="<?php echo esc_url($repo); ?>" target="_blank" rel="noopener"><?php echo esc_html($repo); ?></a></td></tr>
        <tr><th>Rama</th><td><code><?php echo esc_html($branch); ?></code></td></tr>
        <tr><th>Token GitHub</th><td><?php echo esc_html($github_set); ?> <span class="description">(para repos privados, define <code>CRM_GITHUB_TOKEN</code> en <code>wp-config.php</code>)</span></td></tr>
        <tr><th>Última comprobación</th><td><?php echo $last_check ? esc_html(date_i18n('d/m/Y H:i', $last_check)) : '—'; ?></td></tr>
        <tr><th>Última actualización</th><td><?php echo $last_update ? esc_html(date_i18n('d/m/Y H:i', $last_update)) : '—'; ?></td></tr>
    </table>

    <p>
        <button id="crm-check-updates" class="button button-primary">Buscar actualizaciones ahora</button>
        <a class="button" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Ir a Plugins</a>
        <span id="crm-check-result" class="description" style="margin-left:12px"></span>
    </p>

    <hr>
    <h2>Caché del servidor</h2>
    <p class="description" style="max-width:700px;">Si acabas de actualizar el plugin y una página nueva sigue sin aparecer (error de "no tienes permisos" en una página que debería existir), puede que PHP esté ejecutando una versión compilada antigua del código (OPcache) aunque los archivos en el servidor ya estén al día. Este botón fuerza a PHP a recompilar el plugin desde cero.</p>
    <p>
        <button id="crm-purge-cache" class="button">Purgar caché del servidor (OPcache)</button>
        <span id="crm-purge-result" class="description" style="margin-left:12px"></span>
    </p>

    <script>
    (function(){
        var btn = document.getElementById('crm-check-updates');
        var msg = document.getElementById('crm-check-result');
        if (!btn) return;
        btn.addEventListener('click', function(){
            btn.disabled = true;
            msg.textContent = 'Comprobando…';
            var body = new URLSearchParams();
            body.append('action', 'crm_check_updates');
            body.append('nonce', '<?php echo esc_js($nonce); ?>');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body: body.toString()})
                .then(function(r){ return r.json(); })
                .then(function(j){
                    btn.disabled = false;
                    msg.textContent = (j && j.data && j.data.message) ? j.data.message : 'Sin respuesta';
                })
                .catch(function(){ btn.disabled = false; msg.textContent = 'Error de red'; });
        });
    })();
    (function(){
        var btn = document.getElementById('crm-purge-cache');
        var msg = document.getElementById('crm-purge-result');
        if (!btn) return;
        btn.addEventListener('click', function(){
            btn.disabled = true;
            msg.textContent = 'Purgando…';
            var body = new URLSearchParams();
            body.append('action', 'crm_purge_cache');
            body.append('nonce', '<?php echo esc_js($nonce); ?>');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body: body.toString()})
                .then(function(r){ return r.json(); })
                .then(function(j){
                    btn.disabled = false;
                    msg.textContent = (j && j.data && j.data.message) ? j.data.message : 'Sin respuesta';
                })
                .catch(function(){ btn.disabled = false; msg.textContent = 'Error de red'; });
        });
    })();
    </script>
    <?php
    crm_admin_page_footer();
}

/**
 * v1.20.118 — botón "Purgar caché del servidor" (wp-admin → CRM →
 * Actualizaciones). Nace de un caso real: la página wp-admin → CRM → Email
 * (registrada en includes/mail-settings.php) daba "no tienes permisos" a un
 * administrator real incluso recién reactivado el plugin y con Members
 * dando todos los permisos — el Editor de plugins de WordPress confirmó que
 * ese archivo ni siquiera aparecía en el listado real de ficheros del
 * plugin activo, señal de que PHP seguía ejecutando bytecode compilado de
 * una versión antigua (OPcache) aunque el archivo en disco ya estuviera
 * actualizado. `opcache_reset()` no está garantizado en todo hosting
 * (algunos lo deshabilitan por seguridad), de ahí el mensaje explícito si
 * no está disponible en vez de fallar en silencio.
 */
add_action('wp_ajax_crm_purge_cache', function () {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_admin_actions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $acciones = [];

    if (function_exists('opcache_reset')) {
        $acciones[] = opcache_reset() ? 'OPcache reiniciado.' : 'OPcache no se pudo reiniciar (opcache_reset devolvió false).';
    } else {
        $acciones[] = 'OPcache no disponible en este servidor (nada que hacer ahí).';
    }

    wp_cache_flush();
    $acciones[] = 'Caché de objetos de WordPress vaciada.';

    delete_transient('crm_plugin_cache');
    delete_transient('crm_db_size');
    delete_site_transient('update_plugins');
    $acciones[] = 'Transients del CRM y de actualizaciones borrados.';

    if (function_exists('crm_log_action')) {
        crm_log_action('cache_purgada', implode(' ', $acciones), null, 0, 'info');
    }

    wp_send_json_success(['message' => implode(' ', $acciones)]);
});

/* ---------------------------------------------------------------------------
 * Ajustes
 * ------------------------------------------------------------------------- */

function crm_admin_render_settings() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    crm_admin_page_header('CRM · Ajustes');
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('crm_settings'); ?>
        <table class="form-table">
            <tr>
                <th><label for="crm_logs_retention_days">Retención de logs (días)</label></th>
                <td>
                    <input type="number" min="7" id="crm_logs_retention_days" name="crm_logs_retention_days" value="<?php echo esc_attr((int) get_option('crm_logs_retention_days', 90)); ?>">
                    <p class="description">Filas más antiguas se eliminan diariamente. Mínimo 7.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_logs_retention_months">Retención de tablas mensuales</label></th>
                <td>
                    <input type="number" min="1" id="crm_logs_retention_months" name="crm_logs_retention_months" value="<?php echo esc_attr((int) get_option('crm_logs_retention_months', 6)); ?>">
                    <p class="description">Las tablas mensuales fuera de esta ventana se eliminan completamente (DROP).</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_login_page_id">Página de login (ID)</label></th>
                <td>
                    <input type="number" min="0" id="crm_login_page_id" name="crm_login_page_id" value="<?php echo esc_attr((int) get_option('crm_login_page_id', 0)); ?>" placeholder="en blanco = página &quot;Acceso&quot; autocreada">
                    <p class="description">Déjalo en blanco (0) para usar la página <strong>"Acceso"</strong> que el plugin crea solo (shortcode <code>[crm_login]</code>, formulario propio, no depende de Elementor ni de Members). Solo rellena esto si quieres usar otra página distinta.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_post_login_page_id">Página tras login (ID)</label></th>
                <td><input type="number" min="0" id="crm_post_login_page_id" name="crm_post_login_page_id" value="<?php echo esc_attr((int) get_option('crm_post_login_page_id', 30)); ?>"></td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Redirecciones frontend (v1.20.2)</h3>
                <p style="font-weight:normal;color:#666;margin:0 0 8px;">Cuando un rol distinto de <code>administrator</code> intenta entrar al <code>wp-admin</code>, se le redirige a la URL frontend correspondiente a su rol. Debe ser una URL completa (https://…).</p></th></tr>
            <tr>
                <th><label for="crm_url_panel_admin">URL panel admin (crm_admin)</label></th>
                <td>
                    <input type="url" id="crm_url_panel_admin" name="crm_url_panel_admin" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_url_panel_admin', '')); ?>" placeholder="https://tusitio.com/panel-admin/">
                    <p class="description">Página con el shortcode <code>[crm_admin_panel]</code>.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_url_panel_comercial">URL panel comercial</label></th>
                <td>
                    <input type="url" id="crm_url_panel_comercial" name="crm_url_panel_comercial" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_url_panel_comercial', '')); ?>" placeholder="https://tusitio.com/comercial/">
                    <p class="description">Página principal del comercial. Recomendado: incluir también el shortcode <code>[crm_agenda_modal]</code> para el botón flotante.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_url_mi_agenda">URL "Mi agenda" (visitador)</label></th>
                <td>
                    <input type="url" id="crm_url_mi_agenda" name="crm_url_mi_agenda" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_url_mi_agenda', '')); ?>" placeholder="https://tusitio.com/mi-agenda/">
                    <p class="description">Página con el shortcode <code>[crm_mi_agenda]</code>. También se usa como contenido del modal del comercial.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_url_mis_leads">URL "Mis leads" (comercial / visitador)</label></th>
                <td>
                    <input type="url" id="crm_url_mis_leads" name="crm_url_mis_leads" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_url_mis_leads', '')); ?>" placeholder="https://tusitio.com/mis-leads/">
                    <p class="description">Página con el shortcode <code>[asignacion_leads_mk modo="mios"]</code>. Si la dejas vacía y no existe la página con slug <code>mis-leads</code>, el item no aparece en el menú.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_url_panel_instalador">URL panel del instalador</label></th>
                <td>
                    <input type="url" id="crm_url_panel_instalador" name="crm_url_panel_instalador" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_url_panel_instalador', home_url('/panel-instalador/'))); ?>">
                    <p class="description">Página con el shortcode <code>[crm_inst_panel_instalador]</code> (se autocrea con este slug). Un usuario con <em>solo</em> el rol Instalador va aquí siempre al entrar, nunca al resto del CRM.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Integración Holded (v1.20.26)</h3></th></tr>
            <tr>
                <th><label for="crm_holded_api_key">Clave de API de Holded</label></th>
                <td>
                    <input type="password" id="crm_holded_api_key" name="crm_holded_api_key" class="regular-text" value="" autocomplete="off" placeholder="<?php echo crm_holded_is_configured() ? '•••••••••••••••• (clave guardada, deja en blanco para no cambiarla)' : 'Pega aquí la clave de API de Holded'; ?>">
                    <p class="description">Se usa para buscar presupuestos y consultar stock desde el módulo de instalaciones. Por seguridad nunca se muestra el valor guardado; deja el campo vacío al guardar para conservarlo.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Geocodificación (v1.20.32)</h3></th></tr>
            <tr>
                <th><label for="crm_nominatim_contact_email">Email de contacto (Nominatim)</label></th>
                <td>
                    <input type="email" id="crm_nominatim_contact_email" name="crm_nominatim_contact_email" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_nominatim_contact_email', '')); ?>" placeholder="contacto@ecovolt.es">
                    <p class="description">Nominatim (OpenStreetMap), el servicio gratuito que geocodifica las direcciones de las instalaciones, exige identificarse con un contacto en cada petición. Sin esto configurado, las peticiones igualmente se envían pero sin forma de que Nominatim te avise si hay algún problema.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Materiales e instalaciones (v1.20.37)</h3></th></tr>
            <tr>
                <th><label for="crm_proveedor_email">Email del proveedor</label></th>
                <td>
                    <input type="email" id="crm_proveedor_email" name="crm_proveedor_email" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_proveedor_email', '')); ?>" placeholder="pedidos@proveedor.com">
                    <p class="description">A esta dirección se envía el aviso al pulsar "Notificar proveedor" en la ficha de una instalación, con la lista de materiales aún pendientes de recibir. De momento un único proveedor para todas las instalaciones.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm-proveedor-holded-buscar">Contacto de Holded del proveedor</label></th>
                <td>
                    <input type="hidden" id="crm_proveedor_holded_contact_id" name="crm_proveedor_holded_contact_id" value="<?php echo esc_attr((string) get_option('crm_proveedor_holded_contact_id', '')); ?>">
                    <input type="hidden" id="crm_proveedor_holded_contact_nombre" name="crm_proveedor_holded_contact_nombre" value="<?php echo esc_attr((string) get_option('crm_proveedor_holded_contact_nombre', '')); ?>">
                    <div id="crm-proveedor-holded-vinculado" <?php echo get_option('crm_proveedor_holded_contact_id', '') === '' ? 'style="display:none;"' : ''; ?>>
                        Vinculado a: <strong id="crm-proveedor-holded-nombre"><?php echo esc_html((string) get_option('crm_proveedor_holded_contact_nombre', '')); ?></strong>
                        <button type="button" class="button" id="crm-proveedor-holded-quitar">Quitar</button>
                    </div>
                    <div id="crm-proveedor-holded-buscador" <?php echo get_option('crm_proveedor_holded_contact_id', '') !== '' ? 'style="display:none;"' : ''; ?>>
                        <input type="text" id="crm-proveedor-holded-buscar" placeholder="Nombre, email o CIF del proveedor…" class="regular-text">
                        <button type="button" class="button" id="crm-proveedor-holded-buscar-btn">Buscar</button>
                        <div id="crm-proveedor-holded-resultados"></div>
                    </div>
                    <p class="description">Necesario para poder crear un pedido de compra real en Holded al notificar al proveedor (opcional, ver más abajo). Guarda los Ajustes después de vincular.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_holded_warehouse_id">ID de almacén (Holded)</label></th>
                <td>
                    <input type="text" id="crm_holded_warehouse_id" name="crm_holded_warehouse_id" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_holded_warehouse_id', '')); ?>" placeholder="69c975f9f8c5399a6b07ac16 (Almacén Ecovolt, por defecto)">
                    <p class="description">Contra qué almacén de Holded se comprueba el stock de cada línea en la ficha. Déjalo en blanco para usar el Almacén Ecovolt por defecto.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Sincronización de clientes desde Holded (v1.20.95)</h3></th></tr>
            <tr>
                <th><label for="crm_holded_sync_clientes_enabled">Activar sincronización</label></th>
                <td>
                    <label><input type="checkbox" id="crm_holded_sync_clientes_enabled" name="crm_holded_sync_clientes_enabled" value="1" <?php checked(get_option('crm_holded_sync_clientes_enabled', false)); ?>> Cada hora, todos los contactos del Holded de Ecovolt se crean/actualizan como cliente del CRM (interés renovables), con su último presupuesto y estado reales.</label>
                    <p class="description">
                        Última pasada:
                        <?php $last = (int) get_option('crm_holded_sync_clientes_last_run', 0); ?>
                        <?php echo $last ? esc_html(date_i18n('d/m/Y H:i', $last)) : '—'; ?>
                        &nbsp;
                        <button type="button" class="button" id="crm-holded-sync-clientes-ahora-btn">Sincronizar ahora</button>
                        <span id="crm-holded-sync-clientes-msg"></span>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_holded_usuarios_mapa">Comerciales de Holded</label></th>
                <td>
                    <textarea id="crm_holded_usuarios_mapa" name="crm_holded_usuarios_mapa" class="large-text" rows="4" placeholder="6a3575970e631f452b05dac8=María&#10;otro_id_de_holded=Nombre"><?php echo esc_textarea((string) get_option('crm_holded_usuarios_mapa', '')); ?></textarea>
                    <p class="description">La API de Holded no permite consultar el nombre de un comercial a partir de su ID — una línea <code>id=Nombre</code> por comercial (el ID aparece sin traducir en la ficha de cliente si falta aquí). Se rellena a mano, cambia poco.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Panel del instalador (v1.20.41)</h3></th></tr>
            <tr>
                <th><label for="crm_instalador_panel_brand">Nombre de marca</label></th>
                <td><input type="text" id="crm_instalador_panel_brand" name="crm_instalador_panel_brand" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_instalador_panel_brand', 'Ecovolt')); ?>"></td>
            </tr>
            <tr>
                <th><label for="crm_instalador_panel_color">Color de acento</label></th>
                <td><input type="text" id="crm_instalador_panel_color" name="crm_instalador_panel_color" value="<?php echo esc_attr((string) get_option('crm_instalador_panel_color', '#15803d')); ?>" placeholder="#15803d"></td>
            </tr>
            <tr>
                <th><label for="crm_instalador_panel_logo_url">URL del logo</label></th>
                <td>
                    <input type="text" id="crm_instalador_panel_logo_url" name="crm_instalador_panel_logo_url" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_instalador_panel_logo_url', CRM_PLUGIN_URL . 'img/ecovolt-logo.jpg')); ?>">
                    <p class="description">Se usa en la cabecera del panel que ven los instaladores (no en el resto del CRM).</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_inst_extra_max">Máximo por partida extra (€)</label></th>
                <td>
                    <input type="number" step="0.01" min="0" id="crm_inst_extra_max" name="crm_inst_extra_max" value="<?php echo esc_attr((string) get_option('crm_inst_extra_max', 500)); ?>">
                    <p class="description">Importe máximo que un instalador puede declarar en una sola partida extra desde su panel (pendiente de validar por el jefe de instalaciones). Por encima de esto, el formulario no le deja enviarla.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_inst_cierre_requiere_aprobacion">Cierre de instalación requiere aprobación</label></th>
                <td>
                    <label>
                        <input type="checkbox" id="crm_inst_cierre_requiere_aprobacion" name="crm_inst_cierre_requiere_aprobacion" value="1" <?php checked(get_option('crm_inst_cierre_requiere_aprobacion', false)); ?>>
                        El cierre que declara el instalador (conformidad + observaciones + fotos) queda pendiente hasta que un jefe de instalaciones lo valide
                    </label>
                    <p class="description">Desmarcado (por defecto): el cierre del instalador es definitivo al momento — la instalación pasa a "Finalizada" directamente.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Aviso de materiales pendientes (v1.20.62)</h3></th></tr>
            <tr>
                <th><label for="crm_inst_aviso_dias_antes">Avisar con antelación</label></th>
                <td>
                    <input type="number" min="0" step="1" id="crm_inst_aviso_dias_antes" name="crm_inst_aviso_dias_antes" value="<?php echo esc_attr((string) get_option('crm_inst_aviso_dias_antes', 3)); ?>" style="width:70px;"> días antes de la visita agendada
                    <p class="description">Si a esa distancia de la fecha de la visita todavía quedan materiales de Holded sin marcar "Recibido", se avisa automáticamente al jefe de instalaciones — cada día hasta que se resuelva.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_inst_aviso_hora">Hora del aviso</label></th>
                <td>
                    <select id="crm_inst_aviso_hora" name="crm_inst_aviso_hora">
                        <?php $hora_actual = (int) get_option('crm_inst_aviso_hora', 9); ?>
                        <?php for ($h = 0; $h <= 23; $h++) : ?>
                            <option value="<?php echo esc_attr($h); ?>" <?php selected($hora_actual, $h); ?>><?php echo esc_html(sprintf('%02d:00', $h)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <p class="description">Hora del servidor a la que se comprueba y se envía (aproximada — depende de que WP-Cron tenga tráfico esa hora).</p>
                </td>
            </tr>
            <tr>
                <th>Canales</th>
                <td>
                    <label style="display:block; margin-bottom:6px;">
                        <input type="checkbox" name="crm_inst_aviso_canal_inapp" value="1" <?php checked(get_option('crm_inst_aviso_canal_inapp', true)); ?>>
                        Notificación in-app (campana)
                    </label>
                    <label style="display:block; margin-bottom:6px;">
                        <input type="checkbox" name="crm_inst_aviso_canal_email" value="1" <?php checked(get_option('crm_inst_aviso_canal_email', true)); ?>>
                        Email
                    </label>
                    <label style="display:block;">
                        <input type="checkbox" name="crm_inst_aviso_canal_whatsapp" value="1" <?php checked(get_option('crm_inst_aviso_canal_whatsapp', false)); ?>>
                        WhatsApp
                    </label>
                    <p class="description">Solo envía algo si abajo hay credenciales de WhatsApp Business configuradas — si no, se ignora sin dar error.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">WhatsApp Business — Meta Cloud API (v1.20.87)</h3></th></tr>
            <tr>
                <td colspan="2" style="padding-top:0;">
                    <p class="description" style="margin:0 0 10px;">
                        Requiere una cuenta de Meta Business verificada, un número de teléfono dedicado a WhatsApp Business, y al menos una <strong>plantilla de mensaje aprobada por Meta</strong> por cada aviso (no se puede enviar texto libre a un cliente que no te ha escrito antes). Sin esto configurado, el CRM sigue funcionando exactamente igual que hoy — nunca bloquea nada.
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_phone_number_id">Phone Number ID</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_phone_number_id" name="crm_whatsapp_phone_number_id" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_phone_number_id', '')); ?>" placeholder="Identificador del número, del panel de Meta for Developers">
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_api_token">Token de acceso</label></th>
                <td>
                    <input type="password" id="crm_whatsapp_api_token" name="crm_whatsapp_api_token" class="regular-text" value="" autocomplete="off" placeholder="<?php echo crm_whatsapp_configurado() ? '•••••••••••••••• (token guardado, deja en blanco para no cambiarlo)' : 'Pega aquí el token de acceso de Meta Cloud API'; ?>">
                    <p class="description">Por seguridad nunca se muestra el valor guardado; deja el campo vacío al guardar para conservarlo.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_aviso_materiales">Plantilla — aviso de materiales</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_aviso_materiales" name="crm_whatsapp_template_aviso_materiales" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_aviso_materiales', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Nombre exacto (tal cual está en Meta Business Manager) de la plantilla para el aviso de materiales pendientes a jefes de instalaciones.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_validar_extra">Plantilla — validar partida extra</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_validar_extra" name="crm_whatsapp_template_validar_extra" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_validar_extra', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Nombre exacto de la plantilla que recibe el cliente para aprobar/rechazar una partida extra.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_en_ejecucion">Plantilla — instalación en marcha</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_en_ejecucion" name="crm_whatsapp_template_en_ejecucion" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_en_ejecucion', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Nombre exacto de la plantilla para avisar a jefes/crm_admin cuando el instalador marca la primera línea como montada ("cierre parcial").</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_recordatorio_visita">Plantilla — recordatorio de visita</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_recordatorio_visita" name="crm_whatsapp_template_recordatorio_visita" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_recordatorio_visita', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Nombre exacto de la plantilla para el recordatorio a jefes/crm_admin el día antes de una visita agendada (v1.20.127). El cliente recibe su recordatorio por email, no por WhatsApp — no hay plantilla para ese caso todavía.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">WhatsApp a instalador (v1.20.129)</h3></th></tr>
            <tr>
                <td colspan="2" style="padding-top:0;">
                    <p class="description" style="margin:0 0 10px;">Cada instalador debe activar esto para sí mismo desde "Mi perfil" (panel branded) — estas plantillas no envían nada si el instalador no ha marcado su casilla de WhatsApp, aunque el número esté guardado.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_instalador_asignado">Plantilla — instalación asignada</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_instalador_asignado" name="crm_whatsapp_template_instalador_asignado" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_instalador_asignado', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Al instalador, cuando se le asigna una instalación nueva.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_instalador_visita">Plantilla — visita programada/reprogramada</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_instalador_visita" name="crm_whatsapp_template_instalador_visita" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_instalador_visita', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Al instalador, cuando se le agenda o reprograma una visita (mismo texto sirve para ambos casos).</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_instalador_extra_resuelta">Plantilla — partida extra resuelta</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_instalador_extra_resuelta" name="crm_whatsapp_template_instalador_extra_resuelta" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_instalador_extra_resuelta', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Al instalador que la declaró, cuando su partida extra queda aprobada o rechazada (por el jefe o por el cliente).</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_instalador_cierre_resuelto">Plantilla — cierre resuelto</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_instalador_cierre_resuelto" name="crm_whatsapp_template_instalador_cierre_resuelto" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_instalador_cierre_resuelto', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Al instalador que lo declaró, cuando el jefe aprueba o rechaza su cierre de instalación.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Aviso de calendario (v1.20.127)</h3></th></tr>
            <tr>
                <th><label for="crm_inst_aviso_calendario_hora">Hora del recordatorio</label></th>
                <td>
                    <select id="crm_inst_aviso_calendario_hora" name="crm_inst_aviso_calendario_hora">
                        <?php $hora_cal_actual = (int) get_option('crm_inst_aviso_calendario_hora', 9); ?>
                        <?php for ($h = 0; $h <= 23; $h++) : ?>
                            <option value="<?php echo esc_attr($h); ?>" <?php selected($hora_cal_actual, $h); ?>><?php echo esc_html(sprintf('%02d:00', $h)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <p class="description">A esta hora del día ANTERIOR a cada visita agendada, se avisa a cliente (email + WhatsApp con botones de confirmación, v1.20.130), instalador asignado (in-app + email + WhatsApp) y jefes/crm_admin (por sus propios canales).</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_inst_aviso_cliente_visita_email">Avisar al cliente al agendar</label></th>
                <td>
                    <label><input type="checkbox" id="crm_inst_aviso_cliente_visita_email" name="crm_inst_aviso_cliente_visita_email" value="1" <?php checked(get_option('crm_inst_aviso_cliente_visita_email', false)); ?>> Enviar un email al cliente en el momento de programar/reprogramar la visita (v1.20.130)</label>
                    <p class="description">Desmarcado (por defecto): el cliente se entera de la visita solo con el recordatorio del día antes. Marcado, recibe también un email inmediato al agendar/reprogramar.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Confirmación de cita por WhatsApp (Fase 8, v1.20.130)</h3></th></tr>
            <tr>
                <td colspan="2" style="padding-top:0;">
                    <?php
                    // Se autogenera al entrar aquí la primera vez — no tiene
                    // sentido pedirle al usuario que invente un token seguro
                    // a mano, y hace falta uno estable para pegar en Meta.
                    $verify_token = trim((string) get_option('crm_whatsapp_webhook_verify_token', ''));
                    if ($verify_token === '') {
                        $verify_token = wp_generate_password(32, false);
                        update_option('crm_whatsapp_webhook_verify_token', $verify_token, false);
                    }
                    ?>
                    <p class="description" style="margin:0 0 10px;">Esto permite RECIBIR la respuesta del cliente (botones "Confirmo"/"Necesito cambiar") a la plantilla del recordatorio del día antes — hasta ahora WhatsApp solo enviaba. Configúralo en tu app de Meta → WhatsApp → Configuración → Webhooks:</p>
                    <table style="margin-bottom:10px;">
                        <tr><td style="padding:2px 8px 2px 0;"><strong>URL de retorno (Callback URL)</strong></td><td><code><?php echo esc_html(rest_url('crm/v1/whatsapp-webhook')); ?></code></td></tr>
                        <tr><td style="padding:2px 8px 2px 0;"><strong>Token de verificación</strong></td><td><code><?php echo esc_html($verify_token); ?></code></td></tr>
                        <tr><td style="padding:2px 8px 2px 0;"><strong>Campo a suscribir</strong></td><td><code>messages</code></td></tr>
                    </table>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_app_secret">App Secret</label></th>
                <td>
                    <input type="password" id="crm_whatsapp_app_secret" name="crm_whatsapp_app_secret" class="regular-text" value="" autocomplete="off" placeholder="<?php echo trim((string) get_option('crm_whatsapp_app_secret', '')) !== '' ? '•••••••••••••••• (guardado, deja en blanco para no cambiarlo)' : 'Meta → Configuración → Básica → Secreto de la aplicación'; ?>">
                    <p class="description">Para comprobar que las llamadas al webhook son de verdad de Meta (firma HMAC), no para llamar a la API — es un dato distinto del token de acceso de arriba. Sin esto configurado, el webhook funciona igual pero sin verificar la firma.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crm_whatsapp_template_confirmacion_visita_cliente">Plantilla — confirmación de cita (cliente)</label></th>
                <td>
                    <input type="text" id="crm_whatsapp_template_confirmacion_visita_cliente" name="crm_whatsapp_template_confirmacion_visita_cliente" class="regular-text" value="<?php echo esc_attr((string) get_option('crm_whatsapp_template_confirmacion_visita_cliente', '')); ?>" placeholder="nombre_exacto_de_la_plantilla_en_meta">
                    <p class="description">Al cliente, junto con el recordatorio del día antes — debe tener 2 botones de respuesta rápida ("Confirmo la visita" / "Necesito cambiar la fecha") para que el webhook pueda interpretar la respuesta.</p>
                </td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:18px 0 6px;">Presupuesto estancado (v1.20.127)</h3></th></tr>
            <tr>
                <th><label for="crm_ventas_aviso_estancados_dias">Avisar tras</label></th>
                <td>
                    <input type="number" min="1" step="1" id="crm_ventas_aviso_estancados_dias" name="crm_ventas_aviso_estancados_dias" value="<?php echo esc_attr((int) get_option('crm_ventas_aviso_estancados_dias', 7)); ?>" style="width:70px;"> días sin aprobarse
                    <p class="description">Avisa al comercial dueño del cliente (in-app + email) cuando un presupuesto de Holded lleva creado más de este número de días y sigue sin aprobar. Solo funciona si el cliente tiene un comercial asignado en su ficha — si no, queda anotado en Logs.</p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <script>
    jQuery(function ($) {
        // v1.20.92 — buscador/vinculador del contacto de Holded del proveedor
        // (para poder crear pedidos de compra reales). Mismo endpoint que el
        // buscador de contactos del Panel — solo cambia qué se hace con el
        // resultado elegido.
        $('#crm-proveedor-holded-buscar-btn').on('click', function () {
            var query = $('#crm-proveedor-holded-buscar').val();
            var cont = $('#crm-proveedor-holded-resultados').html('<p>Buscando…</p>');
            $.post(ajaxurl, {
                action: 'crm_admin_buscar_contactos_holded',
                nonce: '<?php echo wp_create_nonce('crm_inst_holded'); ?>',
                query: query
            }, function (resp) {
                if (!resp.success) {
                    cont.html('<p style="color:#991b1b;">' + (resp.data && resp.data.message ? resp.data.message : 'Error.') + '</p>');
                    return;
                }
                if (!resp.data.items.length) {
                    cont.html('<p>Sin resultados.</p>');
                    return;
                }
                var html = '';
                resp.data.items.forEach(function (c) {
                    html += '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;">' +
                        '<span>' + $('<div>').text(c.name).html() + (c.email ? ' — ' + $('<div>').text(c.email).html() : '') + '</span>' +
                        '<button type="button" class="button crm-proveedor-holded-elegir" data-id="' + $('<div>').text(c.id).html() + '" data-name="' + $('<div>').text(c.name).html() + '">Elegir</button>' +
                        '</div>';
                });
                cont.html(html);
            });
        });

        $(document).on('click', '.crm-proveedor-holded-elegir', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');
            $('#crm_proveedor_holded_contact_id').val(id);
            $('#crm_proveedor_holded_contact_nombre').val(name);
            $('#crm-proveedor-holded-nombre').text(name);
            $('#crm-proveedor-holded-vinculado').show();
            $('#crm-proveedor-holded-buscador').hide();
        });

        $('#crm-proveedor-holded-quitar').on('click', function () {
            $('#crm_proveedor_holded_contact_id').val('');
            $('#crm_proveedor_holded_contact_nombre').val('');
            $('#crm-proveedor-holded-vinculado').hide();
            $('#crm-proveedor-holded-buscador').show();
        });

        // v1.20.95 — sincronización de clientes desde Holded, botón "Sincronizar ahora".
        $('#crm-holded-sync-clientes-ahora-btn').on('click', function () {
            var btn = $(this).prop('disabled', true).text('Sincronizando…');
            var msg = $('#crm-holded-sync-clientes-msg');
            msg.css('color', '#6b7280').text('');
            $.post(ajaxurl, {
                action: 'crm_holded_sync_clientes_ahora',
                nonce: '<?php echo wp_create_nonce('crm_admin_actions'); ?>'
            }, function (resp) {
                btn.prop('disabled', false).text('Sincronizar ahora');
                if (!resp.success) {
                    msg.css('color', '#991b1b').text((resp.data && resp.data.message) ? resp.data.message : 'Error.');
                    return;
                }
                var texto = resp.data.procesados + ' procesados, ' + resp.data.creados + ' creados, ' + resp.data.errores + ' errores, ' + resp.data.pdf_adjuntados + ' PDF adjuntados.';
                var pdfErrores = resp.data.pdf_errores || {};
                var numPdfErrores = Object.keys(pdfErrores).length;
                if (numPdfErrores > 0) {
                    var detalle = Object.keys(pdfErrores).map(function (clientId) {
                        return '#' + clientId + ': ' + pdfErrores[clientId];
                    }).join(' · ');
                    msg.css('color', '#991b1b').text(texto + ' ' + numPdfErrores + ' PDF fallidos — ' + detalle);
                } else {
                    msg.css('color', '#065f46').text(texto);
                }
            }).fail(function () {
                btn.prop('disabled', false).text('Sincronizar ahora');
                msg.css('color', '#991b1b').text('Error de conexión.');
            });
        });
    });
    </script>

    <?php if (isset($_GET['holded_test'])): ?>
        <?php if ($_GET['holded_test'] === 'ok'): ?>
            <div class="notice notice-success"><p>Conexión con Holded correcta. Presupuestos encontrados en la primera página: <?php echo esc_html((int) ($_GET['count'] ?? 0)); ?>.</p></div>
        <?php else: ?>
            <div class="notice notice-error"><p>Fallo al conectar con Holded: <?php echo esc_html(wp_unslash($_GET['holded_test'])); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>
    <p>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_holded_test_connection'), 'crm_holded_test_connection')); ?>">Probar conexión con Holded</a>
    </p>

    <?php if (isset($_GET['geocode_test'])): ?>
        <?php if ($_GET['geocode_test'] === 'ok'): ?>
            <div class="notice notice-success"><p>Geocodificado correctamente: lat <?php echo esc_html((string) ($_GET['lat'] ?? '')); ?>, lng <?php echo esc_html((string) ($_GET['lng'] ?? '')); ?>.</p></div>
        <?php else: ?>
            <div class="notice notice-error"><p>Fallo al geocodificar: <?php echo esc_html(wp_unslash($_GET['geocode_test'])); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>
    <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="crm_geocode_test">
        <?php wp_nonce_field('crm_geocode_test', '_wpnonce', true, true); ?>
        <input type="text" name="address" class="regular-text" placeholder="Calle Mayor 1, León" value="<?php echo esc_attr((string) ($_GET['address'] ?? '')); ?>">
        <button type="submit" class="button">Probar geocodificación</button>
    </form>

    <h2>Mantenimiento de logs</h2>
    <p>Próxima ejecución programada:
        <?php
        $ts = wp_next_scheduled('crm_logs_daily_maintenance');
        echo $ts ? '<code>' . esc_html(date_i18n('d/m/Y H:i', $ts)) . '</code>' : '<em>no programada</em>';
        ?>
    </p>
    <p>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_run_logs_maintenance'), 'crm_run_logs_maintenance')); ?>">Ejecutar mantenimiento ahora</a>
    </p>
    <?php
    crm_admin_page_footer();
}

add_action('admin_post_crm_run_logs_maintenance', function () {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_run_logs_maintenance');
    crm_logs_run_maintenance();
    wp_safe_redirect(add_query_arg(['page' => 'crm-settings', 'maintenance' => 'done'], admin_url('admin.php')));
    exit;
});

add_action('admin_post_crm_holded_test_connection', function () {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_holded_test_connection');

    delete_transient('crm_holded_estimates_first');
    $result = crm_holded_get_estimates(1);

    $redirect_args = ['page' => 'crm-settings'];
    if (is_wp_error($result)) {
        $redirect_args['holded_test'] = $result->get_error_message();
    } else {
        $redirect_args['holded_test'] = 'ok';
        $redirect_args['count']       = count($result);
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit;
});

add_action('admin_post_crm_geocode_test', function () {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_geocode_test');

    $address = sanitize_text_field(wp_unslash($_GET['address'] ?? ''));
    $result  = crm_geo_geocode_address($address);

    $redirect_args = ['page' => 'crm-settings', 'address' => $address];
    if (is_wp_error($result)) {
        $redirect_args['geocode_test'] = $result->get_error_message();
    } else {
        $redirect_args['geocode_test'] = 'ok';
        $redirect_args['lat']          = $result['lat'];
        $redirect_args['lng']          = $result['lng'];
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit;
});

/* ---------------------------------------------------------------------------
 * Estilos inline (sólo en páginas del plugin)
 * ------------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', function ($hook) {
    if (!is_string($hook) || strpos($hook, 'crm-') === false) {
        return;
    }
    $css = <<<CSS
    .crm-admin-wrap .crm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:18px 0}
    .crm-admin-wrap .crm-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px 16px;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
    .crm-admin-wrap .crm-card h2{font-size:14px;margin:0 0 6px;color:#1d2327;text-transform:uppercase;letter-spacing:.04em}
    .crm-admin-wrap .crm-card .crm-metric{font-size:28px;font-weight:600;margin:4px 0;color:#2271b1}
    .crm-admin-wrap .crm-logs-filters{background:#fff;padding:12px 14px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:12px}
    .crm-admin-wrap .crm-logs-filters label{margin-right:14px;display:inline-flex;align-items:center;gap:6px}
    .crm-admin-wrap .crm-logs-table td{vertical-align:top}
    .crm-admin-wrap .crm-log-ctx{margin-top:6px}
    .crm-admin-wrap .crm-log-ctx pre{background:#f6f7f7;padding:8px;border-radius:4px;overflow:auto;max-height:240px;font-size:11px}
    .crm-admin-wrap .crm-level{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
    .crm-admin-wrap .crm-level-debug{background:#eef;color:#3b3b8f}
    .crm-admin-wrap .crm-level-info{background:#e7f5ff;color:#0a558c}
    .crm-admin-wrap .crm-level-notice{background:#e6fcf5;color:#087f5b}
    .crm-admin-wrap .crm-level-warning{background:#fff4e6;color:#b45309}
    .crm-admin-wrap .crm-level-error{background:#ffe3e3;color:#b32424}
    .crm-admin-wrap .crm-level-critical{background:#b32424;color:#fff}
CSS;
    wp_register_style('crm-admin-inline', false);
    wp_enqueue_style('crm-admin-inline');
    wp_add_inline_style('crm-admin-inline', $css);
});

/**
 * v1.20.134 — DataTables solo en wp-admin → CRM → Clientes, para ordenar y
 * paginar. Mismo CDN/versión (1.13.4) + idioma español que ya se usa en
 * mis-altas-de-cliente/todas-las-altas-de-cliente (crm-plugin.php) — un
 * único patrón de tabla ordenable en todo el CRM, no uno distinto por
 * pantalla.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'crm-dashboard_page_crm-clientes') {
        return;
    }
    wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', ['jquery'], null, true);
    wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css');
});

/* ---------------------------------------------------------------------------
 * Leads MK (Google Sheets sync + Notificaciones)
 * ------------------------------------------------------------------------- */

add_action('admin_post_crm_leads_mk_save', 'crm_admin_leads_mk_save');
function crm_admin_leads_mk_save() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_leads_mk_save');

    if (function_exists('crm_leads_sheets_save_settings')) {
        $sa_raw = isset($_POST['sa_json']) ? trim(wp_unslash($_POST['sa_json'])) : '';
        $patch = [
            'enabled'        => !empty($_POST['enabled']),
            'spreadsheet_id' => isset($_POST['spreadsheet_id']) ? sanitize_text_field($_POST['spreadsheet_id']) : '',
            'range'          => isset($_POST['range']) ? sanitize_text_field($_POST['range']) : 'A:Z',
        ];
        // Solo actualizar sa_json si el textarea trae JSON nuevo (no vacío y parseable)
        if ($sa_raw !== '' && $sa_raw !== '****') {
            $decoded = json_decode($sa_raw, true);
            if (is_array($decoded) && !empty($decoded['client_email']) && !empty($decoded['private_key'])) {
                $patch['sa_json'] = $sa_raw;
            }
        }
        crm_leads_sheets_save_settings($patch);
    }

    if (function_exists('crm_notif_save_settings')) {
        crm_notif_save_settings([
            'assignment_enabled' => !empty($_POST['notif_assignment']),
            // El remitente lo aporta WordPress (o WP Mail SMTP si está instalado). No se sobrescribe desde el CRM.
            'from_name'          => '',
            'from_email'         => '',
        ]);
    }

    wp_safe_redirect(add_query_arg(['page' => 'crm-leads-mk', 'saved' => 1], admin_url('admin.php')));
    exit;
}

function crm_admin_render_leads_mk() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }
    $sheets = function_exists('crm_leads_sheets_get_settings') ? crm_leads_sheets_get_settings() : [];
    $notif  = function_exists('crm_notif_get_settings') ? crm_notif_get_settings() : [];

    crm_admin_page_header('CRM · Leads MK / Sheets');

    if (!empty($_GET['saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>';
    }
    if (!empty($_GET['sync'])) {
        $sync_msg = sanitize_text_field(wp_unslash($_GET['sync']));
        $kind = strpos($sync_msg, 'error') === 0 ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($kind) . ' is-dismissible"><p>Sync: ' . esc_html($sync_msg) . '</p></div>';
    }

    // Botón para forzar sync manual desde wp-admin
    $sync_action = wp_nonce_url(admin_url('admin-post.php?action=crm_leads_mk_run_sync'), 'crm_leads_mk_run_sync');
    $sync_action_force = wp_nonce_url(admin_url('admin-post.php?action=crm_leads_mk_run_sync&force=1'), 'crm_leads_mk_run_sync');
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="crm_leads_mk_save">
        <?php wp_nonce_field('crm_leads_mk_save'); ?>

        <h2>Google Sheets — origen de leads</h2>
        <table class="form-table">
            <tr>
                <th><label for="enabled">Activo</label></th>
                <td>
                    <label><input type="checkbox" name="enabled" id="enabled" value="1" <?php checked(!empty($sheets['enabled'])); ?>>
                        Sincronizar automáticamente cada hora</label>
                </td>
            </tr>
            <tr>
                <th><label for="spreadsheet_id">Spreadsheet ID</label></th>
                <td>
                    <input type="text" class="regular-text" name="spreadsheet_id" id="spreadsheet_id"
                           value="<?php echo esc_attr($sheets['spreadsheet_id'] ?? ''); ?>"
                           placeholder="1mdhsmagqUsyzN9wJMkkm4TK2ua3sOARRVirQ0FMO2ZA">
                    <p class="description">ID que aparece en la URL del Sheet: <code>docs.google.com/spreadsheets/d/<strong>{ID}</strong>/edit</code></p>
                </td>
            </tr>
            <tr>
                <th><label for="range">Rango</label></th>
                <td>
                    <input type="text" name="range" id="range" value="<?php echo esc_attr($sheets['range'] ?? 'A:Z'); ?>" placeholder="Hoja1!A:Z">
                </td>
            </tr>
            <tr>
                <th><label for="sa_json">Service Account JSON</label></th>
                <td>
                    <textarea name="sa_json" id="sa_json" rows="8" class="large-text code"
                              placeholder='{ "type": "service_account", "project_id": "...", "client_email": "...", "private_key": "-----BEGIN PRIVATE KEY-----..." }'><?php echo !empty($sheets['sa_json']) ? '****' : ''; ?></textarea>
                    <p class="description">
                        Pega el JSON completo de la cuenta de servicio con permisos de lectura del Sheet.
                        Se almacena <strong>cifrado con <code>AUTH_KEY</code></strong>. Deja <code>****</code> para no modificarlo.
                    </p>
                    <details style="margin-top:6px;">
                        <summary style="cursor:pointer;font-weight:600;">¿Cómo obtener el Service Account JSON?</summary>
                        <ol style="margin-top:8px;line-height:1.5;">
                            <li>Entra en <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> con la cuenta del cliente (o crea un proyecto nuevo).</li>
                            <li>Ve a <em>APIs &amp; Services → Library</em> y activa <strong>Google Sheets API</strong>.</li>
                            <li>Ve a <em>IAM &amp; Admin → Service Accounts → Create service account</em>. Nombre: por ejemplo <code>crm-sheets-reader</code>. No hace falta darle rol de proyecto.</li>
                            <li>Abre la cuenta de servicio creada → pestaña <em>Keys</em> → <em>Add Key → Create new key → JSON</em>. Se descarga un fichero <code>.json</code>.</li>
                            <li>Copia el correo <code>client_email</code> del JSON (algo como <code>crm-sheets-reader@&lt;proyecto&gt;.iam.gserviceaccount.com</code>) y <strong>compártele el Google Sheet en modo Lector</strong> desde el botón <em>Compartir</em> de Sheets.</li>
                            <li>Pega aquí el <strong>contenido completo del JSON</strong> y guarda.</li>
                        </ol>
                        <p style="margin-top:6px;"><em>El JSON contiene una clave privada: trátalo como un secreto. El plugin lo guarda cifrado con AES-256-CBC + AUTH_KEY.</em></p>
                    </details>
                </td>
            </tr>
            <tr>
                <th>Última sincronización</th>
                <td>
                    <?php if (!empty($sheets['last_sync'])): ?>
                        <code><?php echo esc_html(date_i18n('d/m/Y H:i', (int) $sheets['last_sync'])); ?></code> · <?php echo esc_html($sheets['last_status'] ?? ''); ?>
                    <?php else: ?>
                        <em>Nunca ejecutada</em>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h2>Notificaciones por email</h2>
        <table class="form-table">
            <tr>
                <th><label for="notif_assignment">Asignación de cliente</label></th>
                <td>
                    <label><input type="checkbox" name="notif_assignment" id="notif_assignment" value="1" <?php checked(!empty($notif['assignment_enabled'])); ?>>
                        Avisar al comercial cuando se le asigne un cliente o lead</label>
                    <p class="description">
                        Los correos se envían con el sistema de email del propio sitio: si tienes instalado
                        <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener">WP Mail SMTP</a>
                        (u otro plugin equivalente) los enviará por su SMTP; en caso contrario se usa la función nativa
                        <code>wp_mail()</code> de WordPress. No es necesario configurar remitente aquí: se respeta el del sitio.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button('Guardar ajustes'); ?>
    </form>

    <h2>Sincronización manual</h2>
    <p>
        <a class="button button-primary" href="<?php echo esc_url($sync_action); ?>">Sincronizar ahora</a>
        <a class="button" href="<?php echo esc_url($sync_action_force); ?>" onclick="return confirm('Vaciará el cursor de IDs procesados y reimportará todas las filas del Sheet desde cero (los duplicados existentes se detectarán por email/teléfono). ¿Continuar?');" style="margin-left:8px;">Forzar resincronización (ignorar cursor)</a>
    </p>
    <p>
        <a class="button" href="<?php echo esc_url(home_url('/asignacion-leads-mk/')); ?>" target="_blank">Abrir cola de asignación (frontend)</a>
        — recuerda crear una página con el shortcode <code>[asignacion_leads_mk]</code>.
    </p>
    <?php
    crm_admin_page_footer();
}

add_action('admin_post_crm_leads_mk_run_sync', 'crm_admin_leads_mk_run_sync_post');
function crm_admin_leads_mk_run_sync_post() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_leads_mk_run_sync');
    $force = !empty($_GET['force']) || !empty($_POST['force']);
    $msg = 'ok';
    if (function_exists('crm_leads_sheets_run_sync')) {
        $res = crm_leads_sheets_run_sync($force);
        if (is_wp_error($res)) {
            $msg = 'error: ' . $res->get_error_message();
        } else {
            $msg = sprintf(
                '%s%d nuevos · %d dup · %d omitidos (sin id: %d · ya procesados: %d · errores: %d)',
                $force ? '[FORZADO] ' : '',
                $res['inserted'] ?? 0,
                $res['dupes'] ?? 0,
                $res['skipped'] ?? 0,
                $res['no_id'] ?? 0,
                $res['already'] ?? 0,
                $res['errors'] ?? 0
            );
            if (!empty($res['headers'])) {
                $msg .= ' · cabeceras: ' . implode(', ', array_slice((array) $res['headers'], 0, 12));
            }
        }
    } else {
        $msg = 'error: módulo sheets no cargado';
    }
    wp_safe_redirect(add_query_arg(['page' => 'crm-leads-mk', 'sync' => rawurlencode($msg)], admin_url('admin.php')));
    exit;
}

/**
 * v1.20.87: campo de WhatsApp en el perfil de wp-admin (profile.php), para
 * que jefe_instalaciones/crm_admin puedan guardar su número — el instalador
 * ya lo hace desde "Mi perfil" (panel branded, sin acceso a wp-admin); estos
 * roles sí entran a wp-admin, así que reutilizan la pantalla estándar en vez
 * de duplicar una página propia. Mismo user-meta `crm_whatsapp` para los dos
 * casos — es el mismo dato de perfil independientemente de por dónde se edite.
 *
 * v1.20.126: se añaden 3 casillas de canal (in-app/email/WhatsApp) — antes
 * había un único interruptor global en Ajustes que aplicaba a TODOS los
 * jefes/crm_admin por igual; ahora cada uno elige el suyo aquí. Si alguien
 * nunca las toca, `crm_inst_notif_canal_habilitado()` (includes/instalaciones.php)
 * cae al ajuste global de siempre — nadie deja de recibir avisos de golpe.
 */
add_action('show_user_profile', 'crm_admin_render_whatsapp_profile_field');
add_action('edit_user_profile', 'crm_admin_render_whatsapp_profile_field');
function crm_admin_render_whatsapp_profile_field($user) {
    if (!in_array('jefe_instalaciones', (array) $user->roles, true) && !in_array('crm_admin', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true)) {
        return;
    }
    $whatsapp = (string) get_user_meta($user->ID, 'crm_whatsapp', true);

    $canal_valor = function ($canal, $default_global) use ($user) {
        $meta = get_user_meta($user->ID, 'crm_notif_canal_' . $canal, true);
        return $meta === '' ? (bool) get_option('crm_inst_aviso_canal_' . $canal, $default_global) : $meta === '1';
    };
    ?>
    <h2>CRM — Avisos de instalaciones</h2>
    <table class="form-table">
        <tr>
            <th><label for="crm_whatsapp">WhatsApp</label></th>
            <td>
                <input type="text" id="crm_whatsapp" name="crm_whatsapp" class="regular-text" value="<?php echo esc_attr($whatsapp); ?>" placeholder="+34600000000">
                <p class="description">Número al que llegarían los avisos de materiales pendientes por WhatsApp, si ese canal está activado abajo. En blanco, no recibes nada por este canal (el email sigue funcionando igual).</p>
            </td>
        </tr>
        <tr>
            <th>Mis canales de aviso</th>
            <td>
                <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="crm_notif_canal_inapp" value="1" <?php checked($canal_valor('inapp', true)); ?>> Notificación in-app (campana)</label>
                <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="crm_notif_canal_email" value="1" <?php checked($canal_valor('email', true)); ?>> Email</label>
                <label style="display:block;"><input type="checkbox" name="crm_notif_canal_whatsapp" value="1" <?php checked($canal_valor('whatsapp', false)); ?>> WhatsApp</label>
                <p class="description">Por qué canales quieres recibir TÚ los avisos de instalaciones (materiales pendientes, instalación en marcha, etc.). Si no tocas esto, se usa el valor por defecto de Ajustes/Panel de control.</p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('personal_options_update', 'crm_admin_guardar_whatsapp_profile_field');
add_action('edit_user_profile_update', 'crm_admin_guardar_whatsapp_profile_field');
function crm_admin_guardar_whatsapp_profile_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }
    if (isset($_POST['crm_whatsapp'])) {
        $whatsapp = sanitize_text_field(wp_unslash($_POST['crm_whatsapp']));
        if ($whatsapp === '' || preg_match('/^[0-9+\s()-]{6,20}$/', $whatsapp)) {
            // Mismo criterio que el perfil del instalador: si no parece un teléfono, no se guarda nada raro.
            update_user_meta($user_id, 'crm_whatsapp', $whatsapp);
        }
    }
    // v1.20.126: solo se guardan estas 3 casillas si el formulario que se
    // envió es el del perfil de wp-admin (trae 'crm_whatsapp' como marcador
    // de que este bloque estaba presente) — así un guardado de otro
    // formulario que no incluya estos campos no los resetea a "desmarcado".
    if (isset($_POST['crm_whatsapp'])) {
        foreach (['inapp', 'email', 'whatsapp'] as $canal) {
            update_user_meta($user_id, 'crm_notif_canal_' . $canal, !empty($_POST['crm_notif_canal_' . $canal]) ? '1' : '0');
        }
    }
}

