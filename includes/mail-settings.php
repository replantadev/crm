<?php
/**
 * Canales de email del CRM: aviso a proveedores y aviso a instaladores
 * (v1.20.103).
 *
 * El usuario reportó que, probando "Notificar proveedor" y los avisos a
 * instaladores, no llegaba ningún email — auditado el código (crm-plugin.php,
 * includes/instalaciones.php, includes/notifications.php): TODO el envío de
 * email del plugin pasa por `wp_mail()` sin ninguna configuración SMTP propia,
 * dependiendo enteramente de `mail()` del servidor — la causa más común de que
 * un email nunca llegue (o caiga en spam) en hosting compartido.
 *
 * Se añaden 2 canales configurables — remitente propio + SMTP opcional por
 * canal, porque el usuario pidió poder usar una cuenta distinta para avisar a
 * proveedores (Santoki, etc.) que para avisar a instaladores. Gestionable
 * desde wp-admin (CRM → Email, solo `administrator`/webmaster) Y
 * desde el frontend (`/panel-de-control/`, `crm_admin`) — mismo formulario,
 * misma función de guardado, para no mantener dos versiones.
 *
 * Cada envío por estos canales queda en el log de actividad
 * (`crm_log_action()`, visible en wp-admin → CRM → Logs y en el "Registro de
 * actividades" de `/panel-de-control/`) — éxito o fallo, con el motivo real
 * si Holded... si SMTP lo da.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canales de email soportados. Añadir uno nuevo aquí es lo único que hace
 * falta para que aparezca en ambos formularios (wp-admin y frontend).
 *
 * @return array<string,string> slug => etiqueta
 */
function crm_mail_canales() {
    return [
        'proveedor'  => 'Aviso a proveedores (pedidos de material a Santoki y otros)',
        'instalador' => 'Aviso a instaladores (asignación, visita programada/reprogramada)',
    ];
}

/**
 * v1.20.106 — qué evento concreto del CRM dispara cada canal hoy. No hay
 * ningún interruptor por evento (activar/desactivar uno suelto) — esto es
 * solo para que quede visible de un vistazo qué está conectado, sin tener
 * que ir a buscarlo en el código. Si se conecta un evento nuevo a un canal,
 * añadirlo aquí en el mismo commit.
 *
 * @return array<string,string[]> slug de canal => lista de eventos
 */
function crm_mail_eventos_por_canal() {
    return [
        'proveedor'  => [
            '"Notificar proveedor" al pedir material pendiente (ficha de instalación)',
        ],
        'instalador' => [
            'Instalador asignado a una instalación',
            'Visita programada o reprogramada en la agenda',
        ],
    ];
}

/**
 * Últimos envíos por estos canales (`crm_log_action('email_<canal>', ...)`,
 * ver `crm_mail_enviar()`), leídos del mismo log de actividad general del
 * CRM — sin tabla ni vista aparte.
 *
 * @param int $limite
 * @return array<int,array{created_at:string,details:string,level:string}>
 */
