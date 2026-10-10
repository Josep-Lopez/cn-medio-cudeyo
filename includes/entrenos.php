<?php
// Helpers de dominio para entrenos (entrenamientos publicados por el entrenador por grupo).
// Requiere $pdo (config/db.php).

const ENTRENO_UPLOAD_DIR = __DIR__ . '/../public/uploads/entrenos/';
const ENTRENO_MAX_TAMANO = 10 * 1024 * 1024;
const ENTRENO_MAX_ARCHIVOS = 10;
const ENTRENO_MIMES_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

function es_staff_entrenos(): bool
{
    return is_admin() || user_tiene_cargo('director_tecnico') || user_tiene_cargo('entrenador');
}

function listar_entrenos(PDO $pdo, int $grupo_id = 0): array
{
    $sql = '
        SELECT e.*,
               GROUP_CONCAT(DISTINCT g.nombre ORDER BY g.nombre SEPARATOR \', \') AS grupos,
               (SELECT COUNT(*) FROM entreno_archivos a WHERE a.entreno_id = e.id) AS num_archivos
        FROM entrenos e
        LEFT JOIN entreno_grupos eg ON eg.entreno_id = e.id
        LEFT JOIN grupos_entrenamiento g ON g.id = eg.grupo_id';
    $params = [];
    if ($grupo_id > 0) {
        $sql .= ' WHERE e.id IN (SELECT entreno_id FROM entreno_grupos WHERE grupo_id = ?)';
        $params[] = $grupo_id;
    }
    $sql .= ' GROUP BY e.id ORDER BY e.fecha DESC, e.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function obtener_entreno(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM entrenos WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function grupos_de_entreno(PDO $pdo, int $entreno_id): array
{
    $stmt = $pdo->prepare('SELECT grupo_id FROM entreno_grupos WHERE entreno_id = ?');
    $stmt->execute([$entreno_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function archivos_de_entrenos(PDO $pdo, array $entreno_ids): array
{
    if (!$entreno_ids) return [];
    $in = implode(',', array_fill(0, count($entreno_ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM entreno_archivos WHERE entreno_id IN ($in) ORDER BY id");
    $stmt->execute(array_values($entreno_ids));
    $por_entreno = [];
    foreach ($stmt->fetchAll() as $a) {
        $por_entreno[(int)$a['entreno_id']][] = $a;
    }
    return $por_entreno;
}

function validar_datos_entreno(string $titulo, string $fecha, array $grupo_ids): array
{
    $titulo = trim($titulo);
    if ($titulo === '' || mb_strlen($titulo) > 150) {
        throw new InvalidArgumentException('Título obligatorio (máx. 150 caracteres)');
    }
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        throw new InvalidArgumentException('Fecha no válida');
    }
    $grupo_ids = array_values(array_unique(array_filter(array_map('intval', $grupo_ids))));
    if (!$grupo_ids) {
        throw new InvalidArgumentException('Elige al menos un grupo');
    }
    return [$titulo, $fecha, $grupo_ids];
}

function guardar_grupos_entreno(PDO $pdo, int $entreno_id, array $grupo_ids): void
{
    $pdo->prepare('DELETE FROM entreno_grupos WHERE entreno_id = ?')->execute([$entreno_id]);
    $stmt = $pdo->prepare('INSERT IGNORE INTO entreno_grupos (entreno_id, grupo_id)
                           SELECT ?, id FROM grupos_entrenamiento WHERE id = ?');
    foreach ($grupo_ids as $gid) {
        $stmt->execute([$entreno_id, $gid]);
    }
}

function crear_entreno(PDO $pdo, string $titulo, string $fecha, ?string $descripcion, array $grupo_ids, int $creado_por): int
{
    [$titulo, $fecha, $grupo_ids] = validar_datos_entreno($titulo, $fecha, $grupo_ids);
    $desc = $descripcion !== null && trim($descripcion) !== '' ? trim($descripcion) : null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO entrenos (titulo, fecha, descripcion, creado_por) VALUES (?, ?, ?, ?)')
            ->execute([$titulo, $fecha, $desc, $creado_por]);
        $id = (int)$pdo->lastInsertId();
        guardar_grupos_entreno($pdo, $id, $grupo_ids);
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function actualizar_entreno(PDO $pdo, int $id, string $titulo, string $fecha, ?string $descripcion, array $grupo_ids): void
{
    [$titulo, $fecha, $grupo_ids] = validar_datos_entreno($titulo, $fecha, $grupo_ids);
    $desc = $descripcion !== null && trim($descripcion) !== '' ? trim($descripcion) : null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE entrenos SET titulo = ?, fecha = ?, descripcion = ? WHERE id = ?')
            ->execute([$titulo, $fecha, $desc, $id]);
        guardar_grupos_entreno($pdo, $id, $grupo_ids);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function eliminar_entreno(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT archivo FROM entreno_archivos WHERE entreno_id = ?');
    $stmt->execute([$id]);
    $archivos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM entrenos WHERE id = ?')->execute([$id]);
    foreach ($archivos as $a) {
        $path = ENTRENO_UPLOAD_DIR . $a;
        if (is_file($path)) @unlink($path);
    }
}

// Convierte $_FILES['campo'] (multiple) en lista de ficheros; ignora los huecos vacíos.
function normalizar_ficheros(array $files): array
{
    $lista = [];
    if (!is_array($files['name'] ?? null)) {
        $files = array_map(fn($v) => [$v], $files);
    }
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $lista[] = [
            'name' => $name,
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
    }
    return $lista;
}

function subir_archivo_entreno(PDO $pdo, int $entreno_id, array $file): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir «' . ($file['name'] ?? '?') . '» (código ' . ($file['error'] ?? '?') . ')');
    }
    if ($file['size'] > ENTRENO_MAX_TAMANO) {
        throw new RuntimeException('«' . $file['name'] . '» es demasiado grande (máx. 10 MB)');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ENTRENO_MIMES_PERMITIDOS, true)) {
        throw new RuntimeException('Tipo de fichero no permitido: ' . $file['name']);
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM entreno_archivos WHERE entreno_id = ?');
    $countStmt->execute([$entreno_id]);
    if ((int)$countStmt->fetchColumn() >= ENTRENO_MAX_ARCHIVOS) {
        throw new RuntimeException('Máximo ' . ENTRENO_MAX_ARCHIVOS . ' archivos por entreno');
    }

    $ext = match ($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    };
    $nombre = 'ent_' . $entreno_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destino = ENTRENO_UPLOAD_DIR . $nombre;

    if (!is_dir(ENTRENO_UPLOAD_DIR)) {
        mkdir(ENTRENO_UPLOAD_DIR, 0775, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar el fichero');
    }

    try {
        $pdo->prepare('
            INSERT INTO entreno_archivos (entreno_id, archivo, nombre_original, mime, tamano)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$entreno_id, $nombre, mb_substr($file['name'], 0, 255), $mime, (int)$file['size']]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $ex) {
        if (is_file($destino)) @unlink($destino);
        throw $ex;
    }
}

// Sube todos los ficheros; devuelve los mensajes de error de los que fallen (los demás se guardan).
function subir_archivos_entreno(PDO $pdo, int $entreno_id, array $files): array
{
    $errores = [];
    foreach (normalizar_ficheros($files) as $f) {
        try {
            subir_archivo_entreno($pdo, $entreno_id, $f);
        } catch (Throwable $ex) {
            $errores[] = $ex->getMessage();
        }
    }
    return $errores;
}

function obtener_archivo_entreno(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM entreno_archivos WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function eliminar_archivo_entreno(PDO $pdo, int $id): void
{
    $a = obtener_archivo_entreno($pdo, $id);
    if (!$a) return;
    $pdo->prepare('DELETE FROM entreno_archivos WHERE id = ?')->execute([$id]);
    $path = ENTRENO_UPLOAD_DIR . $a['archivo'];
    if (is_file($path)) @unlink($path);
}

// Condición SQL de visibilidad: el nadador está en alguno de los grupos del entreno
// y el entreno se publicó después de que entrara en ese grupo. Alias de entrenos: e.
const ENTRENO_VISIBLE_SQL = 'EXISTS (
    SELECT 1 FROM entreno_grupos eg
    JOIN grupo_nadadores gn ON gn.grupo_id = eg.grupo_id
    WHERE eg.entreno_id = e.id AND gn.user_id = ? AND e.created_at >= gn.added_at
)';

function listar_entrenos_nadador(PDO $pdo, int $user_id, int $limite = 0): array
{
    $sql = '
        SELECT e.*,
               (SELECT GROUP_CONCAT(g.nombre ORDER BY g.nombre SEPARATOR \', \')
                FROM entreno_grupos eg2
                JOIN grupo_nadadores gn2 ON gn2.grupo_id = eg2.grupo_id AND gn2.user_id = ?
                JOIN grupos_entrenamiento g ON g.id = eg2.grupo_id
                WHERE eg2.entreno_id = e.id) AS grupos,
               (ev.user_id IS NULL) AS nuevo
        FROM entrenos e
        LEFT JOIN entreno_vistos ev ON ev.entreno_id = e.id AND ev.user_id = ?
        WHERE ' . ENTRENO_VISIBLE_SQL . '
        ORDER BY e.fecha DESC, e.id DESC';
    if ($limite > 0) $sql .= ' LIMIT ' . $limite;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $user_id, $user_id]);
    return $stmt->fetchAll();
}

function puede_ver_entreno(PDO $pdo, int $entreno_id, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM entrenos e WHERE e.id = ? AND ' . ENTRENO_VISIBLE_SQL);
    $stmt->execute([$entreno_id, $user_id]);
    return (bool)$stmt->fetchColumn();
}

function contar_entrenos_nuevos(PDO $pdo, int $user_id): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM entrenos e
        WHERE ' . ENTRENO_VISIBLE_SQL . '
          AND NOT EXISTS (SELECT 1 FROM entreno_vistos ev WHERE ev.entreno_id = e.id AND ev.user_id = ?)
    ');
    $stmt->execute([$user_id, $user_id]);
    return (int)$stmt->fetchColumn();
}

function marcar_entrenos_vistos(PDO $pdo, int $user_id): void
{
    $pdo->prepare('
        INSERT IGNORE INTO entreno_vistos (entreno_id, user_id)
        SELECT e.id, ? FROM entrenos e WHERE ' . ENTRENO_VISIBLE_SQL
    )->execute([$user_id, $user_id]);
}

function render_archivos_entreno(array $archivos, bool $con_borrar = false, int $entreno_id = 0): void
{
    if (!$archivos) return;
    $fotos = array_filter($archivos, fn($a) => str_starts_with($a['mime'], 'image/'));
    $docs  = array_filter($archivos, fn($a) => !str_starts_with($a['mime'], 'image/'));
?>
  <?php if ($fotos): ?>
    <div class="entreno-fotos">
      <?php foreach ($fotos as $a): $url = '/entreno_archivo?id=' . (int)$a['id']; ?>
        <div class="entreno-foto">
          <a href="<?= $url ?>" onclick="return abrirFotoEntreno(this.href)">
            <img src="<?= $url ?>" alt="<?= e($a['nombre_original']) ?>" loading="lazy">
          </a>
          <?php if ($con_borrar): ?>
            <button type="button" class="entreno-foto-borrar" title="Eliminar foto"
                    onclick="confirmarBorrarArchivo(<?= (int)$a['id'] ?>, '<?= e(addslashes($a['nombre_original'])) ?>')"><i class="bi bi-x-lg"></i></button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($docs): ?>
    <div class="d-flex gap-2 flex-wrap" style="margin-top:10px;">
      <?php foreach ($docs as $a): ?>
        <span class="d-flex gap-2 align-center">
          <a href="/entreno_archivo?id=<?= (int)$a['id'] ?>" target="_blank" class="btn btn-gray btn-sm">
            <i class="bi bi-file-earmark-pdf"></i> <?= e($a['nombre_original']) ?>
          </a>
          <?php if ($con_borrar): ?>
            <button type="button" class="btn btn-gray btn-sm" title="Eliminar PDF"
                    onclick="confirmarBorrarArchivo(<?= (int)$a['id'] ?>, '<?= e(addslashes($a['nombre_original'])) ?>')"><i class="bi bi-trash"></i></button>
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php
}

// Visor de fotos a pantalla completa (incluir una vez por página que use render_archivos_entreno).
function render_visor_fotos_entreno(): void
{
?>
<div id="visor-entreno" class="visor-entreno" style="display:none;" onclick="cerrarFotoEntreno()">
  <button type="button" class="visor-entreno-cerrar" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
  <img id="visor-entreno-img" alt="">
</div>
<script>
function abrirFotoEntreno(src) {
  document.getElementById('visor-entreno-img').src = src;
  document.getElementById('visor-entreno').style.display = 'flex';
  return false;
}
function cerrarFotoEntreno() {
  document.getElementById('visor-entreno').style.display = 'none';
  document.getElementById('visor-entreno-img').src = '';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrarFotoEntreno(); });
</script>
<?php
}
