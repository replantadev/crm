document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("crm-alta-cliente-form");
    const facturasContainer = document.getElementById("facturas-container");
    const intereses = document.querySelectorAll("input[name='intereses[]']");

    // Crear un contenedor para mensajes de validación globales
    const errorContainer = document.createElement("div");
    errorContainer.id = "validation-errors";
    errorContainer.classList.add("crm-notification", "error");
    errorContainer.style.display = "none";
    // Verifica si el formulario y la sección "enviar" existen
    if (form && form.querySelector(".crm-section.enviar")) {
        form.querySelector(".crm-section.enviar").appendChild(errorContainer);
    } else {
        console.error("El formulario o la sección '.crm-section.enviar' no se encontraron.");
    }

    // Función para obtener intereses seleccionados
    const getSelectedInterests = () =>
        Array.from(intereses)
            .filter(checkbox => checkbox.checked)
            .map(checkbox => checkbox.value);

    // Generar o eliminar contenedores de facturas según los intereses seleccionados
    const updateFacturaContainers = () => {
        const selectedInterests = getSelectedInterests();

        // Mostrar u ocultar contenedores existentes
        document.querySelectorAll(".factura-sector").forEach(container => {
            const sector = container.id.replace("factura-", "");
            container.style.display = selectedInterests.includes(sector) ? "block" : "none";
        });

        // Crear nuevos contenedores solo si no existen
        selectedInterests.forEach(sector => {
            if (!facturasContainer.querySelector(`#factura-${sector}`)) {
                const container = document.createElement("div");
                container.id = `factura-${sector}`;
                container.classList.add("factura-sector");
                container.innerHTML = `
                    <h4>Facturas de ${sector.charAt(0).toUpperCase() + sector.slice(1)}</h4>
                    <input type="file" name="facturas[${sector}][]" multiple data-sector="${sector}">
                    <div class="uploaded-files" data-sector="${sector}"></div>
                `;
                facturasContainer.appendChild(container);
                attachFileUploadHandler(container.querySelector("input[type='file']"));
            }
        });
    };

    // Función para manejar el cambio en el input file
    const attachFileChangeHandler = input => {
        input.addEventListener("change", () => {
            const sector = input.dataset.sector;
            const agregarDocumentoBtn = input.parentElement.querySelector(".agregar-documento-btn");

            // Mostrar el botón si hay archivos seleccionados
            if (input.files.length > 0) {
                agregarDocumentoBtn.style.display = "inline-block";
            } else {
                agregarDocumentoBtn.style.display = "none";
            }
        });
    };

    // Función para manejar la subida de archivos
    const attachUploadHandler = (button) => {
        button.addEventListener("click", () => {
            const sector = button.dataset.sector;
            const input = button.parentElement.querySelector(".upload-input");

            if (input.files.length === 0) {
                alert("Selecciona al menos un archivo para subir.");
                return;
            }

            // Crear un contenedor para mostrar los archivos subidos
            let uploadedFilesContainer = button.parentElement.querySelector(".uploaded-files");
            if (!uploadedFilesContainer) {
                uploadedFilesContainer = document.createElement("div");
                uploadedFilesContainer.classList.add("uploaded-files");
                uploadedFilesContainer.setAttribute("data-sector", sector);
                button.parentElement.appendChild(uploadedFilesContainer);
            }

            // Recorrer los archivos seleccionados
            Array.from(input.files).forEach((file) => {
                const formData = new FormData();
                formData.append("file", file);
                formData.append("sector", sector);
                formData.append("nonce", crmData.nonce);

                fetch(`${crmData.ajaxurl}?action=crm_subir_factura`, {
                    method: "POST",
                    body: formData,
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.success) {
                            // Mostrar archivo subido
                            const fileElement = document.createElement("div");
                            fileElement.className = "uploaded-file";
                            fileElement.innerHTML = `
                                <a href="${data.data.url}" target="_blank">${data.data.name}</a>
                                <button type="button" class="remove-file" data-url="${data.data.url}">Eliminar</button>
                            `;
                            uploadedFilesContainer.appendChild(fileElement);
                            attachRemoveFileHandler(fileElement.querySelector(".remove-file"));

                            // Crear un input oculto para guardar el archivo en el formulario
                            const hiddenInput = document.createElement("input");
                            hiddenInput.type = "hidden";
                            hiddenInput.name = `facturas[${sector}][]`;
                            hiddenInput.value = data.data.url;
                            button.parentElement.appendChild(hiddenInput);
                        } else {
                            alert(`Error al subir archivo: ${data.data.message}`);
                        }
                    });
            });

            // Limpiar el input para permitir nuevas selecciones
            input.value = "";
            button.style.display = "none";
        });
    };

    // Función para manejar la eliminación de archivos
    const attachRemoveFileHandler = button => {
        button.addEventListener("click", () => {
            const url = button.dataset.url;

            fetch(`${crmData.ajaxurl}?action=crm_eliminar_factura`, {
                method: "POST",
                body: JSON.stringify({ url, nonce: crmData.nonce }),
                headers: { "Content-Type": "application/json" },
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        button.closest(".uploaded-file").remove();
                    } else {
                        alert(`Error al eliminar archivo: ${data.message}`);
                    }
                });
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

        requiredFields.forEach(field => {
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

        if (!isValid) {
            errorContainer.style.display = "block";
            errorContainer.textContent = "Corrige los errores antes de continuar.";
        } else {
            errorContainer.style.display = "none";
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

    const clearError = field => {
        field.classList.remove("invalid");
        const error = field.nextElementSibling;
        if (error && error.classList.contains("error-message")) {
            error.remove();
        }
    };

    // Inicializar visibilidad de contenedores
    updateFacturaContainers();

    // Añadir eventos a los checkboxes
    intereses.forEach(checkbox => {
        checkbox.addEventListener("change", updateFacturaContainers);
    });

    // Inicializar eventos en inputs y botones existentes
    facturasContainer.querySelectorAll(".upload-input").forEach(input => {
        attachFileChangeHandler(input);
    });

    facturasContainer.querySelectorAll(".agregar-documento-btn").forEach(button => {
        attachUploadHandler(button);
    });

    facturasContainer.querySelectorAll(".remove-file").forEach(button => {
        attachRemoveFileHandler(button);
    });

    // Validación al enviar el formulario
    form.addEventListener("submit", e => {
        if (!validateForm()) {
            e.preventDefault();
        }
    });
    const agregarDocumentoBtn = document.getElementById("agregar-documento-btn");

    if (!facturasContainer) {
        console.error("El contenedor 'facturas-container' no está presente en el DOM.");
    } else if (agregarDocumentoBtn) {
        agregarDocumentoBtn.addEventListener("click", () => {
            const sector = prompt("Ingrese el sector (energia, alarmas, telecomunicaciones, seguros):").toLowerCase();

            if (["energia", "alarmas", "telecomunicaciones", "seguros"].includes(sector)) {
                let sectorContainer = document.querySelector(`#factura-${sector}`);

                // Si no existe el sector, crearlo dinámicamente
                if (!sectorContainer) {
                    sectorContainer = document.createElement("div");
                    sectorContainer.id = `factura-${sector}`;
                    sectorContainer.classList.add("factura-sector");
                    sectorContainer.innerHTML = `
                    <h4>Facturas de ${sector.charAt(0).toUpperCase() + sector.slice(1)}</h4>
                    <div class="uploaded-files" data-sector="${sector}"></div>
                `;
                    facturasContainer.appendChild(sectorContainer);
                }

                // Crear un nuevo input para subir archivos
                const newFileInput = document.createElement("input");
                newFileInput.type = "file";
                newFileInput.name = `facturas[${sector}][]`;
                newFileInput.multiple = true;
                sectorContainer.appendChild(newFileInput);

                // Vincular el manejador de subida al nuevo input
                attachFileChangeHandler(newFileInput);
            } else {
                alert("Sector inválido. Intente nuevamente.");
            }
        });
    } else {
        console.warn("El botón 'agregar-documento' no está presente en el DOM.");
    }

    document.querySelectorAll('input[type="file"]').forEach(input => {
        console.log(input.name, input.files);
    });


    

 
    




});
