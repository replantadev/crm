<?php
/**
 * Shortcode [crm_mi_agenda] — vista frontend de "Mi agenda".
 *
 * Permite a visitadores y comerciales gestionar sus visitas sin entrar al
 * wp-admin. El admin (administrator/crm_admin) ve todas; el resto solo las
 * que tiene asignadas (comercial_id == su user_id).
 *
 * Uso: insertar [crm_mi_agenda] en una página/post.
 *
 * @package CRM_Energitel
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('crm_mi_agenda', 'crm_shortcode_mi_agenda');
function crm_shortcode_mi_agenda($atts = []) {
    if (!is_user_logged_in()) {
        $login_url = wp_login_url(get_permalink());
        return '<div class="crm-notification error" style="padding:14px;background:#fee;border:1px solid #f99;border-radius:6px;">
            Necesitas <a href="' . esc_url($login_url) . '">iniciar sesión</a> para ver tu agenda.
        </div>';
    }

    if (!function_exists('crm_visitas_list')) {
        return '<p>El módulo de visitas no está disponible.</p>';
    }

    $is_admin     = function_exists('crm_user_is_admin')     && crm_user_is_admin();
    $is_visitador = function_exists('crm_user_is_visitador') && crm_user_is_visitador();

    // Filtros (GET)
    $filtro_estado    = isset($_GET['estado']) ? sanitize_key($_GET['estado']) : '';
    $filtro_desde     = isset($_GET['desde']) ? sanitize_text_field((string) $_GET['desde']) : date('Y-m-d');
    $filtro_hasta     = isset($_GET['hasta']) ? sanitize_text_field((string) $_GET['hasta']) : date('Y-m-d', strtotime('+30 days'));
    $filtro_comercial = $is_admin && isset($_GET['comercial_id']) ? (int) $_GET['comercial_id'] : 0;

    $args = [
        'estado'  => $filtro_estado,
        'desde'   => $filtro_desde,
        'hasta'   => $filtro_hasta,
        'limit'   => 500,
        'orderby' => 'fecha_visita',
        'order'   => 'ASC',
    ];
    if (!$is_admin) {
        $args['comercial_id'] = get_current_user_id();
    } elseif ($filtro_comercial > 0) {
        $args['comercial_id'] = $filtro_comercial;
    }

    $visitas = crm_visitas_list($args);
    $estados = crm_visitas_estados();

    $msg = isset($_GET['crm_visita_msg']) ? sanitize_key($_GET['crm_visita_msg']) : '';

    ob_start();
    ?>
    <div class="crm-mi-agenda-wrap" style="max-width:1100px; margin:0 auto; padding:18px; font-family:system-ui,-apple-system,sans-serif;">

        <h2 style="display:flex; align-items:center; gap:10px; margin-top:0;">
            <?php echo function_exists('crm_icon') ? crm_icon('calendar', 22) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            Mi agenda
        </h2>

        <?php if ($msg === 'created'): ?>
            <div style="padding:10px; background:#e6ffed; border:1px solid #5ad06e; border-radius:6px; margin-bottom:12px;">Visita creada correctamente.</div>
        <?php elseif ($msg === 'updated'): ?>
            <div style="padding:10px; background:#e6ffed; border:1px solid #5ad06e; border-radius:6px; margin-bottom:12px;">Visita actualizada.</div>
        <?php elseif ($msg === 'error'): ?>
            <div style="padding:10px; background:#fff0f0; border:1px solid #f99; border-radius:6px; margin-bottom:12px;">
                <?php echo esc_html(urldecode((string)($_GET['crm_msg'] ?? 'Error.'))); ?>
            </div>
        <?php endif; ?>

        <form method="get" style="margin:14px 0; padding:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <label style="display:flex; flex-direction:column;">
                <span style="font-size:12px; font-weight:600;">Desde</span>
                <input type="date" name="desde" value="<?php echo esc_attr($filtro_desde); ?>">
            </label>
            <label style="display:flex; flex-direction:column;">
                <span style="font-size:12px; font-weight:600;">Hasta</span>
                <input type="date" name="hasta" value="<?php echo esc_attr($filtro_hasta); ?>">
            </label>
            <label style="display:flex; flex-direction:column;">
                <span style="font-size:12px; font-weight:600;">Estado</span>
                <select name="estado">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $k => $lbl): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($filtro_estado, $k); ?>><?php echo esc_html($lbl); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($is_admin): ?>
                <label style="display:flex; flex-direction:column;">
                    <span style="font-size:12px; font-weight:600;">Asignado a</span>
                    <?php wp_dropdown_users([
                        'name'             => 'comercial_id',
                        'role__in'         => ['comercial', 'crm_admin', 'administrator', 'visitador'],
                        'selected'         => $filtro_comercial,
                        'show_option_all'  => 'Todos',
                    ]); ?>
                </label>
            <?php endif; ?>
            <button type="submit" style="padding:8px 14px; background:#191919; color:#fff; border:none; border-radius:4px; cursor:pointer;">Filtrar</button>
        </form>

        <?php
        $hoy  = crm_visitas_count(array_merge($args, ['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d')]));
        $sem  = crm_visitas_count(array_merge($args, ['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d', strtotime('+7 days'))]));
        $prog = crm_visitas_count(array_merge($args, ['estado' => 'programada']));
        ?>
        <div style="display:flex; gap:14px; margin-bottom:18px; flex-wrap:wrap;">
            <div style="flex:1; min-width:140px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:14px;">
                <div style="font-size:12px; color:#666;">Hoy</div>
                <div style="font-size:30px; font-weight:700;"><?php echo (int) $hoy; ?></div>
            </div>
            <div style="flex:1; min-width:140px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:14px;">
                <div style="font-size:12px; color:#666;">Próximos 7 días</div>
                <div style="font-size:30px; font-weight:700;"><?php echo (int) $sem; ?></div>
            </div>
            <div style="flex:1; min-width:140px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:14px;">
                <div style="font-size:12px; color:#666;">Programadas</div>
                <div style="font-size:30px; font-weight:700;"><?php echo (int) $prog; ?></div>
            </div>
        </div>

        <?php if (empty($visitas)): ?>
            <p style="padding:18px; background:#fff; border:1px solid #e2e8f0; border-radius:6px;">
                No hay visitas que coincidan con los filtros.
            </p>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; background:#fff; font-size:14px;">
                <thead>
                    <tr style="background:#f1f5f9; text-align:left;">
                        <th style="padding:10px 8px;">Fecha</th>
                        <th style="padding:10px 8px;">Cliente</th>
                        <?php if ($is_visitador): ?>
                            <th style="padding:10px 8px;">Teléfono</th>
                            <th style="padding:10px 8px;">Dirección</th>
                        <?php endif; ?>
                        <th style="padding:10px 8px;">Sector</th>
                        <th style="padding:10px 8px;">Lugar</th>
                        <th style="padding:10px 8px;">Estado</th>
                        <?php if ($is_admin): ?><th style="padding:10px 8px;">Asignado a</th><?php endif; ?>
                        <th style="padding:10px 8px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    global $wpdb;
                    $client_table = $wpdb->prefix . 'crm_clients';
                    foreach ($visitas as $v):
                        $client_fields = $is_visitador
                            ? 'id, cliente_nombre, telefono, direccion, poblacion'
                            : 'id, cliente_nombre, telefono, poblacion';
                        $client = $wpdb->get_row($wpdb->prepare("SELECT $client_fields FROM $client_table WHERE id = %d", (int) $v['client_id']), ARRAY_A);
                        $com_user = $v['comercial_id'] ? get_userdata((int) $v['comercial_id']) : null;
                        $fecha_dt = mysql2date(get_option('date_format') . ' H:i', $v['fecha_visita']);
                        $can = crm_visita_can_manage($v);
                        $estado_label = $estados[$v['estado']] ?? $v['estado'];
                        $estado_color = '#64748b';
                        if ($v['estado'] === 'programada') {
                            $estado_color = '#3b82f6';
                        } elseif ($v['estado'] === 'realizada') {
                            $estado_color = '#10b981';
                        } elseif ($v['estado'] === 'no_show') {
                            $estado_color = '#f59e0b';
                        }
                    ?>
                        <tr style="border-top:1px solid #e2e8f0;">
                            <td style="padding:10px 8px; font-weight:600;"><?php echo esc_html($fecha_dt); ?></td>
                            <td style="padding:10px 8px;">
                                <?php echo $client ? esc_html($client['cliente_nombre']) : '<em>Cliente #' . (int) $v['client_id'] . '</em>'; ?>
                            </td>
                            <?php if ($is_visitador): ?>
                                <td style="padding:10px 8px;">
                                    <?php if ($client && !empty($client['telefono'])): ?>
                                        <a href="tel:<?php echo esc_attr($client['telefono']); ?>"><?php echo esc_html($client['telefono']); ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td style="padding:10px 8px;">
                                    <?php
                                    $addr_parts = [];
                                    if ($client) {
                                        if (!empty($client['direccion'])) {
                                            $addr_parts[] = $client['direccion'];
                                        }
                                        if (!empty($client['poblacion'])) {
                                            $addr_parts[] = $client['poblacion'];
                                        }
                                    }
                                    $addr = implode(', ', $addr_parts);
                                    if ($addr !== ''):
                                    ?>
                                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo rawurlencode($addr); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html($addr); ?>
                                        </a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="padding:10px 8px;"><?php echo $v['sector'] ? esc_html(ucfirst($v['sector'])) : '—'; ?></td>
                            <td style="padding:10px 8px;"><?php echo esc_html($v['lugar']); ?></td>
                            <td style="padding:10px 8px;">
                                <span style="display:inline-block; padding:2px 8px; background:<?php echo esc_attr($estado_color); ?>; color:#fff; border-radius:10px; font-size:12px; font-weight:600;">
                                    <?php echo esc_html($estado_label); ?>
                                </span>
                            </td>
                            <?php if ($is_admin): ?>
                                <td style="padding:10px 8px;"><?php echo $com_user ? esc_html($com_user->display_name) : '—'; ?></td>
                            <?php endif; ?>
                            <td style="padding:10px 8px; white-space:nowrap;">
                                <?php if ($can && $v['estado'] === 'programada'): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="realizada">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(get_permalink()); ?>">
                                        <button type="submit" style="padding:4px 8px; cursor:pointer;" title="Realizada">✓</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="no_show">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(get_permalink()); ?>">
                                        <button type="submit" style="padding:4px 8px; cursor:pointer;" title="No se presentó">!</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('crm_visita_estado'); ?>
                                        <input type="hidden" name="action" value="crm_visita_estado">
                                        <input type="hidden" name="visita_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="estado" value="cancelada">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(get_permalink()); ?>">
                                        <button type="submit" style="padding:4px 8px; cursor:pointer;" title="Cancelar" onclick="return confirm('¿Cancelar?');">✕</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


/**
 * Shortcode [crm_agenda_modal] � bot�n flotante que abre la agenda en un modal.
 *
 * Pinta un bot�n fijo en la esquina (configurable). Al pulsarlo abre un
 * overlay modal que carga la p�gina configurada en `crm_url_mi_agenda` como
 * iframe � as� reutilizamos una sola implementaci�n (el shortcode
 * [crm_mi_agenda] de la p�gina configurada) sin duplicar l�gica.
 *
 * Atributos:
 *  - label  : texto del bot�n (def: "?? Mi agenda")
 *  - posicion : "br" (bottom-right, def) | "bl" | "tr" | "tl" | "inline"
 *
 * Uso t�pico: a�adirlo al panel del comercial o al header del CRM.
 */
