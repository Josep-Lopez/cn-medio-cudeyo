# Competiciones + Fichas de nadador — Design

**Fecha:** 2026-05-17
**Estado:** spec aprobado, pendiente de plan de implementación
**Inspiración:** subset histórico de SplashMe / swimrankings.net

## Objetivo

Página pública de **Competiciones** (resultados pasados completos, estilo SplashMe sin la parte "live") y **fichas públicas de nadador**. Datos extraídos de **swimrankings.net** (archivo público de Splash Meet Manager).

Este spec cubre los sub-proyectos **A (histórico competiciones del club)** y **B (fichas públicas de nadador)** del brainstorming. Quedan explícitamente fuera del scope:

- ❌ Resultados en vivo (sub-proyecto D, fase futura, decisión fuera de scope por coste de mantenimiento)
- ❌ Calendario de próximas competiciones (sub-proyecto C, fase futura)
- ❌ Rankings mundiales, favoritos, notificaciones push
- ❌ Almacenamiento de resultados de nadadores no socios (se parsean al importar pero no se guardan en BD)

## Ubicación en el sitio

**Público (sin login)**, sigue el patrón de `/public/noticias/`:

- `/competiciones/` (`public/competiciones/index.php`) — listado paginado de competiciones donde han nadado socios del club, ordenadas por fecha descendente
- `/competiciones/detall.php?id=X` — ficha de una competición con todos los resultados de socios agrupados por prueba
- `/nadador/detall.php?slug=X` — ficha pública del nadador (canónico: `/nadador/[slug]`)

**Admin**:

- `/admin/swimrankings.php` — buscar deportistas en swimrankings.net, vincular a usuarios del club, importar meets de un nadador, listar competiciones importadas
- `scripts/swimrankings_import_all.php` — CLI espejo de `rfen_import_all.php` para importación masiva

**Navbar público**: añadir entrada "Competiciones" entre "Noticias" y "Biblioteca".

## Modelo de datos

### Tablas nuevas

```sql
CREATE TABLE competiciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  swimrankings_meet_id INT NULL UNIQUE,
  nombre VARCHAR(255) NOT NULL,
  lugar VARCHAR(255),
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE,
  piscina ENUM('25m','50m','open') DEFAULT '25m',
  organizador VARCHAR(100),
  url_origen VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fecha (fecha_inicio DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE competicion_resultados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  competicion_id INT NOT NULL,
  user_id INT NULL,
  nombre_nadador VARCHAR(150) NOT NULL,
  prueba VARCHAR(10) NOT NULL,
  tiempo VARCHAR(10) NOT NULL,
  tiempo_seg DECIMAL(8,2) NOT NULL,
  fase ENUM('final','semifinal','serie') DEFAULT 'serie',
  puesto INT NULL,
  fecha_marca DATE NOT NULL,
  FOREIGN KEY (competicion_id) REFERENCES competiciones(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user (user_id),
  INDEX idx_competicion (competicion_id),
  UNIQUE KEY uniq_resultado (competicion_id, user_id, prueba, fase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Cambios en tabla `users`

```sql
ALTER TABLE users
  ADD COLUMN swimrankings_id INT NULL UNIQUE AFTER rfen_id,
  ADD COLUMN slug VARCHAR(100) NULL UNIQUE AFTER nombre,
  ADD COLUMN perfil_publico TINYINT(1) NOT NULL DEFAULT 1 AFTER swimrankings_id;
```

Slug generado automáticamente en el primer guardado del usuario (slugify del nombre, colisiones `-2`, `-3`).

## Componentes

### 1. Scraper — `includes/swimrankings.php`

Espejo del patrón de `includes/rfen.php`. URLs base de swimrankings.net (a confirmar con muestras reales durante la implementación):

- Detalle nadador: `https://www.swimrankings.net/index.php?page=athleteDetail&athleteId=X`
- Detalle meet: `https://www.swimrankings.net/index.php?page=meetDetail&meetId=X`
- Búsqueda: `https://www.swimrankings.net/index.php?page=athleteSearch&athleteLastname=X&athleteClub=Y`

Funciones públicas:

- `swr_fetch_html(string $url): string` — fetch con curl + UTF-8 (idéntico a `rfen_fetch_html`)
- `swr_buscar_athlete(string $nombre, ?int $club_id = null): array` — devuelve candidatos con `id`, `nombre`, `nacimiento`, `club`
- `swr_get_athlete_meets(int $swimrankings_id, ?string $since_date = null): array` — lista de meets del nadador con `meet_id`, `nombre`, `fecha`, `lugar`
- `swr_parse_meet_results(int $meet_id): array` — todos los resultados del meet, devuelve filas con `nadador_swr_id`, `nombre`, `prueba`, `tiempo`, `fase`, `puesto`
- `swr_import_meet(PDO $pdo, int $meet_id): array` — INSERT/UPDATE en `competiciones` + INSERT en `competicion_resultados` filtrando solo nadadores con `swimrankings_id` matcheado en `users`. Devuelve contadores como `rfen_import_marks`.

Pruebas válidas: reutilizar el array de `rfen_prueba()` (mismas 18 pruebas).

### 2. Páginas públicas

