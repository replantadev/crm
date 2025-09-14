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
                        <td>${cliente.cliente_nombre}</td>
                        <td>${cliente.empresa}</td>
                        <td>${cliente.email_cliente}</td>
                        <td>${cliente.comercial}</td>
                        <td>${cliente.estado}</td>
                        <td data-order="${cliente.actualizado_en}">${formatDate(cliente.actualizado_en)}</td>
                        <td>
                            <a href="/editar-cliente/?client_id=${cliente.id}" class="btn btn-edit" title="Editar">
                                ✏️
                            </a>
                            <button class="btn btn-delete" data-id="${cliente.id}" title="Borrar">🗑️</button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });

                attachDeleteHandlers(); // Conectar los eventos de borrar
                jQuery(tableElement).DataTable({
                    columnDefs: [
                        {
                            targets: 6, // Índice de la columna "Última Edición"
                            type: "datetime",
                        },
                    ],
                    order: [[6, "desc"]], // Ordenar por la columna "Última Edición" en orden descendente
                    language: {
                        url: "https://cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json",
                    },
                });
            } else {
                alert("No se encontraron clientes.");
            }
        })
        .catch((error) => console.error("Error al obtener datos:", error));

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString("es-ES", { dateStyle: "short", timeStyle: "short" });
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
        fetch(crmData.ajaxurl, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "crm_borrar_cliente", nonce: crmData.nonce, client_id: clientId }),
        })
            .then((response) => response.json())
            .then((result) => {
                if (result.success) {
                    alert("Cliente borrado correctamente.");
                    location.reload(); // Recargar la tabla
                } else {
                    alert(result.data.message || "Error al borrar el cliente.");
                }
            })
            .catch((error) => console.error("Error al borrar cliente:", error));
    }
});