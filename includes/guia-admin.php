<?php
/**
 * Guía de uso para CRM Admin - CRM v1.13.0
 * Manual de funcionalidades administrativas, gestión de contratos y notificaciones
 */

if (!defined('ABSPATH')) {
    exit;
}

function crm_guia_admin_shortcode() {
    if (!current_user_can('crm_admin')) {
        return '<p>Acceso denegado. Esta página es solo para administradores CRM.</p>';
    }

    ob_start();
    ?>
    <div class="crm-help-container">
        <div class="crm-help-header admin-header">
            <h1>Guía de Uso para CRM Admin</h1>
            <p class="help-subtitle">Manual completo de administración y gestión de contratos</p>
        </div>

        <div class="help-navigation">
            <ul>
                <li><a href="#panel-control">Panel de Control</a></li>
                <li><a href="#gestion-clientes">Gestión de Clientes</a></li>
                <li><a href="#estados-contratos">Estados y Contratos</a></li>
                <li><a href="#notificaciones">Sistema de Notificaciones</a></li>
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
                        <li><strong>Todas las Altas:</strong> Vista completa de todos los clientes del sistema</li>
                        <li><strong>Resumen:</strong> Dashboard con estadísticas y métricas</li>
                        <li><strong>Alta Manual:</strong> Crear clientes directamente desde admin</li>
                        <li><strong>Gestión de Estados:</strong> Modificar el flujo de trabajo de cada cliente</li>
                    </ul>
                </div>

                <div class="admin-permissions">
                    <h4>Permisos exclusivos de administrador:</h4>
                    <ul>
                        <li>Ver y editar todos los clientes independientemente del comercial</li>
                        <li>Cambiar estados de cliente en cualquier momento</li>
                        <li>Acceso completo a archivos y documentos</li>
                        <li>Envío de notificaciones a comerciales</li>
                        <li>Gestión de contratos generados y firmados</li>
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
                        <li><strong>Filtrado automático:</strong> Busca por nombre, empresa, comercial o estado</li>
                        <li><strong>Ordenación:</strong> Click en columnas para ordenar datos</li>
                        <li><strong>Información completa:</strong> Estado, comercial asignado, fecha de modificación</li>
                        <li><strong>Acciones rápidas:</strong> Editar y eliminar directamente desde la tabla</li>
                        <li><strong>Última edición:</strong> Usuario que modificó y fecha/hora exacta</li>
                    </ul>
                </div>

                <div class="step-by-step">
                    <h4>Proceso de gestión de un cliente:</h4>
                    <ol>
                        <li>Recibir notificación de nueva alta de comercial</li>
                        <li>Revisar datos y documentación en "Todas las Altas"</li>
                        <li>Editar cliente para generar presupuesto</li>
                        <li>Subir presupuesto y cambiar estado a "Presupuesto Generado"</li>
                        <li>Esperar respuesta del cliente</li>
                        <li>Si acepta: generar contratos y cambiar a "Contratos Generados"</li>
                        <li>Subir contratos firmados y finalizar proceso</li>
                    </ol>
                </div>

                <div class="edit-features">
                    <h4>Funciones de edición avanzadas:</h4>
                    <ul>
                        <li><strong>Guardar ficha:</strong> Actualiza datos sin notificar</li>
                        <li><strong>Guardar y notificar comercial:</strong> Envía email automático al comercial</li>
                        <li><strong>Forzar estado:</strong> Cambio manual de estado sin restricciones</li>
                        <li><strong>Gestión de archivos:</strong> Subir, eliminar y organizar documentos</li>
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
                        <div class="workflow-step">
                            <strong>Sin enviar</strong>
                            <p>Ficha creada pero no enviada</p>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <strong>Enviado</strong>
                            <p>Comercial completó y envió</p>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <strong>Presupuesto Generado</strong>
                            <p>Comercial/Admin creó y subió presupuesto</p>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <strong>Presupuesto Aceptado</strong>
                            <p>Cliente aceptó la propuesta</p>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <strong>Contratos Generados</strong>
                            <p>Contratos listos para firma</p>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <strong>Contratos Firmados</strong>
                            <p>Proceso completado</p>
                        </div>
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

                    <p>Para auditoría, el plugin registra detalles del guardado por sector en los logs (entrada <code>debug_sector_save</code>) y en las acciones <code>cliente_actualizado</code>, con un listado de <em>Estados por sector</em>. Usa el registro para verificar qué usuario realizó cada cambio y en qué momento.</p>
                </div>

                <div class="feature-box">
                    <h4>Gestión de contratos por sector:</h4>
                    <p>El sistema permite gestionar contratos independientes para cada sector de interés:</p>
                    <ul>
                        <li><strong>Energía:</strong> Contratos de suministro eléctrico</li>
                        <li><strong>Alarmas:</strong> Sistemas de seguridad</li>
                        <li><strong>Telecomunicaciones:</strong> Servicios de Internet y telefonía</li>
                        <li><strong>Seguros:</strong> Pólizas diversas</li>
                        <li><strong>Renovables:</strong> Instalaciones solares</li>
                    </ul>
                </div>

                <div class="tip-box">
                    <h4>Consejos para gestión eficiente:</h4>
                    <ul>
                        <li>Utiliza "Guardar y notificar" cuando hagas cambios importantes</li>
                        <li>Revisa siempre que los archivos subidos sean correctos antes de cambiar estado</li>
                        <li>El sistema valida automáticamente que existan documentos necesarios</li>
                        <li>Puedes forzar estados manualmente si es necesario</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="notificaciones" class="help-section">
            <h2>Sistema de Notificaciones</h2>
            <div class="help-content">
                <h3>Comunicación automática con comerciales</h3>

                <div class="notification-types">
                    <h4>Tipos de notificaciones automáticas:</h4>
                    <ul>
                        <li><strong>Nueva alta recibida:</strong> Cuando un comercial envía un cliente nuevo</li>
                        <li><strong>Cambio de estado:</strong> Cuando modificas el estado de un cliente</li>
                        <li><strong>Presupuesto generado:</strong> Notificación automática al comercial</li>
                        <li><strong>Contratos listos:</strong> Cuando los contratos están preparados</li>
                    </ul>
                </div>

                <div class="step-by-step">
                    <h4>Cómo funciona "Guardar y notificar comercial":</h4>
                    <ol>
                        <li>Realizas cambios en la ficha del cliente</li>
                        <li>Pulsas "Guardar y notificar comercial"</li>
                        <li>Se guarda automáticamente con tu usuario como "actualizado por"</li>
                        <li>Se envía email al comercial asignado</li>
                        <li>Email incluye resumen de cambios y estado actual</li>
                        <li>Comercial recibe notificación inmediata</li>
                    </ol>
                </div>

                <div class="email-content">
                    <h4>Contenido de emails automáticos:</h4>
                    <ul>
                        <li>Datos del cliente modificado</li>
                        <li>Estado actual y cambios realizados</li>
                        <li>Usuario administrador que realizó los cambios</li>
                        <li>Fecha y hora de la modificación</li>
                        <li>Enlace directo para revisar la ficha</li>
                    </ul>
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
                        <li><strong>Facturas:</strong> Subidas por comerciales, requeridas para evaluación</li>
                        <li><strong>Presupuestos:</strong> Generados por admin, enviados a clientes</li>
                        <li><strong>Contratos Generados:</strong> Documentos creados para firma</li>
                        <li><strong>Contratos Firmados:</strong> Documentos finales del proceso</li>
                    </ul>
                </div>

                <div class="upload-guidelines">
                    <h4>Directrices para subida de archivos:</h4>
                    <ul>
                        <li><strong>Formatos permitidos:</strong> PDF, JPG, PNG, WebP</li>
                        <li><strong>Tamaño máximo:</strong> 5MB por archivo</li>
                        <li><strong>Nomenclatura:</strong> Usa nombres descriptivos y fecha</li>
                        <li><strong>Organización:</strong> Un archivo por sector cuando sea posible</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Funciones avanzadas de archivos:</h4>
                    <ul>
                        <li><strong>Vista previa:</strong> Click en nombre para abrir documento</li>
                        <li><strong>Eliminación:</strong> Botón X para quitar archivos incorrectos</li>
                        <li><strong>Múltiple upload:</strong> Selecciona varios archivos a la vez</li>
                        <li><strong>Progreso visual:</strong> Barras de progreso durante subida</li>
                        <li><strong>Validación automática:</strong> El sistema verifica archivos necesarios</li>
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
                        <li><strong>Estadísticas generales:</strong> Total de clientes por estado</li>
                        <li><strong>Rendimiento por comercial:</strong> Número de altas y conversiones</li>
                        <li><strong>Estados del pipeline:</strong> Distribución de clientes en proceso</li>
                        <li><strong>Tendencias temporales:</strong> Evolución de altas en el tiempo</li>
                    </ul>
                </div>

                <div class="table-analysis">
                    <h4>Análisis desde "Todas las Altas":</h4>
                    <ul>
                        <li><strong>Filtros dinámicos:</strong> Busca por cualquier criterio</li>
                        <li><strong>Exportación:</strong> Funciones de copia y exportación</li>
                        <li><strong>Ordenación múltiple:</strong> Combina criterios de ordenación</li>
                        <li><strong>Paginación:</strong> Navegación eficiente con grandes volúmenes</li>
                    </ul>
                </div>

                <div class="tip-box">
                    <h4>Consejos para análisis efectivo:</h4>
                    <ul>
                        <li>Revisa regularmente la columna "Última edición" para seguimiento</li>
                        <li>Utiliza filtros para identificar clientes estancados en un estado</li>
                        <li>Monitorea la carga de trabajo de cada comercial</li>
                        <li>Identifica patrones en tiempos de respuesta por sector</li>
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
                        <li><strong>No recibo notificaciones:</strong> Verifica email en perfil de comercial</li>
                        <li><strong>Archivo no sube:</strong> Confirma formato y tamaño, verifica conexión</li>
                        <li><strong>Estado no cambia:</strong> Asegúrate de tener documentos necesarios</li>
                        <li><strong>Tabla no carga:</strong> Recarga página, puede ser problema temporal</li>
                        <li><strong>Datos inconsistentes:</strong> Verifica que el comercial tenga permisos correctos</li>
                    </ul>
                </div>

                <div class="maintenance-tips">
                    <h4>Mantenimiento preventivo:</h4>
                    <ul>
                        <li>Revisa logs de error regularmente</li>
                        <li>Mantén actualizado el plugin CRM</li>
                        <li>Realiza copias de seguridad de archivos subidos</li>
                        <li>Limpia fichas "Sin enviar" antiguas periódicamente</li>
                    </ul>
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
                        <li>Nueva fuente de leads automática: el plugin lee una hoja de Google Sheets (Meta/Facebook Lead Ads, etc.) <strong>cada hora</strong> mediante una <em>cuenta de servicio</em> (Service Account JWT RS256, sin Composer).</li>
                        <li>Configurable desde <code>CRM → Leads MK</code>: <em>Spreadsheet ID</em>, <em>Rango</em> (p. ej. <code>Hoja1!A:Z</code>) y <em>Service Account JSON</em>. El JSON se almacena <strong>cifrado con AUTH_KEY</strong> (AES‑256‑CBC) en <code>wp_options</code>.</li>
                        <li>Mapeo: <code>nombre_y_apellidos → cliente_nombre</code>, <code>correo_electrónico → email_cliente</code>, <code>número_de_teléfono → telefono</code>, <code>created_time → fecha</code>. El resto del lead (campaña, anuncio, plataforma, formulario, is_organic…) se guarda en la nueva columna JSON <code>lead_meta</code>.</li>
                        <li>Cursor por <code>id</code> de fila para no reimportar; dedupe automática contra clientes existentes por <strong>teléfono normalizado o email</strong> (no se duplican fichas).</li>
                        <li>Cron horario <code>crm_leads_sheets_sync_cron</code> + botón "Sincronizar ahora" en el panel.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Cola de asignación de leads — shortcode <code>[asignacion_leads_mk]</code></h4>
                    <ul>
                        <li>Pega el shortcode en la página interna que quieras. Solo lo ve el rol <code>crm_admin</code>.</li>
                        <li>Lista los leads no asignados (<code>origen_lead = 'lead_mk'</code>, sin <code>user_id</code>) con filtros: búsqueda libre y sector global.</li>
                        <li>Por fila puedes: <strong>asignar a un comercial</strong> (opcionalmente con sector preseleccionado), <strong>marcar como contacto frío</strong> o <strong>borrar el lead</strong> con sus notas.</li>
                        <li>Al asignar se envía email al comercial (si está activado) y se registra una nota automática en el timeline.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Detección de duplicados al dar de alta</h4>
                    <ul>
                        <li>En el formulario de alta, al cambiar <em>teléfono</em> o <em>email</em> se consulta el backend y se muestra un aviso amarillo "⚠ Posible duplicado" con la lista de fichas que ya tienen ese contacto.</li>
                        <li>Normalización: teléfono comparado por sus últimos 9 dígitos (descarta <code>+34</code>, espacios, guiones); email en minúsculas y validado.</li>
                        <li>Aviso <strong>no bloqueante</strong>: el admin puede crear el cliente igualmente; la respuesta del guardado incluye los duplicados detectados.</li>
                        <li>Los comerciales solo ven duplicados que ya tienen asignados (o sin asignar). Los del resto del equipo aparecen como "[no visible]".</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Origen del lead y clientes activos</h4>
                    <ul>
                        <li>Nuevo campo <code>origen_lead</code> en cada cliente con 5 valores: <em>Directo, Lead MK, Contacto frío, Referido, Web</em>.</li>
                        <li>Casilla <strong>"Es cliente activo"</strong> visible solo para administradores en la ficha.</li>
                        <li>La tabla <code>todas-las-altas-de-cliente</code> añade dos filtros nuevos: <em>Origen</em> y un check <em>Sólo activos</em>.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Entrada en vigor por sector (manual)</h4>
                    <ul>
                        <li>Cada sector contratado tiene ahora un campo de fecha <strong>"Entrada en vigor"</strong> que rellena el administrador a mano cuando lo decide.</li>
                        <li>Los comerciales lo ven en modo lectura (no pueden modificarlo).</li>
                        <li>Se guarda como JSON en la columna <code>entrada_vigor_por_sector</code>.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Notificación al asignar cliente</h4>
                    <ul>
                        <li>Cuando el admin asigna o reasigna un cliente/lead a un comercial, se envía un email automático con: nombre del cliente, origen, campaña (si aplica) y enlace directo a la ficha.</li>
                        <li>Configurable en <code>CRM → Leads MK</code>: activar/desactivar, remitente (nombre + email).</li>
                        <li>El envío queda registrado como nota tipo <em>sistema</em> en el timeline del cliente.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="novedades-1-17" class="help-section">
            <h2>Novedades v1.17 (administración)</h2>
            <div class="help-content">
                <h3>Cambios clave a partir de la versión 1.17.0</h3>

                <div class="feature-box">
                    <h4>Cobertura nacional de municipios (INE)</h4>
                    <ul>
                        <li>El selector de provincias y poblaciones incluye los <strong>52 territorios oficiales</strong> (incluye Ceuta, Melilla, Bizkaia, Gipuzkoa, A Coruña, Ourense, Illes Balears, …) y <strong>8.132 municipios</strong> del INE.</li>
                        <li>El bundle se genera con el script <code>tools/build-municipios.ps1</code>, que descarga y cachea <code>https://www.ine.es/daco/daco42/codmun/diccionario25.xlsx</code> en <code>tools/ine-municipios.xlsx</code> y produce <code>assets/data/municipios-es.json</code>.</li>
                        <li>Si el INE publica una revisión nueva, basta volver a ejecutar el script y desplegar; el JSON se sirve cacheado por el navegador.</li>
                        <li>Los nombres antiguos guardados (Vizcaya, La Coruña, Orense, Islas Baleares, Guipúzcoa, …) se normalizan automáticamente al guardar la ficha.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Historial / notas del cliente con búsqueda</h4>
                    <ul>
                        <li>Cada ficha incluye un <strong>timeline vertical</strong> con notas manuales y eventos automáticos: cambios de estado por sector, subidas y borrados de archivos, reasignaciones de comercial, presupuestos aceptados, contratos generados.</li>
                        <li>Tabla nueva <code>wp_crm_notes</code> creada en la activación (InnoDB con índice <code>FULLTEXT</code>; fallback a MyISAM si el motor lo requiere).</li>
                        <li>La búsqueda usa <strong>MATCH ... AGAINST en modo BOOLEAN</strong> con tokens prefijo (<code>palabra*</code>) y cae a <code>LIKE</code> si el FT no está disponible.</li>
                        <li>Permisos: cualquier usuario con acceso a la ficha (admin o comercial dueño) puede leer y escribir notas; sólo el autor o un admin pueden borrarlas.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Estimado de consumo por sector</h4>
                    <ul>
                        <li>Nuevo campo serializado <code>estimado_consumo</code> en <code>wp_crm_clients</code> con tres controles por sector: <em>rango</em>, <em>valor exacto</em>, <em>unidad</em>.</li>
                        <li>Catálogos definidos en <code>includes/data.php</code> (función <code>crm_get_estimado_opciones()</code>). Para añadir un rango o una unidad, edita ese array y publica una versión nueva.</li>
                        <li>El estimado <strong>sustituye a la factura</strong> a efectos de poder enviar un sector cuando el cliente no la tiene a mano.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Validación al enviar un sector</h4>
                    <ul>
                        <li>Al pulsar "Enviar" en un sector el servidor exige <strong>al menos una factura subida o el estimado informado</strong>.</li>
                        <li>El admin sigue pudiendo saltarse la validación con <em>Forzar estado</em>.</li>
                        <li>El botón "Guardar borrador" desaparece de la UI; queda <strong>"Guardar ficha"</strong> (comercial) y <strong>"Guardar y notificar comercial"</strong> (admin, que además dispara el email). El valor interno <code>borrador</code> se mantiene en BD por compatibilidad.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Uploader de archivos rediseñado (v8)</h4>
                    <ul>
                        <li>Subida automática al seleccionar archivos, varios a la vez, barra de progreso por archivo y reintentos automáticos (3) con backoff exponencial.</li>
                        <li>Soporta <strong>HEIC/HEIF</strong> (cámara iPhone), JPG, PNG, WebP y PDF; tamaño máximo <strong>32 MB</strong> por archivo.</li>
                        <li>En móvil añade <code>capture="environment"</code> para abrir directamente la cámara trasera.</li>
                        <li>Drag &amp; drop sobre la zona azul punteada también funciona en escritorio.</li>
                        <li>El handler antiguo <code>upload-btn</code> queda en cortocircuito si detecta <code>window.CRM_UPLOADER_V8</code> para evitar dobles subidas.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Operación y mantenimiento</h4>
                    <ul>
                        <li>El plugin se actualiza automáticamente desde <code>https://github.com/replantadev/crm/</code> rama <code>master</code> vía <code>yahnis-elsts/plugin-update-checker</code>.</li>
                        <li>El paquete distribuible se genera con <code>tools/build-dist.ps1</code> (.NET ZipArchive, rutas con barras correctas para WordPress).</li>
                        <li>Si un comercial avisa de un municipio que no aparece: ejecuta <code>tools/build-municipios.ps1</code> con la última hoja del INE y publica una versión.</li>
                    </ul>
                </div>
            </div>
        </section>

        <div class="help-footer">
            <div class="contact-support">
                <h3>Soporte Técnico</h3>
                <p>Para incidencias técnicas o dudas avanzadas sobre el sistema, consulta con el desarrollador.</p>
                <p>Email: info@replanta.dev</p>
                <p class="version-info">Versión del sistema: <?php echo CRM_PLUGIN_VERSION; ?> | Manual actualizado: <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('crm_guia_admin', 'crm_guia_admin_shortcode');
?>
