# v1.20.17 — Fix: agenda no mostraba visitas creadas por comercial+visitador + selector fecha/hora estable

## Resumen

Dos fixes del feedback de v1.20.16.

### Cambios

- **Bug 1 — Comercial+visitador no veia sus visitas creadas en agenda.** En v1.20.15 se anadio el filtro `or_creado_por` para que un *comercial puro* viera tambien las visitas que el mismo declaraba y delegaba. Pero la condicion era `is_comercial_no_visitador`, que excluia a los usuarios hibridos con roles `comercial + visitador` (que son la mayoria en este CRM). Ahora la condicion es: cualquier usuario que pueda crear visitas (`crm_visita_can_create()` true) ve en su agenda tanto las asignadas a el como las que el mismo creo, este o no asignado a el. Asi un usuario hibrido ve TODAS sus visitas: las que tiene asignadas + las que ha declarado para si mismo + las que ha delegado a otros visitadores.

- **Bug 2 — Selector de fecha/hora solo mostraba la fecha.** El input `<input type="datetime-local">` renderiza inconsistente entre navegadores: Firefox muchas veces oculta el spinner de hora si no hay focus, Safari iOS lo abre como solo-fecha en ciertas configuraciones, algunas instalaciones de Chrome lo recortan visualmente. Se ha sustituido por dos inputs nativos separados, `<input type="date">` + `<input type="time">`, que se combinan via JS en el hidden `fecha_visita` con formato `YYYY-MM-DDTHH:MM` (el mismo que esperaba el handler). La hora se inicializa al proximo cuarto de hora. El submit valida que ambos esten rellenos antes de enviar y propaga el cambio al chequeo de disponibilidad anti-solape.

### Archivos modificados

- `includes/shortcode-agenda.php`: condicion `or_creado_por` ahora basada en `crm_visita_can_create()`.
- `includes/visitas.php`: input fecha-hora dividido en date+time + JS de sincronizacion + validacion submit.

### Notas operativas

- Tras desplegar, reinicia LocalWP / PHP-FPM (OPcache) y haz Ctrl+Shift+R en el navegador para invalidar caches.
- El formato enviado al servidor sigue siendo `Y-m-d\TH:i`, totalmente compatible con `crm_visita_sanitize`.
