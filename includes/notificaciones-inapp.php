<?php
/**
 * Notificaciones in-app (v1.20.50).
 *
 * Distinto del `includes/notifications.php` existente (que solo envía EMAILS
 * de asignación de cliente/lead, namespace `crm_notif_*`) — este es un canal
 * nuevo y genérico: una campana en la topbar compartida (`app-shell.php`),
 * una página propia por usuario con su historial, y una vista de admin con
 * el historial completo. Cada usuario (instalador, comercial, jefe...) solo
 * ve las suyas; crm_admin/administrator ven el historial de todos.
 *
 * Emisores ya conectados (Fase 4/5 del módulo de instalaciones):
 *  - Instalador asignado a una instalación.
 *  - Visita programada/reprogramada en la agenda.
 *  - Partida extra declarada (avisa a quien puede validarla).
 *  - Partida extra aprobada/rechazada (avisa a quien la declaró).
 *
 * @package CRM_Energitel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function crm_notificaciones_table() {
	global $wpdb;
	return $wpdb->prefix . 'crm_notificaciones';
}

function crm_notificaciones_install_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	$table = crm_notificaciones_table();
	$sql = "CREATE TABLE $table (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  tipo VARCHAR(60) NOT NULL DEFAULT '',
  mensaje TEXT NOT NULL,
  url VARCHAR(500) DEFAULT NULL,
  leida TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY leida (leida),
  KEY fecha (fecha)
) $charset_collate;";
	dbDelta( $sql );
}

/**
 * Crea una notificación in-app para un usuario. Punto único usado por todos
 * los emisores — nunca hace INSERT directo nadie más, así el listado/campana
 * siempre ven todo lo que se genera.
 *
 * @param int    $user_id
 * @param string $tipo     Slug corto (p.ej. 'instalacion_asignada').
 * @param string $mensaje  Texto ya listo para mostrar.
 * @param string $url      Enlace opcional al que lleva al pulsarla.
 */
function crm_notificar( $user_id, $tipo, $mensaje, $url = '' ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || $mensaje === '' ) {
		return;
	}
	global $wpdb;
	$wpdb->insert( crm_notificaciones_table(), [
		'user_id' => $user_id,
		'tipo'    => sanitize_key( $tipo ),
		'mensaje' => $mensaje,
		'url'     => $url !== '' ? esc_url_raw( $url ) : null,
		'fecha'   => current_time( 'mysql' ),
	] );
}

/**
 * Notifica a todos los usuarios que gestionan el módulo de instalaciones
 * (jefe_instalaciones y crm_admin) — deliberadamente sin `administrator`,
 * que es la cuenta de dev/ops y no opera el día a día del módulo. Usado para
 * avisos que les corresponde revisar: partidas extra pendientes, cierres de
 * instalador pendientes de validar, etc.
 */
function crm_notificar_jefes_instalaciones( $tipo, $mensaje, $url = '' ) {
	$users = get_users( [ 'role__in' => [ 'jefe_instalaciones', 'crm_admin' ], 'fields' => 'ID' ] );
	foreach ( $users as $user_id ) {
		crm_notificar( (int) $user_id, $tipo, $mensaje, $url );
	}
}

function crm_notificaciones_get_para_usuario( $user_id, $limit = 20 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . crm_notificaciones_table() . " WHERE user_id = %d ORDER BY fecha DESC LIMIT %d",
		(int) $user_id, (int) $limit
	), ARRAY_A );
}

function crm_notificaciones_contar_no_leidas( $user_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . crm_notificaciones_table() . " WHERE user_id = %d AND leida = 0",
		(int) $user_id
	) );
}

// ──────────────────────────────────────────────────────────────────────────────
// AJAX
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_crm_notif_marcar_leida', 'crm_notif_ajax_marcar_leida' );
function crm_notif_ajax_marcar_leida() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'crm_notif', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}
	global $wpdb;
	$id = (int) ( $_POST['id'] ?? 0 );
	$wpdb->update( crm_notificaciones_table(), [ 'leida' => 1 ], [ 'id' => $id, 'user_id' => get_current_user_id() ] );
	wp_send_json_success();
}

