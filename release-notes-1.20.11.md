## v1.20.11 - Badges de estado por sector: rediseno minimal con escala de color

### Cambios
Rediseno completo de los pills "Sector · Estado" que aparecen en la cabecera de la ficha de cliente.

**Antes**: badges con gradientes rojo/azul/morado fijos por estado, sin distincion por sector.

**Ahora**: cada sector tiene un Hue base y el estado del flujo oscurece progresivamente el color. El paso final ("Cliente activo") se pinta solido para destacar el cierre.

### Sistema
- **Hue por sector**:
  - Energia: ambar (h:38)
  - Alarmas: rojo (h:0)
  - Telecom: azul (h:215)
  - Seguros: indigo (h:250)
  - Renovables: verde (h:145)
- **Steps del flujo** (0 a 6):
  - 0: Sin enviar (gris muy claro)
  - 1: Enviado
  - 2: Presupuesto generado
  - 3: Presupuesto aceptado
  - 4: Contratos generados
  - 5: Contratos firmados
  - 6: Cliente activo (solido)
- Cada step incrementa saturacion y oscurece L del background/border/texto/dot del pill.
- Indicador verde discreto si la ficha tiene `fecha_envio_por_sector` para ese sector ("reenviado").
- En movil <540px se oculta el label del estado y se conserva solo "Sector + dot" (excepto Activo).

### Archivos
- `crm-plugin.php`: reescrito `crm_render_estado_badges()` con nueva firma `($estado_por_sector, $sectores, $fechas_envio, $opts = [])`. Acepta `es_cliente_activo` y `entrada_vigor_por_sector` en `$opts` para detectar step 6.
- `crm-plugin.php`: los dos callsites (linea ~539 y ~714) ahora pasan los nuevos opts.
- `crm-styles.css`: nuevo bloque al final con sistema HSL por custom properties (`.crm-estado-pill`).

### Notas
- Las reglas viejas `.estado.borrador`, `.estado.enviado`, etc se conservan porque se usan en otros lugares (la cabecera del comercial, listados, widgets).
- Sin cambios de DB ni migracion.
