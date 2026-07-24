<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/stripe.php';
require_once dirname(__DIR__) . '/includes/equipacion.php';

$payload       = @file_get_contents('php://input');
$sigHeader     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET') ?: '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
} catch (\Throwable $e) {
    http_response_code(400);
    error_log('Stripe webhook: firma inválida — ' . $e->getMessage());
    exit;
}

$type = $event->type;

if ($type === 'checkout.session.completed') {
    $session = $event->data->object;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE stripe_session_id = ? FOR UPDATE');
    $stmt->execute([$session->id]);
    $pedido = $stmt->fetch();
    if ($pedido && $pedido['estado'] === 'pendiente_pago') {
        $pdo->prepare('UPDATE equipacion_pedidos SET estado = ?, stripe_payment_intent = ? WHERE id = ?')
            ->execute(['pagado', $session->payment_intent, $pedido['id']]);
    } elseif ($pedido && $pedido['estado'] !== 'pagado') {
        error_log('Stripe webhook: checkout.session.completed recibido pero pedido no estaba pendiente_pago — '
            . 'session=' . $session->id . ' pedido_id=' . $pedido['id'] . ' estado=' . $pedido['estado']
            . ' payment_intent=' . $session->payment_intent);
    }
    $pdo->commit();
} elseif ($type === 'checkout.session.expired') {
    $session = $event->data->object;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, estado FROM equipacion_pedidos WHERE stripe_session_id = ? FOR UPDATE');
    $stmt->execute([$session->id]);
    $pedido = $stmt->fetch();
    if ($pedido && $pedido['estado'] === 'pendiente_pago') {
        equipacion_reponer_stock($pdo, (int)$pedido['id']);
        $pdo->prepare('UPDATE equipacion_pedidos SET estado = ? WHERE id = ?')
            ->execute(['cancelado', $pedido['id']]);
    } elseif ($pedido && $pedido['estado'] !== 'cancelado') {
        error_log('Stripe webhook: checkout.session.expired recibido pero pedido no estaba pendiente_pago — '
            . 'session=' . $session->id . ' pedido_id=' . $pedido['id'] . ' estado=' . $pedido['estado']);
    }
    $pdo->commit();
}

http_response_code(200);
echo json_encode(['received' => true]);
