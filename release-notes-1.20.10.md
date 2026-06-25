## v1.20.10 - Fix render visitas + restriccion estricta admin/comercial

### Fixes
1. **`?>` literal antes del bloque Visitas**: en `includes/visitas.php` linea 685 habia un cierre de PHP huerfano que se imprimia tal cual al renderizar la ficha del cliente.
2. **Admin+comercial podia editar cabecera de su propia ficha**: si el usuario tenia rol `administrator` Y `comercial` (o `visitador`) simultaneamente, `crm_user_is_admin()` devolvia true y le mostraba el bloque admin de la ficha (reasignar comercial, cambiar estado global, origen, cliente activo). Ahora la regla es: **tener rol comercial o visitador inhabilita la consideracion de admin** del CRM, aunque tambien sea administrator del sitio.

### Cambios
- `includes/visitas.php`: eliminado `?>` suelto entre el banner "Tienes N visitas" y la card de Visitas.
- `includes/roles.php`:
  - `crm_user_is_admin()`: si el usuario tiene rol `comercial` o `visitador`, devuelve `false` aunque tambien tenga `administrator` o `crm_admin`.
  - `crm_filter_user_has_cap()` (prio 999): revoca todas las caps admin del CRM (crm_admin, crm_view_all_clients, crm_edit_all_clients, crm_delete_clients, crm_manage_settings, crm_view_logs, crm_export_data, crm_manage_visits) para cualquier usuario con rol comercial/visitador, INCLUSO si tambien es administrator.

### Impacto
- Un usuario admin+comercial (caso comun en sitios chicos donde el dueño tambien hace ventas) ya no puede reasignar sus propios clientes a otro comercial ni cambiar el estado global desde la cabecera de la ficha.
- El backend (`crm_guardar_cliente_ajax`, lineas 1874-1894) ya forzaba `delegado`/`email_comercial`/`origen_lead`/`es_cliente_activo` a los valores originales cuando `current_user_can('crm_admin')` era false. Con esta release ese hardening se dispara para usuarios admin+comercial.
- Un administrador PURO del sitio (sin rol comercial ni visitador) sigue viendo y pudiendo cambiar todo, como antes.

### Notas
- El frontend de la cabecera ya usa `crm_user_is_admin()` (v1.20.9), por lo que el cambio en esa funcion propaga la restriccion automaticamente.
- No requiere migracion de datos ni cambio de configuracion.
