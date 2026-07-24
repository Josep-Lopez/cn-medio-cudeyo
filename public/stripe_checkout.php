<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/equipacion.php';
require_once dirname(__DIR__) . '/config/stripe.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /socio/equipacion');
    exit;
}
csrf_verify();

$carrito = carrito_equipacion();
if (!$carrito) {
    flash('Tu carrito está vacío.', 'danger');
    header('Location: /socio/equipacion');
    exit;
}

$uid = (int)current_user()['id'];

$ids = array_map('intval', array_keys($carrito));
$in  = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT v.id AS variante_id, v.talla, i.nombre, i.precio
     FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
     WHERE v.id IN ($in) AND i.activo = 1"
);
$stmt->execute($ids);
$variantes = [];
foreach ($stmt->fetchAll() as $v) $variantes[(int)$v['variante_id']] = $v;

if (count($variantes) !== count($ids)) {
    flash('Algún artículo del carrito ya no está disponible.', 'danger');
    carrito_equipacion_clear();
    header('Location: /socio/equipacion');
    exit;
}

$pdo->beginTransaction();
try {
    $fallo = equipacion_reservar_stock($pdo, $carrito);
    if ($fallo !== 0) {
        $pdo->rollBack();
        flash('Sin stock suficiente de ' . equipacion_variante_label($pdo, $fallo) . '.', 'danger');
        header('Location: /socio/equipacion');
        exit;
    }

    $total = 0.0;
    foreach ($carrito as $variante_id => $cantidad) {
        $total += $variantes[$variante_id]['precio'] * $cantidad;
    }

    $pdo->prepare('INSERT INTO equipacion_pedidos (user_id, estado, total) VALUES (?,?,?)')
        ->execute([$uid, 'pendiente_pago', $total]);
    $pedido_id = (int)$pdo->lastInsertId();

    $insLinea = $pdo->prepare(
        'INSERT INTO equipacion_pedido_lineas (pedido_id, variante_id, cantidad, precio_unitario) VALUES (?,?,?,?)'
    );
    $lineItems = [];
    foreach ($carrito as $variante_id => $cantidad) {
        $v = $variantes[$variante_id];
        $insLinea->execute([$pedido_id, $variante_id, $cantidad, $v['precio']]);
        $lineItems[] = [
            'price_data' => [
                'currency'     => 'eur',
                'unit_amount'  => (int)round($v['precio'] * 100),
                'product_data' => ['name' => $v['nombre'] . ' — talla ' . $v['talla']],
            ],
            'quantity' => $cantidad,
        ];
    }

    $session = stripe_client()->checkout->sessions->create([
        'mode'                => 'payment',
        'line_items'          => $lineItems,
        'client_reference_id' => (string)$pedido_id,
        'success_url'         => stripe_public_url() . '/socio/equipacion_pedidos?pago=ok',
        'cancel_url'          => stripe_public_url() . '/socio/equipacion_pedidos?pago=cancelado',
    ]);

    $pdo->prepare('UPDATE equipacion_pedidos SET stripe_session_id = ? WHERE id = ?')
        ->execute([$session->id, $pedido_id]);

    $pdo->commit();
    carrito_equipacion_clear();
    header('Location: ' . $session->url);
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Stripe checkout error: ' . $e->getMessage());
    flash('No se ha podido iniciar el pago. Inténtalo de nuevo.', 'danger');
    header('Location: /socio/equipacion');
    exit;
}
