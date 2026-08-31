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
  tipo_instalacion ENUM('residencial','comercial') DEFAULT NULL,
  subtipo_instalacion SET('fotovoltaica','aerotermia') DEFAULT NULL,
  direccion_instalacion TEXT DEFAULT NULL,
  lat DECIMAL(10,7) DEFAULT NULL,
  lng DECIMAL(10,7) DEFAULT NULL,
  geocode_status VARCHAR(20) DEFAULT NULL,
  geocoded_at DATETIME DEFAULT NULL,
  proveedor_notificado_en DATETIME DEFAULT NULL,
  proveedor_pedido_token VARCHAR(64) DEFAULT NULL,
  proveedor_pedido_estado ENUM('','enviado','confirmado') NOT NULL DEFAULT '',
  proveedor_confirmado_en DATETIME DEFAULT NULL,
  proveedor_notas TEXT DEFAULT NULL,
  proveedor_entrega_estimada DATE DEFAULT NULL,
  estado ENUM('borrador','pendiente','planificada','en_ejecucion','bloqueada','lista','finalizada','reagendada','cancelada') NOT NULL DEFAULT 'borrador',
  jefe_instalaciones_id BIGINT(20) UNSIGNED DEFAULT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_cierre DATETIME DEFAULT NULL,
  cierre_estado ENUM('','declarado','aprobado','rechazado') NOT NULL DEFAULT '',
  cierre_conformidad TINYINT(1) DEFAULT NULL,
  cierre_observaciones TEXT DEFAULT NULL,
  cierre_declarado_por BIGINT(20) UNSIGNED DEFAULT NULL,
  cierre_declarado_en DATETIME DEFAULT NULL,
  cierre_validado_por BIGINT(20) UNSIGNED DEFAULT NULL,
  cierre_validado_en DATETIME DEFAULT NULL,
  checklist_confirmado_por BIGINT(20) UNSIGNED DEFAULT NULL,
  checklist_confirmado_en DATETIME DEFAULT NULL,
  holded_invoice_id VARCHAR(100) DEFAULT NULL,
  holded_purchase_id VARCHAR(100) DEFAULT NULL,
  holded_waybill_id VARCHAR(100) DEFAULT NULL,
  holded_waybill_numero VARCHAR(50) DEFAULT NULL,
  holded_waybill_aprobado TINYINT(1) DEFAULT NULL,
  holded_waybill_error VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY jefe_instalaciones_id (jefe_instalaciones_id),
  KEY estado (estado),
  KEY holded_doc_id (holded_doc_id),
  KEY tipo_instalacion (tipo_instalacion),
  KEY proveedor_pedido_token (proveedor_pedido_token)
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

	// 3. crm_instalacion_trabajos ─ trabajo extraordinario (Fase 5) Y líneas de
	// materiales importadas de un presupuesto de Holded (Fase 2), diferenciadas
	// por `origen`. Ver PLAN_instalaciones.md Fase 2 para la decisión de reusar
	// esta tabla en vez de crear una tabla de materiales aparte.
	$t3   = crm_inst_table_trabajos();
	$sql3 = "CREATE TABLE $t3 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  descripcion TEXT NOT NULL,
  estado VARCHAR(50) NOT NULL DEFAULT 'pendiente',
  es_extraordinario TINYINT(1) NOT NULL DEFAULT 0,
  origen ENUM('holded','extra') NOT NULL DEFAULT 'extra',
  holded_line_id VARCHAR(100) DEFAULT NULL,
  holded_product_id VARCHAR(100) DEFAULT NULL,
  unidades DECIMAL(10,2) DEFAULT NULL,
  precio_unitario DECIMAL(10,2) DEFAULT NULL,
  coste_unitario DECIMAL(10,2) DEFAULT NULL,
  horas DECIMAL(6,2) DEFAULT NULL,
  materiales_usados TEXT DEFAULT NULL,
  observaciones TEXT DEFAULT NULL,
  justificacion_ruta VARCHAR(500) DEFAULT NULL,
  cliente_token VARCHAR(64) DEFAULT NULL,
  cliente_notificado_en DATETIME DEFAULT NULL,
  declarado_por BIGINT(20) UNSIGNED NOT NULL,
  validado_por BIGINT(20) UNSIGNED DEFAULT NULL,
  validado_en DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY instalacion_id (instalacion_id),
  KEY declarado_por (declarado_por),
  KEY origen (origen),
  KEY holded_line_id (holded_line_id),
  KEY holded_product_id (holded_product_id),
  KEY cliente_token (cliente_token)
) $charset_collate;";

	// 4. crm_instalacion_documentos
	$t4   = crm_inst_table_documentos();
	$sql4 = "CREATE TABLE $t4 (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  instalacion_id BIGINT(20) UNSIGNED NOT NULL,
  tipo ENUM('presupuesto','albaran','foto','certificado','acta') NOT NULL,
  categoria VARCHAR(40) DEFAULT NULL,
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
 * true si el usuario debe tener plenas capacidades del módulo de
 * instalaciones: administrator (manage_options), crm_admin puro (mismo
 * criterio que `crm_user_is_admin()` en roles.php — no se reimplementa aquí)
 * o jefe_instalaciones puro.
 *
 * Única fuente de verdad para los dos filtros `user_has_cap` de abajo, así
 * el criterio de "quién es elevado" no se duplica entre el que concede y el
 * que revoca.
 *
 * @param WP_User|null $user
 * @return bool
 */
function crm_inst_user_is_elevated( $user ) {
	if ( ! is_object( $user ) || empty( $user->ID ) ) {
		return false;
	}
	if ( function_exists( 'crm_user_is_admin' ) && crm_user_is_admin( $user->ID ) ) {
		return true;
	}
	$roles = (array) ( $user->roles ?? [] );
	return in_array( 'jefe_instalaciones', $roles, true )
		&& ! array_intersect( [ 'instalador', 'comercial', 'visitador' ], $roles );
}

/**
 * Garantía dinámica en runtime: administrator, crm_admin puro o
 * jefe_instalaciones puro reciben todas las caps del módulo en cada
 * petición, independientemente del estado guardado en la tabla de roles.
 *
 * Sigue el mismo patrón que el filtro análogo de roles.php (priority 10).
 */
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( ! crm_inst_user_is_elevated( $user ) ) {
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
 * a priority 999): cualquier usuario que no sea elevado (ver
 * `crm_inst_user_is_elevated()`) pierde las caps elevadas del módulo, aunque
 * un plugin externo se las hubiese concedido directamente.
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
	if ( crm_inst_user_is_elevated( $user ) ) {
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
 * Color sólido por estado de instalación — misma paleta que los badges
 * `.status-inst-*` de css/crm-styles.css (Fase 2), reutilizada aquí para que
 * cualquier vista nueva (tarjetas del panel del instalador, eventos del
 * calendario...) hable el mismo lenguaje de color que el listado/ficha en
 * vez de inventar una paleta aparte.
 *
 * @param string $estado
 * @return string Hex color.
 */
function crm_instalaciones_estado_color( $estado ) {
	$colores = [
		'borrador'     => '#6b7280',
		'pendiente'    => '#92400e',
		'planificada'  => '#1e40af',
		'en_ejecucion' => '#3730a3',
		'bloqueada'    => '#991b1b',
		'lista'        => '#155e75',
		'finalizada'   => '#065f46',
		'reagendada'   => '#9a3412',
		'cancelada'    => '#374151',
	];
	return $colores[ $estado ] ?? '#6b7280';
}

/**
 * Estados de una cita en `crm_instalacion_agenda` (distintos de los estados
 * de la instalación en sí).
 *
 * @return array<string, string>
 */
function crm_instalaciones_estados_agenda() {
	return [
		'pendiente'  => 'Pendiente de confirmar',
		'confirmada' => 'Confirmada',
		'reagendada' => 'Reagendada',
	];
}

/**
 * Importe máximo (€) que un instalador puede declarar en una sola partida
 * extra sin más trámite que la validación del jefe — Fase 5. Configurable en
 * Ajustes, 500€ por defecto.
 */
function crm_inst_extra_max() {
	$val = (float) get_option( 'crm_inst_extra_max', 500 );
	return $val > 0 ? $val : 500.0;
}

/**
 * Si true, el cierre que declara un instalador (conformidad + observaciones +
 * fotos) queda `pendiente` hasta que un jefe_instalaciones lo valida — igual
 * que las partidas extra. Si false (por defecto), el cierre es definitivo al
 * momento. Configurable en Ajustes — el usuario puede cambiar de modo sin
 * tocar código.
 */
function crm_inst_cierre_requiere_aprobacion() {
	return (bool) get_option( 'crm_inst_cierre_requiere_aprobacion', false );
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

/**
 * Tipos de instalación (Fase 2, confirmado con el cliente 2026-08-19).
 *
 * @return array<string, string>
 */
function crm_instalaciones_tipos() {
	return [
		'residencial' => 'Residencial',
		'comercial'   => 'Comercial',
	];
}

/**
 * Subtipos de instalación, solo aplicables a `tipo_instalacion = residencial`.
 *
 * @return array<string, string>
 */
function crm_instalaciones_subtipos() {
	return [
		'fotovoltaica' => 'Fotovoltaica',
		'aerotermia'   => 'Aerotermia',
	];
}

/**
 * v1.20.86: una instalación puede ser fotovoltaica Y aerotermia a la vez (un
 * mismo viaje cubre las dos) — `subtipo_instalacion` pasó de ENUM a SET, así
 * que el valor crudo puede traer uno, los dos separados por coma, o nada.
 * Esta función descompone ese valor en un array de claves sueltas; es el
 * único sitio que sabe parsear el formato, para no repetir `explode(',', ...)`
 * por todo el módulo.
 *
 * @param string|null $subtipo_raw Valor crudo de la columna (p.ej. "fotovoltaica,aerotermia").
 * @return string[] Claves sueltas, p.ej. ['fotovoltaica','aerotermia'].
 */
function crm_inst_subtipos_array( $subtipo_raw ) {
	$subtipo_raw = trim( (string) $subtipo_raw );
	if ( $subtipo_raw === '' ) {
		return [];
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $subtipo_raw ) ) ) );
}

/**
 * Etiqueta legible combinando todos los subtipos aplicables, p.ej.
 * "Fotovoltaica + Aerotermia" cuando la instalación es de las dos a la vez.
 *
 * @param string|null $subtipo_raw
 * @return string|null
 */
function crm_inst_subtipo_label( $subtipo_raw ) {
	$claves = crm_inst_subtipos_array( $subtipo_raw );
	if ( empty( $claves ) ) {
		return null;
	}
	$subtipos = crm_instalaciones_subtipos();
	return implode( ' + ', array_map( function ( $k ) use ( $subtipos ) {
		return $subtipos[ $k ] ?? $k;
	}, $claves ) );
}

/**
 * Categorías de foto obligatorias en el cierre, según el/los subtipo(s) de la
 * instalación — cada clave es el `categoria` que se guarda en
 * `crm_instalacion_documentos`. Si es fotovoltaica Y aerotermia a la vez, se
 * combinan las categorías de ambas (p.ej. "cuadros de protección" es la misma
 * clave en los dos mapas, así que no se duplica). Si no hay ningún subtipo
 * con categorías definidas (p.ej. `tipo_instalacion = 'comercial'`), el
 * cierre vuelve al modo libre de "hasta 6 fotos sin categorizar" — ver
 * `crm_inst_ajax_cerrar_instalacion()`.
 *
 * @param string|null $subtipo_raw Valor crudo de la columna (uno, ambos separados por coma, o vacío).
 * @return array<string, string> categoria => etiqueta legible
 */
function crm_inst_categorias_fotos_cierre( $subtipo_raw ) {
	$mapa = [
		'fotovoltaica' => [
			'inversor'           => 'Foto del inversor',
			'placas'             => 'Foto de las placas',
			'cuadros_proteccion' => 'Foto de los cuadros de protección',
		],
		'aerotermia' => [
			'unidad_interior'    => 'Foto de la unidad interior',
			'unidad_exterior'    => 'Foto de la unidad exterior',
			'cuadros_proteccion' => 'Foto de los cuadros de protección',
		],
	];
	$categorias = [];
	foreach ( crm_inst_subtipos_array( $subtipo_raw ) as $subtipo ) {
		if ( isset( $mapa[ $subtipo ] ) ) {
			$categorias = array_merge( $categorias, $mapa[ $subtipo ] );
		}
	}
	return $categorias;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Alta de instalación desde un presupuesto aprobado de Holded
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Normaliza el nombre de provincia de un contacto de Holded para que
 * coincida exactamente con una opción del `<select name="provincia">` de la
 * ficha de cliente.
 *
 * Comprobado contra 68 contactos reales (2026-08-20): Holded no es
 * consistente ("León", "leon", "Leon" aparecen los tres para la misma
 * provincia) y en el 53% de los casos ni siquiera trae provincia. Guardar el
 * texto tal cual es peligroso: el `<select>` de la ficha, si el valor no
 * coincide con ninguna `<option>`, no cae en el placeholder — se queda
 * silenciosamente en la primera provincia de la lista alfabética, mostrando
 * una provincia DISTINTA a la real sin avisar. Por eso aquí se normaliza y,
 * si no hay coincidencia segura con la lista oficial, se devuelve vacío
 * (que sí cae correctamente en el placeholder "Seleccionar provincia").
 *
 * @param string $raw
 * @return string Nombre oficial INE, o '' si no hay coincidencia fiable.
 */
function crm_inst_safe_provincia( $raw ) {
	$raw = trim( (string) $raw );
	if ( $raw === '' || ! function_exists( 'crm_normalize_provincia' ) || ! function_exists( 'crm_get_provincias_oficiales' ) ) {
		return '';
	}
	$normalizada = crm_normalize_provincia( $raw );
	$oficiales   = wp_list_pluck( crm_get_provincias_oficiales(), 'name' );
	return in_array( $normalizada, $oficiales, true ) ? $normalizada : '';
}

/**
 * Extrae de un contacto de Holded los campos que necesita `wp_crm_clients`,
 * con los fallbacks que hacen falta en la práctica (verificado contra 68
 * contactos reales, 2026-08-20):
 *  - `phone` está vacío en más contactos de los que trae `mobile`, así que
 *    se intenta `phone` primero y se cae a `mobile` — cuando ninguno de los
 *    dos existe, el hueco es real (falta el dato en Holded, no un fallo de
 *    extracción) y se deja vacío a propósito, no se inventa nada.
 *  - `province` pasa por `crm_inst_safe_provincia()` (ver arriba) para no
 *    corromper en silencio el `<select>` de provincia.
 *  - `city` (población) es un campo de texto libre en la ficha, así que se
 *    copia tal cual — no necesita normalizarse contra ningún listado.
 *
 * @param array $contact Detalle de contacto de Holded (crm_holded_get_contact()).
 * @return array{nombre:string, empresa:string, direccion:string, poblacion:string, provincia:string, telefono:string, email:string}
 */
function crm_inst_extract_client_fields_from_holded_contact( array $contact ) {
	$bill_address = is_array( $contact['bill_address'] ?? null ) ? $contact['bill_address'] : [];
	$telefono     = trim( (string) ( $contact['phone'] ?: ( $contact['mobile'] ?? '' ) ) );

	return [
		'nombre'    => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
		'empresa'   => sanitize_text_field( (string) ( $contact['trade_name'] ?? '' ) ),
		'direccion' => sanitize_text_field( (string) ( $bill_address['address'] ?? '' ) ),
		'poblacion' => sanitize_text_field( (string) ( $bill_address['city'] ?? '' ) ),
		'provincia' => crm_inst_safe_provincia( $bill_address['province'] ?? '' ),
		'telefono'  => substr( sanitize_text_field( $telefono ), 0, 15 ),
		'email'     => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
	];
}

/**
 * Busca o crea el cliente de `wp_crm_clients` correspondiente a un contacto
 * de Holded.
 *
 * Orden de coincidencia: 1) `holded_contact_id` ya vinculado, 2) email del
 * contacto (si Holded lo tiene), 3) crea una ficha nueva marcada con
 * `origen_lead = 'instalacion_holded'` (reutiliza el campo existente, no se
 * añade uno nuevo) y vincula su `holded_contact_id` para próximas búsquedas.
 *
 * No asigna comercial (user_id/delegado quedan vacíos): esta ficha nace del
 * flujo de instalaciones, no de una asignación comercial. Ver Fase 7 del plan
 * para cuándo/si conviene notificar a un comercial de este alta.
 *
 * @param array $contact Detalle de contacto de Holded (crm_holded_get_contact()).
 * @return int|WP_Error  client_id, o WP_Error si falta el nombre del contacto.
 */
function crm_inst_match_or_create_client_from_holded_contact( array $contact ) {
	global $wpdb;
	$table = $wpdb->prefix . 'crm_clients';

	$holded_contact_id = sanitize_text_field( (string) ( $contact['id'] ?? '' ) );
	if ( $holded_contact_id === '' ) {
		return new WP_Error( 'crm_inst_invalid_contact', 'El presupuesto no tiene un contacto de Holded válido.' );
	}

	// 1) Ya vinculado en una alta anterior.
	$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE holded_contact_id = %s LIMIT 1",
		$holded_contact_id
	) );
	if ( $existing_id > 0 ) {
		return $existing_id;
	}

	$campos = crm_inst_extract_client_fields_from_holded_contact( $contact );

	// 2) Coincidencia por email (si Holded tiene uno registrado).
	if ( $campos['email'] !== '' ) {
		$by_email = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE email_cliente = %s LIMIT 1",
			$campos['email']
		) );
		if ( $by_email > 0 ) {
			$wpdb->update( $table, [ 'holded_contact_id' => $holded_contact_id ], [ 'id' => $by_email ] );
			return $by_email;
		}
	}

	// 3) Crear ficha nueva.
	if ( $campos['nombre'] === '' ) {
		return new WP_Error( 'crm_inst_invalid_contact', 'El contacto de Holded no tiene nombre.' );
	}

	$insert = $wpdb->insert(
		$table,
		[
			'cliente_nombre'           => $campos['nombre'],
			'empresa'                  => $campos['empresa'],
			'direccion'                => $campos['direccion'],
			'telefono'                 => $campos['telefono'],
			'email_cliente'            => $campos['email'],
			'poblacion'                => $campos['poblacion'],
			'provincia'                => $campos['provincia'] !== '' ? $campos['provincia'] : 'León',
			'estado'                   => 'presupuesto_aceptado',
			'fecha_envio_por_sector'   => maybe_serialize( [] ),
			'usuario_envio_por_sector' => maybe_serialize( [] ),
			'origen_lead'              => 'instalacion_holded',
			'holded_contact_id'        => $holded_contact_id,
			'creado_por'               => get_current_user_id(),
			'creado_en'                => current_time( 'mysql' ),
		]
	);

	if ( $insert === false ) {
		return new WP_Error( 'crm_inst_client_insert_failed', 'No se pudo crear la ficha de cliente: ' . $wpdb->last_error );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Mapa tipo_instalacion + ¿es persona física? → campo `tipo` de wp_crm_clients
 * (los 3 valores que ya usa el formulario de ficha: Residencial/Autónomo/Empresa).
 * residencial siempre es una persona con vivienda propia. comercial se reparte
 * entre autónomo (persona) y empresa (organización), según lo que diga Holded.
 *
 * @return string
 */
function crm_inst_map_tipo_cliente( $tipo_instalacion, $is_person ) {
	if ( $tipo_instalacion === 'residencial' ) {
		return 'Residencial';
	}
	return $is_person ? 'Autónomo' : 'Empresa';
}

/**
 * Descarga el PDF del presupuesto de Holded y lo guarda en el uploads de
 * WordPress, para poder adjuntarlo a la ficha de cliente como si lo hubiera
 * subido un comercial a mano.
 *
 * @param string $estimate_id
 * @return string|WP_Error URL del PDF guardado.
 */
function crm_inst_save_holded_pdf_to_uploads( $estimate_id ) {
	$pdf = crm_holded_get_estimate_pdf( $estimate_id );
	if ( is_wp_error( $pdf ) ) {
		return $pdf;
	}

	$filename = 'presupuesto-' . sanitize_file_name( $estimate_id ) . '.pdf';
	$saved    = wp_upload_bits( $filename, null, $pdf );
	if ( ! empty( $saved['error'] ) ) {
		return new WP_Error( 'crm_inst_pdf_save_failed', $saved['error'] );
	}

	return $saved['url'];
}

/**
 * Completa en `wp_crm_clients` lo que el flujo de comercial rellena siempre
 * a mano al dar de alta un interés en renovables: marca el interés, inicia
 * el estado del sector (solo si no tenía ya uno), rellena el tipo de cliente
 * (solo si estaba vacío) y adjunta el PDF del presupuesto de Holded como
 * documento del sector (solo si no estaba ya adjunto).
 *
 * Nunca pisa un valor real que el cliente ya tuviera — un cliente que se
 * empareja por email/holded_contact_id puede llevar años en el CRM con su
 * propio historial, y esto solo debe rellenar huecos, no corregirlo.
 *
 * @param int    $client_id
 * @param string $estimate_id
 * @param string $tipo_instalacion
 * @param array  $contact          Detalle de contacto de Holded (crm_holded_get_contact()).
 */
function crm_inst_enrich_client_for_renovables( $client_id, $estimate_id, $tipo_instalacion, array $contact ) {
	$client_id = (int) $client_id;
	if ( $client_id <= 0 ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'crm_clients';
	$client = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $client_id ), ARRAY_A );
	if ( ! $client ) {
		return;
	}

	$update = [];

	// 1) Interés: unión, nunca se quita nada de lo que ya hubiera.
	$intereses = crm_safe_unserialize_array( $client['intereses'] ?? '' );
	if ( ! in_array( 'renovables', $intereses, true ) ) {
		$intereses[]         = 'renovables';
		$update['intereses'] = maybe_serialize( array_values( $intereses ) );
	}

	// 2) Estado del sector: solo si el sector no tenía ya un estado propio.
	$estado_por_sector = crm_safe_unserialize_array( $client['estado_por_sector'] ?? '' );
	if ( empty( $estado_por_sector['renovables'] ) ) {
		$estado_por_sector['renovables']  = 'presupuesto_aceptado';
		$update['estado_por_sector']      = maybe_serialize( $estado_por_sector );
	}

	// 3) Tipo de cliente: solo si estaba vacío.
	if ( empty( $client['tipo'] ) ) {
		$update['tipo'] = crm_inst_map_tipo_cliente( $tipo_instalacion, ! empty( $contact['is_person'] ) );
	}

	// 4) Presupuesto adjunto: solo si este presupuesto en concreto no estaba ya.
	$presupuesto = crm_safe_unserialize_array( $client['presupuesto'] ?? '' );
	$ya_adjunto  = false;
	foreach ( (array) ( $presupuesto['renovables'] ?? [] ) as $url ) {
		if ( is_string( $url ) && strpos( $url, sanitize_file_name( $estimate_id ) ) !== false ) {
			$ya_adjunto = true;
			break;
		}
	}
	if ( ! $ya_adjunto ) {
		$pdf_url = crm_inst_save_holded_pdf_to_uploads( $estimate_id );
		if ( ! is_wp_error( $pdf_url ) ) {
			$presupuesto['renovables']  = array_merge( (array) ( $presupuesto['renovables'] ?? [] ), [ $pdf_url ] );
			$update['presupuesto']      = maybe_serialize( $presupuesto );
		} else {
			crm_inst_log_action( 0, 'cliente', 'adjuntar_presupuesto_error', 'No se pudo adjuntar el PDF a la ficha del cliente #' . $client_id . ': ' . $pdf_url->get_error_message() );
		}
	}

	if ( ! empty( $update ) ) {
		$wpdb->update( $table, $update, [ 'id' => $client_id ] );
	}
}

/**
 * Crea una instalación en estado `borrador` a partir de un presupuesto
 * aprobado de Holded, con sus líneas cargadas como materiales.
 *
 * Vuelve a pedir el detalle del presupuesto a Holded (nunca se fía de datos
 * que pudieran llegar del navegador) y comprueba `approved_at` justo antes
 * de crear nada.
 *
 * @param string $estimate_id
 * @param string $tipo_instalacion    'residencial'|'comercial'
 * @param string $subtipo_instalacion 'fotovoltaica'|'aerotermia'|'fotovoltaica,aerotermia'|'' (solo si residencial;
 *        admite los dos a la vez — un mismo viaje puede cubrir ambos)
 * @return array|WP_Error {instalacion_id, client_id, lineas_importadas}
 */
function crm_inst_crear_desde_presupuesto( $estimate_id, $tipo_instalacion, $subtipo_instalacion = '' ) {
	global $wpdb;

	$tipos = crm_instalaciones_tipos();
	if ( ! isset( $tipos[ $tipo_instalacion ] ) ) {
		return new WP_Error( 'crm_inst_tipo_invalido', 'Tipo de instalación no válido.' );
	}
	$subtipos = crm_instalaciones_subtipos();
	if ( $tipo_instalacion === 'residencial' ) {
		$subtipos_elegidos = array_values( array_intersect( crm_inst_subtipos_array( $subtipo_instalacion ), array_keys( $subtipos ) ) );
		if ( empty( $subtipos_elegidos ) ) {
			return new WP_Error( 'crm_inst_subtipo_invalido', 'Elige al menos un subtipo (fotovoltaica/aerotermia) para instalaciones residenciales.' );
		}
		$subtipo_instalacion = implode( ',', $subtipos_elegidos );
	} else {
		$subtipo_instalacion = '';
	}

	// Idempotencia: no crear dos instalaciones desde el mismo presupuesto.
	$tabla_inst = crm_inst_table_instalaciones();
	$ya_existe  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$tabla_inst} WHERE holded_doc_id = %s LIMIT 1",
		$estimate_id
	) );
	if ( $ya_existe > 0 ) {
		return new WP_Error( 'crm_inst_ya_existe', 'Ya existe una instalación creada desde este presupuesto (instalación #' . $ya_existe . ').' );
	}

	$estimate = crm_holded_get_estimate( $estimate_id );
	if ( is_wp_error( $estimate ) ) {
		return $estimate;
	}
	if ( empty( $estimate['approved_at'] ) ) {
		return new WP_Error( 'crm_inst_no_aprobado', 'Este presupuesto todavía no está aprobado en Holded.' );
	}

	$contact_id = sanitize_text_field( (string) ( $estimate['contact_id'] ?? '' ) );
	if ( $contact_id === '' ) {
		return new WP_Error( 'crm_inst_sin_contacto', 'El presupuesto no tiene un contacto asociado.' );
	}
	$contact = crm_holded_get_contact( $contact_id );
	if ( is_wp_error( $contact ) ) {
		return $contact;
	}

	$client_id = crm_inst_match_or_create_client_from_holded_contact( $contact );
	if ( is_wp_error( $client_id ) ) {
		return $client_id;
	}

	// Completa la ficha de cliente en lo que el flujo de comercial siempre
	// rellena a mano (interés, tipo, estado del sector, presupuesto adjunto).
	// No pisa nada que el cliente ya tuviera — ver docblock de la función.
	crm_inst_enrich_client_for_renovables( $client_id, $estimate_id, $tipo_instalacion, $contact );

	$bill_address  = is_array( $contact['bill_address'] ?? null ) ? $contact['bill_address'] : [];
	$direccion_partes = array_filter( [
		$bill_address['address']     ?? '',
		$bill_address['city']        ?? '',
		$bill_address['postal_code'] ?? '',
	] );

	$insert_inst = $wpdb->insert(
		$tabla_inst,
		[
			'client_id'            => $client_id,
			'holded_doc_id'        => $estimate_id,
			'holded_doc_type'      => 'estimate',
			'tipo_instalacion'     => $tipo_instalacion,
			'subtipo_instalacion'  => $subtipo_instalacion !== '' ? $subtipo_instalacion : null,
			'direccion_instalacion' => implode( ', ', $direccion_partes ),
			'estado'               => 'borrador',
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( $insert_inst === false ) {
		return new WP_Error( 'crm_inst_insert_failed', 'No se pudo crear la instalación: ' . $wpdb->last_error );
	}
	$instalacion_id = (int) $wpdb->insert_id;

	// Cargar líneas del presupuesto como materiales (origen = 'holded').
	$lineas_importadas = 0;
	$tabla_trabajos     = crm_inst_table_trabajos();
	foreach ( (array) ( $estimate['lines'] ?? [] ) as $linea ) {
		if ( ( $linea['type'] ?? '' ) === 'title' ) {
			continue; // Encabezado de sección del presupuesto, no es un material.
		}
		$nombre = sanitize_text_field( (string) ( $linea['name'] ?? '' ) );
		if ( $nombre === '' ) {
			continue;
		}
		$wpdb->insert(
			$tabla_trabajos,
			[
				'instalacion_id'  => $instalacion_id,
				'descripcion'     => $nombre,
				'estado'          => 'pendiente',
				'es_extraordinario' => 0,
				'origen'          => 'holded',
				'holded_line_id'  => sanitize_text_field( (string) ( $linea['line_id'] ?? '' ) ),
				// v1.20.58: si la línea SÍ viene enganchada a un producto real del
				// catálogo (Holded lo rellena solo si al presupuestar se elige el
				// producto en vez de escribir texto libre), guardamos su ID para
				// poder comprobar el stock por ID (fiable) en vez de por nombre
				// (best-effort) — ver crm_holded_match_producto_por_nombre().
				'holded_product_id' => ! empty( $linea['product_id'] ) ? sanitize_text_field( (string) $linea['product_id'] ) : null,
				'unidades'        => is_numeric( $linea['units'] ?? null ) ? (float) $linea['units'] : (float) str_replace( ',', '.', (string) ( $linea['units'] ?? 0 ) ),
				'precio_unitario' => is_numeric( $linea['price'] ?? null ) ? (float) $linea['price'] : (float) str_replace( ',', '.', (string) ( $linea['price'] ?? 0 ) ),
				'coste_unitario'  => is_numeric( $linea['cost_price'] ?? null ) ? (float) $linea['cost_price'] : (float) str_replace( ',', '.', (string) ( $linea['cost_price'] ?? 0 ) ),
				'declarado_por'   => get_current_user_id(),
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%d' ]
		);
		$lineas_importadas++;
	}

	crm_inst_log_action(
		$instalacion_id,
		'instalacion',
		'crear_desde_presupuesto',
		sprintf( 'Instalación creada desde presupuesto Holded %s (%d líneas importadas).', $estimate_id, $lineas_importadas ),
		null,
		[ 'client_id' => $client_id, 'holded_doc_id' => $estimate_id ]
	);

	if ( implode( ', ', $direccion_partes ) !== '' ) {
		crm_inst_schedule_geocode( $instalacion_id );
	}

	return [
		'instalacion_id'     => $instalacion_id,
		'client_id'          => $client_id,
		'lineas_importadas'  => $lineas_importadas,
	];
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Geocodificación en background (Nominatim, ver includes/geocoding.php)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Programa un evento de WP-Cron para geocodificar una instalación. No se
 * geocodifica nunca dentro de la petición que crea/edita la instalación —
 * así la ficha/el alta no esperan a una API externa.
 */
function crm_inst_schedule_geocode( $instalacion_id ) {
	$instalacion_id = (int) $instalacion_id;
	if ( $instalacion_id <= 0 ) {
		return;
	}
	wp_schedule_single_event( time() + 5, 'crm_inst_geocode_single', [ $instalacion_id ] );
}

/**
 * Handler del evento de WP-Cron: geocodifica y guarda el resultado. También
 * se llama directamente (sin cron) desde el botón "Geocodificar ahora" de la
 * ficha, para poder probar sin esperar a que WP-Cron dispare el evento.
 */
add_action( 'crm_inst_geocode_single', 'crm_inst_run_geocode' );
function crm_inst_run_geocode( $instalacion_id ) {
	$instalacion_id = (int) $instalacion_id;
	if ( $instalacion_id <= 0 ) {
		return;
	}

	global $wpdb;
	$direccion = $wpdb->get_var( $wpdb->prepare(
		"SELECT direccion_instalacion FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		$instalacion_id
	) );
	if ( ! $direccion ) {
		return;
	}

	$resultado = crm_geo_geocode_address( $direccion );
	if ( is_wp_error( $resultado ) ) {
		$wpdb->update(
			crm_inst_table_instalaciones(),
			[ 'geocode_status' => 'error', 'geocoded_at' => current_time( 'mysql' ) ],
			[ 'id' => $instalacion_id ]
		);
		crm_inst_log_action( $instalacion_id, 'instalacion', 'geocode_error', 'No se pudo geocodificar: ' . $resultado->get_error_message() );
		return;
	}

	$wpdb->update(
		crm_inst_table_instalaciones(),
		[
			'lat'            => $resultado['lat'],
			'lng'            => $resultado['lng'],
			'geocode_status' => 'ok',
			'geocoded_at'    => current_time( 'mysql' ),
		],
		[ 'id' => $instalacion_id ]
	);
	crm_inst_log_action( $instalacion_id, 'instalacion', 'geocode_ok', 'Dirección geocodificada correctamente.' );
}

/**
 * Botón "Geocodificar ahora" de la ficha: ejecuta el mismo geocode síncrono,
 * en primer plano, para no depender de cuándo dispare WP-Cron al probar.
 */
add_action( 'wp_ajax_crm_inst_geocodificar_ahora', 'crm_inst_ajax_geocodificar_ahora' );
function crm_inst_ajax_geocodificar_ahora() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	if ( $instalacion_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Instalación no válida.' ] );
	}

	crm_inst_run_geocode( $instalacion_id );

	$data = crm_inst_get_instalacion_data( $instalacion_id );
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}
	if ( $data['geocode_status'] !== 'ok' ) {
		wp_send_json_error( [ 'message' => 'No se pudo geocodificar esta dirección (dirección no encontrada o error del servicio).' ] );
	}

	wp_send_json_success( [ 'lat' => $data['lat'], 'lng' => $data['lng'] ] );
}

/**
 * Rellena a mano `direccion_instalacion` cuando no vino de Holded (o venía
 * mal). No es un campo editable en general —solo se ofrece cuando está
 * vacío, ver la advertencia en la ficha— y al guardarla se reprograma la
 * geocodificación, porque la que hubiera (ninguna, si estaba vacía) ya no
 * vale.
 */
add_action( 'wp_ajax_crm_inst_actualizar_direccion', 'crm_inst_ajax_actualizar_direccion' );
function crm_inst_ajax_actualizar_direccion() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$direccion      = sanitize_text_field( wp_unslash( $_POST['direccion'] ?? '' ) );

	if ( $instalacion_id <= 0 || $direccion === '' ) {
		wp_send_json_error( [ 'message' => 'Escribe una dirección.' ] );
	}

	$wpdb->update(
		crm_inst_table_instalaciones(),
		[ 'direccion_instalacion' => $direccion, 'geocode_status' => null, 'lat' => null, 'lng' => null ],
		[ 'id' => $instalacion_id ]
	);
	crm_inst_log_action( $instalacion_id, 'instalacion', 'direccion_manual', 'Dirección añadida a mano (no vino de Holded): ' . $direccion );
	crm_inst_schedule_geocode( $instalacion_id );

	wp_send_json_success( [ 'direccion' => $direccion ] );
}

