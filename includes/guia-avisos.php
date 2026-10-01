<?php
/**
 * CRM — Guía de avisos (wp-admin → CRM → Guía de avisos, y frontend para
 * crm_admin vía [crm_guia_avisos]).
 *
 * Página puramente explicativa, sin lenguaje técnico: qué avisa el CRM por
 * email, WhatsApp e in-app, a quién y cuándo. Pedido por el usuario
 * 2026-09-26 tras la auditoría del sistema de WhatsApp (ver PLAN_instalaciones.md).
 * Rediseñada 2026-10-01 (sin emojis, con el sistema de diseño del CRM) y
 * ampliada con los avisos nuevos (lead frío, reprogramar visita por
 * WhatsApp).
 *
 * Mismo criterio que includes/flujos-page.php: el contenido se LEE de las
 * fuentes que ya existen y ya se mantienen al día (crm_mail_canales() /
 * crm_mail_eventos_por_canal() en mail-settings.php, crm_whatsapp_catalogo()
 * en whatsapp-api.php) — esta página no es un documento aparte que alguien
 * tenga que acordarse de actualizar a mano; si mañana se añade un canal o
 * una plantilla ahí, aparece aquí solo con darse de alta. Los umbrales de
 * los avisos de negocio (lead frío, presupuesto estancado) se leen de
 * get_option() en el momento, así que si se cambian en Ajustes esta página
 * nunca se desincroniza.
 *
 * @package CRM_Energitel
 * @since 1.20.159
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agrupación de las plantillas de WhatsApp por destinatario, para que se lea
 * de un vistazo "quién recibe qué" en vez de una lista plana de 12 filas.
 * El texto de cada aviso sigue viniendo de crm_whatsapp_catalogo() (única
 * fuente) — esto solo decide en qué grupo va cada opción y en qué orden.
 *
 * @return array<string,string[]> etiqueta del grupo => lista de opciones
 */
function crm_guia_avisos_whatsapp_grupos() {
    return [
        'Al cliente' => [
            'crm_whatsapp_template_validar_extra',
            'crm_whatsapp_template_confirmacion_visita_cliente',
            // v1.20.177 — el cliente puede fijar él mismo una franja al
            // pedir cambio de cita.
            'crm_whatsapp_template_reprogramar_visita_cliente',
        ],
        'Al instalador' => [
            'crm_whatsapp_template_instalador_asignado',
            'crm_whatsapp_template_instalador_visita',
            'crm_whatsapp_template_instalador_extra_resuelta',
            'crm_whatsapp_template_instalador_cierre_resuelto',
        ],
        'Al comercial o visitador' => [
            'crm_whatsapp_template_comercial_cliente_actualizado',
            'crm_whatsapp_template_comercial_visita',
        ],
        'A jefes de instalaciones / crm_admin' => [
            'crm_whatsapp_template_aviso_materiales',
            'crm_whatsapp_template_en_ejecucion',
            'crm_whatsapp_template_recordatorio_visita',
        ],
    ];
}

/**
 * Quita el "(a quién)" del final de la etiqueta del catálogo — sobra cuando
 * ya se está mostrando agrupado bajo ese mismo destinatario.
 */
function crm_guia_avisos_sin_destinatario($etiqueta) {
    return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $etiqueta));
}

/**
 * Avisos "de negocio" — no son un canal de email configurable (sin
 * remitente/SMTP propio, usan el envío por defecto del servidor) sino una
 * regla que dispara un cron por umbral. Los umbrales se leen de get_option()
 * en vivo, nunca a mano, para que esta página no se desincronice si se
 * cambian en Ajustes.
 *
 * @return array<int,array{titulo:string,destinatario:string,condicion:string,canales:string[]}>
 */
