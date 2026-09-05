<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');
$connection = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: 'kredia', (int) (getenv('DB_PORT') ?: 3306));
$connection->set_charset('utf8mb4');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            getPurchase($connection, (int) $_GET['id']);
        } else if (isset($_GET['action']) && $_GET['action'] === 'form_data') {
            getFormData($connection);
        } else {
            listPurchasingData($connection);
        }
    } else if ($method === 'POST') {
        createPurchase($connection);
    } else if ($method === 'PUT') {
        updatePurchase($connection);
    } else if ($method === 'DELETE') {
        deletePurchase($connection);
    } else {
        respond(['message' => 'Método no permitido.'], 405);
    }
} catch (Throwable $error) {
    respond(['message' => 'No fue posible procesar la solicitud de compras.', 'error' => $error->getMessage()], 500);
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

function getFormData(mysqli $connection): void {
    $dateFilter = filter_input(INPUT_GET, 'fecha', FILTER_DEFAULT);
    $dateFilter = is_string($dateFilter) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter) ? $dateFilter : null;

    $suppliers = $connection->query("SELECT id, nombre, dias_plazo FROM proveedores WHERE eliminado_en IS NULL AND estado = 'activo' ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
    $products = $connection->query("SELECT id, nombre, precio_compra, lleva_iva FROM productos WHERE estado = 'activo' ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

    $datesResult = $connection->query("SELECT DISTINCT fecha_entrega FROM pedidos WHERE eliminado_en IS NULL AND estado IN ('pendiente', 'confirmado', 'preparando') AND fecha_entrega IS NOT NULL ORDER BY fecha_entrega ASC")->fetch_all(MYSQLI_ASSOC);
    $availableDates = array_values(array_map(fn(array $row) => (string) $row['fecha_entrega'], $datesResult));

    if (!$dateFilter && count($availableDates) > 0) {
        $dateFilter = $availableDates[0];
    }

    $nextIdRow = $connection->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM compras")->fetch_assoc();
    $nextId = (int) ($nextIdRow['next_id'] ?? 1);
    $nextDocumentNumber = 'FAC-COMP-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);

    $demand = [];
    if ($dateFilter) {
        $stmtDemand = $connection->prepare("SELECT d.producto_id, pr.nombre AS producto, pr.precio_compra, pr.lleva_iva, SUM(d.cantidad) AS cantidad_solicitada, COALESCE(SUM(cobertura.cantidad_comprada), 0) AS cantidad_comprada FROM pedidos_detalle d INNER JOIN pedidos p ON p.id = d.pedido_id INNER JOIN productos pr ON pr.id = d.producto_id LEFT JOIN (SELECT cdp.pedido_detalle_id, SUM(cdp.cantidad_asignada) AS cantidad_comprada FROM compras_detalle_pedidos cdp INNER JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id INNER JOIN compras c ON c.id = cd.compra_id WHERE c.estado_pago <> 'anulada' GROUP BY cdp.pedido_detalle_id) cobertura ON cobertura.pedido_detalle_id = d.id WHERE p.eliminado_en IS NULL AND p.estado IN ('pendiente', 'confirmado', 'preparando') AND p.fecha_entrega = ? GROUP BY d.producto_id, pr.nombre, pr.precio_compra, pr.lleva_iva ORDER BY pr.nombre ASC");
        $stmtDemand->bind_param('s', $dateFilter);
        $stmtDemand->execute();
        $demandRows = $stmtDemand->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($demandRows as $row) {
            $required = (float) $row['cantidad_solicitada'];
            $purchased = (float) $row['cantidad_comprada'];
            $faltante = max(0, $required - $purchased);
            if ($faltante > 0) {
                $demand[] = [
                    'producto_id' => (int) $row['producto_id'],
                    'producto' => $row['producto'],
                    'cantidad' => $faltante,
                    'costo_unitario' => (float) $row['precio_compra'],
                    'lleva_iva' => (int) $row['lleva_iva']
                ];
            }
        }
    }

    respond([
        'suppliers' => $suppliers,
        'products' => $products,
        'next_document_number' => $nextDocumentNumber,
        'available_dates' => $availableDates,
        'selected_date' => $dateFilter,
        'demand' => $demand
    ]);
}

function createPurchase(mysqli $connection): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['proveedorId', 'fechaEmision', 'fechaVencimiento', 'condicionPago', 'detalles'] as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || ($field === 'detalles' && count($data[$field]) === 0)) {
            respond(['message' => "El campo $field es obligatorio."], 422);
        }
    }
    if (!is_array($data['detalles'])) {
        respond(['message' => 'El detalle de la compra no es válido.'], 422);
    }

    $supplierId = (int) $data['proveedorId'];
    $docType = in_array(($data['tipoDocumento'] ?? ''), ['orden_compra', 'factura', 'nota_entrada'], true) ? (string) $data['tipoDocumento'] : 'factura';
    $issueDate = (string) $data['fechaEmision'];
    $dueDate = (string) $data['fechaVencimiento'];
    $paymentCondition = $data['condicionPago'] === 'contado' ? 'contado' : 'credito';
    $orderDeliveryDate = isset($data['fechaEntregaPedido']) && $data['fechaEntregaPedido'] !== '' ? (string) $data['fechaEntregaPedido'] : null;

    $connection->begin_transaction();
    try {
        $checkSupp = $connection->prepare("SELECT id FROM proveedores WHERE id = ? AND eliminado_en IS NULL AND estado = 'activo'");
        $checkSupp->bind_param('i', $supplierId);
        $checkSupp->execute();
        if ($checkSupp->get_result()->num_rows === 0) {
            respond(['message' => 'El proveedor seleccionado no es válido o está inactivo.'], 422);
        }

        $subtotal = 0.0;
        $tax = 0.0;
        $prodStmt = $connection->prepare("SELECT lleva_iva FROM productos WHERE id = ? AND estado = 'activo'");

        foreach ($data['detalles'] as $detail) {
            $productId = (int) ($detail['productoId'] ?? 0);
            $quantity = (float) ($detail['cantidad'] ?? 0);
            $cost = (float) ($detail['costoUnitario'] ?? -1);

            if ($productId <= 0 || $quantity <= 0 || $cost < 0) {
                respond(['message' => 'Las cantidades y costos deben ser válidos.'], 422);
            }

            $prodStmt->bind_param('i', $productId);
            $prodStmt->execute();
            $prod = $prodStmt->get_result()->fetch_assoc();
            if (!$prod) {
                respond(['message' => 'Uno de los productos elegidos no está disponible.'], 422);
            }

            $lineTotal = $quantity * $cost;
            $subtotal += $lineTotal;
            if ((int) $prod['lleva_iva'] === 1) {
                $tax += $lineTotal * 0.16;
            }
        }

        $tax = round($tax, 2);
        $total = $subtotal + $tax;
        $paid = $paymentCondition === 'contado' ? $total : 0.0;
        $paymentState = $paymentCondition === 'contado' ? 'pagada' : 'pendiente';

        $tempNum = 'TMP-' . bin2hex(random_bytes(8));
        $stmtIns = $connection->prepare("INSERT INTO compras (proveedor_id, tipo_documento, numero_documento, fecha_emision, fecha_vencimiento, subtotal, impuesto, total, monto_pagado, condicion_pago, estado_pago) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->bind_param('issssddddss', $supplierId, $docType, $tempNum, $issueDate, $dueDate, $subtotal, $tax, $total, $paid, $paymentCondition, $paymentState);
        $stmtIns->execute();
        $purchaseId = $connection->insert_id;

        $docNum = trim((string) ($data['numeroDocumento'] ?? ''));
        if ($docNum === '') {
            $docNum = 'FAC-COMP-' . str_pad((string) $purchaseId, 5, '0', STR_PAD_LEFT);
        }
        $stmtUpdateNum = $connection->prepare("UPDATE compras SET numero_documento = ? WHERE id = ?");
        $stmtUpdateNum->bind_param('si', $docNum, $purchaseId);
        $stmtUpdateNum->execute();

        $stmtDetailIns = $connection->prepare("INSERT INTO compras_detalle (compra_id, producto_id, cantidad, costo_unitario) VALUES (?, ?, ?, ?)");
        $stmtProdCost = $connection->prepare("UPDATE productos SET precio_compra = ? WHERE id = ?");

        foreach ($data['detalles'] as $detail) {
            $productId = (int) $detail['productoId'];
            $quantity = (float) $detail['cantidad'];
            $cost = (float) $detail['costoUnitario'];

            $stmtDetailIns->bind_param('iidd', $purchaseId, $productId, $quantity, $cost);
            $stmtDetailIns->execute();
            $purchaseDetailId = $connection->insert_id;

            $stmtProdCost->bind_param('di', $cost, $productId);
            $stmtProdCost->execute();

            $remainingToAssign = $quantity;

            $sqlPendingDetails = "SELECT pd.id, pd.pedido_id, pd.cantidad, COALESCE(SUM(cdp.cantidad_asignada), 0) AS asignado FROM pedidos_detalle pd INNER JOIN pedidos p ON p.id = pd.pedido_id LEFT JOIN compras_detalle_pedidos cdp ON cdp.pedido_detalle_id = pd.id LEFT JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id LEFT JOIN compras c ON c.id = cd.compra_id AND c.estado_pago <> 'anulada' WHERE pd.producto_id = ? AND p.eliminado_en IS NULL AND p.estado IN ('pendiente', 'confirmado', 'preparando')";
            if ($orderDeliveryDate) {
                $sqlPendingDetails .= " AND p.fecha_entrega = ?";
            }
            $sqlPendingDetails .= " GROUP BY pd.id, pd.pedido_id, pd.cantidad HAVING (pd.cantidad - asignado) > 0 ORDER BY p.fecha_entrega ASC, p.id ASC";

            $stmtPendingDetails = $connection->prepare($sqlPendingDetails);
            if ($orderDeliveryDate) {
                $stmtPendingDetails->bind_param('is', $productId, $orderDeliveryDate);
            } else {
                $stmtPendingDetails->bind_param('i', $productId);
            }
            $stmtPendingDetails->execute();
            $pendingList = $stmtPendingDetails->get_result()->fetch_all(MYSQLI_ASSOC);

            $stmtAssignIns = $connection->prepare("INSERT INTO compras_detalle_pedidos (compra_detalle_id, pedido_detalle_id, cantidad_asignada) VALUES (?, ?, ?)");

            foreach ($pendingList as $pendingItem) {
                if ($remainingToAssign <= 0) break;
                $needed = (float) $pendingItem['cantidad'] - (float) $pendingItem['asignado'];
                $assign = min($remainingToAssign, $needed);

                if ($assign > 0) {
                    $stmtAssignIns->bind_param('iid', $purchaseDetailId, $pendingItem['id'], $assign);
                    $stmtAssignIns->execute();
                    $remainingToAssign -= $assign;
                }
            }
        }

        $connection->query("UPDATE pedidos p SET p.estado = 'preparando' WHERE p.eliminado_en IS NULL AND p.estado IN ('pendiente', 'confirmado') AND NOT EXISTS (SELECT 1 FROM pedidos_detalle pd LEFT JOIN (SELECT cdp.pedido_detalle_id, SUM(cdp.cantidad_asignada) AS asignado FROM compras_detalle_pedidos cdp INNER JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id INNER JOIN compras c ON c.id = cd.compra_id WHERE c.estado_pago <> 'anulada' GROUP BY cdp.pedido_detalle_id) c ON c.pedido_detalle_id = pd.id WHERE pd.pedido_id = p.id AND (pd.cantidad - COALESCE(c.asignado, 0)) > 0.001)");

        $connection->commit();
        respond(['id' => $purchaseId, 'numeroDocumento' => $docNum, 'message' => 'Compra registrada con éxito.'], 201);
    } catch (Throwable $error) {
        $connection->rollback();
        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) {
            respond(['message' => 'El número de documento ya existe para este proveedor.'], 409);
        }
        throw $error;
    }
}

