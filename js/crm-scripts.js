document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("crm-alta-cliente-form");
    const saveButton = form.querySelector("button[name='crm_guardar_cliente']");
    const sendButton = form.querySelector("button[name='crm_enviar_cliente']");
    const facturasContainer = document.getElementById("facturas-container");
    const intereses = document.querySelectorAll("input[name='intereses[]']");
    const spinner = document.createElement("div");

    // Spinner para indicar acción en progreso
    spinner.className = "spinner";
    spinner.style.display = "none";
    form.querySelector(".crm-section.enviar").appendChild(spinner);

    // Función para bloquear/desbloquear UI durante las solicitudes
    const toggleLoadingState = (isLoading) => {
        saveButton.disabled = isLoading;
        if (sendButton) sendButton.disabled = isLoading;
        spinner.style.display = isLoading ? "inline-block" : "none";
    };

    // Función para manejar la respuesta del servidor
    const handleResponse = (response) => {
        if (response.success) {
            window.location.href = response.data.redirect_url; // Redirigir a la URL proporcionada
        } else {
            alert(response.data.message || "Error desconocido");
        }
    };

    // Función para enviar los datos del formulario por AJAX
    const sendClientData = async (action) => {
        const formData = new FormData(form);
        formData.append("action", action);
        formData.append("crm_nonce", crmData.nonce);

        toggleLoadingState(true);
        formData.forEach((value, key) => {
            console.log(key, value);
        });
        try {
            const response = await fetch(crmData.ajaxurl, {
                method: "POST",
                body: formData,
            });
            const result = await response.json();
            handleResponse(result);
        } catch (error) {
            alert("Ocurrió un error al procesar la solicitud.");
        } finally {
            toggleLoadingState(false);
        }
    };

    const cleanFormData = (formData) => {
        for (const pair of formData.entries()) {
            if (pair[1] instanceof File) {
                if (pair[1].size === 0 || !pair[1].name) {
                    formData.delete(pair[0]);
                }
            }
        }
    };

    // Manejar los botones de guardar y enviar
    saveButton.addEventListener("click", (event) => {
        event.preventDefault();
        if (validateForm()) {
           // cleanFormData(formData);
            sendClientData("crm_guardar_cliente_ajax");
        }
    });
    if (sendButton) {
        sendButton.addEventListener("click", (event) => {
            event.preventDefault();
            if (validateForm()) sendClientData("crm_enviar_cliente_ajax");
        });
    }

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

        selectedInterests.forEach((sector) => {
            if (!facturasContainer.querySelector(`#factura-${sector}`)) {
                const container = document.createElement("div");
                container.id = `factura-${sector}`;
                container.classList.add("factura-sector");
                container.innerHTML = `
                    <h4>Facturas de ${sector.charAt(0).toUpperCase() + sector.slice(1)}</h4>
                    <input type="file" name="facturas[${sector}][]" multiple data-sector="${sector}" class="upload-input">
                    <button type="button" class="agregar-documento-btn" data-sector="${sector}" style="display: none;">Agregar Documento</button>
                    <div class="uploaded-files" data-sector="${sector}"></div>
                `;
                facturasContainer.appendChild(container);
                attachFileChangeHandler(container.querySelector(".upload-input"));
            }
        });
    };


    // Manejo del cambio en los inputs de archivos
    const attachFileChangeHandler = (input) => {
        input.addEventListener("change", () => {
            const sector = input.dataset.sector;
            const agregarDocumentoBtn = input.parentElement.querySelector(".agregar-documento-btn");
    
            // Mostrar el botón si hay archivos válidos seleccionados
            const hasValidFiles = Array.from(input.files).some(file => file.size > 0 && file.name);
    
            if (hasValidFiles) {
                console.log(`Archivos válidos seleccionados en el sector ${sector}`);
                agregarDocumentoBtn.style.display = "inline-block";
            } else {
                console.log(`Sin archivos válidos en el sector ${sector}`);
                agregarDocumentoBtn.style.display = "none";
            }
        });
    };
    

    // Subida de archivos por AJAX
    // Subida de archivos por AJAX