/**
 * Corrige el tipo/subtipo de instalación desde la ficha — a diferencia de la
 * dirección, este SÍ es un campo editable en general (no solo cuando viene
 * vacío): Holded puede traerlo mal, o el jefe puede haberse equivocado al
 * darla de alta a mano. El subtipo solo tiene sentido para "residencial" —
 * al cambiar a "comercial" se vacía siempre, aunque el formulario mandara uno.
 */
add_action( 'wp_ajax_crm_inst_actualizar_tipo', 'crm_inst_ajax_actualizar_tipo' );
function crm_inst_ajax_actualizar_tipo() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$tipo           = sanitize_key( wp_unslash( $_POST['tipo_instalacion'] ?? '' ) );
	// v1.20.86: puede venir más de un subtipo a la vez (fotovoltaica Y
	// aerotermia en el mismo viaje) — llega como coma-separado desde los
	// checkboxes; sanitize_key() a la cadena entera se cargaría la coma, así
	// que se sanea cada clave por separado.
	$subtipo_raw    = (string) wp_unslash( $_POST['subtipo_instalacion'] ?? '' );
	$subtipo_claves = array_map( 'sanitize_key', crm_inst_subtipos_array( $subtipo_raw ) );

	$tipos    = crm_instalaciones_tipos();
	$subtipos = crm_instalaciones_subtipos();

	if ( $instalacion_id <= 0 || ! isset( $tipos[ $tipo ] ) ) {
		wp_send_json_error( [ 'message' => 'Tipo no válido.' ] );
	}
	if ( $tipo !== 'residencial' ) {
		$subtipo_claves = [];
	} else {
		foreach ( $subtipo_claves as $clave ) {
			if ( ! isset( $subtipos[ $clave ] ) ) {
				wp_send_json_error( [ 'message' => 'Subtipo no válido.' ] );
			}
		}
	}
	$subtipo = implode( ',', $subtipo_claves );

	$wpdb->update(
		crm_inst_table_instalaciones(),
		[ 'tipo_instalacion' => $tipo, 'subtipo_instalacion' => $subtipo !== '' ? $subtipo : null ],
		[ 'id' => $instalacion_id ]
	);
	crm_inst_log_action(
		$instalacion_id,
		'instalacion',
		'cambiar_tipo',
		'Tipo cambiado a ' . $tipos[ $tipo ] . ( $subtipo !== '' ? ' — ' . crm_inst_subtipo_label( $subtipo ) : '' ) . '.'
	);

	wp_send_json_success( [
		'tipo_label'    => $tipos[ $tipo ],
		'subtipo_label' => $subtipo !== '' ? crm_inst_subtipo_label( $subtipo ) : '',
	] );
}

/**
 * Descarga el PDF del presupuesto de Holded de una instalación. Pasa por el
 * servidor (nunca expone la clave de API de Holded al navegador) y sirve el
 * PDF como descarga directa, sin JSON de por medio.
 */
add_action( 'admin_post_crm_inst_descargar_presupuesto', 'crm_inst_descargar_presupuesto' );
function crm_inst_descargar_presupuesto() {
	if ( ! crm_inst_current_user_can_manage() ) {
		wp_die( 'Sin permisos.', 403 );
	}
	check_admin_referer( 'crm_inst_descargar_presupuesto' );

	$instalacion_id = (int) ( $_GET['instalacion_id'] ?? 0 );
	$data = crm_inst_get_instalacion_data( $instalacion_id );
	if ( is_wp_error( $data ) || empty( $data['holded_doc_id'] ) ) {
		wp_die( 'Esta instalación no tiene un presupuesto de Holded asociado.', 404 );
	}

	$pdf = crm_holded_get_estimate_pdf( $data['holded_doc_id'] );
	if ( is_wp_error( $pdf ) ) {
		wp_die( esc_html( $pdf->get_error_message() ), 502 );
	}

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="presupuesto-' . sanitize_file_name( $data['holded_doc_id'] ) . '.pdf"' );
	header( 'Content-Length: ' . strlen( $pdf ) );
	echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — AJAX
// ──────────────────────────────────────────────────────────────────────────────

/**
 * true si el usuario actual puede gestionar instalaciones (jefe_instalaciones
 * o crm_admin/administrator vía la cap `crm_inst_manage`).
 */
function crm_inst_current_user_can_manage() {
	return current_user_can( 'crm_inst_manage' );
}

/**
 * Migas de pan compactas, comunes a las 3 pantallas del módulo.
 *
 * @param array<int, array{label:string, url?:string}> $items Último item sin
 *        `url` (o con url vacía) se pinta como el actual, sin enlace.
 */
function crm_inst_render_breadcrumb( array $items ) {
	$parts = [];
	foreach ( $items as $item ) {
		if ( ! empty( $item['url'] ) ) {
			$parts[] = '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		} else {
			$parts[] = '<span>' . esc_html( $item['label'] ) . '</span>';
		}
	}
	echo '<nav class="crm-inst-breadcrumb" aria-label="Ruta">' . implode( ' <span class="crm-inst-breadcrumb-sep">/</span> ', $parts ) . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

add_action( 'wp_ajax_crm_inst_buscar_presupuestos', 'crm_inst_ajax_buscar_presupuestos' );
function crm_inst_ajax_buscar_presupuestos() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$query   = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
	$results = crm_holded_search_estimates( $query );
	if ( is_wp_error( $results ) ) {
		wp_send_json_error( [ 'message' => $results->get_error_message() ] );
	}

	$pagina = array_slice( $results, 0, 50 );

	// Un solo query para saber, de los resultados mostrados, cuáles ya tienen
	// instalación creada — así la tabla lo muestra sin que haya que entrar a
	// verlo uno por uno.
	global $wpdb;
	$doc_ids = array_map( function ( $item ) {
		return (string) $item['id'];
	}, $pagina );
	$instalacion_por_doc = [];
	if ( ! empty( $doc_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $doc_ids ), '%s' ) );
		$filas        = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, holded_doc_id FROM " . crm_inst_table_instalaciones() . " WHERE holded_doc_id IN ({$placeholders})",
			$doc_ids
		), ARRAY_A );
		foreach ( (array) $filas as $fila ) {
			$instalacion_por_doc[ $fila['holded_doc_id'] ] = (int) $fila['id'];
		}
	}

	$out = array_map( function ( $item ) use ( $instalacion_por_doc ) {
		return [
			'id'              => $item['id'],
			'document_number' => $item['document_number'] ?? '',
			'contact_name'    => $item['contact_name'] ?? '',
			'date'            => $item['date'] ?? '',
			'total'           => $item['total'] ?? '',
			'currency'        => $item['currency'] ?? '',
			'instalacion_id'  => $instalacion_por_doc[ $item['id'] ] ?? null,
		];
	}, $pagina );

	wp_send_json_success( [ 'items' => $out, 'total_encontrados' => count( $results ) ] );
}

add_action( 'wp_ajax_crm_inst_ver_presupuesto', 'crm_inst_ajax_ver_presupuesto' );
function crm_inst_ajax_ver_presupuesto() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$estimate_id = sanitize_text_field( wp_unslash( $_POST['estimate_id'] ?? '' ) );
	$estimate    = crm_holded_get_estimate( $estimate_id );
	if ( is_wp_error( $estimate ) ) {
		wp_send_json_error( [ 'message' => $estimate->get_error_message() ] );
	}

	global $wpdb;
	$ya_existe = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM " . crm_inst_table_instalaciones() . " WHERE holded_doc_id = %s LIMIT 1",
		$estimate_id
	) );

	$lineas = array_values( array_filter( array_map( function ( $l ) {
		if ( ( $l['type'] ?? '' ) === 'title' ) {
			return null;
		}
		return [
			'name'  => $l['name'] ?? '',
			'units' => $l['units'] ?? '',
			'price' => $l['price'] ?? '',
		];
	}, (array) ( $estimate['lines'] ?? [] ) ) ) );

	wp_send_json_success( [
		'document_number' => $estimate['document_number'] ?? '',
		'contact_name'    => $estimate['contact_name'] ?? '',
		'date'            => $estimate['date'] ?? '',
		'total'           => $estimate['total'] ?? '',
		'currency'        => $estimate['currency'] ?? '',
		'aprobado'        => ! empty( $estimate['approved_at'] ),
		'approved_at'     => $estimate['approved_at'] ?? null,
		'lineas'          => $lineas,
		'instalacion_existente' => $ya_existe > 0 ? $ya_existe : null,
	] );
}

add_action( 'wp_ajax_crm_inst_crear_desde_presupuesto', 'crm_inst_ajax_crear_desde_presupuesto' );
function crm_inst_ajax_crear_desde_presupuesto() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$estimate_id = sanitize_text_field( wp_unslash( $_POST['estimate_id'] ?? '' ) );
	$tipo        = sanitize_key( wp_unslash( $_POST['tipo_instalacion'] ?? '' ) );
	// v1.20.86: puede traer más de un subtipo separado por coma (fotovoltaica
	// Y aerotermia) — crm_inst_crear_desde_presupuesto() ya sanea y valida
	// cada clave suelta, aquí basta con no romper la coma con sanitize_key().
	$subtipo     = sanitize_text_field( wp_unslash( $_POST['subtipo_instalacion'] ?? '' ) );

	$resultado = crm_inst_crear_desde_presupuesto( $estimate_id, $tipo, $subtipo );
	if ( is_wp_error( $resultado ) ) {
		wp_send_json_error( [ 'message' => $resultado->get_error_message() ] );
	}

	wp_send_json_success( $resultado );
}

/**
 * Datos completos de una instalación para la ficha: cliente, tipo/subtipo,
 * estado, materiales con importe/margen calculado, totales, e instaladores
 * asignados (tabla pivote de la Fase 1, `crm_instalacion_instaladores`).
 *
 * Única fuente de esta consulta — la usan tanto la página de ficha como
 * cualquier AJAX que necesite los mismos datos, para no duplicar el SQL.
 *
 * @param int $instalacion_id
 * @return array|WP_Error
 */
function crm_inst_get_instalacion_data( $instalacion_id ) {
	$instalacion_id = (int) $instalacion_id;
	if ( $instalacion_id <= 0 ) {
		return new WP_Error( 'crm_inst_invalid_id', 'Instalación no válida.' );
	}

	global $wpdb;
	$clients_table = $wpdb->prefix . 'crm_clients';
	$inst = $wpdb->get_row( $wpdb->prepare(
		"SELECT i.*, c.cliente_nombre, c.email_cliente, c.telefono
		 FROM " . crm_inst_table_instalaciones() . " i
		 LEFT JOIN {$clients_table} c ON c.id = i.client_id
		 WHERE i.id = %d",
		$instalacion_id
	), ARRAY_A );

	if ( ! $inst ) {
		return new WP_Error( 'crm_inst_not_found', 'Instalación no encontrada.' );
	}

	$materiales_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, descripcion, unidades, precio_unitario, coste_unitario, origen, estado,
		        horas, materiales_usados, observaciones, holded_product_id,
		        justificacion_ruta, cliente_notificado_en,
		        declarado_por, validado_por, validado_en
		 FROM " . crm_inst_table_trabajos() . " WHERE instalacion_id = %d ORDER BY id ASC",
		$instalacion_id
	), ARRAY_A );

	$subtotal_precio = 0.0;
	$subtotal_coste  = 0.0;
	// v1.20.48 — Fase 5: una partida extra solo cuenta en los totales de la
	// instalación (y por tanto en lo que se factura) una vez el jefe la
	// aprueba; mientras esté pendiente o si se rechaza, no debe inflar el
	// margen ni el importe a facturar. Las líneas de Holded siempre cuentan.
	$materiales = array_map( function ( $m ) use ( &$subtotal_precio, &$subtotal_coste ) {
		$unidades = (float) $m['unidades'];
		$precio   = (float) $m['precio_unitario'];
		$coste    = (float) $m['coste_unitario'];
		$importe  = $unidades * $precio;
		$margen   = $unidades * ( $precio - $coste );
		$cuenta_en_total = ( $m['origen'] !== 'extra' ) || ( $m['estado'] === 'aprobado' );
		if ( $cuenta_en_total ) {
			$subtotal_precio += $importe;
			$subtotal_coste  += $unidades * $coste;
		}
		$declarado_por_user = $m['declarado_por'] ? get_userdata( (int) $m['declarado_por'] ) : null;
		$validado_por_user  = $m['validado_por'] ? get_userdata( (int) $m['validado_por'] ) : null;
		return [
			'id'                   => (int) $m['id'],
			'descripcion'          => $m['descripcion'],
			'unidades'             => $unidades,
			'precio_unitario'      => $precio,
			'coste_unitario'       => $coste,
			'importe'              => $importe,
			'margen'               => $margen,
			'origen'               => $m['origen'],
			'estado'               => $m['estado'],
			'cuenta_en_total'      => $cuenta_en_total,
			'horas'                => $m['horas'] !== null ? (float) $m['horas'] : null,
			'materiales_usados'    => $m['materiales_usados'],
			'observaciones'        => $m['observaciones'],
			'holded_product_id'    => $m['holded_product_id'],
			'justificacion_ruta'   => $m['justificacion_ruta'],
			'cliente_notificado_en' => $m['cliente_notificado_en'],
			'declarado_por_nombre' => $declarado_por_user ? $declarado_por_user->display_name : '',
			'validado_por_nombre'  => $validado_por_user ? $validado_por_user->display_name : '',
			'validado_en'          => $m['validado_en'],
		];
	}, (array) $materiales_raw );

	$instaladores = $wpdb->get_results( $wpdb->prepare(
		"SELECT pi.user_id, pi.rol_en_proyecto, pi.horas_declaradas, u.display_name
		 FROM " . crm_inst_table_instaladores() . " pi
		 INNER JOIN {$wpdb->users} u ON u.ID = pi.user_id
		 WHERE pi.instalacion_id = %d",
		$instalacion_id
	), ARRAY_A );

	$log = $wpdb->get_results( $wpdb->prepare(
		"SELECT l.entity_type, l.accion, l.detalle, l.fecha, u.display_name
		 FROM " . crm_inst_table_log() . " l
		 LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
		 WHERE l.instalacion_id = %d
		 ORDER BY l.fecha DESC
		 LIMIT 50",
		$instalacion_id
	), ARRAY_A );

	$agenda = $wpdb->get_row( $wpdb->prepare(
		"SELECT fecha_cita, estado, instalador_id FROM " . crm_inst_table_agenda() . " WHERE instalacion_id = %d LIMIT 1",
		$instalacion_id
	), ARRAY_A );

	$fotos_cierre = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, ruta, categoria, subido_por, subido_en FROM " . crm_inst_table_documentos() . " WHERE instalacion_id = %d AND tipo = 'foto' ORDER BY id ASC",
		$instalacion_id
	), ARRAY_A );
	$cierre_declarado_por_user = $inst['cierre_declarado_por'] ? get_userdata( (int) $inst['cierre_declarado_por'] ) : null;
	$cierre_validado_por_user  = $inst['cierre_validado_por'] ? get_userdata( (int) $inst['cierre_validado_por'] ) : null;

	$tipos    = crm_instalaciones_tipos();
	$estados  = crm_instalaciones_estados();

	return [
		'id'                  => (int) $inst['id'],
		'client_id'           => (int) $inst['client_id'],
		'cliente_nombre'      => $inst['cliente_nombre'] ?: '(sin cliente)',
		'email_cliente'       => $inst['email_cliente'],
		'telefono'            => $inst['telefono'],
		'direccion_instalacion' => $inst['direccion_instalacion'],
		'lat'                 => $inst['lat'] !== null ? (float) $inst['lat'] : null,
		'lng'                 => $inst['lng'] !== null ? (float) $inst['lng'] : null,
		'geocode_status'      => $inst['geocode_status'],
		'proveedor_notificado_en' => $inst['proveedor_notificado_en'],
		'proveedor_pedido_estado' => $inst['proveedor_pedido_estado'],
		'proveedor_confirmado_en' => $inst['proveedor_confirmado_en'],
		'proveedor_notas'         => $inst['proveedor_notas'],
		'proveedor_entrega_estimada' => $inst['proveedor_entrega_estimada'],
		'tipo_instalacion'    => $inst['tipo_instalacion'],
		'tipo_instalacion_label' => $tipos[ $inst['tipo_instalacion'] ] ?? $inst['tipo_instalacion'],
		'subtipo_instalacion' => $inst['subtipo_instalacion'],
		'subtipo_instalacion_label' => crm_inst_subtipo_label( $inst['subtipo_instalacion'] ),
		'estado'              => $inst['estado'],
		'estado_label'        => $estados[ $inst['estado'] ] ?? $inst['estado'],
		'holded_doc_id'       => $inst['holded_doc_id'],
		'fecha_creacion'      => $inst['fecha_creacion'],
		'materiales'          => $materiales,
		'totales'             => [
			'subtotal_precio' => $subtotal_precio,
			'subtotal_coste'  => $subtotal_coste,
			'margen'          => $subtotal_precio - $subtotal_coste,
		],
		'instaladores'        => $instaladores,
		'agenda'              => $agenda ?: null,
		'log'                 => $log,
		'cierre'              => [
			'estado'               => $inst['cierre_estado'],
			'conformidad'          => $inst['cierre_conformidad'] !== null ? (bool) $inst['cierre_conformidad'] : null,
			'observaciones'        => $inst['cierre_observaciones'],
			'declarado_por_nombre' => $cierre_declarado_por_user ? $cierre_declarado_por_user->display_name : '',
			'declarado_en'         => $inst['cierre_declarado_en'],
			'validado_por_nombre'  => $cierre_validado_por_user ? $cierre_validado_por_user->display_name : '',
			'validado_en'          => $inst['cierre_validado_en'],
			'fotos'                => $fotos_cierre,
		],
		'checklist_confirmado_en' => $inst['checklist_confirmado_en'],
		'holded_invoice_id'   => $inst['holded_invoice_id'],
		'holded_purchase_id'  => $inst['holded_purchase_id'],
		'holded_project_id'   => $inst['holded_project_id'],
		'holded_waybill_id'      => $inst['holded_waybill_id'],
		'holded_waybill_numero'  => $inst['holded_waybill_numero'],
		'holded_waybill_aprobado' => $inst['holded_waybill_aprobado'] !== null ? (bool) $inst['holded_waybill_aprobado'] : null,
		'holded_waybill_error'   => $inst['holded_waybill_error'],
	];
}

/**
 * Cambia el estado de una instalación a mano. Sin reglas de transición
 * todavía salvo una (Fase 3): no se puede pasar a `lista` si quedan
 * materiales de Holded sin marcar como recibidos — ver
 * `crm_inst_materiales_pendientes()`.
 */
add_action( 'wp_ajax_crm_inst_cambiar_estado', 'crm_inst_ajax_cambiar_estado' );
function crm_inst_ajax_cambiar_estado() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$estado         = sanitize_key( wp_unslash( $_POST['estado'] ?? '' ) );
	$estados        = crm_instalaciones_estados();

	if ( $instalacion_id <= 0 || ! isset( $estados[ $estado ] ) ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	if ( $estado === 'lista' ) {
		$pendientes = crm_inst_materiales_pendientes( $instalacion_id );
		if ( $pendientes > 0 ) {
			wp_send_json_error( [ 'message' => 'No se puede pasar a "Lista": quedan ' . $pendientes . ' material(es) sin recibir.' ] );
		}
	}

	$result = $wpdb->update( crm_inst_table_instalaciones(), [ 'estado' => $estado ], [ 'id' => $instalacion_id ] );
	if ( $result === false ) {
		wp_send_json_error( [ 'message' => 'No se pudo actualizar el estado: ' . $wpdb->last_error ] );
	}

	crm_inst_log_action( $instalacion_id, 'instalacion', 'cambiar_estado', 'Estado cambiado a ' . $estados[ $estado ] . ' (manual).' );

	wp_send_json_success( [ 'estado' => $estado, 'estado_label' => $estados[ $estado ] ] );
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 3 — Marcado manual de material recibido + transición a "lista"
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Nº de materiales de Holded (origen='holded') todavía no marcados como
 * recibidos. El trabajo extraordinario (origen='extra', Fase 5) no cuenta
 * aquí — es mano de obra declarada por el instalador, no algo que "llegue"
 * de un proveedor.
 *
 * @param int $instalacion_id
 * @return int
 */
function crm_inst_materiales_pendientes( $instalacion_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . crm_inst_table_trabajos() . "
		 WHERE instalacion_id = %d AND origen = 'holded' AND estado != 'recibido'",
		(int) $instalacion_id
	) );
}

// ──────────────────────────────────────────────────────────────────────────────
// Aviso proactivo: materiales sin recibir a pocos días de la visita agendada
// (v1.20.62) — hasta ahora el sistema era puramente "pull" (solo se veía si
// alguien entraba a mirar el listado o la ficha); esto avisa solo.
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Registra el cron cada hora — la comprobación real solo actúa una vez al
 * día, a la hora configurada en Ajustes (ver crm_inst_aviso_materiales_pendientes_run()).
 * No se puede programar `wp_schedule_event` directamente "a las 9:00
 * configurables" sin reprogramar el evento cada vez que cambie el ajuste, así
 * que el cron corre cada hora y decide él solo si le toca actuar.
 */
function crm_inst_aviso_schedule_cron() {
	if ( ! wp_next_scheduled( 'crm_inst_aviso_cron_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'crm_inst_aviso_cron_hourly' );
	}
}
// Self-heal: por si el activation hook no llegó a correr (update sin
// desactivar), igual que ya hace crm_logger_schedule_cron().
add_action( 'init', function () {
	if ( ! wp_doing_cron() && ! wp_next_scheduled( 'crm_inst_aviso_cron_hourly' ) ) {
		crm_inst_aviso_schedule_cron();
	}
}, 20 );

add_action( 'crm_inst_aviso_cron_hourly', 'crm_inst_aviso_materiales_pendientes_run' );
/**
 * Comprueba instalaciones con visita agendada dentro de los próximos
 * `crm_inst_aviso_dias_antes` días que todavía tengan materiales de Holded
 * sin recibir, y avisa al jefe de instalaciones. Se ejecuta como mucho una
 * vez al día (guardado en `crm_inst_aviso_last_run`), a la hora configurada
 * en Ajustes — cada día que la instalación siga pendiente, vuelve a avisar.
 */
function crm_inst_aviso_materiales_pendientes_run() {
	$hoy = current_time( 'Y-m-d' );
	if ( get_option( 'crm_inst_aviso_last_run', '' ) === $hoy ) {
		return;
	}
	$hora_config = (int) get_option( 'crm_inst_aviso_hora', 9 );
	if ( (int) current_time( 'G' ) !== $hora_config ) {
		return;
	}
	update_option( 'crm_inst_aviso_last_run', $hoy, false );

	$dias   = max( 0, (int) get_option( 'crm_inst_aviso_dias_antes', 3 ) );
	$ahora  = current_time( 'mysql' );
	$limite = date( 'Y-m-d H:i:s', strtotime( '+' . $dias . ' days', current_time( 'timestamp' ) ) );

	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT i.id, i.proveedor_pedido_estado, i.proveedor_entrega_estimada, a.fecha_cita, c.cliente_nombre
		 FROM " . crm_inst_table_instalaciones() . " i
		 INNER JOIN " . crm_inst_table_agenda() . " a ON a.instalacion_id = i.id
		 LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
		 WHERE i.estado NOT IN ( 'finalizada', 'cancelada' )
		   AND a.fecha_cita BETWEEN %s AND %s",
		$ahora, $limite
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return;
	}

	$canal_inapp    = (bool) get_option( 'crm_inst_aviso_canal_inapp', true );
	$canal_email    = (bool) get_option( 'crm_inst_aviso_canal_email', true );
	$canal_whatsapp = (bool) get_option( 'crm_inst_aviso_canal_whatsapp', false );

	foreach ( $rows as $r ) {
		$pendientes = crm_inst_materiales_pendientes( (int) $r['id'] );
		if ( $pendientes <= 0 ) {
			continue;
		}
		$fecha_label = date_i18n( 'd/m/Y H:i', strtotime( $r['fecha_cita'] ) );
		$mensaje = 'Visita el ' . $fecha_label . ' — ' . ( $r['cliente_nombre'] ?: ( 'instalación #' . $r['id'] ) ) . ': quedan ' . $pendientes . ' material(es) sin recibir.';

		// v1.20.63: el mensaje se ajusta según lo que sepamos del pedido —
		// sin esto, un pedido ya confirmado con fecha de entrega holgada
		// suena tan urgente como uno que nadie ha pedido todavía.
		if ( ! empty( $r['proveedor_entrega_estimada'] ) ) {
			$entrega_ts = strtotime( $r['proveedor_entrega_estimada'] );
			$visita_ts  = strtotime( $r['fecha_cita'] );
			$entrega_label = date_i18n( 'd/m/Y', $entrega_ts );
			if ( $entrega_ts >= strtotime( date( 'Y-m-d', $visita_ts ) ) ) {
				$mensaje .= ' AVISO: el proveedor estima entrega el ' . $entrega_label . ', en o después de la visita — puede no llegar a tiempo.';
			} else {
				$mensaje .= ' El proveedor estima entrega el ' . $entrega_label . ', antes de la visita.';
			}
		} elseif ( $r['proveedor_pedido_estado'] === 'enviado' ) {
			$mensaje .= ' Pedido enviado al proveedor, todavía sin confirmar.';
		} elseif ( empty( $r['proveedor_pedido_estado'] ) ) {
			$mensaje .= ' Todavía no se ha pedido al proveedor.';
		}
		$url = add_query_arg( 'id', $r['id'], home_url( '/instalacion/' ) );

		if ( $canal_inapp && function_exists( 'crm_notificar_jefes_instalaciones' ) ) {
			crm_notificar_jefes_instalaciones( 'materiales_pendientes_aviso', $mensaje, $url );
		}
		if ( $canal_email ) {
			crm_inst_aviso_enviar_email_jefes( 'Materiales pendientes antes de una visita', $mensaje, $url );
		}
		if ( $canal_whatsapp ) {
			crm_inst_aviso_enviar_whatsapp_jefes( $r['cliente_nombre'] ?: ( 'instalación #' . $r['id'] ), $fecha_label, (string) $pendientes, $url );
		}
		crm_inst_log_action( (int) $r['id'], 'instalacion', 'aviso_materiales_pendientes', $mensaje );
	}
}

/**
 * Envía un email a todos los jefe_instalaciones/crm_admin — mismo público que
 * `crm_notificar_jefes_instalaciones()`, pero por email en vez de in-app.
 */
function crm_inst_aviso_enviar_email_jefes( $asunto, $mensaje, $url = '' ) {
	$users = get_users( [ 'role__in' => [ 'jefe_instalaciones', 'crm_admin' ], 'fields' => [ 'ID', 'user_email', 'display_name' ] ] );
	if ( empty( $users ) ) {
		return;
	}
	$body = '<p>' . esc_html( $mensaje ) . '</p>';
	if ( $url !== '' ) {
		$body .= '<p><a href="' . esc_url( $url ) . '">Ver instalación</a></p>';
	}
	$body .= '<p style="color:#666;font-size:12px">Aviso automático del CRM.</p>';
	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	foreach ( $users as $u ) {
		if ( ! empty( $u->user_email ) ) {
			wp_mail( $u->user_email, '[CRM] ' . $asunto, $body, $headers );
		}
	}
}

/**
 * v1.20.87: hermano por WhatsApp de crm_inst_aviso_enviar_email_jefes() —
 * mismo público (jefe_instalaciones/crm_admin), pero solo intenta enviar si
 * hay credenciales configuradas Y el jefe tiene un número guardado (mismo
 * user-meta `crm_whatsapp` que ya usa el instalador en "Mi perfil", ahora
 * también editable desde el perfil de wp-admin para estos roles). Un fallo
 * (sin configurar, sin plantilla, sin número, error de la API) se registra
 * en el log y no interrumpe el resto del aviso — el email sigue siendo el
 * canal que de verdad funciona hoy.
 */
function crm_inst_aviso_enviar_whatsapp_jefes( $cliente_nombre, $fecha_label, $pendientes, $url = '' ) {
	if ( ! function_exists( 'crm_whatsapp_configurado' ) || ! crm_whatsapp_configurado() ) {
		return;
	}
	$template = trim( (string) get_option( 'crm_whatsapp_template_aviso_materiales', '' ) );
	if ( $template === '' ) {
		return;
	}
	$users = get_users( [ 'role__in' => [ 'jefe_instalaciones', 'crm_admin' ], 'fields' => [ 'ID' ] ] );
	foreach ( $users as $u ) {
		$telefono = get_user_meta( (int) $u->ID, 'crm_whatsapp', true );
		if ( empty( $telefono ) ) {
			continue;
		}
		$resultado = crm_whatsapp_enviar_plantilla( $telefono, $template, [ $cliente_nombre, $fecha_label, $pendientes ] );
		if ( is_wp_error( $resultado ) ) {
			crm_whatsapp_log_error( $template, 'Aviso materiales a jefe #' . $u->ID . ': ' . $resultado->get_error_message() );
		}
	}
}

