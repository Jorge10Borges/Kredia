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
    $requirements = $connection->query("SELECT d.producto_id, pr.nombre AS producto, SUM(d.cantidad) AS cantidad_solicitada, COALESCE(SUM(cobertura.cantidad_comprada), 0) AS cantidad_comprada, MIN(p.fecha_entrega) AS fecha_proxima, COUNT(DISTINCT p.id) AS pedidos_asociados FROM pedidos_detalle d INNER JOIN pedidos p ON p.id = d.pedido_id INNER JOIN productos pr ON pr.id = d.producto_id LEFT JOIN (SELECT cdp.pedido_detalle_id, SUM(cdp.cantidad_asignada) AS cantidad_comprada FROM compras_detalle_pedidos cdp INNER JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id INNER JOIN compras c ON c.id = cd.compra_id WHERE c.estado_pago <> 'anulada' GROUP BY cdp.pedido_detalle_id) cobertura ON cobertura.pedido_detalle_id = d.id WHERE p.eliminado_en IS NULL AND p.estado IN ('pendiente', 'confirmado', 'preparando') GROUP BY d.producto_id, pr.nombre ORDER BY MIN(p.fecha_entrega) IS NULL, MIN(p.fecha_entrega) ASC, pr.nombre ASC")->fetch_all(MYSQLI_ASSOC);
    foreach ($requirements as &$requirement) {
        $required = (float) $requirement['cantidad_solicitada'];
        $purchased = (float) $requirement['cantidad_comprada'];
        $requirement['faltante'] = max(0, $required - $purchased);
        $requirement['estado_cobertura'] = $purchased >= $required ? 'Cubierto' : ($purchased > 0 ? 'Parcial' : 'Por comprar');
    }

    $purchases = $connection->query("SELECT c.id, c.numero_documento, c.tipo_documento, c.fecha_emision, c.total, c.estado_pago, p.nombre AS proveedor FROM compras c INNER JOIN proveedores p ON p.id = c.proveedor_id WHERE c.estado_pago <> 'anulada' ORDER BY c.fecha_emision DESC, c.id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    $pendingOrders = $connection->query("SELECT COUNT(*) AS total FROM pedidos WHERE eliminado_en IS NULL AND estado IN ('pendiente', 'confirmado', 'preparando')")->fetch_assoc()['total'];
    $totalRequired = array_sum(array_map(fn(array $requirement) => (float) $requirement['cantidad_solicitada'], $requirements));
    $totalPurchased = array_sum(array_map(fn(array $requirement) => min((float) $requirement['cantidad_comprada'], (float) $requirement['cantidad_solicitada']), $requirements));
    $estimatedValue = $connection->query("SELECT COALESCE(SUM(c.total), 0) AS total FROM compras c WHERE c.estado_pago <> 'anulada'")->fetch_assoc()['total'];
    respond(['requirements' => $requirements, 'purchases' => $purchases, 'kpis' => ['pending_orders' => (int) $pendingOrders, 'products_to_buy' => count(array_filter($requirements, fn(array $requirement) => (float) $requirement['faltante'] > 0)), 'coverage' => $totalRequired > 0 ? round(($totalPurchased / $totalRequired) * 100, 1) : 100, 'purchases_value' => (float) $estimatedValue]]);
}

function respond(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