function crm_guia_avisos_reglas_negocio() {
    $dias_estancado = max(1, (int) get_option('crm_ventas_aviso_estancados_dias', 7));
    $horas_frio     = max(1, (int) get_option('crm_leads_mk_aviso_frio_horas', 48));
    $horas_escalado = max($horas_frio + 1, (int) get_option('crm_leads_mk_aviso_frio_escalar_horas', $horas_frio * 2));

    return [
        [
            'titulo'       => 'Presupuesto estancado',
            'destinatario' => 'El comercial dueño del cliente',
            'condicion'    => 'Un presupuesto de Holded lleva más de ' . $dias_estancado . ' día(s) creado y sigue sin aprobarse. Avisa una sola vez por presupuesto.',
            'canales'      => ['in-app', 'email'],
        ],
        [
            'titulo'       => 'Lead frío',
            'destinatario' => 'El comercial al que se le asignó',
            'condicion'    => 'Un lead lleva más de ' . $horas_frio . ' horas asignado sin que el comercial lo haya tocado.',
            'canales'      => ['in-app', 'email'],
        ],
        [
            'titulo'       => 'Lead frío — escalado',
            'destinatario' => 'Jefes de instalaciones / crm_admin',
            'condicion'    => 'El lead frío de arriba sigue sin tocarse pasadas ' . $horas_escalado . ' horas desde la asignación.',
            'canales'      => ['in-app'],
        ],
    ];
}

/**
 * Notificaciones in-app (la campana) agrupadas por destinatario — mismo
 * criterio que crm_guia_avisos_whatsapp_grupos(): el "tipo" real es el que
 * ya usa cada crm_notificar() del código, esto solo organiza la lectura.
 * No hay un catálogo único en código para in-app (sí lo hay para WhatsApp,
 * crm_whatsapp_catalogo()) — esta lista es la referencia a mano, y hay que
 * tocarla en el mismo commit que añada un crm_notificar() nuevo.
 *
 * @return array<string,string[]>
 */
function crm_guia_avisos_inapp_grupos() {
    return [
        'Al instalador' => [
            'Instalación asignada',
            'Visita programada o reprogramada en la agenda (incluida la reprogramada por el propio cliente por WhatsApp)',
            'Recordatorio de visita al día siguiente',
            'Partida extra resuelta (aprobada o rechazada)',
            'Cierre de instalación resuelto',
            'Incidencia que abrió él mismo pasó de estado (en curso / resuelta)',
            'Se abrió una incidencia nueva desde la ficha (por un jefe/crm_admin)',
        ],
        'Al comercial' => [
            'Lead frío (sin tocar pasado el umbral)',
            'Presupuesto estancado',
            'Ficha de cliente actualizada por el admin',
        ],
        'A jefes de instalaciones / crm_admin' => [
            'Materiales pendientes de un pedido',
            'Instalación en marcha (cierre parcial)',
            'Recordatorio de visita al día siguiente',
            'Partida extra pendiente de validar',
            'Cierre de instalador pendiente de validar',
            'Cliente confirmó la visita por WhatsApp',
            'Cliente pidió cambiar la visita por WhatsApp (y si se le ofrecieron franjas automáticas)',
            'Cliente reprogramó su visita él mismo por WhatsApp',
            'Lead frío escalado (sigue sin tocarse)',
            'Incidencia nueva abierta por un instalador',
        ],
    ];
}

/**
 * HTML común a wp-admin y al shortcode del frontend — mismo criterio que
 * crm_flujos_render_diagramas_html() en flujos-page.php.
 */
