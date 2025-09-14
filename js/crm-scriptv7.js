document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("crm-alta-cliente-form");
    if (!form) {
        console.error("No se encontró el formulario #crm-alta-cliente-form.");
        return;
    }

    // Botones del flujo normal:
    const saveButton = form.querySelector("button[name='crm_guardar_cliente']");
    const sendButton = form.querySelector("button[name='crm_enviar_cliente']");
    // Botón especial “Guardar como [Estado]”
    const adminCustomButton = form.querySelector("#admin-custom-button");

    // Campo hidden con el estado actual
    const estadoHidden = form.querySelector("#estado_formulario");
    // Select de estado (solo visible/útil para admin)
    const estadoSelect = form.querySelector("select[name='estado']");

    // Guardar el estado original con el que cargó la página:
    const originalEstado = estadoHidden ? estadoHidden.value : "borrador";
    const forzarCheckbox = document.getElementById("forzar_estado");
    //const estadoSelect = document.getElementById("estado");
    if (forzarCheckbox && estadoSelect) {
        forzarCheckbox.addEventListener("change", () => {
            if (forzarCheckbox.checked) {
                estadoSelect.disabled = false;
            } else {
                estadoSelect.disabled = true;
            }
        });
        // Al cargar la página:
        estadoSelect.disabled = !forzarCheckbox.checked;
    }

    // -------------------------------------------------------------------------
    // 1) Función para capitalizar (para texto del botón):
    function capitalize(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    // -------------------------------------------------------------------------
    // 2) Mostrar/ocultar los botones del flujo normal
    function toggleFlowButtons(show) {
        // show = true => se muestran
        // show = false => se ocultan
        if (saveButton) saveButton.style.display = show ? "inline-block" : "none";
        if (sendButton) sendButton.style.display = show ? "inline-block" : "none";
    }

    // -------------------------------------------------------------------------
    // Referencias a contenedores y campos
    const facturasContainer = document.getElementById("facturas-container");
    const presupuestosContainer = document.getElementById("presupuestos-container");
    const contratosFirmadosContainer = document.querySelector(".contratos-firmados-container");
    const intereses = form.querySelectorAll("input[name='intereses[]']");

    // Spinner
    const spinner = document.createElement("div");
    spinner.className = "spinner";
    spinner.style.display = "none";

    const enviarSection = form.querySelector(".crm-section.enviar");
    if (enviarSection) {
        enviarSection.appendChild(spinner);
    } else {
        console.error("No se encontró la sección .crm-section.enviar para añadir el spinner.");
    }

    // -------------------------------------------------------------------------
    // Función para alternar el estado de carga (deshabilitar botones, etc.)
    const toggleLoadingState = (isLoading) => {
        if (saveButton) saveButton.disabled = isLoading;
        if (sendButton) sendButton.disabled = isLoading;
        if (adminCustomButton) adminCustomButton.disabled = isLoading;
        spinner.style.display = isLoading ? "inline-block" : "none";
    };

    // -------------------------------------------------------------------------
    // Respuesta del servidor al procesar el formulario
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

    // -------------------------------------------------------------------------
    // Respuesta del servidor al subir archivos
    const handleUploadResponse = (response, tipo) => {
        if (response.success) {
            alert(`Archivo ${response.data.name} subido correctamente.`);
        } else {
            alert(response.data.message || "Error desconocido");
        }
    };

    // -------------------------------------------------------------------------
    // Enviar datos del formulario por AJAX
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

    // -------------------------------------------------------------------------
    // Validar formulario antes de enviar
    const validateForm = () => {
        let isValid = true;

        // Campos requeridos
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

        // Validar email
        const emailField = form.querySelector("[name='email_cliente']");
        if (emailField && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value)) {
            isValid = false;
            showError(emailField, "El email no es válido.");
        } else if (emailField) {
            clearError(emailField);
        }

        // Si NO es admin, requerimos al menos una factura
        if (!crmData.is_admin) {
            const facturasUploaded = facturasContainer.querySelector(".uploaded-file");
            if (!facturasUploaded) {
                isValid = false;
                alert("Debes subir al menos una factura para enviar el cliente.");
            }
        }
        // Si es admin, validaciones según el estado:
        else {
            if (estadoSelect) {
                const selectedEstado = estadoSelect.value;
                // Si "presupuesto_generado", verificar al menos un presupuesto
                if (selectedEstado === 'presupuesto_generado' &&
                    !presupuestosContainer.querySelector(".uploaded-file")) {
                    isValid = false;
                    alert("Debe subir al menos un presupuesto para generar el presupuesto.");
                }
                // Si "contratos_firmados", verificar un contrato subido
                if (selectedEstado === 'contratos_firmados' &&
                    (!contratosFirmadosContainer || !contratosFirmadosContainer.querySelector(".uploaded-file"))) {
                    isValid = false;
                    alert("Debe subir al menos un contrato firmado.");
                }
            }
        }

        return isValid;
    };

    // -------------------------------------------------------------------------
    // Mostrar error
    const showError = (field, message) => {
        field.classList.add("invalid");
        let error = field.nextElementSibling;
        if (!error || !error.classList.contains("error-message")) {
            error = document.createElement("span");
            error.className = "error-message";
            error.textContent = message;
            field.insertAdjacentElement("afterend", error);
        }
    };

    // Quitar error
    const clearError = (field) => {
        field.classList.remove("invalid");
        const error = field.nextElementSibling;
        if (error && error.classList.contains("error-message")) {
            error.remove();
        }
    };

    // -------------------------------------------------------------------------
    // Obtener intereses seleccionados
    const getSelectedInterests = () =>
        Array.from(intereses)
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => checkbox.value);

    // -------------------------------------------------------------------------
    // Mostrar/ocultar contenedores de facturas
    const updateFacturaContainers = () => {
        const selectedInterests = getSelectedInterests();
        document.querySelectorAll(".factura-sector").forEach((container) => {
            const sector = container.id.replace("factura-", "");
            container.style.display = selectedInterests.includes(sector) ? "block" : "none";
        });
        // Actualizar la visibilidad de los presupuestos
        updatePresupuestoContainers();
    };

    // -------------------------------------------------------------------------
    // Mostrar/ocultar contenedores de presupuestos según facturas
    const updatePresupuestoContainers = () => {
        document.querySelectorAll(".presupuesto-sector").forEach((container) => {
            const sector = container.id.replace("presupuesto-", "");
            const facturaUploaded = facturasContainer.querySelector(`#factura-${sector} .uploaded-file`);
            container.style.display = facturaUploaded ? "block" : "none";
        });
    };

    // -------------------------------------------------------------------------
    // Manejador de cambios en inputs de archivo
    const attachFileChangeHandler = (input) => {
        input.addEventListener("change", () => {
            const parentElement = input.closest(".factura-sector, .presupuesto-sector, .contrato-firmado-sector");
            const agregarDocumentoBtn = parentElement.querySelector(".agregar-documento-btn");

            if (input.files.length > 0 && agregarDocumentoBtn) {
                agregarDocumentoBtn.style.display = "inline-block";
            } else if (agregarDocumentoBtn) {
                agregarDocumentoBtn.style.display = "none";
            }
        });
    };

    // -------------------------------------------------------------------------
    // Subir archivo al hacer clic en “Agregar Documento”
    const attachUploadHandler = (button, tipo) => {
        button.addEventListener("click", async () => {
            const sector = button.dataset.sector;
            const input = button.parentElement.querySelector(".upload-input");

            if (!input || input.files.length === 0) {
                alert("Selecciona al menos un archivo para subir.");
                return;
            }

            const uploadedFilesContainer = button.parentElement.querySelector(".uploaded-files");
            const tipoAccion = `crm_subir_${tipo}`; // e.g. crm_subir_factura

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
                        // Añadir un div con el enlace y un botón de eliminar
                        const fileElement = document.createElement("div");
                        fileElement.className = "uploaded-file";
                        fileElement.innerHTML = `
                            <a href="${result.data.url}" target="_blank">${result.data.name}</a>
                            <button type="button" class="remove-file" data-url="${result.data.url}">X</button>`;

                        uploadedFilesContainer.appendChild(fileElement);
                        attachRemoveFileHandler(fileElement.querySelector(".remove-file"));

                        // Input hidden con la URL
                        const hiddenInput = document.createElement("input");
                        hiddenInput.type = "hidden";
                        if (tipo === 'contrato_firmado') {
                            hiddenInput.name = `contratos_firmados[${sector}][]`;
                        } else {
                            hiddenInput.name = `${tipo}[${sector}][]`;
                        }
                        hiddenInput.value = result.data.url;
                        button.parentElement.appendChild(hiddenInput);

                        // Actualizar contenedores si es una factura
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

            // Ocultar el botón y limpiar el input
            button.style.display = "none";
            input.value = "";
            updateSubmitButtonState();
        });
    };

    // -------------------------------------------------------------------------
    // Eliminar archivo subido
    const attachRemoveFileHandler = (button) => {
        button.addEventListener("click", async () => {
            const url = button.dataset.url;
            if (!url) {
                alert("No se encontró la URL del archivo a eliminar.");
                return;
            }

            // Determinar el tipo basado en la clase contenedora
            let tipo = 'factura';
            const parentContainer = button.closest(".factura-sector, .presupuesto-sector, .contrato-firmado-sector");
            if (parentContainer) {
                if (parentContainer.classList.contains("presupuesto-sector")) {
                    tipo = 'presupuesto';
                } else if (parentContainer.classList.contains("contrato-firmado-sector")) {
                    tipo = 'contrato_firmado';
                }
            }
            const tipoAccion = `crm_eliminar_${tipo}`;

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
                    // Remover también el input hidden que contenga esa URL
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

    // -------------------------------------------------------------------------
    // Manejar el envío del formulario
    form.addEventListener("submit", (event) => {
        event.preventDefault();

        // Por defecto, asumimos que se pulsó “enviar cliente”
        let action = "crm_enviar_cliente_ajax";

        if (event.submitter) {
            switch (event.submitter.name) {
                case "crm_guardar_cliente":
                    action = "crm_guardar_cliente_ajax";
                    break;
                // Botón “Marcar presupuesto aceptado” (flujo normal)
                case "crm_marcar_presupuesto_aceptado":
                    action = "crm_marcar_presupuesto_aceptado";
                    break;
                // Botón especial “Guardar como X” (nuevo)
                case "crm_guardar_como_estado":
                    action = "crm_enviar_cliente_ajax";
                    break;
                default:
                    action = "crm_enviar_cliente_ajax";
                    break;
            }
        }

        // Validación
        if (validateForm()) {
            sendClientData(action);
        }
    });

    // -------------------------------------------------------------------------
    // Manejar cambios en el <select name="estado"> (solo Admin)
    if (crmData.is_admin && estadoSelect && adminCustomButton) {
        estadoSelect.addEventListener("change", () => {
            const newEstado = estadoSelect.value;
            if (estadoHidden) {
                estadoHidden.value = newEstado;
            }

            // Si es diferente al original, mostramos el botón especial
            if (newEstado !== originalEstado) {
                adminCustomButton.textContent = `Guardar como ${capitalize(newEstado.replace('_', ' '))}`;
                adminCustomButton.style.display = "inline-block";
                // Ocultar los botones del flujo normal
                toggleFlowButtons(false);
            } else {
                // Volvió al estado original => restauramos botones normales
                adminCustomButton.style.display = "none";
                toggleFlowButtons(true);
            }
            updateSubmitButtonState();
        });
    }

    // -------------------------------------------------------------------------
    // Manejar cambios en el <select name="delegado"> para email
    if (crmData.is_admin) {
        const delegadoSelect = form.querySelector('select[name="delegado"]');
        const emailComercialInput = form.querySelector('#email_comercial');
        if (delegadoSelect && emailComercialInput) {
            const updateEmailComercial = () => {
                const selectedOption = delegadoSelect.options[delegadoSelect.selectedIndex];
                const email = selectedOption.text.match(/\(([^)]+)\)$/);
                emailComercialInput.value = email ? email[1] : '';
            };
            delegadoSelect.addEventListener('change', updateEmailComercial);
            updateEmailComercial();
        }
    }

    // -------------------------------------------------------------------------
    // Función para actualizar el estado del botón de envío y el campo oculto
    const updateSubmitButtonState = () => {
        if (crmData.is_admin) {
            const estadoSelect = form.querySelector('select[name="estado"]');
            const selectedEstado = estadoSelect ? estadoSelect.value : 'enviado';

            // Solo si sendButton existe
            if (sendButton) {
                sendButton.textContent = capitalize(selectedEstado.replace('_', ' '));
            }

            estadoHidden.value = selectedEstado;

        } else {
            // Lógica para rol "comercial"
            const presupuestosUploaded = presupuestosContainer.querySelector(".uploaded-file");
            const contratosFirmadosUploaded = contratosFirmadosContainer
                ? contratosFirmadosContainer.querySelector(".uploaded-file")
                : null;

            if (presupuestosUploaded && sendButton) {
                sendButton.textContent = "Generar Presupuesto";
                estadoHidden.value = "presupuesto_generado";
            } else if (contratosFirmadosUploaded && sendButton) {
                sendButton.textContent = "Contratos Firmados";
                estadoHidden.value = "contratos_firmados";
            } else if (sendButton) {
                sendButton.textContent = "Enviar Cliente";
                estadoHidden.value = "enviado";
            }
        }
    };


    // -------------------------------------------------------------------------
    // Inicializar contenedores y eventos
    updateFacturaContainers();
    intereses.forEach((checkbox) => checkbox.addEventListener("change", updateFacturaContainers));

    // Adjuntar manejadores de cambio de archivo a todos los .upload-input
    document.querySelectorAll(".upload-input").forEach((input) => attachFileChangeHandler(input));

    // Adjuntar manejadores de subida a todos los botones "Agregar Documento"
    document.querySelectorAll(".agregar-documento-btn").forEach((button) => {
        const parentClasses = Array.from(button.parentElement.classList);
        let tipo = 'factura';
        if (parentClasses.includes('presupuesto-sector')) {
            tipo = 'presupuesto';
        } else if (parentClasses.includes('contrato-firmado-sector')) {
            tipo = 'contrato_firmado';
        }
        attachUploadHandler(button, tipo);
    });

    // Adjuntar manejadores de eliminación a todos los botones "Eliminar Archivo"
    document.querySelectorAll(".remove-file").forEach((button) => attachRemoveFileHandler(button));

    // Actualizar el estado del botón de envío
    updateSubmitButtonState();

});
