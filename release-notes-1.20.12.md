## v1.20.12 - Fix modal agenda + badges minimal en tablas

### Fixes

1. **Modal "Mi agenda" duplicaba el menu del shell dentro del iframe**
   El boton `[crm_agenda_modal]` abre un iframe con la URL de "Mi agenda" (?crm_modal=1). Hasta ahora esa pagina renderizaba el shell completo (topbar/menu lateral), provocando que dentro del modal aparecieran DOS menus.
   Fix: `crm_app_shell_is_crm_page()` ahora detecta `$_GET['crm_modal']==='1'` y devuelve false, suprimiendo topbar y wrapper del shell. El iframe muestra solo el contenido limpio.

2. **Boton de cerrar del modal de agenda no se veia**
   El glifo del boton estaba corrupto (U+FFFD `?`) por un problema de encoding antiguo. Reemplazado por `&times;` (×) con tamaño font 20px, color #191919 y centrado flex.

3. **Tablas (Mis altas, Todas las altas, etc.) seguian mostrando badges grandes con border-radius enorme**
   El JS de las tablas genera `<span class="crm-badge estado-xxx">` y `<span class="crm-badge sector-xxx">`. Las reglas CSS legacy aplicaban background solido, padding 2px 10px y border-radius 12px (forma pildora gigante).
   Fix: rediseñado el bloque `.crm-badge` con el mismo lenguaje minimal que `.crm-estado-pill` (v1.20.11):
   - HSL por sector (energia=ambar, alarmas=rojo, telecom=azul, seguros=indigo, renovables=verde).
   - Estados: cada uno con su matiz (borrador gris, enviado azul claro, presupuesto generado ambar, presupuesto aceptado verde, contratos generados azul, contratos firmados violeta).
   - Sectores: chip con dot de 6px del color del sector + label corto.
   - Padding 2px 8px, border 1px del color del sector, fondo muy claro, texto oscuro del mismo hue.
   - Eliminados los 3 bloques duplicados que reaplicaban backgrounds solidos al final del archivo.

### Cache busting CSS
`crm-styles.css` se sirve con `?ver=CRM_PLUGIN_VERSION`, por lo que al actualizar a 1.20.12 los navegadores invalidan automaticamente. Si tras actualizar el plugin sigues viendo los badges viejos: hard reload (Ctrl+Shift+R) o purga el cache del plugin de cache que uses (WP Rocket, LiteSpeed, etc).

### Archivos
- `includes/app-shell.php`: chromeless mode con `?crm_modal=1`.
- `includes/shortcode-agenda.php`: boton close con `&times;` y estilo mejorado.
- `crm-styles.css`: bloque `.crm-badge` reescrito + eliminacion de overrides duplicados (~50 lineas suprimidas).
