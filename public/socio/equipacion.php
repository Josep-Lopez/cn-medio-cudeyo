<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/equipacion.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $variante_id = (int)($_POST['variante_id'] ?? 0);
        $cantidad    = max(1, min(20, (int)($_POST['cantidad'] ?? 1)));
        $chk = $pdo->prepare(
            'SELECT v.stock, i.bajo_pedido FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
             WHERE v.id = ? AND i.activo = 1'
        );
        $chk->execute([$variante_id]);
        $row = $chk->fetch();
        if ($row === false) {
            flash('Artículo no disponible.', 'danger');
        } elseif ((int)$row['bajo_pedido'] === 0 && (int)$row['stock'] < $cantidad) {
            flash('No queda stock suficiente de ese artículo.', 'danger');
        } else {
            carrito_equipacion_add($variante_id, $cantidad);
            flash('Añadido al carrito.', 'success');
        }
    } elseif ($action === 'quitar') {
        carrito_equipacion_set((int)($_POST['variante_id'] ?? 0), 0);
        flash('Artículo quitado del carrito.', 'warning');
    } elseif ($action === 'vaciar') {
        carrito_equipacion_clear();
        flash('Carrito vaciado.', 'warning');
    }

    header('Location: /socio/equipacion');
    exit;
}

$catalogoRows = $pdo->query(
    "SELECT i.id AS item_id, i.nombre, i.descripcion, i.precio, i.imagen_url, i.bajo_pedido,
            v.id AS variante_id, v.talla, v.stock
     FROM equipacion_items i
     JOIN equipacion_variantes v ON v.item_id = i.id
     WHERE i.activo = 1
     ORDER BY i.nombre, v.talla"
)->fetchAll();

$catalogo = [];
foreach ($catalogoRows as $r) {
    $iid = (int)$r['item_id'];
    if (!isset($catalogo[$iid])) {
        $catalogo[$iid] = [
            'nombre'      => $r['nombre'],
            'descripcion' => $r['descripcion'],
            'precio'      => (float)$r['precio'],
            'imagen_url'  => $r['imagen_url'],
            'bajo_pedido' => (int)$r['bajo_pedido'],
            'variantes'   => [],
        ];
    }
    $catalogo[$iid]['variantes'][] = [
        'variante_id' => (int)$r['variante_id'],
        'talla'       => $r['talla'],
        'stock'       => (int)$r['stock'],
    ];
}

$carritoDetalle = equipacion_carrito_detalle($pdo, carrito_equipacion());
$carritoTotal    = array_sum(array_column($carritoDetalle, 'subtotal'));

render_header('Equipación', 'socio-equipacion');
?>
<div class="container page-content">
  <h1>Equipación del club</h1>
  <?php render_flash(); ?>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start;">
    <div>
      <?php if (!$catalogo): ?>
        <p class="text-muted">No hay artículos disponibles ahora mismo.</p>
      <?php endif; ?>
      <?php foreach ($catalogo as $item): ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="d-flex justify-between align-center" style="gap:12px;flex-wrap:wrap;">
              <div class="d-flex gap-3" style="align-items:flex-start;">
                <?php if ($item['imagen_url']): ?>
                  <img src="<?= e($item['imagen_url']) ?>" alt="<?= e($item['nombre']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                <?php else: ?>
                  <div style="width:80px;height:80px;border-radius:8px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-image" style="font-size:28px;color:var(--gray);"></i>
                  </div>
                <?php endif; ?>
                <div>
                  <h3 style="margin:0;"><?= e($item['nombre']) ?></h3>
                  <?php if ($item['descripcion']): ?>
                    <p style="color:var(--gray);margin:4px 0;"><?= e($item['descripcion']) ?></p>
                  <?php endif; ?>
                </div>
              </div>
              <div style="font-size:20px;font-weight:700;"><?= number_format($item['precio'], 2, ',', '.') ?> €</div>
            </div>
            <form method="POST" class="d-flex gap-2" style="margin-top:12px;flex-wrap:wrap;align-items:flex-end;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add">
              <div class="form-group" style="margin:0;">
                <label class="form-label">Talla</label>
                <select name="variante_id" class="form-control" required>
                  <?php foreach ($item['variantes'] as $v): ?>
                    <?php $sinStock = !$item['bajo_pedido'] && $v['stock'] <= 0; ?>
                    <option value="<?= $v['variante_id'] ?>" <?= $sinStock ? 'disabled' : '' ?>>
                      <?= e($v['talla']) ?><?= $sinStock ? ' (sin stock)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin:0;width:90px;">
                <label class="form-label">Cant.</label>
                <input type="number" name="cantidad" class="form-control" value="1" min="1" max="20">
              </div>
              <button type="submit" class="btn btn-primary"><i class="bi bi-cart-plus"></i> Añadir</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3 style="margin:0;font-size:16px;"><i class="bi bi-cart"></i> Tu carrito</h3></div>
      <div class="card-body">
        <?php if (!$carritoDetalle): ?>
          <p class="text-muted" style="margin:0;">Carrito vacío.</p>
        <?php else: ?>
          <?php foreach ($carritoDetalle as $l): ?>
            <div class="d-flex justify-between align-center" style="padding:8px 0;border-bottom:1px solid #eee;gap:8px;">
              <div>
                <strong><?= e($l['nombre']) ?></strong> — talla <?= e($l['talla']) ?><br>
                <span style="color:var(--gray);font-size:13px;"><?= $l['cantidad'] ?> × <?= number_format($l['precio'], 2, ',', '.') ?> €</span>
              </div>
              <div class="d-flex align-center gap-2">
                <strong><?= number_format($l['subtotal'], 2, ',', '.') ?> €</strong>
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="quitar">
                  <input type="hidden" name="variante_id" value="<?= $l['variante_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-gray" title="Quitar"><i class="bi bi-x"></i></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="d-flex justify-between" style="margin-top:12px;font-size:18px;font-weight:700;">
            <span>Total</span><span><?= number_format($carritoTotal, 2, ',', '.') ?> €</span>
          </div>
          <form method="POST" action="/stripe_checkout" style="margin-top:16px;" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-credit-card"></i> Pagar con Stripe</button>
          </form>
          <form method="POST" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="vaciar">
            <button type="submit" class="btn btn-gray btn-sm w-100">Vaciar carrito</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <p style="margin-top:16px;"><a href="/socio/equipacion_pedidos"><i class="bi bi-receipt"></i> Ver mis pedidos</a></p>
</div>
<?php render_footer(); ?>
