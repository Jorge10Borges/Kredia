<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$connection = new mysqli(
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_NAME') ?: 'kredia',
    (int) (getenv('DB_PORT') ?: 3306)
);
$connection->set_charset('utf8mb4');

if ($connection->connect_error) {
    respond(['message' => 'No fue posible conectar con la base de datos.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') listCustomers($connection);
    if ($method === 'POST') createCustomer($connection);
    if ($method === 'PUT') updateCustomer($connection);
    if ($method === 'DELETE') deleteCustomer($connection);
    respond(['message' => 'Método no permitido.'], 405);
} catch (Throwable $error) {
    respond(['message' => 'No fue posible procesar la solicitud.'], 500);
}

function listCustomers(mysqli $connection): void {
    if (isset($_GET['id'])) {
        $id = $_GET['id'];
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) respond(['message' => 'Cliente no válido.'], 400);
        $statement = $connection->prepare("SELECT c.id, c.tipo_persona, c.nombre, c.razon_social, c.nombre_comercial, c.tipo_documento, c.numero_documento, c.estado, c.telefono_principal, c.telefono_alternativo, c.email, u.direccion_fiscal, u.ciudad, u.estado_region, u.pais, u.codigo_postal, u.persona_contacto, u.telefono_contacto, u.email_contacto, cc.limite_credito, cc.dias_credito, cc.estado_credito, cc.notas_cobranza FROM clientes c LEFT JOIN clientes_ubicacion u ON u.cliente_id = c.id AND u.eliminado_en IS NULL LEFT JOIN clientes_creditos cc ON cc.cliente_id = c.id AND cc.eliminado_en IS NULL WHERE c.id = ? AND c.eliminado_en IS NULL LIMIT 1");
        $statement->bind_param('s', $id); $statement->execute(); $result = $statement->get_result();
        if ($result->num_rows === 0) respond(['message' => 'Cliente no encontrado.'], 404);
        respond(['customer' => $result->fetch_assoc()]);
    }
    $customers = $connection->query("SELECT c.id, c.nombre, c.telefono_principal, c.estado, COALESCE(cc.limite_credito, 0) AS limite_credito, COALESCE(cc.saldo_pendiente, 0) AS saldo_pendiente, COALESCE(cc.dias_mora, 0) AS dias_mora, COALESCE(cc.estado_credito, 'activo') AS estado_credito FROM clientes c LEFT JOIN clientes_creditos cc ON cc.cliente_id = c.id AND cc.eliminado_en IS NULL WHERE c.eliminado_en IS NULL ORDER BY c.nombre ASC");
    $rows = $customers->fetch_all(MYSQLI_ASSOC);
    $kpis = $connection->query("SELECT (SELECT COUNT(*) FROM clientes WHERE eliminado_en IS NULL) AS total_customers, (SELECT COUNT(*) FROM clientes WHERE estado = 'activo' AND eliminado_en IS NULL) AS active_customers, (SELECT COUNT(*) FROM clientes_creditos cc INNER JOIN clientes c ON c.id = cc.cliente_id WHERE cc.estado_credito = 'activo' AND cc.eliminado_en IS NULL AND c.eliminado_en IS NULL) AS active_credits, (SELECT MAX(u.actualizado_en) FROM clientes_ubicacion u INNER JOIN clientes c ON c.id = u.cliente_id WHERE u.eliminado_en IS NULL AND c.eliminado_en IS NULL) AS last_updated")->fetch_assoc();
    respond(['customers' => $rows, 'kpis' => $kpis]);
}

