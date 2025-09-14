document.addEventListener('DOMContentLoaded', () => {

  /* ---------- referencias DOM ---------- */
  const table   = document.getElementById('crm-lista-altas');
  const heading = document.querySelector('h2.elementor-heading-title');
  if (!table) return;

  /* ---------- instancia DataTable reutilizable ---------- */
  let dt = null;                 // guardamos la instancia globalmente



  

/**
 * Acepta:
 *  - []                                → array de URLs
 *  - { sector1: [url,…], sector2: […]} → objeto por sector
 *  - { sector1: url, sector2: url }    → objeto con URLs sueltas
 * Devuelve siempre { sector: [url,…], … }.
 */
function normalizeSectorFiles(files) {
  if (!files) return {};
  // Si es array plano:
  if (Array.isArray(files)) {
    return { default: files };
  }
  // Si es objeto:
  if (typeof files === 'object') {
    const out = {};
    Object.entries(files).forEach(([sector, urls]) => {
      if (Array.isArray(urls)) {
        out[sector] = urls;
      } else if (typeof urls === 'string') {
        out[sector] = [urls];
      } else {
        // null, número, booleano...
        out[sector] = [];
      }
    });
    return out;
  }
  // Cualquier otro tipo:
  return {};
}

/**
 * Formatea Facturas:
 *  – recorre cada sector
 *  – por cada URL: `<a class="factura-icon factura-${sector}">📄</a>`
 */
function formatFacturas(facturas) {
  const bySector = normalizeSectorFiles(facturas);
  const html = [];
  Object.entries(bySector).forEach(([sector, urls]) => {
    urls.forEach(url => {
      html.push(
        `<a href="${url}" target="_blank" class="factura-icon factura-${sector}">📄</a>`
      );
    });
  });
  return html.length
    ? html.join(' ')
    : "<span class='no-data'>-</span>";
}

/**
 * Formatea Presupuestos + Contratos:
 * 1) presupuestos por sector → 📄 link
 * 2) contratos generados → 📃 icono
 * 3) contratos firmados por sector → 📑 link
 */
function formatPresupuestos(presupuestos = {}, contratosGenerados = [], contratosFirmados = {}) {
  const presBySector = normalizeSectorFiles(presupuestos);
  const firmBySector = normalizeSectorFiles(contratosFirmados);
  const html = [];

  // 1) presupuestos
  Object.entries(presBySector).forEach(([sector, urls]) => {
    urls.forEach(url => {
      html.push(
        `<a href="${url}" target="_blank" class="presupuesto-icon presupuesto-${sector}">📄</a>`
      );
    });
  });

  // 2) contratos generados
  contratosGenerados.forEach(sector => {
    html.push(
      `<span class="contrato-icon contrato-gen-${sector}" title="Contrato generado">📃</span>`
    );
  });

  // 3) contratos firmados
  Object.entries(firmBySector).forEach(([sector, urls]) => {
    urls.forEach(url => {
      html.push(
        `<a href="${url}" target="_blank" class="contrato-firmado-icon contrato-firm-${sector}">📑</a>`
      );
    });
  });

  return html.length
    ? html.join(' ')
    : "<span class='no-data'>-</span>";
}

/**  
 * Si en algún punto necesitas **solo** los contratos firmados:
 */
function formatFirmados(contratosFirmados) {
  const firmBySector = normalizeSectorFiles(contratosFirmados);
  const html = [];
  Object.entries(firmBySector).forEach(([sector, urls]) => {
    urls.forEach(url => {
      html.push(
        `<a href="${url}" target="_blank" class="contrato-firmado-icon contrato-firm-${sector}">📑</a>`
      );
    });
  });
  return html.length
    ? html.join(' ')
    : "<span class='no-data'>-</span>";
}



  /* ——— fin utilidades ——— */


  /* ---------- pinto o actualizo la tabla ---------- */
  function pintarTabla(data) {

    /* ❶  construimos el cuerpo HTML ---------------------------------- */
    const rowsHtml = data.map(c => `
      <tr>
        <td>${c.id}</td>
        <td data-order="${c.fecha}">${formatDates(c.fecha)}</td>
        <td>${buildClienteCell(c)}</td>
        <td>${formatFacturas(c.facturas)}</td>
        <td>${formatPresupuestos(
              c.presupuesto,
              c.contratos_generados || [],
              c.contratos_firmados  || {}
            )}</td>
        <td>${formatIntereses(c.intereses)}</td>
        <td>${formatEstado(c.estado, c.reenvios)}</td>
        <td>${formatEstadoPorSector(c.estado_por_sector)}</td>
        <td data-order="${c.actualizado_en}">
              ${formatDate(c.actualizado_en)}
        </td>
        <td>
          <a href="/editar-cliente/?client_id=${c.id}"
             class="btn btn-edit">✏️</a>
        </td>
      </tr>`).join('');

    table.querySelector('tbody').innerHTML = rowsHtml;

    /* ❷  primera vez → inicializamos -------------------------------- */
    if (!dt) {
      dt = jQuery(table).DataTable({
        columns: [
          null,
          { type: 'date' },
          null,
          null,
          null,
          null,
          null,
          null,
          { type: 'date' },
          { orderable: false }
        ],
        order: [[8, 'desc']],
        autoWidth: false,
        responsive: true,
        pageLength: 50,
        language: {
          url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
        }
      });

      /* delegación para copiar email & otros listeners  */
      attachEmailListeners(table);

    /* ❸  recarga → clear / add / draw ------------------------------- */
    } else {
      dt.clear().draw(false);       // false = conserva la paginación
      dt.rows.add(jQuery(rowsHtml)); // añade las TR ya formateadas
      dt.draw(false);
    }
  }

  /* ---------- fetch de los datos ---------- */
  const params = new URLSearchParams(window.location.search);
  const userId = params.get('user_id') || crmData.user_id;

  fetch(crmData.ajaxurl, {
    method : 'POST',
    headers: { 'Content-Type':'application/x-www-form-urlencoded' },
    body   : new URLSearchParams({
      action : 'crm_obtener_altas',
      nonce  : crmData.nonce,
      user_id: userId
    })
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) { showToast('No se encontraron clientes','error'); return; }

    pintarTabla(res.data.clientes);

    /* título dinámico ------------------------------------------------ */
    if (heading) {
      heading.textContent =
        userId != crmData.user_id
          ? `Altas de clientes de ${res.data.user_name}`
          : 'Mis altas de clientes';
    }

  })
  .catch(err => {
    console.error(err);
    showToast('Error al obtener datos','error');
  });

  /* ---------- estilos extra globales para DataTables ---------- */
  jQuery('.dataTables_wrapper').css('font-size','12px');

});

