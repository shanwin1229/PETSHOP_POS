<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connection.php';

ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('[PAWPOS] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage(), 'data' => null]);
    exit;
});

require_once __DIR__ . '/config/constants.php';

/* Bootstrap */
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireAuth();

/* Constants */
if (!defined('TAX_RATE'))         define('TAX_RATE',         0.00);
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);
if (!defined('TXN_PREFIX'))       define('TXN_PREFIX',       'TXN');

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {

    case 'GET':
        switch ($action) {
            case 'summary':      getSalesSummary();  break;
            case 'top_products': getTopProducts();   break;
            default:
                $id ? getTransaction($id) : getTransactions();
        }
        break;

    case 'POST':
        requireRole(['Admin', 'Cashier']);
        switch ($action) {
            case 'void': voidTransaction(); break;
            default:     processSale();
        }
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Transactions */
function getTransactions(): never
{
    global $pdo;

    $dateFrom   = sanitizeString($_GET['date_from']   ?? '');
    $dateTo     = sanitizeString($_GET['date_to']     ?? '');
    $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
    $cashierId  = isset($_GET['cashier_id'])  ? (int)$_GET['cashier_id']  : null;
    $status     = sanitizeString($_GET['status'] ?? '');
    $page       = max(1, (int)($_GET['page']  ?? 1));
    $limit      = min(100, max(1, (int)($_GET['limit'] ?? 10)));
    $offset     = ($page - 1) * $limit;

    $where  = ['1 = 1'];
    $params = [];

    if ($dateFrom !== '') {
        $where[]              = 'DATE(t.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]            = 'DATE(t.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    if ($customerId !== null) {
        $where[]                = 't.customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }
    if ($cashierId !== null) {
        $where[]               = 't.cashier_id = :cashier_id';
        $params[':cashier_id'] = $cashierId;
    }
    if (in_array($status, ['completed', 'void'], true)) {
        $where[]           = 't.status = :status';
        $params[':status'] = $status;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            t.id,
            t.txn_no,
            t.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            t.cashier_id,
            CONCAT(u.first_name, ' ', u.last_name) AS cashier_name,
            t.subtotal,
            t.discount_amount,
            t.discount_type,
            t.tax_amount,
            t.total_amount,
            t.amount_paid,
            t.change_amount,
            t.status,
            t.created_at
        FROM transactions t
        LEFT JOIN customers c ON c.id = t.customer_id
        LEFT JOIN users     u ON u.id = t.cashier_id
        WHERE {$whereSQL}
        ORDER BY t.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $transactions = $stmt->fetchAll();
    $transactions = array_map('castTransactionTypes', $transactions);

    jsonResponse(true, 'Transactions retrieved.', [
        'transactions' => $transactions,
        'pagination'   => buildPagination($total, $page, $limit)
    ]);
}

/* Read: Get Single Transaction */
function getTransaction(int $id): never
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            t.id, t.txn_no, t.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            t.cashier_id,
            CONCAT(u.first_name, ' ', u.last_name) AS cashier_name,
            t.subtotal, t.discount_amount, t.discount_type,
            t.tax_amount, t.total_amount, t.amount_paid,
            t.change_amount, t.status, t.created_at
        FROM transactions t
        LEFT JOIN customers c ON c.id = t.customer_id
        LEFT JOIN users     u ON u.id = t.cashier_id
        WHERE t.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $txn = $stmt->fetch();

    if (!$txn) {
        jsonResponse(false, "Transaction #{$id} not found.", null, 404);
    }

    $itemStmt = $pdo->prepare("
        SELECT
            ti.id,
            ti.product_id,
            p.name          AS product_name,
            p.sku,
            ti.quantity,
            ti.unit_price,
            ti.line_total
        FROM transaction_items ti
        JOIN products p ON p.id = ti.product_id
        WHERE ti.transaction_id = :txn_id
        ORDER BY ti.id ASC
    ");
    $itemStmt->execute([':txn_id' => $id]);
    $items = $itemStmt->fetchAll();

    foreach ($items as &$item) {
        $item['quantity']   = (int)$item['quantity'];
        $item['unit_price'] = (float)$item['unit_price'];
        $item['line_total'] = (float)$item['line_total'];
    }
    unset($item);

    $txn = castTransactionTypes($txn);
    $txn['items'] = $items;

    jsonResponse(true, 'Transaction retrieved.', $txn);
}

/* Read: Sales Summary */
function getSalesSummary(): never
{
    global $pdo;

    $period = sanitizeString($_GET['period'] ?? 'today');

    $dateFilter = match ($period) {
        'week'  => 'DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)',
        'month' => 'YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())',
        'year'  => 'YEAR(created_at) = YEAR(NOW())',
        default => 'DATE(created_at) = CURDATE()'
    };

    $stmt = $pdo->query("
        SELECT
            COUNT(*)                       AS transaction_count,
            COALESCE(SUM(total_amount),  0) AS total_revenue,
            COALESCE(SUM(discount_amount),0) AS total_discounts,
            COALESCE(SUM(tax_amount),    0) AS total_tax,
            COALESCE(AVG(total_amount),  0) AS avg_ticket
        FROM transactions
        WHERE status = 'completed'
          AND {$dateFilter}
    ");

    $summary = $stmt->fetch();

    $yesterdayStmt = $pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0) AS total
         FROM transactions
         WHERE status = 'completed'
           AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
    );
    $yesterday = (float)$yesterdayStmt->fetchColumn();
    $today     = (float)$summary['total_revenue'];

    $growth = $yesterday > 0
        ? round((($today - $yesterday) / $yesterday) * 100, 1)
        : null;

    jsonResponse(true, 'Sales summary retrieved.', [
        'period'              => $period,
        'transaction_count'   => (int)$summary['transaction_count'],
        'total_revenue'       => round((float)$summary['total_revenue'],    2),
        'total_discounts'     => round((float)$summary['total_discounts'],  2),
        'total_tax'           => round((float)$summary['total_tax'],        2),
        'avg_ticket'          => round((float)$summary['avg_ticket'],       2),
        'growth_vs_yesterday' => $growth
    ]);
}

/* Read: Top-Selling Products */
function getTopProducts(): never
{
    global $pdo;

    $limit  = min(20, max(1, (int)($_GET['limit']  ?? 5)));
    $period = sanitizeString($_GET['period'] ?? 'month');

    $dateFilter = match ($period) {
        'today' => 'DATE(t.created_at) = CURDATE()',
        'week'  => 'DATE(t.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)',
        'year'  => 'YEAR(t.created_at) = YEAR(NOW())',
        default => 'YEAR(t.created_at) = YEAR(NOW()) AND MONTH(t.created_at) = MONTH(NOW())'
    };

    $stmt = $pdo->prepare("
        SELECT
            ti.product_id,
            p.name          AS product_name,
            p.category,
            SUM(ti.quantity)   AS total_qty_sold,
            SUM(ti.line_total) AS total_revenue
        FROM transaction_items ti
        JOIN transactions t ON t.id = ti.transaction_id
        JOIN products     p ON p.id = ti.product_id
        WHERE t.status = 'completed'
          AND {$dateFilter}
        GROUP BY ti.product_id, p.name, p.category
        ORDER BY total_qty_sold DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $products = $stmt->fetchAll();
    foreach ($products as &$p) {
        $p['total_qty_sold'] = (int)$p['total_qty_sold'];
        $p['total_revenue']  = round((float)$p['total_revenue'], 2);
    }
    unset($p);

    jsonResponse(true, 'Top products retrieved.', [
        'period'   => $period,
        'products' => $products
    ]);
}

/* Write: Process Sale */
function processSale(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $customerId    = isset($body['customer_id']) && $body['customer_id'] !== null
                     ? (int)$body['customer_id'] : null;
    $rawItems      = $body['items']          ?? [];
    $discountType  = sanitizeString($body['discount_type']  ?? 'peso');
    $discountValue = (float)($body['discount_value'] ?? 0);
    $amountPaid    = (float)($body['amount_paid']    ?? 0);

    if (empty($rawItems) || !is_array($rawItems)) {
        jsonResponse(false, 'Cart cannot be empty.', null, 422);
    }

    if (!in_array($discountType, ['peso', 'percent'], true)) {
        jsonResponse(false, 'discount_type must be "peso" or "percent".', null, 422);
    }

    if ($amountPaid <= 0) {
        jsonResponse(false, 'amount_paid must be greater than zero.', null, 422);
    }

    $cartItems = validateCartItems($pdo, $rawItems);

    $subtotal = computeSubtotal($cartItems);
    $discount = computeDiscount($subtotal, $discountType, $discountValue);
    $taxable  = $subtotal - $discount;
    $tax      = TAX_RATE > 0 ? round($taxable * TAX_RATE, 2) : 0.0;
    $total    = round(max(0, $taxable + $tax), 2);
    $change   = round(max(0, $amountPaid - $total), 2);

    if ($amountPaid < $total) {
        jsonResponse(
            false,
            sprintf('Insufficient payment. Amount due: ₱%s. Received: ₱%s.',
                number_format($total, 2), number_format($amountPaid, 2)),
            null, 422
        );
    }
    $txnNo        = generateTxnNo($pdo);

    try {
        $txnId = db_transaction(function (PDO $pdo) use (
            $txnNo, $customerId, $cartItems,
            $subtotal, $discount, $discountType, $discountValue,
            $tax, $total, $amountPaid, $change
        ) {
            $headerStmt = $pdo->prepare("
                INSERT INTO transactions
                    (txn_no, customer_id, cashier_id,
                     subtotal, discount_amount, discount_type, discount_value,
                     tax_amount, total_amount, amount_paid, change_amount,
                     status, created_at)
                VALUES
                    (:txn_no, :customer_id, :cashier_id,
                     :subtotal, :discount, :disc_type, :disc_val,
                     :tax, :total, :paid, :change,
                     'completed', NOW())
            ");
            $headerStmt->execute([
                ':txn_no'      => $txnNo,
                ':customer_id' => $customerId,
                ':cashier_id'  => currentUserId(),
                ':subtotal'    => $subtotal,
                ':discount'    => $discount,
                ':disc_type'   => $discountType,
                ':disc_val'    => $discountValue,
                ':tax'         => $tax,
                ':total'       => $total,
                ':paid'        => $amountPaid,
                ':change'      => $change,
            ]);

            $txnId = (int)$pdo->lastInsertId();

            foreach ($cartItems as $item) {
                $pdo->prepare("
                    INSERT INTO transaction_items
                        (transaction_id, product_id, quantity, unit_price, line_total)
                    VALUES
                        (:txn_id, :product_id, :qty, :unit_price, :line_total)
                ")->execute([
                    ':txn_id'     => $txnId,
                    ':product_id' => $item['product_id'],
                    ':qty'        => $item['quantity'],
                    ':unit_price' => $item['unit_price'],
                    ':line_total' => $item['line_total']
                ]);

                $stockStmt = $pdo->prepare(
                    'SELECT stock_qty FROM products WHERE id = :id FOR UPDATE'
                );
                $stockStmt->execute([':id' => $item['product_id']]);
                $currentStock = (int)$stockStmt->fetchColumn();

                if ($currentStock < $item['quantity']) {
                    throw new RangeException(
                        "Insufficient stock for '{$item['product_name']}'. " .
                        "Available: {$currentStock}, requested: {$item['quantity']}."
                    );
                }

                $newStock = $currentStock - $item['quantity'];

                $pdo->prepare(
                    'UPDATE products SET stock_qty = :stock, updated_at = NOW() WHERE id = :id'
                )->execute([':stock' => $newStock, ':id' => $item['product_id']]);

                $pdo->prepare("
                    INSERT INTO stock_history
                        (product_id, type, quantity, stock_before, stock_after,
                         reason, remarks, created_by, created_at)
                    VALUES
                        (:pid, 'out', :qty, :before, :after,
                         'POS sale', :txn_no, :user_id, NOW())
                ")->execute([
                    ':pid'     => $item['product_id'],
                    ':qty'     => $item['quantity'],
                    ':before'  => $currentStock,
                    ':after'   => $newStock,
                    ':txn_no'  => $txnNo,
                    ':user_id' => currentUserId()
                ]);
            }

            if ($customerId !== null) {
                $pdo->prepare(
                    'UPDATE customers SET total_spent = total_spent + :spent, updated_at = NOW() WHERE id = :id'
                )->execute([':spent' => $total, ':id' => $customerId]);
            }

            return $txnId;
        });

    } catch (RangeException $e) {
        jsonResponse(false, $e->getMessage(), null, 422);
    } catch (Throwable $e) {
        error_log('[PAWPOS] Sale processing error: ' . $e->getMessage());
        jsonResponse(false, 'Sale processing failed. Please try again.', null, 500);
    }

    logAuditAction(
        'SALE_PROCESSED',
        "Transaction {$txnNo} processed. Total: ₱" . number_format($total, 2) .
        ". Items: " . count($cartItems) . ". Customer ID: " . ($customerId ?? 'walk-in') . "."
    );

    $receipt = buildReceiptData(
        $txnId, $txnNo, $customerId, $cartItems,
        $subtotal, $discount, $discountType, $tax, $total,
        $amountPaid, $change
    );

    jsonResponse(true, 'Sale processed successfully.', $receipt, 201);
}

/* Write: Void Transaction */
function voidTransaction(): never
{
    global $pdo;

    $body   = getJsonBody();
    validateCsrfFromBody($body);
    requireRole(['Admin']);

    $txnId  = (int)($body['transaction_id'] ?? 0);
    $reason = sanitizeString($body['reason'] ?? '');

    if ($txnId  <= 0)  jsonResponse(false, 'transaction_id is required.',            null, 422);
    if ($reason === '') jsonResponse(false, 'A reason for voiding is required.',      null, 422);

    $txnStmt = $pdo->prepare(
        "SELECT id, txn_no, status, total_amount FROM transactions WHERE id = :id LIMIT 1"
    );
    $txnStmt->execute([':id' => $txnId]);
    $txn = $txnStmt->fetch();

    if (!$txn) {
        jsonResponse(false, "Transaction #{$txnId} not found.", null, 404);
    }

    if ($txn['status'] === 'void') {
        jsonResponse(false, "Transaction {$txn['txn_no']} is already void.", null, 409);
    }

    $itemStmt = $pdo->prepare(
        'SELECT product_id, quantity FROM transaction_items WHERE transaction_id = :id'
    );
    $itemStmt->execute([':id' => $txnId]);
    $items = $itemStmt->fetchAll();

    try {
        db_transaction(function (PDO $pdo) use ($txn, $txnId, $reason, $items) {
            $pdo->prepare(
                "UPDATE transactions SET status = 'void', updated_at = NOW() WHERE id = :id"
            )->execute([':id' => $txnId]);

            foreach ($items as $item) {
                $stockStmt = $pdo->prepare(
                    'SELECT stock_qty FROM products WHERE id = :id FOR UPDATE'
                );
                $stockStmt->execute([':id' => $item['product_id']]);
                $currentStock  = (int)$stockStmt->fetchColumn();
                $restoredStock = $currentStock + (int)$item['quantity'];

                $pdo->prepare(
                    'UPDATE products SET stock_qty = :stock, updated_at = NOW() WHERE id = :id'
                )->execute([':stock' => $restoredStock, ':id' => $item['product_id']]);

                $pdo->prepare("
                    INSERT INTO stock_history
                        (product_id, type, quantity, stock_before, stock_after,
                         reason, remarks, created_by, created_at)
                    VALUES
                        (:pid, 'adj', :qty, :before, :after,
                         'Void transaction', :txn_no, :user_id, NOW())
                ")->execute([
                    ':pid'     => $item['product_id'],
                    ':qty'     => $item['quantity'],
                    ':before'  => $currentStock,
                    ':after'   => $restoredStock,
                    ':txn_no'  => $txn['txn_no'],
                    ':user_id' => currentUserId()
                ]);
            }
        });

    } catch (Throwable $e) {
        error_log('[PAWPOS] Void transaction error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to void transaction. Please try again.', null, 500);
    }

    logAuditAction(
        'SALE_VOID',
        "Transaction {$txn['txn_no']} voided. Reason: {$reason}."
    );

    jsonResponse(true, "Transaction {$txn['txn_no']} has been voided successfully.");
}

/* Computation Helpers */
function validateCartItems(PDO $pdo, array $rawItems): array
{
    $cartItems = [];
    $errors    = [];

    foreach ($rawItems as $index => $raw) {
        $productId = (int)($raw['product_id'] ?? 0);
        $quantity  = (int)($raw['quantity']   ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            $errors[] = "Item #{$index}: Invalid product_id or quantity.";
            continue;
        }

        $stmt = $pdo->prepare(
            "SELECT id, name, selling_price, stock_qty, status
             FROM products WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $errors[] = "Item #{$index}: Product #{$productId} not found.";
            continue;
        }

        if ($product['status'] === 'inactive') {
            $errors[] = "Item #{$index}: '{$product['name']}' is not available for sale.";
            continue;
        }

        if ((int)$product['stock_qty'] < $quantity) {
            $errors[] = "Item #{$index}: Insufficient stock for '{$product['name']}'. " .
                        "Available: {$product['stock_qty']}, requested: {$quantity}.";
            continue;
        }

        $unitPrice = (float)$product['selling_price'];
        $lineTotal = round($unitPrice * $quantity, 2);

        $cartItems[] = [
            'product_id'   => $productId,
            'product_name' => $product['name'],
            'quantity'     => $quantity,
            'unit_price'   => $unitPrice,
            'line_total'   => $lineTotal
        ];
    }

    if ($errors) {
        jsonResponse(false, 'Cart validation failed.', ['errors' => $errors], 422);
    }

    return $cartItems;
}

function computeSubtotal(array $cartItems): float
{
    return round(array_sum(array_column($cartItems, 'line_total')), 2);
}

function computeDiscount(float $subtotal, string $type, float $value): float
{
    if ($value <= 0) return 0.0;
    $discount = $type === 'percent'
        ? ($subtotal * min($value, 100)) / 100
        : $value;
    return round(min($discount, $subtotal), 2);
}


function generateTxnNo(PDO $pdo): string
{
    $stmt  = $pdo->query(
        "SELECT MAX(CAST(SUBSTRING(txn_no, " . strlen(TXN_PREFIX) + 2 . ") AS UNSIGNED))
         FROM transactions"
    );
    $maxNo = (int)$stmt->fetchColumn();
    $next  = str_pad((string)($maxNo + 1), 5, '0', STR_PAD_LEFT);
    return TXN_PREFIX . '-' . $next;
}

function buildReceiptData(
    int    $txnId,
    string $txnNo,
    ?int   $customerId,
    array  $cartItems,
    float  $subtotal,
    float  $discount,
    string $discountType,
    float  $tax,
    float  $total,
    float  $amountPaid,
    float  $change
): array {
    global $pdo;

    $customerName   = 'Walk-in customer';
    if ($customerId !== null) {
        $custStmt = $pdo->prepare(
            'SELECT CONCAT(first_name, \' \', last_name) AS name FROM customers WHERE id = :id LIMIT 1'
        );
        $custStmt->execute([':id' => $customerId]);
        $cust = $custStmt->fetch();
        if ($cust) {
            $customerName = $cust['name'];
        }
    }

    return [
        'transaction_id'  => $txnId,
        'txn_no'          => $txnNo,
        'date'            => date('M j, Y h:i A'),
        'cashier'         => ($_SESSION['name'] ?? 'Cashier'),
        'customer'        => $customerName,
        'customer_id'     => $customerId,
        'items'           => $cartItems,
        'subtotal'        => $subtotal,
        'discount'        => $discount,
        'discount_type'   => $discountType,
        'tax'             => $tax,
        'total'           => $total,
        'amount_paid'     => $amountPaid,
        'change'          => $change
    ];
}

/* Type Casting */
function castTransactionTypes(array $txn): array
{
    $intFields   = ['id', 'customer_id', 'cashier_id'];
    $floatFields = ['subtotal', 'discount_amount', 'discount_value',
                    'tax_amount', 'total_amount', 'amount_paid', 'change_amount'];

    foreach ($intFields   as $f) { if (isset($txn[$f])) $txn[$f] = (int)$txn[$f];   }
    foreach ($floatFields as $f) { if (isset($txn[$f])) $txn[$f] = (float)$txn[$f]; }

    return $txn;
}

/* Utility */
function buildPagination(int $total, int $page, int $limit): array
{
    $totalPages = (int)ceil($total / max(1, $limit));
    return [
        'total'       => $total,
        'per_page'    => $limit,
        'page'        => $page,
        'total_pages' => $totalPages,
        'has_next'    => $page < $totalPages,
        'has_prev'    => $page > 1
    ];
}

/* Auth / Session / Csrf / Audit / Helpers */
function requireAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        jsonResponse(false, 'Authentication required.', null, 401);
    }
    if (time() - (int)$_SESSION['login_time'] > SESSION_LIFETIME) {
        session_destroy();
        jsonResponse(false, 'Session expired. Please log in again.', null, 401);
    }
}

function requireRole(array $allowedRoles): void
{
    if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
        jsonResponse(false, 'You do not have permission to perform this action.', null, 403);
    }
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function validateCsrfFromBody(array $body): void
{
    return;
}

function logAuditAction(string $action, string $details): void
{
    global $pdo;
    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, details, ip_address, created_at)
             VALUES (:uid, :action, :details, :ip, NOW())'
        )->execute([
            ':uid'     => currentUserId(),
            ':action'  => $action,
            ':details' => $details,
            ':ip'      => getClientIp()
        ]);
    } catch (PDOException $e) {
        error_log('[PAWPOS] Audit log error: ' . $e->getMessage());
    }
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    }
    return $_POST;
}

function sanitizeString(string $value): string
{
    return trim(str_replace("\0", '', $value));
}

function getClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function jsonResponse(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}