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
    global $wpdb;
    $usuarios = get_users(['role' => $rol, 'orderby' => 'display_name', 'order' => 'ASC']);
    return array_map(function ($u) use ($rol, $wpdb) {
        $fila = [
            'id'       => (int) $u->ID,
            'nombre'   => $u->display_name,
            'email'    => $u->user_email,
            'whatsapp' => (string) get_user_meta($u->ID, 'crm_whatsapp', true),
            // v1.20.156 — interruptor personal de WhatsApp (mismo user-meta
            // que ya usan instaladores/jefes, crm_inst_notif_canal_habilitado())
            // — hasta ahora ningún comercial tenía forma de activarlo.
            'whatsapp_activo' => (string) get_user_meta($u->ID, 'crm_notif_canal_whatsapp', true) === '1',
        ];
        // v1.20.149 — reunión con cliente 2026-09-22, punto 8: "KO" (baja) de
        // un comercial — solo tiene sentido para ese rol, nunca instaladores.
        if ($rol === 'comercial') {
            $fila['ko'] = (bool) get_user_meta($u->ID, 'crm_comercial_ko', true);
            $fila['cartera'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}crm_clients WHERE user_id = %d",
                $u->ID
            ));
        }
        return $fila;
    }, $usuarios);
}

/**
 * Comerciales activos (no KO) — para el desplegable "reasignar a" al
 * repartir la cartera de alguien que se marca como KO. v1.20.149.
 *
 * @param int $excluir_user_id No incluir a este (el que se está dando de baja).
 * @return array<int,array{id:int,nombre:string}>
 */