/**
 * Mantiene el invariante "estado = lista  ⇔  cero materiales de Holded
 * pendientes" en los dos sentidos, para que marcar/desmarcar una casilla
 * nunca deje la instalación en un estado incoherente:
 *
 *  - Hacia delante: si ya no queda ningún material pendiente y el estado
 *    sigue siendo "de antes de tener todo" (borrador/pendiente), pasa sola
 *    a `lista`.
 *  - Hacia atrás: si se desmarca un material (por error o no) y el estado
 *    actual es exactamente `lista`, vuelve a `pendiente` — porque `lista`
 *    ya no sería cierto.
 *
 * Deliberadamente NO toca instalaciones más avanzadas (planificada,
 * en_ejecucion, finalizada...): una vez que una persona la movió a mano
 * más allá de `lista` (instalador ya en camino o trabajando), desmarcar un
 * material por error no debe deshacer ese avance solo — eso sí lo revisa
 * a mano quien gestione la instalación.
 *
 * @param int $instalacion_id
 */
function crm_inst_sync_estado_con_materiales( $instalacion_id ) {
	$instalacion_id = (int) $instalacion_id;
	global $wpdb;

	$total_holded = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . crm_inst_table_trabajos() . " WHERE instalacion_id = %d AND origen = 'holded'",
		$instalacion_id
	) );
	if ( $total_holded === 0 ) {
		return; // Sin materiales no hay nada que "esperar" — se deja al cambio manual.
	}

	$pendientes    = crm_inst_materiales_pendientes( $instalacion_id );
	$estado_actual = $wpdb->get_var( $wpdb->prepare(
		"SELECT estado FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		$instalacion_id
	) );

	if ( $pendientes === 0 && in_array( $estado_actual, [ 'borrador', 'pendiente' ], true ) ) {
		$wpdb->update( crm_inst_table_instalaciones(), [ 'estado' => 'lista' ], [ 'id' => $instalacion_id ] );
		crm_inst_log_action( $instalacion_id, 'instalacion', 'auto_lista', 'Todos los materiales recibidos — pasa a "Lista" automáticamente.' );
		return;
	}

	if ( $pendientes > 0 && $estado_actual === 'lista' ) {
		$wpdb->update( crm_inst_table_instalaciones(), [ 'estado' => 'pendiente' ], [ 'id' => $instalacion_id ] );
		crm_inst_log_action( $instalacion_id, 'instalacion', 'auto_pendiente', 'Un material vuelve a pendiente — ya no está todo recibido, vuelve de "Lista" a "Pendiente" automáticamente.' );
	}
}

/**
 * Marca/desmarca un material como recibido y sincroniza el estado de la
 * instalación en el sentido que corresponda.
 */
add_action( 'wp_ajax_crm_inst_marcar_material', 'crm_inst_ajax_marcar_material' );
function crm_inst_ajax_marcar_material() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$material_id = (int) ( $_POST['material_id'] ?? 0 );
	$recibido     = ! empty( $_POST['recibido'] ) && $_POST['recibido'] !== 'false';

	$material = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . crm_inst_table_trabajos() . " WHERE id = %d",
		$material_id
	), ARRAY_A );
	if ( ! $material ) {
		wp_send_json_error( [ 'message' => 'Material no encontrado.' ] );
	}

	$nuevo_estado = $recibido ? 'recibido' : 'pendiente';
	$wpdb->update( crm_inst_table_trabajos(), [ 'estado' => $nuevo_estado ], [ 'id' => $material_id ] );
	crm_inst_log_action( (int) $material['instalacion_id'], 'material', 'marcar_' . $nuevo_estado, $material['descripcion'] . ' → ' . $nuevo_estado );

	// v1.20.60: el descuento de stock en Holded se movió de aquí (marcar
	// "Recibido" en oficina) al checklist del instalador — ver
	// crm_inst_ajax_confirmar_checklist(). "Recibido" significa "ha llegado
	// del proveedor", no "ha salido del almacén hacia la obra"; el momento
	// correcto para restar stock es cuando el instalador confirma que se
	// lleva el material de verdad, justo antes de empezar el trabajo.
	$holded_stock_sync = null;

	crm_inst_sync_estado_con_materiales( (int) $material['instalacion_id'] );

	$instalacion = $wpdb->get_row( $wpdb->prepare(
		"SELECT estado FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		(int) $material['instalacion_id']
	), ARRAY_A );
	$estados = crm_instalaciones_estados();

	wp_send_json_success( [
		'estado'             => $nuevo_estado,
		'pendientes'         => crm_inst_materiales_pendientes( (int) $material['instalacion_id'] ),
		'instalacion_estado' => $instalacion['estado'] ?? null,
		'instalacion_estado_label' => isset( $instalacion['estado'] ) ? ( $estados[ $instalacion['estado'] ] ?? $instalacion['estado'] ) : null,
		'holded_stock_sync'  => $holded_stock_sync,
	] );
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 5 — Partidas extra declaradas por el instalador
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Un instalador declara una partida extra (mano de obra/material no previsto
 * en el presupuesto de Holded) desde su propio panel. Queda `pendiente` hasta
 * que el jefe la valida — no cuenta en los totales de la instalación hasta
 * entonces (ver `crm_inst_get_instalacion_data()`).
 */
add_action( 'wp_ajax_crm_inst_declarar_extra', 'crm_inst_ajax_declarar_extra' );
function crm_inst_ajax_declarar_extra() {
	if ( ! is_user_logged_in() || ! current_user_can( 'crm_inst_edit_own' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$user_id           = get_current_user_id();
	$instalacion_id    = (int) ( $_POST['instalacion_id'] ?? 0 );
	$descripcion       = sanitize_text_field( wp_unslash( $_POST['descripcion'] ?? '' ) );
	$importe           = (float) str_replace( ',', '.', (string) ( $_POST['importe'] ?? 0 ) );
	$horas_raw         = trim( (string) ( $_POST['horas'] ?? '' ) );
	$horas             = $horas_raw === '' ? null : (float) str_replace( ',', '.', $horas_raw );
	$materiales_usados = sanitize_textarea_field( wp_unslash( $_POST['materiales_usados'] ?? '' ) );
	$observaciones     = sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ?? '' ) );
	// v1.20.75: si el instalador seleccionó un producto real del autocomplete
	// de Holded, queda enganchado igual que una línea de presupuesto — no se
	// usa para descontar stock automáticamente (a diferencia del checklist),
	// solo como referencia fiable de qué material fue.
	$holded_product_id = sanitize_text_field( wp_unslash( $_POST['holded_product_id'] ?? '' ) );

	if ( $instalacion_id <= 0 || $descripcion === '' || $importe <= 0 ) {
		wp_send_json_error( [ 'message' => 'Rellena descripción e importe.' ] );
	}
	if ( $horas !== null && $horas < 0 ) {
		wp_send_json_error( [ 'message' => 'Las horas no pueden ser negativas.' ] );
	}
	if ( empty( $_FILES['justificacion']['name'] ) ) {
		wp_send_json_error( [ 'message' => 'Adjunta una foto que justifique la partida extra.' ] );
	}

	$max = crm_inst_extra_max();
	if ( $importe > $max ) {
		wp_send_json_error( [ 'message' => 'El importe máximo por partida extra es ' . number_format_i18n( $max, 2 ) . ' €. Si es mayor, avisa a tu jefe de instalaciones por otro medio.' ] );
	}

	// Un instalador solo puede declarar extras en instalaciones donde está
	// asignado; un jefe/admin puede hacerlo en cualquiera (p.ej. de prueba).
	if ( ! crm_inst_current_user_can_manage() ) {
		$asignado = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
			$instalacion_id, $user_id
		) );
		if ( $asignado === 0 ) {
			wp_send_json_error( [ 'message' => 'No estás asignado a esta instalación.' ], 403 );
		}
	}

	$justificacion_ruta = '';
	if ( function_exists( 'crm_handle_secure_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$subida = crm_handle_secure_upload( $_FILES['justificacion'], 'justificacion_extra' );
		if ( is_wp_error( $subida ) ) {
			wp_send_json_error( [ 'message' => 'No se pudo subir la foto de justificación: ' . $subida->get_error_message() ] );
		}
		$justificacion_ruta = $subida['url'];
	}

	$wpdb->insert( crm_inst_table_trabajos(), [
		'instalacion_id'     => $instalacion_id,
		'descripcion'        => $descripcion,
		'estado'             => 'pendiente',
		'es_extraordinario'  => 1,
		'origen'             => 'extra',
		'unidades'           => 1,
		'precio_unitario'    => $importe,
		'horas'              => $horas,
		'materiales_usados'  => $materiales_usados !== '' ? $materiales_usados : null,
		'holded_product_id'  => $holded_product_id !== '' ? $holded_product_id : null,
		'observaciones'      => $observaciones !== '' ? $observaciones : null,
		'justificacion_ruta' => $justificacion_ruta !== '' ? $justificacion_ruta : null,
		'declarado_por'      => $user_id,
	] );
	$trabajo_id = (int) $wpdb->insert_id;

	$detalle_log = 'Partida extra declarada: "' . $descripcion . '" — ' . number_format_i18n( $importe, 2 ) . ' €';
	if ( $horas !== null ) {
		$detalle_log .= ', ' . number_format_i18n( $horas, 2 ) . ' h';
	}
	$detalle_log .= ' (pendiente de enviar al cliente para validar).';
	crm_inst_log_action( $instalacion_id, 'trabajo', 'extra_declarado', $detalle_log );

	if ( function_exists( 'crm_notificar_jefes_instalaciones' ) ) {
		$cliente_nombre = $wpdb->get_var( $wpdb->prepare(
			"SELECT c.cliente_nombre FROM " . crm_inst_table_instalaciones() . " i LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id WHERE i.id = %d",
			$instalacion_id
		) );
		crm_notificar_jefes_instalaciones(
			'extra_declarada',
			'Nueva partida extra pendiente: "' . $descripcion . '" — ' . number_format_i18n( $importe, 2 ) . ' € en ' . ( $cliente_nombre ?: ( 'instalación #' . $instalacion_id ) ),
			add_query_arg( 'id', $instalacion_id, home_url( '/instalacion/' ) )
		);
	}

	wp_send_json_success( [
		'id'          => $trabajo_id,
		'descripcion' => $descripcion,
		'importe'     => $importe,
		'estado'      => 'pendiente',
	] );
}

/**
 * v1.20.76: el instalador puede corregir una partida extra que declaró mal
 * (descripción, horas, materiales, importe, observaciones, foto) mientras
 * siga en estado `pendiente` — en cuanto se envía al cliente ya no se puede
 * tocar, para que lo que el cliente ve y aprueba sea justo lo que se declaró.
 */
add_action( 'wp_ajax_crm_inst_editar_extra', 'crm_inst_ajax_editar_extra' );
function crm_inst_ajax_editar_extra() {
	if ( ! is_user_logged_in() || ! current_user_can( 'crm_inst_edit_own' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$user_id           = get_current_user_id();
	$trabajo_id        = (int) ( $_POST['trabajo_id'] ?? 0 );
	$descripcion       = sanitize_text_field( wp_unslash( $_POST['descripcion'] ?? '' ) );
	$importe           = (float) str_replace( ',', '.', (string) ( $_POST['importe'] ?? 0 ) );
	$horas_raw         = trim( (string) ( $_POST['horas'] ?? '' ) );
	$horas             = $horas_raw === '' ? null : (float) str_replace( ',', '.', $horas_raw );
	$materiales_usados = sanitize_textarea_field( wp_unslash( $_POST['materiales_usados'] ?? '' ) );
	$observaciones     = sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ?? '' ) );
	$holded_product_id = sanitize_text_field( wp_unslash( $_POST['holded_product_id'] ?? '' ) );

	if ( $trabajo_id <= 0 || $descripcion === '' || $importe <= 0 ) {
		wp_send_json_error( [ 'message' => 'Rellena descripción e importe.' ] );
	}
	if ( $horas !== null && $horas < 0 ) {
		wp_send_json_error( [ 'message' => 'Las horas no pueden ser negativas.' ] );
	}
	$max = crm_inst_extra_max();
	if ( $importe > $max ) {
		wp_send_json_error( [ 'message' => 'El importe máximo por partida extra es ' . number_format_i18n( $max, 2 ) . ' €.' ] );
	}

	$trabajo = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . crm_inst_table_trabajos() . " WHERE id = %d AND origen = 'extra'",
		$trabajo_id
	), ARRAY_A );
	if ( ! $trabajo ) {
		wp_send_json_error( [ 'message' => 'Partida extra no encontrada.' ] );
	}
	if ( $trabajo['estado'] !== 'pendiente' ) {
		wp_send_json_error( [ 'message' => 'Ya se envió al cliente, no se puede editar. Si hace falta corregirla, avisa a tu jefe de instalaciones.' ] );
	}

	if ( ! crm_inst_current_user_can_manage() ) {
		$asignado = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
			$trabajo['instalacion_id'], $user_id
		) );
		if ( $asignado === 0 ) {
			wp_send_json_error( [ 'message' => 'No estás asignado a esta instalación.' ], 403 );
		}
	}

	$update = [
		'descripcion'       => $descripcion,
		'precio_unitario'   => $importe,
		'horas'             => $horas,
		'materiales_usados' => $materiales_usados !== '' ? $materiales_usados : null,
		'holded_product_id' => $holded_product_id !== '' ? $holded_product_id : null,
		'observaciones'     => $observaciones !== '' ? $observaciones : null,
	];

	// La foto es opcional al editar: si no se manda una nueva, se conserva la
	// que ya había. Si la partida no tenía ninguna todavía, sí es obligatoria
	// (mismo requisito que al declararla).
	if ( ! empty( $_FILES['justificacion']['name'] ) ) {
		if ( function_exists( 'crm_handle_secure_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$subida = crm_handle_secure_upload( $_FILES['justificacion'], 'justificacion_extra' );
			if ( is_wp_error( $subida ) ) {
				wp_send_json_error( [ 'message' => 'No se pudo subir la foto de justificación: ' . $subida->get_error_message() ] );
			}
			$update['justificacion_ruta'] = $subida['url'];
		}
	} elseif ( empty( $trabajo['justificacion_ruta'] ) ) {
		wp_send_json_error( [ 'message' => 'Adjunta una foto que justifique la partida extra.' ] );
	}

	$wpdb->update( crm_inst_table_trabajos(), $update, [ 'id' => $trabajo_id ] );

	crm_inst_log_action( (int) $trabajo['instalacion_id'], 'trabajo', 'extra_editado', 'Partida extra editada: "' . $descripcion . '" — ' . number_format_i18n( $importe, 2 ) . ' €.' );

	wp_send_json_success( [ 'id' => $trabajo_id ] );
}

/**
 * v1.20.75: desde que la partida extra la valida el CLIENTE por email (ver
 * crm_inst_ajax_enviar_extra_cliente() / crm_inst_ajax_validar_extra_cliente()),
 * esto queda como respaldo manual del jefe — igual que "Marcar confirmado a
 * mano" en el flujo del proveedor — para los casos en que el cliente no
 * responde por el canal digital. Aprobarla la incorpora a los totales.
 */
add_action( 'wp_ajax_crm_inst_validar_extra', 'crm_inst_ajax_validar_extra' );
function crm_inst_ajax_validar_extra() {
	if ( ! current_user_can( 'crm_inst_validate_extra' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$trabajo_id = (int) ( $_POST['trabajo_id'] ?? 0 );
	$decision   = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );

	if ( $trabajo_id <= 0 || ! in_array( $decision, [ 'aprobar', 'rechazar' ], true ) ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$trabajo = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . crm_inst_table_trabajos() . " WHERE id = %d AND origen = 'extra'",
		$trabajo_id
	), ARRAY_A );
	if ( ! $trabajo ) {
		wp_send_json_error( [ 'message' => 'Partida extra no encontrada.' ] );
	}

	$nuevo_estado = $decision === 'aprobar' ? 'aprobado' : 'rechazado';
	$wpdb->update( crm_inst_table_trabajos(), [
		'estado'       => $nuevo_estado,
		'validado_por' => get_current_user_id(),
		'validado_en'  => current_time( 'mysql' ),
	], [ 'id' => $trabajo_id ] );

	crm_inst_log_action( (int) $trabajo['instalacion_id'], 'trabajo', 'extra_' . $nuevo_estado, 'Partida extra "' . $trabajo['descripcion'] . '" ' . $nuevo_estado . ' por el jefe.' );

	if ( function_exists( 'crm_notificar' ) && ! empty( $trabajo['declarado_por'] ) ) {
		crm_notificar(
			(int) $trabajo['declarado_por'],
			'extra_' . $nuevo_estado,
			'Tu partida extra "' . $trabajo['descripcion'] . '" ha sido ' . $nuevo_estado . '.',
			home_url( '/panel-instalador/' )
		);
	}

	wp_send_json_success( [ 'id' => $trabajo_id, 'estado' => $nuevo_estado ] );
}

/**
 * Autocomplete de materiales del catálogo de Holded, para que el instalador
 * declare una partida extra con materiales de verdad (evita texto libre con
 * erratas) en vez de escribirlo a mano. Reutiliza el mismo catálogo cacheado
 * que ya usa el emparejamiento de stock — ver crm_holded_get_products_cached().
 */
add_action( 'wp_ajax_crm_inst_buscar_material_holded', 'crm_inst_ajax_buscar_material_holded' );
function crm_inst_ajax_buscar_material_holded() {
	if ( ! is_user_logged_in() || ! current_user_can( 'crm_inst_edit_own' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}
	if ( ! function_exists( 'crm_holded_get_products_cached' ) || ! function_exists( 'crm_holded_normalizar_texto' ) ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	$query = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
	if ( mb_strlen( $query ) < 2 ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	$productos = crm_holded_get_products_cached();
	if ( is_wp_error( $productos ) || ! is_array( $productos ) ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	$query_norm = crm_holded_normalizar_texto( $query );
	$items = [];
	foreach ( $productos as $p ) {
		$nombre = (string) ( $p['name'] ?? '' );
		if ( $nombre === '' || strpos( crm_holded_normalizar_texto( $nombre ), $query_norm ) === false ) {
			continue;
		}
		$items[] = [
			'id'   => (string) ( $p['id'] ?? '' ),
			'name' => $nombre,
			'sku'  => (string) ( $p['sku'] ?? '' ),
		];
		if ( count( $items ) >= 10 ) {
			break;
		}
	}

	wp_send_json_success( [ 'items' => $items ] );
}

/**
 * El instalador (o un jefe/admin) envía una partida extra concreta al
 * cliente para que la valide por email — mismo patrón que
 * crm_inst_ajax_notificar_proveedor(): token de un solo uso, enlace público
 * sin login. Sustituye la aprobación del jefe como vía principal (ver
 * crm_inst_ajax_validar_extra_cliente()); el jefe conserva un respaldo
 * manual en crm_inst_ajax_validar_extra().
 */
add_action( 'wp_ajax_crm_inst_enviar_extra_cliente', 'crm_inst_ajax_enviar_extra_cliente' );
function crm_inst_ajax_enviar_extra_cliente() {
	if ( ! is_user_logged_in() || ! current_user_can( 'crm_inst_edit_own' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$user_id    = get_current_user_id();
	$trabajo_id = (int) ( $_POST['trabajo_id'] ?? 0 );
	if ( $trabajo_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$trabajo = $wpdb->get_row( $wpdb->prepare(
		"SELECT t.*, i.client_id, c.cliente_nombre, c.email_cliente
		 FROM " . crm_inst_table_trabajos() . " t
		 INNER JOIN " . crm_inst_table_instalaciones() . " i ON i.id = t.instalacion_id
		 LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
		 WHERE t.id = %d AND t.origen = 'extra'",
		$trabajo_id
	), ARRAY_A );
	if ( ! $trabajo ) {
		wp_send_json_error( [ 'message' => 'Partida extra no encontrada.' ] );
	}

	if ( ! crm_inst_current_user_can_manage() ) {
		$asignado = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
			$trabajo['instalacion_id'], $user_id
		) );
		if ( $asignado === 0 ) {
			wp_send_json_error( [ 'message' => 'No estás asignado a esta instalación.' ], 403 );
		}
	}

	if ( in_array( $trabajo['estado'], [ 'aprobado', 'rechazado' ], true ) ) {
		wp_send_json_error( [ 'message' => 'Esta partida ya está ' . $trabajo['estado'] . ', no hace falta reenviarla.' ] );
	}

	// v1.20.76: el modal de "Enviar al cliente" deja corregir el email/WhatsApp
	// que ya tenemos en la ficha del cliente, por si están desactualizados —
	// si no se manda nada, se usa el de la ficha como hasta ahora.
	$email_form = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$cliente_email = $email_form !== '' ? $email_form : sanitize_email( (string) $trabajo['email_cliente'] );
	if ( $cliente_email === '' ) {
		wp_send_json_error( [ 'message' => 'Indica un email de contacto — el cliente no tiene ninguno registrado en su ficha.' ] );
	}
	// v1.20.87: si hay credenciales de WhatsApp Business configuradas Y una
	// plantilla asignada, se intenta un envío real (best-effort, nunca
	// bloquea el email); si no, el número solo queda anotado en el log para
	// cuando se configure — mismo comportamiento que antes.
	$whatsapp_form = sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) );

	$token = wp_generate_password( 32, false );
	$validar_url = add_query_arg( 'token', $token, home_url( '/validar-extra/' ) );

	$subject = sprintf( 'Confirmación de trabajo adicional — %s', $trabajo['cliente_nombre'] ?: 'tu instalación' );

	$body  = '<p>Durante la instalación se ha detectado un trabajo adicional que necesita tu aprobación:</p>';
	$body .= '<p><strong>' . esc_html( $trabajo['descripcion'] ) . '</strong></p>';
	if ( ! empty( $trabajo['materiales_usados'] ) ) {
		$body .= '<p>Materiales: ' . esc_html( $trabajo['materiales_usados'] ) . '</p>';
	}
	if ( $trabajo['horas'] !== null ) {
		$body .= '<p>Horas: ' . esc_html( number_format_i18n( (float) $trabajo['horas'], 2 ) ) . '</p>';
	}
	$body .= '<p>Importe: ' . esc_html( number_format_i18n( (float) $trabajo['precio_unitario'], 2 ) ) . ' €</p>';
	if ( ! empty( $trabajo['justificacion_ruta'] ) ) {
		$body .= '<p><img src="' . esc_url( $trabajo['justificacion_ruta'] ) . '" alt="Foto de justificación" style="max-width:320px;border-radius:8px;"></p>';
	}
	$body .= '<p><a href="' . esc_url( $validar_url ) . '">Ver y responder a esta solicitud</a></p>';
	$body .= '<p style="color:#666;font-size:12px">Mensaje automático de Ecovolt Renovables S.L. No responder a este correo — usa el enlace de arriba.</p>';

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	$sent    = wp_mail( $cliente_email, $subject, $body, $headers );

	if ( ! $sent ) {
		wp_send_json_error( [ 'message' => 'No se pudo enviar el email (revisa la configuración de envío del servidor).' ] );
	}

	$wpdb->update( crm_inst_table_trabajos(), [
		'estado'                => 'enviado_cliente',
		'cliente_token'         => $token,
		'cliente_notificado_en' => current_time( 'mysql' ),
	], [ 'id' => $trabajo_id ] );

	$detalle_envio = 'Partida extra "' . $trabajo['descripcion'] . '" enviada al cliente (' . $cliente_email . ') para validar.';
	if ( $whatsapp_form !== '' ) {
		$whatsapp_template = trim( (string) get_option( 'crm_whatsapp_template_validar_extra', '' ) );
		if ( function_exists( 'crm_whatsapp_configurado' ) && crm_whatsapp_configurado() && $whatsapp_template !== '' ) {
			$whatsapp_resultado = crm_whatsapp_enviar_plantilla(
				$whatsapp_form,
				$whatsapp_template,
				[ $trabajo['cliente_nombre'] ?: 'cliente', $trabajo['descripcion'], number_format_i18n( (float) $trabajo['precio_unitario'], 2 ), $validar_url ]
			);
			$detalle_envio .= is_wp_error( $whatsapp_resultado )
				? ' WhatsApp a ' . $whatsapp_form . ': falló (' . $whatsapp_resultado->get_error_message() . ').'
				: ' WhatsApp enviado también a ' . $whatsapp_form . '.';
		} else {
			$detalle_envio .= ' WhatsApp de contacto anotado: ' . $whatsapp_form . ' (canal todavía no configurado).';
		}
	}
	crm_inst_log_action( (int) $trabajo['instalacion_id'], 'trabajo', 'extra_enviado_cliente', $detalle_envio );

	wp_send_json_success( [ 'enviado_a' => $cliente_email ] );
}

/**
 * Página pública (sin login) a la que llega el CLIENTE desde el email para
 * aprobar o rechazar una partida extra concreta — mismo patrón que
 * crm_inst_shortcode_confirmar_pedido(). Gated por token de un solo uso.
 */
add_shortcode( 'crm_inst_validar_extra_cliente', 'crm_inst_shortcode_validar_extra_cliente' );
function crm_inst_shortcode_validar_extra_cliente() {
	if ( function_exists( 'crm_app_shell_chromeless_css' ) ) {
		echo '<style>' . crm_app_shell_chromeless_css( false ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	global $wpdb;
	$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
	if ( $token === '' ) {
		return '<p>Enlace no válido.</p>';
	}

	$trabajo = $wpdb->get_row( $wpdb->prepare(
		"SELECT t.*, c.cliente_nombre
		 FROM " . crm_inst_table_trabajos() . " t
		 INNER JOIN " . crm_inst_table_instalaciones() . " i ON i.id = t.instalacion_id
		 LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
		 WHERE t.cliente_token = %s AND t.origen = 'extra'",
		$token
	), ARRAY_A );
	if ( ! $trabajo ) {
		return '<p>Enlace no válido o caducado.</p>';
	}

	if ( in_array( $trabajo['estado'], [ 'aprobado', 'rechazado' ], true ) ) {
		return '<p>Ya respondiste a esta solicitud (' . esc_html( $trabajo['estado'] ) . ') el ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $trabajo['validado_en'] ) ) ) . '. Gracias.</p>';
	}

	$nonce = wp_create_nonce( 'crm_inst_validar_extra_' . $token );

	ob_start();
	?>
	<div id="crm-validar-extra-wrap" style="max-width:480px; margin:40px auto; padding:24px; font-family:system-ui,-apple-system,sans-serif; background:#fff; border:1px solid #e5e7eb; border-radius:10px; color:#1f2937;">
		<h2 style="margin-top:0; font-size:18px;">Trabajo adicional — <?php echo esc_html( $trabajo['cliente_nombre'] ?: 'tu instalación' ); ?></h2>
		<p><strong><?php echo esc_html( $trabajo['descripcion'] ); ?></strong></p>
		<?php if ( ! empty( $trabajo['materiales_usados'] ) ) : ?>
			<p>Materiales: <?php echo esc_html( $trabajo['materiales_usados'] ); ?></p>
		<?php endif; ?>
		<?php if ( $trabajo['horas'] !== null ) : ?>
			<p>Horas: <?php echo esc_html( number_format_i18n( (float) $trabajo['horas'], 2 ) ); ?></p>
		<?php endif; ?>
		<p>Importe: <strong><?php echo esc_html( number_format_i18n( (float) $trabajo['precio_unitario'], 2 ) ); ?> €</strong></p>
		<?php if ( ! empty( $trabajo['justificacion_ruta'] ) ) : ?>
			<p><img src="<?php echo esc_url( $trabajo['justificacion_ruta'] ); ?>" alt="Foto de justificación" style="max-width:100%;border-radius:8px;"></p>
		<?php endif; ?>
		<label style="display:block; font-size:13px; font-weight:600; margin:16px 0 6px;">Notas (opcional)</label>
		<textarea id="crm-validar-extra-notas" rows="3" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-family:inherit;"></textarea>
		<div style="display:flex; gap:10px; margin-top:14px;">
			<button type="button" id="crm-validar-extra-aprobar" style="flex:1; padding:12px; background:#15803d; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer;">Aprobar</button>
			<button type="button" id="crm-validar-extra-rechazar" style="flex:1; padding:12px; background:#fff; color:#991b1b; border:1px solid #991b1b; border-radius:6px; font-weight:600; cursor:pointer;">Rechazar</button>
		</div>
		<p id="crm-validar-extra-msg" style="margin-top:10px; font-size:13px;"></p>
	</div>
	<script>
	(function () {
		var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var token   = <?php echo wp_json_encode( $token ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		function responder(decision) {
			var wrap = document.getElementById('crm-validar-extra-wrap');
			var msg  = document.getElementById('crm-validar-extra-msg');
			document.getElementById('crm-validar-extra-aprobar').disabled = true;
			document.getElementById('crm-validar-extra-rechazar').disabled = true;
			msg.style.color = '#6b7280';
			msg.textContent = 'Enviando...';
			var body = new URLSearchParams();
			body.set('action', 'crm_inst_validar_extra_cliente');
			body.set('token', token);
			body.set('nonce', nonce);
			body.set('decision', decision);
			body.set('notas', document.getElementById('crm-validar-extra-notas').value);
			fetch(ajaxurl, { method: 'POST', body: body })
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (!resp.success) {
						document.getElementById('crm-validar-extra-aprobar').disabled = false;
						document.getElementById('crm-validar-extra-rechazar').disabled = false;
						msg.style.color = '#991b1b';
						msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
						return;
					}
					wrap.innerHTML = '<p style="color:#065f46;">¡Gracias! Tu respuesta ha quedado registrada.</p>';
				})
				.catch(function () {
					document.getElementById('crm-validar-extra-aprobar').disabled = false;
					document.getElementById('crm-validar-extra-rechazar').disabled = false;
					msg.style.color = '#991b1b';
					msg.textContent = 'Error de conexión.';
				});
		}
		document.getElementById('crm-validar-extra-aprobar').addEventListener('click', function () { responder('aprobar'); });
		document.getElementById('crm-validar-extra-rechazar').addEventListener('click', function () { responder('rechazar'); });
	})();
	</script>
	<?php
	return ob_get_clean();
}

add_action( 'wp_ajax_nopriv_crm_inst_validar_extra_cliente', 'crm_inst_ajax_validar_extra_cliente' );
add_action( 'wp_ajax_crm_inst_validar_extra_cliente', 'crm_inst_ajax_validar_extra_cliente' );
function crm_inst_ajax_validar_extra_cliente() {
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	if ( $token === '' || ! check_ajax_referer( 'crm_inst_validar_extra_' . $token, 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Enlace no válido.' ], 403 );
	}

	$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
	if ( ! in_array( $decision, [ 'aprobar', 'rechazar' ], true ) ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	global $wpdb;
	$trabajo = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, instalacion_id, descripcion, estado, declarado_por FROM " . crm_inst_table_trabajos() . " WHERE cliente_token = %s AND origen = 'extra'",
		$token
	), ARRAY_A );
	if ( ! $trabajo ) {
		wp_send_json_error( [ 'message' => 'Enlace no válido.' ] );
	}
	if ( in_array( $trabajo['estado'], [ 'aprobado', 'rechazado' ], true ) ) {
		wp_send_json_error( [ 'message' => 'Ya se respondió a esta solicitud.' ] );
	}

	$notas = sanitize_textarea_field( wp_unslash( $_POST['notas'] ?? '' ) );
	$nuevo_estado = $decision === 'aprobar' ? 'aprobado' : 'rechazado';

	// validado_por se deja en NULL a propósito: así se distingue en la ficha
	// una validación real del cliente de un respaldo manual del jefe (ver
	// crm_inst_ajax_validar_extra(), que sí lo rellena).
	$update = [
		'estado'      => $nuevo_estado,
		'validado_en' => current_time( 'mysql' ),
	];
	if ( $notas !== '' ) {
		$update['observaciones'] = $notas;
	}
	$wpdb->update( crm_inst_table_trabajos(), $update, [ 'id' => $trabajo['id'] ] );

	crm_inst_log_action( (int) $trabajo['instalacion_id'], 'trabajo', 'extra_' . $nuevo_estado, 'Partida extra "' . $trabajo['descripcion'] . '" ' . $nuevo_estado . ' por el cliente.' . ( $notas !== '' ? ' Notas: "' . $notas . '"' : '' ) );

	if ( function_exists( 'crm_notificar_jefes_instalaciones' ) ) {
		crm_notificar_jefes_instalaciones(
			'extra_' . $nuevo_estado,
			'El cliente ' . $nuevo_estado . ' la partida extra "' . $trabajo['descripcion'] . '".',
			add_query_arg( 'id', $trabajo['instalacion_id'], home_url( '/instalacion/' ) )
		);
	}
	if ( function_exists( 'crm_notificar' ) && ! empty( $trabajo['declarado_por'] ) ) {
		crm_notificar(
			(int) $trabajo['declarado_por'],
			'extra_' . $nuevo_estado,
			'El cliente ' . $nuevo_estado . ' tu partida extra "' . $trabajo['descripcion'] . '".',
			home_url( '/panel-instalador/' )
		);
	}

	wp_send_json_success();
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 4 Entrega 2 — Checklist previo del instalador (materiales + seguridad)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * El instalador confirma, antes de empezar el trabajo, que tiene en su poder
 * todos los materiales de esta instalación y que ha leído y acepta el plan
 * de seguridad y prevención (ver `crm_inst_shortcode_plan_seguridad()`).
 * Paso obligatorio: `crm_inst_ajax_cerrar_instalacion()` no deja declarar el
 * cierre si esto no está confirmado — así no se puede saltar el flujo.
 *
 * v1.20.60: este es el momento en que el material sale de verdad del almacén
 * hacia la obra, así que es aquí (no al marcar "Recibido" en oficina, que
 * significa "ha llegado del proveedor", no "ha salido hacia la obra") donde
 * se descuenta el stock real en Holded — solo para las líneas con
 * `holded_product_id` confirmado, nunca a partir de un match por nombre.
 */
add_action( 'wp_ajax_crm_inst_confirmar_checklist', 'crm_inst_ajax_confirmar_checklist' );
function crm_inst_ajax_confirmar_checklist() {
	if ( ! is_user_logged_in() || ! current_user_can( 'crm_inst_edit_own' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$user_id        = get_current_user_id();
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$materiales_ok  = ! empty( $_POST['materiales_ok'] );
	$seguridad_ok   = ! empty( $_POST['seguridad_ok'] );

	if ( $instalacion_id <= 0 || ! $materiales_ok || ! $seguridad_ok ) {
		wp_send_json_error( [ 'message' => 'Confirma que tienes todos los materiales y que has leído el plan de seguridad.' ] );
	}

	if ( ! crm_inst_current_user_can_manage() ) {
		$asignado = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
			$instalacion_id, $user_id
		) );
		if ( $asignado === 0 ) {
			wp_send_json_error( [ 'message' => 'No estás asignado a esta instalación.' ], 403 );
		}
	}

	// v1.20.73: gate autoritativo — no basta con que el instalador marque la
	// casilla "tengo los materiales", si en oficina todavía queda alguna
	// línea de Holded sin marcar "Recibido" no se deja confirmar. La UI del
	// panel ya deshabilita el botón en este caso; esto es el respaldo en
	// servidor para que no se pueda saltar manipulando la petición.
	$materiales_pendientes = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . crm_inst_table_trabajos() . " WHERE instalacion_id = %d AND origen = 'holded' AND estado != 'recibido'",
		$instalacion_id
	) );
	if ( $materiales_pendientes > 0 ) {
		wp_send_json_error( [ 'message' => 'Todavía hay ' . $materiales_pendientes . ' material(es) sin recibir en almacén. No puedes confirmar hasta que estén todos.' ] );
	}

	$ya_confirmado = $wpdb->get_var( $wpdb->prepare(
		"SELECT checklist_confirmado_en FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		$instalacion_id
	) );
	if ( ! empty( $ya_confirmado ) ) {
		wp_send_json_error( [ 'message' => 'Ya habías confirmado esto antes.' ] );
	}

	// v1.20.88: en vez de un ajuste directo de stock (nunca probado, y según
	// la gestoría de Ecovolt no es el mecanismo correcto), se genera un
	// albarán de venta real en Holded (documento "waybill") contra el
	// cliente final — es lo que de verdad descuenta stock en su
	// contabilidad, y queda como comprobante de la entrega. Solo se incluyen
	// líneas enganchadas de verdad a un producto real (holded_product_id
	// confirmado, nunca un match por nombre). Best-effort: un fallo aquí no
	// debe bloquear la confirmación del checklist en sí.
	$materiales = $wpdb->get_results( $wpdb->prepare(
		"SELECT descripcion, unidades, holded_product_id FROM " . crm_inst_table_trabajos() . "
		 WHERE instalacion_id = %d AND origen = 'holded' AND holded_product_id IS NOT NULL AND holded_product_id != '' AND unidades > 0",
		$instalacion_id
	), ARRAY_A );

	// El almacén se pide siempre que haya algo que enviar, pero un fallo al
	// elegirlo (p.ej. si el listado de almacenes de Holded no cargó al abrir
	// el panel) nunca debe bloquear la confirmación del checklist en sí —
	// solo se salta la generación del albarán, igual que si faltase el
	// contacto del cliente.
	$warehouse_id = sanitize_text_field( wp_unslash( $_POST['warehouse_id'] ?? '' ) );

	$waybill_update = [];
	if ( ! empty( $materiales ) && $warehouse_id === '' ) {
		$waybill_update = [ 'holded_waybill_error' => 'No se seleccionó almacén — no se pudo generar el albarán de salida.' ];
		crm_inst_log_action( $instalacion_id, 'instalacion', 'albaran_holded_error', $waybill_update['holded_waybill_error'] );
	} elseif ( ! empty( $materiales ) && function_exists( 'crm_holded_crear_albaran_salida' ) ) {
		$contacto = $wpdb->get_row( $wpdb->prepare(
			"SELECT i.holded_project_id, c.holded_contact_id
			 FROM " . crm_inst_table_instalaciones() . " i
			 LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
			 WHERE i.id = %d",
			$instalacion_id
		), ARRAY_A );

		if ( empty( $contacto['holded_contact_id'] ) ) {
			$waybill_update = [ 'holded_waybill_error' => 'Falta el contacto de Holded del cliente — no se pudo generar el albarán de salida.' ];
			crm_inst_log_action( $instalacion_id, 'instalacion', 'albaran_holded_error', $waybill_update['holded_waybill_error'] );
		} else {
			$items = array_map( function ( $m ) {
				return [ 'name' => $m['descripcion'], 'product_id' => $m['holded_product_id'], 'units' => (float) $m['unidades'] ];
			}, $materiales );

			$resultado = crm_holded_crear_albaran_salida(
				$contacto['holded_contact_id'],
				$warehouse_id,
				$items,
				$contacto['holded_project_id'] ?: '',
				'Materiales retirados para la instalación #' . $instalacion_id . ' — recogidos por el instalador.'
			);

			if ( is_wp_error( $resultado ) ) {
				$waybill_update = [ 'holded_waybill_error' => $resultado->get_error_message() ];
				crm_inst_log_action( $instalacion_id, 'instalacion', 'albaran_holded_error', 'No se pudo generar el albarán de salida: ' . $resultado->get_error_message() );
			} else {
				$waybill_update = [
					'holded_waybill_id'       => $resultado['id'],
					'holded_waybill_numero'   => $resultado['document_number'],
					'holded_waybill_aprobado' => $resultado['aprobado'] ? 1 : 0,
					'holded_waybill_error'    => $resultado['aprobado'] ? null : $resultado['error_aprobacion'],
				];
				$detalle = 'Albarán de salida #' . $resultado['document_number'] . ' generado (' . count( $items ) . ' línea(s))' . ( $resultado['aprobado'] ? ', aprobado.' : ( ', SIN aprobar: ' . $resultado['error_aprobacion'] ) );
				crm_inst_log_action( $instalacion_id, 'instalacion', 'albaran_holded_generado', $detalle );
			}
		}
	}

	$wpdb->update( crm_inst_table_instalaciones(), array_merge( [
		'checklist_confirmado_por' => $user_id,
		'checklist_confirmado_en'  => current_time( 'mysql' ),
	], $waybill_update ), [ 'id' => $instalacion_id ] );

	crm_inst_log_action( $instalacion_id, 'instalacion', 'checklist_confirmado', 'Instalador confirmó materiales recibidos y plan de seguridad aceptado.' );

	if ( function_exists( 'crm_notificar_jefes_instalaciones' ) ) {
		crm_notificar_jefes_instalaciones(
			'checklist_confirmado',
			'El instalador confirmó materiales y seguridad — instalación #' . $instalacion_id . '.',
			add_query_arg( 'id', $instalacion_id, home_url( '/instalacion/' ) )
		);
	}

	wp_send_json_success( [ 'albaran_generado' => ! empty( $waybill_update['holded_waybill_id'] ) ] );
}

/**
 * Respaldo manual: reintenta aprobar un albarán de salida que se creó pero
 * no llegó a aprobarse (p.ej. permiso `inventory:shipments.write` denegado
 * en el primer intento). No vuelve a crear el documento, solo repite el paso
 * de aprobación sobre el mismo `holded_waybill_id` ya guardado.
 */
add_action( 'wp_ajax_crm_inst_aprobar_albaran_holded', 'crm_inst_ajax_aprobar_albaran_holded' );
function crm_inst_ajax_aprobar_albaran_holded() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$waybill_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT holded_waybill_id FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		$instalacion_id
	) );
	if ( empty( $waybill_id ) ) {
		wp_send_json_error( [ 'message' => 'Esta instalación todavía no tiene ningún albarán de salida generado.' ] );
	}

	$resultado = crm_holded_aprobar_albaran_salida( $waybill_id );
	if ( is_wp_error( $resultado ) ) {
		$wpdb->update( crm_inst_table_instalaciones(), [ 'holded_waybill_error' => $resultado->get_error_message() ], [ 'id' => $instalacion_id ] );
		wp_send_json_error( [ 'message' => 'No se pudo aprobar el albarán: ' . $resultado->get_error_message() ] );
	}

	$wpdb->update( crm_inst_table_instalaciones(), [ 'holded_waybill_aprobado' => 1, 'holded_waybill_error' => null ], [ 'id' => $instalacion_id ] );
	crm_inst_log_action( $instalacion_id, 'instalacion', 'albaran_holded_generado', 'Albarán de salida aprobado (reintento manual).' );

	wp_send_json_success();
}

