<?php
/**
 * Módulo de control de instalaciones.
 *
 * Tablas gestionadas:
 *   - {prefix}crm_instalaciones
 *   - {prefix}crm_instalacion_instaladores  (pivote N:M)
 *   - {prefix}crm_instalacion_trabajos
 *   - {prefix}crm_instalacion_documentos
 *   - {prefix}crm_instalacion_agenda
 *   - {prefix}crm_instalacion_log
 *
 * Roles: jefe_instalaciones (acceso completo), instalador (solo lo suyo).
 *
 * @package CRM_Energitel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers de nombre de tabla
// ──────────────────────────────────────────────────────────────────────────────

function crm_inst_table_instalaciones() {
	global $wpdb;
	return $wpdb->prefix . 'crm_instalaciones';
}

function crm_inst_table_instaladores() {
	global $wpdb;
	return $wpdb->prefix . 'crm_instalacion_instaladores';
}

function crm_inst_table_trabajos() {
	global $wpdb;
	return $wpdb->prefix . 'crm_instalacion_trabajos';
}

function crm_inst_table_documentos() {
	global $wpdb;
	return $wpdb->prefix . 'crm_instalacion_documentos';
}

function crm_inst_table_agenda() {
	global $wpdb;
	return $wpdb->prefix . 'crm_instalacion_agenda';
}

function crm_inst_table_log() {
	global $wpdb;
	return $wpdb->prefix . 'crm_instalacion_log';
}

// ──────────────────────────────────────────────────────────────────────────────
// Creación / actualización de tablas (dbDelta)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Crea o actualiza las 6 tablas del módulo mediante dbDelta.
 * Idempotente: se puede llamar en activación y en plugins_loaded.
 *
 * NOTA sobre dbDelta:
 * - Cada columna en su propia línea (tabulación, no espacios mixtos).
 * - PRIMARY KEY con dos espacios antes del paréntesis.
 * - Sin FK CONSTRAINT (dbDelta no las maneja; la integridad se mantiene por código).
 */
function crm_instalaciones_install_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// 1. crm_instalaciones ─ tabla principal
	$t1   = crm_inst_table_instalaciones();
	$sql1 = "CREATE TABLE $t1 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT(20) UNSIGNED DEFAULT NULL,
  holded_doc_id VARCHAR(100) DEFAULT NULL,
  holded_doc_type VARCHAR(50) DEFAULT NULL,
  holded_project_id VARCHAR(100) DEFAULT NULL,
  direccion_instalacion TEXT DEFAULT NULL,
  estado ENUM('borrador','pendiente','planificada','en_ejecucion','bloqueada','lista','finalizada','reagendada','cancelada') NOT NULL DEFAULT 'borrador',
  jefe_instalaciones_id BIGINT(20) UNSIGNED DEFAULT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_cierre DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY jefe_instalaciones_id (jefe_instalaciones_id),
  KEY estado (estado)
) $charset_collate;";

	// 2. crm_instalacion_instaladores ─ pivote N:M instalacion <-> user
	$t2   = crm_inst_table_instaladores();
	$sql2 = "CREATE TABLE $t2 (
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  rol_en_proyecto VARCHAR(100) DEFAULT NULL,
  horas_declaradas DECIMAL(6,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY  (instalacion_id,user_id),
  KEY user_id (user_id)
) $charset_collate;";

	// 3. crm_instalacion_trabajos
	$t3   = crm_inst_table_trabajos();
	$sql3 = "CREATE TABLE $t3 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  descripcion TEXT NOT NULL,
  estado VARCHAR(50) NOT NULL DEFAULT 'pendiente',
  es_extraordinario TINYINT(1) NOT NULL DEFAULT 0,
  declarado_por BIGINT(20) UNSIGNED NOT NULL,
  validado_por BIGINT(20) UNSIGNED DEFAULT NULL,
  validado_en DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY instalacion_id (instalacion_id),
  KEY declarado_por (declarado_por)
) $charset_collate;";

	// 4. crm_instalacion_documentos
	$t4   = crm_inst_table_documentos();
	$sql4 = "CREATE TABLE $t4 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  tipo ENUM('presupuesto','albaran','foto','certificado','acta') NOT NULL,
  ruta VARCHAR(500) NOT NULL DEFAULT '',
  subido_por BIGINT(20) UNSIGNED NOT NULL,
  subido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY instalacion_id (instalacion_id),
  KEY tipo (tipo)
) $charset_collate;";

	// 5. crm_instalacion_agenda
	$t5   = crm_inst_table_agenda();
	$sql5 = "CREATE TABLE $t5 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  fecha_cita DATETIME NOT NULL,
  estado ENUM('pendiente','confirmada','reagendada') NOT NULL DEFAULT 'pendiente',
  instalador_id BIGINT(20) UNSIGNED NOT NULL,
  PRIMARY KEY  (id),
  KEY instalacion_id (instalacion_id),
  KEY instalador_id (instalador_id),
  KEY fecha_cita (fecha_cita)
) $charset_collate;";

	// 6. crm_instalacion_log
	$t6   = crm_inst_table_log();
	$sql6 = "CREATE TABLE $t6 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  entity_type VARCHAR(80) NOT NULL DEFAULT '',
  accion VARCHAR(100) NOT NULL DEFAULT '',
  user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detalle LONGTEXT DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY instalacion_id (instalacion_id),
  KEY accion (accion),
  KEY user_id (user_id),
  KEY fecha (fecha)
) $charset_collate;";

	dbDelta( $sql1 );
	dbDelta( $sql2 );
	dbDelta( $sql3 );
	dbDelta( $sql4 );
	dbDelta( $sql5 );
	dbDelta( $sql6 );
}

