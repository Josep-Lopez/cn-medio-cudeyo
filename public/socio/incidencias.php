<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/incidencias.php';

require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    csrf_verify();
    try {
        $data = [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'fecha_suceso' => $_POST['fecha_suceso'] ?? '',
            'user_id' => (int)$user['id'],
            'visible_socio' => 1,
            'creado_por' => (int)$user['id'],
        ];
        $files = [];
        if (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name'])) {
            foreach ($_FILES['adjuntos']['name'] as $idx => $name) {
                if (($_FILES['adjuntos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $files[] = [
                    'name' => $name,
                    'tmp_name' => $_FILES['adjuntos']['tmp_name'][$idx],
                    'error' => $_FILES['adjuntos']['error'][$idx],
                    'size' => $_FILES['adjuntos']['size'][$idx],
                    'type' => $_FILES['adjuntos']['type'][$idx],
                ];
            }
        }
        $id = crear_incidencia($pdo, $data, $files);
        flash('Incidencia creada.', 'success');
        header('Location: /socio/incidencias?ver=' . $id);
        exit;
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'danger');
        header('Location: /socio/incidencias?accion=nueva');
        exit;
    }
}

$accion = $_GET['accion'] ?? '';
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;

$filtros = [
    'tipo' => $_GET['tipo'] ?? '',
    'estado' => $_GET['estado'] ?? '',
];
$incidencias = listar_incidencias_socio($pdo, (int)$user['id'], $filtros);

render_header('Incidencias', 'socio-incidencias');
?>

<main class="container" style="padding:24px 16px;">
  <?php if ($accion === '' && $verId === 0): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
      <h1 style="margin:0;">Mis incidencias</h1>
      <a href="/socio/incidencias?accion=nueva" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva incidencia</a>
    </div>
    <?php render_flash(); ?>

    <div class="card mb-6">
      <form method="GET" class="d-flex gap-3 align-center flex-wrap" style="padding:16px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-control" style="width:auto;">
            <option value="">Todos</option>
            <?php foreach (INCIDENCIA_TIPOS as $t): ?>
              <option value="<?= $t ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= format_incidencia_tipo($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Estado</label>
          <select name="estado" class="form-control" style="width:auto;">
            <option value="">Todos</option>
            <?php foreach (INCIDENCIA_ESTADOS as $e): ?>
              <option value="<?= $e ?>" <?= $filtros['estado'] === $e ? 'selected' : '' ?>><?= format_incidencia_estado($e) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <a href="/socio/incidencias" class="btn btn-gray btn-sm">Limpiar</a>
      </form>
    </div>

    <?php if (!$incidencias): ?>
      <div class="card text-center" style="padding:32px;">
        <p class="text-muted">No tienes incidencias.</p>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Título</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($incidencias as $i): ?>
                <tr>
                  <td><?= date('d/m/Y', strtotime($i['fecha_suceso'])) ?></td>
                  <td><span class="badge <?= badge_clase_tipo($i['tipo']) ?>"><?= format_incidencia_tipo($i['tipo']) ?></span></td>
                  <td><?= e($i['titulo']) ?></td>
                  <td><span class="badge <?= badge_clase_estado($i['estado']) ?>"><?= format_incidencia_estado($i['estado']) ?></span></td>
                  <td><a href="/socio/incidencias?ver=<?= (int)$i['id'] ?>" class="btn btn-gray btn-sm">Ver →</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($accion === 'nueva'): ?>
    <h1>Nueva incidencia</h1>
    <?php render_flash(); ?>
    <form method="POST" action="/socio/incidencias" enctype="multipart/form-data" class="card" style="padding:24px;max-width:680px;">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="crear">

      <div class="form-group">
        <label class="form-label">Tipo *</label>
        <select name="tipo" class="form-control" required>
          <option value="">— Selecciona —</option>
          <?php foreach (INCIDENCIA_TIPOS as $t): ?>
            <option value="<?= $t ?>"><?= format_incidencia_tipo($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="titulo" class="form-control" required maxlength="200">
      </div>

      <div class="form-group">
        <label class="form-label">Descripción *</label>
        <textarea name="descripcion" class="form-control" rows="5" required></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Fecha del suceso *</label>
        <input type="date" name="fecha_suceso" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Adjuntos (PDF, JPG, PNG — máx 5 MB, hasta 5)</label>
        <input type="file" name="adjuntos[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png">
      </div>

      <div style="display:flex;gap:12px;">
        <button type="submit" class="btn btn-primary">Crear</button>
        <a href="/socio/incidencias" class="btn btn-gray">Cancelar</a>
      </div>
    </form>
  <?php endif; ?>
</main>

<?php render_footer(); ?>
