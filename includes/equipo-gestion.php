<?php
/**
 * Gestión de equipo desde el frontend: alta de comerciales/instaladores +
 * edición de su ficha (nombre, email, WhatsApp), sin pasar por wp-admin →
 * Usuarios (v1.20.109).
 *
 * El usuario pidió esto explícitamente: crm_admin opera enteramente desde el
 * frontend (ver [[project-instalaciones-plan]], "crm_admin es un usuario de
 * frontend, no de wp-admin") y hasta ahora dar de alta un comercial o
 * instalador exigía entrar a Usuarios → Añadir nuevo en wp-admin, bloqueado
 * para ese rol por `admin-lockdown.php`.
 *
 * Reutiliza el mismo user-meta `crm_whatsapp` que ya usa el instalador en su
 * "Mi perfil" (`includes/instalador-panel.php`) y el jefe/crm_admin en
 * wp-admin/profile.php (`includes/admin-page.php`) — es el mismo dato de
 * perfil, no uno nuevo por rol.
 *
 * Pensado para vivir en la página "Equipo" (slug `resumen`) junto a las
 * estadísticas ya existentes — paso manual pendiente: añadir el shortcode
 * [crm_equipo_gestion] al contenido de esa página (page-bootstrap.php nunca
 * reescribe una página que ya existe).
 *
 * v1.20.110: el email de alta ("pon tu contraseña") ya NO usa el
 * `wp_new_user_notification()` genérico de WordPress — se envía por el canal
 * del propio rol (`comercial`/`instalador`, `includes/mail-settings.php`),
 * mismo slug que el rol, para respetar el remitente/SMTP configurado ahí.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Roles que se pueden dar de alta/editar desde esta pantalla — deliberadamente
 * solo estos dos, nunca crm_admin/administrator/jefe_instalaciones a través
 * de este formulario.
 *
 * @return array<string,string> slug de rol => etiqueta
 */
function crm_equipo_roles_gestionables() {
    return [
        'comercial'  => 'Comercial',
        'instalador' => 'Instalador',
    ];
}

/**
 * Todos los usuarios de un rol gestionable, con su WhatsApp.
 *
 * @param string $rol
 * @return array<int,array{id:int,nombre:string,email:string,whatsapp:string}>
 */
function crm_equipo_listar_usuarios($rol) {
    if (!isset(crm_equipo_roles_gestionables()[$rol])) {
        return [];
    }
    $usuarios = get_users(['role' => $rol, 'orderby' => 'display_name', 'order' => 'ASC']);
    return array_map(function ($u) {
        return [
            'id'       => (int) $u->ID,
            'nombre'   => $u->display_name,
            'email'    => $u->user_email,
            'whatsapp' => (string) get_user_meta($u->ID, 'crm_whatsapp', true),
        ];
    }, $usuarios);
}

/**
 * [crm_equipo_gestion] — alta + edición de comerciales/instaladores.
 */