// ──────────────────────────────────────────────────────────────────────────────
// Definición de capacidades
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Todas las caps del módulo (acceso completo: jefe_instalaciones).
 *
 * @return array<string, bool>
 */
function crm_jefe_instalaciones_caps() {
	return [
		'read'                    => true,
		'crm_inst_view_all'       => true,
		'crm_inst_manage'         => true,
		'crm_inst_view_own'       => true,
		'crm_inst_edit_own'       => true,
		'crm_inst_validate_extra' => true,
		'crm_inst_close'          => true,
	];
}

/**
 * Caps del rol instalador: solo sus propias instalaciones.
 *
 * @return array<string, bool>
 */
function crm_instalador_caps() {
	return [
		'read'              => true,
		'crm_inst_view_own' => true,
		'crm_inst_edit_own' => true,
	];
}

/**
 * Caps elevadas que solo pueden tener jefe_instalaciones y administrator.
 * Se revocan dinámicamente en el filtro user_has_cap para cualquier otro rol.
 *
 * @return string[]
 */
function crm_inst_privileged_caps() {
	return [
		'crm_inst_view_all',
		'crm_inst_manage',
		'crm_inst_validate_extra',
		'crm_inst_close',
	];
}

// ──────────────────────────────────────────────────────────────────────────────
// Gestión de roles
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Crea o actualiza los roles de instalaciones. Idempotente.
 */
function crm_install_instalaciones_roles() {
	if ( ! function_exists( 'add_role' ) ) {
		return;
	}

	// Rol jefe_instalaciones
	if ( ! get_role( 'jefe_instalaciones' ) ) {
		add_role( 'jefe_instalaciones', __( 'Jefe de Instalaciones', 'crm-basico' ), crm_jefe_instalaciones_caps() );
	} else {
		$role = get_role( 'jefe_instalaciones' );
		foreach ( crm_jefe_instalaciones_caps() as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			}
		}
	}

	// Rol instalador
	if ( ! get_role( 'instalador' ) ) {
		add_role( 'instalador', __( 'Instalador', 'crm-basico' ), crm_instalador_caps() );
	} else {
		$role = get_role( 'instalador' );
		foreach ( crm_instalador_caps() as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			}
		}
	}

	// Propagar caps de jefe_instalaciones al rol administrator de WordPress,
	// coherente con cómo roles.php sincroniza las caps de crm_admin.
	$wp_admin = get_role( 'administrator' );
	if ( $wp_admin ) {
		foreach ( array_keys( crm_jefe_instalaciones_caps() ) as $cap ) {
			$wp_admin->add_cap( $cap );
		}
	}
}

/**
 * Revoca las caps del módulo del rol administrator.
 * Se llama en desactivación del plugin para dejar wp_user_roles limpio.
 */
function crm_remove_instalaciones_caps_from_wp_admin() {
	$wp_admin = get_role( 'administrator' );
	if ( ! $wp_admin ) {
		return;
	}
	foreach ( array_keys( crm_jefe_instalaciones_caps() ) as $cap ) {
		if ( 'read' === $cap ) {
			continue;
		}
		$wp_admin->remove_cap( $cap );
	}
}

/**
 * Elimina completamente los roles de instalaciones.
 * Solo se llama desde uninstall.php, nunca en desactivación.
 */
function crm_uninstall_instalaciones_roles() {
	if ( function_exists( 'remove_role' ) ) {
		remove_role( 'jefe_instalaciones' );
		remove_role( 'instalador' );
	}
	crm_remove_instalaciones_caps_from_wp_admin();
}

// ──────────────────────────────────────────────────────────────────────────────
// Filtros de capabilities
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Garantía dinámica en runtime: cualquier usuario con `manage_options`
 * (administrador WordPress) recibe todas las caps del módulo en cada petición,
 * independientemente del estado guardado en la tabla de roles.
 *
 * Sigue el mismo patrón que el filtro análogo de roles.php (priority 10).
 */
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( empty( $allcaps['manage_options'] ) ) {
		return $allcaps;
	}
	foreach ( array_keys( crm_jefe_instalaciones_caps() ) as $cap ) {
		if ( 'read' === $cap ) {
			continue;
		}
		$allcaps[ $cap ] = true;
	}
	return $allcaps;
}, 10, 4 );

