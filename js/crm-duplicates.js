/**
 * Aviso visual de duplicados en el formulario de alta v1.18.
 * Llama a wp_ajax_crm_check_duplicate al cambiar email o teléfono.
 */
(function () {
    'use strict';

    function ready(fn) { (document.readyState !== 'loading') ? fn() : document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
        const form = document.querySelector('.crm-form, .crm-form-container form, form.crm-alta-form');
        if (!form) return;
        if (!window.crmData || !window.crmData.ajaxUrl || !window.crmData.nonce) return;

        const tel = form.querySelector("[name='telefono']");
        const email = form.querySelector("[name='email_cliente']");
        const clientId = (form.querySelector("[name='client_id']") || {}).value || 0;
        if (!tel && !email) return;

        // Contenedor de aviso
        const warn = document.createElement('div');
        warn.className = 'crm-dup-warning';
        warn.style.display = 'none';
        (email || tel).closest('.form-group, .crm-section, .form-row, form').insertAdjacentElement('beforeend', warn);

        let timer = null;
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(check, 400);
        }

        function check() {
            const t = tel ? tel.value : '';
            const e = email ? email.value : '';
            if ((t || '').replace(/\D/g, '').length < 9 && !(e || '').includes('@')) {
                warn.style.display = 'none';
                warn.innerHTML = '';
                return;
            }
            const fd = new FormData();
            fd.append('action', 'crm_check_duplicate');
            fd.append('nonce', window.crmData.nonce);
            fd.append('telefono', t);
            fd.append('email', e);
            fd.append('client_id', clientId);
            fetch(window.crmData.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp || !resp.success || !resp.data || !resp.data.count) {
                        warn.style.display = 'none';
                        warn.innerHTML = '';
                        return;
                    }
                    const lines = resp.data.dupes.map(function (d) {
                        const tag = d.asignado ? 'asignado' : 'sin asignar';
                        return '<li><strong>#' + d.id + '</strong> · ' + escapeHtml(d.nombre || '') +
                               ' · ' + escapeHtml(d.telefono || '') +
                               ' · <em>' + escapeHtml(d.origen) + '</em>' +
                               ' · <span class="crm-dup-tag">' + tag + '</span></li>';
                    });
                    warn.innerHTML = '<p><strong>⚠ Posible duplicado:</strong> existen ' + resp.data.count + ' ficha(s) con este teléfono o email.</p><ul>' + lines.join('') + '</ul>';
                    warn.style.display = 'block';
                })
                .catch(function () { /* silencioso */ });
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        if (tel)   tel.addEventListener('change', schedule);
        if (tel)   tel.addEventListener('blur',   schedule);
        if (email) email.addEventListener('change', schedule);
        if (email) email.addEventListener('blur',   schedule);
    });
})();