function crm_guia_avisos_render_html() {
    $canales_email  = function_exists('crm_mail_canales') ? crm_mail_canales() : [];
    $eventos_email  = function_exists('crm_mail_eventos_por_canal') ? crm_mail_eventos_por_canal() : [];
    $catalogo_wa    = function_exists('crm_whatsapp_catalogo') ? crm_whatsapp_catalogo() : [];
    $wa_configurado = function_exists('crm_whatsapp_configurado') && crm_whatsapp_configurado();
    $reglas_negocio = crm_guia_avisos_reglas_negocio();
    $inapp_grupos   = crm_guia_avisos_inapp_grupos();
    $ic             = function_exists('crm_icon') ? 'crm_icon' : null;

    ob_start();
    ?>
    <style>
        .crm-ga { max-width: 980px; margin: 0 auto; font-family: var(--crm-font, ui-sans-serif, system-ui, sans-serif); color: var(--crm-n-800, #27272a); }
        .crm-ga-intro { color: var(--crm-n-600, #52525b); font-size: 14px; max-width: 760px; margin: 0 0 var(--crm-sp-5, 24px); line-height: 1.55; }
        .crm-ga-search-wrap { position: relative; margin-bottom: var(--crm-sp-5, 24px); }
        .crm-ga-search {
            width: 100%; box-sizing: border-box; padding: 10px 14px;
            border: 1px solid var(--crm-n-200, #e4e4e7); border-radius: var(--crm-r-md, 8px);
            font-size: 14px; background: var(--crm-n-0, #fff); color: var(--crm-n-800, #27272a);
        }
        .crm-ga-search:focus { outline: none; border-color: var(--crm-acc-500, #3b82f6); box-shadow: 0 0 0 3px var(--crm-acc-100, #dbeafe); }
        .crm-ga-nav { display: flex; flex-wrap: wrap; gap: var(--crm-sp-2, 8px); margin-bottom: var(--crm-sp-6, 32px); }
        .crm-ga-nav a {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: var(--crm-r-pill, 999px);
            background: var(--crm-n-100, #f4f4f5); color: var(--crm-n-700, #3f3f46);
            font-size: 13px; font-weight: 600; text-decoration: none;
            border: 1px solid transparent;
        }
        .crm-ga-nav a:hover { background: var(--crm-acc-50, #eff6ff); color: var(--crm-acc-700, #1d4ed8); border-color: var(--crm-acc-200, #bfdbfe); }
        .crm-ga-section { margin-bottom: var(--crm-sp-6, 32px); scroll-margin-top: 16px; }
        .crm-ga-section-head { display: flex; align-items: center; gap: 10px; margin-bottom: var(--crm-sp-4, 16px); }
        .crm-ga-section-head .crm-i { color: var(--crm-acc-600, #2563eb); }
        .crm-ga-section-head h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--crm-n-900, #18181b); }
        .crm-ga-section-sub { margin: 0 0 var(--crm-sp-4, 16px); color: var(--crm-n-600, #52525b); font-size: 13.5px; max-width: 760px; line-height: 1.5; }
        .crm-ga-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--crm-sp-4, 16px); }
        .crm-ga-card {
            background: var(--crm-n-0, #fff); border: 1px solid var(--crm-n-200, #e4e4e7);
            border-radius: var(--crm-r-lg, 12px); padding: var(--crm-sp-4, 16px);
            box-shadow: var(--crm-sh-1, 0 1px 2px rgba(0,0,0,.04));
        }
        .crm-ga-card h3 { margin: 0 0 10px; font-size: 14px; font-weight: 700; color: var(--crm-n-900, #18181b); }
        .crm-ga-card ul { margin: 0; padding: 0; list-style: none; }
        .crm-ga-card li {
            padding: 7px 0; font-size: 13.5px; color: var(--crm-n-700, #3f3f46); line-height: 1.45;
            border-top: 1px solid var(--crm-n-100, #f4f4f5);
        }
        .crm-ga-card li:first-child { border-top: none; padding-top: 0; }
        .crm-ga-card .crm-ga-empty { color: var(--crm-n-400, #a1a1aa); font-style: italic; }
        .crm-ga-status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px; border-radius: var(--crm-r-pill, 999px); font-size: 12px; font-weight: 600; }
        .crm-ga-status-on { background: var(--crm-ok-50, #ecfdf5); color: var(--crm-ok-700, #047857); }
        .crm-ga-status-off { background: var(--crm-n-100, #f4f4f5); color: var(--crm-n-500, #71717a); }
        .crm-ga-rule-row {
            display: grid; grid-template-columns: 1fr 1fr 1.6fr auto; gap: var(--crm-sp-3, 12px);
            align-items: start; padding: 12px 0; border-top: 1px solid var(--crm-n-100, #f4f4f5);
            font-size: 13.5px;
        }
        .crm-ga-rule-row:first-child { border-top: none; }
        .crm-ga-rule-titulo { font-weight: 700; color: var(--crm-n-900, #18181b); }
        .crm-ga-rule-dest { color: var(--crm-n-600, #52525b); }
        .crm-ga-rule-cond { color: var(--crm-n-700, #3f3f46); line-height: 1.45; }
        .crm-ga-chip { display: inline-block; padding: 2px 9px; border-radius: var(--crm-r-pill, 999px); background: var(--crm-n-100, #f4f4f5); color: var(--crm-n-600, #52525b); font-size: 11px; font-weight: 600; margin: 0 4px 4px 0; white-space: nowrap; }
        .crm-ga-condiciones {
            display: flex; flex-wrap: wrap; gap: var(--crm-sp-3, 12px); margin-bottom: var(--crm-sp-4, 16px);
        }
        .crm-ga-condicion {
            flex: 1 1 220px; background: var(--crm-n-50, #fafafa); border: 1px solid var(--crm-n-150, #ececef);
            border-radius: var(--crm-r-md, 8px); padding: 12px 14px; font-size: 13px; color: var(--crm-n-700, #3f3f46);
        }
        .crm-ga-condicion strong { display: block; color: var(--crm-n-900, #18181b); font-size: 13px; margin-bottom: 2px; }
        .crm-ga-hidden { display: none !important; }
        @media (max-width: 720px) {
            .crm-ga-rule-row { grid-template-columns: 1fr; gap: 4px; }
        }
    </style>

    <div class="crm-ga" id="crm-ga-root">
        <p class="crm-ga-intro">Qué avisa el CRM automáticamente, a quién y bajo qué condición — sin tener que leer código ni preguntarlo. Esta página se actualiza sola en cuanto algo cambia: los umbrales que ves abajo son los que están configurados ahora mismo, no un texto fijo.</p>

        <div class="crm-ga-search-wrap">
            <input type="search" id="crm-ga-search" class="crm-ga-search" placeholder="Buscar un aviso, un destinatario o una palabra…">
        </div>

        <nav class="crm-ga-nav">
            <a href="#crm-ga-negocio"><?php echo $ic ? $ic('target', 14) : ''; ?>Reglas automáticas</a>
            <a href="#crm-ga-email"><?php echo $ic ? $ic('envelope', 14) : ''; ?>Email</a>
            <a href="#crm-ga-whatsapp"><?php echo $ic ? $ic('phone', 14) : ''; ?>WhatsApp</a>
            <a href="#crm-ga-inapp"><?php echo $ic ? $ic('bell', 14) : ''; ?>In-app (campana)</a>
        </nav>

        <section class="crm-ga-section" id="crm-ga-negocio">
            <div class="crm-ga-section-head">
                <?php echo $ic ? $ic('target', 20) : ''; ?>
                <h2>Reglas automáticas por umbral</h2>
            </div>
            <p class="crm-ga-section-sub">No dependen de que alguien pulse nada — un cron las comprueba por hora y avisa solo una vez por caso.</p>
            <div class="crm-ga-card">
                <?php foreach ($reglas_negocio as $regla) : ?>
                    <div class="crm-ga-rule-row" data-ga-item>
                        <div class="crm-ga-rule-titulo"><?php echo esc_html($regla['titulo']); ?></div>
                        <div class="crm-ga-rule-dest"><?php echo esc_html($regla['destinatario']); ?></div>
                        <div class="crm-ga-rule-cond"><?php echo esc_html($regla['condicion']); ?></div>
                        <div>
                            <?php foreach ($regla['canales'] as $canal) : ?>
                                <span class="crm-ga-chip"><?php echo esc_html($canal); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="crm-ga-section" id="crm-ga-email">
            <div class="crm-ga-section-head">
                <?php echo $ic ? $ic('envelope', 20) : ''; ?>
                <h2>Avisos por email</h2>
            </div>
            <p class="crm-ga-section-sub">El CRM manda estos emails automáticamente, sin que nadie tenga que acordarse. Cada canal con el remitente que le corresponde (nunca uno genérico de WordPress).</p>
            <?php if (empty($canales_email)) : ?>
                <p class="crm-ga-empty">Todavía no hay ningún canal de email registrado.</p>
            <?php else : ?>
                <div class="crm-ga-grid">
                    <?php foreach ($canales_email as $slug => $etiqueta) : ?>
                        <div class="crm-ga-card" data-ga-item data-ga-text="<?php echo esc_attr(mb_strtolower($etiqueta)); ?>">
                            <h3><?php echo esc_html($etiqueta); ?></h3>
                            <?php $eventos = $eventos_email[$slug] ?? []; ?>
                            <?php if (empty($eventos)) : ?>
                                <p class="crm-ga-empty">Sin eventos conectados todavía.</p>
                            <?php else : ?>
                                <ul>
                                    <?php foreach ($eventos as $evento) : ?>
                                        <li><?php echo esc_html($evento); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="crm-ga-section" id="crm-ga-whatsapp">
            <div class="crm-ga-section-head">
                <?php echo $ic ? $ic('phone', 20) : ''; ?>
                <h2>Avisos por WhatsApp</h2>
                <span class="crm-ga-status <?php echo $wa_configurado ? 'crm-ga-status-on' : 'crm-ga-status-off'; ?>">
                    <?php echo $wa_configurado ? 'Conectado' : 'Sin conectar todavía'; ?>
                </span>
            </div>
            <div class="crm-ga-condiciones">
                <div class="crm-ga-condicion"><strong>El canal</strong>tiene que estar conectado (token + plantillas aprobadas por Meta).</div>
                <div class="crm-ga-condicion"><strong>La persona</strong>tiene que tener su número de WhatsApp guardado en su perfil.</div>
                <div class="crm-ga-condicion"><strong>El aviso</strong>tiene que estar activado por esa persona para ese caso en concreto.</div>
            </div>
            <p class="crm-ga-section-sub">Si falta cualquiera de las tres, simplemente no se envía nada — no da ningún error ni bloquea nada, y el email (cuando ese aviso también lo manda) llega igual. WhatsApp es siempre un extra sobre el email, nunca lo sustituye.</p>
            <div class="crm-ga-grid">
                <?php foreach (crm_guia_avisos_whatsapp_grupos() as $grupo => $opciones) : ?>
                    <div class="crm-ga-card" data-ga-item data-ga-text="<?php echo esc_attr(mb_strtolower($grupo)); ?>">
                        <h3><?php echo esc_html($grupo); ?></h3>
                        <ul>
                            <?php foreach ($opciones as $opcion) :
                                if (!isset($catalogo_wa[$opcion])) { continue; }
                            ?>
                                <li><?php echo esc_html(crm_guia_avisos_sin_destinatario($catalogo_wa[$opcion])); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="crm-ga-section" id="crm-ga-inapp">
            <div class="crm-ga-section-head">
                <?php echo $ic ? $ic('bell', 20) : ''; ?>
                <h2>Avisos in-app (la campana)</h2>
            </div>
            <p class="crm-ga-section-sub">Siempre se guardan, pase lo que pase con el email o el WhatsApp de esa persona — cada uno puede ver su historial completo en la campana de la topbar.</p>
            <div class="crm-ga-grid">
                <?php foreach ($inapp_grupos as $grupo => $tipos) : ?>
                    <div class="crm-ga-card" data-ga-item data-ga-text="<?php echo esc_attr(mb_strtolower($grupo)); ?>">
                        <h3><?php echo esc_html($grupo); ?></h3>
                        <ul>
                            <?php foreach ($tipos as $tipo) : ?>
                                <li><?php echo esc_html($tipo); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <script>
    (function () {
        var input = document.getElementById('crm-ga-search');
        if (!input) { return; }
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            document.querySelectorAll('#crm-ga-root [data-ga-item]').forEach(function (el) {
                if (q === '') { el.classList.remove('crm-ga-hidden'); return; }
                var texto = (el.getAttribute('data-ga-text') || '') + ' ' + el.textContent.toLowerCase();
                el.classList.toggle('crm-ga-hidden', texto.indexOf(q) === -1);
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * [crm_guia_avisos] — misma vista que wp-admin → CRM → Guía de avisos,
 * para crm_admin desde el frontend (no tiene acceso a wp-admin).
 */
add_shortcode('crm_guia_avisos', 'crm_guia_avisos_shortcode');
function crm_guia_avisos_shortcode() {
    if (!current_user_can('crm_admin')) {
        return '<p>No tienes permiso para ver esta sección.</p>';
    }
    return crm_guia_avisos_render_html();
}

function crm_guia_avisos_render_admin() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }
    crm_admin_page_header('CRM · Guía de avisos');
    echo crm_guia_avisos_render_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    crm_admin_page_footer();
}