/**
 * Página pública con el plan de seguridad y prevención — se abre desde el
 * panel del instalador con target="_blank". Contenido de demo hasta que se
 * suba el documento real de Ecovolt.
 */
add_shortcode( 'crm_inst_plan_seguridad', 'crm_inst_shortcode_plan_seguridad' );
function crm_inst_shortcode_plan_seguridad() {
	ob_start();
	// v1.20.66: documento independiente abierto en pestaña nueva — sin menú
	// del CRM ni del tema, esté quien esté viéndolo logueado o no.
	if ( function_exists( 'crm_app_shell_chromeless_css' ) ) {
		echo '<style>' . crm_app_shell_chromeless_css( false ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>
	<div style="max-width:640px; margin:32px auto; padding:24px; font-family:system-ui,-apple-system,sans-serif; background:#fff; border:1px solid #e5e7eb; border-radius:10px; color:#1f2937; line-height:1.6;">
		<h1 style="font-size:20px; margin-top:0;">Plan de Seguridad y Prevención — Ecovolt Renovables</h1>
		<p style="color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:10px 12px; font-size:13px;">Contenido de demostración — pendiente de sustituir por el documento real de PRL de Ecovolt.</p>
		<h3>Equipos de protección individual (EPI)</h3>
		<p>Uso obligatorio de guantes, calzado de seguridad y gafas de protección durante la manipulación de materiales e instalación.</p>
		<h3>Trabajos en altura</h3>
		<p>Uso de línea de vida y arnés homologado en cubiertas. Comprobar el estado de andamios y escaleras antes de subir.</p>
		<h3>Riesgo eléctrico</h3>
		<p>Verificar ausencia de tensión antes de manipular cualquier conexión. No trabajar en condiciones de lluvia o humedad sobre instalaciones eléctricas.</p>
		<h3>En caso de emergencia</h3>
		<p>Contactar con el responsable de obra y, si procede, con el 112.</p>
	</div>
	<?php
	return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 4 Entrega 2 — Cierre de instalación por el instalador
// ──────────────────────────────────────────────────────────────────────────────

/**
 * El instalador declara el cierre de una instalación: conformidad
 * (obligatoria), observaciones (opcional) y fotos (opcional, hasta 6). Si
 * `crm_inst_cierre_requiere_aprobacion()` está activo queda `declarado`
 * pendiente de que el jefe lo valide; si no, es definitivo y la instalación
 * pasa a `finalizada` al momento.
 *
 * Las fotos reutilizan `crm_handle_secure_upload()` (includes/uploads-handler.php)
 * — el mismo pipeline seguro ya usado para facturas/contratos, que valida
 * tipo/tamaño real y acepta JPEG/PNG/WebP/HEIC (cámara de móvil) — y se
 * guardan en `crm_instalacion_documentos` (tipo='foto'), tabla de la Fase 1
 * pensada para esto y sin usar hasta ahora.
 */
add_action( 'wp_ajax_crm_inst_cerrar_instalacion', 'crm_inst_ajax_cerrar_instalacion' );
function crm_inst_ajax_cerrar_instalacion() {
	if ( ! is_user_logged_in() || ! current_user_can( 'crm_inst_edit_own' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$user_id        = get_current_user_id();
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$conformidad    = ! empty( $_POST['conformidad'] );
	$observaciones  = sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ?? '' ) );

	if ( $instalacion_id <= 0 || ! $conformidad ) {
		wp_send_json_error( [ 'message' => 'Confirma que la instalación se realizó conforme a lo acordado.' ] );
	}

	// v1.20.60: no se puede cerrar sin haber pasado antes por el checklist de
	// materiales + plan de seguridad — evita que un instalador se salte el
	// flujo paso a paso. Los roles elevados (jefe/admin) sí pueden saltarlo,
	// para poder gestionar casos excepcionales sin quedar bloqueados.
	if ( ! crm_inst_current_user_can_manage() ) {
		$checklist_en = $wpdb->get_var( $wpdb->prepare(
			"SELECT checklist_confirmado_en FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
			$instalacion_id
		) );
		if ( empty( $checklist_en ) ) {
			wp_send_json_error( [ 'message' => 'Antes de cerrar, confirma que tienes los materiales y el plan de seguridad desde la tarjeta de esta instalación.' ] );
		}
	}

	if ( ! crm_inst_current_user_can_manage() ) {
		$asignado = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
			$instalacion_id, $user_id
		) );
		if ( $asignado === 0 ) {
			wp_send_json_error( [ 'message' => 'No estás asignado a esta instalación.' ], 403 );
		}
	}

	// v1.20.74: fotos organizadas por categoría según el subtipo (fotovoltaica/
	// aerotermia) — ver crm_inst_categorias_fotos_cierre(). Si el subtipo no
	// tiene categorías definidas (p.ej. comercial, sin subtipo hoy), se
	// mantiene el modo libre de "hasta 6 fotos sin categorizar" de siempre.
	$subtipo_instalacion = $wpdb->get_var( $wpdb->prepare(
		"SELECT subtipo_instalacion FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		$instalacion_id
	) );
	$categorias_fotos = crm_inst_categorias_fotos_cierre( $subtipo_instalacion );

	if ( ! empty( $categorias_fotos ) ) {
		foreach ( $categorias_fotos as $cat_key => $cat_label ) {
			if ( empty( $_FILES[ 'foto_' . $cat_key ]['name'] ) ) {
				wp_send_json_error( [ 'message' => 'Falta la foto: ' . $cat_label . '.' ] );
			}
		}
	}

	$fotos_subidas = 0;
	if ( function_exists( 'crm_handle_secure_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! empty( $categorias_fotos ) ) {
			foreach ( $categorias_fotos as $cat_key => $cat_label ) {
				$subida = crm_handle_secure_upload( $_FILES[ 'foto_' . $cat_key ], 'foto_cierre' );
				if ( is_wp_error( $subida ) ) {
					wp_send_json_error( [ 'message' => 'No se pudo subir la foto "' . $cat_label . '": ' . $subida->get_error_message() ] );
				}
				$wpdb->insert( crm_inst_table_documentos(), [
					'instalacion_id' => $instalacion_id,
					'tipo'           => 'foto',
					'categoria'      => $cat_key,
					'ruta'           => $subida['url'],
					'subido_por'     => $user_id,
					'subido_en'      => current_time( 'mysql' ),
				] );
				$fotos_subidas++;
			}
		} elseif ( ! empty( $_FILES['fotos']['name'] ) && is_array( $_FILES['fotos']['name'] ) ) {
			$max_fotos = 6;
			$total     = count( $_FILES['fotos']['name'] );
			for ( $i = 0; $i < $total && $fotos_subidas < $max_fotos; $i++ ) {
				if ( ( $_FILES['fotos']['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
					continue;
				}
				$file = [
					'name'     => $_FILES['fotos']['name'][ $i ],
					'type'     => $_FILES['fotos']['type'][ $i ],
					'tmp_name' => $_FILES['fotos']['tmp_name'][ $i ],
					'error'    => $_FILES['fotos']['error'][ $i ],
					'size'     => $_FILES['fotos']['size'][ $i ],
				];
				$subida = crm_handle_secure_upload( $file, 'foto_cierre' );
				if ( is_wp_error( $subida ) ) {
					continue; // Una foto fallida no debe tumbar el cierre entero.
				}
				$wpdb->insert( crm_inst_table_documentos(), [
					'instalacion_id' => $instalacion_id,
					'tipo'           => 'foto',
					'ruta'           => $subida['url'],
					'subido_por'     => $user_id,
					'subido_en'      => current_time( 'mysql' ),
				] );
				$fotos_subidas++;
			}
		}
	}

	$requiere_aprobacion = crm_inst_cierre_requiere_aprobacion();
	$update = [
		'cierre_estado'        => $requiere_aprobacion ? 'declarado' : 'aprobado',
		'cierre_conformidad'   => 1,
		'cierre_observaciones' => $observaciones !== '' ? $observaciones : null,
		'cierre_declarado_por' => $user_id,
		'cierre_declarado_en'  => current_time( 'mysql' ),
	];
	if ( ! $requiere_aprobacion ) {
		$update['estado']       = 'finalizada';
		$update['fecha_cierre'] = current_time( 'mysql' );
	}
	$wpdb->update( crm_inst_table_instalaciones(), $update, [ 'id' => $instalacion_id ] );

	crm_inst_log_action(
		$instalacion_id,
		'instalacion',
		'cierre_declarado',
		'Cierre declarado por el instalador' . ( $fotos_subidas > 0 ? ' con ' . $fotos_subidas . ' foto(s)' : '' )
			. ( $requiere_aprobacion ? ' (pendiente de validar).' : ' — instalación finalizada.' )
	);

	if ( function_exists( 'crm_notificar_jefes_instalaciones' ) ) {
		$cliente_nombre = $wpdb->get_var( $wpdb->prepare(
			"SELECT c.cliente_nombre FROM " . crm_inst_table_instalaciones() . " i LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id WHERE i.id = %d",
			$instalacion_id
		) );
		$mensaje = $requiere_aprobacion
			? 'Cierre pendiente de validar: ' . ( $cliente_nombre ?: ( '#' . $instalacion_id ) )
			: 'Instalación finalizada: ' . ( $cliente_nombre ?: ( '#' . $instalacion_id ) );
		crm_notificar_jefes_instalaciones( 'cierre_declarado', $mensaje, add_query_arg( 'id', $instalacion_id, home_url( '/instalacion/' ) ) );
	}

	wp_send_json_success( [ 'estado' => $requiere_aprobacion ? 'declarado' : 'aprobado' ] );
}

/**
 * El jefe de instalaciones aprueba o rechaza el cierre declarado por un
 * instalador (solo aplica cuando `crm_inst_cierre_requiere_aprobacion()`
 * está activo). Aprobar pasa la instalación a `finalizada`.
 */
add_action( 'wp_ajax_crm_inst_validar_cierre', 'crm_inst_ajax_validar_cierre' );
function crm_inst_ajax_validar_cierre() {
	if ( ! current_user_can( 'crm_inst_close' ) || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$decision       = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );

	if ( $instalacion_id <= 0 || ! in_array( $decision, [ 'aprobar', 'rechazar' ], true ) ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$inst = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, cierre_declarado_por FROM " . crm_inst_table_instalaciones() . " WHERE id = %d AND cierre_estado = 'declarado'",
		$instalacion_id
	), ARRAY_A );
	if ( ! $inst ) {
		wp_send_json_error( [ 'message' => 'No hay ningún cierre pendiente de validar en esta instalación.' ] );
	}

	$nuevo_estado = $decision === 'aprobar' ? 'aprobado' : 'rechazado';
	$update = [
		'cierre_estado'       => $nuevo_estado,
		'cierre_validado_por' => get_current_user_id(),
		'cierre_validado_en'  => current_time( 'mysql' ),
	];
	if ( $nuevo_estado === 'aprobado' ) {
		$update['estado']       = 'finalizada';
		$update['fecha_cierre'] = current_time( 'mysql' );
	}
	$wpdb->update( crm_inst_table_instalaciones(), $update, [ 'id' => $instalacion_id ] );

	crm_inst_log_action( $instalacion_id, 'instalacion', 'cierre_' . $nuevo_estado, 'Cierre ' . $nuevo_estado . ' por el jefe.' );

	if ( function_exists( 'crm_notificar' ) && ! empty( $inst['cierre_declarado_por'] ) ) {
		crm_notificar(
			(int) $inst['cierre_declarado_por'],
			'cierre_' . $nuevo_estado,
			'Tu cierre de instalación ha sido ' . $nuevo_estado . '.',
			home_url( '/panel-instalador/' )
		);
	}

	wp_send_json_success( [ 'estado' => $nuevo_estado ] );
}

/**
 * Envía al proveedor configurado en Ajustes la lista de materiales todavía
 * pendientes de recibir para esta instalación.
 */
add_action( 'wp_ajax_crm_inst_notificar_proveedor', 'crm_inst_ajax_notificar_proveedor' );
function crm_inst_ajax_notificar_proveedor() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$proveedor_email = sanitize_email( (string) get_option( 'crm_proveedor_email', '' ) );
	if ( $proveedor_email === '' ) {
		wp_send_json_error( [ 'message' => 'No hay email de proveedor configurado en Ajustes.' ] );
	}

	$data = crm_inst_get_instalacion_data( $instalacion_id );
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( [ 'message' => $data->get_error_message() ] );
	}

	$pendientes = array_filter( $data['materiales'], function ( $m ) {
		return $m['origen'] === 'holded' && $m['estado'] !== 'recibido';
	} );
	if ( empty( $pendientes ) ) {
		wp_send_json_error( [ 'message' => 'No queda ningún material pendiente para esta instalación.' ] );
	}

	// v1.20.57: token de un solo uso para que el proveedor confirme el pedido
	// con un clic, sin necesitar cuenta — más fiable que fiarse de si abrió o
	// no el email (los píxeles de lectura los bloquean/falsean Gmail/Outlook).
	$token = wp_generate_password( 32, false );
	$confirmar_url = add_query_arg( 'token', $token, home_url( '/confirmar-pedido/' ) );

	$subject = sprintf( 'Nuevo pedido para Ecovolt Renovables S.L. — %s', $data['cliente_nombre'] );

	$body  = '<p>Instalación #' . (int) $data['id'] . ' — ' . esc_html( $data['cliente_nombre'] ) . '</p>';
	$body .= '<p>' . esc_html( $data['direccion_instalacion'] ) . '</p>';
	$body .= '<p>Materiales pendientes de recibir:</p><ul>';
	foreach ( $pendientes as $m ) {
		$body .= '<li>' . esc_html( $m['unidades'] ) . ' × ' . esc_html( $m['descripcion'] ) . '</li>';
	}
	$body .= '</ul>';
	$body .= '<p><a href="' . esc_url( $confirmar_url ) . '">Confirmar disponibilidad de este pedido</a></p>';
	$body .= '<p style="color:#666;font-size:12px">Mensaje automático del CRM. No responder a este correo — usa el enlace de arriba para confirmar.</p>';

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	$sent    = wp_mail( $proveedor_email, $subject, $body, $headers );

	if ( ! $sent ) {
		wp_send_json_error( [ 'message' => 'No se pudo enviar el email (revisa la configuración de envío del servidor).' ] );
	}

	global $wpdb;
	$wpdb->update( crm_inst_table_instalaciones(), [
		'proveedor_notificado_en' => current_time( 'mysql' ),
		'proveedor_pedido_token'  => $token,
		'proveedor_pedido_estado' => 'enviado',
		'proveedor_confirmado_en' => null,
		'proveedor_notas'         => null,
	], [ 'id' => $instalacion_id ] );
	crm_inst_log_action( $instalacion_id, 'instalacion', 'notificar_proveedor', 'Aviso enviado a ' . $proveedor_email . ' (' . count( $pendientes ) . ' materiales pendientes).' );

	wp_send_json_success( [ 'enviado_a' => $proveedor_email, 'pendientes' => count( $pendientes ) ] );
}

/**
 * El jefe marca a mano que el proveedor confirmó el pedido (p.ej. llamó por
 * teléfono) — respaldo del enlace de confirmación de un clic, para cuando
 * la respuesta no llega por ese canal.
 */
add_action( 'wp_ajax_crm_inst_marcar_proveedor_confirmado', 'crm_inst_ajax_marcar_proveedor_confirmado' );
function crm_inst_ajax_marcar_proveedor_confirmado() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	if ( $instalacion_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$wpdb->update( crm_inst_table_instalaciones(), [
		'proveedor_pedido_estado' => 'confirmado',
		'proveedor_confirmado_en' => current_time( 'mysql' ),
		'proveedor_notas'         => 'Confirmado a mano por ' . wp_get_current_user()->display_name . '.',
	], [ 'id' => $instalacion_id ] );
	crm_inst_log_action( $instalacion_id, 'instalacion', 'proveedor_confirmado_manual', 'Marcado como confirmado a mano por el jefe.' );

	wp_send_json_success();
}

/**
 * Página pública (sin login) a la que llega el proveedor desde el enlace del
 * email para confirmar el pedido con un clic. Gated por el token de un solo
 * uso, no por sesión — ver `restrict_access_for_guests()` en acceso.php,
 * donde esta página está exenta del redirect a login.
 */
