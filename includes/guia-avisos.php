<?php
/**
 * CRM — Guía de avisos (wp-admin → CRM → Guía de avisos, y frontend para
 * crm_admin vía [crm_guia_avisos]).
 *
 * Página puramente explicativa, sin lenguaje técnico: qué avisa el CRM por
 * email y por WhatsApp, a quién y cuándo. Pedido por el usuario 2026-09-26
 * tras la auditoría del sistema de WhatsApp (ver PLAN_instalaciones.md).
 *
 * Mismo criterio que includes/flujos-page.php: el contenido se LEE de las
 * fuentes que ya existen y ya se mantienen al día (crm_mail_canales() /
 * crm_mail_eventos_por_canal() en mail-settings.php, crm_whatsapp_catalogo()
 * en whatsapp-api.php) — esta página no es un documento aparte que alguien
 * tenga que acordarse de actualizar a mano; si mañana se añade un canal o
 * una plantilla ahí, aparece aquí solo con darse de alta.
 *
 * @package CRM_Energitel
 * @since 1.20.159
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agrupación de las plantillas de WhatsApp por destinatario, para que se lea
 * de un vistazo "quién recibe qué" en vez de una lista plana de 11 filas.
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
 * HTML común a wp-admin y al shortcode del frontend — mismo criterio que
 * crm_flujos_render_diagramas_html() en flujos-page.php.
 */
function crm_guia_avisos_render_html() {
    $canales_email = function_exists('crm_mail_canales') ? crm_mail_canales() : [];
    $eventos_email = function_exists('crm_mail_eventos_por_canal') ? crm_mail_eventos_por_canal() : [];
    $catalogo_wa   = function_exists('crm_whatsapp_catalogo') ? crm_whatsapp_catalogo() : [];
    $wa_configurado = function_exists('crm_whatsapp_configurado') && crm_whatsapp_configurado();

    ob_start();
    ?>
    <style>
        .crm-guia-avisos-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:20px; margin-bottom:20px; }
        .crm-guia-avisos-card h2, .crm-guia-avisos-card h3 { margin-top:0; }
        .crm-guia-avisos-canal { margin-bottom:18px; }
        .crm-guia-avisos-canal:last-child { margin-bottom:0; }
        .crm-guia-avisos-canal h4 { margin:0 0 6px; color:#1f2937; }
        .crm-guia-avisos-canal ul { margin:0; padding-left:20px; color:#4b5563; }
        .crm-guia-avisos-canal li { margin-bottom:4px; }
        .crm-guia-avisos-nota { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; color:#475569; font-size:13.5px; margin-bottom:18px; }
        .crm-guia-avisos-estado { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; }
        .crm-guia-avisos-estado-on { background:#d1fae5; color:#065f46; }
        .crm-guia-avisos-estado-off { background:#f3f4f6; color:#6b7280; }
    </style>

    <div class="crm-guia-avisos-card">
        <h2>📧 Avisos por email</h2>
        <p style="max-width:820px;color:#555;">El CRM manda estos emails automáticamente, sin que nadie tenga que acordarse. Cada uno con el remitente que le corresponde (nunca uno genérico de WordPress).</p>
        <?php if (empty($canales_email)) : ?>
            <p><em>Todavía no hay ningún canal de email registrado.</em></p>
        <?php else : ?>
            <?php foreach ($canales_email as $slug => $etiqueta) : ?>
                <div class="crm-guia-avisos-canal">
                    <h4><?php echo esc_html($etiqueta); ?></h4>
                    <?php $eventos = $eventos_email[$slug] ?? []; ?>
                    <?php if (empty($eventos)) : ?>
                        <p style="color:#9ca3af;font-size:13px;margin:0;"><em>Sin eventos conectados todavía.</em></p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($eventos as $evento) : ?>
                                <li><?php echo esc_html($evento); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="crm-guia-avisos-card">
        <h2>💬 Avisos por WhatsApp</h2>
        <p>
            Estado del canal:
            <?php if ($wa_configurado) : ?>
                <span class="crm-guia-avisos-estado crm-guia-avisos-estado-on">● Conectado</span>
            <?php else : ?>
                <span class="crm-guia-avisos-estado crm-guia-avisos-estado-off">● Sin conectar todavía</span>
            <?php endif; ?>
        </p>
        <div class="crm-guia-avisos-nota">
            El WhatsApp es siempre un extra sobre el email, nunca lo sustituye. Para que llegue de verdad tienen que darse <strong>las tres</strong> cosas a la vez: el canal esté conectado, la persona tenga su número guardado, y esa persona haya activado ese aviso en concreto. Si falta cualquiera de las tres, simplemente no se envía nada — no da ningún error ni bloquea nada, y el email (cuando ese aviso también lo manda) llega igual.
        </div>
        <?php foreach (crm_guia_avisos_whatsapp_grupos() as $grupo => $opciones) : ?>
            <div class="crm-guia-avisos-canal">
                <h4><?php echo esc_html($grupo); ?></h4>
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
