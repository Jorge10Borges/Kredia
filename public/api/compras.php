<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');
$connection = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: 'kredia', (int) (getenv('DB_PORT') ?: 3306));
$connection->set_charset('utf8mb4');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(['message' => 'Método no permitido.'], 405);
    listPurchasingData($connection);
} catch (mysqli_sql_exception $error) {
    respond(['message' => 'No fue posible cargar la información de compras.'], 500);
}

function listPurchasingData(mysqli $connection): void {
    $hasFechaParam = isset($_GET['fecha']);
    $dateFilter = filter_input(INPUT_GET, 'fecha', FILTER_DEFAULT);
    $dateFilter = is_string($dateFilter) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter) ? $dateFilter : null;

    $datesResult = $connection->query("SELECT DISTINCT fecha_entrega FROM pedidos WHERE eliminado_en IS NULL AND estado IN ('pendiente', 'confirmado', 'preparando') AND fecha_entrega IS NOT NULL ORDER BY fecha_entrega ASC")->fetch_all(MYSQLI_ASSOC);
    $availableDates = array_values(array_map(fn(array $row) => (string) $row['fecha_entrega'], $datesResult));

    if (!$hasFechaParam && count($availableDates) > 0) {
        $dateFilter = $availableDates[0];
    }

    $sqlRequirements = "SELECT d.producto_id, pr.nombre AS producto, SUM(d.cantidad) AS cantidad_solicitada, COALESCE(SUM(cobertura.cantidad_comprada), 0) AS cantidad_comprada, MIN(p.fecha_entrega) AS fecha_proxima, COUNT(DISTINCT p.id) AS pedidos_asociados FROM pedidos_detalle d INNER JOIN pedidos p ON p.id = d.pedido_id INNER JOIN productos pr ON pr.id = d.producto_id LEFT JOIN (SELECT cdp.pedido_detalle_id, SUM(cdp.cantidad_asignada) AS cantidad_comprada FROM compras_detalle_pedidos cdp INNER JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id INNER JOIN compras c ON c.id = cd.compra_id WHERE c.estado_pago <> 'anulada' GROUP BY cdp.pedido_detalle_id) cobertura ON cobertura.pedido_detalle_id = d.id WHERE p.eliminado_en IS NULL AND p.estado IN ('pendiente', 'confirmado', 'preparando')";
    if ($dateFilter) {
        $sqlRequirements .= " AND p.fecha_entrega = ?";
    }
    $sqlRequirements .= " GROUP BY d.producto_id, pr.nombre ORDER BY MIN(p.fecha_entrega) IS NULL, MIN(p.fecha_entrega) ASC, pr.nombre ASC";

    $stmtReq = $connection->prepare($sqlRequirements);
    if ($dateFilter) {
        $stmtReq->bind_param('s', $dateFilter);
    }
    $stmtReq->execute();
    $requirements = $stmtReq->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($requirements as &$requirement) {
        $required = (float) $requirement['cantidad_solicitada'];
        $purchased = (float) $requirement['cantidad_comprada'];
        $requirement['faltante'] = max(0, $required - $purchased);
        $requirement['estado_cobertura'] = $purchased >= $required ? 'Cubierto' : ($purchased > 0 ? 'Parcial' : 'Por comprar');
    }

    if ($dateFilter) {
        $stmtPurchases = $connection->prepare("SELECT DISTINCT c.id, c.numero_documento, c.tipo_documento, c.fecha_emision, c.total, c.estado_pago, p.nombre AS proveedor FROM compras c INNER JOIN proveedores p ON p.id = c.proveedor_id LEFT JOIN compras_detalle cd ON cd.compra_id = c.id LEFT JOIN compras_detalle_pedidos cdp ON cdp.compra_detalle_id = cd.id LEFT JOIN pedidos_detalle pd ON pd.id = cdp.pedido_detalle_id LEFT JOIN pedidos ped ON ped.id = pd.pedido_id WHERE c.estado_pago <> 'anulada' AND (c.fecha_emision = ? OR ped.fecha_entrega = ?) ORDER BY c.fecha_emision DESC, c.id DESC LIMIT 10");
        $stmtPurchases->bind_param('ss', $dateFilter, $dateFilter);
        $stmtPurchases->execute();
        $purchases = $stmtPurchases->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $purchases = $connection->query("SELECT c.id, c.numero_documento, c.tipo_documento, c.fecha_emision, c.total, c.estado_pago, p.nombre AS proveedor FROM compras c INNER JOIN proveedores p ON p.id = c.proveedor_id WHERE c.estado_pago <> 'anulada' ORDER BY c.fecha_emision DESC, c.id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    }

    $sqlPending = "SELECT COUNT(*) AS total FROM pedidos WHERE eliminado_en IS NULL AND estado IN ('pendiente', 'confirmado', 'preparando')";
    if ($dateFilter) {
        $sqlPending .= " AND fecha_entrega = ?";
    }
    $stmtPending = $connection->prepare($sqlPending);
    if ($dateFilter) {
        $stmtPending->bind_param('s', $dateFilter);
    }
    $stmtPending->execute();
    $pendingOrders = $stmtPending->get_result()->fetch_assoc()['total'];

    $totalRequired = array_sum(array_map(fn(array $requirement) => (float) $requirement['cantidad_solicitada'], $requirements));
    $totalPurchased = array_sum(array_map(fn(array $requirement) => min((float) $requirement['cantidad_comprada'], (float) $requirement['cantidad_solicitada']), $requirements));
    $estimatedValue = array_sum(array_map(fn(array $p) => (float) $p['total'], $purchases));

    respond([
        'requirements' => $requirements,
        'purchases' => $purchases,
        'available_dates' => $availableDates,
        'selected_date' => $dateFilter,
        'kpis' => [
            'pending_orders' => (int) $pendingOrders,
            'products_to_buy' => count(array_filter($requirements, fn(array $requirement) => (float) $requirement['faltante'] > 0)),
            'coverage' => $totalRequired > 0 ? round(($totalPurchased / $totalRequired) * 100, 1) : 100,
            'purchases_value' => (float) $estimatedValue
        ]
    ]);
}

function respond(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
