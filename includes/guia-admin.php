<?php
/**
 * Guía de uso para administración - CRM v1.20.68
 * Manual de funcionalidades administrativas, gestión de contratos, notificaciones
 * y módulo de control de instalaciones. Contenido adaptado al rol: crm_admin /
 * administrator ven la guía completa; jefe_instalaciones ve solo la parte del
 * módulo de instalaciones (es lo único a lo que tiene acceso).
 */

if (!defined('ABSPATH')) {
    exit;
}

function crm_guia_admin_shortcode() {
    $user           = wp_get_current_user();
    $roles          = is_user_logged_in() ? (array) $user->roles : [];
    $is_full_admin  = function_exists('crm_user_is_admin') && crm_user_is_admin();
    $is_jefe_inst   = !$is_full_admin && in_array('jefe_instalaciones', $roles, true);

    if (!$is_full_admin && !$is_jefe_inst) {
        return '<p>Acceso denegado. Esta página es solo para administradores CRM y jefes de instalaciones.</p>';
    }

    ob_start();
    ?>
    <div class="crm-help-container">
        <div class="crm-help-header admin-header">
            <h1><?php echo $is_full_admin ? 'Guía de Uso para CRM Admin' : 'Guía de Uso para Jefe de Instalaciones'; ?></h1>
            <p class="help-subtitle"><?php echo $is_full_admin ? 'Manual completo de administración, gestión de contratos e instalaciones' : 'Manual del módulo de control de instalaciones'; ?></p>
        </div>

        <?php echo $is_full_admin ? crm_guia_admin_render_full() : crm_guia_admin_render_jefe_instalaciones(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <div class="help-footer">
            <div class="contact-support">
                <h3>Soporte Técnico</h3>
                <p>Para incidencias técnicas o dudas avanzadas sobre el sistema, consulta con el desarrollador.</p>
                <p>Email: info@replanta.dev</p>
                <p class="version-info">Versión del sistema: <?php echo esc_html(CRM_PLUGIN_VERSION); ?> | Manual actualizado: <?php echo esc_html(date('d/m/Y')); ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('crm_guia_admin', 'crm_guia_admin_shortcode');

/**
 * Bloque de contenido del módulo de instalaciones, compartido entre la guía
 * completa (crm_admin/administrator) y la guía reducida de jefe_instalaciones.
 * Evita duplicar el mismo texto en dos sitios.
 */
function crm_guia_admin_render_instalaciones_modulo() {
    ob_start();
    ?>
    <section id="instalaciones-resumen" class="help-section">
        <h2>Qué es el Módulo de Instalaciones</h2>
        <div class="help-content">
            <p>Controla el ciclo completo de una instalación de renovables: desde que se acepta el presupuesto hasta que el instalador cierra la obra, pasando por la gestión de materiales, el pedido al proveedor y la validación final.</p>
            <div class="admin-permissions">
                <h4>Quién ve qué:</h4>
                <ul>
                    <li><strong>Jefe de Instalaciones / crm_admin / administrator:</strong> acceso completo — ve todas las instalaciones, valida a mano de respaldo las partidas extra que el cliente no responda, aprueba cierres, gestiona proveedores.</li>
                    <li><strong>Instalador:</strong> solo ve y trabaja sus propias instalaciones asignadas. Es un rol <strong>interno de Ecovolt</strong> (cuando exista el módulo de empresas instaladoras externas, habrá más instaladores con este mismo rol o uno equivalente).</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="instalaciones-crear" class="help-section">
        <h2>Crear una Instalación</h2>
        <div class="help-content">
            <div class="step-by-step">
                <h4>Desde "Instalaciones" → "+ Nueva instalación":</h4>
                <ol>
                    <li>Busca el presupuesto por nombre del cliente o número de presupuesto (búsqueda directa contra Holded).</li>
                    <li>Selecciona el presupuesto correcto entre los resultados.</li>
                    <li>Elige el <strong>tipo</strong> y <strong>subtipo</strong> de instalación — si el mismo viaje cubre fotovoltaica y aerotermia, marca las dos casillas.</li>
                    <li>Se crea la instalación con las líneas del presupuesto importadas como materiales de partida.</li>
                </ol>
            </div>
            <p>El tipo y el subtipo se pueden corregir después desde la propia ficha si al crearla se seleccionó algo equivocado.</p>
        </div>
    </section>

    <section id="instalaciones-materiales" class="help-section">
        <h2>Materiales y Stock (Holded)</h2>
        <div class="help-content">
            <h3>Saber si hay que pedir material a Santoki</h3>
            <p>En la ficha de cada instalación, la tabla de materiales incluye una columna <strong>"Stock"</strong> que consulta en tiempo real el almacén de Ecovolt en Holded:</p>
            <div class="feature-box">
                <ul>
                    <li><strong>Emparejado por ID de producto:</strong> cuando la línea del presupuesto trae el producto de Holded vinculado — máxima confianza, muestra stock, precio y coste reales.</li>
                    <li><strong>Emparejado por nombre:</strong> si no hay ID, el sistema busca el producto más parecido por descripción — se marca como una coincidencia de <strong>menor confianza</strong>, conviene revisarla a mano.</li>
                    <li><strong>Sin match:</strong> no se encontró producto equivalente en Holded; se gestiona a mano.</li>
                </ul>
            </div>
            <p>Con esa información decides, línea a línea, si el material está <strong>en almacén</strong> (se puede recoger) o si hay que <strong>pedirlo a Santoki</strong>.</p>
        </div>
    </section>

    <section id="instalaciones-proveedor" class="help-section">
        <h2>Comunicación con el Proveedor (Santoki)</h2>
        <div class="help-content">
            <h3>Pedir material y hacer seguimiento sin llamadas</h3>
            <div class="step-by-step">
                <ol>
                    <li>En la ficha, pulsa <strong>"Notificar proveedor"</strong>: se envía un email a Santoki con la lista de materiales pendientes de esa instalación.</li>
                    <li>El correo incluye un <strong>enlace de confirmación de un clic</strong> que no requiere cuenta ni contraseña — el proveedor pulsa, ve la lista y confirma disponibilidad.</li>
                    <li>El proveedor puede indicar la <strong>fecha de entrega estimada</strong> y notas (por ejemplo, la agencia de transporte).</li>
                    <li>La ficha muestra el estado del pedido (enviado / confirmado) y la fecha de entrega, con un aviso en rojo si la entrega estimada coincide o es <strong>posterior</strong> a la fecha de la visita programada — con un acceso directo para reprogramarla.</li>
                </ol>
            </div>
            <div class="tip-box">
                <p>La página de confirmación que ve el proveedor es pública y no muestra ningún menú ni marca del CRM — solo el pedido, para no exponer información interna a un tercero externo.</p>
            </div>
        </div>
    </section>

    <section id="instalaciones-checklist" class="help-section">
        <h2>Checklist del Instalador y Albarán de Salida</h2>
        <div class="help-content">
            <p>Antes de poder marcar una instalación como realizada, el instalador debe completar un checklist previo desde su panel: confirmar que tiene <strong>todos los materiales</strong> en su poder, elegir de qué <strong>almacén</strong> de Holded sale el material, y que ha leído y acepta el <strong>plan de seguridad y prevención</strong> (página pública de demostración, pensada para sustituirse por el documento real de PRL).</p>
            <div class="warning-box">
                <h4>Por qué en ese momento y no antes</h4>
                <p>El sistema genera y aprueba automáticamente un <strong>albarán de salida</strong> real en Holded al confirmar este checklist — es el momento en que el material sale físicamente del almacén hacia la obra, no cuando se marca "Recibido" en oficina (que es un evento distinto: la llegada del pedido del proveedor). El albarán se crea contra el cliente final (el mismo que se factura) y es lo que descuenta el stock de verdad en la contabilidad de Holded. Solo se incluyen las líneas con producto identificado por ID; si falla la llamada a Holded, se registra en la ficha de la instalación (con opción de reintentar la aprobación) pero no bloquea el flujo del instalador.</p>
            </div>
        </div>
    </section>

    <section id="instalaciones-extras-cierre" class="help-section">
        <h2>Partidas Extra y Cierre</h2>
        <div class="help-content">
            <div class="feature-box">
                <h4>Partidas extra</h4>
                <p>El instalador declara material u horas no previstas en el presupuesto original (puede buscar el material en el inventario de Holded) con una foto de justificación obligatoria. Ahora la aprueba o rechaza el <strong>cliente por email</strong>: el instalador pulsa "Enviar al cliente", el cliente recibe un enlace de un clic para aprobar o rechazar, y el resultado se refleja solo en la ficha.</p>
                <p>Tú ya no apruebas cada partida — ves el estado en tiempo real. Si el cliente no responde por email, tienes un <strong>respaldo manual</strong> (Aprobar/Rechazar a mano) en la propia tabla de partidas extra, igual que el "Marcar confirmado a mano" del proveedor.</p>
            </div>
            <div class="feature-box">
                <h4>Cierre de la instalación</h4>
                <p>El instalador declara el cierre con fotos y observaciones, pero queda <strong>pendiente de tu aprobación</strong>. Solo tú (jefe_instalaciones/crm_admin/administrator) puedes aprobarlo o rechazarlo; si lo rechazas, el instalador puede volver a declararlo con las correcciones. El cierre solo puede declararse si el instalador completó antes el checklist de materiales y plan de seguridad.</p>
                <p>Las fotos van organizadas por categoría según el/los subtipo(s) de la instalación (fotovoltaica: inversor, placas, cuadros de protección; aerotermia: unidad interior, unidad exterior, cuadros de protección) — el instalador no puede enviar el cierre si falta alguna. Si la instalación es fotovoltaica <strong>y</strong> aerotermia a la vez, se piden las categorías de las dos (sin duplicar "cuadros de protección"). En la ficha verás cada foto con su etiqueta. Se comprimen en el móvil del instalador antes de subirse para no pesar de más.</p>
            </div>
        </div>
    </section>

    <section id="instalaciones-facturacion" class="help-section">
        <h2>Facturación y Margen</h2>
        <div class="help-content">
            <p>En la ficha, sección "Facturación y margen", hay un doble check independiente antes de dar el margen por definitivo:</p>
            <div class="feature-box">
                <h4>Cliente → Ecovolt (automático)</h4>
                <p>Al pulsar "Comprobar pago del cliente", el sistema busca en Holded la factura generada a partir del presupuesto de esta instalación (no hace falta vincular nada a mano) y muestra su estado real: pendiente, parcial, cobrada, etc.</p>
            </div>
            <div class="feature-box">
                <h4>Ecovolt → Proveedor (vinculación manual, una vez)</h4>
                <p>Holded no sabe de qué instalación viene una compra a un proveedor, así que aquí buscas y vinculas tú la compra correcta (por número de documento o nombre del proveedor) — una sola vez. A partir de ahí, "Comprobar pago al proveedor" consulta su estado real igual que con la factura del cliente. Puedes quitar el vínculo si te equivocaste.</p>
            </div>
            <div class="tip-box">
                <p>El margen mostrado (materiales + partidas extra aprobadas) no cambia de cifra — el badge junto a él pasa de "Provisional" a "Confirmado" solo cuando <strong>ambos</strong> checks están liquidados (completed).</p>
            </div>
        </div>
    </section>

    <section id="instalaciones-dashboard" class="help-section">
        <h2>Dashboard de Instalaciones</h2>
        <div class="help-content">
            <p>El listado de instalaciones (<code>/instalaciones/</code>) tiene dos filas de estadísticas, ambas <strong>clicables para filtrar la tabla</strong>:</p>
            <div class="feature-box">
                <h4>Fila 1 — por estado</h4>
                <p>Número de instalaciones en cada estado del flujo (pendiente, planificada, en curso, cerrada, etc.).</p>
            </div>
            <div class="feature-box">
                <h4>Fila 2 — "Requiere atención"</h4>
                <ul>
                    <li><strong>Materiales urgentes:</strong> visitas próximas con materiales todavía sin recibir.</li>
                    <li><strong>Extras pendientes:</strong> partidas extra sin enviar al cliente o esperando su respuesta.</li>
                    <li><strong>Cierres pendientes:</strong> cierres declarados esperando tu aprobación.</li>
                    <li><strong>Pedidos sin confirmar:</strong> pedidos notificados a Santoki que aún no han respondido.</li>
                    <li><strong>Riesgo de retraso del proveedor:</strong> pedidos confirmados con entrega estimada igual o posterior a la fecha de la visita.</li>
                </ul>
            </div>
            <p>Además, la tabla marca con un icono (no emoji) la celda de materiales cuando hay partidas extra pendientes de aprobar en esa instalación.</p>
        </div>
    </section>

    <section id="instalaciones-avisos" class="help-section">
        <h2>Avisos Automáticos de Materiales Pendientes</h2>
        <div class="help-content">
            <p>Lo configura el administrador del sistema (sección "Aviso de materiales pendientes"):</p>
            <div class="feature-box">
                <ul>
                    <li><strong>Días antes de la visita:</strong> cuántos días antes se dispara el aviso si el material sigue sin recibirse (3 por defecto).</li>
                    <li><strong>Franja horaria:</strong> hora del día en la que se comprueba y envía el aviso.</li>
                    <li><strong>Canales:</strong> notificación in-app y/o email, y también WhatsApp si el administrador del sistema ha configurado una cuenta de Meta Cloud API y una plantilla aprobada — si no, ese canal simplemente no envía nada, sin dar error.</li>
                </ul>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Guía reducida para jefe_instalaciones: solo el módulo de instalaciones,
 * que es lo único a lo que este rol tiene acceso en el CRM.
 */
function crm_guia_admin_render_jefe_instalaciones() {
    ob_start();
    ?>
    <div class="help-navigation">
        <ul>
            <li><a href="#instalaciones-resumen">Resumen del Módulo</a></li>
            <li><a href="#instalaciones-crear">Crear una Instalación</a></li>
            <li><a href="#instalaciones-materiales">Materiales y Stock</a></li>
            <li><a href="#instalaciones-proveedor">Proveedor (Santoki)</a></li>
            <li><a href="#instalaciones-checklist">Checklist del Instalador</a></li>
            <li><a href="#instalaciones-extras-cierre">Extras y Cierre</a></li>
            <li><a href="#instalaciones-facturacion">Facturación y Margen</a></li>
            <li><a href="#instalaciones-dashboard">Dashboard</a></li>
            <li><a href="#instalaciones-avisos">Avisos Automáticos</a></li>
        </ul>
    </div>
    <?php echo crm_guia_admin_render_instalaciones_modulo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
    return ob_get_clean();
}

/**
 * Guía completa para crm_admin / administrator.
 */
function crm_guia_admin_render_full() {
    ob_start();
    ?>
    <div class="help-navigation">
        <ul>
            <li><a href="#panel-control">Panel de Control</a></li>
            <li><a href="#gestion-clientes">Gestión de Clientes</a></li>
            <li><a href="#estados-contratos">Estados y Contratos</a></li>
            <li><a href="#notificaciones">Sistema de Notificaciones</a></li>
            <li><a href="#instalaciones-resumen">Módulo de Instalaciones</a></li>
            <li><a href="#agenda-holded">Agenda y Panel Holded</a></li>
            <li><a href="#archivos-documentos">Archivos y Documentos</a></li>
            <li><a href="#reportes">Reportes y Análisis</a></li>
        </ul>
    </div>

    <section id="panel-control" class="help-section">
        <h2>Panel de Control Principal</h2>
        <div class="help-content">
            <h3>Vista general del sistema administrativo</h3>

            <div class="feature-box">
                <h4>Acceso a funciones principales:</h4>
                <ul>
                    <li><strong>Todas las Altas:</strong> vista completa de todos los clientes del sistema.</li>
                    <li><strong>Resumen:</strong> dashboard con estadísticas y métricas.</li>
                    <li><strong>Alta Manual:</strong> crear clientes directamente desde admin.</li>
                    <li><strong>Instalaciones:</strong> control de las instalaciones de renovables (ver más abajo).</li>
                    <li><strong>Agenda:</strong> visitas comerciales + citas de instalación de todo el equipo en un único calendario.</li>
                    <li><strong>Panel:</strong> ajustes del CRM + estadísticas y buscador/importador de Holded.</li>
                </ul>
            </div>

            <div class="admin-permissions">
                <h4>Permisos exclusivos de administrador:</h4>
                <ul>
                    <li>Ver y editar todos los clientes independientemente del comercial.</li>
                    <li>Cambiar estados de cliente en cualquier momento.</li>
                    <li>Acceso completo a archivos y documentos.</li>
                    <li>Envío de notificaciones a comerciales.</li>
                    <li>Gestión de contratos generados y firmados.</li>
                    <li>Gestión completa del módulo de instalaciones (materiales, proveedor, cierres).</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="gestion-clientes" class="help-section">
        <h2>Gestión de Clientes</h2>
        <div class="help-content">
            <h3>Administración completa del ciclo de vida del cliente</h3>

            <div class="table-features">
                <h4>Tabla "Todas las Altas" - Funcionalidades:</h4>
                <ul>
                    <li><strong>Filtrado automático:</strong> busca por nombre, empresa, comercial o estado.</li>
                    <li><strong>Ordenación:</strong> click en columnas para ordenar datos.</li>
                    <li><strong>Información completa:</strong> estado, comercial asignado, fecha de modificación.</li>
                    <li><strong>Acciones rápidas:</strong> editar y eliminar directamente desde la tabla.</li>
                    <li><strong>Última edición:</strong> usuario que modificó y fecha/hora exacta.</li>
                </ul>
            </div>

            <div class="step-by-step">
                <h4>Proceso de gestión de un cliente:</h4>
                <ol>
                    <li>Recibir notificación de nueva alta de comercial.</li>
                    <li>Revisar datos y documentación en "Todas las Altas".</li>
                    <li>Editar cliente para generar presupuesto.</li>
                    <li>Subir presupuesto y cambiar estado a "Presupuesto Generado".</li>
                    <li>Esperar respuesta del cliente.</li>
                    <li>Si acepta: generar contratos y cambiar a "Contratos Generados".</li>
                    <li>Subir contratos firmados y finalizar proceso. Si el sector es Renovables, crear la instalación desde el módulo correspondiente.</li>
                </ol>
            </div>

            <div class="edit-features">
                <h4>Funciones de edición avanzadas:</h4>
                <ul>
                    <li><strong>Guardar ficha:</strong> actualiza datos sin notificar.</li>
                    <li><strong>Guardar y notificar comercial:</strong> envía email automático al comercial.</li>
                    <li><strong>Forzar estado:</strong> cambio manual de estado sin restricciones.</li>
                    <li><strong>Gestión de archivos:</strong> subir, eliminar y organizar documentos.</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="estados-contratos" class="help-section">
        <h2>Estados y Gestión de Contratos</h2>
        <div class="help-content">
            <h3>Flujo completo del proceso comercial</h3>

            <div class="estados-workflow">
                <h4>Flujo de estados automático:</h4>
                <div class="workflow-diagram">
                    <div class="workflow-step"><strong>Sin enviar</strong><p>Ficha creada pero no enviada</p></div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step"><strong>Enviado</strong><p>Comercial completó y envió</p></div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step"><strong>Presupuesto Generado</strong><p>Comercial/Admin creó y subió presupuesto</p></div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step"><strong>Presupuesto Aceptado</strong><p>Cliente aceptó la propuesta</p></div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step"><strong>Contratos Generados</strong><p>Contratos listos para firma</p></div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step"><strong>Contratos Firmados</strong><p>Proceso completado</p></div>
                </div>
            </div>

            <div class="detailed-flow">
                <h4>Flujo detallado por sector</h4>
                <p>Cada sector tiene su propio estado independiente. A continuación se describen los estados posibles y qué los provoca:</p>
                <ul>
                    <li><strong>Sin enviar:</strong> ficha creada o sector añadido pero todavía no enviado.</li>
                    <li><strong>Enviado:</strong> el comercial marca el envío del sector (o admin marca envío para el sector). Si se envía y no había estado previo, pasa de <em>sin enviar</em> a <em>enviado</em>.</li>
                    <li><strong>Presupuesto Generado:</strong> existe un archivo de presupuesto subido para ese sector; la lógica automática actualiza <em>enviado → presupuesto_generado</em>.</li>
                    <li><strong>Presupuesto Aceptado:</strong> cuando el comercial o el admin marca la aceptación del presupuesto (checkbox). Este estado puede forzarse o marcarse automáticamente si hay contratos firmados.</li>
                    <li><strong>Contratos Generados:</strong> admin marca que ha generado los contratos (checkbox de contratos generados) o la lógica avanza desde <em>presupuesto_aceptado</em> si procede.</li>
                    <li><strong>Contratos Firmados:</strong> si hay archivos de contratos firmados subidos para el sector, el estado finaliza en <em>contratos_firmados</em> (estado terminal).</li>
                </ul>

                <h5>Reglas importantes</h5>
                <ol>
                    <li>Un contrato firmado para un sector fuerza el estado a <em>contratos_firmados</em>, aunque antes no se marcara la aceptación explícita.</li>
                    <li>El admin puede <strong>forzar</strong> cualquier estado mediante el control "Forzar estado" en la edición de la ficha.</li>
                    <li>Si el admin desmarca la aceptación de un presupuesto que ya tenía archivo subido, la lógica intentará devolver el estado al más lógico: <em>presupuesto_generado</em> si existe el presupuesto, o <em>enviado</em> si no.</li>
                    <li>Las transiciones automáticas no se aplican cuando el estado ha sido forzado por admin.</li>
                </ol>

                <p>Para auditoría, el sistema registra detalles del guardado por sector en los logs (entrada <code>debug_sector_save</code>) y en las acciones <code>cliente_actualizado</code>, con un listado de <em>Estados por sector</em>. Usa el registro para verificar qué usuario realizó cada cambio y en qué momento.</p>
            </div>

            <div class="feature-box">
                <h4>Gestión de contratos por sector:</h4>
                <p>El sistema permite gestionar contratos independientes para cada sector de interés:</p>
                <ul>
                    <li><strong>Energía:</strong> contratos de suministro eléctrico.</li>
                    <li><strong>Alarmas:</strong> sistemas de seguridad.</li>
                    <li><strong>Telecomunicaciones:</strong> servicios de Internet y telefonía.</li>
                    <li><strong>Seguros:</strong> pólizas diversas.</li>
                    <li><strong>Renovables:</strong> instalaciones solares (con seguimiento completo en el módulo de Instalaciones).</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="notificaciones" class="help-section">
        <h2>Sistema de Notificaciones</h2>
        <div class="help-content">
            <h3>Comunicación automática con comerciales y proveedores</h3>

            <div class="notification-types">
                <h4>Tipos de notificaciones automáticas:</h4>
                <ul>
                    <li><strong>Nueva alta recibida:</strong> cuando un comercial envía un cliente nuevo.</li>
                    <li><strong>Cambio de estado:</strong> cuando modificas el estado de un cliente.</li>
                    <li><strong>Presupuesto generado:</strong> notificación automática al comercial.</li>
                    <li><strong>Contratos listos:</strong> cuando los contratos están preparados.</li>
                    <li><strong>Pedido de material:</strong> email a Santoki con enlace de confirmación (ver módulo de Instalaciones).</li>
                    <li><strong>Materiales pendientes:</strong> aviso in-app/email configurable antes de una visita si el material no ha llegado.</li>
                </ul>
            </div>

            <div class="step-by-step">
                <h4>Cómo funciona "Guardar y notificar comercial":</h4>
                <ol>
                    <li>Realizas cambios en la ficha del cliente.</li>
                    <li>Pulsas "Guardar y notificar comercial".</li>
                    <li>Se guarda automáticamente con tu usuario como "actualizado por".</li>
                    <li>Se envía email al comercial asignado.</li>
                    <li>Email incluye resumen de cambios y estado actual.</li>
                </ol>
            </div>
        </div>
    </section>

    <?php echo crm_guia_admin_render_instalaciones_modulo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

    <section id="agenda-holded" class="help-section">
        <h2>Agenda Unificada y Panel Holded</h2>
        <div class="help-content">
            <div class="feature-box">
                <h4>Agenda</h4>
                <p>Como crm_admin/administrator ves <strong>"Agenda"</strong> (no "Mi agenda"): el mismo calendario combina las visitas comerciales de todo el equipo con las citas de instalación (en morado). Al pulsar un evento de instalación vas directamente a su ficha; al pulsar una visita, a la ficha del cliente.</p>
            </div>
            <div class="feature-box">
                <h4>Sección "Holded (Ecovolt)" en Panel</h4>
                <p>Dentro de <code>/panel-de-control/</code> hay un bloque con estadísticas en vivo de Holded: total de clientes, total de presupuestos y cuántos presupuestos tienen ya una instalación creada en el sistema frente a los que no. Incluye además un <strong>buscador de contactos de Holded</strong> con botón para importar directamente un contacto como cliente del CRM.</p>
            </div>
        </div>
    </section>

    <section id="archivos-documentos" class="help-section">
        <h2>Gestión de Archivos y Documentos</h2>
        <div class="help-content">
            <h3>Administración completa de documentación</h3>

            <div class="file-management">
                <h4>Tipos de documentos por proceso:</h4>
                <ul>
                    <li><strong>Facturas:</strong> subidas por comerciales, requeridas para evaluación.</li>
                    <li><strong>Presupuestos:</strong> generados por admin, enviados a clientes.</li>
                    <li><strong>Contratos Generados:</strong> documentos creados para firma.</li>
                    <li><strong>Contratos Firmados:</strong> documentos finales del proceso.</li>
                </ul>
            </div>

            <div class="upload-guidelines">
                <h4>Directrices para subida de archivos:</h4>
                <ul>
                    <li><strong>Formatos permitidos:</strong> PDF, JPG, PNG, WebP, HEIC.</li>
                    <li><strong>Tamaño máximo:</strong> 32 MB por archivo.</li>
                    <li><strong>Nomenclatura:</strong> usa nombres descriptivos y fecha.</li>
                    <li><strong>Organización:</strong> un archivo por sector cuando sea posible.</li>
                </ul>
            </div>

            <div class="feature-box">
                <h4>Funciones avanzadas de archivos:</h4>
                <ul>
                    <li><strong>Vista previa:</strong> click en nombre para abrir documento.</li>
                    <li><strong>Eliminación:</strong> botón X para quitar archivos incorrectos.</li>
                    <li><strong>Múltiple upload:</strong> selecciona varios archivos a la vez.</li>
                    <li><strong>Progreso visual:</strong> barras de progreso durante subida.</li>
                    <li><strong>Validación automática:</strong> el sistema verifica archivos necesarios.</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="reportes" class="help-section">
        <h2>Reportes y Análisis</h2>
        <div class="help-content">
            <h3>Herramientas de seguimiento y control</h3>

            <div class="dashboard-features">
                <h4>Información disponible en Resumen:</h4>
                <ul>
                    <li><strong>Estadísticas generales:</strong> total de clientes por estado.</li>
                    <li><strong>Rendimiento por comercial:</strong> número de altas y conversiones.</li>
                    <li><strong>Estados del pipeline:</strong> distribución de clientes en proceso.</li>
                    <li><strong>Tendencias temporales:</strong> evolución de altas en el tiempo.</li>
                    <li><strong>Instalaciones:</strong> total y avisos activos (ver tarjeta dedicada).</li>
                </ul>
            </div>

            <div class="table-analysis">
                <h4>Análisis desde "Todas las Altas":</h4>
                <ul>
                    <li><strong>Filtros dinámicos:</strong> busca por cualquier criterio.</li>
                    <li><strong>Exportación:</strong> funciones de copia y exportación.</li>
                    <li><strong>Ordenación múltiple:</strong> combina criterios de ordenación.</li>
                    <li><strong>Paginación:</strong> navegación eficiente con grandes volúmenes.</li>
                </ul>
            </div>

            <div class="tip-box">
                <h4>Consejos para análisis efectivo:</h4>
                <ul>
                    <li>Revisa regularmente la columna "Última edición" para seguimiento.</li>
                    <li>Utiliza filtros para identificar clientes estancados en un estado.</li>
                    <li>Monitorea la carga de trabajo de cada comercial.</li>
                    <li>Revisa a diario la fila "Requiere atención" del listado de instalaciones.</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="troubleshooting" class="help-section">
        <h2>Solución de Problemas Comunes</h2>
        <div class="help-content">
            <h3>Guía de resolución de incidencias</h3>

            <div class="troubleshooting">
                <h4>Problemas frecuentes y soluciones:</h4>
                <ul>
                    <li><strong>No recibo notificaciones:</strong> verifica email en perfil de comercial.</li>
                    <li><strong>Archivo no sube:</strong> confirma formato y tamaño, verifica conexión.</li>
                    <li><strong>Estado no cambia:</strong> asegúrate de tener documentos necesarios.</li>
                    <li><strong>Tabla no carga:</strong> recarga página, puede ser problema temporal.</li>
                    <li><strong>No se genera el albarán de salida en Holded:</strong> revisa en la ficha de la instalación (sección "Cierre de la instalación") el motivo exacto — falta producto identificado por ID, falta el contacto de Holded del cliente, o la API key no tiene permiso de escritura sobre envíos (`inventory:shipments.write`). Si se creó pero no se aprobó, hay un botón "Reintentar aprobación" en la misma ficha; en ningún caso bloquea al instalador.</li>
                </ul>
            </div>

            <div class="maintenance-tips">
                <h4>Mantenimiento preventivo:</h4>
                <ul>
                    <li>Revisa el registro de actividades regularmente.</li>
                    <li>Realiza copias de seguridad de archivos subidos.</li>
                    <li>Limpia fichas "Sin enviar" antiguas periódicamente.</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="novedades-1-20" class="help-section">
        <h2>Historial de versiones — v1.20</h2>
        <div class="help-content">
            <h3>Cambios clave a partir de la versión 1.20.0</h3>

            <div class="feature-box">
                <h4>Módulo de Instalaciones (v1.20.27 en adelante)</h4>
                <p>Todo el flujo descrito más arriba (creación desde presupuesto, materiales/stock, proveedor Santoki, checklist del instalador, avisos, dashboard y agenda unificada) se construyó de forma incremental a lo largo de la serie v1.20.27 → v1.20.68. Ver la sección "Módulo de Instalaciones" para el estado actual.</p>
            </div>

            <div class="feature-box">
                <h4>Rol "Visitador" (v1.20.1)</h4>
                <p>Rol <code>visitador</code> para personal de campo: gestiona sus visitas asignadas y también puede dar de alta clientes (igual que un comercial). Al iniciar sesión se le redirige automáticamente a su agenda.</p>
                <p><strong>Cómo crear un visitador:</strong> pide al administrador del sistema que cree el usuario y le asigne el rol <em>Visitador</em>. Desde cualquier ficha de cliente puedes asignarle visitas con el dropdown "Asignar a".</p>
            </div>

            <div class="feature-box">
                <h4>Estado de decisión por sector</h4>
                <p>Campo <strong>"Estado de decisión"</strong> editable por comercial y admin en cada sector: <em>Pendiente financiación</em>, <em>Pendiente competencia</em>, <em>Decisión pendiente</em>, <em>Pendiente visita</em>.</p>
            </div>

            <div class="feature-box">
                <h4>Sistema de visitas</h4>
                <p>Tabla <code>wp_crm_visitas</code> y bloque "Visitas" en la ficha del cliente para agendar, listar y marcar como realizada / no presentado / cancelada. Comerciales ven solo las suyas; admin ve todas.</p>
            </div>

            <div class="feature-box">
                <h4>Leads MK: detección flexible de ID + forzar resync</h4>
                <p>El sync con Google Sheets detecta columnas variantes y genera un id sintético si falta. Botón "Forzar resincronización" que ignora el cursor.</p>
            </div>

            <div class="feature-box">
                <h4>Helper de rol estricto</h4>
                <p>Los controles de administrador se renderizan según el <strong>rol explícito</strong> del usuario, no por un permiso suelto, para que un comercial no pueda acabar viendo controles de admin por error de configuración.</p>
            </div>
        </div>
    </section>

    <section id="novedades-1-19" class="help-section">
        <h2>Novedades v1.19 (rediseño UI)</h2>
        <div class="help-content">
            <h3>Cambios clave a partir de la versión 1.19.0</h3>

            <div class="feature-box">
                <h4>Nuevo sistema de diseño hi-tech</h4>
                <ul>
                    <li>Paleta neutra + marca <strong>#191919 (negro)</strong>, acento azul y estados (esmeralda/ámbar/violeta).</li>
                    <li>Tipografía system-ui / Inter, 14px base, espaciados densos al estilo Linear / Vercel.</li>
                    <li>Iconos vectoriales <strong>Phosphor Icons</strong> en sectores, badges, navegación y acciones.</li>
                    <li>Badges nuevos con icono y color por estado: <em>Borrador, Enviado, Presupuesto aceptado, Contrato generado, Contrato firmado</em>.</li>
                </ul>
            </div>

            <div class="feature-box">
                <h4>Sectores como pestañas (tabs)</h4>
                <ul>
                    <li>Los sectores activos del cliente se muestran como pestañas verticales en escritorio y horizontales en móvil.</li>
                    <li>Cada pestaña abre el panel del sector correspondiente, ya no hay scroll infinito de tarjetas apiladas.</li>
                    <li>Al activar o desactivar un interés, la pestaña aparece o desaparece automáticamente.</li>
                </ul>
            </div>

            <div class="feature-box">
                <h4>Modo "App Shell"</h4>
                <p>Las páginas del CRM se muestran como una aplicación a pantalla completa, sin el header ni el footer del tema y con una barra superior propia. Lo activa el administrador del sistema.</p>
            </div>
        </div>
    </section>

    <section id="novedades-1-18" class="help-section">
        <h2>Novedades v1.18 (administración)</h2>
        <div class="help-content">
            <h3>Cambios clave a partir de la versión 1.18.0</h3>

            <div class="feature-box">
                <h4>Leads de marketing desde Google Sheets</h4>
                <ul>
                    <li>El sistema lee una hoja de Google Sheets (Meta/Facebook Lead Ads, etc.) cada hora mediante una cuenta de servicio.</li>
                    <li>La conexión (hoja, rango y credenciales) la configura el administrador del sistema; las credenciales se guardan cifradas.</li>
                    <li>Dedupe automática contra clientes existentes por teléfono normalizado o email.</li>
                </ul>
            </div>

            <div class="feature-box">
                <h4>Cola de asignación de leads — shortcode <code>[asignacion_leads_mk]</code></h4>
                <p>Lista los leads no asignados con filtros; permite asignar a un comercial, marcar como contacto frío o borrar el lead.</p>
            </div>

            <div class="feature-box">
                <h4>Detección de duplicados al dar de alta</h4>
                <p>Aviso amarillo no bloqueante al detectar teléfono/email ya existente en el sistema.</p>
            </div>

            <div class="feature-box">
                <h4>Origen del lead y clientes activos</h4>
                <p>Campo <code>origen_lead</code> con 5 valores; casilla "Es cliente activo" visible solo para administradores.</p>
            </div>
        </div>
    </section>

    <section id="novedades-1-17" class="help-section">
        <h2>Novedades v1.17 (administración)</h2>
        <div class="help-content">
            <h3>Cambios clave a partir de la versión 1.17.0</h3>

            <div class="feature-box">
                <h4>Cobertura nacional de municipios (INE)</h4>
                <p>El selector de provincias y poblaciones incluye los 52 territorios oficiales y 8.132 municipios del INE.</p>
            </div>

            <div class="feature-box">
                <h4>Historial / notas del cliente con búsqueda</h4>
                <p>Timeline vertical con notas manuales y eventos automáticos; búsqueda con MATCH ... AGAINST y fallback a LIKE.</p>
            </div>

            <div class="feature-box">
                <h4>Estimado de consumo por sector</h4>
                <p>Sustituye a la factura a efectos de poder enviar un sector cuando el cliente no la tiene a mano.</p>
            </div>

            <div class="feature-box">
                <h4>Uploader de archivos rediseñado (v8)</h4>
                <p>Subida automática, varios archivos a la vez, reintentos automáticos, soporte HEIC/HEIF, drag &amp; drop.</p>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