function crm_equipo_comerciales_activos($excluir_user_id = 0) {
    $usuarios = get_users(['role' => 'comercial', 'orderby' => 'display_name', 'order' => 'ASC']);
    $out = [];
    foreach ($usuarios as $u) {
        if ((int) $u->ID === (int) $excluir_user_id) {
            continue;
        }
        if (get_user_meta($u->ID, 'crm_comercial_ko', true)) {
            continue;
        }
        $out[] = ['id' => (int) $u->ID, 'nombre' => $u->display_name];
    }
    return $out;
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
                                <span class="crm-equipo-lista-nombre"><?php echo esc_html($u['nombre']); ?><?php if (!empty($u['ko'])) : ?> <span style="color:#991b1b;font-weight:700;">(KO)</span><?php endif; ?></span>
                                <span class="crm-equipo-lista-meta"><?php echo esc_html($u['email']); ?><?php echo $u['whatsapp'] !== '' ? ' · ' . esc_html($u['whatsapp']) : ''; ?><?php echo $rol_slug === 'comercial' ? ' · ' . (int) $u['cartera'] . ' cliente(s)' : ''; ?></span>
                                <button type="button" class="crm-btn crm-equipo-editar-btn" data-user-id="<?php echo esc_attr($u['id']); ?>">Editar</button>
                                <?php if ($rol_slug === 'comercial') : ?>
                                    <?php if (!empty($u['ko'])) : ?>
                                        <button type="button" class="crm-btn crm-equipo-ko-btn" data-user-id="<?php echo esc_attr($u['id']); ?>" data-ko="0" style="background:#065f46;">Reactivar</button>
                                    <?php else : ?>
                                        <button type="button" class="crm-btn crm-equipo-ko-btn" data-user-id="<?php echo esc_attr($u['id']); ?>" data-ko="1" style="background:#991b1b;">Marcar KO</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="crm-equipo-edit-form" id="crm-equipo-edit-<?php echo esc_attr($u['id']); ?>">
                                    <label>Nombre</label>
                                    <input type="text" class="crm-equipo-edit-nombre" value="<?php echo esc_attr($u['nombre']); ?>">
                                    <label>Email</label>
                                    <input type="email" class="crm-equipo-edit-email" value="<?php echo esc_attr($u['email']); ?>">
                                    <label>WhatsApp</label>
                                    <input type="text" class="crm-equipo-edit-whatsapp" value="<?php echo esc_attr($u['whatsapp']); ?>" placeholder="+34600000000">
                                    <label style="font-weight:400;">
                                        <input type="checkbox" class="crm-equipo-edit-whatsapp-activo" <?php checked(!empty($u['whatsapp_activo'])); ?>>
                                        Avisar por WhatsApp (visitas, cliente actualizado…)
                                    </label>
                                    <p style="margin:10px 0 0;">
                                        <button type="button" class="crm-btn crm-equipo-guardar-btn" data-user-id="<?php echo esc_attr($u['id']); ?>">Guardar</button>
                                        <span class="crm-equipo-form-msg"></span>
                                    </p>
                                </div>
                                <?php if ($rol_slug === 'comercial') : ?>
                                    <div class="crm-equipo-cartera-panel" id="crm-equipo-cartera-<?php echo esc_attr($u['id']); ?>" style="display:none;width:100%;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;margin-top:6px;">
                                        <p style="margin:0 0 8px;font-size:12.5px;color:#92400e;">Reparte su cartera antes de marcarlo como KO: selecciona clientes, elige el comercial destino y pulsa "Reasignar".</p>
                                        <div class="crm-equipo-cartera-lista"></div>
                                        <p style="margin:8px 0 0;">
                                            <select class="crm-equipo-cartera-destino"></select>
                                            <button type="button" class="crm-btn crm-equipo-cartera-reasignar-btn" data-user-id="<?php echo esc_attr($u['id']); ?>">Reasignar seleccionados</button>
                                            <span class="crm-equipo-cartera-msg"></span>
                                        </p>
                                    </div>
                                <?php endif; ?>
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

        function cargarCartera(userId, panel) {
            var lista = panel.querySelector('.crm-equipo-cartera-lista');
            var destino = panel.querySelector('.crm-equipo-cartera-destino');
            lista.innerHTML = 'Cargando…';
            var body = new URLSearchParams();
            body.set('action', 'crm_equipo_listar_cartera');
            body.set('nonce', nonce);
            body.set('user_id', userId);
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        lista.innerHTML = '<span style="color:#991b1b;">' + ((resp.data && resp.data.message) || 'Error.') + '</span>';
                        return;
                    }
                    var clientes = resp.data.clientes || [];
                    var comerciales = resp.data.comerciales || [];
                    if (!clientes.length) {
                        lista.innerHTML = '<em>Sin clientes pendientes de reasignar.</em>';
                    } else {
                        lista.innerHTML = clientes.map(function (c) {
                            return '<label style="display:block;font-weight:400;font-size:13px;margin:2px 0;">' +
                                '<input type="checkbox" class="crm-equipo-cartera-check" value="' + c.id + '"> ' +
                                (c.cliente_nombre || ('Cliente #' + c.id)) + (c.empresa ? ' — ' + c.empresa : '') +
                                '</label>';
                        }).join('');
                    }
                    destino.innerHTML = comerciales.map(function (com) {
                        return '<option value="' + com.id + '">' + com.nombre + '</option>';
                    }).join('') || '<option value="">(no hay otro comercial activo)</option>';
                });
        }

        document.addEventListener('click', function (e) {
            var koBtn = e.target.closest && e.target.closest('.crm-equipo-ko-btn');
            if (koBtn) {
                var userId = koBtn.getAttribute('data-user-id');
                var ko = koBtn.getAttribute('data-ko') === '1';
                var panel = document.getElementById('crm-equipo-cartera-' + userId);
                koBtn.disabled = true;
                var body = new URLSearchParams();
                body.set('action', 'crm_equipo_marcar_ko');
                body.set('nonce', nonce);
                body.set('user_id', userId);
                body.set('ko', ko ? '1' : '0');
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        koBtn.disabled = false;
                        if (!resp.success) {
                            if (resp.data && resp.data.code === 'cartera_pendiente' && panel) {
                                panel.style.display = 'block';
                                cargarCartera(userId, panel);
                            } else {
                                alert((resp.data && resp.data.message) || 'Error.');
                            }
                            return;
                        }
                        location.reload();
                    });
                return;
            }

            var reasignarBtn = e.target.closest && e.target.closest('.crm-equipo-cartera-reasignar-btn');
            if (reasignarBtn) {
                var uId = reasignarBtn.getAttribute('data-user-id');
                var panel2 = document.getElementById('crm-equipo-cartera-' + uId);
                var checks = Array.from(panel2.querySelectorAll('.crm-equipo-cartera-check:checked')).map(function (c) { return c.value; });
                var destinoId = panel2.querySelector('.crm-equipo-cartera-destino').value;
                var msg = panel2.querySelector('.crm-equipo-cartera-msg');
                if (!checks.length || !destinoId) {
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Selecciona al menos un cliente y el comercial destino.';
                    return;
                }
                reasignarBtn.disabled = true;
                msg.style.color = '#6b7280';
                msg.textContent = 'Reasignando…';
                var body2 = new URLSearchParams();
                body2.set('action', 'crm_equipo_reasignar_cartera');
                body2.set('nonce', nonce);
                checks.forEach(function (id) { body2.append('client_ids[]', id); });
                body2.set('nuevo_comercial_id', destinoId);
                fetch(ajaxurl, { method: 'POST', body: body2 })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        reasignarBtn.disabled = false;
                        if (!resp.success) {
                            msg.style.color = '#991b1b';
                            msg.textContent = (resp.data && resp.data.message) || 'Error.';
                            return;
                        }
                        msg.style.color = '#065f46';
                        msg.textContent = resp.data.movidos + ' cliente(s) reasignado(s).';
                        cargarCartera(uId, panel2);
                    });
                return;
            }

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
                var waActivo = wrap.querySelector('.crm-equipo-edit-whatsapp-activo').checked;
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
                body.set('whatsapp_activo', waActivo ? '1' : '0');
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
    // v1.20.156 — mismo interruptor que ya usan instaladores/jefes
    // (crm_inst_notif_canal_habilitado()) — hasta ahora ningún comercial
    // tenía forma de activarlo (no tienen "Mi perfil" propio como el
    // instalador, así que se gestiona aquí, desde Equipo).
    update_user_meta($user_id, 'crm_notif_canal_whatsapp', !empty($_POST['whatsapp_activo']) ? '1' : '0');

    if (function_exists('crm_log_action')) {
        crm_log_action('equipo_editar', 'Ficha editada: ' . $nombre . ' (' . $email . ')', null, null, 'info');
    }

    wp_send_json_success([]);
}