/**
 * Revocación dinámica (priority 1000, después del filtro principal de roles.php
 * a priority 999): cualquier usuario que NO sea administrator (vía manage_options)
 * ni jefe_instalaciones pierde las caps elevadas del módulo, aunque un plugin
 * externo se las hubiese concedido directamente.
 *
 * Así se garantiza que instalador (y comercial/visitador) nunca puedan escalar
 * privilegios dentro del módulo.
 *
 * @param array   $allcaps
 * @param array   $caps
 * @param array   $args
 * @param WP_User $user
 * @return array
 */
function crm_inst_filter_user_has_cap( $allcaps, $caps, $args, $user ) {
	if ( ! is_object( $user ) || empty( $user->ID ) ) {
		return $allcaps;
	}
	$roles = (array) ( $user->roles ?? [] );

	// administrator (manage_options) y jefe_instalaciones puro: no tocar.
	$is_elevated = (
		! empty( $allcaps['manage_options'] )
		|| (
			in_array( 'jefe_instalaciones', $roles, true )
			&& ! array_intersect( [ 'instalador', 'comercial', 'visitador' ], $roles )
		)
	);
	if ( $is_elevated ) {
		return $allcaps;
	}

	// Todos los demás pierden las caps elevadas del módulo.
	foreach ( crm_inst_privileged_caps() as $cap ) {
		$allcaps[ $cap ] = false;
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'crm_inst_filter_user_has_cap', 1000, 4 );

/**
 * Garantía perezosa (init priority 5): si los roles se perdieron en un upgrade
 * silencioso, los recrea sin necesidad de reactivar el plugin manualmente.
 */
add_action( 'init', function () {
	if ( get_option( 'crm_instalaciones_installed_version' ) === CRM_PLUGIN_VERSION ) {
		return;
	}
	crm_install_instalaciones_roles();
	update_option( 'crm_instalaciones_installed_version', CRM_PLUGIN_VERSION, false );
}, 5 );

// ──────────────────────────────────────────────────────────────────────────────
// Helper de log del módulo
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Registra una acción en crm_instalacion_log y, en paralelo, en el log
 * general de actividad (logger.php) para no duplicar la lógica de IP,
 * normalización de nivel y codificación de contexto.
 *
 * @param int              $instalacion_id  ID de la instalación afectada.
 * @param string           $entity_type     Entidad dentro del módulo (p.ej. 'trabajo', 'documento').
 * @param string           $accion          Acción realizada (p.ej. 'crear', 'cambiar_estado').
 * @param string           $detalle         Descripción legible del evento.
 * @param int|null         $user_id         ID de usuario; null para el actual.
 * @param array|null       $context         Datos adicionales que se serializan como contexto en el log general.
 * @return bool  true si la inserción en crm_instalacion_log tuvo éxito.
 */
function crm_inst_log_action( $instalacion_id, $entity_type, $accion, $detalle, $user_id = null, $context = null ) {
	global $wpdb;

	$instalacion_id = (int) $instalacion_id;
	$user_id        = $user_id ? (int) $user_id : get_current_user_id();
	$entity_type    = substr( sanitize_key( $entity_type ), 0, 80 );
	$accion         = substr( sanitize_key( $accion ), 0, 100 );

	// Insertar en la tabla dedicada del módulo.
	$result = (bool) $wpdb->insert(
		crm_inst_table_log(),
		[
			'instalacion_id' => $instalacion_id,
			'entity_type'    => $entity_type,
			'accion'         => $accion,
			'user_id'        => $user_id,
			'fecha'          => current_time( 'mysql' ),
			'detalle'        => (string) $detalle,
		],
		[ '%d', '%s', '%s', '%d', '%s', '%s' ]
	);

	// Delegar también al log general reutilizando crm_log_action() de logger.php.
	if ( function_exists( 'crm_log_action' ) ) {
		$general_context = array_merge(
			is_array( $context ) ? $context : [],
			[ 'instalacion_id' => $instalacion_id ]
		);
		crm_log_action(
			'inst_' . $entity_type . '_' . $accion,
			$detalle,
			null,
			$user_id,
			'info',
			$general_context
		);
	}

	return $result;
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers de dominio
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Estados de la instalación con su label legible.
 *
 * @return array<string, string>
 */
function crm_instalaciones_estados() {
	return [
		'borrador'     => 'Borrador',
		'pendiente'    => 'Pendiente',
		'planificada'  => 'Planificada',
		'en_ejecucion' => 'En ejecución',
		'bloqueada'    => 'Bloqueada',
		'lista'        => 'Lista',
		'finalizada'   => 'Finalizada',
		'reagendada'   => 'Reagendada',
		'cancelada'    => 'Cancelada',
	];
}

/**
 * Tipos de documento aceptados en crm_instalacion_documentos.
 *
 * @return array<string, string>
 */
function crm_instalaciones_tipos_doc() {
	return [
		'presupuesto' => 'Presupuesto',
		'albaran'     => 'Albarán',
		'foto'        => 'Foto',
		'certificado' => 'Certificado',
		'acta'        => 'Acta',
	];
}
