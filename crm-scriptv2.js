document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("crm-alta-cliente-form");
    if (!form) {
        console.error("No se encontró el formulario #crm-alta-cliente-form.");
        return;
    }

    const saveButton = form.querySelector("button[name='crm_guardar_cliente']");
    const sendButton = form.querySelector("button[name='crm_enviar_cliente']");
    const facturasContainer = document.getElementById("facturas-container");
    const presupuestosContainer = document.getElementById("presupuestos-container");
    const contratosFirmadosContainer = document.querySelector(".contratos-firmados-container");
    const intereses = form.querySelectorAll("input[name='intereses[]']");
    const spinner = document.createElement("div");

    // Configurar el spinner
    spinner.className = "spinner"; // Asegúrate de tener estilos CSS para la clase 'spinner'
    spinner.style.display = "none";
    const enviarSection = form.querySelector(".crm-section.enviar");
    if (enviarSection) {
        enviarSection.appendChild(spinner);
    } else {
        console.error("No se encontró la sección .crm-section.enviar para añadir el spinner.");
    }

    // Función para alternar el estado de carga
    const toggleLoadingState = (isLoading) => {
        if (saveButton) saveButton.disabled = isLoading;
        if (sendButton) sendButton.disabled = isLoading;
        spinner.style.display = isLoading ? "inline-block" : "none";
    };

    // Función para manejar la respuesta del servidor para el formulario
    const handleFormResponse = (response) => {
        if (response.success) {
            alert("¡Los datos se han guardado correctamente!");
            if (response.data.redirect_url) {
                window.location.href = response.data.redirect_url;
            }
        } else {
            alert(response.data.message || "Error desconocido");
        }
    };

    // Función para manejar la respuesta del servidor para las subidas de archivos
    const handleUploadResponse = (response, tipo) => {
        if (response.success) {
            alert(`Archivo ${response.data.name} subido correctamente.`);
        } else {
            alert(response.data.message || "Error desconocido");
        }
    };

    // Función para enviar datos del formulario por AJAX
    const sendClientData = async (action) => {
        const formData = new FormData(form);
        formData.append("action", action);

        toggleLoadingState(true);
        try {
            const response = await fetch(crmData.ajaxurl, {
                method: "POST",
                body: formData,
            });
            const result = await response.json();
            handleFormResponse(result);
        } catch (error) {
            console.error("Error en la solicitud:", error);
            alert("Ocurrió un error al procesar la solicitud.");
        } finally {
            toggleLoadingState(false);
        }
    };

    // Función para validar el formulario antes de enviar
    const validateForm = () => {
        let isValid = true;
        const requiredFields = [
            { selector: "[name='cliente_nombre']", message: "El nombre del cliente es obligatorio." },
            { selector: "[name='empresa']", message: "El nombre de la empresa es obligatorio." },
            { selector: "[name='direccion']", message: "La dirección es obligatoria." },
        ];

        requiredFields.forEach(({ selector, message }) => {
            const input = form.querySelector(selector);
            if (input && !input.value.trim()) {
                isValid = false;
                showError(input, message);
            } else if (input) {
                clearError(input);
            }
        });

        const emailField = form.querySelector("[name='email_cliente']");
        if (emailField && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value)) {
            isValid = false;
            showError(emailField, "El email no es válido.");
        } else if (emailField) {
            clearError(emailField);
        }

        // Validación adicional para administradores
        if (crmData.is_admin) {
            const estadoSelect = form.querySelector('select[name="estado"]');
            if (estadoSelect) {
                const selectedEstado = estadoSelect.value;
                if (selectedEstado === 'presupuesto_generado' && !presupuestosContainer.querySelector(".uploaded-file")) {
                    isValid = false;
                    alert("Debe subir al menos un presupuesto para generar el presupuesto.");
                }
                if (selectedEstado === 'contratos_firmados' && (!contratosFirmadosContainer || !contratosFirmadosContainer.querySelector(".uploaded-file"))) {
                    isValid = false;
                    alert("Debe subir al menos un contrato firmado.");
                }
            }
        }

        return isValid;
    };

    // Funciones para mostrar y limpiar errores
    const showError = (field, message) => {
        field.classList.add("invalid"); // Asegúrate de tener estilos CSS para la clase 'invalid'
        let error = field.nextElementSibling;
        if (!error || !error.classList.contains("error-message")) {
            error = document.createElement("span");
            error.className = "error-message"; // Asegúrate de tener estilos CSS para 'error-message'
            error.textContent = message;
            field.insertAdjacentElement("afterend", error);
        }
    };

    const clearError = (field) => {
        field.classList.remove("invalid");
        const error = field.nextElementSibling;
        if (error && error.classList.contains("error-message")) {
            error.remove();
        }
    };

    // Función para capitalizar la primera letra
    const capitalize = (str) => str.charAt(0).toUpperCase() + str.slice(1);

    // Obtener intereses seleccionados
    const getSelectedInterests = () =>
        Array.from(intereses)
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => checkbox.value);

    // Actualizar contenedores de facturas según intereses seleccionados
    const updateFacturaContainers = () => {
        const selectedInterests = getSelectedInterests();

        document.querySelectorAll(".factura-sector").forEach((container) => {
            const sector = container.id.replace("factura-", "");
            container.style.display = selectedInterests.includes(sector) ? "block" : "none";
        });

        // Actualizar la visibilidad de los presupuestos en base a las facturas subidas
        updatePresupuestoContainers();
    };

    // Actualizar contenedores de presupuestos según facturas subidas
    const updatePresupuestoContainers = () => {
        document.querySelectorAll(".presupuesto-sector").forEach((container) => {
            const sector = container.id.replace("presupuesto-", "");
            const facturaUploaded = facturasContainer.querySelector(`#factura-${sector} .uploaded-file`);
            container.style.display = facturaUploaded ? "block" : "none";
        });
    };

    // Función para adjuntar manejadores de cambio de archivo
    const attachFileChangeHandler = (input) => {
        console.log("Cambio detectado en el input", input); // Depuración
        input.addEventListener("change", () => {
            const sector = input.dataset.sector;
            console.log("Archivos seleccionados:", input.files); // Verificar selección de archivos
            const parentElement = input.closest(".factura-sector, .presupuesto-sector, .contrato-firmado-sector");
            const agregarDocumentoBtn = parentElement.querySelector(".agregar-documento-btn");
    
            if (input.files.length > 0 && agregarDocumentoBtn) {
                agregarDocumentoBtn.style.display = "inline-block"; // Mostrar el botón
            } else if (agregarDocumentoBtn) {
                agregarDocumentoBtn.style.display = "none"; // Ocultar si no hay archivos
            }
        });
    };
    
    

    // Función para manejar la subida de archivos al hacer clic en "Agregar Documento"
    const attachUploadHandler = (button, tipo) => {
        button.addEventListener("click", async () => {
            const sector = button.dataset.sector;
            const input = button.parentElement.querySelector(".upload-input");

            if (!input || input.files.length === 0) {
                alert("Selecciona al menos un archivo para subir.");
                return;
            }

            const uploadedFilesContainer = button.parentElement.querySelector(".uploaded-files");
            const tipoAccion = `crm_subir_${tipo}`; // e.g., crm_subir_factura

            for (const file of input.files) {
                const formData = new FormData();
                formData.append("file", file);
                formData.append("sector", sector);
                formData.append("nonce", crmData.nonce);

                try {
                    const response = await fetch(`${crmData.ajaxurl}?action=${tipoAccion}`, {
                        method: "POST",
                        body: formData,
                    });

                    const result = await response.json();

                    if (result.success && result.data.url && result.data.name) {
                        const fileElement = document.createElement("div");
                        fileElement.className = "uploaded-file";
                        fileElement.innerHTML = `
                            <a href="${result.data.url}" target="_blank">${result.data.name}</a>
                            <button type="button" class="remove-file" data-url="${result.data.url}">X</button>`;
                        uploadedFilesContainer.appendChild(fileElement);
                        attachRemoveFileHandler(fileElement.querySelector(".remove-file"));

                        const hiddenInput = document.createElement("input");
                        hiddenInput.type = "hidden";
                        // Ajuste en el nombre del campo oculto para 'contrato_firmado'
                        if (tipo === 'contrato_firmado') {
                            hiddenInput.name = `contratos_firmados[${sector}][]`;
                        } else {
                            hiddenInput.name = `${tipo}s[${sector}][]`; // e.g., facturas[energia][] o presupuestos[energia][]
                        }
                        hiddenInput.value = result.data.url;
                        button.parentElement.appendChild(hiddenInput);

                        // Actualizar contenedores si es necesario
                        if (tipo === 'factura') {
                            updatePresupuestoContainers();
                        }

                        updateSubmitButtonState();
                        handleUploadResponse(result, tipo);
                    } else {
                        console.error("Error en la respuesta del servidor:", result);
                        alert(`Error al subir archivo: ${result.message || "Error desconocido"}`);
                    }
                } catch (error) {
                    console.error("Error al subir archivo:", error);
                    alert(`Error al procesar la solicitud: ${error.message}`);
                }
            }

            if (button) {
                button.style.display = "none";
            }

            if (input) {
                input.value = "";
            }

            updateSubmitButtonState(); // Asegurar que el estado se actualice después de subir archivos
        });
    };

    // Manejar la eliminación de archivos subidos
    const attachRemoveFileHandler = (button) => {
        button.addEventListener("click", async () => {
            const url = button.dataset.url;

            if (!url) {
                alert("No se encontró la URL del archivo a eliminar.");
                return;
            }

            // Determinar el tipo de archivo basado en el contenedor padre
            let tipo = 'factura';
            const parentContainer = button.closest(".factura-sector, .presupuesto-sector, .contrato-firmado-sector");
            if (parentContainer) {
                if (parentContainer.classList.contains("presupuesto-sector")) {
                    tipo = 'presupuesto';
                } else if (parentContainer.classList.contains("contrato-firmado-sector")) {
                    tipo = 'contrato_firmado';
                }
            }

            const tipoAccion = `crm_eliminar_${tipo}`; // e.g., crm_eliminar_factura

            try {
                const formData = new URLSearchParams();
                formData.append("action", tipoAccion);
                formData.append("url", url);
                formData.append("nonce", crmData.nonce);

                const response = await fetch(crmData.ajaxurl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData.toString(),
                });

                const result = await response.json();

                if (result.success) {
                    const uploadedFileDiv = button.closest(".uploaded-file");
                    if (uploadedFileDiv) {
                        uploadedFileDiv.remove();
                    }
                    // También eliminar el input oculto correspondiente
                    const hiddenInput = button.parentElement.querySelector(`input[type="hidden"][value="${url}"]`);
                    if (hiddenInput) {
                        hiddenInput.remove();
                    }

                    updateSubmitButtonState();
                    alert(result.message || "Archivo eliminado correctamente.");
                } else {
                    alert(`Error al eliminar archivo: ${result.data.message}`);
                }
            } catch (error) {
                console.error("Error al eliminar archivo:", error);
                alert(`Error al procesar la solicitud: ${error.message}`);
            }
        });
    };

    // Manejar el envío del formulario
    form.addEventListener("submit", (event) => {
        event.preventDefault(); // Prevenir el envío predeterminado

        const action =
            event.submitter && event.submitter.name === "crm_guardar_cliente"
                ? "crm_guardar_cliente_ajax"
                : "crm_enviar_cliente_ajax";

        if (validateForm()) {
            sendClientData(action);
        }
    });

    // Manejar cambios en el selector de estado para administradores
    if (crmData.is_admin) {
        const estadoSelect = form.querySelector('select[name="estado"]');
        if (estadoSelect) {
            estadoSelect.addEventListener('change', () => {
                form.querySelector('#estado_formulario').value = estadoSelect.value;
                updateSubmitButtonState();
            });

            // Inicializar el estado_formulario según el estado actual
            form.querySelector('#estado_formulario').value = estadoSelect.value;
        }
    }

    // Función para actualizar el estado del botón de envío y el campo oculto
    const updateSubmitButtonState = () => {
        if (crmData.is_admin) {
            const estadoSelect = form.querySelector('select[name="estado"]');
            const selectedEstado = estadoSelect ? estadoSelect.value : 'enviado';

            sendButton.textContent = capitalize(selectedEstado.replace('_', ' '));
            form.querySelector("#estado_formulario").value = selectedEstado;
        } else {
            const presupuestosUploaded = presupuestosContainer.querySelector(".uploaded-file");
            const contratosFirmadosUploaded = contratosFirmadosContainer
                ? contratosFirmadosContainer.querySelector(".uploaded-file")
                : null;

            if (presupuestosUploaded) {
                sendButton.textContent = "Generar Presupuesto";
                form.querySelector("#estado_formulario").value = "presupuesto_generado";
            } else if (contratosFirmadosUploaded) {
                sendButton.textContent = "Contratos Firmados";
                form.querySelector("#estado_formulario").value = "contratos_firmados";
            } else {
                sendButton.textContent = "Enviar Cliente";
                form.querySelector("#estado_formulario").value = "enviado";
            }
        }
    };

    // Inicializar contenedores y eventos
    updateFacturaContainers();
    intereses.forEach((checkbox) => checkbox.addEventListener("change", updateFacturaContainers));

    // Adjuntar manejadores de cambio de archivo a todos los upload-input existentes
    document.querySelectorAll(".upload-input").forEach((input) => attachFileChangeHandler(input));

    // Adjuntar manejadores de subida a todos los botones "Agregar Documento" existentes
    document.querySelectorAll(".agregar-documento-btn").forEach((button) => {
        const parentClasses = Array.from(button.parentElement.classList);
        let tipo = 'factura'; // Predeterminado
        if (parentClasses.includes('presupuesto-sector')) {
            tipo = 'presupuesto';
        } else if (parentClasses.includes('contrato-firmado-sector')) {
            tipo = 'contrato_firmado';
        }
        attachUploadHandler(button, tipo);
    });

    // Adjuntar manejadores de eliminación a todos los botones "Eliminar Archivo" existentes
    document.querySelectorAll(".remove-file").forEach((button) => attachRemoveFileHandler(button));
    // Actualizar el estado del botón de envío
    updateSubmitButtonState();
});
