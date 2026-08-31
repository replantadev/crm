<?php
/**
 * Guía de uso para usuarios de campo (comercial / visitador / instalador) - CRM v1.20.68
 * Contenido adaptado automáticamente al rol del usuario que la visita.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [crm_guia_comerciales] — pese al nombre histórico, ahora es la
 * "Guía de Usuario" general: muestra contenido distinto según el rol de
 * quien la visita (comercial, visitador o instalador). Un crm_admin /
 * administrator puede además cambiar de vista con ?vista=comercial|visitador|instalador
 * para poder revisar y dar soporte sobre lo que ve cada rol.
 */
function crm_guia_comerciales_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Necesitas <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a> para ver esta guía.</p>';
    }

    $user          = wp_get_current_user();
    $roles         = (array) $user->roles;
    $is_admin      = function_exists('crm_user_is_admin') && crm_user_is_admin();
    $is_instalador = in_array('instalador', $roles, true);
    $is_visitador  = function_exists('crm_user_is_visitador') && crm_user_is_visitador();
    $is_comercial  = function_exists('crm_user_is_comercial') && crm_user_is_comercial();

    if (!$is_admin && !$is_instalador && !$is_visitador && !$is_comercial) {
        return '<p>Acceso denegado. Esta página es para comerciales, visitadores e instaladores.</p>';
    }

    // Vista por defecto: la del propio rol del usuario.
    $vista = 'comercial';
    if ($is_instalador) {
        $vista = 'instalador';
    } elseif ($is_visitador) {
        $vista = 'visitador';
    } elseif ($is_comercial) {
        $vista = 'comercial';
    }

    // Solo un admin puede pedir explícitamente otra vista (para dar soporte).
    $switcher = '';
    if ($is_admin) {
        $solicitada = isset($_GET['vista']) ? sanitize_key(wp_unslash($_GET['vista'])) : '';
        if (in_array($solicitada, ['comercial', 'visitador', 'instalador'], true)) {
            $vista = $solicitada;
        }
        $base = remove_query_arg('vista');
        $opciones = [
            'comercial'  => 'Comercial',
            'visitador'  => 'Visitador',
            'instalador' => 'Instalador',
        ];
        $links = [];
        foreach ($opciones as $key => $label) {
            $activo = $key === $vista;
            $links[] = sprintf(
                '<a href="%s" style="padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;%s">%s</a>',
                esc_url(add_query_arg('vista', $key, $base)),
                $activo ? 'background:#191919;color:#fff;' : 'background:#f1f5f9;color:#374151;',
                esc_html($label)
            );
        }
        $switcher = '<div style="display:flex;gap:8px;justify-content:center;margin:-10px 0 30px;flex-wrap:wrap;">' . implode('', $links) . '</div>'
            . '<p style="text-align:center;color:#6b7280;font-size:13px;margin:-20px 0 30px;">Estás viendo la guía como <strong>' . esc_html($opciones[$vista]) . '</strong> (solo visible para admin, para dar soporte).</p>';
    }

    $titulos = [
        'comercial'  => ['Guía de Uso para Comerciales', 'Manual completo para el trabajo en campo con altas de clientes'],
        'visitador'  => ['Guía de Uso para Visitadores', 'Manual para gestionar tu agenda de visitas y altas de clientes'],
        'instalador' => ['Guía de Uso para Instaladores', 'Manual para gestionar tus instalaciones, materiales y cierres'],
    ];

    ob_start();
    ?>
    <div class="crm-help-container">
        <div class="crm-help-header">
            <h1><?php echo esc_html($titulos[$vista][0]); ?></h1>
            <p class="help-subtitle"><?php echo esc_html($titulos[$vista][1]); ?></p>
        </div>

        <?php echo $switcher; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <?php
        if ('visitador' === $vista) {
            echo crm_guia_usuario_render_visitador(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } elseif ('instalador' === $vista) {
            echo crm_guia_usuario_render_instalador(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo crm_guia_usuario_render_comercial(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        ?>

        <div class="help-footer">
            <div class="contact-support">
                <h3>¿Necesitas ayuda adicional?</h3>
                <p>Si tienes problemas o dudas, contacta con el administrador del CRM.</p>
                <p class="version-info">Versión del sistema: <?php echo esc_html(CRM_PLUGIN_VERSION); ?> | Última actualización: <?php echo esc_html(date('d/m/Y')); ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('crm_guia_comerciales', 'crm_guia_comerciales_shortcode');

/**
 * Contenido de la guía para el rol "comercial".
 */
function crm_guia_usuario_render_comercial() {
    ob_start();
    ?>
    <div class="help-navigation">
        <ul>
            <li><a href="#trabajo-offline">Trabajo Sin Conexión</a></li>
            <li><a href="#formulario-alta">Formulario de Alta</a></li>
            <li><a href="#estados-cliente">Estados y Seguimiento</a></li>
            <li><a href="#agenda-comercial">Mi Agenda (Visitas)</a></li>
            <li><a href="#gestion-archivos">Gestión de Archivos</a></li>
            <li><a href="#tips-ipad">Consejos para iPad / Móvil</a></li>
        </ul>
    </div>

    <section id="trabajo-offline" class="help-section">
        <h2>Trabajo Sin Conexión</h2>
        <div class="help-content">
            <h3>Qué hacer cuando no tienes conexión a Internet</h3>
            <p>El formulario de alta permite trabajar sin conexión, guardando los datos localmente en el dispositivo.</p>

            <div class="feature-box">
                <h4>Indicador de Conexión</h4>
                <ul>
                    <li><strong>Verde "Conectado":</strong> los datos se envían inmediatamente al guardar.</li>
                    <li><strong>Rojo "Sin conexión":</strong> no hay Internet, los datos se guardan localmente.</li>
                    <li><strong>Número de pendientes:</strong> clientes esperando sincronización.</li>
                </ul>
            </div>

            <div class="step-by-step">
                <h4>Cómo trabajar sin conexión:</h4>
                <ol>
                    <li>Completa el formulario normalmente, aunque no tengas Internet.</li>
                    <li>Adjunta las facturas necesarias.</li>
                    <li>Pulsa "Enviar Cliente" — se guardará localmente.</li>
                    <li>Cuando recuperes conexión, los datos se envían automáticamente.</li>
                </ol>
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
                    <li><strong>Información básica:</strong> nombre, empresa, dirección.</li>
                    <li><strong>Contacto:</strong> teléfono y email válidos.</li>
                    <li><strong>Ubicación:</strong> provincia y población (cobertura nacional, INE).</li>
                    <li><strong>Intereses:</strong> selecciona al menos un sector (chips clicables).</li>
                    <li><strong>Facturas o estimado de consumo:</strong> al menos una factura por sector, o el estimado si el cliente no la tiene a mano.</li>
                </ol>
            </div>

            <div class="warning-box">
                <h4>Aviso de posible duplicado</h4>
                <p>Al escribir el teléfono o el email, si ya existe una ficha con ese contacto verás un aviso amarillo con la lista. No te impide guardar, es solo un aviso para evitar duplicar trabajo. Solo ves los duplicados de fichas tuyas o sin asignar; el resto aparece como "[no visible]".</p>
            </div>

            <div class="feature-box">
                <h4>Estados del cliente (por sector):</h4>
                <ul>
                    <li><strong>Sin enviar:</strong> ficha creada pero todavía no se envió al administrador.</li>
                    <li><strong>Enviado:</strong> has enviado el sector al admin para que lo revise.</li>
                    <li><strong>Presupuesto Generado:</strong> el admin subió el presupuesto al sistema.</li>
                    <li><strong>Presupuesto Aceptado:</strong> el cliente aceptó la propuesta (lo marcas tú con el checkbox).</li>
                    <li><strong>Contratos Generados:</strong> el admin preparó contratos para firma.</li>
                    <li><strong>Contratos Firmados:</strong> proceso completado.</li>
                </ul>
                <p style="margin-top:10px;">Además existe un campo independiente <strong>"Estado de decisión"</strong> para explicar por qué un sector no avanza: <em>Pendiente financiación</em>, <em>Pendiente competencia</em>, <em>Decisión pendiente</em> o <em>Pendiente visita</em>.</p>
            </div>

            <div class="tip-box">
                <h4>Sectores renovables → instalación</h4>
                <p>Cuando un presupuesto de <strong>Renovables</strong> se acepta, el equipo de instalaciones puede crear una "instalación" a partir de ese presupuesto para planificar la obra, los materiales y la visita. Ese seguimiento lo llevan el jefe de instalaciones y el instalador asignado; a ti te basta con mantener el sector como "Presupuesto Aceptado" y las notas del cliente al día.</p>
            </div>
        </div>
    </section>

    <section id="estados-cliente" class="help-section">
        <h2>Seguimiento e Historial</h2>
        <div class="help-content">
            <h3>Notas y timeline del cliente</h3>
            <div class="feature-box">
                <ul>
                    <li>Cada ficha tiene un <strong>timeline vertical</strong> con notas manuales y eventos automáticos (cambios de estado, archivos subidos, reasignaciones, presupuestos aceptados).</li>
                    <li>Puedes <strong>añadir notas</strong> en cualquier momento; opcionalmente vinculadas a un sector.</li>
                    <li>Usa el <strong>buscador del historial</strong> para encontrar notas por palabra clave.</li>
                    <li>Atajo: <em>Cmd/Ctrl + Enter</em> dentro del campo de nota la guarda directamente.</li>
                </ul>
            </div>
        </div>
    </section>

    <section id="agenda-comercial" class="help-section">
        <h2>Mi Agenda (Visitas)</h2>
        <div class="help-content">
            <h3>Agendar y gestionar visitas a clientes</h3>
            <div class="step-by-step">
                <h4>Desde la ficha del cliente, bloque "Visitas":</h4>
                <ol>
                    <li>Pulsa <strong>"+ Agendar visita"</strong> e indica fecha, hora, duración, sector y notas.</li>
                    <li>Puedes asignártela a ti mismo o, si el cliente lo requiere, a un <strong>visitador</strong> (se valida su disponibilidad horaria).</li>
                    <li>Cuando se realice, márcala como <strong>Realizada</strong> (✓), <strong>No se presentó</strong> (!) o <strong>Cancelada</strong>.</li>
                </ol>
            </div>
            <p>En el menú lateral, <strong>"Mi agenda"</strong> lista todas tus visitas (teléfono clicable para llamar, dirección clicable para abrir Google Maps). Solo ves las tuyas; el administrador ve las de todo el equipo.</p>
        </div>
    </section>

    <section id="gestion-archivos" class="help-section">
        <h2>Gestión de Archivos y Facturas</h2>
        <div class="help-content">
            <h3>Cómo subir y organizar facturas correctamente</h3>

            <div class="step-by-step">
                <h4>Proceso de subida:</h4>
                <ol>
                    <li>Selecciona el sector correspondiente (Energía, Alarmas, etc.).</li>
                    <li>Elige uno o varios archivos: se suben automáticamente en cuanto los seleccionas.</li>
                    <li>Verás una barra de progreso por archivo y un OK verde al terminar.</li>
                    <li>Si la red falla, el sistema reintenta hasta 3 veces automáticamente.</li>
                </ol>
            </div>

            <div class="file-types">
                <div class="file-type"><strong>PDF</strong><p>Ideal para facturas escaneadas</p></div>
                <div class="file-type"><strong>JPG / HEIC</strong><p>Foto directa desde cámara/iPhone</p></div>
                <div class="file-type"><strong>PNG / WebP</strong><p>Capturas de pantalla</p></div>
            </div>
            <p style="color:#6b7280;font-size:13px;">Tamaño máximo 32 MB por archivo. En móvil/tablet puedes elegir cámara o galería directamente; también puedes arrastrar y soltar en escritorio.</p>
        </div>
    </section>

    <section id="tips-ipad" class="help-section">
        <h2>Consejos para iPad / Móvil</h2>
        <div class="help-content">
            <div class="step-by-step">
                <h4>Flujo de trabajo recomendado:</h4>
                <ol>
                    <li>Presenta tu tablet o móvil profesionalmente al cliente.</li>
                    <li>Explica que vas a registrar sus datos para un estudio.</li>
                    <li>Completa los datos básicos mientras conversas.</li>
                    <li>Solicita permiso para fotografiar las facturas.</li>
                    <li>Termina el registro y explica los próximos pasos.</li>
                </ol>
            </div>
            <div class="troubleshooting">
                <h4>Solución a problemas comunes:</h4>
                <ul>
                    <li><strong>Archivo no sube:</strong> verifica conexión y tamaño del archivo.</li>
                    <li><strong>Datos perdidos:</strong> revisa el indicador "Sin conexión", pueden estar en cola.</li>
                    <li><strong>App lenta:</strong> cierra y vuelve a abrir el navegador.</li>
                </ul>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Contenido de la guía para el rol "visitador".
 */
function crm_guia_usuario_render_visitador() {
    ob_start();
    ?>
    <div class="help-navigation">
        <ul>
            <li><a href="#que-es-visitador">Tu Rol</a></li>
            <li><a href="#mi-agenda-v">Mi Agenda</a></li>
            <li><a href="#altas-v">Dar de Alta un Cliente</a></li>
        </ul>
    </div>

    <section id="que-es-visitador" class="help-section">
        <h2>Tu Rol como Visitador</h2>
        <div class="help-content">
            <div class="feature-box">
                <h4>Qué puedes hacer:</h4>
                <ul>
                    <li>Ver y gestionar las <strong>visitas que te han asignado</strong> (un comercial o el administrador).</li>
                    <li><strong>Dar de alta clientes nuevos</strong> igual que un comercial (formulario, facturas, sectores).</li>
                    <li>Ver <strong>"Mis leads"</strong> si te han asignado alguno desde marketing.</li>
                </ul>
            </div>
            <div class="warning-box">
                <h4>Qué NO puedes hacer</h4>
                <p>No puedes crear visitas nuevas tú mismo (las agenda un comercial o el admin), ni ver listados generales de clientes, ni acceder a ajustes del sistema.</p>
            </div>
        </div>
    </section>

    <section id="mi-agenda-v" class="help-section">
        <h2>Mi Agenda</h2>
        <div class="help-content">
            <h3>Tus visitas asignadas</h3>
            <div class="step-by-step">
                <h4>Para cada visita puedes:</h4>
                <ol>
                    <li>Ver el <strong>teléfono</strong> del cliente (clicable, llama directamente) y la <strong>dirección</strong> (clicable, abre Google Maps).</li>
                    <li>Marcarla como <strong>Realizada</strong> (✓) al completar la visita.</li>
                    <li>Marcarla como <strong>No se presentó</strong> (!) si el cliente no estaba.</li>
                    <li>Marcarla como <strong>Cancelada</strong> si se pospone o anula.</li>
                </ol>
            </div>
            <p>Solo ves tus propias visitas asignadas. Al entrar al CRM se te redirige automáticamente aquí.</p>
        </div>
    </section>

    <section id="altas-v" class="help-section">
        <h2>Dar de Alta un Cliente</h2>
        <div class="help-content">
            <p>Desde el menú "Alta" puedes registrar un cliente nuevo con el mismo formulario que usan los comerciales: datos de contacto, sectores de interés y facturas (o estimado de consumo si el cliente no las tiene a mano). El formulario funciona también <strong>sin conexión</strong> — se guarda localmente y se envía en cuanto recuperas Internet.</p>
            <p>En "Mis altas" puedes consultar los clientes que has registrado tú.</p>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Contenido de la guía para el rol "instalador" (instaladores internos de Ecovolt).
 */
function crm_guia_usuario_render_instalador() {
    ob_start();
    ?>
    <div class="help-navigation">
        <ul>
            <li><a href="#que-es-instalador">Tu Rol</a></li>
            <li><a href="#mis-instalaciones">Mis Instalaciones</a></li>
            <li><a href="#materiales-i">Materiales y Stock</a></li>
            <li><a href="#checklist-i">Antes de Empezar: Checklist</a></li>
            <li><a href="#extras-i">Partidas Extra</a></li>
            <li><a href="#cierre-i">Cerrar una Instalación</a></li>
            <li><a href="#calendario-i">Calendario y Perfil</a></li>
        </ul>
    </div>

    <section id="que-es-instalador" class="help-section">
        <h2>Tu Rol como Instalador</h2>
        <div class="help-content">
            <div class="feature-box">
                <h4>Qué puedes hacer:</h4>
                <ul>
                    <li>Ver y trabajar sobre <strong>las instalaciones que tienes asignadas</strong> (no las de otros instaladores).</li>
                    <li>Consultar los <strong>materiales</strong> de cada instalación y si están disponibles en almacén.</li>
                    <li>Confirmar que tienes los materiales y que aceptas el <strong>plan de seguridad</strong> antes de empezar a trabajar.</li>
                    <li>Declarar <strong>partidas extra</strong> (material o trabajo no previsto) y enviarlas al cliente para que las valide.</li>
                    <li>Declarar el <strong>cierre</strong> de la instalación con fotos y observaciones.</li>
                </ul>
            </div>
            <p style="color:#6b7280;font-size:13px;">Nota: el cierre que declaras queda <strong>pendiente de aprobación</strong> por el jefe de instalaciones o un administrador antes de darse por completado.</p>
        </div>
    </section>

    <section id="mis-instalaciones" class="help-section">
        <h2>Mis Instalaciones</h2>
        <div class="help-content">
            <p>Desde el menú <strong>"Mis instalaciones"</strong> ves una tarjeta por cada instalación asignada, con la dirección, la fecha de la visita y el estado. Al pulsar en el logo del CRM también vuelves directamente a esta pantalla.</p>
        </div>
    </section>

    <section id="materiales-i" class="help-section">
        <h2>Materiales y Stock</h2>
        <div class="help-content">
            <h3>Qué significa cada estado de material</h3>
            <div class="feature-box">
                <ul>
                    <li><strong>En almacén:</strong> hay stock suficiente en el almacén de Ecovolt, puedes recogerlo.</li>
                    <li><strong>Pedido al proveedor:</strong> ya se ha pedido a Santoki; revisa la fecha de entrega estimada si aparece.</li>
                    <li><strong>Pendiente de pedir:</strong> todavía no se ha gestionado el pedido — avisa a tu jefe si la visita está próxima.</li>
                </ul>
            </div>
            <p>Estos estados los ve también tu jefe de instalaciones, que es quien decide si se pide material a Santoki (proveedor) y hace seguimiento del pedido.</p>
        </div>
    </section>

    <section id="checklist-i" class="help-section">
        <h2>Antes de Empezar: Checklist</h2>
        <div class="help-content">
            <h3>Confirmar materiales y plan de seguridad</h3>
            <p><strong>Este paso es obligatorio.</strong> No podrás marcar la instalación como realizada sin completarlo antes.</p>
            <div class="step-by-step">
                <ol>
                    <li>En la tarjeta de la instalación, pulsa <strong>"Confirmar materiales y plan de seguridad"</strong>.</li>
                    <li>Marca la casilla que confirma que tienes <strong>todos los materiales</strong> necesarios.</li>
                    <li>Abre y lee el <strong>plan de seguridad y prevención</strong> (se abre en una pestaña nueva) y marca la casilla de aceptación.</li>
                    <li>Si aparece, elige el <strong>almacén</strong> del que sale el material.</li>
                    <li>Envía la confirmación.</li>
                </ol>
            </div>
            <div class="tip-box">
                <p>Al confirmar este paso, el sistema genera y aprueba automáticamente un albarán de salida en Holded con los materiales identificados de esa instalación. Es el momento en el que el material "sale" oficialmente del almacén.</p>
            </div>
        </div>
    </section>

    <section id="extras-i" class="help-section">
        <h2>Partidas Extra</h2>
        <div class="help-content">
            <p>Si durante la instalación necesitas material o tiempo no previstos en el presupuesto original, pulsa <strong>"+ Declarar partida extra"</strong> en la tarjeta e indica descripción, horas, materiales (puedes buscarlos en el inventario) e importe. Es obligatorio adjuntar una <strong>foto de justificación</strong>.</p>
            <div class="step-by-step">
                <h4>Validación por el cliente:</h4>
                <ol>
                    <li>Al declararla, la partida queda <strong>Pendiente de enviar</strong>.</li>
                    <li>Pulsa <strong>"Enviar al cliente para validar"</strong> — el cliente recibe un email con los detalles y la foto, y un botón para aprobar o rechazar.</li>
                    <li>En cuanto el cliente responde, lo verás reflejado aquí mismo: <strong>Aprobado</strong> o <strong>Rechazado</strong>. Solo las aprobadas se suman al cierre.</li>
                </ol>
            </div>
            <p style="color:#6b7280;font-size:13px;">Si el cliente no responde por email, tu jefe de instalaciones puede validarla a mano como respaldo.</p>
        </div>
    </section>

    <section id="cierre-i" class="help-section">
        <h2>Cerrar una Instalación</h2>
        <div class="help-content">
            <h3>Marcar como realizada</h3>
            <p>Una vez completado el checklist de materiales y plan de seguridad, aparece el botón <strong>"✓ Marcar como realizada"</strong>:</p>
            <div class="step-by-step">
                <ol>
                    <li>Confirma la casilla de conformidad del trabajo realizado.</li>
                    <li>Añade observaciones si procede.</li>
                    <li>Sube la foto que se pide en cada campo (varía según sea fotovoltaica, aerotermia, o las dos a la vez si el viaje cubre ambas — inversor, placas, cuadros de protección, unidad interior/exterior…). No puedes enviar el cierre si falta alguna.</li>
                    <li>Pulsa "Enviar cierre".</li>
                </ol>
            </div>
            <div class="tip-box">
                <p>Las fotos se comprimen automáticamente en tu móvil antes de subirse, así que puedes usar la cámara sin preocuparte por el peso del archivo.</p>
            </div>
            <div class="warning-box">
                <h4>Pendiente de aprobación</h4>
                <p>Tras enviarlo verás "Cierre declarado — pendiente de que tu jefe lo apruebe". Si tu jefe detecta algo incorrecto puede rechazarlo; en ese caso podrás volver a declararlo con las correcciones.</p>
            </div>
        </div>
    </section>

    <section id="calendario-i" class="help-section">
        <h2>Calendario y Perfil</h2>
        <div class="help-content">
            <ul>
                <li><strong>Calendario:</strong> vista mensual con todas tus visitas de instalación programadas.</li>
                <li><strong>Mi perfil:</strong> tus datos de contacto para que el equipo pueda localizarte.</li>
            </ul>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
