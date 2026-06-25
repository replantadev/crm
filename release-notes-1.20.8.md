## v1.20.8 - 4 fixes (null comercial, botones sobrios, visitador en stats, paginas auto)

### BUG 1 - "null" en columna Comercial de Todas las altas
Cuando un cliente tenia `user_id` apuntando a un usuario eliminado o vacio, el LEFT JOIN devolvia NULL y el JS lo imprimia literal como "null". Ahora:
- SQL usa `COALESCE(u.display_name, '—')` para `comercial` y `''` para `actualizado_por_nombre`.
- JS adicionalmente filtra strings `null` por si quedan caches viejos.

### BUG 2 - Botones de accion con gradientes chillones + emojis
Reemplazado en TODAS las tablas:
- Emojis `✏️` / `🗑️` -> SVG inline Phosphor (pencil, trash).
- Fondos azul/rojo con gradiente y shadow -> estilo sobrio: borde gris suave, fondo blanco, hover azul/rojo MUY suave (color de texto cambia, fondo casi imperceptible).
- Eliminado efecto shimmer y `translateY(-2px)` chillon.
- Backward-compat: clases `.edit-btn`, `.delete-btn`, `.action-btn.edit`, `.action-btn.delete` reciben el mismo estilo sobrio (no rompe templates existentes).

### BUG 3 - Visitador no aparecia con nombre en Estadisticas por Comercial
Cuando un usuario era visitador puro y daba altas, su fila aparecia con nombre vacio (porque `get_userdata` podia fallar y `display_name` quedaba sin sanitizar). Ahora:
- Fallback cascada: `display_name` -> `user_login` -> `(usuario #N eliminado)`.
- Tag pequeno tras el nombre para identificar visitadores: `Visitador` o `Com.+Vis.` (en small caps sobrias).

### BUG 4 - Menu sin "Nueva alta" para comercial/visitador
El menu del shell descartaba items cuyos slugs no tenian pagina creada en WordPress. Si las paginas `alta-de-cliente`, `mis-altas-de-cliente`, etc. no existian, el comercial/visitador no veia el item aunque tuviera rol. Ahora:

1. **Fallback URL inmediato**: el menu nunca descarta un item con slug — si no encuentra pagina, usa `home_url('/slug/')`.
2. **Auto-creacion de paginas requeridas**: nuevo archivo `includes/page-bootstrap.php` que en activacion y en `admin_init` (idempotente, una vez por version via option `crm_pages_bootstrap_version`) crea las paginas:
   - `alta-de-cliente`  -> `[crm_alta_cliente]`
   - `mis-altas-de-cliente`  -> `[crm_lista_altas]`
   - `todas-las-altas-de-cliente`  -> `[todas_las_altas]`
   - `editar-cliente`  -> `[crm_editar_cliente]`
   - `resumen`  -> `[crm_rendimiento_comercial][crm_comerciales_estadisticas][crm_clientes_recientes][crm_clientes_por_estado][crm_clientes_por_interes]`
   - `asignar-leads`  -> `[asignacion_leads_mk]`
   - `panel-de-control`  -> `[crm_admin_panel]`
   - `mi-agenda`  -> `[crm_mi_agenda]`
3. **Idempotente**: si ya existe una pagina (en cualquier estado) con ese slug, NO la toca ni la duplica.
4. **Filtrable**: `apply_filters('crm_required_pages', $pages)` para personalizar.

### Archivos modificados
- `crm-plugin.php`: COALESCE en query todas-las-altas, SVG en boton tabla antigua, include page-bootstrap, llamada a `crm_bootstrap_pages()` en activacion, bump version.
- `includes/app-shell.php`: menu nunca descarta items con slug (fallback URL).
- `includes/page-bootstrap.php` (nuevo): auto-crea paginas requeridas.
- `shortcodes.php`: nombre cascada en estadisticas + tag de rol visitador.
- `js/todas-las-altasv2.js`: SVG pencil/trash + guard contra `c.comercial === 'null'`.
- `crm-styles.css`: estilos sobrios para `.action-btn` (ambas secciones), `.edit-btn`, `.delete-btn` + nuevo `.comercial-role-tag`.

### Post-actualizacion
1. Al actualizar a v1.20.8, accede una vez al wp-admin como admin -> el `admin_init` ejecuta `crm_bootstrap_pages()` y crea las paginas que falten.
2. Recarga el frontend con un visitador -> debe ver: Escritorio, Alta, Mis altas, Mi agenda (Mis leads si configurado).
3. Entra en `Todas las altas` -> si habia clientes con user_id huerfano, verás `—` en lugar de `null`.
4. Entra en `Resumen` -> filas de visitadores muestran nombre + tag pequeno "Visitador" / "Com.+Vis.".
5. Los botones de editar/eliminar son sobrios (borde gris, hover azul/rojo suave).