add_shortcode('crm_agenda_modal', 'crm_shortcode_agenda_modal');
function crm_shortcode_agenda_modal($atts = []) {
    if (!is_user_logged_in()) {
        return '';
    }
    $atts = shortcode_atts([
        'label'    => '?? Mi agenda',
        'posicion' => 'br',
    ], $atts, 'crm_agenda_modal');

    $url = trim((string) get_option('crm_url_mi_agenda', ''));
    if ($url === '') {
        // Sin URL configurada: no podemos cargar el iframe. Mostrar nada.
        return '';
    }
    // A�adir ?crm_modal=1 al iframe para que el shortcode interno pueda
    // ocultar header/footer del tema si se desea (queda como hook futuro).
    $iframe_url = add_query_arg('crm_modal', '1', $url);

    $pos = sanitize_key((string) $atts['posicion']);
    $pos_styles = [
        'br' => 'position:fixed; bottom:24px; right:24px;',
        'bl' => 'position:fixed; bottom:24px; left:24px;',
        'tr' => 'position:fixed; top:24px; right:24px;',
        'tl' => 'position:fixed; top:24px; left:24px;',
        'inline' => '',
    ];
    $btn_style = $pos_styles[$pos] ?? $pos_styles['br'];

    ob_start();
    ?>
    <button type="button" id="crm-agenda-modal-btn"
        style="<?php echo esc_attr($btn_style); ?> z-index:99998; padding:12px 18px; background:#191919; color:#fff; border:none; border-radius:50px; box-shadow:0 4px 14px rgba(0,0,0,.25); cursor:pointer; font-weight:600; font-size:14px;"
        onclick="document.getElementById('crm-agenda-modal').style.display='flex'; document.getElementById('crm-agenda-modal-iframe').src=document.getElementById('crm-agenda-modal-iframe').dataset.src;">
        <?php echo esc_html($atts['label']); ?>
    </button>
    <div id="crm-agenda-modal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:99999; align-items:center; justify-content:center; padding:20px;"
        onclick="if(event.target===this){this.style.display='none'; document.getElementById('crm-agenda-modal-iframe').src='about:blank';}">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:1200px; height:90vh; position:relative; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.4);">
            <button type="button" aria-label="Cerrar"
                style="position:absolute; top:8px; right:12px; z-index:2; background:#fff; border:1px solid #ddd; border-radius:50%; width:34px; height:34px; cursor:pointer; font-size:18px; line-height:1;"
                onclick="document.getElementById('crm-agenda-modal').style.display='none'; document.getElementById('crm-agenda-modal-iframe').src='about:blank';">�</button>
            <iframe id="crm-agenda-modal-iframe"
                src="about:blank"
                data-src="<?php echo esc_url($iframe_url); ?>"
                style="width:100%; height:100%; border:0; display:block;"
                title="Mi agenda"></iframe>
        </div>
    </div>
    <script>
    (function(){
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') {
                var m = document.getElementById('crm-agenda-modal');
                if (m && m.style.display !== 'none') {
                    m.style.display = 'none';
                    var f = document.getElementById('crm-agenda-modal-iframe');
                    if (f) f.src = 'about:blank';
                }
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}