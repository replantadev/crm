/* global jQuery, crmData */
/**
 * CRM Energitel - Notas (timeline + búsqueda + CRUD).
 *
 * Se carga en la ficha de cliente cuando existe `.crm-notes-block`.
 * Depende de jQuery (ya enqueueado para el formulario) y de la
 * configuración global `crmData` (ajaxurl, nonce).
 *
 * @since 1.17.0
 */
(function ($) {
    'use strict';

    function init($block) {
        var clientId = parseInt($block.data('client-id'), 10);
        if (!clientId) {
            return;
        }
        var $textarea = $block.find('.crm-note-textarea');
        var $sector   = $block.find('.crm-note-sector-select');
        var $addBtn   = $block.find('.crm-note-add-btn');
        var $timeline = $block.find('.crm-notes-timeline');
        var $search   = $block.find('.crm-notes-search-input');
        var $clear    = $block.find('.crm-notes-search-clear');

        // Añadir nota manual.
        $addBtn.on('click', function () {
            var texto = ($textarea.val() || '').trim();
            if (!texto) {
                $textarea.focus();
                return;
            }
            $addBtn.prop('disabled', true).text('Guardando...');
            $.post(crmData.ajaxurl, {
                action:    'crm_note_add',
                nonce:     crmData.nonce,
                client_id: clientId,
                sector:    $sector.val() || '',
                texto:     texto
            }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.html) {
                    var $list = $timeline.find('.crm-notes-list');
                    if (!$list.length) {
                        $timeline.html('<ul class="crm-notes-list">' + resp.data.html + '</ul>');
                    } else {
                        $list.prepend(resp.data.html);
                    }
                    $textarea.val('');
                    $sector.val('');
                } else {
                    var msg = (resp && resp.data && resp.data.message) || 'No se pudo guardar la nota.';
                    window.alert(msg);
                }
            }).fail(function () {
                window.alert('Error de red al guardar la nota.');
            }).always(function () {
                $addBtn.prop('disabled', false).text('Añadir nota');
            });
        });

        // Cmd/Ctrl+Enter en el textarea = enviar.
        $textarea.on('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                $addBtn.trigger('click');
            }
        });

        // Borrar nota (delegado).
        $timeline.on('click', '.crm-note-delete', function () {
            var $li     = $(this).closest('.crm-note');
            var noteId  = parseInt($li.data('note-id'), 10);
            if (!noteId || !window.confirm('¿Eliminar esta nota?')) {
                return;
            }
            $.post(crmData.ajaxurl, {
                action:  'crm_note_delete',
                nonce:   crmData.nonce,
                note_id: noteId
            }).done(function (resp) {
                if (resp && resp.success) {
                    $li.fadeOut(180, function () { $(this).remove(); });
                } else {
                    var msg = (resp && resp.data && resp.data.message) || 'No se pudo eliminar.';
                    window.alert(msg);
                }
            }).fail(function () {
                window.alert('Error de red al eliminar la nota.');
            });
        });

        // Búsqueda incremental (cliente-side por velocidad; si el filtro
        // local devuelve 0, lanza búsqueda full-text al servidor).
        var searchTimer = null;
        function applyFilter() {
            var q = ($search.val() || '').trim().toLowerCase();
            var $items = $timeline.find('.crm-note');
            if (!q) {
                $items.show();
                $timeline.find('.crm-notes-search-empty').remove();
                return;
            }
            var visibles = 0;
            $items.each(function () {
                var txt = $(this).text().toLowerCase();
                var match = txt.indexOf(q) !== -1;
                $(this).toggle(match);
                if (match) { visibles++; }
            });
            if (visibles === 0) {
                serverSearch(q);
            } else {
                $timeline.find('.crm-notes-search-empty').remove();
            }
        }

        function serverSearch(q) {
            $.post(crmData.ajaxurl, {
                action:    'crm_note_search',
                nonce:     crmData.nonce,
                client_id: clientId,
                q:         q
            }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.count > 0) {
                    // Si el FTS encuentra notas extra que no estaban en la
                    // página, no las podemos pintar como cards (no tenemos
                    // su HTML pre-renderizado), pero indicamos al usuario
                    // que hay más coincidencias.
                    var $items = $timeline.find('.crm-note').hide();
                    var ids = {};
                    resp.data.rows.forEach(function (n) { ids[n.id] = true; });
                    $items.each(function () {
                        var id = String($(this).data('note-id'));
                        if (ids[id]) { $(this).show(); }
                    });
                }
                $timeline.find('.crm-notes-search-empty').remove();
                if (!$timeline.find('.crm-note:visible').length) {
                    $timeline.append('<p class="crm-notes-search-empty">Sin coincidencias para “' + escapeHtml(q) + '”.</p>');
                }
            });
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        $search.on('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(applyFilter, 180);
        });
        $clear.on('click', function () {
            $search.val('');
            applyFilter();
            $search.focus();
        });
    }

    $(function () {
        $('.crm-notes-block').each(function () { init($(this)); });
    });
})(jQuery);