/**
 * Bloquea el login de un comercial marcado como KO — v1.20.149. No se toca
 * la cuenta ni su historial, solo el acceso; reactivar (crm_comercial_ko
 * a vacío) restaura el acceso normal al instante.
 */
add_filter('wp_authenticate_user', 'crm_authenticate_bloquear_ko', 20, 1);
function crm_authenticate_bloquear_ko($user) {
    if (is_wp_error($user)) {
        return $user;
    }
    if (in_array('comercial', (array) $user->roles, true) && get_user_meta($user->ID, 'crm_comercial_ko', true)) {
        return new WP_Error('crm_comercial_ko', 'Tu cuenta ha sido desactivada. Contacta con tu administrador si crees que es un error.');
    }
    return $user;
}

/**
 * AJAX: marcar/desmarcar un comercial como KO (baja) — v1.20.149. No borra
 * la cuenta ni el historial (decisión explícita del usuario): solo bloquea
 * el acceso (crm_authenticate_bloquear_ko()) mientras conserva todo. Si
 * todavía tiene clientes asignados, se niega a marcarlo como KO — hay que
 * repartir antes su cartera (crm_equipo_ajax_reasignar_cartera()).
 */
add_action('wp_ajax_crm_equipo_marcar_ko', 'crm_equipo_ajax_marcar_ko');
function crm_equipo_ajax_marcar_ko() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_equipo_gestion', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    global $wpdb;
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $ko      = !empty($_POST['ko']);

    $user = get_userdata($user_id);
    if (!$user || !in_array('comercial', (array) $user->roles, true)) {
        wp_send_json_error(['message' => 'Usuario no válido.']);
    }

    if ($ko) {
        $cartera = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}crm_clients WHERE user_id = %d",
            $user_id
        ));
        if ($cartera > 0) {
            wp_send_json_error(['message' => 'Todavía tiene ' . $cartera . ' cliente(s) asignado(s) — reparte su cartera antes de marcarlo como KO.', 'code' => 'cartera_pendiente']);
        }
    }

    update_user_meta($user_id, 'crm_comercial_ko', $ko ? '1' : '');

    // Si ya tenía una sesión abierta en el navegador, marcarlo como KO no la
    // corta por sí solo (el bloqueo de wp_authenticate_user solo actúa en el
    // siguiente intento de login) — se invalida aquí mismo para que el
    // acceso se corte al momento, no en su próximo login.
    if ($ko && class_exists('WP_Session_Tokens')) {
        WP_Session_Tokens::get_instance($user_id)->destroy_all();
    }

    if (function_exists('crm_log_action')) {
        crm_log_action(
            $ko ? 'comercial_marcado_ko' : 'comercial_reactivado',
            ($ko ? 'Comercial marcado como KO (baja): ' : 'Comercial reactivado: ') . $user->display_name,
            null, null, 'notice'
        );
    }

    wp_send_json_success(['ko' => $ko]);
}

