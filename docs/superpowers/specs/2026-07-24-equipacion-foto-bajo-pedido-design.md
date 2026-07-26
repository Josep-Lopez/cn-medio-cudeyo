# Equipación — foto de producto + artículos "bajo pedido"

**Fecha:** 2026-07-24
**Estado:** Aprobado, pendiente de plan de implementación

## Contexto

Extensión del módulo de equipación ya mergeado en `main` (ver `docs/superpowers/specs/2026-07-24-equipacion-design.md` y `docs/superpowers/plans/2026-07-24-equipacion.md`). Dos añadidos pedidos tras el lanzamiento:

1. Foto por artículo en el catálogo.
2. Artículos sin stock fijo ("bajo pedido") — se piden sin límite de unidades, sin bloqueo ni descuento de stock.

## Alcance

- ✅ Una foto por artículo (no galería), subida de fichero (no URL externa).
- ✅ Flag `bajo_pedido` a nivel de artículo completo (no por talla individual).
- ✅ Artículos `bajo_pedido`: sin límite de cantidad, sin aviso especial en el catálogo del socio, sin descuento/reposición de stock.
- ❌ Fuera de alcance: galería de varias fotos, "bajo pedido" por talla individual, aviso visual de "bajo pedido" en el catálogo.

## Modelo de datos (migración `020_equipacion_foto_bajo_pedido.sql`)

```sql
ALTER TABLE equipacion_items
  ADD COLUMN imagen_url VARCHAR(255) NULL AFTER descripcion,
  ADD COLUMN bajo_pedido TINYINT(1) NOT NULL DEFAULT 0 AFTER precio;
```

Sin tabla nueva. `imagen_url` nullable (igual que `noticias.imagen_url`). `bajo_pedido` por defecto `0` (comportamiento actual sin cambios para artículos existentes).

## Subida de foto

Reutiliza el patrón exacto de `public/admin/noticias.php`:
- Input `type="file"` en el modal de crear/editar artículo de `public/directiva/equipacion.php`.
- Validación: `getimagesize()` (rechaza no-imágenes), mime en `['image/jpeg','image/png','image/webp','image/gif']`, tamaño máx 8 MB.
- Nombre de fichero aleatorio, guardado en `public/uploads/equipacion/` (directorio nuevo, con su propio `.htaccess` copiado de `public/uploads/noticias/.htaccess` — deniega ejecución PHP y listado de directorio).
- Campo oculto `imagen_url` conserva el valor actual si no se sube fichero nuevo al editar.
- `crear_item`/`editar_item` procesan `$_FILES['imagen']` igual que `noticias.php` procesa el suyo.

En el catálogo del socio (`socio/equipacion.php`), la imagen se muestra junto al nombre/descripción del artículo; si `imagen_url` es null, se muestra un icono placeholder (sin romper el layout).

## Artículos "bajo pedido"

Checkbox "Bajo pedido (sin límite de stock)" en el mismo modal de crear/editar artículo, guarda `bajo_pedido` (0/1).

**Catálogo socio:** si `bajo_pedido=1`, todas las tallas del selector aparecen siempre habilitadas, sin mostrar número de stock ni disabled por "(sin stock)".

**Añadir al carrito** (`socio/equipacion.php`, acción `add`): si la variante pertenece a un artículo `bajo_pedido=1`, se salta el check `stock >= cantidad` — siempre se permite añadir la cantidad solicitada.

**`equipacion_reservar_stock()`** (`includes/equipacion.php`, ya existente): para cada línea, si el artículo de la variante es `bajo_pedido=1`, no ejecuta el `UPDATE ... SET stock = stock - ?` — esa línea se considera reservada automáticamente (nunca puede fallar por falta de stock). El resto de líneas (artículos normales) siguen la reserva atómica existente sin cambios.

**`equipacion_reponer_stock()`**: simétrico — no incrementa el stock de líneas cuyo artículo es `bajo_pedido=1` al cancelar/expirar un pedido (nada que reponer, evita inflar un número de stock que ya es irrelevante).

**Directiva** (`directiva/equipacion.php`): cuando un artículo tiene `bajo_pedido=1`, el campo de stock de sus tallas se deshabilita visualmente en la tabla de variantes (sigue existiendo en BD como dato dormido, pero no se puede editar ni tiene efecto).

## Edge cases

- Artículo pasa de `bajo_pedido=1` a `0` (directiva lo desactiva): las tallas existentes conservan el último valor de stock que tuvieran en BD (probablemente desactualizado/0) — la directiva debe revisar y ajustar el stock manualmente tras el cambio. No se añade lógica automática de "stock inicial" al desactivar `bajo_pedido`.
- Pedido ya en `pendiente_pago` de un artículo que después pasa a `bajo_pedido=1`: sin efecto retroactivo, ese pedido ya tiene su línea con cantidad fija y no vuelve a pasar por `equipacion_reservar_stock()`.
- Imagen eliminada del servidor manualmente (fuera de la web): el catálogo mostrará una imagen rota; no se añade verificación de existencia de fichero en cada carga (mismo comportamiento que `noticias.php` ya tiene con `imagen_url`).

## Testing (manual — el proyecto no tiene suite automatizada)

- Subir foto a un artículo nuevo y editado, confirmar que se muestra en `/socio/equipacion`.
- Subir fichero no-imagen o >8MB, confirmar rechazo con mensaje claro.
- Marcar un artículo como `bajo_pedido`, confirmar que su talla admite cualquier cantidad en el carrito y que el checkout no falla ni descuenta stock.
- Cancelar un pedido de un artículo `bajo_pedido`, confirmar que no se modifica su stock.
- Desmarcar `bajo_pedido` en un artículo, confirmar que vuelve a aplicar el check de stock normal.
