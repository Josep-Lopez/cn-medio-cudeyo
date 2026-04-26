# RFEN Import Improvements — Design Spec

## Goal

Extend RFEN mark importing to support historical seasons (from 2012), all users at once, and weekly automated execution via cron.

## Current State

- `public/admin/rfen_importar.php` handles manual import for a single user
- Season selector shows last 6 seasons (~2020–current)
- Helper functions (`rfen_temps_to_local`, `rfen_prova`, `rfen_fecha_iso`, `rfen_fetch_html`, `rfen_parse_rows`) are defined inline in the file
- Import logic: INSERT with ON DUPLICATE KEY UPDATE (keeps best time)
- RFEN URL pattern: `https://intranet.rfen.es/ConsultarHistorial.dcl?e={rfen_id}&x_OPCION=ResultadosNatacion&x_FILTRO5_INICIO={start}&x_FILTRO5_FIN={end}`
- Pagination: up to 50 pages per fetch

## Changes

### 1. Extract RFEN helpers to `includes/rfen.php`

Move these functions from `rfen_importar.php` to a new shared file:

- `rfen_temps_to_local(string $t): ?string`
- `rfen_prova(string $estilo, string $distancia): ?string`
- `rfen_fecha_iso(string $fecha): string`
- `rfen_fetch_html(string $url): string`
- `rfen_parse_rows(DOMXPath $xpath): array`

Add a new function for the shared import logic:

- `rfen_import_marks(PDO $pdo, int $user_id, string $rfen_id, ?string $temporada): array`
  - If `$temporada` is null or `'todas'`, fetches without date filter
  - If `$temporada` is a season string like `'2024-25'`, calculates start/end dates
  - Fetches all pages from RFEN, parses rows, deduplicates
  - Inserts/updates marks in DB using ON DUPLICATE KEY UPDATE (same logic as current)
  - Returns associative array: `['procesadas' => int, 'insertadas' => int, 'actualizadas' => int, 'sin_cambios' => int, 'error' => ?string]`

### 2. Extend season selector in `rfen_importar.php`

Change the season range from `$current_year - 5` to start at `2012`:

```php
for ($y = $current_year; $y >= 2012; $y--)
```

This generates seasons from current down to `2012-13`.

Update `rfen_importar.php` to:
- `require_once` the new `includes/rfen.php`
- Remove the inline function definitions
- Use `rfen_import_marks()` for the POST handler instead of inline import logic

### 3. Create CLI script `scripts/rfen_import_all.php`

Script that imports current season marks for all active users with `rfen_id`.

```
Usage: php scripts/rfen_import_all.php [--temporada=2024-25] [--user_id=123]
```

- Default temporada: current season (calculated same way as in admin pages)
- Optional `--user_id` to limit to a single user (useful for testing/manual runs)
- Loads `.env` from project root, requires `config/db.php` and `includes/rfen.php`
- Queries: `SELECT id, nom, rfen_id FROM users WHERE estado='activo' AND rfen_id IS NOT NULL`
- For each user: calls `rfen_import_marks()`, prints result line
- Exit code: 0 if all OK, 1 if any user had errors
- Output format (one line per user):
  ```
  [2026-04-26 06:00:01] Juan García (rfen:12345): 15 procesadas, 3 insertadas, 2 actualizadas, 10 sin cambios
  ```

### 4. Crontab configuration

Not automated by the app — documented for manual setup on production:

```
0 6 * * 3  php /path/to/scripts/rfen_import_all.php >> /var/log/rfen_import.log 2>&1
```

## Files affected

| File | Action |
|------|--------|
| `includes/rfen.php` | **Create** — shared RFEN helpers + `rfen_import_marks()` |
| `public/admin/rfen_importar.php` | **Modify** — remove inline functions, require rfen.php, use shared import, extend season range |
| `scripts/rfen_import_all.php` | **Create** — CLI import script |

## Out of scope

- Notifications (email/Slack) on import results
- Admin UI to trigger "import all users" (the cron handles this)
- Historical backfill UI (admin uses existing UI with extended season selector)
- Docker cron setup (production is bare metal)

## Environment

- Production: bare metal server, PHP 8.4, MySQL 8, `.env` for DB credentials
- Development: Docker Compose (same `.env` structure)
- The CLI script must work in both environments
