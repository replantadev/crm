# v1.20.20 — Diagnostico: separar early dump del handler dump

Sin cambios funcionales. Solo separa los triggers del modo debug:

- `_crm_debug=early` → dump en `admin_init` priority 0 (antes de cualquier hook).
- `_crm_debug=1` → dump dentro de `crm_visita_handle_save` despues de computar el redirect (para ver que URL decide el handler).

Necesario para diagnosticar el incidente actual: el handler debería calcular `redirect=/editar-cliente/?client_id=X&crm_visita_msg=created` pero el navegador acaba en home. Hay que ver que URL pasa a `wp_safe_redirect`.

Quitar todo el codigo de debug en v1.20.21 tras resolver.