**`public/competiciones/index.php`**:
- Paginado 12 por página
- Card por competición: nombre, lugar, fechas, badge con N socios participantes, link a detalle
- Sin filtros en MVP (orden fijo: fecha DESC)

**`public/competiciones/detall.php`**:
- Header con nombre, lugar, fechas, piscina, link externo a swimrankings.net
- Tabla agrupada por prueba; columnas: puesto, nombre nadador (link a `/nadador/[slug]` si `perfil_publico=1`, texto plano si no), tiempo, fase
- Solo muestra resultados de socios del club (filtro `user_id IS NOT NULL`)

**`public/nadador/detall.php`**:
- Si `users.perfil_publico = 0` → 404
- Si usuario no encontrado por slug → 404
- Cabecera: nombre, foto opcional (no en MVP), liga, sexo, club
- Sección "Mejores marcas" — tabla de `marcas` por prueba/piscina (reutilizar query existente del panel socio)
- Sección "Últimas competiciones" — últimas 10 entradas de `competicion_resultados` ordenadas por fecha desc
- Sin gráfico de evolución en MVP

### 3. Admin — `public/admin/swimrankings.php`

Sigue el estilo de `rfen_buscar.php` / `rfen_importar.php`:
- Buscador por nombre + filtro club
- Lista candidatos con botón "Vincular a usuario [select]"
- Una vez vinculado, botón "Importar todos los meets" → recorre `swr_get_athlete_meets()` y por cada meet llama `swr_import_meet()`
- Listado de competiciones importadas con botón "Re-importar"

CLI: `scripts/swimrankings_import_all.php` — recorre todos los `users` con `swimrankings_id` NOT NULL e importa sus meets de la temporada activa.

## Flujo de datos

1. Admin entra a `/admin/swimrankings.php` y busca nadador
2. Selecciona candidato → vincula a usuario del club (set `users.swimrankings_id`)
3. Click "Importar meets" → para cada meet del nadador:
   - `INSERT … ON DUPLICATE KEY UPDATE` en `competiciones` (key: `swimrankings_meet_id`)
   - Parsea todos los resultados del meet
   - Por cada resultado, busca `users.swimrankings_id` que matchea el ID del nadador en swimrankings
   - Si match → INSERT en `competicion_resultados` con `user_id`; si no → descarta (no guardamos no-socios en MVP)
4. Página pública `/competiciones/` agrega: `SELECT … FROM competiciones c WHERE EXISTS (SELECT 1 FROM competicion_resultados r WHERE r.competicion_id = c.id) ORDER BY c.fecha_inicio DESC`
5. Detalle muestra `competicion_resultados` JOIN `users` agrupado por prueba

## Privacidad

- Columna `users.perfil_publico` (default 1)
- Socio puede toggle desde `/socio/perfil`
- Si =0:
  - Nombre sigue apareciendo en `/competiciones/detall.php` pero como texto plano (sin link)
  - `/nadador/[slug]` devuelve 404
  - Mejores marcas del nadador no aparecen ni en `/ranking` público (si lo hubiera) ni en la ficha

## Errores y casos límite

- **swimrankings.net caído**: timeout 15s en curl, mostrar error "datos no disponibles" en admin, no romper páginas públicas (sirven desde BD)
- **HTML cambia y rompe parser**: log + flash al admin, sin actualización destructiva (ya estaba en BD)
- **Homonimia de nadadores**: el admin elige manualmente al vincular (un humano lo resuelve)
- **Slug colisión**: intenta slug base (slugify del nombre); si ya existe, añade sufijo `-2`, `-3`… hasta encontrar uno libre
- **Resultado duplicado por re-importación**: UNIQUE KEY `(competicion_id, user_id, prueba, fase)` evita duplicados; re-import sobrescribe tiempo/puesto si difiere

## Testing

Sin tests automatizados (consistente con el resto del proyecto). Validación manual:

1. Vincular 2-3 socios reales con sus IDs de swimrankings
2. Importar 1 meet conocido y verificar resultados
3. Comprobar `/competiciones/` y `/competiciones/detall.php?id=X`
4. Comprobar `/nadador/[slug]` (público) y toggle `perfil_publico` desde `/socio/perfil`
5. Probar re-importación (no debe duplicar filas)

## Reuso de código existente

- `render_header()`, `render_footer()`, `render_admin_layout()` — layout
- `require_login()`, `require_admin()`, `current_user()`, `e()` — auth
- `format_prueba()`, `tiempo_a_segundos()`, `segundos_a_tiempo()`, `format_liga()` — utils
- Patrón `rfen_fetch_html()` y `rfen_parse_rows()` — base para swimrankings scraper
- Estilos en `public/assets/css/main.css` (cards, tablas, formularios admin)
- Patrón de paginación de `public/noticias/index.php`

## Decisiones diferidas (NO bloquean implementación)

- Gráfico de evolución en ficha de nadador (out of MVP, evaluar tras feedback de socios)
- Foto de perfil del nadador (out of MVP)
- Filtros en `/competiciones/` (por temporada, piscina) — añadir si N > 30 competiciones
- Resultados de nadadores no-socios (decidir si interesa cuando se vea uso real)
