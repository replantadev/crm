<?php
/**
 * Guía de uso para CRM Admin - CRM v1.13.0
 * Manual de funcionalidades administrativas, gestión de contratos y notificaciones
 */

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
                            <strong>Borrador</strong>
                            <p>Cliente creado pero no enviado</p>
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
                        <li>Limpia clientes en borrador antiguos periódicamente</li>
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
