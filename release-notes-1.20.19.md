# v1.20.19 — Hardening lockdown wp-admin + diagnostico early

## Cambios

- **`crm_block_wpadmin_for_non_admins`** (admin-lockdown.php): ademas de comprobar `$pagenow`, ahora tambien comprueba el basename del `REQUEST_URI` para detectar `admin-post.php` / `admin-ajax.php` / `profile.php`. Esto evita que un setup con reverse proxy/FastCGI que deje `$pagenow` vacio o incorrecto bloquee el POST de guardar visita y termine redirigiendo al usuario al home.
- **Hook diagnostico `crm_visita_debug_early_dump`** (visitas.php): en `admin_init` priority 0, si POST trae `_crm_debug=1` y el usuario puede crear visitas, devuelve JSON con `$pagenow`, `REQUEST_URI`, `PHP_SELF`, `wp_get_referer`, roles, etc. antes de que cualquier otro hook redirija. Util para diagnosticar este tipo de incidentes. Quitar en v1.20.20 si todo va bien.

## Archivos

- `includes/admin-lockdown.php`
- `includes/visitas.php`
- `crm-plugin.php` (version bump)

## Notas

- Tras actualizar reinicia OPcache (subir el zip via Plugins → Subir nuevo → Reemplazar normalmente invalida automaticamente).
- Prueba `_crm_debug=1` desde consola y pegame el JSON.