// Simple toast
function showToast(msg, tipo) {
    const toast = document.createElement("div");
    toast.className = "crm-toast " + (tipo || "info");
    toast.innerHTML = msg;
    Object.assign(toast.style, {
        position: "fixed",
        top: "25px", right: "25px",
        background: "#36bb6f",
        color: "#fff", padding: "10px 24px",
        borderRadius: "8px",
        fontSize: "1rem",
        zIndex: 99999,
        boxShadow: "0 3px 16px rgba(0,0,0,0.1)",
    });
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2400);
}
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



function formatContratos(contratos) {
    if (!contratos) return "<span class='no-data'>-</span>";
    return Object.entries(contratos)
        .map(
            ([sector, files]) =>
                files
                    .map(
                        (file) =>
                            `<a href="${file}" target="_blank" class="factura-icon contrato-${sector}">📄</a>`
                    )
                    .join(" ")
        )
        .join(" ");
}

/* 1.  Badges de intereses (sectores) */
function formatIntereses(intereses) {
    if (!intereses || !intereses.length) {
        return "<span class='no-data'>Sin intereses</span>";
    }

    return intereses
        .map(sec => {
            /* “En.”, “Al.”… abreviado opcional */
            const abre = sec.charAt(0).toUpperCase() + sec.slice(1, 3) + '.';
            return `<span class="crm-badge sector-${sec}">${abre}</span>`;
        })
        .join(' ');
}

/* 2.  Estado por sector  */
/* 2. Estado por sector  – con abreviaturas */
function formatEstadoPorSector(estadoPorSector) {

    if (typeof estadoPorSector === 'string') {
        try { estadoPorSector = JSON.parse(estadoPorSector); } catch { estadoPorSector = {}; }
    }

    /* Abreviaturas fijas para no pasarnos de ancho (máx. 4-5 car.) */
    const abre = {
        energia: 'Ene.',
        alarmas: 'Ala.',
        telecomunicaciones: 'Tel.',
        seguros: 'Seg.',
        renovables: 'Ren.'
    };

    const label = {
        borrador: 'Borrador',
        enviado: 'Enviado',
        presupuesto_generado: 'Presupuesto Generado',
        presupuesto_aceptado: 'Presupuesto Aceptado',
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


/* 3.  Estado global + reenvíos */
function formatEstado(estado, reenvios = 0) {

    const labelMap = {
        borrador: 'Borrador',
        enviado: 'Enviado',
        presupuesto_generado: 'Presupuesto Generado',
        presupuesto_aceptado: 'Presupuesto Aceptado',
        contratos_firmados: 'Contratos Firmados'
    };

    let html = `<span class="crm-badge estado-${estado}">
                    ${labelMap[estado] || estado}
                </span>`;

    if (reenvios > 0) {
        html += `<span class="crm-badge reenvio" title="Ficha reenviada ${reenvios} veces">
                    🔁 ${reenvios}
                 </span>`;
    }
    return html;
}



function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" });
}
function formatDates(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString("es-ES", { dateStyle: "short" });
}



function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