function crm_mail_log_reciente($limite = 30) {
    global $wpdb;
    if (!function_exists('crm_get_available_log_months')) {
        return [];
    }
    $canales_validos = array_map(function ($c) { return 'email_' . $c; }, array_keys(crm_mail_canales()));
    $filas = [];
    foreach (crm_get_available_log_months() as $mes) {
        $tabla = $wpdb->prefix . 'crm_activity_log_' . $mes['value'];
        $placeholders = implode(',', array_fill(0, count($canales_validos), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, details, level FROM {$tabla} WHERE action_type IN ({$placeholders}) ORDER BY created_at DESC LIMIT %d",
            array_merge($canales_validos, [$limite])
        ), ARRAY_A);
        if ($rows) {
            $filas = array_merge($filas, $rows);
        }
        if (count($filas) >= $limite) {
            break; // Los meses ya vienen del más reciente al más antiguo.
        }
    }
    usort($filas, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    return array_slice($filas, 0, $limite);
}

/**
 * Configuración de un canal, con valores por defecto.
 *
 * @param string $canal
 * @return array{from_name:string,from_email:string,smtp_enabled:bool,smtp_host:string,smtp_port:int,smtp_user:string,smtp_pass:string,smtp_secure:string}
 */
function crm_mail_get_config($canal) {
    $canal    = sanitize_key($canal);
    $defaults = [
        'from_name'    => '',
        'from_email'   => '',
        'smtp_enabled' => false,
        'smtp_host'    => '',
        'smtp_port'    => 587,
        'smtp_user'    => '',
        'smtp_pass'    => '',
        'smtp_secure'  => 'tls',
    ];
    $guardado = get_option('crm_mail_cfg_' . $canal, []);
    return array_merge($defaults, is_array($guardado) ? $guardado : []);
}

/**
 * Guarda la configuración de un canal. La contraseña SMTP nunca se muestra de
 * vuelta en el formulario (mismo patrón que `crm_holded_api_key`/WhatsApp
 * token) — dejar el campo en blanco al guardar conserva la que ya había.
 *
 * @param string $canal
 * @param array  $input Datos crudos de $_POST (ya con wp_unslash aplicado).
 */
function crm_mail_save_config($canal, array $input) {
    $canal  = sanitize_key($canal);
    $actual = crm_mail_get_config($canal);

    $pass_nueva = trim((string) ($input['smtp_pass'] ?? ''));
    $secure     = (string) ($input['smtp_secure'] ?? 'tls');

    $nuevo = [
        'from_name'    => sanitize_text_field((string) ($input['from_name'] ?? '')),
        'from_email'   => sanitize_email((string) ($input['from_email'] ?? '')),
        'smtp_enabled' => !empty($input['smtp_enabled']),
        'smtp_host'    => sanitize_text_field((string) ($input['smtp_host'] ?? '')),
        'smtp_port'    => max(1, (int) ($input['smtp_port'] ?? 587)),
        'smtp_user'    => sanitize_text_field((string) ($input['smtp_user'] ?? '')),
        'smtp_pass'    => $pass_nueva !== '' ? $pass_nueva : $actual['smtp_pass'],
        'smtp_secure'  => in_array($secure, ['none', 'ssl', 'tls'], true) ? $secure : 'tls',
    ];

    update_option('crm_mail_cfg_' . $canal, $nuevo, false);
}

/**
 * Envía un email por uno de los canales configurados — punto único que
 * deben usar todos los avisos a proveedor/instalador en vez de `wp_mail()`
 * directo, para que el remitente/SMTP configurado se respete y quede
 * registrado en el log.
 *
 * El SMTP, si está activado, solo se aplica a ESTE envío concreto (el hook
 * `phpmailer_init` se añade y se quita en la misma llamada) — nunca cambia
 * el envío de email del resto del sitio/otros plugins.
 *
 * @param string $canal
 * @param string $to
 * @param string $subject
 * @param string $body_html
 * @param array  $extra_headers
 * @return bool
 */
function crm_mail_enviar($canal, $to, $subject, $body_html, array $extra_headers = []) {
    $canal = sanitize_key($canal);
    $cfg   = crm_mail_get_config($canal);

    $from_email = $cfg['from_email'] !== '' ? $cfg['from_email'] : get_option('admin_email');
    $from_name  = $cfg['from_name']  !== '' ? $cfg['from_name']  : get_option('blogname');

    $headers = array_merge([
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
    ], $extra_headers);

    $usa_smtp = !empty($cfg['smtp_enabled']) && $cfg['smtp_host'] !== '';

    $configurar_smtp = function ($phpmailer) use ($cfg, $from_email, $from_name) {
        $phpmailer->isSMTP();
        $phpmailer->Host     = $cfg['smtp_host'];
        $phpmailer->Port     = (int) $cfg['smtp_port'];
        $phpmailer->SMTPAuth = $cfg['smtp_user'] !== '';
        $phpmailer->Username = $cfg['smtp_user'];
        $phpmailer->Password = $cfg['smtp_pass'];
        if ($cfg['smtp_secure'] === 'none') {
            $phpmailer->SMTPSecure  = '';
            $phpmailer->SMTPAutoTLS = false;
        } else {
            $phpmailer->SMTPSecure = $cfg['smtp_secure']; // 'tls' o 'ssl'
        }
        $phpmailer->setFrom($from_email, $from_name);
    };

    $error_capturado = null;
    $capturar_error  = function ($wp_error) use (&$error_capturado) {
        $error_capturado = is_wp_error($wp_error) ? $wp_error->get_error_message() : 'Error desconocido.';
    };

    if ($usa_smtp) {
        add_action('phpmailer_init', $configurar_smtp);
    }
    add_action('wp_mail_failed', $capturar_error);

    $enviado = wp_mail($to, $subject, $body_html, $headers);

    remove_action('wp_mail_failed', $capturar_error);
    if ($usa_smtp) {
        remove_action('phpmailer_init', $configurar_smtp);
    }

    if (function_exists('crm_log_action')) {
        $detalle = ($enviado ? 'Email enviado' : 'Fallo al enviar email') . ' [canal: ' . $canal . '] a ' . $to . ' — «' . $subject . '»';
        if (!$enviado && $error_capturado) {
            $detalle .= ' — ' . $error_capturado;
        }
        crm_log_action(
            'email_' . $canal,
            $detalle,
            null,
            null,
            $enviado ? 'info' : 'error',
            ['canal' => $canal, 'to' => $to, 'subject' => $subject, 'smtp_usado' => $usa_smtp, 'error' => $error_capturado]
        );
    }

    return (bool) $enviado;
}

/**
 * Formulario de un canal (remitente + SMTP + prueba) — mismo render y mismo
 * guardado desde wp-admin y desde el frontend, para no mantener dos copias.
 *
 * @param string $canal
 */
function crm_mail_settings_render_canal($canal) {
    $canal   = sanitize_key($canal);
    $canales = crm_mail_canales();
    if (!isset($canales[$canal])) {
        return;
    }

    $nonce_action = 'crm_mail_guardar_' . $canal;
    if (isset($_POST['crm_mail_guardar_canal']) && $_POST['crm_mail_guardar_canal'] === $canal
        && wp_verify_nonce($_POST['crm_mail_nonce'] ?? '', $nonce_action)
        && current_user_can('crm_admin')
    ) {
        crm_mail_save_config($canal, wp_unslash($_POST));
        echo '<div style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:10px;font-size:13px;">Configuración de «' . esc_html($canales[$canal]) . '» guardada.</div>';
    }

    $cfg    = crm_mail_get_config($canal);
    $eventos = crm_mail_eventos_por_canal()[$canal] ?? [];
    ?>
    <div class="crm-mail-canal" style="margin-bottom:22px;padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
        <h4 style="margin:0 0 10px;"><?php echo esc_html($canales[$canal]); ?></h4>
        <?php if (!empty($eventos)) : ?>
            <p style="font-size:12.5px;color:#6b7280;margin:0 0 12px;">
                Envía email cuando:
                <?php foreach ($eventos as $i => $ev) : ?>
                    <?php echo $i > 0 ? ' · ' : ' '; ?><?php echo esc_html($ev); ?>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <p style="font-size:12.5px;margin:0 0 12px;">
            Estado actual:
            <?php if (!empty($cfg['smtp_enabled']) && $cfg['smtp_host'] !== '') : ?>
                <span style="color:#065f46;font-weight:600;">SMTP propio activo</span> (<?php echo esc_html($cfg['smtp_host']); ?>:<?php echo esc_html($cfg['smtp_port']); ?>)
            <?php else : ?>
                <span style="color:#92400e;font-weight:600;">Envío por defecto del servidor</span> (activa "Usar SMTP propio" abajo si no está llegando)
            <?php endif; ?>
            <?php if ($cfg['from_email'] !== '') : ?>
                — remitente: <?php echo esc_html($cfg['from_name'] !== '' ? $cfg['from_name'] . ' <' . $cfg['from_email'] . '>' : $cfg['from_email']); ?>
            <?php endif; ?>
        </p>
        <form method="post">
            <?php wp_nonce_field($nonce_action, 'crm_mail_nonce'); ?>
            <input type="hidden" name="crm_mail_guardar_canal" value="<?php echo esc_attr($canal); ?>">
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:4px 8px 4px 0;width:170px;">Remitente (nombre)</td>
                    <td style="padding:4px 0;"><input type="text" name="from_name" value="<?php echo esc_attr($cfg['from_name']); ?>" class="regular-text" style="width:100%;max-width:320px;box-sizing:border-box;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;" placeholder="<?php echo esc_attr(get_option('blogname')); ?>"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Remitente (email)</td>
                    <td style="padding:4px 0;"><input type="email" name="from_email" value="<?php echo esc_attr($cfg['from_email']); ?>" class="regular-text" style="width:100%;max-width:320px;box-sizing:border-box;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Usar SMTP propio</td>
                    <td style="padding:4px 0;"><label><input type="checkbox" name="smtp_enabled" value="1" <?php checked(!empty($cfg['smtp_enabled'])); ?>> Activar (si no, se usa el envío por defecto del servidor)</label></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Servidor SMTP</td>
                    <td style="padding:4px 0;"><input type="text" name="smtp_host" value="<?php echo esc_attr($cfg['smtp_host']); ?>" class="regular-text" style="width:100%;max-width:320px;box-sizing:border-box;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;" placeholder="smtp.tuproveedor.com"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Puerto</td>
                    <td style="padding:4px 0;"><input type="number" name="smtp_port" value="<?php echo esc_attr($cfg['smtp_port']); ?>" style="width:90px;"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Usuario SMTP</td>
                    <td style="padding:4px 0;"><input type="text" name="smtp_user" value="<?php echo esc_attr($cfg['smtp_user']); ?>" class="regular-text" style="width:100%;max-width:320px;box-sizing:border-box;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;" autocomplete="off"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Contraseña SMTP</td>
                    <td style="padding:4px 0;"><input type="password" name="smtp_pass" value="" class="regular-text" style="width:100%;max-width:320px;box-sizing:border-box;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;" placeholder="<?php echo $cfg['smtp_pass'] !== '' ? '••••••• (sin cambios si lo dejas en blanco)' : ''; ?>" autocomplete="new-password"></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px 4px 0;">Cifrado</td>
                    <td style="padding:4px 0;">
                        <select name="smtp_secure">
                            <option value="tls" <?php selected($cfg['smtp_secure'], 'tls'); ?>>TLS (recomendado, puerto 587)</option>
                            <option value="ssl" <?php selected($cfg['smtp_secure'], 'ssl'); ?>>SSL (puerto 465)</option>
                            <option value="none" <?php selected($cfg['smtp_secure'], 'none'); ?>>Ninguno</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="crm-btn">Guardar</button></p>
        </form>
        <p style="border-top:1px solid #f1f5f9;padding-top:10px;margin-top:4px;">
            <input type="email" class="crm-mail-test-to" data-canal="<?php echo esc_attr($canal); ?>" placeholder="email de prueba" style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;">
            <button type="button" class="crm-btn crm-mail-test-btn" data-canal="<?php echo esc_attr($canal); ?>">Enviar prueba</button>
            <span class="crm-mail-test-msg" data-canal="<?php echo esc_attr($canal); ?>" style="font-size:12.5px;margin-left:6px;"></span>
        </p>
    </div>
    <?php
}

/**
 * Tabla de los últimos envíos por estos canales — mismo log de actividad de
 * siempre, solo filtrado. v1.20.106, pedido explícitamente para no tener que
 * ir a buscarlo a los Logs generales de wp-admin (que un crm_admin frontend
 * no puede visitar de todos modos).
 */
function crm_mail_settings_render_log() {
    $filas = crm_mail_log_reciente(30);
    ?>
    <div class="crm-mail-canal" style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
        <h4 style="margin:0 0 10px;">Últimos envíos</h4>
        <?php if (empty($filas)) : ?>
            <p style="font-size:13px;color:#6b7280;">Todavía no se ha enviado ningún email por estos canales.</p>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                    <thead>
                        <tr style="text-align:left;color:#6b7280;">
                            <th style="padding:4px 8px 4px 0;">Fecha</th>
                            <th style="padding:4px 8px 4px 0;">Resultado</th>
                            <th style="padding:4px 0;">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filas as $fila) : ?>
                            <tr style="border-top:1px solid #f1f5f9;">
                                <td style="padding:5px 8px 5px 0;white-space:nowrap;color:#6b7280;"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($fila['created_at']))); ?></td>
                                <td style="padding:5px 8px 5px 0;white-space:nowrap;">
                                    <?php if ($fila['level'] === 'error') : ?>
                                        <span style="color:#991b1b;font-weight:600;">Fallo</span>
                                    <?php else : ?>
                                        <span style="color:#065f46;font-weight:600;">Enviado</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:5px 0;"><?php echo esc_html($fila['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Los 2 formularios + la tabla de últimos envíos + el JS del botón "Enviar
 * prueba" — usado tal cual tanto en wp-admin como en el panel frontend.
 */
