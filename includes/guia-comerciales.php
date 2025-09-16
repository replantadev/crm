/**
 * Guía de uso para comerciales - CRM v1.13.0
 * Manual de funcionalidades offline, compresión de imágenes y trabajo en iPad
 */

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
                        <li>Verás una confirmación de "Datos guardados localmente"</li>
                        <li>Cuando recuperes conexión, los datos se sincronizarán automáticamente</li>
                    </ol>
                </div>

                <div class="warning-box">
                    <h4>Importante:</h4>
                    <ul>
                        <li>No cierres el navegador hasta que veas "Conectado" y sin pendientes</li>
                        <li>El sistema guarda automáticamente cada 30 segundos</li>
                        <li>Si cierras por error, al volver se restaurará tu borrador</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="compresion-imagenes" class="help-section">
            <h2>Optimización Automática de Imágenes</h2>
            <div class="help-content">
                <h3>El sistema optimiza tus fotos automáticamente</h3>
                <p>Para mejorar la velocidad de subida y ahorrar datos móviles, las imágenes se comprimen automáticamente.</p>

                <div class="feature-box">
                    <h4>Qué hace el sistema:</h4>
                    <ul>
                        <li>Reduce el tamaño de las fotos hasta un 70% manteniendo la calidad</li>
                        <li>Redimensiona imágenes muy grandes a un tamaño óptimo</li>
                        <li>Convierte automáticamente a formato JPEG optimizado</li>
                        <li>Te muestra cuánto espacio has ahorrado</li>
                    </ul>
                </div>

                <div class="tip-box">
                    <h4>Recomendaciones:</h4>
                    <ul>
                        <li>Toma fotos directamente con la cámara del iPad para mejor calidad</li>
                        <li>No te preocupes por el tamaño, el sistema lo optimiza automáticamente</li>
                        <li>Si ves "Procesando...", espera unos segundos a que termine</li>
                        <li>Las facturas PDF no se comprimen, solo las imágenes</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="formulario-alta" class="help-section">
            <h2>Formulario de Alta de Clientes</h2>
            <div class="help-content">
                <h3>Cómo completar correctamente una alta</h3>

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
                        <li><strong>Borrador:</strong> Datos guardados pero no enviados</li>
                        <li><strong>Enviado:</strong> Cliente completado y enviado al CRM Admin</li>
                        <li><strong>En proceso:</strong> CRM Admin está trabajando con él</li>
                        <li><strong>Finalizado:</strong> Proceso completado</li>
                    </ul>
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
                        <li><strong>Imágenes:</strong> JPG, JPEG, PNG, WebP</li>
                        <li><strong>Documentos:</strong> PDF</li>
                        <li><strong>Tamaño máximo:</strong> 5MB por archivo</li>
                    </ul>
                </div>

                <div class="tip-box">
                    <h4>Consejos importantes:</h4>
                    <ul>
                        <li>Asegúrate de que las facturas sean legibles y de buena calidad</li>
                        <li>Incluye facturas de los últimos 12 meses si es posible</li>
                        <li>Si el archivo es muy grande, el sistema lo comprimirá automáticamente</li>
                        <li>Puedes subir múltiples facturas a la vez</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="tips-ipad" class="help-section">
            <h2>Consejos para Uso en iPad</h2>
            <div class="help-content">
                <h3>Optimiza tu experiencia de trabajo móvil</h3>

                <div class="ipad-tips">
                    <h4>Mejores prácticas:</h4>
                    <ul>
                        <li><strong>Safari recomendado:</strong> Usa Safari para mejor rendimiento</li>
                        <li><strong>Pantalla completa:</strong> Añade a pantalla de inicio para experiencia de app</li>
                        <li><strong>Modo horizontal:</strong> Gira el iPad para más espacio de formulario</li>
                        <li><strong>Teclado externo:</strong> Conecta un teclado para entrada más rápida</li>
                    </ul>
                </div>

                <div class="battery-tips">
                    <h4>Ahorro de batería:</h4>
                    <ul>
                        <li>Reduce el brillo de pantalla en exteriores</li>
                        <li>Cierra otras apps mientras trabajas</li>
                        <li>Usa modo avión + WiFi cuando tengas WiFi estable</li>
                        <li>El trabajo offline consume menos batería</li>
                    </ul>
                </div>

                <div class="troubleshooting">
                    <h4>Solución de problemas comunes:</h4>
                    <ul>
                        <li><strong>Formulario no responde:</strong> Recarga la página, los datos se restaurarán</li>
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

    <style>
    .crm-help-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        line-height: 1.6;
        color: #333;
    }

    .crm-help-header {
        text-align: center;
        margin-bottom: 40px;
        padding: 30px 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
    }

    .crm-help-header h1 {
        margin: 0 0 10px 0;
        font-size: 2.5em;
        font-weight: 300;
    }

    .help-subtitle {
        margin: 0;
        opacity: 0.9;
        font-size: 1.1em;
    }

    .help-navigation {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 30px;
    }

    .help-navigation ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
    }

    .help-navigation a {
        color: #667eea;
        text-decoration: none;
        font-weight: 500;
        padding: 8px 16px;
        border-radius: 6px;
        transition: all 0.3s ease;
    }

    .help-navigation a:hover {
        background: #667eea;
        color: white;
    }

    .help-section {
        margin-bottom: 50px;
        padding: 30px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .help-section h2 {
        color: #2c3e50;
        border-bottom: 3px solid #667eea;
        padding-bottom: 10px;
        margin-top: 0;
    }

    .feature-box, .warning-box, .tip-box {
        padding: 20px;
        margin: 20px 0;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }

    .feature-box {
        background: #f0f7ff;
        border-left-color: #3b82f6;
    }

    .warning-box {
        background: #fef2f2;
        border-left-color: #ef4444;
    }

    .tip-box {
        background: #f0fdf4;
        border-left-color: #10b981;
    }

    .step-by-step ol {
        counter-reset: step-counter;
        list-style: none;
        padding-left: 0;
    }

    .step-by-step li {
        counter-increment: step-counter;
        margin-bottom: 15px;
        padding-left: 40px;
        position: relative;
    }

    .step-by-step li::before {
        content: counter(step-counter);
        position: absolute;
        left: 0;
        top: 0;
        background: #667eea;
        color: white;
        width: 25px;
        height: 25px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }

    .validation-info, .file-types, .ipad-tips, .battery-tips, .troubleshooting {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin: 20px 0;
    }

    .help-footer {
        text-align: center;
        padding: 30px;
        background: #2c3e50;
        color: white;
        border-radius: 12px;
        margin-top: 50px;
    }

    .version-info {
        font-size: 0.9em;
        opacity: 0.8;
        margin-top: 10px;
    }

    @media (max-width: 768px) {
        .crm-help-container {
            padding: 10px;
        }
        
        .help-navigation ul {
            flex-direction: column;
        }
        
        .help-section {
            padding: 20px;
        }
        
        .crm-help-header h1 {
            font-size: 2em;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('crm_guia_comerciales', 'crm_guia_comerciales_shortcode');