function updateCustomer(mysqli $connection): void {
    $id = $_GET['id'] ?? '';
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $required = ['tipoPersona', 'nombre', 'tipoDocumento', 'numeroDocumento', 'estado', 'telefonoPrincipal', 'direccionFiscal', 'pais', 'limiteCredito', 'diasCredito', 'estadoCredito'];
    if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) respond(['message' => 'Cliente no válido.'], 400);
    foreach ($required as $field) if (!isset($data[$field]) || $data[$field] === '') respond(['message' => "El campo $field es obligatorio."], 422);
    $connection->begin_transaction();
    try {
        $customer = $connection->prepare("UPDATE clientes SET tipo_persona = ?, nombre = ?, nombre_comercial = NULLIF(?, ''), tipo_documento = ?, numero_documento = ?, estado = ?, telefono_principal = ?, telefono_alternativo = NULLIF(?, ''), email = NULLIF(?, '') WHERE id = ? AND eliminado_en IS NULL");
        $customer->bind_param('ssssssssss', $data['tipoPersona'], $data['nombre'], $data['nombreComercial'], $data['tipoDocumento'], $data['numeroDocumento'], $data['estado'], $data['telefonoPrincipal'], $data['telefonoAlternativo'], $data['email'], $id); $customer->execute();
        if ($customer->affected_rows === 0) { $connection->rollback(); respond(['message' => 'Cliente no encontrado o sin cambios.'], 404); }
        $location = $connection->prepare("UPDATE clientes_ubicacion SET direccion_fiscal = ?, ciudad = NULLIF(?, ''), estado_region = NULLIF(?, ''), pais = ?, codigo_postal = NULLIF(?, ''), persona_contacto = NULLIF(?, ''), telefono_contacto = NULLIF(?, ''), email_contacto = NULLIF(?, '') WHERE cliente_id = ? AND eliminado_en IS NULL");
        $location->bind_param('sssssssss', $data['direccionFiscal'], $data['ciudad'], $data['estadoRegion'], $data['pais'], $data['codigoPostal'], $data['personaContacto'], $data['telefonoContacto'], $data['emailContacto'], $id); $location->execute();
        $credit = $connection->prepare("UPDATE clientes_creditos SET limite_credito = ?, dias_credito = ?, estado_credito = ?, notas_cobranza = NULLIF(?, '') WHERE cliente_id = ? AND eliminado_en IS NULL");
        $credit->bind_param('disss', $data['limiteCredito'], $data['diasCredito'], $data['estadoCredito'], $data['notasCobranza'], $id); $credit->execute();
        $connection->commit(); respond(['id' => $id]);
    } catch (Throwable $error) { $connection->rollback(); throw $error; }
}

function createCustomer(mysqli $connection): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $required = ['tipoPersona', 'nombre', 'tipoDocumento', 'numeroDocumento', 'estado', 'telefonoPrincipal', 'direccionFiscal', 'pais', 'limiteCredito', 'diasCredito', 'estadoCredito'];
    foreach ($required as $field) if (!isset($data[$field]) || $data[$field] === '') respond(['message' => "El campo $field es obligatorio."], 422);

    $id = bin2hex(random_bytes(16));
    $id = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20);
    $connection->begin_transaction();
    try {
        $customer = $connection->prepare('INSERT INTO clientes (id, tipo_persona, nombre, nombre_comercial, tipo_documento, numero_documento, estado, telefono_principal, telefono_alternativo, email) VALUES (?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'))');
        $customer->bind_param('ssssssssss', $id, $data['tipoPersona'], $data['nombre'], $data['nombreComercial'], $data['tipoDocumento'], $data['numeroDocumento'], $data['estado'], $data['telefonoPrincipal'], $data['telefonoAlternativo'], $data['email']);
        $customer->execute();
        $location = $connection->prepare('INSERT INTO clientes_ubicacion (cliente_id, direccion_fiscal, ciudad, estado_region, pais, codigo_postal, persona_contacto, telefono_contacto, email_contacto) VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))');
        $location->bind_param('sssssssss', $id, $data['direccionFiscal'], $data['ciudad'], $data['estadoRegion'], $data['pais'], $data['codigoPostal'], $data['personaContacto'], $data['telefonoContacto'], $data['emailContacto']);
        $location->execute();
        $credit = $connection->prepare('INSERT INTO clientes_creditos (cliente_id, limite_credito, dias_credito, estado_credito, notas_cobranza) VALUES (?, ?, ?, ?, NULLIF(?, \'\'))');
        $credit->bind_param('sdiss', $id, $data['limiteCredito'], $data['diasCredito'], $data['estadoCredito'], $data['notasCobranza']);
        $credit->execute();
        $connection->commit();
        respond(['id' => $id], 201);
    } catch (Throwable $error) {
        $connection->rollback();
        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) respond(['message' => 'Ya existe un cliente con ese documento.'], 409);
        throw $error;
    }
}

function deleteCustomer(mysqli $connection): void {
    $id = $_GET['id'] ?? '';
    if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) respond(['message' => 'Cliente no válido.'], 400);
    $connection->begin_transaction();
    try {
        $customer = $connection->prepare('UPDATE clientes SET eliminado_en = NOW() WHERE id = ? AND eliminado_en IS NULL');
        $customer->bind_param('s', $id);
        $customer->execute();
        if ($customer->affected_rows === 0) { $connection->rollback(); respond(['message' => 'Cliente no encontrado.'], 404); }
        foreach (['clientes_ubicacion', 'clientes_creditos'] as $table) { $statement = $connection->prepare("UPDATE $table SET eliminado_en = NOW() WHERE cliente_id = ? AND eliminado_en IS NULL"); $statement->bind_param('s', $id); $statement->execute(); }
        $connection->commit();
        respond([], 204);
    } catch (Throwable $error) { $connection->rollback(); throw $error; }
}

function respond(array $data, int $status = 200): void { http_response_code($status); if ($status !== 204) echo json_encode($data); exit; }