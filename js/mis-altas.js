document.addEventListener("DOMContentLoaded", function () {
    // v1.20.201: extraído del <script> inline que llevaba crm_lista_altas()
    // (crm-plugin.php) a un fichero propio encolado vía wp_enqueue_script().
    // Bug real detectado: el <script> inline, al vivir dentro del HTML que
    // devuelve el shortcode, pasaba por el procesamiento de contenido de la
    // página (Elementor/wpautop) — cada línea en blanco del JS se convertía
    // en "</p>\n<p>", rompiendo la sintaxis y dejando `window.crmData` sin
    // definir. Resultado: la tabla "Mis altas" nunca llegaba a inicializar
    // DataTables (ni pedía los datos), así que un cliente recién editado
    // nunca podía aparecer arriba al ordenar por "Actualizado" — la tabla
    // estaba simplemente vacía. Un fichero .js externo nunca pasa por el
    // contenido de la página, así que es inmune a este problema.
    jQuery(document).ready(function ($) {
        if (typeof crmData === 'undefined') {
            console.error('crmData no está definido');
            return;
        }

        if ($.fn.DataTable.isDataTable('#crm-lista-altas')) {
            $('#crm-lista-altas').DataTable().destroy();
            $('#crm-lista-altas tbody').empty();
        }

        function getEstadoLabel(estado) {
            const labels = {
                'borrador': 'Sin enviar',
                'enviado': 'Enviado',
                'presupuesto_generado': 'Presupuesto Generado',
                'presupuesto_aceptado': 'Presupuesto Aceptado',
                'contratos_generados': 'Contratos Generados',
                'contratos_firmados': 'Contratos Firmados'
            };
            return labels[estado] || estado;
        }

        try {
            var table = $('#crm-lista-altas').DataTable({
                "processing": true,
                "serverSide": false,
                "ajax": {
                    "url": crmData.ajaxurl,
                    "type": "POST",
                    "data": function (d) {
                        d.action = 'crm_obtener_altas';
                        d.nonce = crmData.nonce;
                        d.user_id = crmData.user_id;
                        if (crmData.see_all) { d.see_all = 'true'; }
                    },
                    "dataSrc": function (json) {
                        if (json.success && json.data && json.data.data) {
                            return json.data.data;
                        }
                        return [];
                    },
                    "error": function (xhr, error, code) {
                        console.error('Error en AJAX DataTables:', error, code);
                        alert('Error al cargar los datos: ' + error);
                    }
                },
                "columns": [
                    {
                        "data": "id",
                        "className": "text-center",
                        "width": "60px"
                    },
                    {
                        "data": "fecha",
                        "render": function (data, type, row) {
                            if (type === 'display' || type === 'type') {
                                const date = new Date(data);
                                return date.toLocaleDateString('es-ES', {
                                    day: '2-digit',
                                    month: '2-digit',
                                    year: '2-digit'
                                });
                            }
                            return data;
                        }
                    },
                    {
                        "data": "cliente_nombre",
                        "render": function (data, type, row) {
                            let html = '<div class="cliente-info">';
                            html += '<strong>' + data + '</strong>';
                            if (row.empresa) {
                                html += '<span class="cliente-detalle">' + row.empresa + '</span>';
                            }
                            if (row.email_cliente) {
                                html += '<a href="mailto:' + row.email_cliente + '" class="cliente-detalle email-link">' + row.email_cliente + '</a>';
                            }
                            if (crmData.see_all && row.comercial_nombre) {
                                html += '<span class="cliente-detalle">Comercial: ' + row.comercial_nombre + '</span>';
                            }
                            html += '</div>';
                            return html;
                        },
                        "width": "250px"
                    },
                    {
                        "data": "intereses",
                        "render": function (data, type, row) {
                            if (Array.isArray(data) && data.length > 0) {
                                const labels = {
                                    energia: 'Energía', alarmas: 'Alarmas',
                                    telecomunicaciones: 'Telecom',
                                    seguros: 'Seguros', renovables: 'Renovables'
                                };
                                let html = '';
                                data.slice(0, 3).forEach(function (interes) {
                                    const k = String(interes).toLowerCase();
                                    const lbl = labels[k] || interes;
                                    html += '<span class="crm-badge sector-' + k + '">' + lbl + '</span> ';
                                });
                                if (data.length > 3) {
                                    html += '<span class="crm-badge">+' + (data.length - 3) + '</span>';
                                }
                                return html;
                            }
                            return '<em style="color:#999;">Sin intereses</em>';
                        },
                        "width": "180px"
                    },
                    {
                        "data": "estado_por_sector",
                        "render": function (data, type, row) {
                            const stepMap = {
                                borrador: 0, enviado: 1,
                                presupuesto_generado: 2, presupuesto_aceptado: 3,
                                contratos_generados: 4, contratos_firmados: 5
                            };
                            const abre = {
                                energia: 'Energía', alarmas: 'Alarmas',
                                telecomunicaciones: 'Telecom',
                                seguros: 'Seguros', renovables: 'Renovables'
                            };
                            function pill(sector, estado) {
                                const sec = String(sector).toLowerCase();
                                const step = stepMap[estado] ?? 0;
                                const lbl = getEstadoLabel(estado);
                                return '<span class="crm-estado-pill" data-sector="' + sec + '" data-step="' + step + '" title="' + (abre[sec] || sec) + ' · ' + lbl + '">' +
                                    '<span class="crm-estado-pill__dot" aria-hidden="true"></span>' +
                                    '<span class="crm-estado-pill__sector">' + (abre[sec] || sec) + '</span>' +
                                    '<span class="crm-estado-pill__sep" aria-hidden="true">·</span>' +
                                    '<span class="crm-estado-pill__estado">' + lbl + '</span>' +
                                    '</span>';
                            }
                            if (data && typeof data === 'object' && Object.keys(data).length > 0) {
                                let html = '<div style="display:flex; flex-wrap:wrap; gap:4px;">';
                                Object.entries(data).forEach(function (pair) {
                                    html += pill(pair[0], pair[1]);
                                });
                                html += '</div>';
                                return html;
                            }
                            return pill('energia', row.estado || 'borrador');
                        }
                    },
                    {
                        "data": "actualizado_en",
                        "render": function (data, type, row) {
                            if (type === 'display' || type === 'type') {
                                if (data) {
                                    const date = new Date(data);
                                    const day = date.getDate().toString().padStart(2, '0');
                                    const month = (date.getMonth() + 1).toString().padStart(2, '0');
                                    const hours = date.getHours().toString().padStart(2, '0');
                                    const minutes = date.getMinutes().toString().padStart(2, '0');
                                    return day + '/' + month + ' ' + hours + ':' + minutes + 'h';
                                }
                            }
                            return data || '-';
                        },
                        "width": "100px"
                    },
                    {
                        "data": "id",
                        "orderable": false,
                        "className": "text-center",
                        "render": function (data, type, row) {
                            const editUrl = window.location.origin + '/editar-cliente/?client_id=' + data;
                            const svgPencil = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M227.31,73.37,182.63,28.69a16,16,0,0,0-22.62,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31L227.31,96A16,16,0,0,0,227.31,73.37Z"/></svg>';
                            return '<div class="action-buttons">' +
                                '<a href="' + editUrl + '" class="action-btn action-btn--edit" title="Editar cliente" aria-label="Editar cliente">' + svgPencil + '</a>' +
                                '</div>';
                        },
                        "width": "80px"
                    }
                ],
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json"
                },
                "pageLength": 15,
                "responsive": true,
                "dom": '<"top"fl>rt<"bottom"ip><"clear">',
                "order": [[5, "desc"]],
                "columnDefs": [
                    { "targets": [6], "orderable": false }
                ]
            });
        } catch (error) {
            console.error('Error inicializando DataTable:', error);
        }

        window.eliminarCliente = function (clienteId) {
            if (confirm('¿Estás seguro de que quieres eliminar este cliente?')) {
                $.ajax({
                    url: crmData.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'crm_borrar_cliente',
                        nonce: crmData.nonce,
                        client_id: clienteId
                    },
                    success: function (response) {
                        if (response.success) {
                            table.ajax.reload();
                            alert('Cliente eliminado correctamente');
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function () {
                        alert('Error al conectar con el servidor');
                    }
                });
            }
        };
    });
});
