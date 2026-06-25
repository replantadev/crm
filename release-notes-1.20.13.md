## v1.20.13 - Visitador en cabecera + modal sin chrome del tema

### Fixes

1. **Cabecera del CRM ahora muestra "Visitador" en lugar de "Comercial" cuando el usuario tiene el rol `visitador`**
   La prioridad de roles en `crm_user_primary_role()` se ha invertido: el rol mas restrictivo gana. Antes: `administrator > crm_admin > comercial > visitador`. Ahora: `visitador > comercial > crm_admin > administrator`.
   Coherente con la regla de seguridad existente: si un usuario tiene rol comercial o visitador, debe identificarse como tal aunque tambien tenga rol admin.

2. **Modal "Mi agenda" mostraba la cabecera de Astra (tema) dentro del iframe**
   En v1.20.12 ya suprimiamos el shell propio del CRM con `?crm_modal=1`, pero el header del tema (Astra: `#masthead`, `.ast-primary-header-bar`, etc.) y el footer seguian renderizandose.
   Fix: nuevo hook `wp_head` que cuando detecta `?crm_modal=1` inyecta CSS `display:none !important` sobre:
   - `#wpadminbar`, `html.wp-toolbar` (admin bar de WP)
   - `header.site-header`, `#masthead`, `.site-header`, `.ast-primary-header-bar`, `.ast-above-header`, `.ast-below-header`, `.main-header-bar-wrap`, `.main-header-bar`, `.ast-main-header`, `.ast-mobile-header-wrap`
   - `footer.site-footer`, `#colophon`, `.site-footer`, `.ast-scroll-to-top-wrap`
   - `.crm-topbar` (por si acaso)
   Tambien hace `show_admin_bar(false)` en `after_setup_theme` y resetea margenes/padding del contenedor del tema.
   Resultado: dentro del iframe solo se ve el contenido del shortcode, sin nav ni header de tema ni admin bar.

### Archivos
- `includes/roles.php`: invertida prioridad en `crm_user_primary_role()`.
- `includes/app-shell.php`: nuevo helper `crm_app_shell_is_modal_request()` + hook chromeless en `wp_head`/`after_setup_theme`.
