## v1.20.14 — Iconos sector + independencia de envío + tabla Mis altas minimal

### Fixes

- **Iconos de interés en los tabs de la ficha de cliente**: los tabs ahora muestran el icono correcto del sector (rayo para Energía, escudo para Alarmas, broadcast para Telecom, paraguas para Seguros y hoja para Renovables). Antes el JS mapeaba sectores antiguos (luz/gas/agua/asesoría) y además buscaba SVGs en un atributo `[data-crm-icon]` que no existía en el DOM, por lo que siempre caía al fallback de un punto. Ahora el plugin localiza el SVG inline por sector vía `window.CRM_SECTOR_ICONS_SVG`.
- **Independencia de envío por sector**: si un cliente con un interés ya contratado tenía otro interés en otro sector, al pulsar "Enviar a Admin" del nuevo sector el botón aparecía como "ya enviado" porque el handler JS no limpiaba los hiddens `enviar_sector[]` acumulados de pulsaciones anteriores, y el backend acababa marcando como enviados sectores que no se habían tocado en esa interacción. Ahora cada clic envía exclusivamente su sector. El backend ya cambiaba estado y fecha de forma independiente por sector, esto solo arregla la capa JS.
- **Badges minimal en "Mis altas"**: la tabla de Mis altas (`#crm-lista-altas`) reusaba los badges antiguos (`.badge-interes` y `.estado-badge` con colores planos y fondo saturado). Migrado a `.crm-badge` y `.crm-estado-pill` (escala HSL minimal por sector y paso), coherente con la ficha del cliente y "Todas las altas".

### Cambios técnicos

- `includes/icons.php`: añadidos iconos `umbrella` y `leaf` a `crm_icon_path()`. `crm_icon_for_sector()` ahora mapea los 5 sectores reales (`energia/alarmas/telecomunicaciones/seguros/renovables`) y mantiene los aliases legacy.
- `js/crm-sector-tabs.js`: SECTOR_LABELS / SECTOR_ICONS actualizados a los sectores reales. `getInlineSvg()` ahora resuelve primero desde `window.CRM_SECTOR_ICONS_SVG`.
- `js/crm-scriptv7.js`: handler de `.send-sector-btn` limpia hiddens `enviar_sector[]` previos.
- `crm-plugin.php`: `wp_add_inline_script('crm-sector-tabs', …, 'before')` inyecta el mapa de SVG inline por sector. Render de las columnas `intereses` y `estado_por_sector` de la tabla Mis altas migrado a `.crm-badge` y `.crm-estado-pill`.
