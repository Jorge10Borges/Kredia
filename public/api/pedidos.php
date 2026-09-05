<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');
$connection = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: 'kredia', (int) (getenv('DB_PORT') ?: 3306));
$connection->set_charset('utf8mb4');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') listOrders($connection);
    if ($method === 'POST') createOrder($connection);
    if ($method === 'DELETE') deleteOrder($connection);
    respond(['message' => 'Método no permitido.'], 405);
} catch (mysqli_sql_exception $error) {
    if ($error->getCode() === 1062) respond(['message' => 'El número de pedido ya existe.'], 409);
    respond(['message' => 'No fue posible procesar el pedido.'], 500);
}

function listOrders(mysqli $connection): void {
    if (isset($_GET['id'])) {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) respond(['message' => 'Pedido no válido.'], 400);
        $statement = $connection->prepare("SELECT p.id, p.numero_pedido, p.cliente_id, c.nombre AS cliente, p.fecha_pedido, p.fecha_entrega, p.estado, p.notas, p.subtotal, p.impuesto, p.total FROM pedidos p INNER JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.eliminado_en IS NULL AND c.eliminado_en IS NULL");
        $statement->bind_param('i', $id); $statement->execute(); $result = $statement->get_result();
        if ($result->num_rows === 0) respond(['message' => 'Pedido no encontrado.'], 404);
        $order = $result->fetch_assoc();
        $details = $connection->prepare('SELECT d.producto_id, pr.nombre AS producto, d.cantidad, d.precio_unitario, d.descuento, d.subtotal FROM pedidos_detalle d INNER JOIN productos pr ON pr.id = d.producto_id WHERE d.pedido_id = ?');
        $details->bind_param('i', $id); $details->execute(); $order['detalles'] = $details->get_result()->fetch_all(MYSQLI_ASSOC);
        respond(['order' => $order]);
    }
    $orders = $connection->query("SELECT p.id, p.numero_pedido, c.nombre AS cliente, p.fecha_pedido, p.fecha_entrega, p.estado, p.total FROM pedidos p INNER JOIN clientes c ON c.id = p.cliente_id WHERE p.eliminado_en IS NULL AND c.eliminado_en IS NULL ORDER BY p.fecha_pedido DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);
    $customers = $connection->query("SELECT id, nombre FROM clientes WHERE eliminado_en IS NULL AND estado = 'activo' ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
    $products = $connection->query("SELECT id, nombre, precio_venta, lleva_iva FROM productos WHERE estado = 'activo' ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
    $kpis = $connection->query("SELECT COUNT(*) AS total_orders, COALESCE(SUM(estado IN ('pendiente','confirmado','preparando','enviado')), 0) AS open_orders, COALESCE(SUM(total), 0) AS total_value FROM pedidos WHERE eliminado_en IS NULL")->fetch_assoc();
    respond(['orders' => $orders, 'customers' => $customers, 'products' => $products, 'kpis' => $kpis]);
}

function createOrder(mysqli $connection): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['clienteId', 'estado', 'detalles'] as $field) if (!isset($data[$field]) || $data[$field] === '' || ($field === 'detalles' && count($data[$field]) === 0)) respond(['message' => "El campo $field es obligatorio."], 422);
    if (!is_array($data['detalles'])) respond(['message' => 'El detalle del pedido no es válido.'], 422);
    $customerId = (string) $data['clienteId']; $date = $data['fechaEntrega'] ?: null; $state = (string) $data['estado']; $notes = trim((string) ($data['notas'] ?? '')); $subtotal = 0.0;
    $connection->begin_transaction();
    try {
        $check = $connection->prepare("SELECT id FROM clientes WHERE id = ? AND eliminado_en IS NULL AND estado = 'activo'"); $check->bind_param('s', $customerId); $check->execute(); if ($check->get_result()->num_rows === 0) respond(['message' => 'El cliente no es válido.'], 422);
        $productStatement = $connection->prepare("SELECT lleva_iva FROM productos WHERE id = ? AND estado = 'activo'");
        $tax = 0.0;
        foreach ($data['detalles'] as $detail) { $quantity = (float) ($detail['cantidad'] ?? 0); $price = (float) ($detail['precioUnitario'] ?? -1); $discount = (float) ($detail['descuento'] ?? 0); $productId = (int) ($detail['productoId'] ?? 0); if ($quantity <= 0 || $price < 0 || $discount < 0) respond(['message' => 'Las cantidades, precios y descuentos deben ser válidos.'], 422); $productStatement->bind_param('i', $productId); $productStatement->execute(); $product = $productStatement->get_result()->fetch_assoc(); if (!$product) respond(['message' => 'El producto elegido no está disponible.'], 422); $lineSubtotal = ($quantity * $price) - $discount; $subtotal += $lineSubtotal; if ((int) $product['lleva_iva'] === 1) $tax += $lineSubtotal * 0.16; }
        if ($subtotal < 0) respond(['message' => 'El total del pedido no puede ser negativo.'], 422);
        $tax = round($tax, 2); $total = $subtotal + $tax;
        $temporaryNumber = 'TMP-' . bin2hex(random_bytes(8));
        $order = $connection->prepare('INSERT INTO pedidos (cliente_id, numero_pedido, fecha_entrega, estado, notas, subtotal, impuesto, total) VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?)'); $order->bind_param('sssssddd', $customerId, $temporaryNumber, $date, $state, $notes, $subtotal, $tax, $total); $order->execute(); $orderId = $connection->insert_id;
        $number = 'PED-' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
        $numberUpdate = $connection->prepare('UPDATE pedidos SET numero_pedido = ? WHERE id = ?'); $numberUpdate->bind_param('si', $number, $orderId); $numberUpdate->execute();
        $detailStatement = $connection->prepare('INSERT INTO pedidos_detalle (pedido_id, producto_id, cantidad, precio_unitario, descuento, subtotal) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($data['detalles'] as $detail) { $productId = (int) $detail['productoId']; $quantity = (float) $detail['cantidad']; $price = (float) $detail['precioUnitario']; $discount = (float) ($detail['descuento'] ?? 0); $lineTotal = ($quantity * $price) - $discount; $detailStatement->bind_param('iidddd', $orderId, $productId, $quantity, $price, $discount, $lineTotal); $detailStatement->execute(); }
        $connection->commit(); respond(['id' => $orderId], 201);
    } catch (Throwable $error) { $connection->rollback(); throw $error; }
}

function deleteOrder(mysqli $connection): void { $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$id) respond(['message' => 'Pedido no válido.'], 400); $statement = $connection->prepare('UPDATE pedidos SET eliminado_en = NOW() WHERE id = ? AND eliminado_en IS NULL'); $statement->bind_param('i', $id); $statement->execute(); if ($statement->affected_rows === 0) respond(['message' => 'Pedido no encontrado o ya eliminado.'], 404); respond([], 204); }
function respond(array $data, int $status = 200): void { http_response_code($status); if ($status !== 204) echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