add_shortcode('crm_equipo_gestion', 'crm_equipo_gestion_widget');
function crm_equipo_gestion_widget() {
    if (!current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }

    $roles = crm_equipo_roles_gestionables();
    $nonce = wp_create_nonce('crm_equipo_gestion');

    ob_start();
    ?>
    <style>
    .crm-equipo-wrap { display: flex; flex-direction: column; gap: 20px; margin-bottom: 24px; }
    .crm-equipo-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; }
    .crm-equipo-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; }
    .crm-equipo-card h4 { margin: 0 0 4px; font-size: 15px; }
    .crm-equipo-card p.crm-equipo-hint { margin: 0 0 12px; font-size: 12.5px; color: #6b7280; }
    .crm-equipo-form label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin: 8px 0 3px; }
    .crm-equipo-form input[type="text"], .crm-equipo-form input[type="email"] { width: 100%; box-sizing: border-box; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13.5px; }
    .crm-equipo-form-msg { display: block; margin-top: 8px; font-size: 12.5px; }
    .crm-equipo-lista-item { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .crm-equipo-lista-item:last-child { border-bottom: none; }
    .crm-equipo-lista-nombre { font-weight: 600; color: #1f2937; min-width: 140px; }
    .crm-equipo-lista-meta { color: #6b7280; font-size: 12.5px; }
    .crm-equipo-editar-btn { margin-left: auto; }
    .crm-equipo-edit-form { display: none; width: 100%; background: #f9fafb; border-radius: 8px; padding: 12px; margin-top: 6px; }
    .crm-equipo-edit-form.is-open { display: block; }
    </style>

    <div class="crm-equipo-wrap">
        <div class="crm-equipo-col">
            <?php foreach ($roles as $rol_slug => $rol_label) : ?>
                <div class="crm-equipo-card">
                    <h4>Añadir <?php echo esc_html(mb_strtolower($rol_label)); ?></h4>
                    <p class="crm-equipo-hint">Se crea el acceso y se le envía un email para que ponga su propia contraseña.</p>
                    <div class="crm-equipo-form" data-rol="<?php echo esc_attr($rol_slug); ?>">
                        <label>Nombre</label>
                        <input type="text" class="crm-equipo-nuevo-nombre" placeholder="Nombre y apellidos">
                        <label>Email</label>
                        <input type="email" class="crm-equipo-nuevo-email" placeholder="email@ejemplo.com">
                        <label>WhatsApp (opcional)</label>
                        <input type="text" class="crm-equipo-nuevo-whatsapp" placeholder="+34600000000">
                        <p style="margin:12px 0 0;">
                            <button type="button" class="crm-btn crm-equipo-crear-btn" data-rol="<?php echo esc_attr($rol_slug); ?>">Crear <?php echo esc_html(mb_strtolower($rol_label)); ?></button>
                        </p>
                        <span class="crm-equipo-form-msg"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="crm-equipo-col">
            <?php foreach ($roles as $rol_slug => $rol_label) :
                $usuarios = crm_equipo_listar_usuarios($rol_slug);
            ?>
                <div class="crm-equipo-card">
                    <h4><?php echo esc_html($rol_label); ?>s (<?php echo count($usuarios); ?>)</h4>
                    <?php if (empty($usuarios)) : ?>
                        <p class="crm-equipo-hint">Todavía no hay ningún usuario con este rol.</p>
                    <?php else : ?>
                        <?php foreach ($usuarios as $u) : ?>
                            <div class="crm-equipo-lista-item" data-user-id="<?php echo esc_attr($u['id']); ?>">
                                <span class="crm-equipo-lista-nombre"><?php echo esc_html($u['nombre']); ?></span>
                                <span class="crm-equipo-lista-meta"><?php echo esc_html($u['email']); ?><?php echo $u['whatsapp'] !== '' ? ' · ' . esc_html($u['whatsapp']) : ''; ?></span>
                                <button type="button" class="crm-btn crm-equipo-editar-btn" data-user-id="<?php echo esc_attr($u['id']); ?>">Editar</button>
                                <div class="crm-equipo-edit-form" id="crm-equipo-edit-<?php echo esc_attr($u['id']); ?>">
                                    <label>Nombre</label>
                                    <input type="text" class="crm-equipo-edit-nombre" value="<?php echo esc_attr($u['nombre']); ?>">
                                    <label>Email</label>
                                    <input type="email" class="crm-equipo-edit-email" value="<?php echo esc_attr($u['email']); ?>">
                                    <label>WhatsApp</label>
                                    <input type="text" class="crm-equipo-edit-whatsapp" value="<?php echo esc_attr($u['whatsapp']); ?>" placeholder="+34600000000">
                                    <p style="margin:10px 0 0;">
                                        <button type="button" class="crm-btn crm-equipo-guardar-btn" data-user-id="<?php echo esc_attr($u['id']); ?>">Guardar</button>
                                        <span class="crm-equipo-form-msg"></span>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    (function () {
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce   = <?php echo wp_json_encode($nonce); ?>;

        document.querySelectorAll('.crm-equipo-crear-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var card   = btn.closest('.crm-equipo-form');
                var rol    = btn.getAttribute('data-rol');
                var nombre = card.querySelector('.crm-equipo-nuevo-nombre').value.trim();
                var email  = card.querySelector('.crm-equipo-nuevo-email').value.trim();
                var wa     = card.querySelector('.crm-equipo-nuevo-whatsapp').value.trim();
                var msg    = card.querySelector('.crm-equipo-form-msg');
                if (!nombre || !email) {
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Rellena al menos nombre y email.';
                    return;
                }
                btn.disabled = true;
                msg.style.color = '#6b7280';
                msg.textContent = 'Creando…';
                var body = new URLSearchParams();
                body.set('action', 'crm_equipo_crear_usuario');
                body.set('nonce', nonce);
                body.set('rol', rol);
                body.set('nombre', nombre);
                body.set('email', email);
                body.set('whatsapp', wa);
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        btn.disabled = false;
                        if (!resp.success) {
                            msg.style.color = '#991b1b';
                            msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                            return;
                        }
                        if (resp.data && resp.data.email_enviado) {
                            msg.style.color = '#065f46';
                            msg.textContent = 'Creado — se le ha enviado un email para poner su contraseña.';
                        } else {
                            msg.style.color = '#92400e';
                            msg.textContent = 'Creado, pero el email para poner la contraseña no se pudo enviar — revisa la configuración de este canal en Ajustes → Email.';
                        }
                        setTimeout(function () { location.reload(); }, 1800);
                    })
                    .catch(function () {
                        btn.disabled = false;
                        msg.style.color = '#991b1b';
                        msg.textContent = 'Error de conexión.';
                    });
            });
        });

        document.addEventListener('click', function (e) {
            var editBtn = e.target.closest && e.target.closest('.crm-equipo-editar-btn');
            if (editBtn) {
                var form = document.getElementById('crm-equipo-edit-' + editBtn.getAttribute('data-user-id'));
                if (form) { form.classList.toggle('is-open'); }
                return;
            }
            var guardarBtn = e.target.closest && e.target.closest('.crm-equipo-guardar-btn');
            if (guardarBtn) {
                var userId = guardarBtn.getAttribute('data-user-id');
                var wrap   = document.getElementById('crm-equipo-edit-' + userId);
                var nombre = wrap.querySelector('.crm-equipo-edit-nombre').value.trim();
                var email  = wrap.querySelector('.crm-equipo-edit-email').value.trim();
                var wa     = wrap.querySelector('.crm-equipo-edit-whatsapp').value.trim();
                var msg    = wrap.querySelector('.crm-equipo-form-msg');
                if (!nombre || !email) {
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Nombre y email no pueden quedar vacíos.';
                    return;
                }
                guardarBtn.disabled = true;
                msg.style.color = '#6b7280';
                msg.textContent = 'Guardando…';
                var body = new URLSearchParams();
                body.set('action', 'crm_equipo_editar_usuario');
                body.set('nonce', nonce);
                body.set('user_id', userId);
                body.set('nombre', nombre);
                body.set('email', email);
                body.set('whatsapp', wa);
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        guardarBtn.disabled = false;
                        if (!resp.success) {
                            msg.style.color = '#991b1b';
                            msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                            return;
                        }
                        msg.style.color = '#065f46';
                        msg.textContent = 'Guardado.';
                        setTimeout(function () { location.reload(); }, 900);
                    })
                    .catch(function () {
                        guardarBtn.disabled = false;
                        msg.style.color = '#991b1b';
                        msg.textContent = 'Error de conexión.';
                    });
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Valida un número de teléfono con el mismo criterio que ya usan "Mi perfil"
 * del instalador y el campo de wp-admin — vacío es válido (no todos lo
 * rellenan), cualquier otra cosa debe parecer un teléfono de verdad.
 *
 * @param string $whatsapp
 * @return bool
 */
function crm_equipo_whatsapp_valido($whatsapp) {
    return $whatsapp === '' || (bool) preg_match('/^[0-9+\s()-]{6,20}$/', $whatsapp);
}

/**
 * AJAX: crear un comercial/instalador nuevo. Contraseña aleatoria + el email
 * estándar de WordPress con enlace para que el propio usuario la establezca
 * (`wp_new_user_notification()`, núcleo — no pasa por los canales SMTP de
 * proveedor/instalador, es el email de alta de cuenta, no un aviso de
 * negocio).
 */
add_action('wp_ajax_crm_equipo_crear_usuario', 'crm_equipo_ajax_crear_usuario');
function crm_equipo_ajax_crear_usuario() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_equipo_gestion', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $roles  = crm_equipo_roles_gestionables();
    $rol    = sanitize_key($_POST['rol'] ?? '');
    $nombre = sanitize_text_field(wp_unslash($_POST['nombre'] ?? ''));
    $email  = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $whatsapp = sanitize_text_field(wp_unslash($_POST['whatsapp'] ?? ''));

    if (!isset($roles[$rol])) {
        wp_send_json_error(['message' => 'Rol no válido.']);
    }
    if ($nombre === '' || !is_email($email)) {
        wp_send_json_error(['message' => 'Rellena un nombre y un email válido.']);
    }
    if (email_exists($email)) {
        wp_send_json_error(['message' => 'Ya existe un usuario con ese email.']);
    }
    if (!crm_equipo_whatsapp_valido($whatsapp)) {
        wp_send_json_error(['message' => 'Ese WhatsApp no parece un teléfono válido.']);
    }

    // Username único a partir del email (mismo criterio que WordPress usa al
    // dar de alta desde wp-admin: local-part del email, con sufijo si choca).
    $partes_email = explode('@', $email);
    $base_login   = sanitize_user($partes_email[0], true);
    if ($base_login === '') {
        $base_login = 'usuario';
    }
    $login = $base_login;
    $intento = 1;
    while (username_exists($login)) {
        $intento++;
        $login = $base_login . $intento;
    }

    $user_id = wp_insert_user([
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password(20, true),
        'display_name' => $nombre,
        'first_name'   => $nombre,
        'role'         => $rol,
    ]);

    if (is_wp_error($user_id)) {
        wp_send_json_error(['message' => 'No se pudo crear: ' . $user_id->get_error_message()]);
    }

    if ($whatsapp !== '') {
        update_user_meta($user_id, 'crm_whatsapp', $whatsapp);
    }

    // v1.20.110: por el canal del propio rol (mismo slug 'comercial'/'instalador'
    // en crm_mail_canales()), no por el wp_mail() genérico de WordPress — así
    // respeta el remitente/SMTP que se haya configurado para ese canal.
    $email_enviado = crm_equipo_enviar_alta_cuenta($user_id, $rol);

    if (function_exists('crm_log_action')) {
        crm_log_action('equipo_alta', 'Alta de ' . $roles[$rol] . ': ' . $nombre . ' (' . $email . ')', null, null, 'info');
    }

    wp_send_json_success(['user_id' => $user_id, 'email_enviado' => $email_enviado]);
}

/**
 * Email de alta de cuenta ("pon tu contraseña"), enviado por el canal del
 * propio rol en vez del `wp_new_user_notification()` genérico de
 * WordPress — mismo mecanismo de enlace que usa el núcleo
 * (`get_password_reset_key()` + `action=rp` en wp-login.php), pero pasando
 * por `crm_mail_enviar()` para respetar el remitente/SMTP del canal.
 *
 * @param int    $user_id
 * @param string $canal 'comercial'|'instalador' (mismo slug que el rol).
 * @return bool
 */
function crm_equipo_enviar_alta_cuenta($user_id, $canal) {
    $user = get_userdata($user_id);
    if (!$user || !function_exists('crm_mail_enviar')) {
        return false;
    }

    $key = get_password_reset_key($user);
    if (is_wp_error($key)) {
        return false;
    }

    $reset_url = network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user->user_login), 'login');
    $site_name = get_option('blogname');

    $body  = '<p>Hola ' . esc_html($user->display_name) . ',</p>';
    $body .= '<p>Se ha creado tu acceso a ' . esc_html($site_name) . '.</p>';
    $body .= '<p>Usuario: <strong>' . esc_html($user->user_login) . '</strong></p>';
    $body .= '<p><a href="' . esc_url($reset_url) . '">Pulsa aquí para establecer tu contraseña</a></p>';
    $body .= '<p style="color:#666;font-size:12px">Si no esperabas este email, puedes ignorarlo — no se ha creado ningún acceso adicional.</p>';

    return crm_mail_enviar($canal, $user->user_email, 'Tu acceso a ' . $site_name, $body);
}

