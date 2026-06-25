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
    $current_uid  = get_current_user_id();
    // v1.20.15: un comercial debe ver tanto las visitas asignadas a el como
    // las que el mismo ha creado y delegado a un visitador. Un visitador puro
    // sigue viendo solo las suyas (las que tiene asignadas).
    $is_comercial_no_visitador = !$is_admin && !$is_visitador;

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
        $args['comercial_id'] = $current_uid;
        if ($is_comercial_no_visitador) {
            // v1.20.15: incluir visitas creadas por mi (delegadas a visitadores).
            $args['or_creado_por'] = $current_uid;
        }
    } elseif ($filtro_comercial > 0) {
        $args['comercial_id'] = $filtro_comercial;
    }

    $visitas = crm_visitas_list($args);
    // v1.20.15: fallback informativo — si la consulta esta vacia y NO es admin,
    // miramos si hay visitas del usuario fuera del rango para sugerirle ampliar.
    $fuera_rango = 0;
    if (empty($visitas) && !$is_admin) {
        $args_total = $args;
        unset($args_total['desde'], $args_total['hasta'], $args_total['estado']);
        $fuera_rango = crm_visitas_count($args_total);
    }
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

        <?php // v1.20.3: bloque desplegable con la URL iCal personal del usuario. ?>
        <details style="margin-bottom:14px; padding:10px 14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;">
            <summary style="cursor:pointer; font-weight:600; color:#075985;">
                Sincronizar con Google Calendar
            </summary>
            <div style="margin-top:10px;">
                <?php echo do_shortcode('[crm_mi_gcal]'); ?>
            </div>
        </details>

        <?php
        // v1.20.16: toggle Tabla | Calendario. La preferencia se guarda en
        // localStorage. El calendario carga visitas en un rango amplio
        // (-3 meses / +12 meses) independiente del filtro desde/hasta
        // para poder navegar libremente por meses.
        $args_cal = $args;
        unset($args_cal['desde'], $args_cal['hasta'], $args_cal['estado']);
        $args_cal['desde']   = date('Y-m-d', strtotime('-3 months'));
        $args_cal['hasta']   = date('Y-m-d', strtotime('+12 months'));
        $args_cal['limit']   = 2000;
        $visitas_cal = crm_visitas_list($args_cal);
        $eventos_cal = [];
        $estado_color_map = [
            'programada' => '#3b82f6',
            'realizada'  => '#10b981',
            'cancelada'  => '#94a3b8',
            'no_show'    => '#f59e0b',
        ];
        if (!empty($visitas_cal)) {
            global $wpdb;
            $client_table = $wpdb->prefix . 'crm_clients';
            $client_ids = array_unique(array_map(static fn($v) => (int) $v['client_id'], $visitas_cal));
            $client_ids = array_filter($client_ids);
            $nombres = [];
            if (!empty($client_ids)) {
                $in_ph = implode(',', array_fill(0, count($client_ids), '%d'));
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT id, cliente_nombre FROM $client_table WHERE id IN ($in_ph)", $client_ids),
                    ARRAY_A
                );
                foreach ((array) $rows as $r) {
                    $nombres[(int) $r['id']] = (string) $r['cliente_nombre'];
                }
            }
            foreach ($visitas_cal as $v) {
                $cid   = (int) $v['client_id'];
                $title = ($nombres[$cid] ?? ('Cliente #' . $cid));
                $color = $estado_color_map[$v['estado']] ?? '#64748b';
                try {
                    $dt_start = new DateTime($v['fecha_visita']);
                    $start    = $dt_start->format('Y-m-d\TH:i:s');
                    $dt_end   = clone $dt_start;
                    $dt_end->modify('+' . max(5, (int) $v['duracion_min']) . ' minutes');
                    $end      = $dt_end->format('Y-m-d\TH:i:s');
                } catch (Exception $e) {
                    $start = $v['fecha_visita'];
                    $end   = $v['fecha_visita'];
                }
                $com_user = $v['comercial_id'] ? get_userdata((int) $v['comercial_id']) : null;
                $eventos_cal[] = [
                    'id'              => (int) $v['id'],
                    'title'           => $title,
                    'start'           => $start,
                    'end'             => $end,
                    'backgroundColor' => $color,
                    'borderColor'     => $color,
                    'extendedProps'   => [
                        'estado'    => $v['estado'],
                        'lugar'     => (string) $v['lugar'],
                        'sector'    => (string) $v['sector'],
                        'asignado'  => $com_user ? $com_user->display_name : '',
                        'client_id' => $cid,
                    ],
                ];
            }
        }
        $eventos_json = wp_json_encode($eventos_cal);
        ?>
        <div class="crm-agenda-toggle" style="display:inline-flex; gap:4px; margin-bottom:14px; background:#f1f5f9; padding:4px; border-radius:8px;">
            <button type="button" data-view="list" class="crm-agenda-toggle-btn is-active"
                style="padding:6px 14px; border:none; background:#fff; color:#0f172a; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px; box-shadow:0 1px 2px rgba(0,0,0,.06);">
                Lista
            </button>
            <button type="button" data-view="calendar" class="crm-agenda-toggle-btn"
                style="padding:6px 14px; border:none; background:transparent; color:#475569; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                Calendario
            </button>
        </div>

        <div class="crm-agenda-view crm-agenda-view-list">
        <?php if (empty($visitas)): ?>
            <p style="padding:18px; background:#fff; border:1px solid #e2e8f0; border-radius:6px;">
                No hay visitas que coincidan con los filtros.
                <?php if (!$is_admin && $fuera_rango > 0): ?>
                    <br><small style="color:#92400e;">
                        Tienes <strong><?php echo (int) $fuera_rango; ?></strong>
                        visita<?php echo $fuera_rango === 1 ? '' : 's'; ?> fuera de este rango de fechas.
                        Amplía el filtro de fechas para verlas.
                    </small>
                <?php endif; ?>
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
                        <?php if ($is_admin || $is_comercial_no_visitador): ?><th style="padding:10px 8px;">Asignado a</th><?php endif; ?>
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
                            <?php if ($is_admin || $is_comercial_no_visitador): ?>
                                <td style="padding:10px 8px;">
                                    <?php
                                    if (!$com_user) {
                                        echo '—';
                                    } elseif ((int) $com_user->ID === (int) $current_uid) {
                                        echo '<em style="color:#64748b;">Yo</em>';
                                    } else {
                                        echo esc_html($com_user->display_name);
                                        // Si soy comercial y la delegue a un visitador, marcarlo.
                                        if ($is_comercial_no_visitador && in_array('visitador', (array) $com_user->roles, true)) {
                                            echo ' <span style="display:inline-block; padding:1px 6px; background:#f59e0b; color:#fff; border-radius:8px; font-size:10px; font-weight:600;">visitador</span>';
                                        }
                                    }
                                    ?>
                                </td>
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
                                <?php if ($v['estado'] === 'programada' && function_exists('crm_gcal_get_event_url')):
                                    $gcal_url = crm_gcal_get_event_url($v, $client['cliente_nombre'] ?? '');
                                ?>
                                    <a href="<?php echo esc_url($gcal_url); ?>" target="_blank" rel="noopener noreferrer"
                                       title="Añadir a Google Calendar"
                                       style="display:inline-block; padding:4px 8px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; text-decoration:none; color:#0f172a; font-size:12px;">
                                        GCal
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        </div><!-- /.crm-agenda-view-list -->

        <div class="crm-agenda-view crm-agenda-view-calendar" style="display:none;">
            <div id="crm-agenda-calendar" style="background:#fff; padding:14px; border:1px solid #e2e8f0; border-radius:6px;"></div>
            <p style="font-size:12px; color:#64748b; margin-top:8px;">
                Pulsa una visita para abrir la ficha del cliente. Colores:
                <span style="display:inline-block; width:10px; height:10px; background:#3b82f6; border-radius:50%; vertical-align:middle;"></span> programada ·
                <span style="display:inline-block; width:10px; height:10px; background:#10b981; border-radius:50%; vertical-align:middle;"></span> realizada ·
                <span style="display:inline-block; width:10px; height:10px; background:#f59e0b; border-radius:50%; vertical-align:middle;"></span> no se present\u00f3 ·
                <span style="display:inline-block; width:10px; height:10px; background:#94a3b8; border-radius:50%; vertical-align:middle;"></span> cancelada
            </p>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script>
        (function(){
            var STORAGE_KEY = 'crmAgendaView';
            var events = <?php echo $eventos_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
            var fichaUrl = <?php echo wp_json_encode(home_url('/editar-cliente/')); ?>;

            var wrap = document.querySelector('.crm-mi-agenda-wrap');
            if (!wrap) return;
            var btns    = wrap.querySelectorAll('.crm-agenda-toggle-btn');
            var listEl  = wrap.querySelector('.crm-agenda-view-list');
            var calEl   = wrap.querySelector('.crm-agenda-view-calendar');
            var mountEl = document.getElementById('crm-agenda-calendar');
            var calendar = null;

            function activate(view){
                btns.forEach(function(b){
                    var on = (b.dataset.view === view);
                    b.classList.toggle('is-active', on);
                    b.style.background = on ? '#fff' : 'transparent';
                    b.style.color      = on ? '#0f172a' : '#475569';
                    b.style.boxShadow  = on ? '0 1px 2px rgba(0,0,0,.06)' : 'none';
                });
                if (view === 'calendar') {
                    listEl.style.display = 'none';
                    calEl.style.display  = '';
                    if (!calendar && window.FullCalendar) {
                        calendar = new FullCalendar.Calendar(mountEl, {
                            initialView: 'dayGridMonth',
                            locale: 'es',
                            firstDay: 1,
                            height: 'auto',
                            headerToolbar: {
                                left:  'prev,next today',
                                center:'title',
                                right: 'dayGridMonth,timeGridWeek,listMonth'
                            },
                            buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', list: 'Lista' },
                            events: events,
                            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                            eventClick: function(info){
                                info.jsEvent.preventDefault();
                                var cid = info.event.extendedProps.client_id;
                                if (cid) {
                                    var sep = fichaUrl.indexOf('?') === -1 ? '?' : '&';
                                    window.location.href = fichaUrl + sep + 'client_id=' + cid;
                                }
                            },
                            eventDidMount: function(info){
                                var p = info.event.extendedProps;
                                var tip = (info.event.title || '') +
                                    (p.lugar ? '\n' + p.lugar : '') +
                                    (p.asignado ? '\nAsignado: ' + p.asignado : '') +
                                    (p.estado ? '\nEstado: ' + p.estado : '');
                                info.el.setAttribute('title', tip);
                            }
                        });
                        calendar.render();
                    } else if (calendar) {
                        // por si cambia de tamano el contenedor
                        calendar.updateSize();
                    }
                } else {
                    listEl.style.display = '';
                    calEl.style.display  = 'none';
                }
                try { localStorage.setItem(STORAGE_KEY, view); } catch (e) {}
            }

            btns.forEach(function(b){
                b.addEventListener('click', function(){ activate(b.dataset.view); });
            });

            var saved = 'list';
            try { saved = localStorage.getItem(STORAGE_KEY) || 'list'; } catch (e) {}
            activate(saved);
        })();
        </script>
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
        'label'    => 'Mi agenda',
        'posicion' => 'br',
        'icono'    => 'auto', // auto = favicon del sitio | calendar | none
    ], $atts, 'crm_agenda_modal');

    $url = trim((string) get_option('crm_url_mi_agenda', ''));
    if ($url === '') {
        return '';
    }
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

    // Resolver icono: favicon del sitio si está disponible, fallback a SVG calendario.
    $icono_html = '';
    $icono_modo = sanitize_key((string) $atts['icono']);
    if ($icono_modo !== 'none') {
        $favicon = function_exists('get_site_icon_url') ? get_site_icon_url(48) : '';
        if ($icono_modo === 'auto' && $favicon) {
            $icono_html = '<img src="' . esc_url($favicon) . '" alt="" style="width:18px;height:18px;display:inline-block;vertical-align:middle;border-radius:3px;">';
        } else {
            // SVG calendario inline (neutro, hereda currentColor)
            $icono_html = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        }
    }

    ob_start();
    ?>
    <button type="button" id="crm-agenda-modal-btn"
        style="<?php echo esc_attr($btn_style); ?> z-index:99998; padding:10px 16px; background:#191919; color:#fff; border:none; border-radius:50px; box-shadow:0 4px 14px rgba(0,0,0,.25); cursor:pointer; font-weight:600; font-size:14px; display:inline-flex; align-items:center; gap:8px;"
        onclick="document.getElementById('crm-agenda-modal').style.display='flex'; document.getElementById('crm-agenda-modal-iframe').src=document.getElementById('crm-agenda-modal-iframe').dataset.src;">
        <?php echo $icono_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped (SVG/IMG controlados) ?>
        <span><?php echo esc_html($atts['label']); ?></span>
    </button>
    <div id="crm-agenda-modal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:99999; align-items:center; justify-content:center; padding:20px;"
        onclick="if(event.target===this){this.style.display='none'; document.getElementById('crm-agenda-modal-iframe').src='about:blank';}">
        <div style="background:#fff; border-radius:12px; width:100%; max-width:1200px; height:90vh; position:relative; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.4);">
            <button type="button" aria-label="Cerrar"
                style="position:absolute; top:8px; right:12px; z-index:2; background:#fff; border:1px solid #ddd; border-radius:50%; width:34px; height:34px; cursor:pointer; font-size:20px; line-height:1; display:inline-flex; align-items:center; justify-content:center; padding:0; color:#191919;"
                onclick="document.getElementById('crm-agenda-modal').style.display='none'; document.getElementById('crm-agenda-modal-iframe').src='about:blank';">&times;</button>
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