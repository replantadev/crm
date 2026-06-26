# v1.20.18 — Fix: guardar visita no debe redirigir al home + modo debug

## Cambios

- **`crm_visita_handle_save` / `crm_visita_handle_estado`**: el fallback de redirect cuando `wp_get_referer()` queda vacio dejaba de apuntar a `admin.php?page=crm-mi-agenda` (URL wp-admin que el lockdown convierte en redirect al escritorio/home, perdiendo el banner). Ahora va al frontend: `home_url('/editar-cliente/?client_id=X')` si tenemos client_id, o `home_url('/mi-agenda/')` como ultimo recurso.
- **Modo debug temporal en `crm_visita_handle_save`**: si el POST trae `_crm_debug=1` y el usuario tiene capacidad de crear visitas, en lugar de redirigir devuelve JSON con el estado de la operacion (`wp_get_referer`, `wp_get_raw_referer`, `home_url`, redirect computado, etc.) para diagnosticar casos en los que el redirect aterriza en home. Eliminar en v1.20.19.

## Archivos

- `includes/visitas.php`
- `crm-plugin.php` (version bump)

## Notas

- Reinicia LocalWP / PHP-FPM tras deploy.
- Si tras actualizar el comportamiento persiste, ejecutar en consola desde la ficha:
  ```js
  (async function(){
    var f = document.querySelector('form[id^="crm-visita-form-"]');
    var d=f.querySelector('.crm-visita-fecha-d'), h=f.querySelector('.crm-visita-fecha-h');
    var hidden=f.querySelector('input[name="fecha_visita"]');
    if(d&&h&&hidden&&d.value&&h.value) hidden.value=d.value+'T'+h.value;
    var fd=new FormData(f); fd.append('_crm_debug','1');
    var r=await fetch(f.getAttribute('action'),{method:'POST',body:fd,credentials:'same-origin'});
    console.log(await r.json());
  })();
  ```
