<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/equipacion.php';

require_login();
$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancelar') {
        $pedido_id = (int)($_POST['pedido_id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$pedido_id, $uid]);
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
    header('Location: /socio/equipacion_pedidos');
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.*, GROUP_CONCAT(CONCAT(i.nombre,' (',v.talla,') x',l.cantidad) SEPARATOR ', ') AS resumen
    FROM equipacion_pedidos p
    JOIN equipacion_pedido_lineas l ON l.pedido_id = p.id
    JOIN equipacion_variantes v ON v.id = l.variante_id
    JOIN equipacion_items i ON i.id = v.item_id
    WHERE p.user_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute([$uid]);
$pedidos = $stmt->fetchAll();

render_header('Mis pedidos de equipación', 'socio-equipacion');
?>
<div class="container page-content">
  <div class="d-flex justify-between align-center mb-4" style="gap:12px;flex-wrap:wrap;">
    <h1 style="margin:0;">Mis pedidos de equipación</h1>
    <a href="/socio/equipacion" class="btn btn-primary btn-sm"><i class="bi bi-shop"></i> Ir a la tienda</a>
  </div>
  <?php render_flash(); ?>

  <?php if (isset($_GET['pago']) && $_GET['pago'] === 'ok'): ?>
    <div class="alert alert-success">Pago recibido. En unos segundos verás el pedido como pagado.</div>
  <?php elseif (isset($_GET['pago']) && $_GET['pago'] === 'cancelado'): ?>
    <div class="alert alert-warning">Pago cancelado. Puedes reintentarlo desde tus pedidos pendientes.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body" style="padding:0;overflow-x:auto;">
      <?php if (!$pedidos): ?>
        <p style="padding:24px;text-align:center;color:var(--gray);margin:0;">Todavía no has hecho ningún pedido.</p>
      <?php else: ?>
        <table class="table" style="margin:0;">
          <thead>
            <tr><th>Fecha</th><th>Artículos</th><th>Total</th><th>Estado</th><th style="width:100px;"></th></tr>
          </thead>
          <tbody>
            <?php foreach ($pedidos as $p): ?>
              <tr>
                <td><?= e(substr((string)$p['created_at'], 0, 16)) ?></td>
                <td><?= e($p['resumen']) ?></td>
                <td><?= number_format((float)$p['total'], 2, ',', '.') ?> €</td>
                <td><span class="badge <?= equipacion_badge_estado($p['estado']) ?>"><?= e(ucfirst(str_replace('_', ' ', $p['estado']))) ?></span></td>
                <td style="text-align:right;">
                  <?php if ($p['estado'] === 'pendiente_pago'): ?>
                    <form method="POST" data-confirm="¿Cancelar este pedido?">
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
</div>
<?php render_footer(); ?>
