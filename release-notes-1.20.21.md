# v1.20.21 — Debug profundo: trace de wp_redirect + checkpoints en handler

## Cambios
- Nuevo filter `wp_redirect` con prioridad 1 que intercepta cualquier redirect durante una petición con `_crm_debug=1` o `_crm_debug=trace`. Devuelve JSON con:
  - `location` y `status` del redirect interceptado
  - `backtrace` completo (qué función disparó el redirect)
  - `handler_seen` (true si el handler de visita llegó a ejecutarse)
  - `handler_step` (último checkpoint alcanzado dentro del handler)
- `crm_visita_handle_save` instrumentado con checkpoints por fase: `entered`, `before_check_admin_referer`, `after_check_admin_referer`, `after_input_parse`, `after_target_resolution`, `before_create`, `after_create` (etc.).
- Permite distinguir si el redirect a home viene de: (a) lockdown, (b) check_admin_referer, (c) crm_visita_create, (d) wp_safe_redirect del handler, o (e) terceros (theme/otro plugin).

## Cómo usar
Enviar el form normal con `_crm_debug=1` adicional. El filter devolverá JSON con la primera redirección y desde dónde se originó.

Quitar todo el bloque debug tras resolver.