add_action( 'wp_ajax_crm_notif_marcar_todas_leidas', 'crm_notif_ajax_marcar_todas_leidas' );
function crm_notif_ajax_marcar_todas_leidas() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'crm_notif', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}
	global $wpdb;
	$wpdb->update( crm_notificaciones_table(), [ 'leida' => 1 ], [ 'user_id' => get_current_user_id(), 'leida' => 0 ] );
	wp_send_json_success();
}

// ──────────────────────────────────────────────────────────────────────────────
// UI: campana en la topbar compartida (llamada desde crm_app_shell_render_topbar())
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Devuelve el HTML de la campana + su desplegable, listas para inyectar en la
 * topbar. Server-rendered (últimas 8) para no necesitar una petición AJAX
 * extra solo para pintar el desplegable — el AJAX solo se usa al marcar leída.
 */
function crm_notificaciones_render_campana() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '';
	}
	$nonce      = wp_create_nonce( 'crm_notif' );
	$no_leidas  = crm_notificaciones_contar_no_leidas( $user_id );
	$recientes  = crm_notificaciones_get_para_usuario( $user_id, 8 );
	$lista_url  = home_url( '/notificaciones/' );

	ob_start();
	?>
	<div class="crm-topbar__notif" id="crm-notif-wrap">
		<button type="button" class="crm-topbar__notif-btn" id="crm-notif-toggle" aria-label="Notificaciones">
			<?php echo function_exists( 'crm_icon' ) ? crm_icon( 'bell', 18 ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $no_leidas > 0 ) : ?>
				<span class="crm-topbar__notif-badge"><?php echo $no_leidas > 9 ? '9+' : (int) $no_leidas; ?></span>
			<?php endif; ?>
		</button>
		<div class="crm-topbar__notif-dropdown" id="crm-notif-dropdown" style="display:none;">
			<div class="crm-topbar__notif-header">
				<strong>Notificaciones</strong>
				<?php if ( $no_leidas > 0 ) : ?>
					<button type="button" id="crm-notif-marcar-todas">Marcar todas leídas</button>
				<?php endif; ?>
			</div>
			<?php if ( empty( $recientes ) ) : ?>
				<p class="crm-topbar__notif-empty">No tienes notificaciones.</p>
			<?php else : ?>
				<?php foreach ( $recientes as $n ) : ?>
					<a href="<?php echo esc_url( $n['url'] ?: '#' ); ?>" class="crm-topbar__notif-item<?php echo empty( $n['leida'] ) ? ' is-unread' : ''; ?>" data-notif-id="<?php echo esc_attr( $n['id'] ); ?>">
						<span class="crm-topbar__notif-msg"><?php echo esc_html( $n['mensaje'] ); ?></span>
						<span class="crm-topbar__notif-fecha"><?php echo esc_html( human_time_diff( strtotime( $n['fecha'] ), current_time( 'timestamp' ) ) ); ?></span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( $lista_url ); ?>" class="crm-topbar__notif-viewall">Ver todas</a>
		</div>
	</div>
	<script>
	(function () {
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var wrap = document.getElementById('crm-notif-wrap');
		if (!wrap) { return; }
		var toggle = document.getElementById('crm-notif-toggle');
		var dropdown = document.getElementById('crm-notif-dropdown');
		toggle.addEventListener('click', function (e) {
			e.stopPropagation();
			dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
		});
		document.addEventListener('click', function (e) {
			if (!wrap.contains(e.target)) {
				dropdown.style.display = 'none';
			}
		});
		wrap.querySelectorAll('.crm-topbar__notif-item').forEach(function (item) {
			item.addEventListener('click', function () {
				var body = new URLSearchParams();
				body.set('action', 'crm_notif_marcar_leida');
				body.set('nonce', nonce);
				body.set('id', item.getAttribute('data-notif-id'));
				fetch(ajaxurl, { method: 'POST', body: body });
			});
		});
		var marcarTodas = document.getElementById('crm-notif-marcar-todas');
		if (marcarTodas) {
			marcarTodas.addEventListener('click', function () {
				var body = new URLSearchParams();
				body.set('action', 'crm_notif_marcar_todas_leidas');
				body.set('nonce', nonce);
				fetch(ajaxurl, { method: 'POST', body: body }).then(function () { location.reload(); });
			});
		}
	})();
	</script>
	<?php
	return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// Página propia por usuario: [crm_notificaciones_lista]
