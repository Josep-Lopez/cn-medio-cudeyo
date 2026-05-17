# Competiciones + Fichas de nadador — Design

**Fecha:** 2026-05-17
**Estado:** spec aprobado, pendiente de plan de implementación
**Inspiración:** subset histórico de SplashMe (datos de swimrankings.net / Splash Meet Manager)

## Objetivo

App **separada del sitio principal** (subdominio propio, identidad visual propia) que muestra:

- Listado de competiciones con resultados completos (histórico)
- Fichas públicas de nadador

Datos extraídos de **swimrankings.net** (archivo público de Splash Meet Manager). No relacionada con el módulo RFEN/marcas del sitio principal — vive en paralelo y solo comparte BD para vincular con `users`.

Cubre los sub-proyectos **A (histórico de competiciones)** y **B (fichas públicas de nadador)**. Quedan explícitamente fuera del scope:

- ❌ Resultados en vivo (sub-proyecto D — futura fase, mejor enfoque vía iframe de livetiming.splash.de en su día)
- ❌ Calendario de próximas competiciones (sub-proyecto C — futura fase)
- ❌ Rankings mundiales, favoritos, notificaciones push
- ❌ Almacenamiento de resultados de no-socios (se parsean al importar pero no se guardan en BD)

## Naming

Nombre de la app: **TBD** (lo decide el usuario antes de implementación). En todo este documento aparece como `[NOMBRE_APP]`. Ejemplos placeholder:

- `competiciones.cnmediocudeyo.es`
- `[NOMBRE_APP].cnmediocudeyo.es`

Cuando el usuario decida el nombre, se hace search/replace de `[NOMBRE_APP]` por el slug definitivo en este spec y en el código generado.

## Arquitectura: subdominio independiente

### Producción

- Subdominio: `[NOMBRE_APP].cnmediocudeyo.es`
- Apache vhost separado apuntando a una nueva carpeta `[NOMBRE_APP]/public/` (paralela a la actual `public/`)
- Comparte el mismo servidor PHP y la **misma BD MySQL** (mismo `cn_medio_cudeyo`)
- Sesión **independiente** del sitio principal (cookies del subdominio, no compartidas)

### Desarrollo local

- Docker Compose añade un nuevo servicio Apache (o un vhost extra en el contenedor existente) en puerto 8082
- Acceso local vía `http://[NOMBRE_APP].localhost:8082` o `http://localhost:8082`
- Misma BD que el sitio principal

### Estructura de directorios

```
cn-medio-cudeyo/
├── public/                    ← sitio del club (sin cambios)
├── [NOMBRE_APP]/              ← NUEVO: app aparte
│   ├── public/                ← DocumentRoot del subdominio
│   │   ├── index.php          ← landing: últimas competiciones
│   │   ├── competicion.php    ← detalle de una competición ?id=X
│   │   ├── nadador.php        ← ficha pública ?slug=X
│   │   ├── assets/
│   │   │   ├── css/main.css   ← identidad visual propia
│   │   │   └── js/
│   │   └── admin/             ← panel admin de esta app
│   │       ├── index.php
│   │       ├── buscar.php     ← buscar deportistas en swimrankings
│   │       ├── importar.php   ← importar meets de un nadador
│   │       └── login.php      ← auth propia (reutiliza tabla users con rol=admin)
│   └── includes/
│       ├── auth.php           ← helpers de auth para el subdominio
│       ├── layout.php         ← header/footer propios
│       └── swimrankings.php   ← scraper
├── includes/                  ← compartidos sitio principal (sin cambios)
├── config/                    ← db.php compartido
├── scripts/
│   └── swimrankings_import_all.php  ← CLI mass import
└── docker-compose.yml         ← actualizado con vhost del subdominio
```

### Identidad visual

- CSS propio en `[NOMBRE_APP]/public/assets/css/main.css`
- Reutiliza variables de color base si se quiere coherencia (o totalmente distinto, a decidir con mockup)
- Header/footer propios — sin navbar del club
- En el sitio principal del club, un enlace prominente (footer o sección "Más") apunta al subdominio

## Modelo de datos

BD compartida (`cn_medio_cudeyo`), tablas nuevas con prefijo opcional para aislamiento:

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
  ADD COLUMN swimrankings_id INT NULL UNIQUE,
  ADD COLUMN slug VARCHAR(100) NULL UNIQUE AFTER nombre,
  ADD COLUMN perfil_publico TINYINT(1) NOT NULL DEFAULT 1;
```

Slug generado automáticamente al guardar usuario (slugify del nombre + colisiones `-2`, `-3`).

## Componentes

### 1. Scraper — `[NOMBRE_APP]/includes/swimrankings.php`

Patrón genérico de scraping HTML (curl + DOMXPath + UTF-8 + paginación). URLs base de swimrankings.net (a confirmar con muestras reales en implementación):

- Detalle nadador: `https://www.swimrankings.net/index.php?page=athleteDetail&athleteId=X`
- Detalle meet: `https://www.swimrankings.net/index.php?page=meetDetail&meetId=X`
- Búsqueda: `https://www.swimrankings.net/index.php?page=athleteSearch&athleteLastname=X&athleteClub=Y`

Funciones públicas:

- `swr_fetch_html(string $url): string` — fetch con curl + UTF-8
- `swr_buscar_athlete(string $nombre, ?int $club_id = null): array` — candidatos con id, nombre, nacimiento, club
- `swr_get_athlete_meets(int $swimrankings_id, ?string $since_date = null): array` — meets del nadador
- `swr_parse_meet_results(int $meet_id): array` — resultados completos del meet
- `swr_import_meet(PDO $pdo, int $meet_id): array` — INSERT/UPDATE en `competiciones` + `competicion_resultados`, vinculando por `users.swimrankings_id`; devuelve contadores

