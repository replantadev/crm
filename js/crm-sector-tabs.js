/**
 * CRM — Sector tabs (v1.19.0).
 *
 * Construye una barra de tabs (vertical en escritorio, horizontal en móvil)
 * a partir de los sectores con interés marcado en la ficha de cliente.
 *
 * Reutiliza el HTML existente: cada <div class="sector-card sector-{name}">
 * sigue siendo el panel. Este script:
 *   - Genera dinámicamente la rail de tabs.
 *   - Marca uno como activo (.is-active en sector-card + aria-selected en tab).
 *   - Escucha cambios en los chips de Intereses (checkbox ocultos) para
 *     mostrar/ocultar tabs en sincronía.
 */
(function () {
    'use strict';

    const SECTOR_LABELS = {
        luz: 'Luz',
        gas: 'Gas',
        telecomunicaciones: 'Telecom',
        alarmas: 'Alarmas',
        agua: 'Agua',
        asesoria: 'Asesoría',
    };
    const SECTOR_ICONS = {
        luz: 'lightning',
        gas: 'flame',
        telecomunicaciones: 'broadcast',
        alarmas: 'shield-check',
        agua: 'drop',
        asesoria: 'scales',
    };

    function init() {
        const container = document.querySelector('.crm-cards-sectores');
        if (!container) {
            return;
        }
        // Si ya está envuelto en tabs, no reenvolver.
        if (container.parentElement && container.parentElement.classList.contains('crm-sector-tabs__pane')) {
            return;
        }

        // Construir wrapper de tabs
        const wrapper = document.createElement('div');
        wrapper.className = 'crm-sector-tabs';

        const rail = document.createElement('div');
        rail.className = 'crm-sector-tabs__rail';
        rail.setAttribute('role', 'tablist');

        const pane = document.createElement('div');
        pane.className = 'crm-sector-tabs__pane';

        container.parentNode.insertBefore(wrapper, container);
        wrapper.appendChild(rail);
        wrapper.appendChild(pane);
        pane.appendChild(container);

        rebuildTabs(rail, container);

        // Listener en checkboxes de intereses para reconstruir
        document.querySelectorAll('.intereses-container input[type="checkbox"][name="intereses[]"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                // Damos margen al JS legacy para mostrar/ocultar la sector-card,
                // y luego reconstruimos las tabs.
                setTimeout(function () {
                    rebuildTabs(rail, container);
                }, 30);
            });
        });
    }

    function getInlineSvg(name) {
        // Buscamos en cualquier .crm-i SVG ya renderizado por el helper PHP
        const ref = document.querySelector('[data-crm-icon="' + name + '"]');
        if (ref) {
            return ref.innerHTML;
        }
        // Fallback: punto simple
        return '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="3" fill="currentColor"/></svg>';
    }

    function rebuildTabs(rail, container) {
        // Recopilar sectores cuyo card está visible (display != none)
        const cards = Array.from(container.querySelectorAll('.sector-card'));
        const activeSectors = cards
            .filter(function (card) {
                const inline = card.style.display;
                // Considera visible si display no es "none" y la card-header está rellena
                return inline !== 'none';
            })
            .map(function (card) {
                // Extrae el sector desde la clase sector-XYZ
                let sector = '';
                card.classList.forEach(function (c) {
                    if (c.indexOf('sector-') === 0 && c !== 'sector-card') {
                        sector = c.replace('sector-', '');
                    }
                });
                return { sector: sector, card: card };
            })
            .filter(function (it) { return it.sector !== ''; });

        // Limpiar rail
        rail.innerHTML = '';

        if (activeSectors.length === 0) {
            // Ningún sector activo → escondemos el wrapper
            rail.style.display = 'none';
            return;
        }
        rail.style.display = '';

        let activeFound = false;

        activeSectors.forEach(function (item, idx) {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'crm-sector-tab';
            tab.setAttribute('role', 'tab');
            tab.setAttribute('data-sector', item.sector);

            const iconName = SECTOR_ICONS[item.sector] || 'lightning';
            const label = SECTOR_LABELS[item.sector] || item.sector;

            tab.innerHTML = '<span class="crm-i" aria-hidden="true">' + getInlineSvg(iconName) + '</span>' +
                            '<span>' + label + '</span>';

            tab.addEventListener('click', function () {
                activateSector(rail, container, item.sector);
            });

            // ¿Esta card ya tenía .is-active? Mantener selección.
            if (item.card.classList.contains('is-active')) {
                tab.setAttribute('aria-selected', 'true');
                activeFound = true;
            }
            rail.appendChild(tab);
        });

        // Si ninguna card estaba marcada como activa, activar la primera
        if (!activeFound) {
            activateSector(rail, container, activeSectors[0].sector);
        } else {
            // Asegurar que solo la marcada esté visible
            syncCardsVisibility(container);
        }
    }

    function activateSector(rail, container, sector) {
        // Tabs
        rail.querySelectorAll('.crm-sector-tab').forEach(function (t) {
            if (t.getAttribute('data-sector') === sector) {
                t.setAttribute('aria-selected', 'true');
            } else {
                t.removeAttribute('aria-selected');
            }
        });
        // Cards
        container.querySelectorAll('.sector-card').forEach(function (card) {
            if (card.classList.contains('sector-' + sector)) {
                card.classList.add('is-active');
            } else {
                card.classList.remove('is-active');
            }
        });
    }

    function syncCardsVisibility(container) {
        // No-op; el CSS .crm-sector-tabs .sector-card { display:none } + .is-active { display:block }
        // se encarga de la visibilidad. Esta función existe por extensibilidad futura.
        return container;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // --- Floating labels para <select> (sin :placeholder-shown) ---
    function initFloatingSelects() {
        document.querySelectorAll('.crm-field select').forEach(function (sel) {
            const sync = function () {
                if (sel.value && sel.value !== '') {
                    sel.classList.add('has-value');
                } else {
                    sel.classList.remove('has-value');
                }
            };
            sync();
            sel.addEventListener('change', sync);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFloatingSelects);
    } else {
        initFloatingSelects();
    }
})();