function crm_mail_settings_render_todo() {
    foreach (array_keys(crm_mail_canales()) as $canal) {
        crm_mail_settings_render_canal($canal);
    }
    crm_mail_settings_render_log();
    ?>
    <script>
    (function () {
        document.querySelectorAll('.crm-mail-test-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var canal = btn.getAttribute('data-canal');
                var input = document.querySelector('.crm-mail-test-to[data-canal="' + canal + '"]');
                var msg   = document.querySelector('.crm-mail-test-msg[data-canal="' + canal + '"]');
                var to    = input ? input.value.trim() : '';
                if (!to) {
                    if (msg) { msg.style.color = '#991b1b'; msg.textContent = 'Escribe un email primero.'; }
                    return;
                }
                btn.disabled = true;
                if (msg) { msg.style.color = '#6b7280'; msg.textContent = 'Enviando…'; }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', (window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>'));
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function () {
                    btn.disabled = false;
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (msg) {
                            msg.style.color = resp.success ? '#065f46' : '#991b1b';
                            msg.textContent = (resp.data && resp.data.message) ? resp.data.message : (resp.success ? 'Enviado.' : 'Error.');
                        }
                    } catch (e) {
                        if (msg) { msg.style.color = '#991b1b'; msg.textContent = 'Error de conexión.'; }
                    }
                };
                xhr.onerror = function () {
                    btn.disabled = false;
                    if (msg) { msg.style.color = '#991b1b'; msg.textContent = 'Error de conexión.'; }
                };
                xhr.send('action=crm_mail_test_canal&nonce=<?php echo esc_js(wp_create_nonce('crm_admin_actions')); ?>&canal=' + encodeURIComponent(canal) + '&to=' + encodeURIComponent(to));
            });
        });
    })();
    </script>
    <?php
}

