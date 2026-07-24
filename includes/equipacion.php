<?php
// Helpers del módulo de equipación: carrito en sesión + stock atómico.

function carrito_equipacion(): array
{
    return $_SESSION['carrito_equipacion'] ?? [];
}

function carrito_equipacion_add(int $variante_id, int $cantidad): void
{
    if (!isset($_SESSION['carrito_equipacion'])) $_SESSION['carrito_equipacion'] = [];
    $actual = $_SESSION['carrito_equipacion'][$variante_id] ?? 0;
    carrito_equipacion_set($variante_id, $actual + $cantidad);
}

function carrito_equipacion_set(int $variante_id, int $cantidad): void
{
    if ($cantidad <= 0) {
        unset($_SESSION['carrito_equipacion'][$variante_id]);
    } else {
        $_SESSION['carrito_equipacion'][$variante_id] = $cantidad;
    }
}

function carrito_equipacion_clear(): void
{
    unset($_SESSION['carrito_equipacion']);
}

function equipacion_carrito_detalle(PDO $pdo, array $carrito): array
{
    if (!$carrito) return [];
    $ids = array_map('intval', array_keys($carrito));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT v.id AS variante_id, v.talla, v.stock, i.nombre, i.precio
         FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id
         WHERE v.id IN ($in)"
    );
    $stmt->execute($ids);

    $detalle = [];
    foreach ($stmt->fetchAll() as $row) {
        $vid = (int)$row['variante_id'];
        $cantidad = $carrito[$vid] ?? 0;
        if ($cantidad <= 0) continue;
        $detalle[] = [
            'variante_id' => $vid,
            'nombre'      => $row['nombre'],
            'talla'       => $row['talla'],
            'precio'      => (float)$row['precio'],
            'cantidad'    => $cantidad,
            'subtotal'    => (float)$row['precio'] * $cantidad,
            'stock'       => (int)$row['stock'],
        ];
    }
    return $detalle;
}

// Reserva stock atómicamente. Devuelve 0 si todo OK, o el variante_id que
// falló por falta de stock (el caller debe hacer ROLLBACK de la transacción).
function equipacion_reservar_stock(PDO $pdo, array $carrito): int
{
    foreach ($carrito as $variante_id => $cantidad) {
        $stmt = $pdo->prepare(
            'UPDATE equipacion_variantes SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([$cantidad, $variante_id, $cantidad]);
        if ($stmt->rowCount() === 0) return (int)$variante_id;
    }
    return 0;
}

function equipacion_reponer_stock(PDO $pdo, int $pedido_id): void
{
    $stmt = $pdo->prepare('SELECT variante_id, cantidad FROM equipacion_pedido_lineas WHERE pedido_id = ?');
    $stmt->execute([$pedido_id]);
    foreach ($stmt->fetchAll() as $linea) {
        $pdo->prepare('UPDATE equipacion_variantes SET stock = stock + ? WHERE id = ?')
            ->execute([$linea['cantidad'], $linea['variante_id']]);
    }
}

function equipacion_variante_label(PDO $pdo, int $variante_id): string
{
    $stmt = $pdo->prepare(
        'SELECT i.nombre, v.talla FROM equipacion_variantes v JOIN equipacion_items i ON i.id = v.item_id WHERE v.id = ?'
    );
    $stmt->execute([$variante_id]);
    $r = $stmt->fetch();
    return $r ? ($r['nombre'] . ' (talla ' . $r['talla'] . ')') : 'artículo';
}

function equipacion_badge_estado(string $estado): string
{
    return match ($estado) {
        'pagado'    => 'badge-blue',
        'entregado' => 'badge-success',
        'cancelado' => 'badge-gray',
        default     => 'badge-danger', // pendiente_pago
    };
}
