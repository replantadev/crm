# v1.20.23 — Limpieza: retirada de la instrumentación de debug de visitas

Bug del guardado de visitas resuelto en v1.20.22 (Members AdminAccess
desenganchado para acciones admin-post.php que empiezan por `crm_`).

## Cambios
- Eliminado `crm_visita_debug_early_dump` y su hook en admin_init.
- Eliminado `crm_debug_handler_state`, `crm_debug_wp_redirect_capture` y el
  filter `wp_redirect` asociado.
- Eliminados los checkpoints `$state['step']` dentro de
  `crm_visita_handle_save`.
- Eliminado el bloque que devolvía JSON cuando llegaba `_crm_debug=1`.

El handler `crm_visita_handle_save` queda con su lógica original limpia:
auth → nonce → input → permisos → create/update → wp_safe_redirect.
