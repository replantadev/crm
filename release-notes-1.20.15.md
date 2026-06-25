## v1.20.15 — Visitas: widget en escritorio + comercial ve delegadas + fallback "fuera de rango"

### UX

- **Escritorio (`/crm`) muestra "Próximas visitas"**: nuevo widget compacto que se inyecta automáticamente al inicio del contenido de la página `crm` (o se puede colocar manualmente con el shortcode `[crm_proximas_visitas limit="5" days="30"]`). Lista las visitas programadas en los próximos 30 días con cliente, sector, lugar y teléfono clicable. Adapta el contenido al rol:
  - **admin**: ve las próximas de cualquier asignado.
  - **comercial**: las suyas + las que ha delegado a un visitador (marcadas "Delegada a …" sobre fondo ámbar).
  - **visitador**: las que tiene asignadas.
- **Mi agenda — comerciales ven sus visitas delegadas**: antes, si un comercial creaba una visita y la asignaba a un visitador, la visita desaparecía de su agenda. Ahora "Mi agenda" del comercial incluye también las visitas que ha creado (`creado_por = me`) además de las asignadas (`comercial_id = me`). Se añade columna "Asignado a" para distinguir las suyas de las delegadas (badge naranja "visitador" cuando aplica).
- **Mi agenda — fallback "fuera de rango"**: si un visitador o comercial entra a "Mi agenda" y la consulta filtrada está vacía, el aviso ahora indica si hay visitas suyas fuera del rango actual de fechas para que sepa que existen y amplíe el filtro.

### Cambios técnicos

- `includes/visitas.php`:
  - `crm_visitas_list()` y `crm_visitas_count()` aceptan un parámetro nuevo `or_creado_por`. Cuando se combina con `comercial_id`, el WHERE se vuelve `(comercial_id = X OR creado_por = X)`.
  - Nuevo shortcode `[crm_proximas_visitas]` con widget de escritorio adaptado al rol.
  - Filtro `the_content` `crm_inject_proximas_visitas_in_escritorio()`: inyecta el widget al inicio de la página `crm` si no contiene ya el shortcode.
- `includes/shortcode-agenda.php`:
  - Para comerciales puros (no admin, no visitador) se pasa `or_creado_por = current_user_id` y se muestra la columna "Asignado a".
  - Si la consulta filtrada está vacía y el usuario tiene visitas fuera del rango, se muestra un aviso explicativo.

### Sincronización con Google Calendar

Recordatorio: cada usuario tiene su feed iCal personal (`crm_gcal_serve_ical_feed`). Google Calendar refresca esos feeds aproximadamente cada 8–12 horas, por lo que para visitas "de un día para otro" funciona, pero para slots del mismo día conviene consultar Mi agenda directamente. No se ha añadido publicación push (Google Calendar API + OAuth) en esta versión: aporta mucha complejidad y el feed iCal cubre los casos habituales.
