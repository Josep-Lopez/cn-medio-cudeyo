# Ranking por edades (10–18) — Diseño

**Fecha:** 2026-05-24
**Estado:** Aprobado (pendiente de plan de implementación)

## Objetivo

Añadir un ranking de marcas por **edad deportiva** (10 a 18 años) en paralelo al ranking por liga existente. Permite ver los récords del club por edad, con la fecha de la marca y el año de nacimiento del nadador.

## Conceptos clave

- **Edad deportiva**: `YEAR(fecha_marca) - YEAR(fecha_nacimiento)`. Estándar FINA/RFEN. No depende del día/mes.
- **Rango**: marcas cuya edad cae en `[10, 18]`. Resto se excluyen.
- **Inclusión de nadador**: requiere `users.fecha_nacimiento IS NOT NULL` y `users.estado = 'activo'`. Filtro adicional `nadador_activo` consistente con el ranking por liga.

## Arquitectura

Toggle visual ("Por liga" / "Por edad") al principio del ranking en socio y admin. Cada modo en archivo separado:

| Ruta | Estado |
|------|--------|
| `public/socio/ranking.php` | Existente. Se añade el toggle. |
| `public/socio/ranking-edades.php` | Nuevo. |
| `public/admin/ranking.php` | Existente. Se añade el toggle. |
| `public/admin/ranking-edades.php` | Nuevo. |

Justificación: el `ranking.php` actual ya tiene dos ramas (`$filterMejores` + detalle). Añadir una tercera rama "edad" con sus agrupaciones haría el SQL difícil de leer. Archivos hermanos mantienen cada modo aislado.

## Filtros (form GET, sticky en query string)

| Parámetro | Valores | Default |
|-----------|---------|---------|
| `prueba` | `''` (Todas) o una de las 18 pruebas | `''` |
| `piscina` | `25m`, `50m` | `25m` |
| `sexo` | `M`, `F`, `''` (ambos) | Sexo del usuario logueado (igual que ranking actual) |
| `temporada` | Lista temporadas + `todas` | `todas` |
| `nadador` | `1` (activo), `0` (todos) | `1` |

## Dos vistas

### Vista A — prueba seleccionada

9 bloques: uno por edad (10, 11, ..., 18). Cada bloque = tabla **top-10** marcas absolutas en esa edad+prueba, ordenadas por `tiempo_seg ASC`.

Columnas: posición, tiempo, nombre, año de nacimiento, fecha de la marca, lugar.

Bloque vacío: "Sin marcas registradas a esta edad."

### Vista B — sin prueba (matriz)

Tabla matriz única. Filas = edades 10..18. Columnas = 18 pruebas.

Cada celda muestra: mejor tiempo absoluto + nombre nadador + año de nacimiento (formato compacto, p.ej. `'14`). Si no hay marca: `—`.

Celda con datos = link a misma página con `?prueba=X&...` (resto de filtros preservados) → Vista A filtrada por esa prueba.

## SQL

### Vista A — top-10 por edad

```sql
WITH ranked AS (
  SELECT
    m.tiempo, m.tiempo_seg, m.fecha_marca, m.lugar, m.piscina,
    u.id AS uid, u.nombre, u.sexo,
    YEAR(u.fecha_nacimiento) AS anio_nac,
    YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento) AS edad,
    ROW_NUMBER() OVER (
      PARTITION BY YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento)
      ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC
    ) AS rn
  FROM marcas m
  JOIN users u ON u.id = m.user_id
  WHERE u.estado = 'activo'
    AND u.fecha_nacimiento IS NOT NULL
    AND m.piscina = :piscina
    AND m.prueba  = :prueba
    /* filtros opcionales: sexo, temporada, nadador_activo */
    AND YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento) BETWEEN 10 AND 18
)
SELECT * FROM ranked WHERE rn <= 10
ORDER BY edad ASC, rn ASC;
```

PHP agrupa por `edad` en bucle → 9 bloques.

