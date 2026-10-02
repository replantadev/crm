<?php
/**
 * Encuesta de satisfacción al cliente (R-06-2 de Ecovolt) — v1.20.191.
 *
 * Hueco real del presupuesto original (Fase 8): "encuesta de satisfacción
 * días después, con rama alta puntuación (pedir reseña) / baja (ofrecer
 * ayuda)" — nunca se había construido. El usuario pegó el documento real
 * (R-06-2) que usa Ecovolt: 10 aspectos valorados de 1 a 5, 2 preguntas
 * sí/no/probablemente, observaciones libres, y una sección de "evaluación
 * interna" (resultado medio, % global, conforme/no conforme, acciones
 * necesarias, responsable de revisión).
 *
 * Decisiones confirmadas con el usuario:
 * - Se envía por EMAIL (no WhatsApp) a los N días de finalizar la
 *   instalación — N configurable, por defecto 3, editable tanto desde
 *   wp-admin como desde /panel-de-control/ (crm_inst_notificaciones_settings_render(),
 *   includes/instalaciones.php).
 * - Rama alta (aspecto "Satisfacción global" = 4 o 5): se muestra un enlace
 *   para dejar reseña en Google (si está configurado en Ajustes).
 * - Rama baja (Satisfacción global = 1 o 2): se avisa a jefes/crm_admin
 *   (in-app + email) para que contacten — no se pide reseña.
 * - Resultado medio / % global / Conforme se calculan solos de las 10
 *   valoraciones. "Acciones necesarias" y "Responsable de revisión" los
 *   rellena a mano un jefe/crm_admin desde la ficha, después de revisar la
 *   respuesta.
 *
 * Mismo patrón de token de un solo uso que partida extra/proveedor
 * (wp_generate_password(32, false), columna `token` propia, página pública
 * sin login vía crm_paginas_publicas_sin_login() en acceso.php).
 *
 * @package CRM_Energitel
 * @since 1.20.191
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSS compartido entre el formulario y la página de "gracias" — mobile
 * first a propósito (petición explícita del usuario): la fila de 5 pastillas
 * de cada aspecto se queda en una sola línea incluso en un móvil estrecho
 * (flex con `justify-content:space-between` en vez de una tabla, que no
 * reflow-ea bien).
 *
 * @return string
 */
