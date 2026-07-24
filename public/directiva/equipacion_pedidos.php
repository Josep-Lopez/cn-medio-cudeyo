<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/equipacion.php';

require_cargo(['presidente', 'secretario', 'tesorero', 'vocal', 'director_tecnico']);

$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $pedido_id = (int)($_POST['pedido_id'] ?? 0);

    if ($action === 'entregar') {
        $pdo->prepare(
            "UPDATE equipacion_pedidos SET estado='entregado', entregado_por=?, entregado_at=NOW()
             WHERE id=? AND estado='pagado'"
        )->execute([$uid, $pedido_id]);
        flash('Pedido marcado como entregado.', 'success');
    } elseif ($action === 'cancelar') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE id=? FOR UPDATE');
        $stmt->execute([$pedido_id]);
        $pedido = $stmt->fetch();
        if ($pedido && $pedido['estado'] === 'pendiente_pago') {
            equipacion_reponer_stock($pdo, $pedido_id);
            $pdo->prepare(
                "UPDATE equipacion_pedidos SET estado='cancelado', cancelado_por=?, cancelado_at=NOW() WHERE id=?"
            )->execute([$uid, $pedido_id]);
            $pdo->commit();
            flash('Pedido cancelado.', 'warning');
        } else {
            $pdo->rollBack();
            flash('Este pedido ya no se puede cancelar.', 'danger');
        }
    }

    $qs = isset($_GET['estado']) ? '?estado=' . urlencode($_GET['estado']) : '';
    header('Location: /directiva/equipacion_pedidos' . $qs);
    exit;
}

$fEstado = $_GET['estado'] ?? 'todos';
$estadosOk = ['pendiente_pago', 'pagado', 'entregado', 'cancelado'];
$where = [];
$params = [];
if (in_array($fEstado, $estadosOk, true)) { $where[] = 'p.estado=?'; $params[] = $fEstado; }
$sqlW = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT p.*, u.nombre AS socio_nombre,
           GROUP_CONCAT(CONCAT(i.nombre,' (',v.talla,') x',l.cantidad) SEPARATOR ', ') AS resumen
    FROM equipacion_pedidos p
    JOIN users u ON u.id = p.user_id
    JOIN equipacion_pedido_lineas l ON l.pedido_id = p.id
    JOIN equipacion_variantes v ON v.id = l.variante_id
    JOIN equipacion_items i ON i.id = v.item_id
    $sqlW
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$pedidos = $stmt->fetchAll();

$counts = $pdo->query('SELECT estado, COUNT(*) AS n FROM equipacion_pedidos GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Equipación — Pedidos', 'directiva-equipacion');
render_directiva_layout('equipacion', function () use ($pedidos, $fEstado, $counts) {
?>
<div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
  <h1 style="margin:0;">Pedidos de equipación</h1>
  <a href="/directiva/equipacion" class="btn btn-secondary btn-sm"><i class="bi bi-box-seam"></i> Catálogo</a>
</div>

<?php render_flash(); ?>

<div class="card mb-4">
  <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="color:var(--gray);font-size:13px;margin-right:8px;">Filtrar:</span>
    <a href="?estado=todos" class="btn btn-sm <?= $fEstado === 'todos' ? 'btn-primary' : 'btn-gray' ?>">Todos</a>
    <a href="?estado=pendiente_pago" class="btn btn-sm <?= $fEstado === 'pendiente_pago' ? 'btn-primary' : 'btn-gray' ?>">
      Pendientes <?= isset($counts['pendiente_pago']) ? '(' . (int)$counts['pendiente_pago'] . ')' : '' ?>
    </a>
    <a href="?estado=pagado" class="btn btn-sm <?= $fEstado === 'pagado' ? 'btn-primary' : 'btn-gray' ?>">
      Pagados <?= isset($counts['pagado']) ? '(' . (int)$counts['pagado'] . ')' : '' ?>
    </a>
    <a href="?estado=entregado" class="btn btn-sm <?= $fEstado === 'entregado' ? 'btn-primary' : 'btn-gray' ?>">
      Entregados <?= isset($counts['entregado']) ? '(' . (int)$counts['entregado'] . ')' : '' ?>
    </a>
    <a href="?estado=cancelado" class="btn btn-sm <?= $fEstado === 'cancelado' ? 'btn-primary' : 'btn-gray' ?>">
      Cancelados <?= isset($counts['cancelado']) ? '(' . (int)$counts['cancelado'] . ')' : '' ?>
    </a>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (!$pedidos): ?>
      <p style="padding:24px;text-align:center;color:var(--gray);margin:0;">Sin pedidos.</p>
    <?php else: ?>
      <table class="table" style="margin:0;">
        <thead><tr><th>Fecha</th><th>Socio</th><th>Artículos</th><th>Total</th><th>Estado</th><th style="width:180px;text-align:right;">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($pedidos as $p): ?>
            <tr>
              <td><?= e(substr((string)$p['created_at'], 0, 16)) ?></td>
              <td><?= e($p['socio_nombre']) ?></td>
              <td><?= e($p['resumen']) ?></td>
              <td><?= number_format((float)$p['total'], 2, ',', '.') ?> €</td>
              <td><span class="badge <?= equipacion_badge_estado($p['estado']) ?>"><?= e(ucfirst(str_replace('_', ' ', $p['estado']))) ?></span></td>
              <td style="text-align:right;">
                <?php if ($p['estado'] === 'pagado'): ?>
                  <form method="POST" style="display:inline;" data-confirm="¿Marcar como entregado?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="entregar">
                    <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Entregar</button>
                  </form>
                <?php endif; ?>
                <?php if ($p['estado'] === 'pendiente_pago'): ?>
                  <form method="POST" style="display:inline;" data-confirm="¿Cancelar este pedido?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancelar">
                    <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Cancelar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php
});
render_footer();