const attachUploadHandler = (button) => {
    button.addEventListener("click", () => {
        const sector = button.dataset.sector;
        const input = button.parentElement.querySelector(".upload-input");

        if (input.files.length === 0) {
            alert("Selecciona al menos un archivo para subir.");
            return;
        }

        const uploadedFilesContainer =
            button.parentElement.querySelector(".uploaded-files") ||
            button.parentElement.appendChild(document.createElement("div"));
        uploadedFilesContainer.classList.add("uploaded-files");
        uploadedFilesContainer.setAttribute("data-sector", sector);

        Array.from(input.files).forEach(async (file) => {
            const formData = new FormData();
            formData.append("file", file);
            formData.append("sector", sector);
            formData.append("nonce", crmData.nonce);

            try {
                const response = await fetch(`${crmData.ajaxurl}?action=crm_subir_factura`, {
                    method: "POST",
                    body: formData,
                });

                if (!response.ok) {
                    // Respuesta HTTP no exitosa
                    const responseText = await response.text();
                    console.error(`Error HTTP (${response.status}): ${responseText}`);
                    alert(`Error en la subida del archivo: ${response.status} - ${response.statusText}`);
                    return;
                }

                const data = await response.json();

                if (data.success) {
                    console.log(`Archivo subido correctamente: ${data.url}`);
                    const fileElement = document.createElement("div");
                    fileElement.className = "uploaded-file";
                    fileElement.innerHTML = `
                        <a href="${data.url}" target="_blank">${data.name}</a>
                        <button type="button" class="remove-file" data-url="${data.url}">Eliminar</button>
                    `;
                    uploadedFilesContainer.appendChild(fileElement);
                    attachRemoveFileHandler(fileElement.querySelector(".remove-file"));

                    const hiddenInput = document.createElement("input");
                    hiddenInput.type = "hidden";
                    hiddenInput.name = `facturas[${sector}][]`;
                    hiddenInput.value = data.url;
                    button.parentElement.appendChild(hiddenInput);
                } else {
                    console.error(`Error en respuesta del servidor: ${data.message}`);
                    alert(`Error al subir archivo: ${data.message}`);
                }
            } catch (error) {
                // Errores de red o problemas generales
                console.error("Error en la solicitud de subida:", error);
                alert(`Ocurrió un error al procesar la solicitud: ${error.message}`);
            }
        });

        // Limpiar el input para permitir nuevas selecciones
        input.value = "";
        button.style.display = "none"; // Ocultar botón
    });
};



    // Eliminar archivos subidos
    const attachRemoveFileHandler = (button) => {
        button.addEventListener("click", () => {
            const url = button.dataset.url;

            fetch(`${crmData.ajaxurl}?action=crm_eliminar_factura`, {
                method: "POST",
                body: JSON.stringify({ url, nonce: crmData.nonce }),
                headers: { "Content-Type": "application/json" },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.success) {
                        button.closest(".uploaded-file").remove();
                    } else {
                        alert(`Error al eliminar archivo: ${data.message}`);
                    }
                });
        });
    };

    const attachAddDocumentHandler = (button) => {
        button.addEventListener("click", () => {
            const sector = button.dataset.sector;
            const sectorContainer = document.querySelector(`#factura-${sector}`);
            
            if (!sectorContainer) {
                console.error(`Contenedor para el sector ${sector} no encontrado.`);
                return;
            }
    
            // Crear un nuevo input de archivo
            const newFileInput = document.createElement("input");
            newFileInput.type = "file";
            newFileInput.name = `facturas[${sector}][]`;
            newFileInput.multiple = true;
            newFileInput.classList.add("upload-input");
            newFileInput.dataset.sector = sector;
    
            // Añadir el nuevo input al contenedor del sector
            sectorContainer.appendChild(newFileInput);
    
            // Vincular el manejador de cambio al nuevo input
            attachFileChangeHandler(newFileInput);
        });
    };
    
    // Validación del formulario
    const validateForm = () => {
        let isValid = true;
        const requiredFields = [
            { selector: "[name='cliente_nombre']", message: "El nombre del cliente es obligatorio." },
            { selector: "[name='empresa']", message: "El nombre de la empresa es obligatorio." },
            { selector: "[name='direccion']", message: "La dirección es obligatoria." },
        ];

        requiredFields.forEach((field) => {
            const input = form.querySelector(field.selector);
            if (!input.value.trim()) {
                isValid = false;
                showError(input, field.message);
            } else {
                clearError(input);
            }
        });

        const emailField = form.querySelector("[name='email_cliente']");
        if (emailField && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value)) {
            isValid = false;
            showError(emailField, "El email no es válido.");
        } else {
            clearError(emailField);
        }

        return isValid;
    };

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

    const clearError = (field) => {
        field.classList.remove("invalid");
        const error = field.nextElementSibling;
        if (error && error.classList.contains("error-message")) {
            error.remove();
        }
    };

    // Inicializar
    updateFacturaContainers();
    
    // Inicializar manejadores de archivo
    document.querySelectorAll(".upload-input").forEach((input) => {
        attachFileChangeHandler(input);
    });

    // Inicializar manejadores de botón "Agregar Documento"
    document.querySelectorAll(".agregar-documento-btn").forEach((button) => {
        attachAddDocumentHandler(button);
    });

    // Actualizar contenedores dinámicamente según intereses
    intereses.forEach((checkbox) =>
        checkbox.addEventListener("change", updateFacturaContainers)
    );



});

