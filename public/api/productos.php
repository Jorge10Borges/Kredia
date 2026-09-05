<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');
$connection = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: 'kredia', (int) (getenv('DB_PORT') ?: 3306));
$connection->set_charset('utf8mb4');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') listProducts($connection);
    if ($method === 'POST') createProduct($connection);
    if ($method === 'PUT') updateProduct($connection);
    if ($method === 'DELETE') deleteProduct($connection);
    respond(['message' => 'Método no permitido.'], 405);
} catch (mysqli_sql_exception $error) {
    respond(['message' => 'No fue posible procesar el producto.'], 500);
}

function listProducts(mysqli $connection): void {
    if (isset($_GET['id'])) {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) respond(['message' => 'Producto no válido.'], 400);
        $statement = $connection->prepare('SELECT id, nombre, precio_compra, precio_venta, lleva_iva, estado FROM productos WHERE id = ? AND eliminado_en IS NULL');
        $statement->bind_param('i', $id); $statement->execute(); $result = $statement->get_result();
        if ($result->num_rows === 0) respond(['message' => 'Producto no encontrado.'], 404);
        respond(['product' => $result->fetch_assoc()]);
    }
    $products = $connection->query('SELECT id, nombre, precio_compra, precio_venta, lleva_iva, estado, updated_at FROM productos WHERE eliminado_en IS NULL ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);
    $kpis = $connection->query("SELECT COUNT(*) AS total_products, COALESCE(SUM(estado = 'activo'), 0) AS active_products, COALESCE(SUM(precio_venta - precio_compra), 0) AS potential_margin FROM productos WHERE eliminado_en IS NULL")->fetch_assoc();
    respond(['products' => $products, 'kpis' => $kpis]);
}

function validateProduct(array $data): array {
    foreach (['nombre', 'precioCompra', 'precioVenta', 'estado'] as $field) if (!isset($data[$field]) || trim((string) $data[$field]) === '') respond(['message' => "El campo $field es obligatorio."], 422);
    $purchasePrice = (float) $data['precioCompra']; $salePrice = (float) $data['precioVenta'];
    if ($purchasePrice < 0 || $salePrice < 0) respond(['message' => 'Los precios no pueden ser negativos.'], 422);
    if (!in_array($data['estado'], ['activo', 'inactivo'], true)) respond(['message' => 'El estado no es válido.'], 422);
    return [trim((string) $data['nombre']), $purchasePrice, $salePrice, isset($data['llevaIva']) ? (int) (bool) $data['llevaIva'] : 1, (string) $data['estado']];
}

function createProduct(mysqli $connection): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    [$name, $purchasePrice, $salePrice, $includesVat, $state] = validateProduct($data);
    $statement = $connection->prepare('INSERT INTO productos (nombre, precio_compra, precio_venta, lleva_iva, estado) VALUES (?, ?, ?, ?, ?)');
    $statement->bind_param('sddis', $name, $purchasePrice, $salePrice, $includesVat, $state); $statement->execute();
    respond(['id' => $connection->insert_id], 201);
}

function updateProduct(mysqli $connection): void {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) respond(['message' => 'Producto no válido.'], 400);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    [$name, $purchasePrice, $salePrice, $includesVat, $state] = validateProduct($data);
    $statement = $connection->prepare('UPDATE productos SET nombre = ?, precio_compra = ?, precio_venta = ?, lleva_iva = ?, estado = ? WHERE id = ? AND eliminado_en IS NULL');
    $statement->bind_param('sddisi', $name, $purchasePrice, $salePrice, $includesVat, $state, $id); $statement->execute();
    if ($statement->affected_rows === 0) respond(['message' => 'Producto no encontrado o sin cambios.'], 404);
    respond(['id' => $id]);
}

function deleteProduct(mysqli $connection): void {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) respond(['message' => 'Producto no válido.'], 400);
    $statement = $connection->prepare('UPDATE productos SET eliminado_en = NOW() WHERE id = ? AND eliminado_en IS NULL');
    $statement->bind_param('i', $id); $statement->execute();
    if ($statement->affected_rows === 0) respond(['message' => 'Producto no encontrado o ya eliminado.'], 404);
    respond([], 204);
}

function respond(array $data, int $status = 200): void { http_response_code($status); if ($status !== 204) echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