Pruebas válidas: array compartido con el sitio principal (las 18 pruebas estándar). Se extrae a un helper común si interesa, o se duplica si queremos aislamiento total.

### 2. Páginas públicas (subdominio)

**`index.php`** (landing):
- Paginado 12 por página
- Listado de competiciones con socios participantes, ordenadas por `fecha_inicio DESC`
- Card por competición: nombre, lugar, fechas, badge "N socios", link al detalle

**`competicion.php?id=X`**:
- Header: nombre, lugar, fechas, piscina, link externo a swimrankings.net
- Tabla agrupada por prueba; columnas: puesto, nombre nadador (link a `nadador.php?slug=X` si `perfil_publico=1`), tiempo, fase
- Solo muestra resultados de socios del club (filtro `user_id IS NOT NULL`)

**`nadador.php?slug=X`**:
- Si `perfil_publico=0` o slug no existe → 404
- Cabecera: nombre, liga, sexo
- "Mejores marcas" — query a tabla `marcas` existente
- "Últimas competiciones" — últimas 10 entradas de `competicion_resultados` ordenadas por fecha desc

### 3. Admin (subdominio)

`[NOMBRE_APP]/public/admin/`:
- Login propio (reutiliza `users` con `rol='admin'`, sesión independiente del club)
- **Buscar deportistas** en swimrankings, vincular a usuarios del club
- **Importar meets** de un nadador concreto
- **Listar competiciones** importadas, re-importar, eliminar

CLI: `scripts/swimrankings_import_all.php` — recorre todos los `users` con `swimrankings_id NOT NULL` e importa sus meets de la temporada activa.

## Flujo de datos

1. Admin entra a `[NOMBRE_APP].cnmediocudeyo.es/admin/` y busca nadador en swimrankings
2. Selecciona candidato → vincula a usuario (set `users.swimrankings_id`)
3. Click "Importar meets" → por cada meet del nadador:
   - `INSERT … ON DUPLICATE KEY UPDATE` en `competiciones` (key: `swimrankings_meet_id`)
   - Parsea todos los resultados del meet
   - Para cada resultado, busca match en `users.swimrankings_id`
   - Si match → INSERT en `competicion_resultados`; si no → descarta
4. Landing del subdominio agrega: `SELECT … FROM competiciones c WHERE EXISTS (SELECT 1 FROM competicion_resultados r WHERE r.competicion_id = c.id) ORDER BY c.fecha_inicio DESC`
5. Detalle muestra `competicion_resultados JOIN users` agrupado por prueba

## Privacidad

- Columna `users.perfil_publico` (default 1)
- Socio puede toggle desde `/socio/perfil` en el sitio principal
- Si =0:
  - Nombre sigue apareciendo en `competicion.php` pero como texto plano (sin link)
  - `nadador.php?slug=X` devuelve 404
  - Mejores marcas del nadador no aparecen en su ficha pública del subdominio

## Errores y casos límite

- **swimrankings.net caído**: timeout 15s en curl, error "datos no disponibles" en admin, páginas públicas siguen sirviendo desde BD
- **HTML cambia y rompe parser**: log + flash al admin, sin actualización destructiva
- **Homonimia de nadadores**: el admin elige manualmente al vincular
- **Slug colisión**: intenta slug base; si existe, añade sufijo `-2`, `-3`… hasta encontrar libre
- **Resultado duplicado en re-importación**: UNIQUE KEY `(competicion_id, user_id, prueba, fase)` evita duplicados; re-import sobrescribe tiempo/puesto si difiere
- **Subdominio sin DNS aún**: dev usa Docker en puerto distinto; deploy puede arrancar primero con `[NOMBRE_APP]-prefix path` y migrar a subdominio cuando el DNS esté listo

## Testing

Sin tests automatizados (consistente con el resto del proyecto). Validación manual:

1. Levantar Docker con vhost del subdominio funcionando
2. Vincular 2-3 socios reales con sus IDs de swimrankings
3. Importar 1 meet conocido y verificar resultados
4. Comprobar `index.php`, `competicion.php?id=X`, `nadador.php?slug=X`
5. Toggle `perfil_publico` desde `/socio/perfil` y comprobar que la ficha pública se cae
6. Re-importar el mismo meet (no debe duplicar filas)

## Reuso de código existente

- `config/db.php` compartido (mismo `$pdo`)
- `format_prueba()`, `tiempo_a_segundos()`, `segundos_a_tiempo()`, `format_liga()` — extraer a un helper común reutilizable desde ambos sitios, o duplicar mínimamente
- Patrón de scraping HTML con curl + DOMXPath (existe en el repo, sirve de referencia)
- Estilos: el subdominio tiene CSS propio pero puede heredar paleta y tipografía si se quiere coherencia

## Decisiones diferidas (no bloquean implementación)

- **Nombre del subdominio** — TBD
- **Identidad visual concreta** — mockup pendiente; default a reutilizar paleta del club
- Gráfico de evolución en ficha de nadador (out of MVP)
- Foto de perfil del nadador (out of MVP)
- Filtros en landing (por temporada, piscina) — añadir si N > 30 competiciones
- Resultados de no-socios (decidir si interesa con uso real)
- Sesión compartida entre dominio principal y subdominio (default: independiente)