/**
 * AJAX: botón "Enviar prueba" de cada canal.
 */
add_action('wp_ajax_crm_mail_test_canal', 'crm_mail_ajax_test_canal');
function crm_mail_ajax_test_canal() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_admin_actions', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $canal = sanitize_key($_POST['canal'] ?? '');
    $to    = sanitize_email($_POST['to'] ?? '');
    $canales = crm_mail_canales();

    if (!isset($canales[$canal])) {
        wp_send_json_error(['message' => 'Canal no válido.']);
    }
    if ($to === '') {
        wp_send_json_error(['message' => 'Email no válido.']);
    }

    $enviado = crm_mail_enviar(
        $canal,
        $to,
        'Prueba de envío — ' . $canales[$canal],
        '<p>Esto es una prueba del canal <strong>' . esc_html($canales[$canal]) . '</strong> del CRM.</p><p>Si has recibido este correo, la configuración de este canal funciona.</p>'
    );

    if ($enviado) {
        wp_send_json_success(['message' => 'Enviado a ' . $to . ' — revisa también la carpeta de spam.']);
    }
    wp_send_json_error(['message' => 'No se pudo enviar. Revisa la configuración SMTP de este canal (o el log de actividad para el detalle del error).']);
}

/**
 * wp-admin → CRM → Email (solo administrator/webmaster).
 *
 * v1.20.105: el slug/título "Notificaciones" ya lo usaba
 * `includes/notificaciones-inapp.php` (historial de notificaciones in-app,
 * una cosa completamente distinta) — mismo `add_submenu_page('crm-dashboard',
 * 'Notificaciones', ..., 'crm-notificaciones', ...)`, así que el menú salía
 * duplicado (dos entradas "Notificaciones" en la barra lateral). Renombrado
 * a "Email" / slug `crm-email` para no chocar.
 */