// ──────────────────────────────────────────────────────────────────────────────

add_shortcode( 'crm_notificaciones_lista', 'crm_notificaciones_shortcode_lista' );
function crm_notificaciones_shortcode_lista() {
	if ( ! is_user_logged_in() ) {
		return '<p>Necesitas <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">iniciar sesión</a> para acceder.</p>';
	}

	$user_id = get_current_user_id();
	$notas   = crm_notificaciones_get_para_usuario( $user_id, 100 );

	ob_start();
	?>
	<style>
	.crm-notif-lista-wrap { max-width:700px; margin:0 auto; padding:16px 4px; font-family:system-ui,-apple-system,sans-serif; }
	.crm-notif-lista-item { display:flex; justify-content:space-between; gap:12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; text-decoration:none; color:#1f2937; }
	.crm-notif-lista-item.is-unread { border-left:4px solid #2563eb; font-weight:600; }
	.crm-notif-lista-fecha { color:#9ca3af; font-size:12px; white-space:nowrap; }
	.crm-notif-lista-empty { color:#9ca3af; font-size:13px; }
	</style>
	<div class="crm-notif-lista-wrap">
		<h2 style="margin-top:0; font-size:19px; color:#1f2937;">Notificaciones</h2>
		<?php if ( empty( $notas ) ) : ?>
			<p class="crm-notif-lista-empty">No tienes notificaciones todavía.</p>
		<?php else : ?>
			<?php foreach ( $notas as $n ) : ?>
				<a href="<?php echo esc_url( $n['url'] ?: '#' ); ?>" class="crm-notif-lista-item<?php echo empty( $n['leida'] ) ? ' is-unread' : ''; ?>">
					<span><?php echo esc_html( $n['mensaje'] ); ?></span>
					<span class="crm-notif-lista-fecha"><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $n['fecha'] ) ) ); ?></span>
				</a>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// Admin: historial completo (todas las notificaciones, todos los usuarios)
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_submenu_page(
		'crm-dashboard',
		'Notificaciones',
		'Notificaciones',
		'crm_admin',
		'crm-notificaciones',
		'crm_notificaciones_render_admin'
	);
}, 20 );

function crm_notificaciones_render_admin() {
	if ( ! current_user_can( 'crm_admin' ) ) {
		wp_die( 'Acceso denegado' );
	}
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT n.*, u.display_name FROM " . crm_notificaciones_table() . " n
		 LEFT JOIN {$wpdb->users} u ON u.ID = n.user_id
		 ORDER BY n.fecha DESC LIMIT 200",
		ARRAY_A
	);

	crm_admin_page_header( 'CRM · Notificaciones' );
	?>
	<p class="description">Historial de las últimas 200 notificaciones in-app generadas para cualquier usuario (instaladores, comerciales, jefes...).</p>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th>Fecha</th><th>Usuario</th><th>Tipo</th><th>Mensaje</th><th>Leída</th></tr></thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5">Sin notificaciones todavía.</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $r['fecha'] ) ) ); ?></td>
						<td><?php echo esc_html( $r['display_name'] ?: ( 'Usuario #' . $r['user_id'] ) ); ?></td>
						<td><?php echo esc_html( $r['tipo'] ); ?></td>
						<td><?php echo esc_html( $r['mensaje'] ); ?></td>
						<td><?php echo empty( $r['leida'] ) ? '—' : '✓'; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php
	crm_admin_page_footer();
}
