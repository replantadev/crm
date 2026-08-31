<?php
/**
 * CRM — Página "Flujos" (wp-admin → CRM → Flujos) + roadmap del módulo de
 * instalaciones (también en /panel-de-control/, ver crm_admin_panel_widget()
 * en shortcodes.php — misma fuente de datos, dos vistas).
 *
 * Explica visualmente (diagramas mermaid) lo que el CRM hace de verdad, con
 * un objetivo explícito: que nunca se desincronice del código.
 *
 * Por eso NI los diagramas NI el roadmap son un documento aparte que alguien
 * tiene que acordarse de actualizar — cada módulo los registra con los
 * filtros `crm_flujos_diagramas` / `crm_roadmap_fases`, junto al código que
 * describen (ver el bloque al final de `includes/instalaciones.php` como
 * ejemplo). Si el día de mañana un módulo cambia y nadie actualiza su
 * entrada, al menos seguirá estando al lado del código que ya no describe
 * bien, no perdido en otro sitio.
 *
 * v1.20.89: 2026-08-30 — el roadmap (crm_roadmap_fases) se añade a la vez
 * que se descubre que esta página de wp-admin es invisible para un crm_admin
 * real (admin-lockdown.php bloquea todo wp-admin salvo `administrator`) — se
 * decide con el usuario mostrar ambos (diagramas + roadmap) también en
 * /panel-de-control/, reutilizando el mismo render, no una copia.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagramas registrados por los distintos módulos del plugin.
 *
 * @return array<int, array{title:string, description?:string, mermaid:string}>
 */
function crm_flujos_get_diagramas() {
    return apply_filters('crm_flujos_diagramas', []);
}

/**
 * Roadmap: una fila por fase del módulo de instalaciones, con su estado
 * real. Estados válidos: 'hecho', 'en_pruebas', 'pendiente', 'bloqueado'.
 *
 * @return array<int, array{fase:string, titulo:string, estado:string, detalle:string}>
 */
function crm_roadmap_get_fases() {
    return apply_filters('crm_roadmap_fases', []);
}

/**
 * CSS compartido entre la vista de wp-admin y la de /panel-de-control/ —
 * una sola definición para que ambas se vean igual sin duplicar reglas.
 */
function crm_flujos_roadmap_shared_css() {
    return <<<CSS
    .crm-roadmap-tabla { width:100%; border-collapse:collapse; margin-bottom:8px; }
    .crm-roadmap-tabla th, .crm-roadmap-tabla td { text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
    .crm-roadmap-tabla th { font-size:12px; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; }
    .crm-roadmap-fase { white-space:nowrap; font-weight:600; color:#1f2937; }
    .crm-roadmap-detalle { color:#4b5563; font-size:13px; }
    .crm-roadmap-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
    .crm-roadmap-badge-hecho { background:#d1fae5; color:#065f46; }
    .crm-roadmap-badge-en_pruebas { background:#fef3c7; color:#92400e; }
    .crm-roadmap-badge-pendiente { background:#f3f4f6; color:#6b7280; }
    .crm-roadmap-badge-bloqueado { background:#fee2e2; color:#991b1b; }
    .crm-flujo-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:20px; margin-bottom:24px; }
    .crm-flujo-card h2, .crm-flujo-card h3 { margin-top:0; }
    .crm-flujo-card p { color:#666; }
    CSS;
}

/**
 * Etiqueta legible de cada estado del roadmap.
 */
function crm_roadmap_estado_label($estado) {
    $labels = [
        'hecho'      => 'Hecho',
        'en_pruebas' => 'Construido — falta probar',
        'pendiente'  => 'Pendiente',
        'bloqueado'  => 'Bloqueado',
    ];
    return $labels[$estado] ?? ucfirst($estado);
}

/**
 * Tabla de roadmap como HTML, reutilizada por wp-admin y por
 * /panel-de-control/ — cambiar aquí cambia las dos vistas a la vez.
 *
 * @param array $fases
 * @return string
 */
function crm_roadmap_render_html(array $fases) {
    if (empty($fases)) {
        return '<p><em>Todavía no hay ningún roadmap registrado.</em></p>';
    }
    ob_start();
    ?>
    <div style="overflow-x:auto;">
    <table class="crm-roadmap-tabla">
        <thead>
            <tr><th>Fase</th><th>Qué es</th><th>Estado</th><th>Detalle</th></tr>
        </thead>
        <tbody>
            <?php foreach ($fases as $f) : ?>
                <tr>
                    <td class="crm-roadmap-fase"><?php echo esc_html($f['fase'] ?? ''); ?></td>
                    <td><?php echo esc_html($f['titulo'] ?? ''); ?></td>
                    <td><span class="crm-roadmap-badge crm-roadmap-badge-<?php echo esc_attr($f['estado'] ?? 'pendiente'); ?>"><?php echo esc_html(crm_roadmap_estado_label($f['estado'] ?? 'pendiente')); ?></span></td>
                    <td class="crm-roadmap-detalle"><?php echo esc_html($f['detalle'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Tarjetas de diagramas mermaid como HTML — reutilizado por wp-admin y por
 * /panel-de-control/. `$incluir_mermaid_js` se desactiva si la página que
 * llama ya se encarga de cargar la librería (no es el caso de ninguna hoy,
 * pero evita cargarla dos veces si el día de mañana se combinan ambas
 * secciones en la misma página).
 *
 * @param array $diagramas
 * @param bool  $incluir_mermaid_js
 * @return string
 */
function crm_flujos_render_diagramas_html(array $diagramas, $incluir_mermaid_js = true) {
    if (empty($diagramas)) {
        return '<p><em>Todavía no hay ningún diagrama registrado.</em></p>';
    }
    ob_start();
    foreach ($diagramas as $d) {
        echo '<div class="crm-flujo-card">';
        echo '<h3>' . esc_html($d['title'] ?? '') . '</h3>';
        if (!empty($d['description'])) {
            echo '<p>' . esc_html($d['description']) . '</p>';
        }
        echo '<pre class="mermaid">' . esc_html($d['mermaid'] ?? '') . '</pre>';
        echo '</div>';
    }
    if ($incluir_mermaid_js) {
        ?>
        <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof mermaid !== 'undefined') {
                mermaid.initialize({ startOnLoad: true, theme: 'neutral' });
            }
        });
        </script>
        <?php
    }
    return ob_get_clean();
}

function crm_flujos_render_admin() {
    if (!current_user_can('crm_admin')) {
        wp_die('Sin permisos');
    }

    crm_admin_page_header('CRM · Flujos');
    ?>
    <style><?php echo crm_flujos_roadmap_shared_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>

    <div class="crm-flujo-card">
        <h2 style="margin-top:0;">Roadmap del módulo de instalaciones</h2>
        <p style="max-width:820px;color:#555;">Estado real de cada fase — lo registra el módulo junto a su código (<code>add_filter('crm_roadmap_fases', ...)</code>), igual que los diagramas de abajo. También visible para <code>crm_admin</code> en <code>/panel-de-control/</code>.</p>
        <?php echo crm_roadmap_render_html(crm_roadmap_get_fases()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>

    <p style="max-width:820px;color:#555;">
        Cada diagrama lo registra el módulo que describe, junto a su código
        (busca <code>add_filter('crm_flujos_diagramas', ...)</code> en el
        archivo correspondiente). Si algo de aquí no coincide con lo que
        hace el CRM, el sitio a corregir es ese <code>add_filter</code>, no
        esta página.
    </p>
    <?php echo crm_flujos_render_diagramas_html(crm_flujos_get_diagramas()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
    crm_admin_page_footer();
}
