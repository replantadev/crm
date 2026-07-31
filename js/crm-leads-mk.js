/**
 * Asignación de leads MK - lado cliente.
 * v1.18
 */
(function ($) {
    'use strict';

    function ajax(action, data) {
        const $root = $('.crm-leads-mk');
        const nonce = $root.data('nonce');
        return $.ajax({
            url: (window.crmLeadsMK && window.crmLeadsMK.ajaxUrl) || (window.ajaxurl || '/wp-admin/admin-ajax.php'),
            method: 'POST',
            data: Object.assign({ action: action, nonce: nonce }, data)
        });
    }

    function showToast(msg, kind) {
        if (window.showToast) { window.showToast(msg, kind || 'info'); return; }
        console.log('[crm-leads-mk]', msg);
    }

    function refreshCounter() {
        const remaining = $('.crm-leads-mk-row:not(.crm-leads-mk-row--gone)').length;
        const $c = $('.crm-leads-mk-counter strong');
        if ($c.length) $c.text(remaining);
    }

    function rowMatchesStatus(rowStatus, filterValue) {
        if (filterValue === 'todos' || !filterValue) return true;
        if (filterValue === 'activos') return rowStatus === 'pendiente' || rowStatus === 'asignado';
        return rowStatus === filterValue;
    }

    function applyFilters() {
        const q = ($('#crm-leads-mk-search').val() || '').toLowerCase().trim();
        const status = $('#crm-leads-mk-status').val() || 'activos';
        $('.crm-leads-mk-row').each(function () {
            const hay = (this.getAttribute('data-haystack') || '');
            const rowStatus = this.getAttribute('data-status') || 'pendiente';
            const matchesSearch = (q === '' || hay.indexOf(q) !== -1);
            const matchesStatus = rowMatchesStatus(rowStatus, status);
            this.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
        });
    }

    $(document).on('input', '#crm-leads-mk-search', function () {
        applyFilters();
    });

    $(document).on('change', '#crm-leads-mk-status', function () {
        applyFilters();
    });

    $(document).on('click', '.crm-leads-mk-assign', function () {
        const $row = $(this).closest('tr');
        const leadId = $row.data('id');
        const userId = $row.find('.crm-leads-mk-assignee').val();
        const sector = $('#crm-leads-mk-sector').val();
        if (!userId) { showToast('Selecciona un comercial', 'warning'); return; }

        const $btn = $(this).prop('disabled', true).text('Asignando…');
        ajax('crm_lead_assign', { lead_id: leadId, user_id: userId, sector: sector })
            .done(function (resp) {
                if (resp && resp.success) {
                    showToast(resp.data.message, 'success');
                    const status = (resp.data && resp.data.status) || 'asignado';
                    const statusLabel = (resp.data && resp.data.status_label) || 'Asignado';
                    const delegado = (resp.data && resp.data.delegado) || '';
                    $row.attr('data-status', status);
                    $row.find('.crm-lead-mk-status')
                        .removeClass('status-pendiente status-asignado status-trabajado')
                        .addClass('status-' + status)
                        .text(statusLabel);
                    if (delegado) {
                        const $delegateCell = $row.find('td').eq(6);
                        const href = $row.attr('data-ficha') || ('/alta-de-cliente/?client_id=' + leadId);
                        $delegateCell.html('<a class="crm-link" href="' + href + '">' + delegado + '</a>');
                    }
                    applyFilters();
                    $btn.prop('disabled', false).text('Asignar');
                } else {
                    showToast((resp && resp.data && resp.data.message) || 'Error', 'error');
                    $btn.prop('disabled', false).text('Asignar');
                }
            })
            .fail(function (xhr) {
                showToast('Error AJAX: ' + (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || xhr.statusText), 'error');
                $btn.prop('disabled', false).text('Asignar');
            });
    });

    $(document).on('click', '.crm-leads-mk-cold', function () {
        const $row = $(this).closest('tr');
        const leadId = $row.data('id');
        if (!confirm('Mover este lead a "Contacto frío"?')) return;
        ajax('crm_lead_to_cold', { lead_id: leadId })
            .done(function (resp) {
                if (resp && resp.success) {
                    showToast(resp.data.message, 'success');
                    $row.fadeOut(250, function () { $row.remove(); refreshCounter(); });
                } else {
                    showToast((resp && resp.data && resp.data.message) || 'Error', 'error');
                }
            });
    });

    $(document).on('click', '.crm-leads-mk-delete', function () {
        const $row = $(this).closest('tr');
        const leadId = $row.data('id');
        if (!confirm('Eliminar definitivamente este lead?')) return;
        ajax('crm_lead_delete', { lead_id: leadId })
            .done(function (resp) {
                if (resp && resp.success) {
                    showToast(resp.data.message, 'success');
                    $row.fadeOut(250, function () { $row.remove(); refreshCounter(); });
                } else {
                    showToast((resp && resp.data && resp.data.message) || 'Error', 'error');
                }
            });
    });

    $(document).on('click', '.crm-leads-mk-sync', function () {
        const $btn = $(this).prop('disabled', true).text('Sincronizando…');
        const $status = $('.crm-leads-mk-sync-status').text('');
        $.ajax({
            url: (window.crmLeadsMK && window.crmLeadsMK.ajaxUrl) || (window.ajaxurl || '/wp-admin/admin-ajax.php'),
            method: 'POST',
            data: { action: 'crm_leads_sheets_sync_now', nonce: (window.crmLeadsMK && window.crmLeadsMK.syncNonce) }
        })
            .done(function (resp) {
                if (resp && resp.success) {
                    const d = resp.data || {};
                    $status.text(`OK · ${d.inserted || 0} nuevos · ${d.dupes || 0} dup · ${d.skipped || 0} omitidos`);
                    if ((d.inserted || 0) > 0) {
                        setTimeout(function () { location.reload(); }, 1200);
                    }
                } else {
                    $status.text('Error: ' + ((resp && resp.data && resp.data.message) || 'desconocido'));
                }
            })
            .fail(function (xhr) {
                $status.text('Error AJAX: ' + (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || xhr.statusText));
            })
            .always(function () {
                $btn.prop('disabled', false).text('Sincronizar ahora');
            });
    });

    $(function () {
        applyFilters();
    });
})(jQuery);
