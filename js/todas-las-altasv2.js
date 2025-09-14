document.addEventListener("DOMContentLoaded", function () {
    const tableElement = document.getElementById("crm-todas-las-altas");

    fetch(crmData.ajaxurl + "?action=crm_obtener_todas_altas", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ nonce: crmData.nonce }),
    })
        .then((response) => response.json())
        .then((result) => {
            if (result.success) {
                const tableData = result.data;
                const tableBody = tableElement.querySelector("tbody");

                tableBody.innerHTML = ""; // Limpiar la tabla actual

                tableData.forEach((cliente) => {
                    const row = document.createElement("tr");
                    console.log(cliente);
                    row.innerHTML = `
                        <td>${cliente.id}</td>
                         <td data-order="${cliente.fecha}">${formatDates(cliente.fecha)}</td>
                        <td>${cliente.cliente_nombre}</td>
                        <td>${cliente.empresa}</td>
                        <td>${cliente.email_cliente}</td>
                         <td>
                            <a href="/mis-altas-de-cliente/?user_id=${cliente.user_id}" class="comercial-link">
                                ${cliente.comercial}
                            </a>
                        </td>
                         <td>${formatIntereses(cliente.intereses)}</td>
                        <td><strong  class= "estado ${cliente.estado}">${cliente.estado.replace(/_/g, ' ')}</strong></td>
                        <td>${formatPresupuestos(cliente.presupuesto)}</td>
                        <td>${formatContratos(cliente.contratos_generados)}</td>
                        <td>${formatFirmados(cliente.contratos_firmados)}</td>
                        <td data-order="${cliente.actualizado_en}">${formatDate(cliente.actualizado_en)}</td>
                        <td class"accion">
                            <a href="/editar-cliente/?client_id=${cliente.id}" class="btn btn-edit" title="Editar">
                                ✏️
                            </a>
                            <a class="btn btn-delete" data-id="${cliente.id}" title="Borrar">🗑️</a>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });

                attachDeleteHandlers(); // Conectar los eventos de borrar
                jQuery(tableElement).DataTable({
                    columnDefs: [
                        {
                            targets: 11, // Índice de la columna "Última Edición"
                            type: "datetime",
                        },
                    ],
                    order: [[11, "desc"]], // Ordenar por la columna "Última Edición" en orden descendente
                    pageLength: 50,
                    language: {
                        url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json",
                    },
                });
            } else {
                alert("No se encontraron clientes.");
            }
            jQuery(".dataTables_wrapper").css("font-size", "12px");
        })
        .catch((error) => console.error("Error al obtener datos:", error));

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" });
    }
    function formatDates(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString("es-ES", { dateStyle: "short" });
    }

    function formatIntereses(intereses) {
        if (!intereses) return "<span class='no-data'>Sin intereses</span>";

        // Verificar si 'intereses' es un string serializado (en formato PHP)
        if (typeof intereses === "string" && intereses.startsWith("a:")) {
            // Intenta deserializar el string PHP
            try {
                // Intentar convertir el string PHP a un arreglo de JavaScript
                intereses = JSON.parse(intereses.replace(/a:\d+:/g, "").replace(/s:\d+:"(.*?)"/g, '"$1"'));
            } catch (e) {
                console.error("Error al deserializar los intereses:", e);
                return "<span class='no-data'>Error al procesar intereses</span>";
            }
        }

        // Ahora 'intereses' debería ser un array
        if (!Array.isArray(intereses)) return "<span class='no-data'>Sin intereses</span>";

        return intereses
            .map((interes) => {
                // Obtener los primeros tres caracteres y agregar un punto al final
                const abreviado = interes.charAt(0).toUpperCase() + interes.slice(1, 3) + ".";

                // Asignación de color basado en el interés
                const color = {
                    energia: "badge-energia",
                    alarmas: "badge-alarmas",
                    telecomunicaciones: "badge-telecomunicaciones",
                    seguros: "badge-seguros",
                    renovables: "badge-renovables",
                }[interes] || "badge-default";

                // Devolver el interés abreviado con su respectivo color
                return `<span class="badge ${color}">${abreviado}</span>`;
            })
            .join(" "); // Unir los intereses con un espacio
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
                    alert("Cliente borrado correctamente.");
                    location.reload(); // Recargar la tabla
                } else {
                    alert(result.data?.message || "Error al borrar el cliente.");
                }
            })
            .catch((error) => console.error("Error al borrar cliente:", error));
    }


});