add_shortcode( 'crm_inst_confirmar_pedido', 'crm_inst_shortcode_confirmar_pedido' );
function crm_inst_shortcode_confirmar_pedido() {
	// v1.20.66: página pública para un tercero SIN sesión (el proveedor) — no
	// debe verse ningún menú del CRM ni del tema, ni logueado ni sin loguear.
	// Se aplica aquí (echo directo, no ob_start) para que cubra también las
	// salidas tempranas (enlace no válido, ya confirmado), no solo el
	// formulario. Ver acceso.php: esta página también está exenta del
	// redirect a login.
	if ( function_exists( 'crm_app_shell_chromeless_css' ) ) {
		echo '<style>' . crm_app_shell_chromeless_css( false ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	global $wpdb;
	$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
	if ( $token === '' ) {
		return '<p>Enlace no válido.</p>';
	}

	$inst = $wpdb->get_row( $wpdb->prepare(
		"SELECT i.id, i.proveedor_pedido_estado, i.proveedor_confirmado_en, c.cliente_nombre
		 FROM " . crm_inst_table_instalaciones() . " i
		 LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
		 WHERE i.proveedor_pedido_token = %s",
		$token
	), ARRAY_A );
	if ( ! $inst ) {
		return '<p>Enlace no válido o caducado.</p>';
	}

	if ( $inst['proveedor_pedido_estado'] === 'confirmado' ) {
		return '<p>Este pedido ya se confirmó el ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $inst['proveedor_confirmado_en'] ) ) ) . '. Gracias.</p>';
	}

	$materiales = $wpdb->get_results( $wpdb->prepare(
		"SELECT descripcion, unidades FROM " . crm_inst_table_trabajos() . " WHERE instalacion_id = %d AND origen = 'holded' AND estado != 'recibido' ORDER BY id ASC",
		(int) $inst['id']
	), ARRAY_A );

	$nonce = wp_create_nonce( 'crm_inst_confirmar_' . $token );

	ob_start();
	?>
	<div id="crm-confirmar-wrap" style="max-width:480px; margin:40px auto; padding:24px; font-family:system-ui,-apple-system,sans-serif; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
		<h2 style="margin-top:0; font-size:18px; color:#1f2937;">Confirmar pedido — <?php echo esc_html( $inst['cliente_nombre'] ?: ( 'instalación #' . $inst['id'] ) ); ?></h2>
		<?php if ( empty( $materiales ) ) : ?>
			<p>No hay materiales pendientes registrados para este pedido.</p>
		<?php else : ?>
			<p>Materiales pendientes:</p>
			<ul>
				<?php foreach ( $materiales as $m ) : ?>
					<li><?php echo esc_html( $m['unidades'] ); ?> × <?php echo esc_html( $m['descripcion'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<label style="display:block; font-size:13px; font-weight:600; margin:16px 0 6px;">Fecha de entrega estimada</label>
		<input type="date" id="crm-confirmar-fecha" style="padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-family:inherit;">
		<label style="display:block; font-size:13px; font-weight:600; margin:16px 0 6px;">Notas (disponibilidad, condiciones...)</label>
		<textarea id="crm-confirmar-notas" rows="3" style="width:100%; box-sizing:border-box; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-family:inherit;" placeholder="Opcional"></textarea>
		<button type="button" id="crm-confirmar-btn" style="margin-top:12px; padding:10px 18px; background:#15803d; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer;">Confirmar disponibilidad</button>
		<p id="crm-confirmar-msg" style="margin-top:10px; font-size:13px;"></p>
	</div>
	<script>
	(function () {
		var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var token   = <?php echo wp_json_encode( $token ); ?>;
		var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		document.getElementById('crm-confirmar-btn').addEventListener('click', function () {
			var btn = this;
			var msg = document.getElementById('crm-confirmar-msg');
			btn.disabled = true;
			msg.style.color = '#6b7280';
			msg.textContent = 'Enviando...';
			var body = new URLSearchParams();
			body.set('action', 'crm_inst_confirmar_pedido');
			body.set('token', token);
			body.set('nonce', nonce);
			body.set('notas', document.getElementById('crm-confirmar-notas').value);
			body.set('fecha_entrega', document.getElementById('crm-confirmar-fecha').value);
			fetch(ajaxurl, { method: 'POST', body: body })
				.then(function (r) { return r.json(); })
				.then(function (resp) {
					if (!resp.success) {
						btn.disabled = false;
						msg.style.color = '#991b1b';
						msg.textContent = (resp.data && resp.data.message) ? resp.data.message : 'Error.';
						return;
					}
					document.getElementById('crm-confirmar-wrap').innerHTML = '<p style="color:#065f46;">¡Gracias! Pedido confirmado.</p>';
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

add_action( 'wp_ajax_nopriv_crm_inst_confirmar_pedido', 'crm_inst_ajax_confirmar_pedido' );
add_action( 'wp_ajax_crm_inst_confirmar_pedido', 'crm_inst_ajax_confirmar_pedido' );
function crm_inst_ajax_confirmar_pedido() {
	$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
	if ( $token === '' || ! check_ajax_referer( 'crm_inst_confirmar_' . $token, 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Enlace no válido.' ], 403 );
	}

	global $wpdb;
	$inst = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, proveedor_pedido_estado FROM " . crm_inst_table_instalaciones() . " WHERE proveedor_pedido_token = %s",
		$token
	), ARRAY_A );
	if ( ! $inst ) {
		wp_send_json_error( [ 'message' => 'Enlace no válido.' ] );
	}
	if ( $inst['proveedor_pedido_estado'] === 'confirmado' ) {
		wp_send_json_error( [ 'message' => 'Este pedido ya fue confirmado.' ] );
	}

	$notas         = sanitize_textarea_field( wp_unslash( $_POST['notas'] ?? '' ) );
	$fecha_entrega = sanitize_text_field( wp_unslash( $_POST['fecha_entrega'] ?? '' ) );
	// Validación estricta: solo aceptar YYYY-MM-DD real (lo que manda un
	// <input type="date">), cualquier otra cosa se descarta sin avisar — no
	// es un dato crítico y no merece bloquear la confirmación por esto.
	if ( $fecha_entrega !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha_entrega ) ) {
		$fecha_entrega = '';
	}

	$wpdb->update( crm_inst_table_instalaciones(), [
		'proveedor_pedido_estado'    => 'confirmado',
		'proveedor_confirmado_en'    => current_time( 'mysql' ),
		'proveedor_notas'            => $notas !== '' ? $notas : null,
		'proveedor_entrega_estimada' => $fecha_entrega !== '' ? $fecha_entrega : null,
	], [ 'id' => $inst['id'] ] );

	$detalle_log = 'El proveedor confirmó el pedido';
	if ( $fecha_entrega !== '' ) {
		$detalle_log .= ' — entrega estimada ' . date_i18n( 'd/m/Y', strtotime( $fecha_entrega ) );
	}
	$detalle_log .= $notas !== '' ? ': "' . $notas . '"' : '.';
	crm_inst_log_action( (int) $inst['id'], 'instalacion', 'proveedor_confirmado', $detalle_log );

	if ( function_exists( 'crm_notificar_jefes_instalaciones' ) ) {
		$mensaje_notif = 'El proveedor confirmó el pedido de materiales';
		if ( $fecha_entrega !== '' ) {
			$mensaje_notif .= ' — entrega estimada ' . date_i18n( 'd/m/Y', strtotime( $fecha_entrega ) );
		}
		$mensaje_notif .= '.';
		crm_notificar_jefes_instalaciones(
			'proveedor_confirmado',
			$mensaje_notif,
			add_query_arg( 'id', $inst['id'], home_url( '/instalacion/' ) )
		);
	}

	wp_send_json_success();
}

/**
 * Asigna un instalador a la instalación (tabla pivote `crm_instalacion_instaladores`
 * de la Fase 1). Valida que el usuario tenga el rol `instalador`.
 */
add_action( 'wp_ajax_crm_inst_asignar_instalador', 'crm_inst_ajax_asignar_instalador' );
function crm_inst_ajax_asignar_instalador() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$user_id        = (int) ( $_POST['user_id'] ?? 0 );

	if ( $instalacion_id <= 0 || $user_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}
	$user = get_userdata( $user_id );
	if ( ! $user || ! in_array( 'instalador', (array) $user->roles, true ) ) {
		wp_send_json_error( [ 'message' => 'Ese usuario no tiene el rol de instalador.' ] );
	}

	$ya_asignado = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
		$instalacion_id,
		$user_id
	) );
	if ( $ya_asignado > 0 ) {
		wp_send_json_error( [ 'message' => 'Ese instalador ya está asignado a esta instalación.' ] );
	}

	$result = $wpdb->insert(
		crm_inst_table_instaladores(),
		[ 'instalacion_id' => $instalacion_id, 'user_id' => $user_id ],
		[ '%d', '%d' ]
	);
	if ( $result === false ) {
		wp_send_json_error( [ 'message' => 'No se pudo asignar: ' . $wpdb->last_error ] );
	}

	crm_inst_log_action( $instalacion_id, 'instalador', 'asignar', 'Instalador asignado: ' . $user->display_name );

	if ( function_exists( 'crm_notificar' ) ) {
		$cliente_nombre = $wpdb->get_var( $wpdb->prepare(
			"SELECT c.cliente_nombre FROM " . crm_inst_table_instalaciones() . " i LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id WHERE i.id = %d",
			$instalacion_id
		) );
		crm_notificar(
			$user_id,
			'instalacion_asignada',
			'Te han asignado la instalación de ' . ( $cliente_nombre ?: ( '#' . $instalacion_id ) ) . '.',
			home_url( '/panel-instalador/' )
		);
	}

	wp_send_json_success( [ 'user_id' => $user_id, 'display_name' => $user->display_name ] );
}

/**
 * Quita un instalador ya asignado.
 */
add_action( 'wp_ajax_crm_inst_quitar_instalador', 'crm_inst_ajax_quitar_instalador' );
function crm_inst_ajax_quitar_instalador() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$user_id        = (int) ( $_POST['user_id'] ?? 0 );
	if ( $instalacion_id <= 0 || $user_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$wpdb->delete( crm_inst_table_instaladores(), [ 'instalacion_id' => $instalacion_id, 'user_id' => $user_id ], [ '%d', '%d' ] );
	crm_inst_log_action( $instalacion_id, 'instalador', 'quitar', 'Instalador #' . $user_id . ' desasignado.' );

	wp_send_json_success( [] );
}

/**
 * Programa (o reprograma) la visita de una instalación en
 * `crm_instalacion_agenda` (Fase 1, sin usar hasta ahora). Un único registro
 * "activo" por instalación — reprogramar actualiza el mismo, no acumula
 * historial todavía (eso llegará con el flujo de reagendado de la Fase 8).
 */
add_action( 'wp_ajax_crm_inst_guardar_agenda', 'crm_inst_ajax_guardar_agenda' );
function crm_inst_ajax_guardar_agenda() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$instalador_id  = (int) ( $_POST['instalador_id'] ?? 0 );
	$fecha_raw      = sanitize_text_field( wp_unslash( $_POST['fecha_cita'] ?? '' ) );

	if ( $instalacion_id <= 0 || $instalador_id <= 0 || $fecha_raw === '' ) {
		wp_send_json_error( [ 'message' => 'Rellena instalador y fecha.' ] );
	}

	// El <input type="datetime-local"> manda "YYYY-MM-DDTHH:MM".
	$timestamp = strtotime( str_replace( 'T', ' ', $fecha_raw ) );
	if ( $timestamp === false ) {
		wp_send_json_error( [ 'message' => 'Fecha no válida.' ] );
	}

	$ya_asignado = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . crm_inst_table_instaladores() . " WHERE instalacion_id = %d AND user_id = %d",
		$instalacion_id,
		$instalador_id
	) );
	if ( $ya_asignado === 0 ) {
		wp_send_json_error( [ 'message' => 'Ese instalador no está asignado a esta instalación todavía.' ] );
	}

	$fecha_mysql = date( 'Y-m-d H:i:s', $timestamp );
	$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM " . crm_inst_table_agenda() . " WHERE instalacion_id = %d LIMIT 1",
		$instalacion_id
	) );

	if ( $existing_id > 0 ) {
		$wpdb->update(
			crm_inst_table_agenda(),
			[ 'fecha_cita' => $fecha_mysql, 'instalador_id' => $instalador_id, 'estado' => 'pendiente' ],
			[ 'id' => $existing_id ]
		);
	} else {
		$wpdb->insert(
			crm_inst_table_agenda(),
			[ 'instalacion_id' => $instalacion_id, 'fecha_cita' => $fecha_mysql, 'instalador_id' => $instalador_id, 'estado' => 'pendiente' ],
			[ '%d', '%s', '%d', '%s' ]
		);
	}

	crm_inst_log_action( $instalacion_id, 'agenda', 'programar_visita', 'Visita programada para ' . date_i18n( 'd/m/Y H:i', $timestamp ) . '.' );

	if ( function_exists( 'crm_notificar' ) ) {
		$cliente_nombre = $wpdb->get_var( $wpdb->prepare(
			"SELECT c.cliente_nombre FROM " . crm_inst_table_instalaciones() . " i LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id WHERE i.id = %d",
			$instalacion_id
		) );
		crm_notificar(
			$instalador_id,
			$existing_id > 0 ? 'visita_reprogramada' : 'visita_programada',
			( $existing_id > 0 ? 'Visita reprogramada' : 'Visita programada' ) . ' para ' . date_i18n( 'd/m/Y H:i', $timestamp ) . ' — ' . ( $cliente_nombre ?: ( '#' . $instalacion_id ) ) . '.',
			home_url( '/calendario-instalador/' )
		);
	}

	wp_send_json_success( [ 'fecha_cita_label' => date_i18n( 'd/m/Y H:i', $timestamp ) ] );
}

/**
 * Listado filtrable de instalaciones + estadísticas por estado (para las
 * casillas del listado). Límite de 200 filas — sin paginación por ahora,
 * suficiente para el volumen actual; si crece habrá que paginar.
 */
add_action( 'wp_ajax_crm_inst_listar_instalaciones', 'crm_inst_ajax_listar_instalaciones' );
function crm_inst_ajax_listar_instalaciones() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$clients_table = $wpdb->prefix . 'crm_clients';
	$tabla_inst    = crm_inst_table_instalaciones();
	$tabla_trab    = crm_inst_table_trabajos();
	$tabla_agenda  = crm_inst_table_agenda();

	$estados_labels  = crm_instalaciones_estados();
	$tipos_labels    = crm_instalaciones_tipos();
	$subtipos_labels = crm_instalaciones_subtipos();

	$estado    = sanitize_key( wp_unslash( $_POST['estado'] ?? '' ) );
	$tipo      = sanitize_key( wp_unslash( $_POST['tipo_instalacion'] ?? '' ) );
	$query     = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
	$atencion_filtro = sanitize_key( wp_unslash( $_POST['atencion'] ?? '' ) );

	$where  = [ '1=1' ];
	$params = [];

	// v1.20.65: los "Requiere atención" filtran igual que las casillas de
	// estado, pero por un criterio compuesto propio en vez de `i.estado` — se
	// aplican en lugar del filtro de estado normal (mutuamente excluyentes,
	// el front ya los trata así al vaciar el desplegable de estado al elegir uno).
	if ( $atencion_filtro !== '' ) {
		$dias_af   = max( 0, (int) get_option( 'crm_inst_aviso_dias_antes', 3 ) );
		$ahora_af  = current_time( 'mysql' );
		$limite_af = date( 'Y-m-d H:i:s', strtotime( '+' . $dias_af . ' days', current_time( 'timestamp' ) ) );

		switch ( $atencion_filtro ) {
			case 'materiales_urgentes':
				$where[] = "i.estado NOT IN ('finalizada','cancelada')
					AND EXISTS (SELECT 1 FROM {$tabla_agenda} a2 WHERE a2.instalacion_id = i.id AND a2.fecha_cita BETWEEN %s AND %s)
					AND EXISTS (SELECT 1 FROM {$tabla_trab} t2 WHERE t2.instalacion_id = i.id AND t2.origen = 'holded' AND t2.estado != 'recibido')";
				$params[] = $ahora_af;
				$params[] = $limite_af;
				break;
			case 'extras_pendientes':
				// v1.20.75: 'pendiente' (recién declarada, sin enviar al cliente
				// todavía) y 'enviado_cliente' (esperando que el cliente
				// responda) son los dos estados que todavía necesitan
				// seguimiento — solo 'aprobado'/'rechazado' están resueltos.
				$where[] = "EXISTS (SELECT 1 FROM {$tabla_trab} t3 WHERE t3.instalacion_id = i.id AND t3.origen = 'extra' AND t3.estado IN ('pendiente','enviado_cliente'))";
				break;
			case 'cierres_pendientes':
				$where[] = "i.cierre_estado = 'declarado'";
				break;
			case 'pedidos_sin_confirmar':
				$where[] = "i.proveedor_pedido_estado = 'enviado'";
				break;
			case 'riesgo_retraso_proveedor':
				$where[] = "i.proveedor_entrega_estimada IS NOT NULL
					AND i.estado NOT IN ('finalizada','cancelada')
					AND EXISTS (SELECT 1 FROM {$tabla_agenda} a3 WHERE a3.instalacion_id = i.id AND i.proveedor_entrega_estimada >= DATE(a3.fecha_cita))";
				break;
		}
	} elseif ( $estado !== '' && isset( $estados_labels[ $estado ] ) ) {
		$where[]  = 'i.estado = %s';
		$params[] = $estado;
	}
	if ( $tipo !== '' && isset( $tipos_labels[ $tipo ] ) ) {
		$where[]  = 'i.tipo_instalacion = %s';
		$params[] = $tipo;
	}
	if ( $query !== '' ) {
		$where[]  = 'c.cliente_nombre LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $query ) . '%';
	}

	$sql = "SELECT i.id, i.estado, i.tipo_instalacion, i.subtipo_instalacion, i.fecha_creacion, i.holded_doc_id,
			i.proveedor_pedido_estado,
			c.cliente_nombre,
			(SELECT COUNT(*) FROM {$tabla_trab} t WHERE t.instalacion_id = i.id) AS num_materiales,
			(SELECT COUNT(*) FROM {$tabla_trab} t2 WHERE t2.instalacion_id = i.id AND t2.origen = 'extra' AND t2.estado IN ('pendiente','enviado_cliente')) AS extras_pendientes
		FROM {$tabla_inst} i
		LEFT JOIN {$clients_table} c ON c.id = i.client_id
		WHERE " . implode( ' AND ', $where ) . "
		ORDER BY i.fecha_creacion DESC
		LIMIT 200";

	$rows = empty( $params ) ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	$items = array_map( function ( $r ) use ( $tipos_labels, $subtipos_labels, $estados_labels ) {
		return [
			'id'                  => (int) $r['id'],
			'cliente_nombre'      => $r['cliente_nombre'] ?: '(sin cliente)',
			'estado'              => $r['estado'],
			'estado_label'        => $estados_labels[ $r['estado'] ] ?? $r['estado'],
			'tipo_instalacion'    => $tipos_labels[ $r['tipo_instalacion'] ] ?? $r['tipo_instalacion'],
			'subtipo_instalacion' => crm_inst_subtipo_label( $r['subtipo_instalacion'] ),
			'fecha_creacion'      => $r['fecha_creacion'],
			'num_materiales'      => (int) $r['num_materiales'],
			'extras_pendientes'   => (int) $r['extras_pendientes'],
			'holded_doc_id'       => $r['holded_doc_id'],
			'proveedor_pedido_estado' => $r['proveedor_pedido_estado'],
		];
	}, (array) $rows );

	// Estadísticas sobre el total real (sin filtrar), para que las casillas
	// sirvan de acceso rápido a cada estado sin perder el recuento global.
	$stats = array_fill_keys( array_keys( $estados_labels ), 0 );
	foreach ( (array) $wpdb->get_results( "SELECT estado, COUNT(*) AS total FROM {$tabla_inst} GROUP BY estado", ARRAY_A ) as $sr ) {
		if ( isset( $stats[ $sr['estado'] ] ) ) {
			$stats[ $sr['estado'] ] = (int) $sr['total'];
		}
	}

	wp_send_json_success( [
		'items' => $items,
		'stats' => $stats,
		'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla_inst}" ),
		'atencion' => crm_inst_get_atencion_counts(),
	] );
}

/**
 * Resumen de "Requiere atención" — cuántas instalaciones necesitan acción
 * hoy, no solo el recuento por estado. Mismo criterio de "urgente" que el
 * aviso proactivo por cron (`crm_inst_aviso_materiales_pendientes_run()`).
 * Extraído a función propia (v1.20.67) para poder reutilizarlo también en la
 * tarjeta "Instalaciones" del dashboard de wp-admin, no solo en el listado.
 *
 * @return array<string,int>
 */
