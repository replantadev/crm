# v1.20.22 — Fix: Members AdminAccess bloqueaba admin-post.php para comerciales

## Causa raíz
El plugin **Members** (Cory Miller / MemberPress) tiene un add-on "Admin Access"
que en `admin_init` redirige a `home_url('/')` a cualquier usuario no-admin que
toque `/wp-admin/*`, incluyendo `admin-post.php`. Esto rompía completamente el
guardado de visitas (y cualquier otro formulario CRM que envíe a admin-post).

Confirmado vía backtrace capturado por v1.20.21:
```
crm_debug_wp_redirect_capture
wp_redirect
Members\AddOns\AdminAccess\access_check  ← culpable
do_action('admin_init')
```

## Solución
Nuevo hook `crm_allow_own_admin_post_actions` en `admin_init` con prioridad
`PHP_INT_MIN + 1` (muy temprana). Detecta peticiones a `admin-post.php` cuya
acción empieza por `crm_` y desengancha:

1. La función concreta `Members\AddOns\AdminAccess\access_check` vía
   `remove_action`.
2. Cualquier callback en el hook `admin_init` cuyo nombre incluya
   `Members\AddOns\AdminAccess` o `members_admin_access` (fallback defensivo
   para variantes futuras).

Resto del wp-admin sigue protegido para no-admin como antes.

## Compatibilidad
- No afecta a sitios sin Members instalado (remove_action de hooks inexistentes
  es no-op en WP).
- No abre wp-admin a no-admin: solo permite el endpoint admin-post.php para las
  acciones del CRM, que ya verifican nonce + permisos en sus propios handlers.