function getPurchase(mysqli $connection, int $id): void {
    $statement = $connection->prepare("SELECT c.id, c.proveedor_id, c.tipo_documento, c.numero_documento, c.fecha_emision, c.fecha_vencimiento, c.condicion_pago, c.subtotal, c.impuesto, c.total, c.estado_pago FROM compras c WHERE c.id = ? AND c.estado_pago <> 'anulada'");
    $statement->bind_param('i', $id);
    $statement->execute();
    $result = $statement->get_result();
    if ($result->num_rows === 0) respond(['message' => 'Compra no encontrada.'], 404);
    $purchase = $result->fetch_assoc();

    $details = $connection->prepare("SELECT cd.producto_id, pr.nombre AS producto, pr.lleva_iva, cd.cantidad, cd.costo_unitario, cd.subtotal FROM compras_detalle cd INNER JOIN productos pr ON pr.id = cd.producto_id WHERE cd.compra_id = ?");
    $details->bind_param('i', $id);
    $details->execute();
    $purchase['detalles'] = $details->get_result()->fetch_all(MYSQLI_ASSOC);

    respond(['purchase' => $purchase]);
}

function deletePurchase(mysqli $connection): void {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) respond(['message' => 'Compra no válida.'], 400);

    $connection->begin_transaction();
    try {
        $statement = $connection->prepare("UPDATE compras SET estado_pago = 'anulada' WHERE id = ? AND estado_pago <> 'anulada'");
        $statement->bind_param('i', $id);
        $statement->execute();
        if ($statement->affected_rows === 0) respond(['message' => 'Compra no encontrada o ya anulada.'], 404);

        $connection->query("DELETE cdp FROM compras_detalle_pedidos cdp INNER JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id WHERE cd.compra_id = $id");

        $connection->commit();
        respond([], 204);
    } catch (Throwable $error) {
        $connection->rollback();
        throw $error;
    }
}

