document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("crm-alta-cliente-form");
    if (!form) {
        console.error("No se encontró el formulario #crm-alta-cliente-form.");
        return;
    }

    // ————— Validación de provincia y población —————
    const provinciaSelect = document.getElementById('provincia');
    const poblacionInput = document.getElementById('poblacion');
    
    if (provinciaSelect) {
        provinciaSelect.addEventListener('change', function() {
            validateProvincia(this);
            // Limpiar población cuando cambie la provincia
            if (poblacionInput) {
                poblacionInput.value = '';
                poblacionInput.classList.remove('valid', 'invalid');
            }
        });
    }
    
    if (poblacionInput) {
        poblacionInput.addEventListener('blur', function() {
            validatePoblacion(this, provinciaSelect?.value);
        });
        
        poblacionInput.addEventListener('input', function() {
            // Quitar estado de error mientras escribe
            this.classList.remove('invalid');
        });
    }
    
    function validateProvincia(select) {
        const provincia = select.value;
        if (!provincia) {
            select.classList.remove('valid', 'invalid');
            return false;
        }
        
        // Verificar si es una provincia válida usando el sistema de municipios
        if (window.CRM_Municipios && window.CRM_Municipios.esProvinciaValida(provincia)) {
            select.classList.add('valid');
            select.classList.remove('invalid');
            return true;
        } else {
            select.classList.add('invalid');
            select.classList.remove('valid');
            return false;
        }
    }
    
    function validatePoblacion(input, provincia) {
        const poblacion = input.value.trim();
        if (!poblacion) {
            input.classList.remove('valid', 'invalid');
            return false;
        }
        
        // Validación básica de formato
        const isValidFormat = /^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ\s\-'\.]+$/.test(poblacion) && poblacion.length >= 2;
        
        if (isValidFormat) {
            input.classList.add('valid');
            input.classList.remove('invalid');
            return true;
        } else {
            input.classList.add('invalid');
            input.classList.remove('valid');
            return false;
        }
    }

    // ————— Botones de flujo normal —————
    const saveButton = form.querySelector("button[name='crm_guardar_cliente']");
    let sendButton = form.querySelector("button[name='crm_enviar_cliente']");
    if (!sendButton) {
        sendButton = document.createElement("button");
        sendButton.type = "submit";
        sendButton.name = "crm_enviar_cliente";
        sendButton.style.display = "none";
        form.appendChild(sendButton);
    }

    // ————— Botón admin “Guardar como [Estado]” —————
    const adminCustomButton = form.querySelector("#admin-custom-button");
    const estadoHidden = form.querySelector("#estado_formulario");
    const estadoSelect = form.querySelector("select[name='estado']");
    const forzarCheckbox = document.getElementById("forzar_estado");
    if (forzarCheckbox && estadoSelect) {
        estadoSelect.disabled = !forzarCheckbox.checked;
        forzarCheckbox.addEventListener("change", () => {
            estadoSelect.disabled = !forzarCheckbox.checked;
        });
    }
    const checkboxes = Array.from(form.querySelectorAll("input[name='intereses[]']"));
    const cards = Array.from(form.querySelectorAll(".sector-card"));
    const sectors = ['energia', 'alarmas', 'telecomunicaciones', 'seguros', 'renovables'];
    // ————— Mostrar/ocultar cards por “intereses” —————
    function toggleCards() {
        const checked = new Set(
            Array.from(form.querySelectorAll("input[name='intereses[]']:checked"))
                .map(ch => ch.value)
        );

        cards.forEach(card => {
            const sector = sectors.find(s => card.classList.contains(`sector-${s}`));
            if (!sector) return;

            const hasFiles = card.querySelector(".uploaded-file") !== null;
            const chk = form.querySelector(`input[name="intereses[]"][value="${sector}"]`);
            const lbl = form.querySelector(`label[for="interes-${sector}"]`);

            // 1) muestro/oculto card
            card.style.display = (checked.has(sector) || hasFiles) ? "block" : "none";

            // 2) si hay archivos → checkbox forced + disabled + clase sector
            if (hasFiles) {
                chk.checked = true;
                chk.disabled = true;
                lbl.classList.add(`interes-disabled-with-files`, `interes-${sector}`);
                card.classList.add(`card-has-files`);
            } else {
                chk.disabled = false;
                lbl.classList.remove(`interes-disabled-with-files`, `interes-${sector}`);
                card.classList.remove(`card-has-files`);
            }
        });
    }



    // 3) Arranco una vez
    toggleCards();

    // Quitar interés desde la card (solo admin)
    document.body.addEventListener('click', async e => {
        if (!e.target.matches('.remove-interest-btn')) return;
        const sector = e.target.dataset.sector;
        const client_id = form.querySelector('input[name="client_id"]').value;

        // 1) confirm
        if (!confirm(`¿Estás seguro de que quieres quitar el interés “${sector}”? Esta acción no se puede deshacer.`)) {
            return;
        }

        // 2) invocar AJAX para quitar interés en BD
        const res = await fetch(crmData.ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'crm_quitar_interes',
                client_id: client_id,
                sector: sector,
                nonce: crmData.nonce
            })
        }).then(r => r.json());

        if (!res.success) {
            return showToast(res.data?.message || res.message || 'Error al quitar interés', 'error');
        }
        showToast(res.data?.message || res.message || 'Interés eliminado correctamente', 'success');

        // 3) limpiar UI local
        // eliminar ficheros visibles
        form.querySelectorAll(`.sector-${sector} .uploaded-file`).forEach(d => d.remove());
        // desmarcar checkbox
        const chk = form.querySelector(`input[name="intereses[]"][value="${sector}"]`);
        if (chk) chk.checked = false;
        // ocultar card
        const card = form.querySelector(`.sector-card.sector-${sector}`);
        if (card) {
            card.classList.remove('card-has-files');
            card.style.display = 'none';
        }
        // re-renderizar
        toggleCards();
    });