/**
 * AJAX: cartera (clientes asignados) de un comercial, para el panel de
 * reparto al marcarlo como KO. v1.20.149.
 */
add_action('wp_ajax_crm_equipo_listar_cartera', 'crm_equipo_ajax_listar_cartera');
function crm_equipo_ajax_listar_cartera() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_equipo_gestion', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    global $wpdb;
    $user_id = (int) ($_POST['user_id'] ?? 0);

    $clientes = $wpdb->get_results($wpdb->prepare(
        "SELECT id, cliente_nombre, empresa FROM {$wpdb->prefix}crm_clients WHERE user_id = %d ORDER BY cliente_nombre ASC",
        $user_id
    ), ARRAY_A);

    wp_send_json_success([
        'clientes'    => $clientes,
        'comerciales' => crm_equipo_comerciales_activos($user_id),
    ]);
}

/**
 * AJAX: reasigna en lote un grupo de clientes a otro comercial activo —
 * "el CRM manda, Holded se sincroniza": esto SOLO toca wp_crm_clients
 * (delegado/user_id/email_comercial), nunca escribe nada en Holded ni se ve
 * afectado por su sincronización (que solo cachea holded_lead_user_id, un
 * campo aparte — ver includes/holded-clientes-sync.php). v1.20.149.
 */
add_action('wp_ajax_crm_equipo_reasignar_cartera', 'crm_equipo_ajax_reasignar_cartera');
function crm_equipo_ajax_reasignar_cartera() {
    if (!current_user_can('crm_admin') || !check_ajax_referer('crm_equipo_gestion', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    global $wpdb;
    $client_ids = array_map('intval', (array) ($_POST['client_ids'] ?? []));
    $client_ids = array_filter($client_ids);
    $nuevo_id   = (int) ($_POST['nuevo_comercial_id'] ?? 0);

    if (empty($client_ids) || $nuevo_id <= 0) {
        wp_send_json_error(['message' => 'Selecciona al menos un cliente y el comercial destino.']);
    }

    $nuevo = get_userdata($nuevo_id);
    if (!$nuevo || !in_array('comercial', (array) $nuevo->roles, true) || get_user_meta($nuevo_id, 'crm_comercial_ko', true)) {
        wp_send_json_error(['message' => 'El comercial destino no es válido o está de baja.']);
    }

    $tabla = $wpdb->prefix . 'crm_clients';
    $movidos = 0;
    foreach ($client_ids as $client_id) {
        $result = $wpdb->update(
            $tabla,
            [
                'delegado'        => $nuevo->display_name,
                'user_id'         => $nuevo_id,
                'email_comercial' => $nuevo->user_email,
            ],
            ['id' => $client_id]
        );
        if ($result !== false) {
            $movidos++;
        }
    }

    if (function_exists('crm_log_action')) {
        crm_log_action(
            'cartera_reasignada',
            $movidos . ' cliente(s) reasignado(s) a ' . $nuevo->display_name . ' (baja de comercial).',
            null, null, 'info'
        );
    }

    wp_send_json_success(['movidos' => $movidos]);
}

/**
 * Roadmap (v1.20.109) — ver includes/flujos-page.php.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Equipo',
        'titulo'  => 'Alta, edición y baja de comerciales/instaladores desde el frontend',
        'estado'  => 'hecho',
        'detalle' => 'Antes, dar de alta un comercial o instalador exigía entrar a wp-admin → Usuarios, bloqueado para crm_admin. Ahora se crea (nombre, email, WhatsApp) y edita la ficha de cada uno directamente desde la página "Equipo", en uso real durante toda esta ronda de trabajo. El email de alta ("pon tu contraseña") se envía por el canal de email del propio rol (v1.20.110, ver Notificaciones). v1.20.149 (reunión 2026-09-22, punto 8): dar de baja a un comercial (KO) sin eliminar su cuenta ni su historial, bloqueado mientras tenga cartera de clientes sin reasignar — con panel para repartirla antes de la baja. v1.20.156: casilla para que cada comercial/visitador active su propio aviso por WhatsApp (antes solo lo tenían jefes/instalador); v1.20.158: su número de WhatsApp también visible desde wp-admin → Usuarios, como vía de respaldo.',
    ];
    return $fases;
});
