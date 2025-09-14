document.addEventListener("DOMContentLoaded", function () {
    
    // Toast notification function
    function showToast(message, type = 'info', duration = 3000) {
        // Remove existing toast if any
        const existingToast = document.querySelector('.crm-toast');
        if (existingToast) {
            existingToast.remove();
        }
        
        const toast = document.createElement('div');
        toast.className = `crm-toast crm-toast-${type}`;
        toast.textContent = message;
        
        // Add styles if not already in CSS
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            max-width: 300px;
            word-wrap: break-word;
        `;
        
        // Set background color based on type
        const colors = {
            success: '#28a745',
            error: '#dc3545',
            warning: '#ffc107',
            info: '#17a2b8'
        };
        toast.style.backgroundColor = colors[type] || colors.info;
        
        document.body.appendChild(toast);
        
        // Trigger animation
        setTimeout(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        }, 10);
        
        // Auto remove
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, duration);
    }
    
    const tableElement = document.getElementById("crm-todas-las-altas");

    fetch(crmData.ajaxurl + "?action=crm_obtener_todas_altas", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ nonce: crmData.nonce }),
    })
        .then(res => res.json())
        .then(result => {
            if (!result.success) {
                return showToast("No se encontraron clientes.", "error");
            }

            // 1) Poblar la tabla
            const tbody = tableElement.querySelector("tbody");
            tbody.innerHTML = "";
            result.data.forEach(c => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
          <td>${c.id}</td>
          <td data-order="${c.fecha}">${formatDates(c.fecha)}</td>
          <td>${buildClienteCell(c)}<br>${formatIntereses(c.intereses)}</td>
          <td class="td-comercial">
            <a href="/mis-altas-de-cliente/?user_id=${c.user_id}">
                ${c.comercial}
            </a>
          </td>

          <td>${formatEstadoPorSector(c.estado_por_sector)}</td>
          <td>${documentosMatrix(c)}</td>
          <td data-order="${c.actualizado_en}">${formatDate(c.actualizado_en)}</td>
           <td class="accion">
                                <a href="/editar-cliente/?client_id=${c.id}"
                                    class="btn btn-edit" title="Editar">✏️</a>
                                <a class="btn btn-delete" data-id="${c.id}" title="Borrar">🗑️</a>
                            </td>
        `;
                tbody.appendChild(tr);
            });

            attachDeleteHandlers();
            attachEmailListeners(tableElement);

            // 2) Inicializar DataTable
            const dt = jQuery(tableElement).DataTable({
                autoWidth: false,
                responsive: true,
                columnDefs: [
                    { targets: 0, width: "60px", className: "text-center" }, // ID - ancho mínimo
                    { targets: 1, width: "100px" }, // Fecha - ancho mínimo
                    // Documentos - ancho del contenido
                    { targets: 6, width: "100px", type: "datetime" } // Última edición - ancho mínimo para fecha
                ],
                order: [[6, "desc"]],
                pageLength: 50,
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json",
                },
                dom: 'lfrtip',
                initComplete: function () {
                    const api = this.api();

                    // a) Filtro global por estado/comercial
                    jQuery.fn.dataTableExt.afnFiltering.push((settings, rows, idx) => {
                        if (settings.nTable.id !== tableElement.id) return true;
                        const $tr = jQuery(api.row(idx).node());
                        const estadoSel = jQuery('#dt-filter-estado').val();
                        const comSel = jQuery('#dt-filter-comercial').val();
                        if (estadoSel && !$tr.find(`.crm-badge.estado-${estadoSel}`).length) return false;
                        if (comSel && $tr.find('td.td-comercial').text().trim() !== comSel) return false;
                        return true;
                    });

                    // b) Convertir contenedor length en flex
                    jQuery('.dataTables_length').css({
                        display: 'flex',
                        alignItems: 'center',
                        gap: '1em'
                    });

                    // c) Wrapper para nuestros selects
                    const wrapper = jQuery('<div class="dt-extra-filters"></div>').css({
                        display: 'flex',
                        gap: '8px',
                        marginLeft: 'auto',
                        alignItems: 'center'
                    });
                    jQuery('.dataTables_length').append(wrapper);

                    // d) Select Estado (solo 4 básicos)
                    const estados = [
                        { v: '', t: 'Todos los estados' },
                        { v: 'borrador', t: 'Borrador' },
                        { v: 'presupuesto_aceptado', t: 'Presupuesto Aceptado' },
                        { v: 'contratos_generados', t: 'Contratos Generados' },
                        { v: 'contratos_firmados', t: 'Contratos Firmados' },
                    ];
                    const selE = jQuery('<select id="dt-filter-estado"></select>').on('change', () => api.draw());
                    estados.forEach(o => selE.append(`<option value="${o.v}">${o.t}</option>`));
                    wrapper.append('<label>Estado:</label>').append(selE);

                    // e) Select Comercial (únicos)
                    const comercials = [];
                    api.column(3).nodes().each(cell => {
                        const txt = jQuery(cell).text().trim();
                        if (txt && comercials.indexOf(txt) < 0) comercials.push(txt);
                    });
                    comercials.sort();
                    const selC = jQuery('<select id="dt-filter-comercial"><option value="">Todos los comerciales</option></select>')
                        .on('change', () => api.draw());
                    comercials.forEach(name => selC.append(`<option value="${name}">${name}</option>`));
                    wrapper.append('<label>Comercial:</label>').append(selC);
                }
            });

            // Reducir tamaño de fuente
            jQuery(".dataTables_wrapper").css("font-size", "12px");
        })
        .catch(console.error);

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" });
    }
    function formatDates(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString("es-ES", { dateStyle: "short" });
    }
    /* ----------  Construir celda “Cliente”  ---------- */
    function buildClienteCell(c) {
        const dir = c.direccion ? `${c.direccion}, ` : "";
        const city = c.poblacion || "";
        return `
    <strong>${c.cliente_nombre}</strong><br>
    ${c.email_cliente}<br>
    ${c.empresa}<br>
    ${dir}${city}
  `;
    }

    /* ---------- Estado por sector ---------- */
    function formatEstadoPorSector(estadoPorSector) {

        if (typeof estadoPorSector === 'string') {
            try { estadoPorSector = JSON.parse(estadoPorSector); } catch { estadoPorSector = {}; }
        }

        /* Abreviaturas fijas para no pasarnos de ancho (máx. 4-5 car.) */
        const abre = {
            energia: 'Energía',
            alarmas: 'Alarmas',
            telecomunicaciones: 'Teleco.',
            seguros: 'Seguros.',
            renovables: 'Renovables'
        };

        const label = {
            borrador: 'Borrador',
            enviado: 'Enviado',
            presupuesto_generado: 'Presupuesto Generado',
            presupuesto_aceptado: 'Presupuesto Aceptado',
            contratos_generados: 'Contratos Generados',
            contratos_firmados: 'Contratos Firmados'
        };

        return Object.entries(estadoPorSector)
            .map(([sec, est]) => `
            <span class="crm-badge estado-${est}">
                ${abre[sec] ?? (sec.charAt(0).toUpperCase() + sec.slice(1, 3) + '.')}
                : ${label[est] ?? est}
            </span>
        `)
            .join(' ');
    }


    /* ---------- Intereses / Sectores ---------- */
    function formatIntereses(intereses) {

        if (!intereses || intereses.length === 0) {
            return "<span class='no-data'>Sin intereses</span>";
        }

        /* convertir string serializado (caso legacy) */
        if (typeof intereses === "string") {
            if (intereses.startsWith("a:")) {            // viene serializado en PHP
                try {
                    intereses = JSON.parse(
                        intereses.replace(/a:\d+:/g, "")
                            .replace(/s:\d+:"(.*?)"/g, '"$1"')
                    );
                } catch { intereses = []; }
            } else {
                try { intereses = JSON.parse(intereses); } catch { intereses = []; }
            }
        }

        if (!Array.isArray(intereses)) {
            return "<span class='no-data'>Sin intereses</span>";
        }

        return intereses
            .map(sec => {
                const abre = sec.charAt(0).toUpperCase() + sec.slice(1, 7) + '.';
                return `<span class="crm-badge sector-${sec}">${abre}</span>`;
            })
            .join(' ');
    }
    /* ---------- Documentos ---------- */
    /* helper que unifica presupuestos + contratos */


    /*  🔸  formatDocumentosSector()  ───────────────────────────
 *   Devuelve una serie de <a>/<span> por sector:
 *   Ej:  F²   P¹   C    Cf¹    (color = sector)
 *   – F : facturas   – P : presupuestos
 *   – C : contrato generado (sin PDF)   – Cf : contrato firmado
 */

    /* 1️⃣  colores por sector ─ igual que en tu CSS */
    const colSector = {
        energia: '#FF6B6B', alarmas: '#FFB400',
        telecomunicaciones: '#00B8D9', seguros: '#6DD400',
        renovables: '#38B24A'
    };

    /* 2️⃣  chip genérico: <a> si hay url, <span> si no */
    const chip = (txt, n, url, col) => {
        const el = document.createElement(url ? 'a' : 'span');
        el.className = 'doc-chip';
        el.style.background = col;
        if (url) { el.href = url; el.target = '_blank'; el.rel = 'noopener'; }
        el.innerHTML = `${txt}${n ? '<sup>' + n + '</sup>' : ''}`;
        return el.outerHTML;
    };

    /* 3️⃣  una celda completa (mini-grid) */
    function documentosMatrix(data) {
        const sectores = [...new Set([
            ...Object.keys(data.facturas || {}),
            ...Object.keys(data.presupuesto || {}),
            ...Object.keys(data.contratos_firmados || {}),
            ...(data.contratos_generados || [])
        ])];

        if (!sectores.length) return "<span class='no-data'>-</span>";

        /* cabecera: abreviaturas fijas */
        const head = sectores.map(s => `<th>${abrev(s)}</th>`).join('');

        /* filas */
        const fila = (lbl, getCell) => `
      <tr><th>${lbl}</th>
          ${sectores.map(s => `<td>${getCell(s)}</td>`).join('')}
      </tr>`;

        return `
  <table class="docs-mini">
    <thead><tr><th></th>${head}</tr></thead>
    <tbody>
      ${fila('F', s => {
            const f = (data.facturas?.[s] || []);
            return f.length ? chip('', f.length, f[0], colSector[s]) : '';
        })}        ${fila('P', s => {
            const p = (data.presupuesto?.[s] || []);
            return p.length ? chip('', p.length, p[0], colSector[s]) : '';
        })}
        ${fila('C', s => {
            return (data.contratos_generados || []).includes(s)
                ? chip('✓', '', null, colSector[s]) : '';
        })}
        ${fila('Cf', s => {
            const cf = (data.contratos_firmados?.[s] || []);
            return cf.length ? chip('', cf.length, cf[0], colSector[s]) : '';
        })}
    </tbody>
  </table>`;
    }

    /* abreviaturas Ene. / Ala. / Tel… */
    const abrev = s => ({
        energia: 'Ene.', alarmas: 'Ala.',
        telecomunicaciones: 'Tel.', seguros: 'Seg.',
        renovables: 'Ren.'
    }[s] || s.slice(0, 3));




    function formatPresupuestos(presupuestos) {
        if (!presupuestos) return "<span class='no-data'>-</span>";

        return Object.entries(presupuestos)
            .map(([sector, files]) =>
                files
                    .map(file =>
                        `<a href="${file}" target="_blank"
                        class="presupuesto-icon presupuesto-${sector}">📄</a>`
                    )
                    .join(" ")
            )
            .join(" ");
    }


    function formatContratos(contratosGenerados) {
        if (!contratosGenerados) return "<span class='no-data'>Sin contratos generados</span>";
        return contratosGenerados
            .map(
                (sector) =>
                    `<span class="badge badge-contrato-generado">${sector.charAt(0).toUpperCase() + sector.slice(1)}</span>`
            )
            .join(" ");
    }
    function formatFirmados(contratosFirmados) {
        if (!contratosFirmados) return "<span class='no-data'>Sin contratos firmados</span>";
        return Object.entries(contratosFirmados)
            .map(([sector, files]) => {
                const fileLinks = files
                    .map((file) => `<a href="${file}" target="_blank" class="contrato-firmado-icon">📄</a>`)
                    .join(" ");
                return `<div><strong>${sector.charAt(0).toUpperCase() + sector.slice(1)}:</strong> ${fileLinks}</div>`;
            })
            .join("");
    }




    function attachDeleteHandlers() {
        const deleteButtons = document.querySelectorAll(".btn-delete");
        deleteButtons.forEach((button) => {
            button.addEventListener("click", () => {
                const clientId = button.dataset.id;
                if (confirm("¿Estás seguro de que deseas borrar este cliente?")) {
                    deleteClient(clientId);
                }
            });
        });
    }
    /* ------------------------------------------------------------------ */
    /*  (NUEVO)  Mostrar y copiar el e-mail                               */
    /* ------------------------------------------------------------------ */
    function attachEmailListeners(tbl) {
        tbl.addEventListener('click', async (e) => {
            const el = e.target.closest('.email-preview, .show-email-btn');
            if (!el) return;

            const email = el.dataset.email        /* 👍 ya existe                 */
                || el.closest('.td-email')?.dataset.email; /* respaldo     */

            if (!email) return;                    // seguridad

            try { await navigator.clipboard.writeText(email); } catch { }

            el.title = '¡Copiado!';
            showToast('E-mail copiado ✔️', 'success');
        });
    }




    function deleteClient(clientId) {
        const data = {
            action: "crm_borrar_cliente",
            nonce: crmData.nonce,
            client_id: clientId
        };

        console.log("Enviando datos al servidor:", data);

        fetch(crmData.ajaxurl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams(data),
        })
            .then((response) => {
                console.log("Respuesta del servidor:", response);
                return response.json();
            })
            .then((result) => {
                console.log("Datos procesados:", result);
                if (result.success) {
                    showToast("Cliente borrado correctamente.", "success");
                    setTimeout(() => location.reload(), 1500); // Recargar después del toast
                } else {
                    showToast(result.data?.message || "Error al borrar el cliente.", "error");
                }
            })
            .catch((error) => {
                console.error("Error al borrar cliente:", error);
                showToast("Error de conexión al borrar el cliente.", "error");
            });
    }


});