function crm_inst_get_atencion_counts() {
	global $wpdb;
	$tabla_inst = crm_inst_table_instalaciones();
	$tabla_trab = crm_inst_table_trabajos();

	$dias_aviso = max( 0, (int) get_option( 'crm_inst_aviso_dias_antes', 3 ) );
	$ahora_at   = current_time( 'mysql' );
	$limite_at  = date( 'Y-m-d H:i:s', strtotime( '+' . $dias_aviso . ' days', current_time( 'timestamp' ) ) );

	$materiales_urgentes = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT i.id) FROM {$tabla_inst} i
		 INNER JOIN " . crm_inst_table_agenda() . " a ON a.instalacion_id = i.id
		 INNER JOIN {$tabla_trab} t ON t.instalacion_id = i.id AND t.origen = 'holded' AND t.estado != 'recibido'
		 WHERE i.estado NOT IN ( 'finalizada', 'cancelada' ) AND a.fecha_cita BETWEEN %s AND %s",
		$ahora_at, $limite_at
	) );
	$extras_pendientes_total = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT instalacion_id) FROM {$tabla_trab} WHERE origen = 'extra' AND estado IN ('pendiente','enviado_cliente')"
	);
	$cierres_pendientes = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$tabla_inst} WHERE cierre_estado = 'declarado'"
	);
	$pedidos_sin_confirmar = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$tabla_inst} WHERE proveedor_pedido_estado = 'enviado'"
	);
	$riesgo_retraso_proveedor = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT i.id) FROM {$tabla_inst} i
		 INNER JOIN " . crm_inst_table_agenda() . " a ON a.instalacion_id = i.id
		 WHERE i.proveedor_entrega_estimada IS NOT NULL
		   AND i.proveedor_entrega_estimada >= DATE( a.fecha_cita )
		   AND i.estado NOT IN ( 'finalizada', 'cancelada' )"
	);

	return [
		'materiales_urgentes'      => $materiales_urgentes,
		'extras_pendientes'        => $extras_pendientes_total,
		'cierres_pendientes'       => $cierres_pendientes,
		'pedidos_sin_confirmar'    => $pedidos_sin_confirmar,
		'riesgo_retraso_proveedor' => $riesgo_retraso_proveedor,
	];
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Modal compartido "Ver instalación" (usado por el listado y por el
// buscador de presupuestos, para no duplicar el marcado ni el JS entre las
// dos pantallas).
// ──────────────────────────────────────────────────────────────────────────────

function crm_inst_modal_css() {
	return '
	#crm-inst-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:100000; }
	#crm-inst-modal-content { background:#fff; max-width:640px; margin:5vh auto; padding:24px; border-radius:12px; max-height:88vh; overflow-y:auto; position:relative; box-shadow:0 20px 25px -5px rgba(0,0,0,.15); }
	#crm-inst-modal-content h3 { margin-top:0; color:#1f2937; }
	#crm-inst-modal-close { position:absolute; top:12px; right:12px; border:none; background:none; font-size:20px; cursor:pointer; line-height:1; color:#6b7280; }
	';
}

function crm_inst_render_modal_html() {
	?>
	<div id="crm-inst-modal-backdrop">
		<div id="crm-inst-modal-content">
			<button type="button" id="crm-inst-modal-close">×</button>
			<div id="crm-inst-modal-body"></div>
		</div>
	</div>
	<?php
}

/**
 * escapeHtml, la única pieza de JS que necesita casi cualquier pantalla del
 * módulo. Separada del resto del modal para que las pantallas que ya no usan
 * modal (el listado, la ficha) no tengan que cargar código de modal que no
 * usan.
 */
function crm_inst_js_escape_html() {
	return <<<'JS'
	function escapeHtml(s) {
		return $('<div>').text(s == null ? '' : s).html();
	}
	JS;
}

/**
 * openModal/closeModal + los bindings de cierre. Requiere que
 * `crm_inst_js_escape_html()` ya se haya inyectado antes (usa escapeHtml).
 * Hoy solo lo usa el buscador de presupuestos, para previsualizar un
 * presupuesto de Holded antes de crear la instalación — "ver instalación"
 * ya no es un modal, es la página `[crm_inst_ficha]`.
 */
function crm_inst_modal_js_open_close() {
	return <<<'JS'
	function openModal(html) {
		$('#crm-inst-modal-body').html(html);
		$('#crm-inst-modal-backdrop').show();
	}
	function closeModal() {
		$('#crm-inst-modal-backdrop').hide();
		$('#crm-inst-modal-body').empty();
	}
	$('#crm-inst-modal-close').on('click', closeModal);
	$('#crm-inst-modal-backdrop').on('click', function (e) {
		if (e.target === this) { closeModal(); }
	});
	JS;
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Shortcode [crm_inst_listado]: listado filtrable de instalaciones.
// ──────────────────────────────────────────────────────────────────────────────

add_shortcode( 'crm_inst_listado', 'crm_inst_shortcode_listado' );
function crm_inst_shortcode_listado() {
	if ( ! is_user_logged_in() ) {
		return '<p>Necesitas <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">iniciar sesión</a> para acceder.</p>';
	}
	if ( ! crm_inst_current_user_can_manage() ) {
		return '<p>No tienes permisos para ver las instalaciones.</p>';
	}

	$nonce     = wp_create_nonce( 'crm_inst_holded' );
	$ajax_url  = admin_url( 'admin-ajax.php' );
	$estados   = crm_instalaciones_estados();
	$tipos     = crm_instalaciones_tipos();
	$nueva_url = home_url( '/nueva-instalacion/' );
	$ficha_url = home_url( '/instalacion/' );

	ob_start();
	?>
	<style>
	.crm-inst-holded-wrap { max-width:1100px; margin:0 auto; padding:18px; font-family:system-ui,-apple-system,sans-serif; }
	.crm-inst-holded-wrap .widget-header-compact { flex-wrap:wrap; gap:10px; }
	.crm-inst-holded-wrap .crm-inst-filtros { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; align-items:center; }
	.crm-inst-holded-wrap input[type="text"],
	.crm-inst-holded-wrap select { padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; color:#374151; }
	.crm-inst-holded-wrap .crm-inst-filtros input[type="text"] { flex:1; min-width:180px; }
	.crm-inst-stat-item { cursor:pointer; transition:box-shadow .15s ease, border-color .15s ease; }
	.crm-inst-stat-item.is-active { border-color:#0073aa; box-shadow:0 0 0 2px rgba(0,115,170,.15); }
	#crm-inst-stats { display:flex; flex-wrap:nowrap; overflow-x:auto; gap:8px; margin-bottom:10px; padding-bottom:2px; }
	#crm-inst-stats .stat-item-compact { flex:0 0 auto; min-width:76px; padding:8px 10px; }
	#crm-inst-stats .stat-value { font-size:18px; margin-bottom:2px; }
	#crm-inst-stats .stat-label { font-size:9.5px; white-space:nowrap; }
	#crm-inst-atencion { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
	.crm-inst-atencion-item { flex:0 0 auto; padding:8px 12px; border-radius:6px; font-size:12.5px; font-weight:600; background:#f9fafb; border:1px solid #e5e7eb; color:#9ca3af; cursor:pointer; transition:box-shadow .15s ease, border-color .15s ease; }
	.crm-inst-atencion-item.tiene-avisos { background:#fffbeb; border-color:#fde68a; color:#92400e; }
	.crm-inst-atencion-item.is-active { border-color:#0073aa; box-shadow:0 0 0 2px rgba(0,115,170,.15); }
	</style>
	<div class="crm-inst-holded-wrap">
		<?php crm_inst_render_breadcrumb( [ [ 'label' => 'Instalaciones' ] ] ); ?>
		<div class="crm-widget-compact">
			<div class="widget-header-compact">
				<h3 class="widget-title-compact">Instalaciones</h3>
				<a href="<?php echo esc_url( $nueva_url ); ?>" class="crm-btn">+ Nueva instalación</a>
			</div>
			<div class="widget-content-compact">
				<div class="stats-row-compact" id="crm-inst-stats"></div>
				<div id="crm-inst-atencion"></div>
				<div class="crm-inst-filtros">
					<input type="text" id="crm-inst-filtro-query" placeholder="Buscar por cliente…">
					<select id="crm-inst-filtro-estado">
						<option value="">Todos los estados</option>
						<?php foreach ( $estados as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="crm-inst-filtro-tipo">
						<option value="">Todos los tipos</option>
						<?php foreach ( $tipos as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div id="crm-inst-listado-resultados"><p>Cargando…</p></div>
			</div>
		</div>
	</div>

	<script>
	(function ($) {
		var ajaxurl  = <?php echo wp_json_encode( $ajax_url ); ?>;
		var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
		var fichaUrl = <?php echo wp_json_encode( $ficha_url ); ?>;
		var estadosLabels = <?php echo wp_json_encode( $estados ); ?>;
		var iconExtraPendiente = <?php echo wp_json_encode( function_exists( 'crm_icon' ) ? crm_icon( 'warning-circle', 14 ) : '' ); ?>;

		<?php echo crm_inst_js_escape_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		function badgeClass(estado) {
			return 'status-badge status-inst-' + estado;
		}

		function proveedorLabel(estado) {
			if (estado === 'confirmado') { return '<span style="color:#065f46;font-weight:600;">Confirmado</span>'; }
			if (estado === 'enviado') { return '<span style="color:#92400e;font-weight:600;">Enviado</span>'; }
			return '<span style="color:#9ca3af;">—</span>';
		}

		function renderStats(stats, total, estadoActivo) {
			var html = '<div class="stat-item-compact crm-inst-stat-item' + (estadoActivo === '' ? ' is-active' : '') + '" data-estado="">' +
				'<div class="stat-value">' + total + '</div><div class="stat-label">Total</div></div>';
			Object.keys(estadosLabels).forEach(function (key) {
				html += '<div class="stat-item-compact crm-inst-stat-item' + (estadoActivo === key ? ' is-active' : '') + '" data-estado="' + key + '">' +
					'<div class="stat-value">' + (stats[key] || 0) + '</div><div class="stat-label">' + escapeHtml(estadosLabels[key]) + '</div></div>';
			});
			$('#crm-inst-stats').html(html);
		}

		var atencionActiva = '';

		function renderAtencion(atencion, activa) {
			if (!atencion) { return; }
			var items = [
				{ key: 'materiales_urgentes', label: 'Con visita próxima sin materiales', value: atencion.materiales_urgentes },
				{ key: 'extras_pendientes', label: 'Con partidas extra sin resolver', value: atencion.extras_pendientes },
				{ key: 'cierres_pendientes', label: 'Con cierre por aprobar', value: atencion.cierres_pendientes },
				{ key: 'pedidos_sin_confirmar', label: 'Con pedido a proveedor sin confirmar', value: atencion.pedidos_sin_confirmar },
				{ key: 'riesgo_retraso_proveedor', label: 'Con riesgo de retraso del proveedor', value: atencion.riesgo_retraso_proveedor }
			];
			var html = items.map(function (it) {
				return '<div class="crm-inst-atencion-item' + (it.value > 0 ? ' tiene-avisos' : '') + (activa === it.key ? ' is-active' : '') + '" data-key="' + it.key + '">' + it.value + ' ' + escapeHtml(it.label) + '</div>';
			}).join('');
			$('#crm-inst-atencion').html(html);
		}

		function cargar() {
			var estado = atencionActiva === '' ? $('#crm-inst-filtro-estado').val() : '';
			var tipo   = $('#crm-inst-filtro-tipo').val();
			var query  = $('#crm-inst-filtro-query').val();
			$('#crm-inst-listado-resultados').html('<p>Cargando…</p>');
			$.post(ajaxurl, {
				action: 'crm_inst_listar_instalaciones', nonce: nonce,
				estado: estado, tipo_instalacion: tipo, query: query, atencion: atencionActiva
			}, function (resp) {
				if (!resp.success) {
					$('#crm-inst-listado-resultados').html('<p style="color:#991b1b;">' + escapeHtml(resp.data.message) + '</p>');
					return;
				}
				renderStats(resp.data.stats, resp.data.total, estado);
				renderAtencion(resp.data.atencion, atencionActiva);
				if (!resp.data.items.length) {
					$('#crm-inst-listado-resultados').html('<p>No hay instalaciones con estos filtros.</p>');
					return;
				}
				var rows = resp.data.items.map(function (it) {
					return '<tr>' +
						'<td>' + escapeHtml(it.cliente_nombre) + '</td>' +
						'<td>' + escapeHtml(it.tipo_instalacion) + (it.subtipo_instalacion ? ' — ' + escapeHtml(it.subtipo_instalacion) : '') + '</td>' +
						'<td><span class="' + badgeClass(it.estado) + '">' + escapeHtml(it.estado_label) + '</span></td>' +
						'<td>' + escapeHtml(it.num_materiales) +
							(it.extras_pendientes > 0
								? ' <span title="' + it.extras_pendientes + ' partida(s) extra sin resolver" style="color:#92400e; vertical-align:middle;">' + iconExtraPendiente + '</span>'
								: '') +
							'</td>' +
						'<td>' + proveedorLabel(it.proveedor_pedido_estado) + '</td>' +
						'<td>' + escapeHtml(it.fecha_creacion) + '</td>' +
						'<td><a href="' + fichaUrl + '?id=' + it.id + '" class="crm-btn">Ver</a></td>' +
						'</tr>';
				}).join('');
				$('#crm-inst-listado-resultados').html(
					'<div class="table-responsive-compact"><table class="crm-table-compact"><thead><tr><th>Cliente</th><th>Tipo</th><th>Estado</th><th>Materiales</th><th>Proveedor</th><th>Creada</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				);
			});
		}

		$('#crm-inst-filtro-estado, #crm-inst-filtro-tipo').on('change', function () {
			atencionActiva = '';
			cargar();
		});
		$('#crm-inst-filtro-query').on('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); cargar(); }
		});
		var queryTimer = null;
		$('#crm-inst-filtro-query').on('input', function () {
			clearTimeout(queryTimer);
			queryTimer = setTimeout(cargar, 400);
		});
		$(document).on('click', '.crm-inst-stat-item', function () {
			atencionActiva = '';
			$('#crm-inst-filtro-estado').val($(this).data('estado'));
			cargar();
		});
		$(document).on('click', '.crm-inst-atencion-item', function () {
			var key = $(this).data('key');
			atencionActiva = (atencionActiva === key) ? '' : key;
			$('#crm-inst-filtro-estado').val('');
			cargar();
		});

		cargar();
	})(jQuery);
	</script>
	<?php
	return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Resumen embebido en la ficha de cliente (card "renovables" de
// crm_formulario_alta_cliente() en crm-plugin.php).
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Resumen de instalaciones de un cliente — solo lectura, campos mínimos.
 * Para el detalle completo está `crm_inst_get_instalacion_data()`.
 *
 * @param int $client_id
 * @return array
 */
function crm_inst_get_instalaciones_resumen_by_client( $client_id ) {
	$client_id = (int) $client_id;
	if ( $client_id <= 0 ) {
		return [];
	}

	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, estado, tipo_instalacion, subtipo_instalacion, fecha_creacion
		 FROM " . crm_inst_table_instalaciones() . " WHERE client_id = %d ORDER BY fecha_creacion DESC",
		$client_id
	), ARRAY_A );

	$estados  = crm_instalaciones_estados();
	$tipos    = crm_instalaciones_tipos();
	$subtipos = crm_instalaciones_subtipos();

	return array_map( function ( $r ) use ( $estados, $tipos, $subtipos ) {
		return [
			'id'                        => (int) $r['id'],
			'estado'                    => $r['estado'],
			'estado_label'              => $estados[ $r['estado'] ] ?? $r['estado'],
			'tipo_instalacion_label'    => $tipos[ $r['tipo_instalacion'] ] ?? $r['tipo_instalacion'],
			'subtipo_instalacion_label' => crm_inst_subtipo_label( $r['subtipo_instalacion'] ),
			'fecha_creacion'            => $r['fecha_creacion'],
		];
	}, (array) $rows );
}

/**
 * Imprime el bloque de instalaciones dentro de la card de un sector de la
 * ficha de cliente. Visible para cualquiera que ya pueda ver al cliente
 * (confirmado 2026-08-20); el enlace a la ficha completa de la instalación
 * solo aparece si el usuario tiene `crm_inst_manage`. No imprime nada si el
 * cliente no tiene ninguna instalación (para no ensuciar la ficha de la
 * mayoría de clientes con interés en renovables que aún no tienen una).
 *
 * @param int $client_id
 */
function crm_inst_render_resumen_cliente( $client_id ) {
	$instalaciones = crm_inst_get_instalaciones_resumen_by_client( $client_id );
	if ( empty( $instalaciones ) ) {
		return;
	}

	$puede_gestionar = crm_inst_current_user_can_manage();
	$ficha_url       = home_url( '/instalacion/' );
	?>
	<div class="crm-inst-resumen-cliente">
		<strong>Instalaciones</strong>
		<?php foreach ( $instalaciones as $inst ) : ?>
			<div class="crm-inst-resumen-fila">
				<span class="status-badge status-inst-<?php echo esc_attr( $inst['estado'] ); ?>"><?php echo esc_html( $inst['estado_label'] ); ?></span>
				<span><?php echo esc_html( $inst['tipo_instalacion_label'] ); ?><?php echo $inst['subtipo_instalacion_label'] ? ' — ' . esc_html( $inst['subtipo_instalacion_label'] ) : ''; ?></span>
				<span class="crm-inst-resumen-fecha"><?php echo esc_html( $inst['fecha_creacion'] ); ?></span>
				<?php if ( $puede_gestionar ) : ?>
					<a href="<?php echo esc_url( $ficha_url . '?id=' . $inst['id'] ); ?>" class="crm-btn crm-inst-resumen-ver">Ver</a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<style>
	.crm-inst-resumen-cliente { margin-top:14px; border-top:1px solid #e5e7eb; padding-top:10px; }
	.crm-inst-resumen-cliente strong { display:block; margin-bottom:6px; font-size:13px; color:#1f2937; }
	.crm-inst-resumen-fila { display:flex; align-items:center; gap:8px; padding:4px 0; font-size:13px; flex-wrap:wrap; }
	.crm-inst-resumen-fecha { color:#9ca3af; font-size:12px; }
	.crm-inst-resumen-ver { padding:2px 8px; font-size:12px; margin-left:auto; }
	</style>
	<?php
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 6 — Doble check de facturación y margen
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Lado CLIENTE del doble check: resuelve (si hace falta) y comprueba el
 * estado de pago de la factura generada a partir del presupuesto de esta
 * instalación. Se resuelve solo — no hace falta vincular nada a mano, ver
 * crm_holded_find_invoice_from_estimate().
 */
add_action( 'wp_ajax_crm_inst_comprobar_pago_cliente', 'crm_inst_ajax_comprobar_pago_cliente' );
function crm_inst_ajax_comprobar_pago_cliente() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$inst = $wpdb->get_row( $wpdb->prepare(
		"SELECT i.holded_doc_id, i.holded_invoice_id, c.holded_contact_id
		 FROM " . crm_inst_table_instalaciones() . " i
		 LEFT JOIN {$wpdb->prefix}crm_clients c ON c.id = i.client_id
		 WHERE i.id = %d",
		$instalacion_id
	), ARRAY_A );
	if ( ! $inst ) {
		wp_send_json_error( [ 'message' => 'Instalación no encontrada.' ] );
	}

	$invoice_id = $inst['holded_invoice_id'];
	if ( empty( $invoice_id ) ) {
		if ( empty( $inst['holded_doc_id'] ) || empty( $inst['holded_contact_id'] ) ) {
			wp_send_json_error( [ 'message' => 'Falta el presupuesto o el contacto de Holded del cliente — no se puede buscar la factura.' ] );
		}
		$invoice_id = crm_holded_find_invoice_from_estimate( $inst['holded_contact_id'], $inst['holded_doc_id'] );
		if ( $invoice_id === null ) {
			wp_send_json_error( [ 'message' => 'Todavía no hay ninguna factura generada en Holded a partir de este presupuesto.' ] );
		}
		$wpdb->update( crm_inst_table_instalaciones(), [ 'holded_invoice_id' => $invoice_id ], [ 'id' => $instalacion_id ] );
	}

	$invoice = crm_holded_get_invoice( $invoice_id );
	if ( is_wp_error( $invoice ) ) {
		wp_send_json_error( [ 'message' => 'No se pudo consultar la factura en Holded: ' . $invoice->get_error_message() ] );
	}

	wp_send_json_success( [
		'invoice_id'       => $invoice_id,
		'status'           => $invoice['status'] ?? '',
		'document_number'  => $invoice['document_number'] ?? '',
		'total'            => $invoice['total'] ?? '0',
		'payments_pending' => $invoice['payments_pending'] ?? '0',
	] );
}

/**
 * Lado PROVEEDOR del doble check: busca compras/gastos de Holded por texto
 * libre, para que el jefe de instalaciones vincule a mano la que corresponde
 * a esta instalación (no hay forma de resolverlo solo, ver
 * crm_holded_search_purchases()).
 */
add_action( 'wp_ajax_crm_inst_buscar_compra_holded', 'crm_inst_ajax_buscar_compra_holded' );
function crm_inst_ajax_buscar_compra_holded() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$query = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
	if ( mb_strlen( $query ) < 2 ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	$resultados = crm_holded_search_purchases( $query );
	if ( is_wp_error( $resultados ) ) {
		wp_send_json_error( [ 'message' => $resultados->get_error_message() ] );
	}

	$items = array_map( function ( $p ) {
		return [
			'id'              => $p['id'] ?? '',
			'document_number' => $p['document_number'] ?? '',
			'contact_name'    => $p['contact_name'] ?? '',
			'date'            => $p['date'] ?? '',
			'total'           => $p['total'] ?? '0',
			'status'          => $p['status'] ?? '',
		];
	}, array_slice( $resultados, 0, 20 ) );

	wp_send_json_success( [ 'items' => $items ] );
}

/**
 * Vincula (o desvincula) la compra de Holded elegida a esta instalación.
 */
add_action( 'wp_ajax_crm_inst_vincular_compra_proveedor', 'crm_inst_ajax_vincular_compra_proveedor' );
function crm_inst_ajax_vincular_compra_proveedor() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$purchase_id    = sanitize_text_field( wp_unslash( $_POST['purchase_id'] ?? '' ) );
	if ( $instalacion_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$wpdb->update( crm_inst_table_instalaciones(), [ 'holded_purchase_id' => $purchase_id !== '' ? $purchase_id : null ], [ 'id' => $instalacion_id ] );
	crm_inst_log_action( $instalacion_id, 'instalacion', 'proveedor_compra_vinculada', $purchase_id !== '' ? ( 'Compra de proveedor vinculada: ' . $purchase_id ) : 'Vínculo con la compra de proveedor eliminado.' );

	wp_send_json_success();
}

/**
 * Comprueba el estado de pago de la compra de proveedor ya vinculada.
 */
add_action( 'wp_ajax_crm_inst_comprobar_pago_proveedor', 'crm_inst_ajax_comprobar_pago_proveedor' );
function crm_inst_ajax_comprobar_pago_proveedor() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$purchase_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT holded_purchase_id FROM " . crm_inst_table_instalaciones() . " WHERE id = %d",
		$instalacion_id
	) );
	if ( empty( $purchase_id ) ) {
		wp_send_json_error( [ 'message' => 'Todavía no hay ninguna compra de proveedor vinculada a esta instalación.' ] );
	}

	$purchase = crm_holded_get_purchase( $purchase_id );
	if ( is_wp_error( $purchase ) ) {
		wp_send_json_error( [ 'message' => 'No se pudo consultar la compra en Holded: ' . $purchase->get_error_message() ] );
	}

	wp_send_json_success( [
		'purchase_id'      => $purchase_id,
		'status'           => $purchase['status'] ?? '',
		'document_number'  => $purchase['document_number'] ?? '',
		'total'            => $purchase['total'] ?? '0',
		'payments_pending' => $purchase['payments_pending'] ?? '0',
	] );
}

/**
 * Busca proyectos de Holded para vincular a mano, una vez, el que
 * corresponde a esta instalación (v1.20.88 — usado para etiquetar el
 * albarán de salida de materiales con `project_id`).
 */
add_action( 'wp_ajax_crm_inst_buscar_proyecto_holded', 'crm_inst_ajax_buscar_proyecto_holded' );
function crm_inst_ajax_buscar_proyecto_holded() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	$query = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
	if ( mb_strlen( $query ) < 2 ) {
		wp_send_json_success( [ 'items' => [] ] );
	}

	$resultados = crm_holded_search_projects( $query );
	if ( is_wp_error( $resultados ) ) {
		wp_send_json_error( [ 'message' => $resultados->get_error_message() ] );
	}

	$items = array_map( function ( $p ) {
		return [
			'id'           => $p['id'] ?? '',
			'name'         => $p['name'] ?? '',
			'contact_name' => $p['contact_name'] ?? '',
		];
	}, array_slice( $resultados, 0, 20 ) );

	wp_send_json_success( [ 'items' => $items ] );
}

/**
 * Vincula (o desvincula) el proyecto de Holded elegido a esta instalación.
 */
add_action( 'wp_ajax_crm_inst_vincular_proyecto', 'crm_inst_ajax_vincular_proyecto' );
function crm_inst_ajax_vincular_proyecto() {
	if ( ! crm_inst_current_user_can_manage() || ! check_ajax_referer( 'crm_inst_holded', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
	}

	global $wpdb;
	$instalacion_id = (int) ( $_POST['instalacion_id'] ?? 0 );
	$project_id     = sanitize_text_field( wp_unslash( $_POST['project_id'] ?? '' ) );
	if ( $instalacion_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Datos no válidos.' ] );
	}

	$wpdb->update( crm_inst_table_instalaciones(), [ 'holded_project_id' => $project_id !== '' ? $project_id : null ], [ 'id' => $instalacion_id ] );
	crm_inst_log_action( $instalacion_id, 'instalacion', 'proyecto_holded_vinculado', $project_id !== '' ? ( 'Proyecto de Holded vinculado: ' . $project_id ) : 'Vínculo con el proyecto de Holded eliminado.' );

	wp_send_json_success();
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Shortcode [crm_inst_ficha]: ficha completa de una instalación.
// ──────────────────────────────────────────────────────────────────────────────

add_shortcode( 'crm_inst_ficha', 'crm_inst_shortcode_ficha' );
function crm_inst_shortcode_ficha() {
	if ( ! is_user_logged_in() ) {
		return '<p>Necesitas <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">iniciar sesión</a> para acceder.</p>';
	}
	if ( ! crm_inst_current_user_can_manage() ) {
		return '<p>No tienes permisos para ver instalaciones.</p>';
	}

	$instalacion_id = (int) ( $_GET['id'] ?? 0 );
	$listado_url    = home_url( '/instalaciones/' );

	$data = crm_inst_get_instalacion_data( $instalacion_id );
	if ( is_wp_error( $data ) ) {
		return '<p style="color:#991b1b;">' . esc_html( $data->get_error_message() ) . '</p><p><a href="' . esc_url( $listado_url ) . '">← Volver al listado</a></p>';
	}

	$nonce     = wp_create_nonce( 'crm_inst_holded' );
	$ajax_url  = admin_url( 'admin-ajax.php' );
	$estados   = crm_instalaciones_estados();

	// Instaladores disponibles para asignar: usuarios con el rol `instalador`
	// que todavía no están en esta instalación.
	$ya_asignados_ids = wp_list_pluck( $data['instaladores'], 'user_id' );
	$instaladores_disponibles = array_filter(
		get_users( [ 'role' => 'instalador', 'orderby' => 'display_name' ] ),
		function ( $u ) use ( $ya_asignados_ids ) {
			return ! in_array( (int) $u->ID, array_map( 'intval', $ya_asignados_ids ), true );
		}
	);

	ob_start();
	?>
	<style>
	.crm-inst-ficha-wrap { max-width:1000px; margin:0 auto; padding:18px; font-family:system-ui,-apple-system,sans-serif; }
	.crm-inst-ficha-wrap .widget-header-compact { flex-wrap:wrap; gap:10px; }
	.crm-inst-ficha-section { border-top:1px solid #e5e7eb; padding-top:16px; margin-top:16px; }
	.crm-inst-ficha-section:first-child { border-top:none; padding-top:0; margin-top:0; }
	.crm-inst-ficha-section h4 { margin:0 0 10px; font-size:14px; color:#1f2937; }
	.crm-inst-datos-generales { display:flex; gap:16px; align-items:stretch; flex-wrap:wrap; }
	.crm-inst-datos-lista { flex:1 1 320px; min-width:280px; }
	.crm-inst-dato-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
	.crm-inst-dato-row span { color:#6b7280; }
	.crm-inst-dato-row strong { color:#1f2937; text-align:right; }
	.crm-inst-aviso-campo { color:#92400e; font-weight:600; font-size:12px; }
	.crm-inst-aviso-direccion { background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:10px 12px; margin-top:-1px; }
	.crm-inst-direccion-edit { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
	.crm-inst-direccion-edit input[type="text"] { flex:1; min-width:220px; }
	.crm-inst-extra-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; text-transform:uppercase; }
	.crm-inst-extra-badge-pendiente { background:#fef3c7; color:#92400e; }
	.crm-inst-extra-badge-enviado_cliente { background:#dbeafe; color:#1e40af; }
	.crm-inst-extra-badge-aprobado { background:#d1fae5; color:#065f46; }
	.crm-inst-extra-badge-rechazado { background:#fee2e2; color:#991b1b; }
	.crm-inst-extra-badge-neutro { background:#f3f4f6; color:#6b7280; }
	.crm-inst-stock-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; white-space:nowrap; }
	.crm-inst-stock-badge-ok { background:#d1fae5; color:#065f46; }
	.crm-inst-stock-badge-bajo { background:#fef3c7; color:#92400e; }
	.crm-inst-stock-badge-sin { background:#fee2e2; color:#991b1b; }
	.crm-inst-stock-badge-neutro { background:#f3f4f6; color:#6b7280; }
	.crm-inst-cierre-fotos { display:flex; gap:8px; flex-wrap:wrap; margin:8px 0; }
	.crm-inst-cierre-foto-item { display:flex; flex-direction:column; align-items:center; gap:4px; text-decoration:none; }
	.crm-inst-cierre-foto-item img { width:90px; height:90px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; }
	.crm-inst-cierre-foto-item span { font-size:10px; color:#6b7280; max-width:90px; text-align:center; }
	.crm-inst-cambiar-estado-wrap { margin-top:10px; }
	.crm-inst-cambiar-estado { display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; }
	.crm-inst-field-info { display:block; margin-top:4px; color:#9ca3af; font-size:11px; font-style:italic; }
	.crm-inst-cambiar-estado select { padding:5px 8px; font-size:12px; }
	.crm-inst-mapa-placeholder { flex:0 1 160px; min-width:140px; min-height:100px; border:1px dashed #d1d5db; border-radius:8px; background:#f9fafb; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; color:#9ca3af; font-size:12px; text-align:center; padding:16px; }
	.crm-inst-mapa-placeholder span { font-size:24px; }
	.crm-inst-mapa-link { flex:0 1 160px; min-width:140px; min-height:100px; border:1px solid #e5e7eb; border-radius:8px; background:#f9fafb; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; color:#374151; text-decoration:none; font-size:12px; font-weight:600; text-align:center; padding:16px; transition:background .15s ease; }
	.crm-inst-mapa-link:hover { background:#f3f4f6; }
	.crm-inst-mapa-link span:first-child { font-size:24px; }
	.crm-inst-mapa-wrap { position:relative; flex:0 1 220px; min-width:180px; min-height:150px; border-radius:8px; overflow:hidden; }
	.crm-inst-mapa-wrap #crm-inst-mapa { width:100%; height:100%; min-height:150px; }
	.crm-inst-mapa-overlay-link { position:absolute; top:8px; left:50%; transform:translateX(-50%); z-index:500; background:rgba(255,255,255,.92); color:#1f2937; text-decoration:none; font-size:12px; font-weight:600; padding:5px 10px; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.2); white-space:nowrap; }
	.crm-inst-mapa-overlay-link:hover { background:#fff; }
	.crm-inst-mapa-wrap .leaflet-control-attribution { font-size:9px; background:rgba(255,255,255,.65); padding:0 4px; line-height:1.4; }
	.crm-inst-ficha-wrap select, .crm-inst-ficha-wrap input[type="text"] { padding:7px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; color:#374151; }
	.crm-inst-instalador-row { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f3f4f6; }
	.crm-inst-proveedor-row { margin-top:10px; font-size:12px; color:#6b7280; }
	.crm-inst-facturacion-check { border-top:1px solid #f3f4f6; padding-top:12px; margin-top:12px; }
	.crm-inst-facturacion-check:first-of-type { border-top:none; padding-top:0; margin-top:14px; }
	.crm-inst-facturacion-check strong { display:block; font-size:13px; color:#1f2937; margin-bottom:4px; }
	.crm-inst-compra-buscador { display:flex; gap:8px; margin:8px 0; }
	.crm-inst-compra-buscador input { flex:1; }
	.crm-inst-compra-resultado-item { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:6px; font-size:12.5px; }
	.crm-inst-material-recibido { width:16px; height:16px; cursor:pointer; }
	.crm-inst-timeline { display:flex; flex-wrap:wrap; gap:0; }
	.crm-inst-timeline-step { flex:1; min-width:140px; text-align:center; padding:12px 8px; background:#f9fafb; border:1px dashed #d1d5db; border-radius:6px; margin:4px; color:#9ca3af; font-size:12px; }
	.crm-inst-log-item { font-size:12px; color:#6b7280; padding:6px 0; border-bottom:1px solid #f3f4f6; }
	.crm-inst-log-item strong { color:#374151; }
	.crm-inst-totales td { font-weight:700; }
	</style>
	<div class="crm-inst-ficha-wrap">
		<?php crm_inst_render_breadcrumb( [
			[ 'label' => 'Instalaciones', 'url' => $listado_url ],
			[ 'label' => $data['cliente_nombre'] ],
		] ); ?>
		<div class="crm-widget-compact">
			<div class="widget-header-compact">
				<h3 class="widget-title-compact"><?php echo esc_html( $data['cliente_nombre'] ); ?></h3>
				<span id="crm-inst-estado-badge" class="status-badge status-inst-<?php echo esc_attr( $data['estado'] ); ?>"><?php echo esc_html( $data['estado_label'] ); ?></span>
			</div>
			<div class="widget-content-compact">

				<div class="crm-inst-ficha-section">
					<h4>Datos generales</h4>
					<div class="crm-inst-datos-generales">
						<div class="crm-inst-datos-lista">
							<div class="crm-inst-dato-row"><span>Cliente</span><strong><?php echo esc_html( $data['cliente_nombre'] ); ?></strong></div>
							<div class="crm-inst-dato-row"><span>Contacto</span><strong>
								<?php if ( empty( $data['email_cliente'] ) && empty( $data['telefono'] ) ) : ?>
									<span class="crm-inst-aviso-campo">⚠️ Sin datos de contacto (no vinieron de Holded) — revísalo en la ficha del cliente.</span>
								<?php else : ?>
									<?php echo esc_html( $data['email_cliente'] ?: '—' ); ?><?php echo $data['telefono'] ? ' · ' . esc_html( $data['telefono'] ) : ''; ?>
								<?php endif; ?>
							</strong></div>
							<div class="crm-inst-dato-row">
								<span>Tipo</span>
								<strong>
									<select id="crm-inst-tipo-select">
										<?php foreach ( crm_instalaciones_tipos() as $tk => $tl ) : ?>
											<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $data['tipo_instalacion'], $tk ); ?>><?php echo esc_html( $tl ); ?></option>
										<?php endforeach; ?>
									</select>
									<span id="crm-inst-subtipo-checks" <?php echo $data['tipo_instalacion'] !== 'residencial' ? 'style="display:none;"' : ''; ?>>
										<?php $subtipos_actuales = crm_inst_subtipos_array( $data['subtipo_instalacion'] ); ?>
										<?php foreach ( crm_instalaciones_subtipos() as $sk => $sl ) : ?>
											<label style="font-weight:400;margin-right:8px;">
												<input type="checkbox" class="crm-inst-subtipo-checkbox" value="<?php echo esc_attr( $sk ); ?>" <?php checked( in_array( $sk, $subtipos_actuales, true ) ); ?>>
												<?php echo esc_html( $sl ); ?>
											</label>
										<?php endforeach; ?>
									</span>
									<button type="button" class="crm-btn" id="crm-inst-guardar-tipo-btn">Guardar</button>
									<span id="crm-inst-tipo-msg" class="crm-inst-field-info"></span>
								</strong>
							</div>
							<div class="crm-inst-dato-row crm-inst-dato-row-direccion">
								<span>Dirección</span>
								<strong id="crm-inst-direccion-actual" <?php echo empty( $data['direccion_instalacion'] ) ? 'style="display:none;"' : ''; ?>><?php echo esc_html( $data['direccion_instalacion'] ); ?></strong>
							</div>
							<?php if ( empty( $data['direccion_instalacion'] ) ) : ?>
								<div class="crm-inst-aviso-direccion" id="crm-inst-aviso-direccion">
									<span class="crm-inst-aviso-campo">⚠️ Falta la dirección de la instalación — no vino de Holded. Es imprescindible para la visita, añádela a mano:</span>
									<div class="crm-inst-direccion-edit">
										<input type="text" id="crm-inst-direccion-input" placeholder="Calle, número, población, código postal">
										<button type="button" class="crm-btn" id="crm-inst-guardar-direccion-btn">Guardar dirección</button>
										<span id="crm-inst-direccion-msg"></span>
									</div>
								</div>
							<?php endif; ?>
							<div class="crm-inst-dato-row"><span>Presupuesto Holded</span><strong>
								<?php echo esc_html( $data['holded_doc_id'] ?: '—' ); ?>
								<?php if ( ! empty( $data['holded_doc_id'] ) ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=crm_inst_descargar_presupuesto&instalacion_id=' . $data['id'] ), 'crm_inst_descargar_presupuesto' ) ); ?>" style="margin-left:6px;font-weight:600;">⬇ Descargar</a>
								<?php endif; ?>
							</strong></div>
							<div class="crm-inst-dato-row"><span>Creada</span><strong><?php echo esc_html( $data['fecha_creacion'] ); ?></strong></div>

							<?php // Sin reglas de transición todavía (llegan con la automatización de las Fases 3-6): cualquier estado del catálogo es válido de momento. ?>
							<div class="crm-inst-cambiar-estado-wrap">
								<div class="crm-inst-cambiar-estado">
									<label for="crm-inst-select-estado">Estado</label>
									<select id="crm-inst-select-estado">
										<?php foreach ( $estados as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $data['estado'] ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="button" class="crm-btn" id="crm-inst-guardar-estado-btn">Guardar</button>
									<span id="crm-inst-estado-msg"></span>
								</div>
								<small id="crm-inst-estado-info" class="crm-inst-field-info"></small>
							</div>
						</div>
						<?php
						$maps_url = '';
						if ( $data['lat'] !== null && $data['lng'] !== null ) {
							$maps_url = 'https://www.google.com/maps?q=' . $data['lat'] . ',' . $data['lng'];
						} elseif ( ! empty( $data['direccion_instalacion'] ) ) {
							$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $data['direccion_instalacion'] );
						}
						?>
						<?php if ( $data['geocode_status'] === 'ok' && $data['lat'] !== null && $data['lng'] !== null ) : ?>
							<div class="crm-inst-mapa-wrap">
								<div id="crm-inst-mapa" data-lat="<?php echo esc_attr( $data['lat'] ); ?>" data-lng="<?php echo esc_attr( $data['lng'] ); ?>"></div>
								<a href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener noreferrer" class="crm-inst-mapa-overlay-link">Ver en Google Maps</a>
							</div>
						<?php elseif ( $maps_url !== '' ) : ?>
							<a href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener noreferrer" class="crm-inst-mapa-link">
								<span>📍</span>
								<span>Ver en Google Maps</span>
							</a>
						<?php else : ?>
							<div class="crm-inst-mapa-placeholder"><span>📍</span><p>Sin dirección.</p></div>
						<?php endif; ?>
					</div>
				</div>

				<div class="crm-inst-ficha-section">
					<h4>Materiales<?php echo empty( $data['materiales'] ) ? '' : ' (' . count( $data['materiales'] ) . ')'; ?></h4>
					<?php if ( empty( $data['materiales'] ) ) : ?>
						<p>Sin materiales cargados.</p>
					<?php else : ?>
						<div class="table-responsive-compact">
							<table class="crm-table-compact">
								<thead><tr><th>Descripción</th><th>Uds.</th><th>Precio</th><th>Coste</th><th>Importe</th><th>Margen</th><th>Origen</th><th>Stock</th><th>Recibido</th></tr></thead>
								<tbody id="crm-inst-materiales-tbody">
									<?php foreach ( $data['materiales'] as $m ) : ?>
										<tr data-material-id="<?php echo esc_attr( $m['id'] ); ?>">
											<td><?php echo esc_html( $m['descripcion'] ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $m['unidades'], 2 ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $m['precio_unitario'], 2 ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $m['coste_unitario'], 2 ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $m['importe'], 2 ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( $m['margen'], 2 ) ); ?></td>
											<td>
												<?php if ( $m['origen'] === 'extra' ) : ?>
													<span class="crm-inst-stock-badge crm-inst-stock-badge-neutro" title="Partida añadida por el instalador durante la instalación, no venía en el presupuesto">Instalador</span>
												<?php else : ?>
													Presupuesto
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $m['origen'] === 'holded' && function_exists( 'crm_holded_match_producto_por_nombre' ) ) :
													// v1.20.58: si la línea viene enganchada a un producto real
													// (se eligió del catálogo al presupuestar) el match es 100%
													// fiable por ID; si no, caemos al best-effort por nombre.
													$producto  = null;
													$confianza = '';
													if ( ! empty( $m['holded_product_id'] ) && function_exists( 'crm_holded_get_product_by_id' ) ) {
														$producto  = crm_holded_get_product_by_id( $m['holded_product_id'] );
														$confianza = 'catálogo (línea enganchada al producto)';
													}
													if ( ! $producto ) {
														$match = crm_holded_match_producto_por_nombre( $m['descripcion'] );
														if ( $match ) {
															$producto  = $match['producto'];
															$confianza = 'posible coincidencia por nombre, revísalo';
														}
													}
													if ( $producto ) :
														$stock     = crm_holded_producto_stock_en_almacen( $producto, crm_holded_warehouse_id() );
														$min_stock = isset( $producto['min_stock'] ) && $producto['min_stock'] !== null ? (float) str_replace( ',', '.', (string) $producto['min_stock'] ) : null;
														$alarma    = ! empty( $producto['min_stock_alarm'] );
														if ( $stock === null ) {
															$badge_class = 'neutro';
															$badge_text  = 'Sin control de stock';
														} elseif ( $stock <= 0 ) {
															$badge_class = 'sin';
															$badge_text  = 'Sin stock';
														} elseif ( $alarma && $min_stock !== null && $stock <= $min_stock ) {
															$badge_class = 'bajo';
															$badge_text  = 'Stock bajo (' . number_format_i18n( $stock, 0 ) . ')';
														} else {
															$badge_class = 'ok';
															$badge_text  = 'En stock (' . number_format_i18n( $stock, 0 ) . ')';
														}
														?>
														<span class="crm-inst-stock-badge crm-inst-stock-badge-<?php echo esc_attr( $badge_class ); ?>" title="<?php echo esc_attr( ucfirst( $confianza ) . ': ' . $producto['name'] ); ?>"><?php echo esc_html( $badge_text ); ?></span>
														<?php if ( isset( $producto['price'] ) || isset( $producto['cost'] ) ) : ?>
															<br><small class="crm-inst-field-info">Cat.: <?php echo esc_html( $producto['price'] ?? '—' ); ?>€ vta. / <?php echo esc_html( $producto['cost'] ?? '—' ); ?>€ cst.</small>
														<?php endif; ?>
													<?php else : ?>
														<span class="crm-inst-stock-badge crm-inst-stock-badge-neutro">Sin match — a mano</span>
													<?php endif;
												else : ?>
													—
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $m['origen'] === 'holded' ) : ?>
													<input type="checkbox" class="crm-inst-material-recibido" data-material-id="<?php echo esc_attr( $m['id'] ); ?>" <?php checked( $m['estado'], 'recibido' ); ?>>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
								<tfoot>
									<tr class="crm-inst-totales">
										<td colspan="3">Totales</td>
										<td><?php echo esc_html( number_format_i18n( $data['totales']['subtotal_coste'], 2 ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $data['totales']['subtotal_precio'], 2 ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $data['totales']['margen'], 2 ) ); ?></td>
										<td colspan="3"></td>
									</tr>
								</tfoot>
							</table>
						</div>
						<small id="crm-inst-materiales-info" class="crm-inst-field-info"></small>
						<p class="crm-inst-proveedor-row">
							<button type="button" class="crm-btn" id="crm-inst-notificar-proveedor-btn">Notificar proveedor</button>
							<span id="crm-inst-proveedor-msg">
								<?php if ( ! empty( $data['proveedor_notificado_en'] ) ) : ?>
									(último aviso: <?php echo esc_html( $data['proveedor_notificado_en'] ); ?>)
								<?php endif; ?>
							</span>
							<?php if ( $data['proveedor_pedido_estado'] === 'enviado' ) : ?>
								<span class="crm-inst-extra-badge crm-inst-extra-badge-pendiente">Enviado — esperando confirmación</span>
								<button type="button" class="crm-btn" id="crm-inst-proveedor-confirmar-mano-btn">Marcar confirmado a mano</button>
							<?php elseif ( $data['proveedor_pedido_estado'] === 'confirmado' ) : ?>
								<span class="crm-inst-extra-badge crm-inst-extra-badge-aprobado">Confirmado por el proveedor</span>
								<?php if ( ! empty( $data['proveedor_entrega_estimada'] ) ) :
									$entrega_ts = strtotime( $data['proveedor_entrega_estimada'] );
									$visita_ts  = ! empty( $data['agenda']['fecha_cita'] ) ? strtotime( $data['agenda']['fecha_cita'] ) : null;
									$en_riesgo  = $visita_ts && $entrega_ts >= strtotime( date( 'Y-m-d', $visita_ts ) );
								?>
									<span id="crm-inst-riesgo-badge" class="crm-inst-extra-badge crm-inst-extra-badge-<?php echo $en_riesgo ? 'rechazado' : 'aprobado'; ?>">
										Entrega estimada: <?php echo esc_html( date_i18n( 'd/m/Y', $entrega_ts ) ); ?><span id="crm-inst-riesgo-badge-aviso"><?php echo $en_riesgo ? ' — ¡en o después de la visita!' : ''; ?></span>
									</span>
								<?php if ( $en_riesgo ) : ?>
									<span id="crm-inst-riesgo-proveedor-wrap"><a href="#crm-inst-agenda-fecha" class="crm-btn" id="crm-inst-reprogramar-atajo-btn">Reprogramar visita</a></span>
								<?php endif; ?>
								<?php endif; ?>
								<?php if ( ! empty( $data['proveedor_notas'] ) ) : ?>
									<br><small class="crm-inst-field-info">"<?php echo esc_html( $data['proveedor_notas'] ); ?>" — <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $data['proveedor_confirmado_en'] ) ) ); ?></small>
								<?php endif; ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>

				<?php $extras = array_values( array_filter( $data['materiales'], function ( $m ) { return $m['origen'] === 'extra'; } ) ); ?>
				<div class="crm-inst-ficha-section">
					<h4>Partidas extra<?php echo empty( $extras ) ? '' : ' (' . count( $extras ) . ')'; ?></h4>
					<?php if ( empty( $extras ) ) : ?>
						<p>Sin partidas extra declaradas por ningún instalador.</p>
					<?php else : ?>
						<?php $extra_estado_labels_ficha = [ 'pendiente' => 'Pendiente de enviar', 'enviado_cliente' => 'Enviado al cliente', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado' ]; ?>
						<div class="table-responsive-compact">
							<table class="crm-table-compact">
								<thead><tr><th>Descripción</th><th>Horas</th><th>Importe</th><th>Justificación</th><th>Declarado por</th><th>Estado</th><th></th></tr></thead>
								<tbody id="crm-inst-extras-tbody">
									<?php foreach ( $extras as $ex ) : ?>
										<tr data-extra-id="<?php echo esc_attr( $ex['id'] ); ?>">
											<td>
												<?php echo esc_html( $ex['descripcion'] ); ?>
												<?php if ( ! empty( $ex['materiales_usados'] ) ) : ?>
													<br><small class="crm-inst-field-info">Materiales: <?php echo esc_html( $ex['materiales_usados'] ); ?><?php echo ! empty( $ex['holded_product_id'] ) ? ' (enganchado a Holded)' : ''; ?></small>
												<?php endif; ?>
												<?php if ( ! empty( $ex['observaciones'] ) ) : ?>
													<br><small class="crm-inst-field-info">Obs.: <?php echo esc_html( $ex['observaciones'] ); ?></small>
												<?php endif; ?>
											</td>
											<td><?php echo $ex['horas'] !== null ? esc_html( number_format_i18n( $ex['horas'], 2 ) ) : '—'; ?></td>
											<td><?php echo esc_html( number_format_i18n( $ex['importe'], 2 ) ); ?> €</td>
											<td>
												<?php if ( ! empty( $ex['justificacion_ruta'] ) ) : ?>
													<a href="<?php echo esc_url( $ex['justificacion_ruta'] ); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( $ex['justificacion_ruta'] ); ?>" alt="Justificación" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;"></a>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $ex['declarado_por_nombre'] ?: '—' ); ?></td>
											<td>
												<span class="crm-inst-extra-badge crm-inst-extra-badge-<?php echo esc_attr( $ex['estado'] ); ?>"><?php echo esc_html( $extra_estado_labels_ficha[ $ex['estado'] ] ?? ucfirst( $ex['estado'] ) ); ?></span>
												<?php if ( in_array( $ex['estado'], [ 'aprobado', 'rechazado' ], true ) ) : ?>
													<span class="crm-inst-field-info" style="display:block;"><?php echo esc_html( $ex['validado_por_nombre'] ?: 'Validado por el cliente' ); ?><?php echo $ex['validado_en'] ? ' · ' . esc_html( date_i18n( 'd/m/Y', strtotime( $ex['validado_en'] ) ) ) : ''; ?></span>
												<?php elseif ( $ex['estado'] === 'enviado_cliente' && $ex['cliente_notificado_en'] ) : ?>
													<span class="crm-inst-field-info" style="display:block;">Enviado el <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $ex['cliente_notificado_en'] ) ) ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( in_array( $ex['estado'], [ 'pendiente', 'enviado_cliente' ], true ) && current_user_can( 'crm_inst_manage' ) ) : ?>
													<?php if ( $ex['estado'] === 'pendiente' ) : ?>
														<button type="button" class="crm-btn crm-inst-extra-enviar-cliente-btn" data-extra-id="<?php echo esc_attr( $ex['id'] ); ?>">Enviar al cliente</button>
													<?php endif; ?>
												<?php endif; ?>
												<?php if ( in_array( $ex['estado'], [ 'pendiente', 'enviado_cliente' ], true ) && current_user_can( 'crm_inst_validate_extra' ) ) : ?>
													<small class="crm-inst-field-info" style="display:block;">Respaldo manual:</small>
													<button type="button" class="crm-btn crm-inst-extra-validar-btn" data-extra-id="<?php echo esc_attr( $ex['id'] ); ?>" data-decision="aprobar">Aprobar</button>
													<button type="button" class="crm-btn crm-inst-extra-validar-btn" data-extra-id="<?php echo esc_attr( $ex['id'] ); ?>" data-decision="rechazar">Rechazar</button>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<small class="crm-inst-field-info">Solo las partidas <strong>aprobadas</strong> cuentan en los totales de materiales de arriba. Las aprueba el cliente por email; "Respaldo manual" es solo para cuando el cliente no responde por ese canal.</small>
					<?php endif; ?>
				</div>

				<div class="crm-inst-ficha-section">
					<h4>Facturación y margen</h4>
					<p class="crm-inst-field-info">
						Margen calculado (materiales + extras aprobadas):
						<strong><?php echo esc_html( number_format_i18n( $data['totales']['margen'], 2 ) ); ?> €</strong>
						<span id="crm-inst-margen-badge" class="crm-inst-extra-badge crm-inst-extra-badge-neutro">Provisional — pendiente de los dos checks de abajo</span>
					</p>

					<div class="crm-inst-facturacion-check">
						<strong>Cliente → Ecovolt</strong>
						<span id="crm-inst-pago-cliente-estado" class="crm-inst-field-info">
							<?php if ( ! empty( $data['holded_invoice_id'] ) ) : ?>
								Factura <?php echo esc_html( $data['holded_invoice_id'] ); ?> — pulsa "Comprobar" para ver el estado.
							<?php else : ?>
								Todavía no se ha buscado la factura de este presupuesto en Holded.
							<?php endif; ?>
						</span>
						<button type="button" class="crm-btn" id="crm-inst-comprobar-pago-cliente-btn">Comprobar pago del cliente</button>
					</div>

					<div class="crm-inst-facturacion-check">
						<strong>Ecovolt → Proveedor</strong>
						<?php if ( empty( $data['holded_purchase_id'] ) ) : ?>
							<p class="crm-inst-field-info">Todavía no se ha vinculado ninguna compra de proveedor a esta instalación.</p>
							<div class="crm-inst-compra-buscador">
								<input type="text" id="crm-inst-buscar-compra-input" placeholder="Buscar por proveedor o nº de documento…">
								<button type="button" class="crm-btn" id="crm-inst-buscar-compra-btn">Buscar</button>
							</div>
							<div id="crm-inst-compra-resultados"></div>
						<?php else : ?>
							<span id="crm-inst-pago-proveedor-estado" class="crm-inst-field-info">Compra <?php echo esc_html( $data['holded_purchase_id'] ); ?> — pulsa "Comprobar" para ver el estado.</span>
							<button type="button" class="crm-btn" id="crm-inst-comprobar-pago-proveedor-btn">Comprobar pago al proveedor</button>
							<button type="button" class="crm-btn" id="crm-inst-desvincular-compra-btn" style="background:#fee2e2;color:#991b1b;">Quitar vínculo</button>
						<?php endif; ?>
					</div>
				</div>

				<div class="crm-inst-ficha-section">
					<h4>Instaladores asignados</h4>
					<div id="crm-inst-instaladores-lista">
						<?php if ( empty( $data['instaladores'] ) ) : ?>
							<p id="crm-inst-sin-instaladores">Todavía no hay ningún instalador asignado.</p>
						<?php else : ?>
							<?php foreach ( $data['instaladores'] as $inst_asig ) : ?>
								<div class="crm-inst-instalador-row" data-user-id="<?php echo esc_attr( $inst_asig['user_id'] ); ?>" data-display-name="<?php echo esc_attr( $inst_asig['display_name'] ); ?>">
									<span><?php echo esc_html( $inst_asig['display_name'] ); ?></span>
									<button type="button" class="crm-btn crm-inst-quitar-instalador-btn" data-user-id="<?php echo esc_attr( $inst_asig['user_id'] ); ?>">Quitar</button>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<?php if ( empty( $instaladores_disponibles ) && empty( $data['instaladores'] ) ) : ?>
						<p style="color:#92400e;">Aún no hay ningún usuario con el rol <code>Instalador</code>. Crea uno desde Usuarios en wp-admin para poder asignarlo aquí.</p>
					<?php elseif ( ! empty( $instaladores_disponibles ) ) : ?>
						<p>
							<select id="crm-inst-select-instalador">
								<?php foreach ( $instaladores_disponibles as $u ) : ?>
									<option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="crm-btn" id="crm-inst-asignar-instalador-btn">Asignar</button>
							<span id="crm-inst-instalador-msg"></span>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $data['instaladores'] ) ) : ?>
					<div class="crm-inst-ficha-section">
						<h4>Agenda de la visita</h4>
						<?php if ( ! empty( $data['agenda'] ) ) : ?>
							<p class="crm-inst-field-info" style="display:inline;">Programada para <strong id="crm-inst-agenda-fecha-actual"><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $data['agenda']['fecha_cita'] ) ) ); ?></strong> (<?php echo esc_html( crm_instalaciones_estados_agenda()[ $data['agenda']['estado'] ] ?? $data['agenda']['estado'] ); ?>).</p>
						<?php else : ?>
							<p class="crm-inst-field-info" style="display:inline;">Todavía sin fecha de visita asignada.</p>
						<?php endif; ?>
						<p>
							<select id="crm-inst-agenda-instalador">
								<?php foreach ( $data['instaladores'] as $inst_asig ) : ?>
									<option value="<?php echo esc_attr( $inst_asig['user_id'] ); ?>" <?php selected( ! empty( $data['agenda'] ) && (int) $data['agenda']['instalador_id'] === (int) $inst_asig['user_id'] ); ?>><?php echo esc_html( $inst_asig['display_name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="datetime-local" id="crm-inst-agenda-fecha" value="<?php echo esc_attr( ! empty( $data['agenda'] ) ? str_replace( ' ', 'T', substr( $data['agenda']['fecha_cita'], 0, 16 ) ) : '' ); ?>">
							<button type="button" class="crm-btn" id="crm-inst-guardar-agenda-btn"><?php echo empty( $data['agenda'] ) ? 'Programar visita' : 'Reprogramar'; ?></button>
							<span id="crm-inst-agenda-msg"></span>
						</p>
					</div>
				<?php endif; ?>

				<div class="crm-inst-ficha-section">
					<h4>Proyecto de Holded</h4>
					<p class="crm-inst-field-info">Se usa para etiquetar el albarán de salida de materiales (abajo) con el proyecto correspondiente en Holded.</p>
					<?php if ( empty( $data['holded_project_id'] ) ) : ?>
						<p class="crm-inst-field-info">Todavía no se ha vinculado ningún proyecto de Holded a esta instalación.</p>
						<div class="crm-inst-compra-buscador">
							<input type="text" id="crm-inst-buscar-proyecto-input" placeholder="Buscar por nombre de proyecto o cliente…">
							<button type="button" class="crm-btn" id="crm-inst-buscar-proyecto-btn">Buscar</button>
						</div>
						<div id="crm-inst-proyecto-resultados"></div>
					<?php else : ?>
						<span class="crm-inst-field-info">Proyecto <?php echo esc_html( $data['holded_project_id'] ); ?> vinculado.</span>
						<button type="button" class="crm-btn" id="crm-inst-desvincular-proyecto-btn" style="background:#fee2e2;color:#991b1b;">Quitar vínculo</button>
					<?php endif; ?>
				</div>

				<div class="crm-inst-ficha-section">
					<h4>Cierre de la instalación</h4>
					<p class="crm-inst-field-info">
						Checklist previo (materiales + plan de seguridad):
						<?php if ( ! empty( $data['checklist_confirmado_en'] ) ) : ?>
							<span class="crm-inst-extra-badge crm-inst-extra-badge-aprobado">Confirmado</span> el <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $data['checklist_confirmado_en'] ) ) ); ?>
						<?php else : ?>
							<span class="crm-inst-extra-badge crm-inst-extra-badge-pendiente">Pendiente</span> — el instalador todavía no lo ha confirmado.
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $data['checklist_confirmado_en'] ) ) : ?>
						<p class="crm-inst-field-info">
							Albarán de salida en Holded al confirmar:
							<?php if ( empty( $data['holded_waybill_id'] ) && empty( $data['holded_waybill_error'] ) ) : ?>
								<span class="crm-inst-extra-badge crm-inst-extra-badge-neutro">No se generó</span> — ninguna línea de esta instalación está enganchada a un producto real de Holded (columna "Stock" con match por nombre no cuenta, solo por ID).
							<?php elseif ( ! empty( $data['holded_waybill_id'] ) && $data['holded_waybill_aprobado'] ) : ?>
								<span class="crm-inst-extra-badge crm-inst-extra-badge-aprobado">Albarán #<?php echo esc_html( $data['holded_waybill_numero'] ); ?> generado y aprobado</span>
							<?php elseif ( ! empty( $data['holded_waybill_id'] ) ) : ?>
								<span class="crm-inst-extra-badge crm-inst-extra-badge-pendiente">Albarán #<?php echo esc_html( $data['holded_waybill_numero'] ); ?> creado, sin aprobar</span>
								<br><small class="crm-inst-field-info"><?php echo esc_html( $data['holded_waybill_error'] ); ?></small>
								<br><button type="button" class="crm-btn" id="crm-inst-aprobar-albaran-btn">Reintentar aprobación</button>
							<?php else : ?>
								<span class="crm-inst-extra-badge crm-inst-extra-badge-rechazado">No se pudo generar</span>
								<br><small class="crm-inst-field-info"><?php echo esc_html( $data['holded_waybill_error'] ); ?></small>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $data['cierre']['estado'] === '' ) : ?>
						<p>El instalador todavía no ha declarado el cierre.</p>
					<?php else : ?>
						<p>
							<span class="crm-inst-extra-badge crm-inst-extra-badge-<?php echo esc_attr( $data['cierre']['estado'] === 'declarado' ? 'pendiente' : $data['cierre']['estado'] ); ?>"><?php echo esc_html( $data['cierre']['estado'] === 'declarado' ? 'Pendiente' : ucfirst( $data['cierre']['estado'] ) ); ?></span>
							<?php if ( $data['cierre']['declarado_por_nombre'] ) : ?>
								— declarado por <?php echo esc_html( $data['cierre']['declarado_por_nombre'] ); ?><?php echo $data['cierre']['declarado_en'] ? ' el ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $data['cierre']['declarado_en'] ) ) ) : ''; ?>
							<?php endif; ?>
						</p>
						<?php if ( ! empty( $data['cierre']['observaciones'] ) ) : ?>
							<p class="crm-inst-field-info">Observaciones: <?php echo esc_html( $data['cierre']['observaciones'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $data['cierre']['fotos'] ) ) :
							$categorias_labels = crm_inst_categorias_fotos_cierre( $data['subtipo_instalacion'] );
						?>
							<div class="crm-inst-cierre-fotos">
								<?php foreach ( $data['cierre']['fotos'] as $foto ) :
									$cat_label = ! empty( $foto['categoria'] ) ? ( $categorias_labels[ $foto['categoria'] ] ?? ucfirst( str_replace( '_', ' ', $foto['categoria'] ) ) ) : '';
								?>
									<a href="<?php echo esc_url( $foto['ruta'] ); ?>" target="_blank" rel="noopener noreferrer" class="crm-inst-cierre-foto-item">
										<img src="<?php echo esc_url( $foto['ruta'] ); ?>" alt="<?php echo esc_attr( $cat_label ?: 'Foto del cierre' ); ?>" loading="lazy">
										<?php if ( $cat_label !== '' ) : ?><span><?php echo esc_html( $cat_label ); ?></span><?php endif; ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if ( $data['cierre']['estado'] === 'declarado' && current_user_can( 'crm_inst_close' ) ) : ?>
							<p>
								<button type="button" class="crm-btn crm-inst-cierre-validar-btn" data-decision="aprobar">Aprobar cierre</button>
								<button type="button" class="crm-btn crm-inst-cierre-validar-btn" data-decision="rechazar">Rechazar cierre</button>
								<span id="crm-inst-cierre-msg"></span>
							</p>
						<?php endif; ?>
						<?php if ( $data['cierre']['validado_por_nombre'] ) : ?>
							<p class="crm-inst-field-info">Validado por <?php echo esc_html( $data['cierre']['validado_por_nombre'] ); ?><?php echo $data['cierre']['validado_en'] ? ' el ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $data['cierre']['validado_en'] ) ) ) : ''; ?>.</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<div class="crm-inst-ficha-section">
					<h4>Notificaciones</h4>
					<p style="color:#6b7280;font-size:13px;">Asignación de instalador, agenda, partidas extra y cierre ya avisan por notificación in-app (campana arriba a la derecha). El aviso al cliente por WhatsApp/email (Fase 7 y 8 del plan) sigue pendiente de configurar credenciales:</p>
					<div class="crm-inst-timeline">
						<div class="crm-inst-timeline-step">Cliente notificado (lista)</div>
						<div class="crm-inst-timeline-step">Reconfirmación día antes</div>
						<div class="crm-inst-timeline-step">Encuesta de satisfacción</div>
					</div>
				</div>

				<div class="crm-inst-ficha-section">
					<h4>Actividad</h4>
					<?php if ( empty( $data['log'] ) ) : ?>
						<p>Sin actividad registrada todavía.</p>
					<?php else : ?>
						<?php foreach ( $data['log'] as $entry ) : ?>
							<div class="crm-inst-log-item">
								<strong><?php echo esc_html( $entry['display_name'] ?: 'Sistema' ); ?></strong>
								— <?php echo esc_html( $entry['detalle'] ); ?>
								<span style="float:right;"><?php echo esc_html( $entry['fecha'] ); ?></span>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

			</div>
		</div>
	</div>

	<?php if ( $data['geocode_status'] === 'ok' && $data['lat'] !== null ) : ?>
		<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
		<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
	<?php endif; ?>

	<script>
	(function ($) {
		var ajaxurl        = <?php echo wp_json_encode( $ajax_url ); ?>;
		var nonce          = <?php echo wp_json_encode( $nonce ); ?>;
		var instalacionId  = <?php echo (int) $data['id']; ?>;
		var estadosLabels  = <?php echo wp_json_encode( $estados ); ?>;
		var estadoActual   = <?php echo wp_json_encode( $data['estado'] ); ?>;

		<?php echo crm_inst_js_escape_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		// v1.20.84: los botones de esta ficha usan $.post(url, datos, callback)
		// — esa forma corta SOLO llama al callback cuando la petición responde
		// 2xx. Un 403 (p.ej. "Sin permisos" o nonce caducado tras tener la
		// página abierta mucho rato) o un error 500 no disparaban el callback
		// ni ningún aviso: el botón parecía "no hacer nada". Este manejador
		// global captura cualquier fallo AJAX de la página y muestra el motivo,
		// en vez de fallar en silencio.
		$(document).ajaxError(function (event, jqXHR) {
			var msg = 'Error de conexión.';
			try {
				var data = JSON.parse(jqXHR.responseText);
				if (data && data.data && data.data.message) {
					msg = data.data.message;
				} else if (jqXHR.status) {
					msg = 'Error ' + jqXHR.status + ' al procesar la petición.';
				}
			} catch (e) {
				if (jqXHR.status) { msg = 'Error ' + jqXHR.status + ' al procesar la petición.'; }
			}
			if (jqXHR.status === 403) {
				msg += ' Si llevas la página abierta mucho rato, recárgala e inténtalo de nuevo (puede que la sesión de seguridad haya caducado).';
			}
			alert(msg);
		});

		// Textos de ayuda: qué significa cada estado, para que el selector no
		// sea una caja negra — sobre todo ahora que "lista" y "pendiente" se
		// mueven solos según los materiales (crm_inst_sync_estado_con_materiales).
		var estadoInfoTexts = {
			borrador:     'Instalación recién creada, todavía sin materiales cargados o sin revisar.',
			pendiente:    'Esperando algo antes de poder planificarse (normalmente, que lleguen materiales).',
			planificada:  'Ya tiene instalador y visita planificada, pendiente de ejecutarse.',
			en_ejecucion: 'El instalador está trabajando en ella ahora mismo.',
			bloqueada:    'Algo la está deteniendo — revisa el motivo con quien la gestione.',
			lista:        'Todos los materiales están recibidos. Si desmarcas uno, vuelve sola a "Pendiente".',
			finalizada:   'Instalación completada.',
			reagendada:   'El cliente pidió otra fecha para la visita.',
			cancelada:    'Instalación cancelada, no continúa.'
		};

		function actualizarInfoEstado(estado) {
			$('#crm-inst-estado-info').text(estadoInfoTexts[estado] || '');
		}
		actualizarInfoEstado(estadoActual);
		$('#crm-inst-select-estado').on('change', function () {
			actualizarInfoEstado($(this).val());
		});

		function actualizarInfoMateriales() {
			var total = $('.crm-inst-material-recibido').length;
			if (total === 0) {
				$('#crm-inst-materiales-info').text('');
				return;
			}
			var pendientes = $('.crm-inst-material-recibido:not(:checked)').length;
			$('#crm-inst-materiales-info').text(
				pendientes === 0
					? 'Todos los materiales de Holded están recibidos.'
					: pendientes + ' de ' + total + ' material(es) todavía sin recibir.'
			);
			// v1.20.73: el aviso de riesgo por retraso del proveedor y el atajo
			// de reprogramar dejan de tener sentido en cuanto ya se ha marcado
			// todo como recibido (el material ya está aquí) — antes se quedaban
			// visibles hasta recargar la página porque nada los refrescaba. Solo
			// actuamos al llegar a 0 pendientes: no intentamos "adivinar" el
			// riesgo original si luego se desmarca algo, para no mostrar un
			// aviso inventado — en ese caso basta con recargar la página.
			if (pendientes === 0) {
				$('#crm-inst-riesgo-proveedor-wrap').hide();
				$('#crm-inst-riesgo-badge').removeClass('crm-inst-extra-badge-rechazado').addClass('crm-inst-extra-badge-aprobado');
				$('#crm-inst-riesgo-badge-aviso').text('');
			}
		}
		actualizarInfoMateriales();

		var mapaEl = document.getElementById('crm-inst-mapa');
		if (mapaEl && window.L) {
			var lat = parseFloat(mapaEl.dataset.lat);
			var lng = parseFloat(mapaEl.dataset.lng);
			// Solo visual: sin arrastre/zoom, para que se vea como una imagen fija.
			// La atribución de OSM se mantiene (la exige su política de uso) pero
			// minimizada por CSS en vez del recuadro doble por defecto de Leaflet.
			var map = L.map('crm-inst-mapa', {
				center: [lat, lng],
				zoom: 16,
				dragging: false,
				zoomControl: false,
				scrollWheelZoom: false,
				doubleClickZoom: false,
				boxZoom: false,
				touchZoom: false,
				keyboard: false,
				attributionControl: true
			});
			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution: '© OpenStreetMap',
				maxZoom: 19
			}).addTo(map);
			L.marker([lat, lng]).addTo(map);
		}

		$('#crm-inst-guardar-estado-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var estado = $('#crm-inst-select-estado').val();
			$('#crm-inst-estado-msg').text('');
			$.post(ajaxurl, { action: 'crm_inst_cambiar_estado', nonce: nonce, instalacion_id: instalacionId, estado: estado }, function (resp) {
				btn.prop('disabled', false);
				if (!resp.success) {
					// El intento se rechazó — el selector vuelve a mostrar lo que
					// hay guardado de verdad, no lo que se intentó poner.
					$('#crm-inst-select-estado').val(estadoActual);
					actualizarInfoEstado(estadoActual);
					$('#crm-inst-estado-msg').css('color', '#991b1b').text(resp.data.message);
					return;
				}
				estadoActual = resp.data.estado;
				$('#crm-inst-estado-badge').attr('class', 'status-badge status-inst-' + resp.data.estado).text(resp.data.estado_label);
				actualizarInfoEstado(resp.data.estado);
				$('#crm-inst-estado-msg').css('color', '#065f46').text('Guardado.');
			});
		});

		$(document).on('change', '.crm-inst-material-recibido', function () {
			var checkbox = $(this);
			var materialId = checkbox.data('material-id');
			checkbox.prop('disabled', true);
			$.post(ajaxurl, {
				action: 'crm_inst_marcar_material', nonce: nonce,
				material_id: materialId, recibido: checkbox.is(':checked') ? '1' : 'false'
			}, function (resp) {
				checkbox.prop('disabled', false);
				if (!resp.success) {
					checkbox.prop('checked', !checkbox.is(':checked'));
					alert(resp.data.message);
					return;
				}
				actualizarInfoMateriales();
				if (resp.data.instalacion_estado) {
					estadoActual = resp.data.instalacion_estado;
					$('#crm-inst-estado-badge').attr('class', 'status-badge status-inst-' + resp.data.instalacion_estado).text(resp.data.instalacion_estado_label);
					$('#crm-inst-select-estado').val(resp.data.instalacion_estado);
					actualizarInfoEstado(resp.data.instalacion_estado);
				}
				if (resp.data.holded_stock_sync === true) {
					$('#crm-inst-materiales-info').css('color', '#065f46').text('Stock actualizado en Holded.');
				} else if (resp.data.holded_stock_sync === false) {
					$('#crm-inst-materiales-info').css('color', '#991b1b').text('No se pudo actualizar el stock en Holded (revisa el log).');
				}
			});
		});

		$('#crm-inst-notificar-proveedor-btn').on('click', function () {
			var btn = $(this).prop('disabled', true).text('Enviando…');
			$.post(ajaxurl, { action: 'crm_inst_notificar_proveedor', nonce: nonce, instalacion_id: instalacionId }, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false).text('Notificar proveedor');
					$('#crm-inst-proveedor-msg').css('color', '#991b1b').text(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		$('#crm-inst-proveedor-confirmar-mano-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			$.post(ajaxurl, { action: 'crm_inst_marcar_proveedor_confirmado', nonce: nonce, instalacion_id: instalacionId }, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false);
					alert(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		$('#crm-inst-reprogramar-atajo-btn').on('click', function () {
			setTimeout(function () { $('#crm-inst-agenda-fecha').trigger('focus'); }, 300);
		});

		$('#crm-inst-guardar-agenda-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var instaladorId = $('#crm-inst-agenda-instalador').val();
			var fecha = $('#crm-inst-agenda-fecha').val();
			$('#crm-inst-agenda-msg').text('');
			$.post(ajaxurl, {
				action: 'crm_inst_guardar_agenda', nonce: nonce, instalacion_id: instalacionId,
				instalador_id: instaladorId, fecha_cita: fecha
			}, function (resp) {
				btn.prop('disabled', false);
				if (!resp.success) {
					$('#crm-inst-agenda-msg').css('color', '#991b1b').text(resp.data.message);
					return;
				}
				$('#crm-inst-agenda-msg').css('color', '#065f46').text('Guardado: ' + resp.data.fecha_cita_label);
				btn.text('Reprogramar');
			});
		});

		$('#crm-inst-guardar-direccion-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var direccion = $.trim($('#crm-inst-direccion-input').val());
			$('#crm-inst-direccion-msg').css('color', '#991b1b').text('');
			if (direccion === '') {
				btn.prop('disabled', false);
				$('#crm-inst-direccion-msg').css('color', '#991b1b').text('Escribe una dirección.');
				return;
			}
			$.post(ajaxurl, {
				action: 'crm_inst_actualizar_direccion', nonce: nonce,
				instalacion_id: instalacionId, direccion: direccion
			}, function (resp) {
				btn.prop('disabled', false);
				if (!resp.success) {
					$('#crm-inst-direccion-msg').css('color', '#991b1b').text(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		$('#crm-inst-tipo-select').on('change', function () {
			$('#crm-inst-subtipo-checks').toggle($(this).val() === 'residencial');
		});
		$('#crm-inst-guardar-tipo-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var subtiposElegidos = $('.crm-inst-subtipo-checkbox:checked').map(function () { return this.value; }).get();
			$.post(ajaxurl, {
				action: 'crm_inst_actualizar_tipo', nonce: nonce, instalacion_id: instalacionId,
				tipo_instalacion: $('#crm-inst-tipo-select').val(),
				subtipo_instalacion: subtiposElegidos.join(',')
			}, function (resp) {
				btn.prop('disabled', false);
				if (!resp.success) {
					$('#crm-inst-tipo-msg').css('color', '#991b1b').text(resp.data.message);
					return;
				}
				$('#crm-inst-tipo-msg').css('color', '#065f46').text('Guardado.');
			});
		});

		$('.crm-inst-extra-enviar-cliente-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			$.post(ajaxurl, {
				action: 'crm_inst_enviar_extra_cliente', nonce: nonce,
				trabajo_id: btn.data('extra-id')
			}, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false);
					alert(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		// Fase 6 — doble check de facturación. Los dos estados se guardan aquí
		// para poder marcar el margen como "confirmado" en cuanto ambos lados
		// estén completed, sin depender del orden en que se comprueben.
		var estadoPagoCliente = null;
		var estadoPagoProveedor = <?php echo empty( $data['holded_purchase_id'] ) ? 'null' : '\'\''; ?>;
		function actualizarBadgeMargen() {
			var badge = $('#crm-inst-margen-badge');
			if (estadoPagoCliente === 'completed' && estadoPagoProveedor === 'completed') {
				badge.attr('class', 'crm-inst-extra-badge crm-inst-extra-badge-aprobado').text('Confirmado — cliente y proveedor liquidados');
			} else {
				badge.attr('class', 'crm-inst-extra-badge crm-inst-extra-badge-neutro').text('Provisional — pendiente de los dos checks de abajo');
			}
		}

		$('#crm-inst-comprobar-pago-cliente-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var out = $('#crm-inst-pago-cliente-estado');
			out.text('Comprobando…');
			$.post(ajaxurl, { action: 'crm_inst_comprobar_pago_cliente', nonce: nonce, instalacion_id: instalacionId }, function (resp) {
				btn.prop('disabled', false);
				if (!resp.success) {
					out.text(resp.data.message);
					return;
				}
				estadoPagoCliente = resp.data.status;
				out.text('Factura ' + (resp.data.document_number || resp.data.invoice_id) + ' — ' + resp.data.status + ' (pendiente: ' + resp.data.payments_pending + ' €)');
				actualizarBadgeMargen();
			});
		});

		$('#crm-inst-comprobar-pago-proveedor-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var out = $('#crm-inst-pago-proveedor-estado');
			out.text('Comprobando…');
			$.post(ajaxurl, { action: 'crm_inst_comprobar_pago_proveedor', nonce: nonce, instalacion_id: instalacionId }, function (resp) {
				btn.prop('disabled', false);
				if (!resp.success) {
					out.text(resp.data.message);
					return;
				}
				estadoPagoProveedor = resp.data.status;
				out.text('Compra ' + (resp.data.document_number || resp.data.purchase_id) + ' — ' + resp.data.status + ' (pendiente: ' + resp.data.payments_pending + ' €)');
				actualizarBadgeMargen();
			});
		});

		$('#crm-inst-desvincular-compra-btn').on('click', function () {
			if (!confirm('¿Quitar el vínculo con esta compra de proveedor?')) { return; }
			$.post(ajaxurl, { action: 'crm_inst_vincular_compra_proveedor', nonce: nonce, instalacion_id: instalacionId, purchase_id: '' }, function (resp) {
				if (!resp.success) { alert(resp.data.message); return; }
				location.reload();
			});
		});

		$('#crm-inst-buscar-compra-btn').on('click', function () {
			var q = $('#crm-inst-buscar-compra-input').val();
			var cont = $('#crm-inst-compra-resultados').html('Buscando…');
			$.post(ajaxurl, { action: 'crm_inst_buscar_compra_holded', nonce: nonce, q: q }, function (resp) {
				if (!resp.success) { cont.text(resp.data.message); return; }
				if (!resp.data.items.length) { cont.text('Sin resultados.'); return; }
				var html = '';
				resp.data.items.forEach(function (it) {
					html += '<div class="crm-inst-compra-resultado-item">' +
						'<span>' + escapeHtml(it.document_number || '(sin nº)') + ' — ' + escapeHtml(it.contact_name || '') + ' — ' + escapeHtml(it.total) + ' € (' + escapeHtml(it.status) + ')</span>' +
						'<button type="button" class="crm-btn crm-inst-vincular-compra-btn" data-purchase-id="' + escapeHtml(it.id) + '">Vincular</button>' +
						'</div>';
				});
				cont.html(html);
			});
		});

		$(document).on('click', '.crm-inst-vincular-compra-btn', function () {
			var purchaseId = $(this).data('purchase-id');
			$.post(ajaxurl, { action: 'crm_inst_vincular_compra_proveedor', nonce: nonce, instalacion_id: instalacionId, purchase_id: purchaseId }, function (resp) {
				if (!resp.success) { alert(resp.data.message); return; }
				location.reload();
			});
		});

		$('#crm-inst-desvincular-proyecto-btn').on('click', function () {
			if (!confirm('¿Quitar el vínculo con este proyecto de Holded?')) { return; }
			$.post(ajaxurl, { action: 'crm_inst_vincular_proyecto', nonce: nonce, instalacion_id: instalacionId, project_id: '' }, function (resp) {
				if (!resp.success) { alert(resp.data.message); return; }
				location.reload();
			});
		});

		$('#crm-inst-buscar-proyecto-btn').on('click', function () {
			var q = $('#crm-inst-buscar-proyecto-input').val();
			var cont = $('#crm-inst-proyecto-resultados').html('Buscando…');
			$.post(ajaxurl, { action: 'crm_inst_buscar_proyecto_holded', nonce: nonce, q: q }, function (resp) {
				if (!resp.success) { cont.text(resp.data.message); return; }
				if (!resp.data.items.length) { cont.text('Sin resultados.'); return; }
				var html = '';
				resp.data.items.forEach(function (it) {
					html += '<div class="crm-inst-compra-resultado-item">' +
						'<span>' + escapeHtml(it.name || '(sin nombre)') + ' — ' + escapeHtml(it.contact_name || '') + '</span>' +
						'<button type="button" class="crm-btn crm-inst-vincular-proyecto-btn" data-project-id="' + escapeHtml(it.id) + '">Vincular</button>' +
						'</div>';
				});
				cont.html(html);
			});
		});

		$(document).on('click', '.crm-inst-vincular-proyecto-btn', function () {
			var projectId = $(this).data('project-id');
			$.post(ajaxurl, { action: 'crm_inst_vincular_proyecto', nonce: nonce, instalacion_id: instalacionId, project_id: projectId }, function (resp) {
				if (!resp.success) { alert(resp.data.message); return; }
				location.reload();
			});
		});

		$('#crm-inst-aprobar-albaran-btn').on('click', function () {
			var btn = $(this).prop('disabled', true).text('Aprobando…');
			$.post(ajaxurl, { action: 'crm_inst_aprobar_albaran_holded', nonce: nonce, instalacion_id: instalacionId }, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false).text('Reintentar aprobación');
					alert(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		$('.crm-inst-extra-validar-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			$.post(ajaxurl, {
				action: 'crm_inst_validar_extra', nonce: nonce,
				trabajo_id: btn.data('extra-id'), decision: btn.data('decision')
			}, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false);
					alert(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		$('.crm-inst-cierre-validar-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			$.post(ajaxurl, {
				action: 'crm_inst_validar_cierre', nonce: nonce,
				instalacion_id: instalacionId, decision: btn.data('decision')
			}, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false);
					alert(resp.data.message);
					return;
				}
				location.reload();
			});
		});

		$('#crm-inst-asignar-instalador-btn').on('click', function () {
			var btn = $(this).prop('disabled', true);
			var userId = $('#crm-inst-select-instalador').val();
			$.post(ajaxurl, { action: 'crm_inst_asignar_instalador', nonce: nonce, instalacion_id: instalacionId, user_id: userId }, function (resp) {
				if (!resp.success) {
					btn.prop('disabled', false);
					$('#crm-inst-instalador-msg').css('color', '#991b1b').text(resp.data.message);
					return;
				}
				// Recarga completa: si es el primer instalador asignado, la
				// seccion "Agenda de la visita" no existe todavia en el DOM
				// (solo se renderiza en PHP cuando ya hay instaladores) y no
				// apareceria con un simple parche de la lista.
				location.reload();
			});
		});

		$(document).on('click', '.crm-inst-quitar-instalador-btn', function () {
			var row = $(this).closest('.crm-inst-instalador-row');
			var userId = $(this).data('user-id');
			var nombre = row.data('display-name');
			$.post(ajaxurl, { action: 'crm_inst_quitar_instalador', nonce: nonce, instalacion_id: instalacionId, user_id: userId }, function (resp) {
				if (!resp.success) {
					alert(resp.data.message);
					return;
				}
				row.remove();
				if (!$('#crm-inst-instaladores-lista .crm-inst-instalador-row').length) {
					$('#crm-inst-instaladores-lista').html('<p id="crm-inst-sin-instaladores">Todavía no hay ningún instalador asignado.</p>');
				}
				if ($('#crm-inst-select-instalador').length) {
					$('#crm-inst-select-instalador').append('<option value="' + userId + '">' + escapeHtml(nombre) + '</option>');
				}
			});
		});
	})(jQuery);
	</script>
	<?php
	return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// Fase 2 — Shortcode [crm_inst_nueva_desde_presupuesto]
// ──────────────────────────────────────────────────────────────────────────────

add_shortcode( 'crm_inst_nueva_desde_presupuesto', 'crm_inst_shortcode_nueva_desde_presupuesto' );
function crm_inst_shortcode_nueva_desde_presupuesto() {
	if ( ! is_user_logged_in() ) {
		return '<p>Necesitas <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">iniciar sesión</a> para acceder.</p>';
	}
	if ( ! crm_inst_current_user_can_manage() ) {
		return '<p>No tienes permisos para crear instalaciones.</p>';
	}

	$nonce      = wp_create_nonce( 'crm_inst_holded' );
	$ajax_url   = admin_url( 'admin-ajax.php' );
	$tipos      = crm_instalaciones_tipos();
	$subtipos   = crm_instalaciones_subtipos();
	$listado_url = home_url( '/instalaciones/' );
	$ficha_url  = home_url( '/instalacion/' );

	ob_start();
	?>
	<style>
	<?php echo crm_inst_modal_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	.crm-inst-holded-wrap { max-width:900px; margin:0 auto; padding:18px; font-family:system-ui,-apple-system,sans-serif; }
	.crm-inst-holded-wrap .widget-header-compact { flex-wrap:wrap; gap:10px; }
	.crm-inst-holded-wrap .crm-inst-search-row { display:flex; gap:8px; margin-bottom:16px; }
	.crm-inst-holded-wrap input[type="text"],
	#crm-inst-modal-content select { padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px; color:#374151; }
	.crm-inst-holded-wrap .crm-inst-search-row input[type="text"] { flex:1; }
	</style>
	<div class="crm-inst-holded-wrap">
		<?php crm_inst_render_breadcrumb( [
			[ 'label' => 'Instalaciones', 'url' => $listado_url ],
			[ 'label' => 'Nueva instalación' ],
		] ); ?>
		<div class="crm-widget-compact">
			<div class="widget-header-compact">
				<h3 class="widget-title-compact">Nueva instalación desde presupuesto</h3>
			</div>
			<div class="widget-content-compact">
				<div class="crm-inst-search-row">
					<input type="text" id="crm-inst-buscar-input" placeholder="Nombre del cliente o nº de presupuesto…">
					<button type="button" id="crm-inst-buscar-btn" class="crm-btn">Buscar</button>
				</div>
				<div id="crm-inst-resultados"></div>
			</div>
		</div>
	</div>

	<?php crm_inst_render_modal_html(); ?>

	<script>
	(function ($) {
		var ajaxurl  = <?php echo wp_json_encode( $ajax_url ); ?>;
		var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
		var tipos    = <?php echo wp_json_encode( $tipos ); ?>;
		var subtipos = <?php echo wp_json_encode( $subtipos ); ?>;
		var fichaUrl = <?php echo wp_json_encode( $ficha_url ); ?>;

		<?php echo crm_inst_js_escape_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo crm_inst_modal_js_open_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		function buscar() {
			var query = $('#crm-inst-buscar-input').val();
			$('#crm-inst-resultados').html('<p>Buscando…</p>');
			closeModal();
			$.post(ajaxurl, { action: 'crm_inst_buscar_presupuestos', nonce: nonce, query: query }, function (resp) {
				if (!resp.success) {
					$('#crm-inst-resultados').html('<p style="color:#991b1b;">' + escapeHtml(resp.data.message) + '</p>');
					return;
				}
				if (!resp.data.items.length) {
					$('#crm-inst-resultados').html('<p>Sin resultados.</p>');
					return;
				}
				var rows = resp.data.items.map(function (it) {
					var accion = it.instalacion_id
						? '<span class="status-badge status-instalacion-creada">Instalación #' + it.instalacion_id + '</span> <a href="' + fichaUrl + '?id=' + it.instalacion_id + '" class="crm-btn">Ver</a>'
						: '<button type="button" class="crm-btn crm-inst-ver-btn">Ver / crear</button>';
					return '<tr data-id="' + escapeHtml(it.id) + '">' +
						'<td>' + escapeHtml(it.document_number) + '</td>' +
						'<td>' + escapeHtml(it.contact_name) + '</td>' +
						'<td>' + escapeHtml(it.date) + '</td>' +
						'<td>' + escapeHtml(it.total) + ' ' + escapeHtml(it.currency) + '</td>' +
						'<td>' + accion + '</td>' +
						'</tr>';
				}).join('');
				var aviso = '';
				if (resp.data.total_encontrados > resp.data.items.length) {
					aviso = '<p style="color:#92400e;">Mostrando ' + resp.data.items.length + ' de ' + resp.data.total_encontrados + ' encontrados — afina la búsqueda para ver el resto.</p>';
				}
				$('#crm-inst-resultados').html(
					aviso + '<div class="table-responsive-compact"><table class="crm-table-compact"><thead><tr><th>Nº</th><th>Cliente</th><th>Fecha</th><th>Total</th><th>Estado</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				);
			});
		}

		function verPresupuesto(estimateId) {
			openModal('<p>Cargando…</p>');
			$.post(ajaxurl, { action: 'crm_inst_ver_presupuesto', nonce: nonce, estimate_id: estimateId }, function (resp) {
				if (!resp.success) {
					openModal('<p style="color:#991b1b;">' + escapeHtml(resp.data.message) + '</p>');
					return;
				}
				var d = resp.data;
				if (d.instalacion_existente) {
					openModal(
						'<p style="color:#92400e;">Ya existe una instalación creada desde este presupuesto.</p>' +
						'<a href="' + fichaUrl + '?id=' + d.instalacion_existente + '" class="crm-btn">Ver instalación #' + d.instalacion_existente + '</a>'
					);
					return;
				}
				if (!d.aprobado) {
					openModal('<p style="color:#991b1b;">Este presupuesto todavía no está aprobado en Holded.</p>');
					return;
				}

				var lineasHtml = d.lineas.map(function (l) {
					return '<li>' + escapeHtml(l.name) + ' — ' + escapeHtml(l.units) + ' ud. x ' + escapeHtml(l.price) + '</li>';
				}).join('');

				var tipoOptions = Object.keys(tipos).map(function (k) {
					return '<option value="' + k + '">' + escapeHtml(tipos[k]) + '</option>';
				}).join('');
				// Checkboxes, no <select> — una instalación puede ser
				// fotovoltaica Y aerotermia a la vez (mismo viaje cubre las dos).
				var subtipoChecks = Object.keys(subtipos).map(function (k) {
					return '<label style="font-weight:400;margin-right:10px;"><input type="checkbox" class="crm-inst-crear-subtipo-checkbox" value="' + k + '"> ' + escapeHtml(subtipos[k]) + '</label>';
				}).join('');

				openModal(
					'<h3 style="margin-top:0;">' + escapeHtml(d.contact_name) + '</h3>' +
					'<p>' + escapeHtml(d.document_number) + ' (' + escapeHtml(d.date) + ')</p>' +
					'<p>Total: ' + escapeHtml(d.total) + ' ' + escapeHtml(d.currency) + ' — Aprobado el ' + escapeHtml(d.approved_at) + '</p>' +
					'<ul>' + lineasHtml + '</ul>' +
					'<p><label>Tipo de instalación<br><select id="crm-inst-tipo">' + tipoOptions + '</select></label></p>' +
					'<p id="crm-inst-subtipo-wrap">Subtipo (puedes marcar los dos)<br>' + subtipoChecks + '</p>' +
					'<button type="button" class="crm-btn" id="crm-inst-crear-btn">Crear instalación</button>' +
					'<div id="crm-inst-crear-resultado" style="margin-top:10px;"></div>'
				);

				function toggleSubtipo() {
					$('#crm-inst-subtipo-wrap').toggle($('#crm-inst-tipo').val() === 'residencial');
				}
				$('#crm-inst-tipo').on('change', toggleSubtipo);
				toggleSubtipo();

				$('#crm-inst-crear-btn').on('click', function () {
					var btn = $(this).prop('disabled', true).text('Creando…');
					var subtiposElegidos = $('.crm-inst-crear-subtipo-checkbox:checked').map(function () { return this.value; }).get();
					$.post(ajaxurl, {
						action: 'crm_inst_crear_desde_presupuesto',
						nonce: nonce,
						estimate_id: estimateId,
						tipo_instalacion: $('#crm-inst-tipo').val(),
						subtipo_instalacion: $('#crm-inst-tipo').val() === 'residencial' ? subtiposElegidos.join(',') : ''
					}, function (resp) {
						if (!resp.success) {
							$('#crm-inst-crear-resultado').html('<p style="color:#991b1b;">' + escapeHtml(resp.data.message) + '</p>');
							btn.prop('disabled', false).text('Crear instalación');
							return;
						}
						btn.text('Creada').prop('disabled', true);
						$('#crm-inst-crear-resultado').html(
							'<p style="color:#065f46;">Instalación #' + resp.data.instalacion_id + ' creada (cliente #' + resp.data.client_id + ', ' + resp.data.lineas_importadas + ' líneas importadas).</p>' +
							'<a href="' + fichaUrl + '?id=' + resp.data.instalacion_id + '" class="crm-btn">Ver instalación #' + resp.data.instalacion_id + '</a>'
						);
					});
				});
			});
		}

		$('#crm-inst-buscar-btn').on('click', buscar);
		$('#crm-inst-buscar-input').on('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); buscar(); }
		});
		$(document).on('click', '.crm-inst-ver-btn', function () {
			verPresupuesto($(this).closest('tr').data('id'));
		});
	})(jQuery);
	</script>
	<?php
	return ob_get_clean();
}

// ──────────────────────────────────────────────────────────────────────────────
// Diagramas de "Flujos" (wp-admin → CRM → Flujos, ver includes/flujos-page.php)
//
// Se registran aquí, junto al código que describen, para que quien cambie
// el comportamiento del módulo tenga el diagrama a la vista y lo actualice
// en el mismo commit — la idea es que nunca queden desincronizados.
// ──────────────────────────────────────────────────────────────────────────────

add_filter( 'crm_flujos_diagramas', function ( $diagramas ) {

	$diagramas[] = [
		'title'       => 'Instalaciones — roles y capacidades (Fase 1)',
		'description' => 'Quién tiene acceso completo al módulo (crm_inst_manage y demás caps elevadas) y quién solo ve lo suyo. Ver crm_inst_user_is_elevated(). crm_inst_validate_extra ya no es la vía principal para aprobar partidas extra (eso lo hace el cliente por email, Fase 5) — queda como respaldo manual.',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["administrator de WordPress (manage_options)"] --> E
    B["crm_admin puro (sin comercial/visitador)"] --> E
    C["jefe_instalaciones puro (sin instalador/comercial/visitador)"] --> E
    E["Elevado"] --> F["crm_inst_manage, crm_inst_view_all, crm_inst_validate_extra (respaldo manual), crm_inst_close"]
    G["instalador"] --> H["crm_inst_view_own, crm_inst_edit_own (solo sus propias instalaciones, interno de Ecovolt)"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — alta desde presupuesto de Holded (Fase 2)',
		'description' => 'Shortcode [crm_inst_nueva_desde_presupuesto]. crm_inst_crear_desde_presupuesto() vuelve a comprobar approved_at contra Holded antes de crear nada, nunca se fía de datos del navegador.',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Buscar presupuesto en Holded por cliente o num de documento"] --> B{"aprobado en Holded"}
    B -->|"no"| C["Bloqueado: no aprobado"]
    B -->|"si"| D{"ya existe instalacion con este holded_doc_id"}
    D -->|"si"| E["Bloqueado: ya existe, enlaza a esa instalacion"]
    D -->|"no"| F{"holded_contact_id o email ya coincide con un cliente"}
    F -->|"si"| G["Usa el cliente existente (solo anade huecos, nunca pisa datos)"]
    F -->|"no"| H["Crea cliente nuevo (origen_lead=instalacion_holded)"]
    G --> I["Crea instalacion en borrador + materiales desde las lineas"]
    H --> I
    I --> J["Programa geocodificacion en background (WP-Cron +5s)"]
    I --> K["Enriquece ficha del cliente: interes renovables, tipo, estado del sector, PDF adjunto (solo huecos vacios)"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — stock, proveedor Santoki y transición a "Lista" (Fase 3, actualizado v1.20.57-67)',
		'description' => 'El catálogo de Holded ya tiene productos con stock real (antes vacío) — el badge de la ficha distingue línea enganchada por ID (fiable) de coincidencia por nombre (best-effort, nunca toca inventario real). El invariante de "Lista" lo mantiene crm_inst_sync_estado_con_materiales() en los dos sentidos.',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Linea de material (origen=holded)"] --> B{"tiene holded_product_id (enganchada de verdad)"}
    B -->|"si"| C["Stock real del catalogo Holded: En stock / Stock bajo / Sin stock"]
    B -->|"no"| D["Match best-effort por nombre (o Sin match) — solo informativo"]
    C --> E{"quedan materiales sin marcar Recibido"}
    D --> E
    E -->|"si"| F["Notificar proveedor: email a Santoki con token de un clic"]
    F --> G["Santoki confirma en /confirmar-pedido/: fecha de entrega + notas (o el jefe lo marca a mano de respaldo)"]
    G --> H{"fecha de entrega es igual o posterior a la visita"}
    H -->|"si"| I["Aviso de riesgo en la ficha + atajo Reprogramar visita"]
    H -->|"no"| J["Sin aviso"]
    E -->|"no, todo recibido"| K["Pasa sola a Lista (solo si venia de borrador/pendiente)"]
    K --> L["El instalador aun no puede descontar stock aqui — eso pasa en el checklist previo, ver diagrama de cierre"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — relación con la ficha de cliente',
		'description' => 'El módulo no sustituye el flujo comercial por sectores que ya existía — se cuelga de él por client_id/holded_contact_id.',
		'mermaid'     => <<<'MERMAID'
flowchart LR
    subgraph Comercial["Flujo comercial (preexistente)"]
        A["Alta de cliente"] --> B["Declara interes: renovables"]
        B --> C["Sube presupuesto / contratos"]
        C --> D["Estado del sector avanza: contratos_generados / firmados"]
    end
    subgraph Inst["Modulo de instalaciones"]
        E["Presupuesto aprobado en Holded"] --> F["Crea o empareja cliente"]
        F --> G["Instalacion: materiales, instalador, mapa, estado"]
    end
    D -. "mismo client_id / holded_contact_id" .-> F
    G -. "resumen de solo lectura" .-> H["Card renovables de la ficha de cliente"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — panel del instalador (Fase 4, entrega 1 de 2)',
		'description' => 'Shortcode [crm_inst_panel_instalador], dentro del mismo App Shell que el resto de roles desde v1.20.46 (topbar propia con marca del instalador). El checklist de cierre con soporte offline (cola de sincronización) sigue siendo la única pieza pendiente de la entrega 2 — el resto de la entrega 2 (checklist + cierre online) sí está construido, ver el diagrama de cierre.',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Jefe asigna instalador a la instalacion"] --> B["Jefe programa fecha en la ficha (crm_instalacion_agenda)"]
    B --> C["Instalador entra a su panel dentro del App Shell (topbar con su marca)"]
    C --> D["Ve Proximas visitas, Asignadas sin fecha y Realizadas"]
    D --> E["Icono i por tarjeta: estado y siguiente paso en 1-2 frases"]
    D --> F["Buscador dinamico: filtra por cliente/calle/material, resalta coincidencias"]
    D --> G["Cada tarjeta: materiales con badge, partidas extra, checklist, cierre"]
    G --> H["Pendiente (offline): cola de sincronizacion si se pierde la conexion a media faena"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — checklist previo, albarán de salida y cierre con fotos por categoría (Fase 4 Entrega 2, v1.20.60-88)',
		'description' => 'El albarán de salida real en Holded se genera aquí (checklist), no al marcar Recibido en oficina — goods-in y goods-out son eventos distintos. Sustituye al ajuste directo de stock (nunca probado) por lo que la gestoría de Ecovolt confirmó como el mecanismo correcto: un albarán de venta contra el cliente final. Ver crm_inst_ajax_confirmar_checklist() / crm_holded_crear_albaran_salida() / crm_inst_ajax_cerrar_instalacion().',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Instalador: Confirmar materiales y plan de seguridad"] --> B{"quedan materiales origen=holded sin Recibido"}
    B -->|"si"| C["Boton bloqueado — avisa cuantos faltan"]
    B -->|"no"| D["Elige almacen (si hay lineas enganchadas a Holded) + confirma materiales y plan de seguridad"]
    D --> E["Se crea el albaran de salida en Holded contra el cliente final (solo lineas con holded_product_id, project_id opcional)"]
    E --> F["Se aprueba en el acto — el stock solo se descuenta al aprobar, no al crear el borrador"]
    F --> G["Ficha admin: bloque Albaran de salida — generado y aprobado / creado sin aprobar (reintentar) / no se pudo generar / no se genero"]
    D --> H["Aparece Marcar como realizada"]
    H --> I{"subtipo tiene categorias de foto (fotovoltaica y/o aerotermia — pueden ser las dos a la vez)"}
    I -->|"si"| J["Una foto obligatoria por categoria combinada (dropzone drag&drop, se comprime en el movil)"]
    I -->|"no, comercial"| K["Modo libre: hasta 6 fotos sin categorizar"]
    J --> L["Conformidad + observaciones + cierre"]
    K --> L
    L --> M{"cierre_requiere_aprobacion (Ajustes)"}
    M -->|"si"| N["Cierre declarado — pendiente de aprobar el jefe"]
    M -->|"no"| O["Instalacion finalizada al momento"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — partidas extra, validadas por el CLIENTE (Fase 5, rediseñada v1.20.75-87)',
		'description' => 'El jefe ya no aprueba cada partida directamente — solo queda como respaldo manual si el cliente no responde. Ver crm_inst_ajax_declarar_extra(), crm_inst_ajax_enviar_extra_cliente(), crm_inst_ajax_validar_extra_cliente().',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Instalador declara: descripcion, horas, materiales (autocomplete Holded opcional), importe, foto de justificacion obligatoria"] --> B["Pendiente de enviar (editable mientras siga asi)"]
    B --> C["Instalador: Enviar al cliente para validar"]
    C --> D["Modal: confirma/edita email (obligatorio) y opcionalmente WhatsApp (best-effort, real solo si hay credenciales+plantilla en Ajustes; si no, solo queda anotado en el log)"]
    D --> E["Email al cliente con detalle + foto + token de un clic — siempre se envia, es el canal fiable"]
    E --> F["Enviado al cliente — ya NO editable"]
    F --> G["Cliente abre /validar-extra/?token=... (publica, sin login)"]
    G --> H{"decision del cliente"}
    H -->|"aprobar"| I["Aprobado — cuenta en los totales del cierre"]
    H -->|"rechazar"| J["Rechazado — no cuenta"]
    F --> K["Respaldo manual: jefe aprueba/rechaza a mano si el cliente no responde"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'Instalaciones — doble check de facturación y margen (Fase 6, v1.20.83)',
		'description' => 'Solo lectura contra Holded (nunca escribe). El lado proveedor no tiene enlace automático posible en el modelo de datos de Holded — se vincula una vez a mano. Ver crm_holded_find_invoice_from_estimate() / crm_holded_search_purchases().',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Comprobar pago del cliente"] --> B{"holded_invoice_id ya resuelto"}
    B -->|"no"| C["Busca en Holded la factura cuyo from.id = holded_doc_id (presupuesto), filtrando por contact_id del cliente"]
    C --> D["Guarda holded_invoice_id encontrado"]
    B -->|"si"| E["Pide directamente esa factura"]
    D --> E
    E --> F["Muestra status: pending/partial/completed/... + importe pendiente"]
    G["Compra al proveedor"] --> H{"holded_purchase_id vinculado"}
    H -->|"no"| I["Buscador manual (por documento o contacto) — el jefe elige la compra correcta, una sola vez"]
    I --> H
    H -->|"si"| J["Comprobar pago al proveedor: misma consulta de status"]
    F --> K{"factura completed Y compra completed"}
    J --> K
    K -->|"si"| L["Badge de margen: Confirmado (la cifra no cambia, solo el badge)"]
    K -->|"no"| M["Badge de margen: Provisional"]
MERMAID,
	];

	$diagramas[] = [
		'title'       => 'WhatsApp Business — andamiaje sin credenciales todavía (v1.20.87)',
		'description' => 'Construido antes de tener cuenta de ningún proveedor: nunca rompe nada si falta configurar algo, y el día que haya credenciales + plantillas aprobadas por Meta, empieza a enviar de verdad sin tocar código. Ver includes/whatsapp-api.php.',
		'mermaid'     => <<<'MERMAID'
flowchart TD
    A["Un flujo intenta avisar por WhatsApp (aviso materiales a jefes, o enviar partida extra al cliente)"] --> B{"crm_whatsapp_configurado() — token + phone_number_id en Ajustes"}
    B -->|"no"| C["No se intenta nada — solo queda anotado en el log"]
    B -->|"si"| D{"hay nombre de plantilla asignado para ese aviso concreto"}
    D -->|"no"| C
    D -->|"si"| E["POST a Meta Cloud API: mensaje de plantilla (no texto libre — Meta lo exige para avisos que inicia la empresa)"]
    E --> F{"Meta responde 2xx"}
    F -->|"si"| G["Enviado — se registra en el log de la instalacion"]
    F -->|"no"| H["Fallo registrado en Panel - Registro de actividades, con el error real de Meta"]
    I["Numero de WhatsApp del destinatario"] --> J["Jefe/crm_admin: campo en su perfil de wp-admin"]
    I --> K["Instalador: campo en Mi perfil (panel branded)"]
    I --> L["Cliente: precargado en el modal Enviar al cliente, editable"]
MERMAID,
	];

	return $diagramas;
} );

// ──────────────────────────────────────────────────────────────────────────────
// Roadmap (wp-admin → CRM → Flujos, y /panel-de-control/ — v1.20.89)
//
// Una fila por fase de PLAN_instalaciones.md (raíz del repo), con su estado
// real. Igual que los diagramas de arriba: se registra junto al código para
// que quien lo cambie actualice esta misma fila, no un documento aparte.
// Estados: 'hecho' | 'en_pruebas' (construido, falta probar) | 'pendiente' |
// 'bloqueado' (construido, pero algo fuera del CRM lo frena).
// ──────────────────────────────────────────────────────────────────────────────

add_filter( 'crm_roadmap_fases', function ( $fases ) {

	$fases[] = [
		'fase'    => 'Fase 1',
		'titulo'  => 'Esquema base y roles',
		'estado'  => 'hecho',
		'detalle' => 'Tablas, roles (jefe_instalaciones, instalador) y capacidades — cerrado y revisado en preprod.',
	];

	$fases[] = [
		'fase'    => 'Fase 2',
		'titulo'  => 'Alta de instalación desde un presupuesto de Holded',
		'estado'  => 'en_pruebas',
		'detalle' => 'Buscador de presupuestos aprobados, creación de cliente/instalación desde sus líneas — construido, sin una ronda de prueba formal todavía (con cliente nuevo y existente).',
	];

	$fases[] = [
		'fase'    => 'Fase 3',
		'titulo'  => 'Stock, aviso al proveedor Santoki y transición a "Lista"',
		'estado'  => 'hecho',
		'detalle' => 'Probado en preprod: el estado cambia solo al recibir todo el material, y vuelve atrás si se desmarca algo por error.',
	];

	$fases[] = [
		'fase'    => 'Fase 4 · Entrega 1',
		'titulo'  => 'Panel del instalador (calendario, agenda, buscador)',
		'estado'  => 'hecho',
		'detalle' => 'Usado de verdad por un instalador real en varias rondas de prueba de esta sesión (partidas extra, fotos de cierre, buscador).',
	];

	$fases[] = [
		'fase'    => 'Fase 4 · Entrega 2',
		'titulo'  => 'Checklist previo, albarán de salida en Holded y cierre con fotos',
		'estado'  => 'pendiente',
		'detalle' => 'El checklist y las fotos de cierre por categoría SÍ están probados. Lo que falta es la parte más delicada: ninguna escritura real contra Holded (crear y aprobar el albarán de salida) se ha probado todavía — aparcado a propósito por el usuario para el final de esta ronda.',
	];

	$fases[] = [
		'fase'    => 'Fase 4 · Offline',
		'titulo'  => 'Soporte offline del checklist y el cierre',
		'estado'  => 'pendiente',
		'detalle' => 'No se ha empezado a construir — hoy el instalador necesita conexión para confirmar el checklist y declarar el cierre.',
	];

	$fases[] = [
		'fase'    => 'Fase 4bis',
		'titulo'  => 'Dashboard "Requiere atención", agenda unificada, guías por rol',
		'estado'  => 'en_pruebas',
		'detalle' => 'Construido fuera del plan original a petición del usuario. Sin confirmar todavía: que el email a Santoki llegue de verdad y que los 5 filtros del dashboard devuelvan lo esperado con datos reales.',
	];

	$fases[] = [
		'fase'    => 'Fase 5',
		'titulo'  => 'Partidas extra, validadas por el cliente por email',
		'estado'  => 'hecho',
		'detalle' => 'Probado de punta a punta en preprod: declarar, enviar, aprobar como cliente y cerrar la instalación funciona.',
	];

	$fases[] = [
		'fase'    => 'Fase 6',
		'titulo'  => 'Doble check de facturación (cliente pagó / se pagó al proveedor) y margen',
		'estado'  => 'en_pruebas',
		'detalle' => 'Solo lecturas contra Holded, riesgo bajo. Falta confirmar si "sin factura todavía" en el lado cliente es correcto o un fallo de emparejamiento, y probar el buscador/vínculo de la compra de proveedor.',
	];

	$fases[] = [
		'fase'    => 'Fase 7 · WhatsApp',
		'titulo'  => 'Envíos por WhatsApp Business (avisos internos y al cliente)',
		'estado'  => 'bloqueado',
		'detalle' => 'Andamiaje completo y listo (plantillas, ajustes, perfil) — bloqueado por algo fuera del CRM: todavía no hay cuenta de proveedor Meta Cloud API ni plantillas aprobadas. El día que existan, es solo rellenar Ajustes.',
	];

	$fases[] = [
		'fase'    => 'Fase 7 · resto',
		'titulo'  => 'Canal por persona configurable, aviso de calendario, aviso de presupuesto estancado',
		'estado'  => 'pendiente',
		'detalle' => 'No empezado — depende en parte de que el canal WhatsApp deje de estar bloqueado.',
	];

	$fases[] = [
		'fase'    => 'Fase 8',
		'titulo'  => 'Agente de notificación al cliente (confirmación de cita, encuesta)',
		'estado'  => 'pendiente',
		'detalle' => 'No empezado — depende del canal WhatsApp (Fase 7) para el envío real.',
	];

	$fases[] = [
		'fase'    => 'Fase 9',
		'titulo'  => 'QA end-to-end de los 3 flujos validados con el cliente',
		'estado'  => 'pendiente',
		'detalle' => 'No empezado — recorrido completo + revisión cruzada de permisos entre roles.',
	];

	return $fases;
} );