/**
 * AJAX: editar nombre/email/WhatsApp de un comercial/instalador ya existente.
 * Restringido a usuarios que tengan uno de los roles gestionables aquí — no
 * se puede tocar por esta vía a un crm_admin/administrator/jefe_instalaciones,
 * ni cambiar el rol de nadie.
 */
add_action('wp_ajax_crm_equipo_editar_usuario', 'crm_equipo_ajax_editar_usuario');
function crm_equipo_ajax_editar_usuario() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_equipo_gestion', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $user_id  = (int) ($_POST['user_id'] ?? 0);
    $nombre   = sanitize_text_field(wp_unslash($_POST['nombre'] ?? ''));
    $email    = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $whatsapp = sanitize_text_field(wp_unslash($_POST['whatsapp'] ?? ''));

    $user = get_userdata($user_id);
    if (!$user || !array_intersect(array_keys(crm_equipo_roles_gestionables()), (array) $user->roles)) {
        wp_send_json_error(['message' => 'Usuario no válido.']);
    }
    if ($nombre === '' || !is_email($email)) {
        wp_send_json_error(['message' => 'Rellena un nombre y un email válido.']);
    }
    if (!crm_equipo_whatsapp_valido($whatsapp)) {
        wp_send_json_error(['message' => 'Ese WhatsApp no parece un teléfono válido.']);
    }
    $existente = email_exists($email);
    if ($existente && (int) $existente !== $user_id) {
        wp_send_json_error(['message' => 'Ya hay otro usuario con ese email.']);
    }

    $resultado = wp_update_user([
        'ID'           => $user_id,
        'display_name' => $nombre,
        'user_email'   => $email,
    ]);
    if (is_wp_error($resultado)) {
        wp_send_json_error(['message' => 'No se pudo guardar: ' . $resultado->get_error_message()]);
    }

    update_user_meta($user_id, 'crm_whatsapp', $whatsapp);

    if (function_exists('crm_log_action')) {
        crm_log_action('equipo_editar', 'Ficha editada: ' . $nombre . ' (' . $email . ')', null, null, 'info');
    }

    wp_send_json_success([]);
}

/**
 * Roadmap (v1.20.109) — ver includes/flujos-page.php.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Equipo',
        'titulo'  => 'Alta y edición de comerciales/instaladores desde el frontend',
        'estado'  => 'en_pruebas',
        'detalle' => 'Antes, dar de alta un comercial o instalador exigía entrar a wp-admin → Usuarios, bloqueado para crm_admin. Ahora se puede crear (nombre, email, WhatsApp) y editar la ficha de cada uno directamente desde la página "Equipo". El email de alta ("pon tu contraseña") se envía por el canal de email del propio rol (v1.20.110, ver Notificaciones), no por el wp_mail() genérico. Pendiente el paso manual de añadir el shortcode [crm_equipo_gestion] a esa página, y de probar el alta real.',
    ];
    return $fases;
});
