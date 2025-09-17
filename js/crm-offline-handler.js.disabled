/**
 * CRM Offline Handler v1.0
 * Sistema de trabajo offline, compresión de imágenes y progress bars
 * Para comerciales usando iPad en campo
 */

class CRMOfflineHandler {
    constructor() {
        this.isOnline = navigator.onLine;
        this.pendingData = this.loadPendingData();
        this.compressionQueue = [];
        this.uploadQueue = [];
        
        this.init();
    }

    init() {
        this.setupConnectionHandlers();
        this.setupOfflineIndicator();
        this.setupFormInterception();
        this.setupUploadEnhancements();
        this.processPendingData();
        
        console.log('🚀 CRM Offline Handler iniciado');
    }

    // ===============================================
    // 1. SISTEMA DE TRABAJO OFFLINE
    // ===============================================
    
    setupConnectionHandlers() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.updateConnectionStatus();
            this.processPendingData();
            this.showToast('🟢 Conexión restaurada. Sincronizando datos...', 'success');
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.updateConnectionStatus();
            this.showToast('🔴 Sin conexión. Los datos se guardarán localmente.', 'warning');
        });
    }

    setupOfflineIndicator() {
        // Crear indicador de conexión si no existe
        if (!document.getElementById('connection-status')) {
            const indicator = document.createElement('div');
            indicator.id = 'connection-status';
            indicator.className = 'connection-indicator';
            
            // Insertar en el header del formulario
            const header = document.querySelector('.crm-header') || document.body;
            header.appendChild(indicator);
        }
        
        this.updateConnectionStatus();
    }

    updateConnectionStatus() {
        const indicator = document.getElementById('connection-status');
        if (!indicator) return;

        const pendingCount = this.pendingData.length;
        
        if (this.isOnline) {
            indicator.className = 'connection-indicator online';
            indicator.innerHTML = `
                <span class="status-dot online"></span>
                <span class="status-text">Conectado</span>
                ${pendingCount > 0 ? `<span class="pending-count">${pendingCount} pendientes</span>` : ''}
            `;
        } else {
            indicator.className = 'connection-indicator offline';
            indicator.innerHTML = `
                <span class="status-dot offline"></span>
                <span class="status-text">Sin conexión</span>
                <span class="pending-count">${pendingCount} guardados localmente</span>
            `;
        }
    }

    setupFormInterception() {
        const form = document.getElementById('crm-alta-cliente-form');
        if (!form) return;

        // Interceptar envío del formulario
        form.addEventListener('submit', (e) => {
            if (!this.isOnline) {
                e.preventDefault();
                this.saveOffline(new FormData(form));
                return false;
            }
        });

        // Auto-save cada 30 segundos
        setInterval(() => {
            if (!this.isOnline) {
                this.autoSaveForm();
            }
        }, 30000);
    }

    saveOffline(formData) {
        const clientData = {
            id: Date.now(),
            timestamp: new Date().toISOString(),
            data: this.formDataToObject(formData),
            files: [], // Los archivos se manejan por separado
            status: 'pending'
        };

        this.pendingData.push(clientData);
        this.savePendingData();
        this.updateConnectionStatus();
        
        this.showToast('✅ Datos guardados localmente. Se enviarán al recuperar conexión.', 'success');
    }

    autoSaveForm() {
        const form = document.getElementById('crm-alta-cliente-form');
        if (!form) return;

        const formData = new FormData(form);
        const draftData = {
            timestamp: new Date().toISOString(),
            data: this.formDataToObject(formData)
        };

        localStorage.setItem('crm_draft', JSON.stringify(draftData));
        console.log('💾 Auto-save realizado');
    }

    loadDraft() {
        const draft = localStorage.getItem('crm_draft');
        if (!draft) return;

        try {
            const draftData = JSON.parse(draft);
            const form = document.getElementById('crm-alta-cliente-form');
            
            if (form && draftData.data) {
                // Restaurar campos del formulario
                Object.entries(draftData.data).forEach(([key, value]) => {
                    const field = form.querySelector(`[name="${key}"]`);
                    if (field && field.type !== 'file') {
                        field.value = value;
                    }
                });
                
                this.showToast('📝 Borrador restaurado automáticamente', 'info');
            }
        } catch (e) {
            console.error('Error al cargar borrador:', e);
        }
    }

    async processPendingData() {
        if (!this.isOnline || this.pendingData.length === 0) return;

        this.showToast(`🔄 Sincronizando ${this.pendingData.length} registros pendientes...`, 'info');

        for (const item of [...this.pendingData]) {
            try {
                await this.syncPendingItem(item);
                this.removePendingItem(item.id);
            } catch (error) {
                console.error('Error sincronizando:', error);
                break; // Detener si hay error para evitar pérdida de datos
            }
        }

        this.updateConnectionStatus();
    }

    async syncPendingItem(item) {
        const formData = this.objectToFormData(item.data);
        formData.append('action', 'crm_sync_offline_data');
        formData.append('offline_sync', '1');
        formData.append('crm_nonce', crmData.nonce); // Usar el nonce existente

        const response = await fetch(crmData.ajaxurl, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.data?.message || 'Error en sincronización');
        }

        return result;
    }

    // ===============================================
    // 2. COMPRESIÓN AUTOMÁTICA DE IMÁGENES
    // ===============================================

    setupUploadEnhancements() {
        // Interceptar todos los inputs de archivo
        document.addEventListener('change', (e) => {
            if (e.target.type === 'file' && e.target.classList.contains('upload-input')) {
                this.handleFileSelection(e.target);
            }
        });
    }

    async handleFileSelection(input) {
        if (!input.files.length) return;

        const files = Array.from(input.files);
        const processedFiles = [];

        // Crear progress bar para la operación
        const progressBar = this.createProgressBar(`Procesando ${files.length} archivo(s)...`);

        try {
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                this.updateProgressBar(progressBar, (i / files.length) * 50, `Procesando ${file.name}...`);

                if (this.isImageFile(file)) {
                    // Comprimir imagen
                    const compressedFile = await this.compressImage(file);
                    processedFiles.push(compressedFile);
                    
                    this.showCompressionStats(file, compressedFile);
                } else {
                    // Archivo no es imagen, mantener original
                    processedFiles.push(file);
                }
            }

            // Simular nuevos archivos en el input
            this.replaceInputFiles(input, processedFiles);
            
            this.updateProgressBar(progressBar, 100, '✅ Procesamiento completado');
            setTimeout(() => progressBar.remove(), 2000);

        } catch (error) {
            console.error('Error procesando archivos:', error);
            this.updateProgressBar(progressBar, 100, '❌ Error en procesamiento');
            setTimeout(() => progressBar.remove(), 3000);
        }
    }

    isImageFile(file) {
        return file.type.startsWith('image/') && 
               ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'].includes(file.type);
    }

    async compressImage(file, quality = 0.8, maxWidth = 1920, maxHeight = 1080) {
        return new Promise((resolve) => {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const img = new Image();

            img.onload = function() {
                // Calcular nuevas dimensiones manteniendo aspecto
                let { width, height } = img;
                const ratio = Math.min(maxWidth / width, maxHeight / height);

                if (ratio < 1) {
                    width *= ratio;
                    height *= ratio;
                }

                canvas.width = width;
                canvas.height = height;

                // Dibujar imagen redimensionada
                ctx.drawImage(img, 0, 0, width, height);

                // Convertir a blob con compresión
                canvas.toBlob(
                    (blob) => {
                        // Crear nuevo archivo con mismo nombre pero comprimido
                        const compressedFile = new File([blob], file.name, {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });
                        resolve(compressedFile);
                    },
                    'image/jpeg',
                    quality
                );
            };

            img.src = URL.createObjectURL(file);
        });
    }

    showCompressionStats(original, compressed) {
        const reduction = ((original.size - compressed.size) / original.size * 100).toFixed(1);
        const originalMB = (original.size / 1024 / 1024).toFixed(2);
        const compressedMB = (compressed.size / 1024 / 1024).toFixed(2);

        this.showToast(
            `📷 ${original.name}: ${originalMB}MB → ${compressedMB}MB (${reduction}% reducción)`,
            'success'
        );
    }

    // ===============================================
    // 4. PROGRESS BARS EN UPLOADS
    // ===============================================

    createProgressBar(text = 'Cargando...') {
        const progressContainer = document.createElement('div');
        progressContainer.className = 'crm-progress-container';
        progressContainer.innerHTML = `
            <div class="crm-progress-bar">
                <div class="crm-progress-fill"></div>
            </div>
            <div class="crm-progress-text">${text}</div>
        `;

        // Insertar en el formulario
        const form = document.getElementById('crm-alta-cliente-form');
        if (form) {
            form.appendChild(progressContainer);
        } else {
            document.body.appendChild(progressContainer);
        }

        return progressContainer;
    }

    updateProgressBar(progressBar, percentage, text = '') {
        const fill = progressBar.querySelector('.crm-progress-fill');
        const textEl = progressBar.querySelector('.crm-progress-text');

        if (fill) {
            fill.style.width = `${Math.min(100, Math.max(0, percentage))}%`;
        }

        if (text && textEl) {
            textEl.textContent = text;
        }
    }

    enhanceFormSubmission() {
        const form = document.getElementById('crm-alta-cliente-form');
        if (!form) return;

        const originalSubmit = form.onsubmit;
        
        form.onsubmit = async (e) => {
            if (!this.isOnline) {
                // Ya manejado por setupFormInterception
                return;
            }

            // Crear progress bar para envío
            const progressBar = this.createProgressBar('Enviando datos...');
            
            try {
                // Llamar al handler original si existe
                if (originalSubmit) {
                    const result = await originalSubmit.call(form, e);
                    this.updateProgressBar(progressBar, 100, '✅ Enviado correctamente');
                    setTimeout(() => progressBar.remove(), 2000);
                    return result;
                }
            } catch (error) {
                this.updateProgressBar(progressBar, 100, '❌ Error en envío');
                setTimeout(() => progressBar.remove(), 3000);
                throw error;
            }
        };
    }

    // ===============================================
    // UTILIDADES
    // ===============================================

    formDataToObject(formData) {
        const obj = {};
        for (const [key, value] of formData.entries()) {
            if (obj[key]) {
                // Si ya existe, convertir a array
                if (Array.isArray(obj[key])) {
                    obj[key].push(value);
                } else {
                    obj[key] = [obj[key], value];
                }
            } else {
                obj[key] = value;
            }
        }
        return obj;
    }

    objectToFormData(obj) {
        const formData = new FormData();
        Object.entries(obj).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach(v => formData.append(key, v));
            } else {
                formData.append(key, value);
            }
        });
        return formData;
    }

    replaceInputFiles(input, files) {
        // Crear nuevo DataTransfer para simular selección de archivos
        const dt = new DataTransfer();
        files.forEach(file => dt.items.add(file));
        input.files = dt.files;
        
        // Disparar evento change
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    loadPendingData() {
        try {
            return JSON.parse(localStorage.getItem('crm_pending_data') || '[]');
        } catch {
            return [];
        }
    }

    savePendingData() {
        localStorage.setItem('crm_pending_data', JSON.stringify(this.pendingData));
    }

    removePendingItem(id) {
        this.pendingData = this.pendingData.filter(item => item.id !== id);
        this.savePendingData();
    }

    showToast(message, type = 'info') {
        // Buscar sistema de toast existente o crear uno simple
        if (typeof showToast === 'function') {
            showToast(message, type);
            return;
        }

        // Toast simple si no existe sistema
        const toast = document.createElement('div');
        toast.className = `crm-toast crm-toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            max-width: 400px;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        `;

        // Colores por tipo
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };

        toast.style.backgroundColor = colors[type] || colors.info;

        document.body.appendChild(toast);

        // Animación de entrada
        setTimeout(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        }, 100);

        // Remover después de 5 segundos
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }
}

// ===============================================
// INICIALIZACIÓN AUTOMÁTICA
// ===============================================

document.addEventListener('DOMContentLoaded', () => {
    // Solo inicializar si estamos en una página con el formulario CRM
    if (document.getElementById('crm-alta-cliente-form')) {
        window.crmOfflineHandler = new CRMOfflineHandler();
        
        // Cargar borrador si existe
        setTimeout(() => {
            window.crmOfflineHandler.loadDraft();
        }, 1000);
    }
});

// Exportar para uso global
window.CRMOfflineHandler = CRMOfflineHandler;