function showToast(msg, tipo, duration = 4000) {
    // Limitar el número máximo de toasts visibles (máximo 3)
    const existingToasts = document.querySelectorAll('.crm-toast');
    const maxToasts = 3;
    
    // Si hay demasiados toasts, remover los más antiguos
    if (existingToasts.length >= maxToasts) {
        for (let i = 0; i < existingToasts.length - maxToasts + 1; i++) {
            if (existingToasts[i]) {
                existingToasts[i].remove();
            }
        }
    }
    
    // Verificar si ya existe un toast con el mismo mensaje para evitar duplicados
    const duplicateToast = Array.from(existingToasts).find(toast => 
        toast.innerHTML === msg && toast.classList.contains(tipo || "info")
    );
    if (duplicateToast) {
        return duplicateToast;
    }
    
    const toast = document.createElement("div");
    toast.className = "crm-toast " + (tipo || "info");
    toast.innerHTML = msg;
    
    // Colores según el tipo
    let backgroundColor = "#36bb6f"; // verde por defecto
    if (tipo === "error") backgroundColor = "#dc3545"; // rojo
    if (tipo === "info") backgroundColor = "#17a2b8"; // azul
    
    // Posición dinámica basada en los toasts existentes
    const currentToasts = document.querySelectorAll('.crm-toast');
    const topPosition = 25 + (currentToasts.length * 70); // 70px de separación entre toasts
    
    Object.assign(toast.style, {
        position: "fixed",
        top: topPosition + "px", 
        right: "25px",
        background: backgroundColor,
        color: "#fff", 
        padding: "12px 24px",
        borderRadius: "8px",
        fontSize: "1rem",
        zIndex: 99999,
        boxShadow: "0 3px 16px rgba(0,0,0,0.2)",
        maxWidth: "400px",
        wordWrap: "break-word",
        opacity: "0",
        transform: "translateX(100%)",
        transition: "all 0.3s ease"
    });
    
    document.body.appendChild(toast);
    
    // Animación de entrada
    setTimeout(() => {
        toast.style.opacity = "1";
        toast.style.transform = "translateX(0)";
    }, 10);
    
    // Auto-remove con animación de salida
    setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 300);
    }, duration);
    
    return toast; // devolver el elemento para poder manipularlo
}





    // cada vez que cambie un interés
    checkboxes.forEach(chk =>
        chk.addEventListener("change", toggleCards)
    );

    // ————— Enviar por sector —————
    form.addEventListener('click', e => {
        if (!e.target.matches('.send-sector-btn')) return;
        const sector = e.target.dataset.sector;
        // inyectamos el campo para el AJAX
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'enviar_sector[]';
        h.value = sector;
        form.appendChild(h);
        // disparamos el envío AJAX
        sendClientData('crm_enviar_cliente_ajax');
    });


    // ————— Subida de archivos —————
    form.addEventListener("click", async e => {
        if (!e.target.matches(".upload-btn")) return;
        const btn = e.target;
        
        // Prevenir múltiples clics mientras se procesa
        if (btn.disabled) return;
        
        const sector = btn.dataset.sector;
        const tipo = btn.dataset.tipo; // factura|presupuesto|contrato_firmado
        const input = form.querySelector(`.upload-input[data-sector="${sector}"][data-tipo="${tipo}"]`);
        
        if (!input || !input.files.length) {
            showToast("Selecciona un archivo antes de subir.", "error");
            return;
        }

        // Solo procesar el primer archivo seleccionado para mayor estabilidad
        const file = input.files[0];
        
        // Validación del lado cliente para tipos de archivo
        const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            showToast(`Tipo de archivo no permitido. Solo se permiten JPEG, PNG y PDF.`, "error");
            return;
        }

        // Validación de tamaño (10MB)
        const maxSize = 10 * 1024 * 1024; // 10MB en bytes
        if (file.size > maxSize) {
            showToast(`El archivo excede el tamaño permitido de 10 MB.`, "error");
            return;
        }

        // Deshabilitar el botón y mostrar estado de carga
        btn.disabled = true;
        const originalText = btn.textContent;
        btn.textContent = "Subiendo...";
        
        const fd = new FormData();
        fd.append("file", file);
        fd.append("sector", sector);
        fd.append("nonce", crmData.nonce);
        
        try {
            const res = await fetch(`${crmData.ajaxurl}?action=crm_subir_${tipo}`, { 
                method: "POST", 
                body: fd 
            });
            
            const json = await res.json();
            
            if (json.success) {
                const container = btn.closest(".upload-section");
                const div = document.createElement("div");
                div.className = "uploaded-file";
                div.innerHTML = `
                    <a href="${json.data.url}" target="_blank">${json.data.name}</a>
                    <button type="button" class="remove-file-btn" data-url="${json.data.url}" data-tipo="${tipo}">×</button>
                    <input type="hidden" name="${tipo === "factura"
                        ? `facturas[${sector}][]`
                        : tipo === "presupuesto"
                            ? `presupuesto[${sector}][]`
                            : `contratos_firmados[${sector}][]`
                    }" value="${json.data.url}">`;
                container.insertBefore(div, input);
                toggleCards();
                showToast("Archivo subido correctamente", "success");

                // Emitir evento personalizado para notificar la subida
                const uploadEvent = new CustomEvent('CRM_FILE_UPLOADED', {
                    detail: {
                        tipo: tipo,
                        sector: sector,
                        url: json.data.url,
                        name: json.data.name
                    }
                });
                document.dispatchEvent(uploadEvent);
            } else {
                showToast(`Error al subir archivo: ${json.data?.message || "Error desconocido"}`, "error");
            }
        } catch (err) {
            console.error("Error AJAX:", err);
            showToast("Error de conexión al subir archivo", "error");
        } finally {
            // Restaurar el botón siempre
            btn.disabled = false;
            btn.textContent = originalText;
            input.value = ""; // Limpiar el input
        }
    });

    // ————— Eliminación de archivos —————
    form.addEventListener("click", async e => {
        if (!e.target.matches(".remove-file-btn")) return;
        const btn = e.target;
        const url = btn.dataset.url;
        const tipo = btn.dataset.tipo;
        const body = new URLSearchParams({ action: `crm_eliminar_${tipo}`, url, nonce: crmData.nonce });
        try {
            const res = await fetch(crmData.ajaxurl, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString()
            });
            const json = await res.json();
            if (json.success) {
                btn.closest(".uploaded-file").remove();
                toggleCards();
            } else {
                console.error(json.data.message);
            }
        } catch (err) {
            console.error("Error AJAX:", err);
        }
    });



    // ————— AJAX de envío y validaciones intactas —————
    const spinner = document.createElement("div");
    spinner.className = "spinner"; spinner.style.display = "none";
    const enviarSection = form.querySelector(".crm-section.enviar");
    if (enviarSection) enviarSection.appendChild(spinner);

    const toggleLoadingState = isLoading => {
        if (saveButton) saveButton.disabled = isLoading;
        if (sendButton) sendButton.disabled = isLoading;
        if (adminCustomButton) adminCustomButton.disabled = isLoading;
        spinner.style.display = isLoading ? "inline-block" : "none";
    };

    const handleFormResponse = response => {
        if (response.success) {
            showToast("¡Los datos se han guardado correctamente!", "success", 3000);
            if (response.data.redirect_url) {
                // Esperar 3 segundos antes de redirigir para que se pueda leer el toast
                setTimeout(() => {
                    window.location.href = response.data.redirect_url;
                }, 3000);
            }
        } else {
            showToast(response.data.message || "Error desconocido", "error", 5000);
        }
    };

    const sendClientData = async action => {
        const fd = new FormData(form);
        // Añado también al FormData aquellos sectores que estén "disabled" pero con archivos
        form.querySelectorAll('input[name="intereses[]"][disabled]').forEach(chk => {
            fd.append('intereses[]', chk.value);
        });

        fd.append("action", action);
        
        // Debug: mostrar lo que se está enviando
        console.log("Enviando acción:", action);
        console.log("FormData entries:");
        for (let [key, value] of fd.entries()) {
            console.log(key, value);
        }
        
        toggleLoadingState(true);
        try {
            const res = await fetch(crmData.ajaxurl, { method: "POST", body: fd });
            
            // Verificar si la respuesta HTTP es exitosa
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            
            // Obtener el texto de la respuesta primero
            const responseText = await res.text();
            console.log("Respuesta cruda del servidor:", responseText);
            
            // Intentar parsear como JSON
            let json;
            try {
                json = JSON.parse(responseText);
            } catch (parseError) {
                console.error("Error al parsear JSON:", parseError);
                console.error("Respuesta recibida:", responseText);
                
                // Buscar indicios de error de PHP
                if (responseText.includes('Fatal error') || responseText.includes('Parse error') || responseText.includes('Warning')) {
                    showToast("Error de PHP detectado. Revisa la consola para más detalles.", "error");
                } else if (responseText.includes('<html') || responseText.includes('<!DOCTYPE')) {
                    showToast("El servidor devolvió HTML en lugar de JSON. Posible error de redirección o plugin conflictivo.", "error");
                } else {
                    showToast("Respuesta del servidor no válida. Revisa la consola para más detalles.", "error");
                }
                return;
            }
            
            handleFormResponse(json);
        } catch (e) {
            console.error("Error en la solicitud:", e);
            showToast("Error de conexión: " + e.message, "error");
        } finally {
            toggleLoadingState(false);
        }
    };

    const showError = (field, msg) => {
        field.classList.add("invalid");
        let err = field.nextElementSibling;
        if (!err || !err.classList.contains("error-message")) {
            err = document.createElement("span");
            err.className = "error-message";
            err.textContent = msg;
            field.insertAdjacentElement("afterend", err);
        }
    };
    const clearError = field => {
        field.classList.remove("invalid");
        let err = field.nextElementSibling;
        if (err && err.classList.contains("error-message")) err.remove();
    };

    const getSelectedInterests = () =>
        Array.from(form.querySelectorAll("input[name='intereses[]']")).filter(c => c.checked).map(c => c.value);

    const validateForm = () => {
        let valid = true;

        // 1) Validaciones de campos obligatorios
        [
            { sel: "[name='cliente_nombre']", msg: "El nombre del cliente es obligatorio." },
            { sel: "[name='empresa']", msg: "El nombre de la empresa es obligatorio." },
            { sel: "[name='direccion']", msg: "La dirección es obligatoria." }
        ].forEach(({ sel, msg }) => {
            const inp = form.querySelector(sel);
            if (inp && !inp.value.trim()) { valid = false; showError(inp, msg); }
            else if (inp) clearError(inp);
        });

        // 2) Validación de email
        const email = form.querySelector("[name='email_cliente']");
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
            valid = false;
            showError(email, "El email no es válido.");
        } else if (email) {
            clearError(email);
        }

        // 3) Si NO es admin y NO estamos guardando borrador → exigir factura
        if (!crmData.is_admin && estadoHidden.value !== 'borrador') {
            const factura = form.querySelector(".upload-section.facturas .uploaded-file");
            if (!factura) {
                valid = false;
                showToast("Debes subir al menos una factura para enviar el cliente.", "error");
            }
        }

        // 4) Si SÍ es admin → las validaciones de presupuesto / contrato firmado
        if (crmData.is_admin) {
            const estado = estadoSelect.value;
            if (estado === "presupuesto_generado") {
                const presu = form.querySelector(".upload-section.presupuestos .uploaded-file");
                if (!presu) {
                    valid = false;
                    showToast("Debe subir al menos un presupuesto para generar el presupuesto.", "error");
                }
            }
            if (estado === "contratos_firmados") {
                const ctf = form.querySelector(".upload-section.contratos-firmados .uploaded-file");
                if (!ctf) {
                    valid = false;
                    showToast("Debe subir al menos un contrato firmado.", "error");
                }
            }
        }

        return valid;
    };


    form.addEventListener("submit", event => {
        event.preventDefault();
        const isEnviar = event.submitter?.name === "crm_enviar_cliente";
        const isGuardar = event.submitter?.name === "crm_guardar_cliente";
        const isNotificar = event.submitter?.name === "crm_guardar_notificar";
        const isCustom = event.submitter?.name === "crm_guardar_como_estado";
        const forzaEstado = forzarCheckbox?.checked;
        const pendings = Array.from(form.querySelectorAll('.upload-input'))
            .some(input => input.files.length > 0);
        if (pendings) {
            event.preventDefault();
            showToast('Tienes archivos seleccionados pero no has pulsado "Agregar Documento".\nPor favor hazlo antes de enviar.', "error");
            return false;
        }

        // 1) Enviar sin forzar → forzamos global "enviado"
        if (isEnviar && !forzaEstado) {
            estadoHidden.value = "enviado";
        }
        // 2) Guardar → "borrador"
        else if (isGuardar) {
            estadoHidden.value = "borrador";
        }
        // 3) Guardar y notificar → "actualizado"
        else if (isNotificar) {
            estadoHidden.value = "actualizado";
        }
        // 4) Custom (admin) → dejamos el valor del select

        const action = isGuardar || isCustom
            ? "crm_guardar_cliente_ajax"
            : isNotificar
            ? "crm_guardar_notificar_ajax"
            : "crm_enviar_cliente_ajax";

        if (validateForm()) sendClientData(action);
    });




    // ————— Admin custom-button y delegado —————
    if (crmData.is_admin && estadoSelect && adminCustomButton) {
        estadoSelect.addEventListener("change", () => {
            const ne = estadoSelect.value;
            estadoHidden.value = ne;
            if (ne !== originalEstado) {
                adminCustomButton.textContent = `Guardar como ${capitalize(ne.replace(/_/g, ' '))}`;
                adminCustomButton.style.display = "inline-block";
                toggleFlowButtons(false);
            } else {
                adminCustomButton.style.display = "none";
                toggleFlowButtons(true);
            }
        });
    }
    if (crmData.is_admin) {
        const del = form.querySelector("select[name='delegado']");
        const emailIn = form.querySelector("#email_comercial");
        if (del && emailIn) {
            const upd = () => {
                const m = del.options[del.selectedIndex].text.match(/\(([^)]+)\)$/);
                emailIn.value = m?.[1] || "";
            };
            del.addEventListener("change", upd);
            upd();
        }
    }

});