add_action('admin_menu', function () {
    add_submenu_page('crm-dashboard', 'Email — proveedores e instaladores', 'Email', 'crm_admin', 'crm-email', 'crm_mail_render_admin_page');
});
function crm_mail_render_admin_page() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }
    crm_admin_page_header('CRM · Notificaciones por email');
    ?>
    <p style="max-width:820px;color:#555;">Remitente y (opcionalmente) SMTP propio para los avisos a proveedores e instaladores. Si "Usar SMTP propio" está desactivado, se usa el envío por defecto del servidor.</p>
    <?php crm_mail_settings_render_todo(); ?>
    <?php
    crm_admin_page_footer();
}

/**
 * Roadmap de los canales de email (v1.20.103) — ver includes/flujos-page.php.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Notificaciones',
        'titulo'  => 'Canales de email propios para avisos a proveedores e instaladores (remitente + SMTP)',
        'estado'  => 'en_pruebas',
        'detalle' => 'El usuario reportó que ningún email de aviso llegaba — causa: todo el envío del plugin dependía del wp_mail() por defecto del servidor, sin ninguna configuración SMTP. Ahora hay 2 canales configurables (wp-admin → CRM → Email, y /panel-de-control/), con botón de prueba, lista de qué evento dispara cada canal, y tabla de últimos envíos — todo en el mismo sitio. Confirmado por el usuario: el envío de prueba de cada canal SÍ llega con SMTP configurado. Bug real corregido en v1.20.106: esta sección nunca se veía en /panel-de-control/ (el archivo solo se cargaba dentro de wp-admin). Pendiente de confirmar los envíos reales conectados (notificar proveedor, instalador asignado/visita programada).',
    ];
    return $fases;
});
