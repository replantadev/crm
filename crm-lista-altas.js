document.addEventListener("DOMContentLoaded", function () {
    const tableElement = document.getElementById("crm-lista-altas");
    const headingElement = document.querySelector("h2.elementor-heading-title"); // Seleccionamos el h2 con la clase específica


    if (!tableElement) return;
    const urlParams = new URLSearchParams(window.location.search);
    const userIdFromUrl = urlParams.get('user_id');  // Obtiene el user_id de la URL si existe

    // Si no se pasa el user_id en la URL, usar el user_id del usuario actual (pasado desde PHP)
    const userId = userIdFromUrl ? userIdFromUrl : crmData.user_id;

    // Si no hay un user_id (ni en la URL ni en crmData), no hacemos nada
    if (!userId) {
        console.error("No se encontró user_id en la URL ni en los datos del usuario.");
        return;
    }

    fetch(crmData.ajaxurl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
            action: "crm_obtener_altas",
            nonce: crmData.nonce,
            user_id: userId,
        }),
    })
        .then((response) => response.json())
        .then((result) => {
            if (result.success) {
                const tableData = result.data.clientes;
                const userName = result.data.user_name; // Obtener el nombre del usuario desde la respuesta
                const tableBody = tableElement.querySelector("tbody");
                tableBody.innerHTML = ""; // Limpiar la tabla actual
                tableData.forEach((cliente) => {
                    const row = document.createElement("tr");
                    row.innerHTML = `
                        <td>${cliente.id}</td>
                        <td data-order="${cliente.fecha}">${formatDates(cliente.fecha)}</td>
                        <td>${cliente.cliente_nombre}</td>
                        <td>${cliente.empresa}</td>
                        <td>${cliente.email_cliente}</td>
                        <td>${formatFacturas(cliente.facturas)}</td>
                        <td>${formatPresupuestos(cliente.presupuesto)}</td>
                        <td>${formatFirmados(cliente.contratos_firmados)}</td>
                        <td>${formatIntereses(cliente.intereses)}</td>
                        <td>${formatEstado(cliente.estado)}</td>
                        <td data-order="${cliente.actualizado_en}">
                            ${formatDate(cliente.actualizado_en)}
                        </td>
                        <td>
                            <a href="/editar-cliente/?client_id=${cliente.id}" class="btn btn-edit">✏️</a>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
                // Cambiar el texto del encabezado con el nombre del usuario
                if (headingElement) {
                    if (userId !== crmData.user_id) {
                        headingElement.textContent = `Altas de clientes de ${userName}`; // Mostrar nombre del usuario si es diferente
                    } else {
                        headingElement.textContent = 'Mis altas de clientes'; // Si es el usuario actual, mostrar el texto original
                    }
                }

                jQuery(tableElement).DataTable({
                    order: [[10, "desc"]], // Ordenar por la columna de la fecha (índice 8)
                    language: {
                        url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json",
                    },
                    responsive: true,
                    pageLength: 50, // Mostrar 50 registros por página
                });

                // Aplicar estilo global
                jQuery(".dataTables_wrapper").css("font-size", "12px");
            } else {
                alert("No se encontraron clientes.");
            }
        })
        .catch((error) => console.error("Error al obtener datos:", error));
});

function formatFacturas(facturas) {
    if (!facturas) return "<span class='no-data'>-</span>";
    return Object.entries(facturas)
        .map(
            ([sector, files]) =>
                files
                    .map(
                        (file) =>
                            `<a href="${file}" target="_blank" class="factura-icon factura-${sector}">📄</a>`
                    )
                    .join(" ")
        )
        .join(" ");
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

function formatIntereses(intereses) {
    if (!intereses) return "<span class='no-data'>Sin intereses</span>";
    return intereses
        .map((interes) => {
            const color = {
                energia: "badge-energia",
                alarmas: "badge-alarmas",
                telecomunicaciones: "badge-telecomunicaciones",
                seguros: "badge-seguros",
                renovables: "badge-renovables",
            }[interes] || "badge-default";

            return `<span class="badge ${color}">${interes}</span>`;
        })
        .join(" ");
}

function formatEstado(estado) {
    return `<span class="estado badge-${estado}">${estado.replace(/_/g, ' ')}</span>`;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" });
}
function formatDates(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString("es-ES", { dateStyle: "short" });
}
function formatPresupuestos(presupuestos) {
    if (!presupuestos) return "<span class='no-data'>Sin presupuestos</span>";
    return Object.entries(presupuestos)
        .map(([sector, files]) => {
            const fileLinks = files
                .map((file) => `<a href="${file}" target="_blank" class="presupuesto-icon">📄</a>`)
                .join(" ");
            return `<div><strong>${sector.charAt(0).toUpperCase() + sector.slice(1)}:</strong> ${fileLinks}</div>`;
        })
        .join("");
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
