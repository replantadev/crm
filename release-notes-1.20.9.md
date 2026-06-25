## v1.20.9 - SEGURIDAD: comerciales ya no pueden escalar privilegios

### Bug critico
Un comercial (o visitador) con la capability `crm_admin` inyectada por un plugin externo (Members, User Role Editor) podia ver y usar el bloque admin de la ficha de cliente, incluyendo:
- Reasignar el cliente a CUALQUIER comercial via dropdown "Comercial Asignado".
- Cambiar el ESTADO global y por sector.
- Cambiar el origen del lead.
- Marcar/desmarcar "cliente activo".
- Forzar entrada en vigor por sector.

Causa: el plugin usaba `current_user_can('crm_admin')` para gatekeeping. Esa funcion devuelve true en cuanto el usuario TIENE la capability, independientemente de su ROL. Plugins externos pueden conceder esa cap a usuarios que NO son admin.

### Fix (sistemico, una linea cubre todos los chequeos)
Nuevo filtro `user_has_cap` en `includes/roles.php` con prioridad 999:

```php
function crm_filter_user_has_cap($allcaps, $caps, $args, $user) {
    if (!is_object($user) || empty($user->ID)) return $allcaps;
    $roles = (array) ($user->roles ?? []);
    if (array_intersect(['administrator', 'crm_admin'], $roles)) return $allcaps;
    $admin_only = ['crm_admin','crm_view_all_clients','crm_edit_all_clients','crm_delete_clients','crm_manage_settings','crm_view_logs','crm_export_data','crm_manage_visits'];
    foreach ($admin_only as $cap) {
        if (!empty($allcaps[$cap])) $allcaps[$cap] = false;
    }
    return $allcaps;
}
```

Resultado: si un usuario NO tiene rol `administrator` ni `crm_admin`, todas las caps admin del CRM se revocan dinamicamente. `current_user_can('crm_admin')` devuelve `false` para comerciales y visitadores aunque otro plugin se las haya concedido. Esto neutraliza de golpe los ~30 chequeos `current_user_can('crm_admin')` repartidos por el plugin.

### Refuerzos adicionales
- **Frontend ficha cliente** (`crm_formulario_alta_cliente`): bloque admin gateado por `crm_user_is_admin()` (chequeo basado en rol) en vez de `current_user_can()`. Doble cinturon.
- **Dropdown "Comercial Asignado"**: ahora lista `role__in => ['comercial', 'visitador']` (antes solo `comercial`), coherente con v1.20.7 donde el visitador puede dar altas.
- El hardening existente del backend (lineas ~1874-1894 que fuerzan `delegado`, `email_comercial`, `origen_lead`, `es_cliente_activo` al guardar) sigue intacto y ahora se activa CORRECTAMENTE para comerciales con caps inyectadas (antes podia saltarselo).

### Archivos modificados
- `includes/roles.php`: nuevo filtro `crm_filter_user_has_cap` (prioridad 999).
- `crm-plugin.php`: bloque admin de la ficha de cliente usa `crm_user_is_admin()`; dropdown delegado incluye visitadores; bump version.

### Post-actualizacion
1. Recarga la ficha de un cliente como **comercial** (incluso uno al que se le haya dado por error la cap `crm_admin`) → el bloque "Comercial Asignado / Estado global / Origen lead / Cliente activo" YA NO se ve.
2. Si intenta enviar esos campos via POST manual, el backend los descarta y mantiene los valores originales del cliente.
3. Como **administrator** o **crm_admin** todo sigue funcionando igual.
