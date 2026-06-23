/* global crmData */
/**
 * CRM Energitel - Uploader v8 (iPad-friendly).
 *
 * Reemplaza al manejador antiguo de `crm-scriptv7.js` para subir
 * facturas/presupuestos/contratos. Características:
 *
 *  - Multi-archivo: acepta varios archivos por interacción.
 *  - Auto-subida: se dispara en `change` del input, sin botón.
 *  - Drag & drop sobre la `.upload-section` (resaltado visual).
 *  - Cámara nativa en móvil/iPad (`capture="environment"`).
 *  - Barra de progreso por archivo vía XMLHttpRequest.upload.
 *  - Reintento automático (3 intentos, backoff exponencial) en errores
 *    de red transitorios.
 *  - Validación cliente: extensión + tamaño máx (alineado con backend).
 *
 * Coexiste con `crm-scriptv7.js`: este módulo define
 * `window.CRM_UPLOADER_V8 = true` y el handler antiguo de `.upload-btn`
 * comprueba ese flag para no duplicar el envío.
 *
 * @since 1.17.0
 */
(function () {
    'use strict';

    // Configuración alineada con backend (uploads-handler.php).
    var MAX_SIZE_BYTES = 32 * 1024 * 1024;       // 32 MB
    var MAX_RETRIES    = 3;
    var RETRY_BASE_MS  = 800;
    var ALLOWED_EXT    = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];
    var ALLOWED_MIME_PREFIX = ['image/', 'application/pdf'];

    // Marca para que el handler antiguo se desactive.
    window.CRM_UPLOADER_V8 = true;

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form.crm-form, .crm-form-container form, form.crm-alta-form');
        if (!form) {
            // El form puede no estar presente fuera de la ficha.
            form = document.querySelector('.crm-form-container');
        }
        if (!form) { return; }
        attachUploadSections(form);
    });

    /**
     * Inicializa cada `.upload-section` del formulario.
     */
    function attachUploadSections(root) {
        var sections = root.querySelectorAll('.upload-section');
        sections.forEach(function (section) {
            if (section.dataset.uploaderV8 === '1') { return; }
            section.dataset.uploaderV8 = '1';

            var input = section.querySelector('.upload-input');
            if (!input) { return; }

            // Aceptar imágenes y PDF; en móvil, ofrecer la cámara trasera.
            if (!input.hasAttribute('accept')) {
                input.setAttribute('accept', 'image/*,application/pdf,.heic,.heif,.webp');
            }
            // No forzamos `capture` siempre porque en desktop deshabilita el
            // selector de archivos. Solo se sugiere en móviles.
            if (isMobile() && !input.hasAttribute('capture')) {
                input.setAttribute('capture', 'environment');
            }
            // `multiple` ya viene del HTML, pero lo aseguramos.
            input.setAttribute('multiple', '');

            // Estructura: dropzone + lista de progresos.
            buildDropzone(section, input);

            // Eventos.
            input.addEventListener('change', function () {
                handleFiles(section, input, Array.from(input.files));
                // Limpia para permitir reseleccionar el mismo archivo.
                input.value = '';
            });
            attachDragDrop(section, input);
        });
    }

    function isMobile() {
        return /iPad|iPhone|iPod|Android/i.test(navigator.userAgent);
    }

    /**
     * Construye el dropzone visual y la lista de progresos.
     */
    function buildDropzone(section, input) {
        var sector = input.dataset.sector || '';
        var tipo   = input.dataset.tipo   || '';

        var dz = document.createElement('div');
        dz.className = 'crm-dropzone';
        dz.innerHTML =
            '<div class="crm-dropzone-inner">'
          + '  <svg class="crm-dropzone-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
          + '    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
          + '    <polyline points="17 8 12 3 7 8"/>'
          + '    <line x1="12" y1="3" x2="12" y2="15"/>'
          + '  </svg>'
          + '  <div class="crm-dropzone-text">'
          + '    <strong>Toca o arrastra archivos aquí</strong>'
          + '    <small>JPG, PNG, HEIC, WebP o PDF · hasta 32 MB · varios a la vez</small>'
          + '  </div>'
          + '</div>';
        dz.addEventListener('click', function (e) {
            // Ignorar clics dentro de progresos ya creados.
            if (e.target.closest('.crm-upload-progress')) { return; }
            input.click();
        });

        var list = document.createElement('div');
        list.className = 'crm-upload-progress-list';

        // Insertar dropzone *antes* del input/botón legacy, lista al final.
        section.insertBefore(dz, input);
        section.appendChild(list);

        // Hacemos invisible el input (mantenemos accesibilidad).
        input.classList.add('crm-upload-input-hidden');
        // Ocultamos el botón legacy salvo en navegadores antiguos sin DnD.
        var legacyBtn = section.querySelector('.upload-btn');
        if (legacyBtn) {
            legacyBtn.classList.add('crm-upload-btn-legacy');
            legacyBtn.style.display = 'none';
        }

        section._crmContext = { sector: sector, tipo: tipo, list: list };
    }

    /**
     * Drag & drop sobre la sección.
     */
    function attachDragDrop(section, input) {
        ['dragenter', 'dragover'].forEach(function (evt) {
            section.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                section.classList.add('crm-dropzone-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            section.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                section.classList.remove('crm-dropzone-dragover');
            });
        });
        section.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files
                ? Array.from(e.dataTransfer.files)
                : [];
            if (files.length) {
                handleFiles(section, input, files);
            }
        });
    }

    /**
     * Procesa la cola de archivos seleccionados/arrastrados.
     */
    function handleFiles(section, input, files) {
        var ctx = section._crmContext || {};
        files.forEach(function (file) {
            var err = validateFile(file);
            if (err) {
                appendProgressRow(ctx.list, file.name, { state: 'error', message: err });
                return;
            }
            uploadWithRetry(section, input, file, 0);
        });
    }

    /**
     * Validación cliente alineada con backend.
     */
    function validateFile(file) {
        if (!file) { return 'Archivo inválido.'; }
        if (file.size <= 0) { return 'El archivo está vacío.'; }
        if (file.size > MAX_SIZE_BYTES) {
            return 'Demasiado grande (' + bytesToMb(file.size) + ' MB · máx 32 MB).';
        }
        var name = (file.name || '').toLowerCase();
        var ext  = name.split('.').pop();
        if (ALLOWED_EXT.indexOf(ext) === -1) {
            // El MIME en HEIC suele venir vacío en iOS, por eso validamos ext.
            var typeOk = (file.type || '').length > 0 && ALLOWED_MIME_PREFIX.some(function (p) {
                return file.type.indexOf(p) === 0;
            });
            if (!typeOk) {
                return 'Tipo no permitido (' + ext + ').';
            }
        }
        return null;
    }

    function bytesToMb(b) {
        return (b / (1024 * 1024)).toFixed(1).replace('.', ',');
    }

    /**
     * Sube un archivo con reintentos exponenciales.
     */
    function uploadWithRetry(section, input, file, attempt) {
        var ctx  = section._crmContext;
        var row  = appendProgressRow(ctx.list, file.name, { state: 'uploading' });
        var xhr  = new XMLHttpRequest();
        var url  = crmData.ajaxurl + '?action=crm_subir_' + encodeURIComponent(ctx.tipo);

        var fd = new FormData();
        fd.append('file',   file);
        fd.append('sector', ctx.sector);
        fd.append('nonce',  crmData.nonce);

        xhr.open('POST', url, true);

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                updateProgressRow(row, { state: 'uploading', percent: pct });
            }
        });

        xhr.addEventListener('load', function () {
            var json = null;
            try { json = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }

            if (xhr.status >= 200 && xhr.status < 300 && json && json.success) {
                updateProgressRow(row, { state: 'done' });
                insertUploadedFileMarkup(section, input, ctx, json.data);
                row.classList.add('crm-upload-fadeout');
                setTimeout(function () { row.remove(); }, 1200);
                dispatchUploadEvent(ctx, json.data);
                showToastSafe('Archivo subido: ' + (json.data && json.data.name ? json.data.name : file.name), 'success');
                refreshCardsLayout();
            } else {
                var msg = (json && json.data && json.data.message)
                    || ('Error HTTP ' + xhr.status);
                handleFailure(section, input, file, attempt, row, msg);
            }
        });

        xhr.addEventListener('error', function () {
            handleFailure(section, input, file, attempt, row, 'Error de red.');
        });
        xhr.addEventListener('abort', function () {
            updateProgressRow(row, { state: 'error', message: 'Subida cancelada.' });
        });
        xhr.addEventListener('timeout', function () {
            handleFailure(section, input, file, attempt, row, 'Tiempo agotado.');
        });

        xhr.timeout = 120000; // 2 min por archivo.
        xhr.send(fd);
    }

    function handleFailure(section, input, file, attempt, row, msg) {
        if (attempt < MAX_RETRIES) {
            var delay = RETRY_BASE_MS * Math.pow(2, attempt);
            updateProgressRow(row, {
                state: 'retry',
                message: msg + ' Reintentando en ' + Math.round(delay / 1000) + 's (' + (attempt + 1) + '/' + MAX_RETRIES + ')...'
            });
            setTimeout(function () {
                row.remove();
                uploadWithRetry(section, input, file, attempt + 1);
            }, delay);
        } else {
            updateProgressRow(row, {
                state: 'error',
                message: msg + ' (tras ' + MAX_RETRIES + ' reintentos)'
            });
            showToastSafe('Falló la subida de ' + file.name + ': ' + msg, 'error');
        }
    }

    /**
     * Inserta el `<div class="uploaded-file">` para que la ficha lo persista
     * al guardar (mismo markup que el render PHP).
     */
    function insertUploadedFileMarkup(section, input, ctx, data) {
        var div = document.createElement('div');
        div.className = 'uploaded-file';

        var nameField = ctx.tipo === 'factura'
            ? 'facturas[' + ctx.sector + '][]'
            : (ctx.tipo === 'presupuesto'
                ? 'presupuesto[' + ctx.sector + '][]'
                : 'contratos_firmados[' + ctx.sector + '][]');

        div.innerHTML =
            '<a href="' + escapeAttr(data.url) + '" target="_blank" rel="noopener">' + escapeHtml(data.name) + '</a>'
          + '<button type="button" class="remove-file-btn" data-url="' + escapeAttr(data.url) + '" data-tipo="' + escapeAttr(ctx.tipo) + '">&times;</button>'
          + '<input type="hidden" name="' + escapeAttr(nameField) + '" value="' + escapeAttr(data.url) + '">';

        // Insertarlo antes del input para mantener orden visual coherente
        // con el render del servidor.
        section.insertBefore(div, input);
    }

    function dispatchUploadEvent(ctx, data) {
        var evt = new CustomEvent('CRM_FILE_UPLOADED', {
            detail: { tipo: ctx.tipo, sector: ctx.sector, url: data.url, name: data.name }
        });
        document.dispatchEvent(evt);
    }

    function refreshCardsLayout() {
        // El script principal exporta `toggleCards` en algunos casos. Si no,
        // simplemente disparamos un evento que el script principal escucha.
        if (typeof window.toggleCards === 'function') {
            try { window.toggleCards(); } catch (e) { /* ignore */ }
        }
    }

    function showToastSafe(msg, type) {
        if (typeof window.showToast === 'function') {
            try { window.showToast(msg, type || 'info'); return; } catch (e) { /* fall */ }
        }
        // Fallback ultra-básico para no dejar al usuario sin feedback.
        console.log('[CRM upload]', type || 'info', msg);
    }

    /* --------- Render de barra de progreso --------- */

    function appendProgressRow(list, name, opts) {
        var row = document.createElement('div');
        row.className = 'crm-upload-progress crm-upload-' + (opts.state || 'idle');
        row.innerHTML =
            '<div class="crm-upload-progress-meta">'
          + '  <span class="crm-upload-name">' + escapeHtml(name) + '</span>'
          + '  <span class="crm-upload-state">' + stateLabel(opts) + '</span>'
          + '</div>'
          + '<div class="crm-upload-bar"><div class="crm-upload-bar-fill" style="width:'
          + (opts.percent || 0) + '%"></div></div>';
        list.appendChild(row);
        return row;
    }

    function updateProgressRow(row, opts) {
        if (!row) { return; }
        row.className = 'crm-upload-progress crm-upload-' + (opts.state || 'uploading');
        var fill  = row.querySelector('.crm-upload-bar-fill');
        var state = row.querySelector('.crm-upload-state');
        if (fill && typeof opts.percent === 'number') {
            fill.style.width = opts.percent + '%';
        }
        if (opts.state === 'done' && fill) { fill.style.width = '100%'; }
        if (state) { state.textContent = stateLabel(opts); }
    }

    function stateLabel(opts) {
        switch (opts.state) {
            case 'uploading': return (opts.percent || 0) + '%';
            case 'done':      return 'OK';
            case 'retry':     return opts.message || 'Reintentando...';
            case 'error':     return opts.message || 'Error';
            default:          return '';
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }
})();
