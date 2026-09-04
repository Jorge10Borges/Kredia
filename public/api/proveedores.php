<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');

$connection = new mysqli(
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_NAME') ?: 'kredia',
    (int) (getenv('DB_PORT') ?: 3306)
);
$connection->set_charset('utf8mb4');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') listSuppliers($connection);
    if ($method === 'POST') createSupplier($connection);
    if ($method === 'DELETE') deleteSupplier($connection);
    respond(['message' => 'Método no permitido.'], 405);
} catch (mysqli_sql_exception $error) {
    if ($error->getCode() === 1062) respond(['message' => 'Ya existe un proveedor con esa identificación fiscal.'], 409);
    respond(['message' => 'No fue posible procesar la solicitud.'], 500);
}

function listSuppliers(mysqli $connection): void {
    $result = $connection->query("SELECT id, nombre, identificacion_fiscal, telefono, email, direccion, dias_plazo, estado, created_at, updated_at FROM proveedores WHERE eliminado_en IS NULL ORDER BY nombre ASC");
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $kpis = $connection->query("SELECT COUNT(*) AS total_suppliers, SUM(estado = 'activo') AS active_suppliers, MAX(updated_at) AS last_updated FROM proveedores WHERE eliminado_en IS NULL")->fetch_assoc();
    respond(['suppliers' => $rows, 'kpis' => $kpis]);
}

function createSupplier(mysqli $connection): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['nombre', 'identificacionFiscal', 'diasPlazo', 'estado'] as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') respond(['message' => "El campo $field es obligatorio."], 422);
    }
    if (!is_numeric($data['diasPlazo']) || (int) $data['diasPlazo'] < 0 || (int) $data['diasPlazo'] > 365) respond(['message' => 'Los días de plazo deben estar entre 0 y 365.'], 422);

    $nombre = trim((string) $data['nombre']);
    $identificacionFiscal = trim((string) $data['identificacionFiscal']);
    $telefono = trim((string) ($data['telefono'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $direccion = trim((string) ($data['direccion'] ?? ''));
    $diasPlazo = (int) $data['diasPlazo'];
    $estado = (string) $data['estado'];

    $statement = $connection->prepare("INSERT INTO proveedores (nombre, identificacion_fiscal, telefono, email, direccion, dias_plazo, estado) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?)");
    $statement->bind_param('sssssds', $nombre, $identificacionFiscal, $telefono, $email, $direccion, $diasPlazo, $estado);
    $statement->execute();
    respond(['id' => $connection->insert_id], 201);
}

function deleteSupplier(mysqli $connection): void {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) respond(['message' => 'Proveedor no válido.'], 400);
    $statement = $connection->prepare('UPDATE proveedores SET eliminado_en = NOW() WHERE id = ? AND eliminado_en IS NULL');
    $statement->bind_param('i', $id);
    $statement->execute();
    if ($statement->affected_rows === 0) respond(['message' => 'Proveedor no encontrado o ya eliminado.'], 404);
    respond([], 204);
}

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    if ($status !== 204) echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