### Vista B — mejor por edad+prueba

```sql
WITH ranked AS (
  SELECT
    m.prueba, m.tiempo, m.tiempo_seg, m.fecha_marca,
    u.nombre, YEAR(u.fecha_nacimiento) AS anio_nac,
    YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento) AS edad,
    ROW_NUMBER() OVER (
      PARTITION BY
        YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento),
        m.prueba
      ORDER BY m.tiempo_seg ASC, m.fecha_marca ASC
    ) AS rn
  FROM marcas m
  JOIN users u ON u.id = m.user_id
  WHERE u.estado = 'activo'
    AND u.fecha_nacimiento IS NOT NULL
    AND m.piscina = :piscina
    /* filtros opcionales: sexo, temporada, nadador_activo */
    AND YEAR(m.fecha_marca) - YEAR(u.fecha_nacimiento) BETWEEN 10 AND 18
)
SELECT * FROM ranked WHERE rn = 1;
```

PHP construye matriz `[edad][prueba] = fila` → tabla 9×18.

### Índices

Existentes en `marcas`: PK + `unique_marca (user_id, prueba, piscina, temporada, fecha_marca, lugar)`.

Sin índice extra al inicio. Si crece, considerar `INDEX (piscina, prueba)`.

## UI / Layout

### Toggle

Dos `<a>` con clases `.tab` y `.tab--active` arriba del bloque de filtros, en las cuatro páginas afectadas. Pill con fondo azul (`--blue`) cuando activa.

```
[ Por liga ] [ Por edad ]
```

### Filtros

Form GET único compartido por Vista A y B:

- Selector prueba (con "Todas las pruebas" → Vista B)
- Selector piscina (25m / 50m)
- Selector sexo (Mujer / Hombre / Ambos)
- Selector temporada (con "Todas")
- Checkbox "Solo nadadores activos"
- Botón "Aplicar"

### Vista A — render

9 `<section class="edad-block">`. Cada sección: encabezado "Edad N" + tabla top-10.

### Vista B — render

Tabla `.matriz-edades`. Móvil: `overflow-x: auto`.

### CSS

Añadir clases `.ranking-tabs`, `.edad-block`, `.matriz-edades` a [public/assets/css/main.css](public/assets/css/main.css). Reusa estilos de tabla existentes.

## Edge cases

- **Usuario sin `fecha_nacimiento`**: sus marcas no aparecen en modo edad.
- **Edad fuera de 10–18**: marca excluida.
- **BD sin marcas en rango**: Vista A muestra todos los bloques vacíos; Vista B matriz con `—` en todas las celdas.
- **Filtros inválidos en query string**: caer al default (patrón consistente con ranking actual).
- **Sexo `''` (ambos)** en matriz: mezcla M/F en mismo top-10 / celda. Aceptado.
- **SQL injection**: prepared statements en todo el código.

## Test manual

1. Login socio con `fecha_nacimiento` válida → `/socio/ranking.php` → tab "Por edad".
2. Sin prueba → matriz 9×18, celdas pobladas donde hay marcas.
3. Click celda → carga Vista A con prueba pre-seleccionada.
4. Vista A → 9 bloques edad, top-10 por bloque.
5. Cambiar piscina/sexo/temporada/nadador → resultados cambian.
6. Login admin → `/admin/ranking.php` → mismo toggle.
7. Marca con edad <10 o >18 no aparece.
8. Nadador sin `fecha_nacimiento` no aparece.

## Navegación

- Sin entrada nueva en sidebar socio ni admin. El toggle vive dentro del ranking.
- Añadir las 2 nuevas rutas a la tabla "Estado de páginas" de `CLAUDE.md`.

## Fuera de alcance (YAGNI)

- Export CSV/Excel del modo edad.
- Modo "rango edad" (p.ej. 12–14 agrupado).
- Edad real (cumpleaños exacto).
- Comparativa entre temporadas.
- Modo "edad real a la fecha".
- Edad fuera del rango 10–18.