function updatePurchase(mysqli $connection): void {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) respond(['message' => 'Compra no válida.'], 400);

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['proveedorId', 'fechaEmision', 'fechaVencimiento', 'condicionPago', 'detalles'] as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || ($field === 'detalles' && count($data[$field]) === 0)) {
            respond(['message' => "El campo $field es obligatorio."], 422);
        }
    }
    if (!is_array($data['detalles'])) {
        respond(['message' => 'El detalle de la compra no es válido.'], 422);
    }

    $supplierId = (int) $data['proveedorId'];
    $docType = in_array(($data['tipoDocumento'] ?? ''), ['orden_compra', 'factura', 'nota_entrada'], true) ? (string) $data['tipoDocumento'] : 'factura';
    $docNum = trim((string) ($data['numeroDocumento'] ?? ''));
    $issueDate = (string) $data['fechaEmision'];
    $dueDate = (string) $data['fechaVencimiento'];
    $paymentCondition = $data['condicionPago'] === 'contado' ? 'contado' : 'credito';
    $orderDeliveryDate = isset($data['fechaEntregaPedido']) && $data['fechaEntregaPedido'] !== '' ? (string) $data['fechaEntregaPedido'] : null;

    $connection->begin_transaction();
    try {
        $checkPurchase = $connection->prepare("SELECT id FROM compras WHERE id = ? AND estado_pago <> 'anulada'");
        $checkPurchase->bind_param('i', $id);
        $checkPurchase->execute();
        if ($checkPurchase->get_result()->num_rows === 0) respond(['message' => 'Compra no encontrada.'], 404);

        $subtotal = 0.0;
        $tax = 0.0;
        $prodStmt = $connection->prepare("SELECT lleva_iva FROM productos WHERE id = ? AND estado = 'activo'");

        foreach ($data['detalles'] as $detail) {
            $productId = (int) ($detail['productoId'] ?? 0);
            $quantity = (float) ($detail['cantidad'] ?? 0);
            $cost = (float) ($detail['costoUnitario'] ?? -1);

            if ($productId <= 0 || $quantity <= 0 || $cost < 0) {
                respond(['message' => 'Las cantidades y costos deben ser válidos.'], 422);
            }

            $prodStmt->bind_param('i', $productId);
            $prodStmt->execute();
            $prod = $prodStmt->get_result()->fetch_assoc();
            if (!$prod) respond(['message' => 'Uno de los productos elegidos no está disponible.'], 422);

            $lineTotal = $quantity * $cost;
            $subtotal += $lineTotal;
            if ((int) $prod['lleva_iva'] === 1) $tax += $lineTotal * 0.16;
        }

        $tax = round($tax, 2);
        $total = $subtotal + $tax;
        $paid = $paymentCondition === 'contado' ? $total : 0.0;
        $paymentState = $paymentCondition === 'contado' ? 'pagada' : 'pendiente';

        $stmtIns = $connection->prepare("UPDATE compras SET proveedor_id = ?, tipo_documento = ?, numero_documento = ?, fecha_emision = ?, fecha_vencimiento = ?, subtotal = ?, impuesto = ?, total = ?, monto_pagado = ?, condicion_pago = ?, estado_pago = ? WHERE id = ?");
        $stmtIns->bind_param('issssddddssi', $supplierId, $docType, $docNum, $issueDate, $dueDate, $subtotal, $tax, $total, $paid, $paymentCondition, $paymentState, $id);
        $stmtIns->execute();

        $connection->query("DELETE cdp FROM compras_detalle_pedidos cdp INNER JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id WHERE cd.compra_id = $id");
        $connection->query("DELETE FROM compras_detalle WHERE compra_id = $id");

        $stmtDetailIns = $connection->prepare("INSERT INTO compras_detalle (compra_id, producto_id, cantidad, costo_unitario) VALUES (?, ?, ?, ?)");
        $stmtProdCost = $connection->prepare("UPDATE productos SET precio_compra = ? WHERE id = ?");

        foreach ($data['detalles'] as $detail) {
            $productId = (int) $detail['productoId'];
            $quantity = (float) $detail['cantidad'];
            $cost = (float) $detail['costoUnitario'];

            $stmtDetailIns->bind_param('iidd', $id, $productId, $quantity, $cost);
            $stmtDetailIns->execute();
            $purchaseDetailId = $connection->insert_id;

            $stmtProdCost->bind_param('di', $cost, $productId);
            $stmtProdCost->execute();

            $remainingToAssign = $quantity;

            $sqlPendingDetails = "SELECT pd.id, pd.pedido_id, pd.cantidad, COALESCE(SUM(cdp.cantidad_asignada), 0) AS asignado FROM pedidos_detalle pd INNER JOIN pedidos p ON p.id = pd.pedido_id LEFT JOIN compras_detalle_pedidos cdp ON cdp.pedido_detalle_id = pd.id LEFT JOIN compras_detalle cd ON cd.id = cdp.compra_detalle_id LEFT JOIN compras c ON c.id = cd.compra_id AND c.estado_pago <> 'anulada' WHERE pd.producto_id = ? AND p.eliminado_en IS NULL AND p.estado IN ('pendiente', 'confirmado', 'preparando')";
            if ($orderDeliveryDate) {
                $sqlPendingDetails .= " AND p.fecha_entrega = ?";
            }
            $sqlPendingDetails .= " GROUP BY pd.id, pd.pedido_id, pd.cantidad HAVING (pd.cantidad - asignado) > 0 ORDER BY p.fecha_entrega ASC, p.id ASC";

            $stmtPendingDetails = $connection->prepare($sqlPendingDetails);
            if ($orderDeliveryDate) {
                $stmtPendingDetails->bind_param('is', $productId, $orderDeliveryDate);
            } else {
                $stmtPendingDetails->bind_param('i', $productId);
            }
            $stmtPendingDetails->execute();
            $pendingList = $stmtPendingDetails->get_result()->fetch_all(MYSQLI_ASSOC);

            $stmtAssignIns = $connection->prepare("INSERT INTO compras_detalle_pedidos (compra_detalle_id, pedido_detalle_id, cantidad_asignada) VALUES (?, ?, ?)");

            foreach ($pendingList as $pendingItem) {
                if ($remainingToAssign <= 0) break;
                $needed = (float) $pendingItem['cantidad'] - (float) $pendingItem['asignado'];
                $assign = min($remainingToAssign, $needed);

                if ($assign > 0) {
                    $stmtAssignIns->bind_param('iid', $purchaseDetailId, $pendingItem['id'], $assign);
                    $stmtAssignIns->execute();
                    $remainingToAssign -= $assign;
                }
            }
        }

        $connection->commit();
        respond(['id' => $id, 'numeroDocumento' => $docNum, 'message' => 'Compra actualizada con éxito.'], 200);
    } catch (Throwable $error) {
        $connection->rollback();
        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) {
            respond(['message' => 'El número de documento ya existe para este proveedor.'], 409);
        }
        throw $error;
    }
}

function respond(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
