# v1.20.16 — Visitador ve todas las fichas + vista calendario + errores de visita visibles

## Resumen

Tres mejoras pedidas para el sistema de visitas.

### Cambios

- **Visitador ve y edita la ficha de cualquier cliente.** Hasta ahora la página `/editar-cliente/?client_id=X` solo dejaba entrar al comercial propietario o a un admin. A partir de esta versión, cualquier usuario con rol `visitador` (combinado o no con `comercial`) puede abrir cualquier ficha para registrar el resultado de la visita, cambiar el estado y añadir notas tras la visita. La lista "Mis altas" (`[crm_lista_altas]`) también muestra **todos los clientes** para usuarios visitadores, con un badge azul `Comercial: <nombre>` en cada fila para no perder de vista de quién es la ficha.

- **Vista calendario mensual en Mi agenda.** Toggle **Lista | Calendario** sobre la tabla. El calendario usa FullCalendar 6.1.15 (CSS embebido en el bundle, cargado desde jsDelivr), con vistas Mes/Semana/Lista, navegación libre, hoy/anterior/siguiente, locale `es`, semana empezando en lunes. Las visitas se cargan en un rango amplio (-3 / +12 meses) independiente del filtro de fechas, para poder navegar por meses sin restricciones. Las visitas se pintan con color según estado (azul programada, verde realizada, naranja no-show, gris cancelada). Click en una visita abre la ficha del cliente. La preferencia Lista/Calendario se recuerda en `localStorage`.

- **Errores al crear/editar visita ahora se ven en la ficha.** El handler `admin_post_crm_visita_save` ya redirigía con `?crm_visita_msg=error&crm_msg=...` pero el bloque de visitas en la ficha del cliente no leía esos parámetros, así que cuando la creación fallaba (solape con otra visita del visitador, fecha en el pasado, etc.) el usuario veía la página igual que antes y creía que la visita se había guardado. Ahora el bloque `crm_render_visitas_box()` muestra un banner rojo con el mensaje de error o un banner verde de confirmación, igual que en `[crm_mi_agenda]`.

### Archivos modificados

- `crm-plugin.php`: bump versión + permiso visitador en `crm_editar_cliente` + see_all en `crm_lista_altas` y `crm_obtener_altas` (con columna `comercial_nombre`).
- `includes/visitas.php`: banner de mensajes (error/created/updated) en `crm_render_visitas_box`.
- `includes/shortcode-agenda.php`: toggle Lista/Calendario, query ampliada `-3m/+12m`, FullCalendar v6 vía CDN, JSON de eventos, `localStorage` para preferencia.

### Notas operativas

- FullCalendar v6 se carga desde `https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js` (CSS-in-JS, no necesita stylesheet aparte).
- Si se prefiere alojar local, copiar el JS bajo `crm-plugin/vendor/fullcalendar/` y cambiar el `<script src>` en `includes/shortcode-agenda.php`.
- El visitador ve TODOS los clientes en `/mis-altas-de-cliente/`. Si la base es muy grande, considerar paginar o filtrar por "clientes con visita asignada".
- Tras desplegar, reiniciar LocalWP/PHP-FPM para invalidar OPcache.
