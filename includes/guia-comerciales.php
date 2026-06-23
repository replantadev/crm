<?php
/**
 * Guía de uso para comerciales - CRM v1.13.1
 * Manual de funcionalidades offline, compresión de imágenes y trabajo en iPad
 */

if (!defined('ABSPATH')) {
    exit;
}

function crm_guia_comerciales_shortcode() {
    if (!current_user_can('comercial') && !current_user_can('crm_admin')) {
        return '<p>Acceso denegado. Esta página es solo para comerciales.</p>';
    }

    ob_start();
    ?>
    <div class="crm-help-container">
        <div class="crm-help-header">
            <h1>Guía de Uso para Comerciales</h1>
            <p class="help-subtitle">Manual completo para el trabajo en campo con iPad</p>
        </div>

        <div class="help-navigation">
            <ul>
                <li><a href="#trabajo-offline">Trabajo Sin Conexión</a></li>
                <li><a href="#compresion-imagenes">Optimización de Imágenes</a></li>
                <li><a href="#formulario-alta">Formulario de Alta</a></li>
                <li><a href="#gestion-archivos">Gestión de Archivos</a></li>
                <li><a href="#tips-ipad">Consejos para iPad</a></li>
            </ul>
        </div>

        <section id="trabajo-offline" class="help-section">
            <h2>Trabajo Sin Conexión</h2>
            <div class="help-content">
                <h3>Qué hacer cuando no tienes conexión a Internet</h3>
                <p>El sistema CRM permite trabajar completamente sin conexión, guardando todos tus datos localmente.</p>
                
                <div class="feature-box">
                    <h4>Indicador de Conexión</h4>
                    <p>En la esquina superior derecha verás el estado de tu conexión:</p>
                    <ul>
                        <li><strong>Verde "Conectado":</strong> Tienes conexión a Internet, los datos se envían inmediatamente</li>
                        <li><strong>Rojo "Sin conexión":</strong> No hay Internet, los datos se guardan localmente</li>
                        <li><strong>Número de pendientes:</strong> Cantidad de clientes esperando sincronización</li>
                    </ul>
                </div>

                <div class="step-by-step">
                    <h4>Cómo trabajar sin conexión:</h4>
                    <ol>
                        <li>Completa el formulario normalmente, aunque no tengas Internet</li>
                        <li>Adjunta las facturas necesarias (se comprimen automáticamente)</li>
                        <li>Pulsa "Enviar Cliente" - se guardará localmente</li>
                        <li>Verás una notificación "Cliente guardado offline"</li>
                        <li>Cuando recuperes conexión, los datos se envían automáticamente</li>
                        <li>Recibirás confirmación "Cliente sincronizado correctamente"</li>
                    </ol>
                </div>

                <div class="tip-box">
                    <h4>Ventajas del trabajo offline:</h4>
                    <ul>
                        <li>No pierdes nunca los datos introducidos</li>
                        <li>Puedes trabajar en cualquier lugar sin cobertura</li>
                        <li>Sincronización automática cuando hay conexión</li>
                        <li>Las imágenes se comprimen para usar menos datos</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="compresion-imagenes" class="help-section">
            <h2>Optimización Automática de Imágenes</h2>
            <div class="help-content">
                <h3>Cómo el sistema reduce el uso de datos móviles</h3>
                <p>Todas las imágenes se comprimen automáticamente antes de enviarlas, ahorrando hasta un 70% de datos.</p>

                <div class="feature-box">
                    <h4>Proceso automático de compresión:</h4>
                    <ul>
                        <li><strong>Detección automática:</strong> El sistema identifica fotos grandes</li>
                        <li><strong>Compresión inteligente:</strong> Reduce tamaño manteniendo calidad</li>
                        <li><strong>Ahorro de datos:</strong> Hasta 70% menos consumo de móvil</li>
                        <li><strong>Velocidad mejorada:</strong> Subidas más rápidas</li>
                    </ul>
                </div>

                <div class="step-by-step">
                    <h4>Qué sucede cuando subes una imagen:</h4>
                    <ol>
                        <li>Seleccionas la foto desde tu iPad</li>
                        <li>El sistema detecta si es mayor a 800KB</li>
                        <li>Automáticamente la comprime manteniendo calidad</li>
                        <li>Ves la barra de progreso durante la subida</li>
                        <li>Confirmación "Archivo subido y optimizado"</li>
                    </ol>
                </div>

                <div class="tip-box">
                    <h4>Recomendaciones para fotos:</h4>
                    <ul>
                        <li>Usa la cámara del iPad para mejor calidad</li>
                        <li>Asegúrate que las facturas se lean claramente</li>
                        <li>El sistema conserva la calidad necesaria automáticamente</li>
                        <li>No necesitas preocuparte por el tamaño de las fotos</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="formulario-alta" class="help-section">
            <h2>Completar el Formulario de Alta</h2>
            <div class="help-content">
                <h3>Guía paso a paso para registrar un nuevo cliente</h3>

                <div class="step-by-step">
                    <h4>Datos obligatorios:</h4>
                    <ol>
                        <li><strong>Información básica:</strong> Nombre, empresa, dirección</li>
                        <li><strong>Contacto:</strong> Teléfono y email válidos</li>
                        <li><strong>Ubicación:</strong> Provincia y población</li>
                        <li><strong>Intereses:</strong> Selecciona al menos un sector</li>
                        <li><strong>Facturas:</strong> Mínimo una factura por sector de interés</li>
                    </ol>
                </div>

                <div class="validation-info">
                    <h4>Validaciones automáticas:</h4>
                    <ul>
                        <li><strong>Teléfono:</strong> Formato español (6XX/7XX XXX XXX o 9XX XXX XXX)</li>
                        <li><strong>Email:</strong> Formato válido con @ y dominio</li>
                        <li><strong>Provincia:</strong> Debe ser una provincia oficial española</li>
                        <li><strong>Población:</strong> Mínimo 2 caracteres, solo letras y espacios</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Estados del cliente:</h4>
                    <ul>
                        <li><strong>Sin enviar:</strong> Ficha creada pero todavía no se envió al administrador.</li>
                        <li><strong>Enviado:</strong> Has enviado el sector al admin para que lo revise.</li>
                        <li><strong>Presupuesto Generado:</strong> Has subido el presupuesto al sistema.</li>
                        <li><strong>Presupuesto Aceptado:</strong> El cliente aceptó la propuesta.</li>
                        <li><strong>Contratos Generados:</strong> El admin preparó contratos para firma.</li>
                        <li><strong>Contratos Firmados:</strong> Proceso completado.</li>
                    </ul>

                    <div class="tip-box">
                        <h4>💡 Nuevo Flujo de Trabajo:</h4>
                        <ol>
                            <li>Completa los datos del cliente y súbelos presupuestos</li>
                            <li>Envía al cliente → Estado: <strong>Presupuesto Generado</strong></li>
                            <li>Cuando el cliente acepta, marca ✓ "Cliente ha aceptado presupuesto"</li>
                            <li>Aparece el botón "Enviar a Admin" para notificar aceptación</li>
                            <li>Admin genera contratos y cliente firma</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section id="gestion-archivos" class="help-section">
            <h2>Gestión de Archivos y Facturas</h2>
            <div class="help-content">
                <h3>Cómo subir y organizar facturas correctamente</h3>

                <div class="step-by-step">
                    <h4>Proceso de subida:</h4>
                    <ol>
                        <li>Selecciona el sector correspondiente (Energía, Alarmas, etc.)</li>
                        <li>Pulsa "Elegir archivos" o "Subir factura"</li>
                        <li>Selecciona una o varias facturas (máximo 5MB cada una)</li>
                        <li>Espera a que aparezca la barra de progreso</li>
                        <li>Verás confirmación de "Archivo subido correctamente"</li>
                    </ol>
                </div>

                <div class="file-types">
                    <h4>Tipos de archivo permitidos:</h4>
                    <ul>
                        <li><strong>PDF:</strong> Ideal para facturas escaneadas</li>
                        <li><strong>JPG/JPEG:</strong> Fotos de facturas</li>
                        <li><strong>PNG:</strong> Capturas de pantalla</li>
                        <li><strong>WebP:</strong> Formato optimizado</li>
                    </ul>
                </div>

                <div class="tip-box">
                    <h4>Consejos para mejores resultados:</h4>
                    <ul>
                        <li>Fotografía la factura con buena iluminación</li>
                        <li>Asegúrate que se lean todos los datos importantes</li>
                        <li>Si tienes varias páginas, súbelas todas</li>
                        <li>Usa nombres descriptivos si es posible</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="novedades-1-17" class="help-section">
            <h2>Novedades v1.17</h2>
            <div class="help-content">
                <h3>Mejoras pensadas para trabajo en campo</h3>

                <div class="feature-box">
                    <h4>Subir archivos (iPad, móvil, escritorio)</h4>
                    <ul>
                        <li>El nuevo sistema sube los archivos <strong>automáticamente</strong> en cuanto los seleccionas, sin tener que pulsar "Subir factura".</li>
                        <li>Puedes seleccionar <strong>varios archivos a la vez</strong> y se subirán en paralelo.</li>
                        <li>Verás una <strong>barra de progreso</strong> por archivo y un OK verde cuando termina.</li>
                        <li>Si la red falla, el sistema <strong>reintenta hasta 3 veces</strong> automáticamente.</li>
                        <li>Desde iPad/iPhone, al tocar la zona de subida puedes elegir <strong>cámara o galería</strong>.</li>
                        <li>Acepta JPG, PNG, HEIC (cámara de iPhone), WebP y PDF · hasta 32 MB por archivo.</li>
                        <li>También puedes <strong>arrastrar y soltar</strong> archivos sobre la zona azul punteada.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Estimado de consumo del cliente</h4>
                    <p>En cada sector (Energía, Alarmas, Teleco, Seguros, Renovables) ahora aparece un bloque "Estimado de consumo" con tres campos:</p>
                    <ul>
                        <li><strong>Rango</strong>: selecciona el tramo que se ajusta al cliente (por ejemplo "1.000 – 3.000 kWh/año").</li>
                        <li><strong>Valor exacto</strong>: si el cliente te dice un número concreto, escríbelo aquí (admite decimales con coma o punto).</li>
                        <li><strong>Unidad</strong>: kWh/año, €/mes, kWp, etc. — las opciones cambian según el sector.</li>
                    </ul>
                    <p>El estimado <strong>permite enviar el sector al administrador aunque todavía no tengas la factura</strong>. Es la alternativa cuando el cliente no la tiene a mano.</p>
                </div>

                <div class="feature-box">
                    <h4>Enviar un sector al administrador</h4>
                    <ul>
                        <li>Para enviar un sector ahora se exige <strong>al menos una factura subida O el estimado de consumo</strong> informado.</li>
                        <li>Sin alguno de los dos, el sistema te avisa y no envía nada.</li>
                        <li>Si solo quieres dejar la ficha guardada para continuar más tarde, usa <strong>"Guardar ficha"</strong>: ya no existe el botón "Guardar borrador".</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Historial y notas del cliente</h4>
                    <ul>
                        <li>Debajo de los sectores tienes un <strong>historial completo</strong> del cliente: cambios de estado, archivos subidos, reasignaciones y tus propias notas manuales.</li>
                        <li>Puedes <strong>añadir notas</strong> escritas en cualquier momento; opcionalmente las puedes vincular a un sector concreto.</li>
                        <li>Usa el <strong>buscador del historial</strong> para encontrar notas por palabra clave (por ejemplo "vuelve a llamar" o un nombre).</li>
                        <li>Atajo: <em>Cmd/Ctrl + Enter</em> dentro del campo de nota la guarda directamente.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4>Provincias y poblaciones</h4>
                    <ul>
                        <li>La lista de provincias y poblaciones ya cubre <strong>toda España</strong> (8.132 municipios oficiales del INE), no solo León.</li>
                        <li>Empieza a escribir el nombre y el sistema te sugiere coincidencias dentro de la provincia que hayas seleccionado.</li>
                        <li>Si te falta algún municipio (caso muy raro), avisa al administrador para que regenere el bundle del INE.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="tips-ipad" class="help-section">
            <h2>Consejos Específicos para iPad</h2>
            <div class="help-content">
                <h3>Optimizar tu experiencia de trabajo en campo</h3>

                <div class="feature-box">
                    <h4>Configuración recomendada:</h4>
                    <ul>
                        <li><strong>Safari:</strong> Usa siempre Safari para mejor compatibilidad</li>
                        <li><strong>Pantalla completa:</strong> Añade la página a tu pantalla de inicio</li>
                        <li><strong>Datos móviles:</strong> Activa datos cuando sea necesario</li>
                        <li><strong>Notificaciones:</strong> Permite notificaciones del navegador</li>
                    </ul>
                </div>

                <div class="step-by-step">
                    <h4>Flujo de trabajo recomendado:</h4>
                    <ol>
                        <li>Presenta tu tablet profesionalmente al cliente</li>
                        <li>Explica que vas a registrar sus datos para un estudio</li>
                        <li>Completa los datos básicos mientras conversas</li>
                        <li>Solicita permiso para fotografiar las facturas</li>
                        <li>Termina el registro y explica los próximos pasos</li>
                    </ol>
                </div>

                <div class="troubleshooting">
                    <h4>Solución a problemas comunes:</h4>
                    <ul>
                        <li><strong>Pantalla se apaga:</strong> Ajusta el tiempo de bloqueo en Configuración</li>
                        <li><strong>Archivo no sube:</strong> Verifica conexión y tamaño del archivo</li>
                        <li><strong>Datos perdidos:</strong> Revisa "Sin conexión", pueden estar en cola</li>
                        <li><strong>App lenta:</strong> Cierra Safari y vuelve a abrirlo</li>
                    </ul>
                </div>
            </div>
        </section>

        <div class="help-footer">
            <div class="contact-support">
                <h3>¿Necesitas ayuda adicional?</h3>
                <p>Si tienes problemas o dudas, contacta con el administrador del CRM.</p>
                <p class="version-info">Versión del sistema: <?php echo CRM_PLUGIN_VERSION; ?> | Última actualización: <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('crm_guia_comerciales', 'crm_guia_comerciales_shortcode');
?>
