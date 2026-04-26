# Remove lliga from marques — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the `lliga` column from the `marques` table so that category is derived from the user, not duplicated per mark.

**Architecture:** All SQL queries that currently filter/display `m.lliga` will be changed to use `u.lliga` (via the existing JOIN to `users`). The `marques` table loses its `lliga` column and the category UI elements are removed from the marks management form.

**Tech Stack:** PHP 8.4, MySQL 8, PDO

---

### Task 1: Update schema.sql — remove lliga from marques

**Files:**
- Modify: `schema.sql:43-64`

- [ ] **Step 1: Remove lliga column from marques table definition**

In `schema.sql`, change the `marques` CREATE TABLE to remove the `lliga` line:

```sql
CREATE TABLE IF NOT EXISTS marques (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    prova      VARCHAR(10) NOT NULL,
    piscina    ENUM('25m','50m') NOT NULL DEFAULT '25m',
    temps      VARCHAR(20) NOT NULL,
    temps_seg  FLOAT       NOT NULL,
    data_marca DATE        NOT NULL,
    lugar      VARCHAR(255) NOT NULL DEFAULT '',
    temporada  VARCHAR(10) GENERATED ALWAYS AS (
        IF(
            MONTH(data_marca) >= 9,
            CONCAT(YEAR(data_marca), '-', LPAD((YEAR(data_marca) + 1) MOD 100, 2, '0')),
            CONCAT(YEAR(data_marca) - 1, '-', LPAD(YEAR(data_marca) MOD 100, 2, '0'))
        )
    ) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_marca (user_id, prova, piscina, temporada, data_marca, lugar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Commit**

```bash
git add schema.sql
git commit -m "schema: remove lliga column from marques table"
```

---

### Task 2: Update admin marques.php — remove category from marks CRUD

**Files:**
- Modify: `public/admin/marques.php`

- [ ] **Step 1: Remove lliga from POST save handler (lines 20-24, 30-41)**

Change the save action to remove all `lliga_m` / `lliga_marca` handling. The INSERT and UPDATE queries should no longer include `lliga`:

```php
if ($action === 'save') {
    $marca_id  = (int)($_POST['marca_id'] ?? 0);
    $user_id   = (int)($_POST['user_id'] ?? 0);
    $prova     = $_POST['prova'] ?? '';
    $piscina   = $_POST['piscina'] ?? '25m';
    $temps     = trim($_POST['temps'] ?? '');
    $lugar     = trim($_POST['lugar'] ?? '');
    $data_m    = $_POST['data_marca'] ?? date('Y-m-d');

    if ($user_id && in_array($prova, $PROVES) && in_array($piscina, ['25m','50m']) && $temps) {
        $secs = temps_a_segons($temps);
        if ($secs > 0) {
            if ($marca_id > 0) {
                $stmt = $pdo->prepare('
                    UPDATE marques
                    SET prova=?, piscina=?, temps=?, temps_seg=?, data_marca=?, lugar=?, updated_at=NOW()
                    WHERE id=? AND user_id=?
                ');
                $stmt->execute([$prova, $piscina, $temps, $secs, $data_m, $lugar, $marca_id, $user_id]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO marques (user_id, prova, piscina, temps, temps_seg, data_marca, lugar)
                    VALUES (?,?,?,?,?,?,?)
                ');
                $stmt->execute([$user_id, $prova, $piscina, $temps, $secs, $data_m, $lugar]);
            }
            flash('Marca guardada correctamente.', 'success');
        } else {
            flash('Formato de tiempo incorrecto. Usa mm:ss.cc o ss.cc', 'danger');
        }
    }
}
```

- [ ] **Step 2: Remove lliga_back from redirect params (line 56)**

Remove the `'lliga' => $_POST['lliga_back'] ?? ''` line from the `$back` array.

- [ ] **Step 3: Remove "Categoría" column from the histórico table (lines 216-227)**

Remove the `<th>Categoría</th>` header and the corresponding `<td>` that displays `format_lliga($marca['lliga'])`.

- [ ] **Step 4: Remove lliga from openForm JS and edit button onclick (line 232)**

The `openForm()` call currently passes `lliga` as 6th param. Remove that parameter. Update the function signature to remove `lliga`:

```javascript
function openForm(prova, temps, data, marcaId, lugar) {
```

Update the edit button onclick:
```php
onclick="openForm('<?= e($marca['prova']) ?>', '<?= e($marca['temps']) ?>', '<?= e($marca['data_marca']) ?>', <?= (int)$marca['id'] ?>, <?= htmlspecialchars(json_encode($marca['lugar'] ?? ''), ENT_QUOTES) ?>)"
```

- [ ] **Step 5: Remove category select from modal form (lines 283-293)**

Delete the entire `<div class="form-group">` block containing the "Categoría" `<select id="modalLligaSelect">`.

Also remove the hidden input `<input type="hidden" name="lliga_marca" id="modalLligaMarca">` (line 273).

- [ ] **Step 6: Remove lliga-related JS code**

Remove these from the `<script>`:
- Lines setting `modalLligaMarca` and `modalLligaSelect` values in `openForm()` and `closeForm()`
- The `modalLligaSelect` change event listener (line 399-401)

- [ ] **Step 7: Clean up lliga_back hidden inputs in delete form (line 240)**

Remove: `<input type="hidden" name="lliga_back" value="<?= e($selected_user['lliga'] ?? '') ?>">`

- [ ] **Step 8: Update RFEN import link (line 176)**

Remove the `&lliga=...` parameter from the RFEN import link URL:
```php
<a href="/admin/rfen_importar?user_id=<?= $selectedUserId ?>&temporada=<?= e($temporada) ?>" ...>
```

- [ ] **Step 9: Commit**

```bash
git add public/admin/marques.php
git commit -m "admin/marques: remove lliga from marks CRUD and UI"
```

---

### Task 3: Update admin rfen_importar.php — stop saving lliga per mark

**Files:**
- Modify: `public/admin/rfen_importar.php`

- [ ] **Step 1: Remove lliga_marca variable (lines 156-157)**

Delete these two lines:
```php
$lliga_marca = $_GET['lliga'] ?? $_POST['lliga_marca'] ?? ($nadador['lliga'] ?? '');
$lliga_marca = in_array($lliga_marca, ['benjamin','alevin','infantil','junior','absoluto','master'], true) ? $lliga_marca : null;
```

- [ ] **Step 2: Update INSERT query to exclude lliga (lines 195-204)**

```php
$stmtImport = $pdo->prepare('
    INSERT INTO marques (user_id, prova, piscina, temps, temps_seg, data_marca, lugar)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        temps=IF(VALUES(temps_seg)<temps_seg, VALUES(temps), temps),
        temps_seg=IF(VALUES(temps_seg)<temps_seg, VALUES(temps_seg), temps_seg),
        data_marca=IF(VALUES(temps_seg)<temps_seg, VALUES(data_marca), data_marca),
        lugar=IF(VALUES(temps_seg)<temps_seg, VALUES(lugar), lugar),
        updated_at=NOW()
');
```

- [ ] **Step 3: Update execute call to remove lliga_marca (line 223)**

Change from:
```php
$stmtImport->execute([$user_id, $prova, $piscina, $lliga_marca, $temps, $secs, $data_m, $lugar]);
```
To:
```php
$stmtImport->execute([$user_id, $prova, $piscina, $temps, $secs, $data_m, $lugar]);
```

- [ ] **Step 4: Update redirect to remove lliga param (line 235)**

```php
header('Location: /admin/marques?user_id=' . $user_id);
```

- [ ] **Step 5: Remove lliga_marca from form hidden input (line 409)**

Delete: `<input type="hidden" name="lliga_marca" value="<?= e($lliga_marca ?? '') ?>">`

- [ ] **Step 6: Clean up "Volver" link lliga param (line 356)**

Change to:
```php
<a href="/admin/marques?user_id=<?= $user_id ?>" class="btn btn-gray btn-sm">
```

Same for the "Cancelar" link (line 425):
```php
<a href="/admin/marques?user_id=<?= $user_id ?>" class="btn btn-gray">
```

- [ ] **Step 7: Commit**

```bash
git add public/admin/rfen_importar.php
git commit -m "rfen_importar: stop saving lliga per mark on import"
```

---

### Task 4: Update admin ranking.php — filter by u.lliga instead of m.lliga

**Files:**
- Modify: `public/admin/ranking.php`

- [ ] **Step 1: Change sortable map (line 35)**

Change `'lliga' => 'm.lliga'` to `'lliga' => 'u.lliga'`.

- [ ] **Step 2: Update "mejores marcas" query (lines 47-67)**

Change `m.lliga` references to `u.lliga`:

Line 52: `$where .= ' AND m.lliga=?'` → `$where .= ' AND u.lliga=?'`

Line 59 SELECT: change `m.lliga` to `u.lliga`

Line 65 GROUP BY: change `m.lliga` to `u.lliga`

- [ ] **Step 3: Update normal ranking query (lines 68-80)**

Line 72: `$where .= ' AND m.lliga=?'` → `$where .= ' AND u.lliga=?'`

Line 74 SELECT: `m.*` already pulls everything, but the template uses `$row['lliga']`. Since `lliga` won't exist in marques anymore, we need to explicitly select `u.lliga`:

```php
$sql = "
    SELECT m.*, u.nom, u.sexe, u.lliga
    FROM marques m
    JOIN users u ON u.id = m.user_id
    $where
    ORDER BY $orderSql
";
```

- [ ] **Step 4: Update "mejores marcas" SELECT similarly**

Ensure the SELECT in the millors query uses `u.lliga` instead of `m.lliga`:

```php
$sql = "
    SELECT m.temps, m.lugar, m.data_marca, m.temporada, m.prova,
           u.nom, u.lliga, u.sexe,
           MIN(m.temps_seg) AS best_seg
    FROM marques m
    JOIN users u ON u.id = m.user_id
    $where
    AND m.temps_seg = (SELECT MIN(m2.temps_seg) FROM marques m2 WHERE $sub_where)
    GROUP BY u.id, u.nom, u.lliga, u.sexe, m.temps, m.lugar, m.data_marca, m.temporada, m.prova
    ORDER BY $orderSql
";
```

- [ ] **Step 5: Commit**

```bash
git add public/admin/ranking.php
git commit -m "admin/ranking: filter and display category from users table"
```

---

### Task 5: Update soci ranking.php — filter by u.lliga instead of m.lliga

**Files:**
- Modify: `public/soci/ranking.php`

- [ ] **Step 1: Change sortable map (line 39)**

Change `'lliga' => 'm.lliga'` to `'lliga' => 'u.lliga'`.

- [ ] **Step 2: Update "mejores marcas" query (lines 48-84)**

Line 59: `$where .= ' AND m.lliga=?'` → `$where .= ' AND u.lliga=?'`

Line 76 SELECT: change `m.lliga` to `u.lliga`

Line 82 GROUP BY: change `m.lliga` to `u.lliga`

- [ ] **Step 3: Update normal ranking query (lines 85-103)**

Line 93: `$where .= ' AND m.lliga=?'` → `$where .= ' AND u.lliga=?'`

Line 96-99: Add explicit `u.lliga` to SELECT:
```php
$sql = "
    SELECT m.*, u.nom, u.sexe, u.lliga, u.id as uid
    FROM marques m
    JOIN users u ON u.id = m.user_id
    $where
    ORDER BY $orderSql
";
```

- [ ] **Step 4: Commit**

```bash
git add public/soci/ranking.php
git commit -m "soci/ranking: filter and display category from users table"
```

---

### Task 6: Apply DB migration on running Docker instance

**Files:**
- None (SQL command only)

- [ ] **Step 1: Run ALTER TABLE to drop lliga from marques**

```bash
docker compose exec db mysql -u root -proot cn_medio_cudeyo -e "ALTER TABLE marques DROP COLUMN lliga;"
```

If this fails because the column is part of a UNIQUE KEY, first check the constraint name and drop it if needed. The current UNIQUE KEY `unique_marca` is `(user_id, prova, piscina, temporada, data_marca, lugar)` — it does NOT include `lliga`, so the ALTER should work directly.

- [ ] **Step 2: Verify the column is gone**

```bash
docker compose exec db mysql -u root -proot cn_medio_cudeyo -e "DESCRIBE marques;"
```

Expected: no `lliga` row in the output.

- [ ] **Step 3: Commit (no file changes — migration is in schema.sql already)**

No commit needed, schema.sql was already updated in Task 1.

---

### Task 7: Smoke test the application

- [ ] **Step 1: Verify admin ranking loads**

Open http://localhost:8080/admin/ranking and confirm:
- Page loads without errors
- Category filter dropdown works (filters by user category)
- "Mejores marcas" toggle works

- [ ] **Step 2: Verify admin marques loads**

Open http://localhost:8080/admin/marques, select a user:
- No "Categoría" column in the histórico table
- Add/edit mark modal has no category select
- Save a mark — should work without errors

- [ ] **Step 3: Verify soci ranking loads**

Log in as a soci and open /soci/ranking:
- Category filter works
- Marks show user's category, not mark's category

- [ ] **Step 4: Verify RFEN import**

Open /admin/marques, select a user with RFEN ID, click "Importar desde RFEN":
- Page loads
- Import form has no lliga_marca hidden field
- Importing marks works without errors