function crm_inst_encuesta_css() {
    return '
        .crm-encuesta-wrap { max-width:560px; margin:24px auto; padding:0 16px 32px; font-family:system-ui,-apple-system,sans-serif; color:#1f2937; }
        .crm-encuesta-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:28px 20px; box-shadow:0 4px 16px rgba(0,0,0,.04); }
        .crm-encuesta-logo { display:block; max-width:140px; height:auto; margin:0 auto 18px; }
        .crm-encuesta-titulo { margin:0 0 8px; font-size:20px; text-align:center; color:#111827; }
        .crm-encuesta-intro { margin:0 0 24px; font-size:14px; line-height:1.55; color:#4b5563; text-align:center; }
        .crm-encuesta-rating { padding:12px 0; border-bottom:1px solid #f3f4f6; }
        .crm-encuesta-rating:last-child { border-bottom:none; }
        .crm-encuesta-rating-label { display:block; font-size:13.5px; font-weight:600; margin-bottom:10px; }
        .crm-encuesta-rating-opciones { display:flex; justify-content:space-between; gap:4px; }
        .crm-encuesta-rating-opciones label { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; font-size:12px; color:#6b7280; cursor:pointer; }
        .crm-encuesta-rating-opciones input { width:22px; height:22px; margin:0; accent-color:#15803d; }
        .crm-encuesta-pregunta { margin:18px 0 6px; font-weight:600; font-size:13.5px; }
        .crm-encuesta-select, .crm-encuesta-textarea { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-family:inherit; font-size:14px; }
        .crm-encuesta-enviar-btn { width:100%; margin-top:20px; padding:13px 18px; background:#15803d; color:#fff; border:none; border-radius:8px; font-weight:600; font-size:15px; cursor:pointer; }
        .crm-encuesta-enviar-btn:disabled { opacity:.6; cursor:wait; }
        .crm-encuesta-review-btn { display:inline-block; margin-top:10px; padding:11px 20px; background:#2563eb; color:#fff; text-decoration:none; border-radius:8px; font-weight:600; }
        @media (max-width:360px) {
            .crm-encuesta-rating-opciones label { font-size:11px; }
            .crm-encuesta-rating-opciones input { width:20px; height:20px; }
        }
    ';
}

/**
 * Las 10 preguntas valoradas de 1 a 5, en el orden exacto del documento
 * R-06-2 — una sola fuente de verdad para el formulario público, el AJAX
 * que las valida/guarda, y la ficha que las muestra.
 *
 * @return array<string,string> columna => etiqueta
 */
function crm_inst_encuesta_aspectos() {
    return [
        'atencion_trato'           => 'Atención y trato recibido',
        'rapidez_respuesta'        => 'Rapidez de respuesta',
        'asesoramiento_tecnico'    => 'Asesoramiento técnico',
        'cumplimiento_plazos'      => 'Cumplimiento de plazos',
        'calidad_trabajos'         => 'Calidad de los trabajos realizados',
        'profesionalidad_personal' => 'Profesionalidad del personal',
        'limpieza_orden'           => 'Limpieza y orden de los trabajos',
        'cumplimiento_compromisos' => 'Cumplimiento de compromisos',
        'documentacion_entregada'  => 'Documentación entregada',
        'satisfaccion_global'      => 'Satisfacción global',
    ];
}

/* ---------------------------------------------------------------------------
 * Cron: envío automático N días después de finalizar.
 * ------------------------------------------------------------------------- */

function crm_inst_encuesta_schedule_cron() {
    if (!wp_next_scheduled('crm_inst_encuesta_cron_hourly')) {
        wp_schedule_event(time() + 300, 'hourly', 'crm_inst_encuesta_cron_hourly');
    }
}
// Mismo patrón que crm_inst_aviso_schedule_cron()/crm_inst_aviso_calendario_schedule_cron():
// sin activation hook propio, se autorregistra en el primer init normal.
add_action('init', function () {
    if (!wp_doing_cron() && !wp_next_scheduled('crm_inst_encuesta_cron_hourly')) {
        crm_inst_encuesta_schedule_cron();
    }
}, 20);

add_action('crm_inst_encuesta_cron_hourly', 'crm_inst_encuesta_satisfaccion_run');
/**
 * Busca instalaciones finalizadas hace ya N días que todavía no han
 * recibido la encuesta, y la envía. Dedup real: una fila en
 * crm_instalacion_encuestas por instalación es señal suficiente de que ya
 * se envió — no hace falta ningún option de "última pasada" como otros
 * crons (aquí cada instalación se evalúa una sola vez en su vida, no cada
 * día que seguiría cumpliendo la condición).
 */
function crm_inst_encuesta_satisfaccion_run() {
    if (!get_option('crm_inst_encuesta_activa', false)) {
        return;
    }
    global $wpdb;
    $dias   = max(0, (int) get_option('crm_inst_encuesta_dias', 3));
    $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days', current_time('timestamp')));

    $tabla_inst = crm_inst_table_instalaciones();
    $tabla_enc  = crm_inst_table_encuestas();

    $candidatas = $wpdb->get_results($wpdb->prepare(
        "SELECT i.id, c.cliente_nombre, c.email_cliente
         FROM {$tabla_inst} i
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         WHERE i.estado = 'finalizada' AND i.fecha_cierre IS NOT NULL AND i.fecha_cierre <= %s
           AND NOT EXISTS (SELECT 1 FROM {$tabla_enc} e WHERE e.instalacion_id = i.id)
         LIMIT 100",
        $limite
    ), ARRAY_A);

    foreach ((array) $candidatas as $c) {
        $email = sanitize_email((string) ($c['email_cliente'] ?? ''));
        if ($email === '') {
            // Sin email no hay forma de mandarla — se deja constancia en el
            // log para que no parezca que el cron la ignoró sin más, pero
            // NO se crea la fila (así, si más adelante se rellena el email,
            // el siguiente paso del cron sí la cogerá).
            crm_inst_log_action((int) $c['id'], 'instalacion', 'encuesta_sin_email', 'No se pudo enviar la encuesta de satisfacción: el cliente no tiene email registrado.');
            continue;
        }

        $token = wp_generate_password(32, false);
        $wpdb->insert($tabla_enc, [
            'instalacion_id' => (int) $c['id'],
            'token'          => $token,
            'enviada_en'     => current_time('mysql'),
        ]);

        $url = add_query_arg('token', $token, home_url('/encuesta-satisfaccion/'));
        $nombre = $c['cliente_nombre'] ?: 'cliente';
        $asunto = 'Tu opinión nos importa — Ecovolt';
        $body   = '<p>Hola ' . esc_html($nombre) . ',</p>'
            . '<p>Hace unos días finalizamos tu instalación y nos encantaría saber qué tal ha ido todo. ¿Nos ayudas con 2 minutos?</p>'
            . '<p><a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 18px;background:#15803d;color:#fff;text-decoration:none;border-radius:6px;">Responder encuesta</a></p>'
            . '<p style="color:#666;font-size:12px">Si el botón no funciona, copia este enlace en tu navegador: ' . esc_html($url) . '</p>';
        wp_mail($email, $asunto, $body, ['Content-Type: text/html; charset=UTF-8']);

        crm_inst_log_action((int) $c['id'], 'instalacion', 'encuesta_enviada', 'Encuesta de satisfacción enviada al cliente por email.');
    }
}

/* ---------------------------------------------------------------------------
 * Página pública (sin login) — formulario de respuesta.
 * ------------------------------------------------------------------------- */

add_shortcode('crm_inst_encuesta_satisfaccion', 'crm_inst_shortcode_encuesta_satisfaccion');
function crm_inst_shortcode_encuesta_satisfaccion() {
    if (function_exists('crm_app_shell_chromeless_css')) {
        echo '<style>' . crm_app_shell_chromeless_css(false) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '<style>' . crm_inst_encuesta_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    global $wpdb;
    $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
    if ($token === '') {
        return '<p>Enlace no válido.</p>';
    }

    $enc = $wpdb->get_row($wpdb->prepare(
        "SELECT e.*, c.cliente_nombre
         FROM " . crm_inst_table_encuestas() . " e
         LEFT JOIN " . crm_inst_table_instalaciones() . " i ON i.id = e.instalacion_id
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         WHERE e.token = %s",
        $token
    ), ARRAY_A);
    if (!$enc) {
        return '<p>Enlace no válido o caducado.</p>';
    }
    if (!empty($enc['respondida_en'])) {
        return crm_inst_encuesta_pagina_gracias($enc);
    }

    $nonce = wp_create_nonce('crm_inst_encuesta_' . $token);
    $aspectos = crm_inst_encuesta_aspectos();
    $logo_url = (string) get_option('crm_instalador_panel_logo_url', CRM_PLUGIN_URL . 'img/ecovolt-logo.jpg');
    $nombre_pila = $enc['cliente_nombre'] ? trim(explode(' ', trim((string) $enc['cliente_nombre']))[0]) : '';

    ob_start();
    ?>
    <div class="crm-encuesta-wrap" id="crm-encuesta-wrap">
        <div class="crm-encuesta-card">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Ecovolt" class="crm-encuesta-logo">
            <?php endif; ?>
            <h2 class="crm-encuesta-titulo">¡Gracias por confiar en Ecovolt<?php echo $nombre_pila ? ', ' . esc_html($nombre_pila) : ''; ?>!</h2>
            <p class="crm-encuesta-intro">Tu instalación ya está en marcha y nos encantaría saber qué tal ha sido tu experiencia con nosotros. Tu opinión nos ayuda a seguir mejorando el servicio y el soporte que te damos — solo te llevará 2 minutos.</p>

            <?php foreach ($aspectos as $campo => $label): ?>
                <div class="crm-encuesta-rating">
                    <label class="crm-encuesta-rating-label"><?php echo esc_html($label); ?></label>
                    <div class="crm-encuesta-rating-opciones">
                        <?php for ($n = 1; $n <= 5; $n++): ?>
                            <label><input type="radio" name="asp_<?php echo esc_attr($campo); ?>" value="<?php echo (int) $n; ?>"><?php echo (int) $n; ?></label>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <p class="crm-encuesta-pregunta">¿Volverías a contratar los servicios de ECOVOLT?</p>
            <select id="crm-encuesta-volveria" class="crm-encuesta-select">
                <option value="">— Elige —</option>
                <option value="si">Sí</option>
                <option value="no">No</option>
                <option value="probablemente">Probablemente</option>
            </select>

            <p class="crm-encuesta-pregunta">¿Recomendarías ECOVOLT a otras empresas o particulares?</p>
            <select id="crm-encuesta-recomendaria" class="crm-encuesta-select">
                <option value="">— Elige —</option>
                <option value="si">Sí</option>
                <option value="no">No</option>
                <option value="probablemente">Probablemente</option>
            </select>

            <p class="crm-encuesta-pregunta">Observaciones y propuestas de mejora</p>
            <textarea id="crm-encuesta-observaciones" rows="4" class="crm-encuesta-textarea" placeholder="Opcional"></textarea>

            <button type="button" id="crm-encuesta-enviar-btn" class="crm-encuesta-enviar-btn">Enviar mi opinión</button>
            <p id="crm-encuesta-msg" style="margin-top:10px;font-size:13px;color:#991b1b;text-align:center;"></p>
        </div>
    </div>
    <script>
    (function () {
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var token   = <?php echo wp_json_encode($token); ?>;
        var nonce   = <?php echo wp_json_encode($nonce); ?>;
        var campos  = <?php echo wp_json_encode(array_keys($aspectos)); ?>;

        document.getElementById('crm-encuesta-enviar-btn').addEventListener('click', function () {
            var btn = this;
            var msg = document.getElementById('crm-encuesta-msg');
            var body = new URLSearchParams();
            body.set('action', 'crm_inst_encuesta_responder');
            body.set('token', token);
            body.set('nonce', nonce);
            var faltan = false;
            campos.forEach(function (campo) {
                var checked = document.querySelector('input[name="asp_' + campo + '"]:checked');
                if (!checked) { faltan = true; return; }
                body.set(campo, checked.value);
            });
            if (faltan) {
                msg.textContent = 'Valora todos los aspectos antes de enviar.';
                return;
            }
            var volveria = document.getElementById('crm-encuesta-volveria').value;
            var recomendaria = document.getElementById('crm-encuesta-recomendaria').value;
            if (!volveria || !recomendaria) {
                msg.textContent = 'Responde también si volverías a contratar y si recomendarías ECOVOLT.';
                return;
            }
            body.set('volveria_contratar', volveria);
            body.set('recomendaria', recomendaria);
            body.set('observaciones', document.getElementById('crm-encuesta-observaciones').value);

            btn.disabled = true;
            msg.style.color = '#6b7280';
            msg.textContent = 'Enviando…';
            fetch(ajaxurl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        btn.disabled = false;
                        msg.style.color = '#991b1b';
                        msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
                        return;
                    }
                    document.getElementById('crm-encuesta-wrap').outerHTML = resp.data.html;
                })
                .catch(function () {
                    btn.disabled = false;
                    msg.style.color = '#991b1b';
                    msg.textContent = 'Error de conexión.';
                });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Página de "gracias" tras responder — distinta según la rama (alta/baja
 * puntuación de "Satisfacción global"), reutilizada tanto por el AJAX de
 * respuesta (primera vez) como por una recarga posterior del mismo enlace.
 *
 * @param array $enc Fila de crm_instalacion_encuestas ya respondida.
 * @return string
 */
function crm_inst_encuesta_pagina_gracias(array $enc) {
    $global = (int) ($enc['satisfaccion_global'] ?? 0);
    $logo_url = (string) get_option('crm_instalador_panel_logo_url', CRM_PLUGIN_URL . 'img/ecovolt-logo.jpg');
    ob_start();
    ?>
    <div class="crm-encuesta-wrap" id="crm-encuesta-wrap">
        <div class="crm-encuesta-card" style="text-align:center;">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Ecovolt" class="crm-encuesta-logo">
            <?php endif; ?>
            <?php if ($global >= 4): ?>
                <h2 class="crm-encuesta-titulo">¡Mil gracias por tu confianza!</h2>
                <p class="crm-encuesta-intro">Nos alegra muchísimo saber que has tenido una buena experiencia — seguimos aquí para lo que necesites.</p>
                <?php $review_url = (string) get_option('crm_inst_encuesta_google_review_url', ''); ?>
                <?php if ($review_url !== ''): ?>
                    <p class="crm-encuesta-intro" style="margin-bottom:10px;">Si tienes un segundo más, nos ayudaría muchísimo que compartieras tu opinión en Google:</p>
                    <a href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer" class="crm-encuesta-review-btn">Dejar una reseña en Google</a>
                <?php endif; ?>
            <?php elseif ($global <= 2): ?>
                <h2 class="crm-encuesta-titulo" style="color:#991b1b;">Gracias por contárnoslo</h2>
                <p class="crm-encuesta-intro">Sentimos que la experiencia no haya sido la que esperabas. Nuestro equipo se pondrá en contacto contigo lo antes posible para ayudarte.</p>
            <?php else: ?>
                <h2 class="crm-encuesta-titulo">¡Gracias por tu opinión!</h2>
                <p class="crm-encuesta-intro">Hemos recibido tu respuesta — la tendremos en cuenta para seguir mejorando.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_nopriv_crm_inst_encuesta_responder', 'crm_inst_ajax_encuesta_responder');
add_action('wp_ajax_crm_inst_encuesta_responder', 'crm_inst_ajax_encuesta_responder');
function crm_inst_ajax_encuesta_responder() {
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    if ($token === '' || !check_ajax_referer('crm_inst_encuesta_' . $token, 'nonce', false)) {
        wp_send_json_error(['message' => 'Enlace no válido.'], 403);
    }

    global $wpdb;
    $tabla = crm_inst_table_encuestas();
    $enc = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tabla} WHERE token = %s", $token), ARRAY_A);
    if (!$enc) {
        wp_send_json_error(['message' => 'Enlace no válido.']);
    }
    if (!empty($enc['respondida_en'])) {
        wp_send_json_error(['message' => 'Esta encuesta ya se respondió, gracias.']);
    }

    $aspectos = crm_inst_encuesta_aspectos();
    $valores = [];
    $suma = 0;
    foreach ($aspectos as $campo => $label) {
        $v = (int) ($_POST[$campo] ?? 0);
        if ($v < 1 || $v > 5) {
            wp_send_json_error(['message' => 'Falta valorar: ' . $label . '.']);
        }
        $valores[$campo] = $v;
        $suma += $v;
    }

    $volveria = sanitize_key(wp_unslash($_POST['volveria_contratar'] ?? ''));
    $recomendaria = sanitize_key(wp_unslash($_POST['recomendaria'] ?? ''));
    if (!in_array($volveria, ['si', 'no', 'probablemente'], true) || !in_array($recomendaria, ['si', 'no', 'probablemente'], true)) {
        wp_send_json_error(['message' => 'Respuesta no válida.']);
    }
    $observaciones = sanitize_textarea_field(wp_unslash($_POST['observaciones'] ?? ''));

    // "Resultado medio"/"Valoración global (%)"/"Conforme" — calculados
    // solos de las 10 valoraciones (documento R-06-2, sección 4). Umbral de
    // "Conforme" fijado en 70% (3,5/5 de media) — valor por defecto
    // razonable, ajustable en el futuro si Ecovolt ya tiene uno propio en su
    // sistema de calidad.
    $resultado_medio = round($suma / count($aspectos), 2);
    $valoracion_pct  = round($resultado_medio / 5 * 100, 2);
    $conforme        = $valoracion_pct >= 70 ? 1 : 0;

    $update = array_merge($valores, [
        'respondida_en'         => current_time('mysql'),
        'volveria_contratar'    => $volveria,
        'recomendaria'          => $recomendaria,
        'observaciones'         => $observaciones,
        'resultado_medio'       => $resultado_medio,
        'valoracion_global_pct' => $valoracion_pct,
        'conforme'              => $conforme,
    ]);
    $wpdb->update($tabla, $update, ['id' => (int) $enc['id']]);

    $instalacion_id = (int) $enc['instalacion_id'];
    $global = (int) $valores['satisfaccion_global'];

    if (function_exists('crm_inst_log_action')) {
        crm_inst_log_action($instalacion_id, 'instalacion', 'encuesta_respondida', 'Encuesta de satisfacción respondida — satisfacción global: ' . $global . '/5, media: ' . $resultado_medio . '/5.');
    }

    // Rama baja: avisar a jefes/crm_admin para que contacten (decisión del
    // usuario) — no se pide reseña en este caso.
    if ($global <= 2) {
        $url = add_query_arg('id', $instalacion_id, home_url('/instalacion/'));
        $mensaje = 'Encuesta de satisfacción con nota baja (' . $global . '/5) — conviene contactar con el cliente.';
        if (function_exists('crm_notificar_jefes_instalaciones')) {
            crm_notificar_jefes_instalaciones('encuesta_nota_baja', $mensaje, $url);
        }
        if (function_exists('crm_inst_aviso_enviar_email_jefes')) {
            crm_inst_aviso_enviar_email_jefes('Encuesta de satisfacción con nota baja', $mensaje, $url);
        }
    }

    $enc_actualizada = array_merge($enc, $update);
    wp_send_json_success(['html' => crm_inst_encuesta_pagina_gracias($enc_actualizada)]);
}

/**
 * Guarda la "evaluación interna" (acciones necesarias + responsable de
 * revisión) — solo jefe/crm_admin, desde la ficha de instalación. El
 * resultado medio/% global/conforme NO se tocan aquí, son de solo lectura
 * (ya se calcularon al responder).
 */
add_action('wp_ajax_crm_inst_encuesta_guardar_revision', 'crm_inst_ajax_encuesta_guardar_revision');
function crm_inst_ajax_encuesta_guardar_revision() {
    if (!is_user_logged_in() || !check_ajax_referer('crm_inst_holded', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }
    if (!current_user_can('crm_inst_manage')) {
        wp_send_json_error(['message' => 'Sin permisos.'], 403);
    }

    $instalacion_id = (int) ($_POST['instalacion_id'] ?? 0);
    if ($instalacion_id <= 0) {
        wp_send_json_error(['message' => 'Instalación no válida.']);
    }

    global $wpdb;
    $tabla = crm_inst_table_encuestas();
    $acciones = sanitize_textarea_field(wp_unslash($_POST['acciones_necesarias'] ?? ''));
    $responsable = sanitize_text_field(wp_unslash($_POST['responsable_revision'] ?? ''));

    $updated = $wpdb->update($tabla, [
        'acciones_necesarias'   => $acciones !== '' ? $acciones : null,
        'responsable_revision'  => $responsable !== '' ? $responsable : null,
        'revisado_por'          => get_current_user_id(),
        'revisado_en'           => current_time('mysql'),
    ], ['instalacion_id' => $instalacion_id], null, ['%d']);

    if ($updated === false) {
        wp_send_json_error(['message' => 'No se pudo guardar.']);
    }

    if (function_exists('crm_inst_log_action')) {
        crm_inst_log_action($instalacion_id, 'instalacion', 'encuesta_revisada', 'Evaluación interna de la encuesta de satisfacción actualizada.');
    }

    wp_send_json_success();
}

/* ---------------------------------------------------------------------------
 * Listado para crm_admin/jefe_instalaciones — "integrado con instalaciones":
 * cada fila enlaza a la ficha de la instalación, que es donde ya vive el
 * detalle completo + la "evaluación interna" editable (no se duplica esa UI
 * aquí, este listado es solo para ver de un vistazo qué hay pendiente de
 * revisar entre todas las instalaciones, igual que el listado de
 * instalaciones o la cola de leads MK).
 * ------------------------------------------------------------------------- */

add_shortcode('crm_inst_encuestas_listado', 'crm_inst_shortcode_encuestas_listado');
function crm_inst_shortcode_encuestas_listado() {
    if (!is_user_logged_in() || !current_user_can('crm_inst_manage')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }

    global $wpdb;
    $filtro = sanitize_key(wp_unslash($_GET['filtro'] ?? ''));
    $where = '1=1';
    if ($filtro === 'pendientes') {
        $where = 'e.respondida_en IS NULL';
    } elseif ($filtro === 'respondidas') {
        $where = 'e.respondida_en IS NOT NULL';
    } elseif ($filtro === 'sin_revisar') {
        $where = "e.respondida_en IS NOT NULL AND e.revisado_en IS NULL";
    } elseif ($filtro === 'no_conforme') {
        $where = 'e.conforme = 0';
    }

    $filas = $wpdb->get_results(
        "SELECT e.*, i.id AS instalacion_id, c.cliente_nombre
         FROM " . crm_inst_table_encuestas() . " e
         LEFT JOIN " . crm_inst_table_instalaciones() . " i ON i.id = e.instalacion_id
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         WHERE {$where}
         ORDER BY e.enviada_en DESC
         LIMIT 500",
        ARRAY_A
    );

    $export_url = esc_url(add_query_arg(
        array_filter(['filtro' => $filtro]),
        wp_nonce_url(admin_url('admin-post.php?action=crm_inst_export_encuestas_csv'), 'crm_inst_export_encuestas_csv')
    ));

    ob_start();
    ?>
    <div class="crm-widget-compact">
        <div class="widget-header-compact">
            <h3 class="widget-title-compact">Encuestas de satisfacción</h3>
            <a href="<?php echo $export_url; ?>" class="crm-btn"><?php echo function_exists('crm_icon') ? crm_icon('file-text', 14) : ''; ?> Exportar CSV</a>
        </div>
        <div class="widget-content-compact">
            <p style="margin:0 0 12px;">
                <?php
                $filtros = [
                    ''              => 'Todas',
                    'pendientes'    => 'Pendientes de respuesta',
                    'respondidas'   => 'Respondidas',
                    'sin_revisar'   => 'Respondidas sin revisar',
                    'no_conforme'   => 'No conformes',
                ];
                foreach ($filtros as $key => $label):
                    $url = $key === '' ? remove_query_arg('filtro') : add_query_arg('filtro', $key);
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="crm-btn" style="<?php echo $filtro === $key ? '' : 'background:#9ca3af;'; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </p>
            <div class="table-responsive-compact">
                <table class="crm-table-compact">
                    <thead>
                        <tr><th>Cliente</th><th>Enviada</th><th>Estado</th><th>Satisfacción global</th><th>Conforme</th><th>Acciones necesarias</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filas)): ?>
                            <tr><td colspan="7">No hay encuestas con este filtro.</td></tr>
                        <?php endif; ?>
                        <?php foreach ((array) $filas as $f):
                            $ficha_url = add_query_arg('id', $f['instalacion_id'], home_url('/instalacion/'));
                        ?>
                            <tr>
                                <td><?php echo esc_html($f['cliente_nombre'] ?: '(sin cliente)'); ?></td>
                                <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($f['enviada_en']))); ?></td>
                                <td>
                                    <?php if (empty($f['respondida_en'])): ?>
                                        <span class="status-badge status-inst-pendiente">Pendiente</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inst-lista">Respondida</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $f['satisfaccion_global'] !== null ? esc_html($f['satisfaccion_global']) . ' / 5' : '—'; ?></td>
                                <td>
                                    <?php if ($f['conforme'] === null): ?>
                                        —
                                    <?php elseif ((int) $f['conforme'] === 1): ?>
                                        <span class="status-badge status-inst-lista">Conforme</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inst-cancelada">No conforme</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($f['acciones_necesarias']) ? '✓' : '—'; ?></td>
                                <td><a href="<?php echo esc_url($ficha_url); ?>" class="crm-btn">Ver ficha</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Exporta el listado de encuestas a CSV — mismo patrón que los otros 4
 * listados exportables (includes/csv-export.php, v1.20.188), respetando el
 * filtro activo igual que hace Instalaciones.
 */
add_action('admin_post_crm_inst_export_encuestas_csv', 'crm_inst_export_encuestas_csv');
function crm_inst_export_encuestas_csv() {
    if (!is_user_logged_in() || !current_user_can('crm_inst_manage')) {
        wp_die('Sin permisos', 403);
    }
    check_admin_referer('crm_inst_export_encuestas_csv');

    global $wpdb;
    $filtro = sanitize_key(wp_unslash($_GET['filtro'] ?? ''));
    $where = '1=1';
    if ($filtro === 'pendientes') {
        $where = 'e.respondida_en IS NULL';
    } elseif ($filtro === 'respondidas') {
        $where = 'e.respondida_en IS NOT NULL';
    } elseif ($filtro === 'sin_revisar') {
        $where = "e.respondida_en IS NOT NULL AND e.revisado_en IS NULL";
    } elseif ($filtro === 'no_conforme') {
        $where = 'e.conforme = 0';
    }

    $filas = $wpdb->get_results(
        "SELECT e.*, i.id AS instalacion_id, c.cliente_nombre
         FROM " . crm_inst_table_encuestas() . " e
         LEFT JOIN " . crm_inst_table_instalaciones() . " i ON i.id = e.instalacion_id
         LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
         WHERE {$where}
         ORDER BY e.enviada_en DESC
         LIMIT 10000",
        ARRAY_A
    );

    $rows = [];
    foreach ((array) $filas as $f) {
        $rows[] = [
            $f['cliente_nombre'] ?: '(sin cliente)',
            $f['enviada_en'],
            empty($f['respondida_en']) ? 'Pendiente' : $f['respondida_en'],
            $f['satisfaccion_global'] ?? '',
            $f['resultado_medio'] ?? '',
            $f['valoracion_global_pct'] ?? '',
            $f['conforme'] === null ? '' : ((int) $f['conforme'] === 1 ? 'Conforme' : 'No conforme'),
            $f['volveria_contratar'] ?? '',
            $f['recomendaria'] ?? '',
            $f['acciones_necesarias'] ?? '',
            $f['responsable_revision'] ?? '',
        ];
    }

    crm_csv_export_stream(
        'encuestas_satisfaccion_' . date('Y-m-d_H-i-s') . '.csv',
        ['Cliente', 'Enviada', 'Respondida', 'Satisfacción global', 'Resultado medio', 'Valoración %', 'Conforme', 'Volvería a contratar', 'Recomendaría', 'Acciones necesarias', 'Responsable revisión'],
        $rows
    );
}

/**
 * Roadmap de "Encuesta de satisfacción" (v1.20.191) — ver includes/flujos-page.php.
 * Cierra el último punto pendiente de Fase 8 (ver roadmap, includes/instalaciones.php).
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Fase 8 · encuesta de satisfacción',
        'titulo'  => 'R-06-2 de Ecovolt: 10 aspectos valorados, rama alta/baja puntuación',
        'estado'  => 'en_pruebas',
        'detalle' => 'Último punto pendiente de Fase 8. El usuario pegó el documento real (R-06-2) de Ecovolt — formulario construido exactamente con sus 10 aspectos, en el mismo orden, más las 2 preguntas sí/no/probablemente y observaciones. Se envía por email (no WhatsApp) a los N días de finalizar (configurable, por defecto 3, editable desde wp-admin y desde /panel-de-control/), con un enlace de un solo uso — desactivada por defecto hasta activarla en Ajustes. Rama alta (Satisfacción global 4-5): enlace para dejar reseña en Google, SOLO si está configurado (si no, se da las gracias sin más — todavía no hay otros canales para la rama baja, el usuario los añadirá más adelante). Rama baja (1-2): aviso in-app + email a jefes/crm_admin para que contacten, sin pedir reseña. "Resultado medio"/"% global"/"Conforme" se calculan solos (umbral de conforme: 70%, un valor por defecto razonable — ajustar si Ecovolt ya tiene uno propio en su sistema de calidad); "Acciones necesarias" y "Responsable de revisión" los rellena un jefe/crm_admin a mano desde la ficha. v1.20.191: a petición del usuario, la página pública se rediseñó con el logo de Ecovolt (reutilizando el mismo ajuste que ya usa el panel del instalador) y un texto empático centrado en el soporte, en vez del formulario genérico inicial; la fila de 5 valoraciones de cada aspecto se hizo mobile-first (flex en vez de tabla, no se sale de pantalla en un móvil estrecho). También se añadió un listado propio para crm_admin/jefe_instalaciones (crm_inst_encuestas_listado(), página "Encuestas de satisfacción", enlazada desde el listado de Instalaciones) con filtros (pendientes/respondidas/sin revisar/no conformes) y exportación CSV, para ver de un vistazo todas las encuestas sin tener que entrar instalación por instalación. Construido, sin ninguna prueba real todavía (ni un envío real por el cron, ni una respuesta real al formulario).',
    ];
    return $fases;
});
