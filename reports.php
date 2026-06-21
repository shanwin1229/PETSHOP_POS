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

requireAuth();

/* Constants */
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$type   = sanitizeString($_GET['type']   ?? '');
$action = sanitizeString($_GET['action'] ?? '');

if ($method !== 'GET') {
    sendJson(false, 'Method not allowed.', null, 405);
}

switch ($action) {
    case 'export':  exportReport($type); break;
    case 'summary': getSummaryStats();   break;
    default:
        switch ($type) {
            case 'daily':       getSalesReport('daily');       break;
            case 'weekly':      getSalesReport('weekly');      break;
            case 'monthly':     getSalesReport('monthly');     break;
            case 'inventory':   getInventoryReport();          break;
            case 'appointment': getAppointmentReport();        break;
            default:
                sendJson(false, 'Invalid report type. Allowed: daily, weekly, monthly, inventory, appointment.', null, 400);
        }
}

/* Date Range Helpers */
function resolveDateRange(string $defaultPeriod = 'month'): array
{
    $from = sanitizeString($_GET['date_from'] ?? '');
    $to   = sanitizeString($_GET['date_to']   ?? '');

    if ($from !== '' && $to !== '') {
        if (!isValidDate($from) || !isValidDate($to)) {
            sendJson(false, 'date_from and date_to must be valid YYYY-MM-DD dates.', null, 422);
        }
        if ($from > $to) {
            sendJson(false, 'date_from cannot be after date_to.', null, 422);
        }
        return ['from' => $from, 'to' => $to, 'label' => "{$from} to {$to}"];
    }

    $period = sanitizeString($_GET['period'] ?? $defaultPeriod);
    $today  = date('Y-m-d');

    switch ($period) {
        case 'today':
            return ['from' => $today, 'to' => $today, 'label' => 'Today'];

        case 'week':
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            return ['from' => $weekStart, 'to' => $today, 'label' => 'This week'];

        case 'year':
            return ['from' => date('Y-01-01'), 'to' => $today, 'label' => date('Y')];

        case 'month':
        default:
            return ['from' => date('Y-m-01'), 'to' => $today, 'label' => date('F Y')];
    }
}

/* Report: Sales */
function getSalesReport(string $type): never
{
    global $pdo;

    $periodMap = ['daily' => 'today', 'weekly' => 'week', 'monthly' => 'month'];
    $range     = resolveDateRange($periodMap[$type] ?? 'month');

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*)                              AS transaction_count,
            COALESCE(SUM(total_amount),   0)      AS total_revenue,
            COALESCE(SUM(discount_amount),0)      AS total_discounts,
            COALESCE(SUM(tax_amount),     0)      AS total_tax,
            COALESCE(AVG(total_amount),   0)      AS avg_ticket
        FROM transactions
        WHERE status = 'completed'
          AND DATE(created_at) BETWEEN :from AND :to
    ");
    $summaryStmt->execute([':from' => $range['from'], ':to' => $range['to']]);
    $summary = $summaryStmt->fetch();

    $periodDays = (int)ceil((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;
    $prevTo     = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
    $prevFrom   = date('Y-m-d', strtotime($prevTo . " -{$periodDays} days +1 day"));

    $prevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM transactions
        WHERE status = 'completed'
          AND DATE(created_at) BETWEEN :from AND :to
    ");
    $prevStmt->execute([':from' => $prevFrom, ':to' => $prevTo]);
    $prevRevenue = (float)$prevStmt->fetchColumn();
    $curRevenue  = (float)$summary['total_revenue'];
    $growth      = $prevRevenue > 0
        ? round((($curRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
        : null;

    $chartStmt = $pdo->prepare("
        SELECT
            DATE(created_at)               AS label,
            COALESCE(SUM(total_amount), 0) AS value
        FROM transactions
        WHERE status = 'completed'
          AND DATE(created_at) BETWEEN :from AND :to
        GROUP BY DATE(created_at)
        ORDER BY label ASC
    ");
    $chartStmt->execute([':from' => $range['from'], ':to' => $range['to']]);
    $chartRows = $chartStmt->fetchAll();

    $chartLabels = array_column($chartRows, 'label');
    $chartValues = array_map(fn($r) => round((float)$r['value'], 2), $chartRows);

    $txnStmt = $pdo->prepare("
        SELECT
            t.txn_no,
            COALESCE(CONCAT(c.first_name,' ',c.last_name), 'Walk-in') AS customer,
            CONCAT(u.first_name,' ',u.last_name)                       AS cashier,
            (SELECT COUNT(*) FROM transaction_items ti WHERE ti.transaction_id = t.id) AS items,
            t.subtotal,
            t.discount_amount,
            t.total_amount,
            t.status,
            DATE(t.created_at) AS date,
            TIME_FORMAT(t.created_at, '%h:%i %p') AS time
        FROM transactions t
        LEFT JOIN customers c ON c.id = t.customer_id
        LEFT JOIN users     u ON u.id = t.cashier_id
        WHERE DATE(t.created_at) BETWEEN :from AND :to
        ORDER BY t.created_at DESC
        LIMIT 200
    ");
    $txnStmt->execute([':from' => $range['from'], ':to' => $range['to']]);
    $transactions = $txnStmt->fetchAll();

    foreach ($transactions as &$txn) {
        $txn['items']           = (int)$txn['items'];
        $txn['subtotal']        = (float)$txn['subtotal'];
        $txn['discount_amount'] = (float)$txn['discount_amount'];
        $txn['total_amount']    = (float)$txn['total_amount'];
    }
    unset($txn);

    sendJson(true, ucfirst($type) . ' sales report generated.', [
        'type'    => $type,
        'range'   => $range,
        'summary' => [
            'transaction_count'   => (int)$summary['transaction_count'],
            'total_revenue'       => round($curRevenue, 2),
            'total_discounts'     => round((float)$summary['total_discounts'], 2),
            'total_tax'           => round((float)$summary['total_tax'], 2),
            'avg_ticket'          => round((float)$summary['avg_ticket'], 2),
            'growth_pct'          => $growth,
            'prev_period_revenue' => round($prevRevenue, 2)
        ],
        'chart' => [
            'title'         => ucfirst($type) . ' revenue',
            'labels'        => $chartLabels,
            'values'        => $chartValues,
            'primary_label' => 'Revenue (₱)'
        ],
        'table' => [
            'title'   => 'Transactions',
            'columns' => ['TXN No.','Customer','Cashier','Items','Subtotal','Discount','Total','Status','Date','Time'],
            'rows'    => array_map(fn($t) => [
                $t['txn_no'], $t['customer'], $t['cashier'],
                $t['items'],
                '₱' . number_format($t['subtotal'],        2),
                '₱' . number_format($t['discount_amount'], 2),
                '₱' . number_format($t['total_amount'],    2),
                ucfirst($t['status']),
                $t['date'],
                $t['time']
            ], $transactions)
        ]
    ]);
}

/* Report: Inventory */
function getInventoryReport(): never
{
    global $pdo;

    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*)                                                  AS total_skus,
            SUM(CASE WHEN stock_qty = 0 THEN 1 ELSE 0 END)          AS out_of_stock,
            SUM(CASE WHEN reorder_level > 0
                      AND stock_qty > 0
                      AND stock_qty <= reorder_level THEN 1 ELSE 0 END) AS low_stock,
            SUM(CASE WHEN expiry_date IS NOT NULL
                      AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                      THEN 1 ELSE 0 END)                             AS expiring_soon,
            SUM(stock_qty * cost_price)                              AS total_stock_value
        FROM products
        WHERE status != 'inactive'
    ");
    $summary = $summaryStmt->fetch();

    $chartStmt = $pdo->query("
        SELECT category AS label, SUM(stock_qty) AS value
        FROM products
        WHERE status != 'inactive'
        GROUP BY category
        ORDER BY value DESC
    ");
    $chartRows = $chartStmt->fetchAll();

    $prodStmt = $pdo->query("
        SELECT
            p.name,
            p.sku,
            p.category,
            p.stock_qty,
            p.reorder_level,
            CASE
                WHEN p.stock_qty = 0                                        THEN 'Out of stock'
                WHEN p.reorder_level > 0 AND p.stock_qty <= p.reorder_level THEN 'Low stock'
                ELSE 'OK'
            END AS stock_status,
            p.expiry_date,
            p.cost_price,
            p.selling_price,
            p.status
        FROM products p
        WHERE p.status != 'deleted'
        ORDER BY stock_qty ASC, p.name ASC
    ");
    $products = $prodStmt->fetchAll();

    foreach ($products as &$p) {
        $p['stock_qty']     = (int)$p['stock_qty'];
        $p['reorder_level'] = (int)$p['reorder_level'];
        $p['cost_price']    = (float)$p['cost_price'];
        $p['selling_price'] = (float)$p['selling_price'];
    }
    unset($p);

    sendJson(true, 'Inventory report generated.', [
        'type'    => 'inventory',
        'summary' => [
            'total_skus'        => (int)$summary['total_skus'],
            'out_of_stock'      => (int)$summary['out_of_stock'],
            'low_stock'         => (int)$summary['low_stock'],
            'expiring_soon'     => (int)$summary['expiring_soon'],
            'total_stock_value' => round((float)$summary['total_stock_value'], 2)
        ],
        'chart' => [
            'title'         => 'Stock by category',
            'labels'        => array_column($chartRows, 'label'),
            'values'        => array_map(fn($r) => (int)$r['value'], $chartRows),
            'primary_label' => 'Units in stock'
        ],
        'table' => [
            'title'   => 'Full inventory',
            'columns' => ['Product','SKU','Category','Stock','Reorder at','Status','Expiry','Cost','Price'],
            'rows'    => array_map(fn($p) => [
                $p['name'], $p['sku'], $p['category'],
                $p['stock_qty'], $p['reorder_level'],
                $p['stock_status'],
                $p['expiry_date'] ?? '—',
                '₱' . number_format($p['cost_price'],    2),
                '₱' . number_format($p['selling_price'], 2)
            ], $products)
        ]
    ]);
}

/* Report: Appointments */
function getAppointmentReport(): never
{
    global $pdo;

    $range = resolveDateRange('month');

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)            AS completed,
            SUM(CASE WHEN status IN ('pending','confirmed') THEN 1 ELSE 0 END) AS upcoming,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)            AS cancelled
        FROM appointments
        WHERE date BETWEEN :from AND :to AND status != 'deleted'
    ");
    $summaryStmt->execute([':from' => $range['from'], ':to' => $range['to']]);
    $summary = $summaryStmt->fetch();

    $chartStmt = $pdo->prepare("
        SELECT
            groomer AS label,
            COUNT(*) AS value
        FROM appointments
        WHERE date BETWEEN :from AND :to
          AND status NOT IN ('cancelled', 'deleted')
        GROUP BY groomer
        ORDER BY value DESC
    ");
    $chartStmt->execute([':from' => $range['from'], ':to' => $range['to']]);
    $chartRows = $chartStmt->fetchAll();

    $apptStmt = $pdo->prepare("
        SELECT
            p.name AS pet_name,
            p.species,
            a.service,
            a.groomer,
            a.date,
            TIME_FORMAT(a.time, '%h:%i %p') AS time,
            a.duration,
            COALESCE(CONCAT(c.first_name,' ',c.last_name), '—') AS owner,
            a.status
        FROM appointments a
        LEFT JOIN pets p      ON p.id = a.pet_id
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE a.date BETWEEN :from AND :to AND a.status != 'deleted'
        ORDER BY a.date ASC, a.time ASC
        LIMIT 200
    ");
    $apptStmt->execute([':from' => $range['from'], ':to' => $range['to']]);
    $appointments = $apptStmt->fetchAll();

    sendJson(true, 'Appointment report generated.', [
        'type'    => 'appointment',
        'range'   => $range,
        'summary' => [
            'total'     => (int)$summary['total'],
            'completed' => (int)$summary['completed'],
            'upcoming'  => (int)$summary['upcoming'],
            'cancelled' => (int)$summary['cancelled']
        ],
        'chart' => [
            'title'         => 'Appointments by groomer',
            'labels'        => array_column($chartRows, 'label'),
            'values'        => array_map(fn($r) => (int)$r['value'], $chartRows),
            'primary_label' => 'Appointments'
        ],
        'table' => [
            'title'   => 'Appointment list',
            'columns' => ['Pet','Species','Service','Groomer','Date','Time','Duration','Owner','Status'],
            'rows'    => array_map(fn($a) => [
                $a['pet_name'], $a['species'], $a['service'],
                $a['groomer'],  $a['date'],    $a['time'],
                $a['duration'], $a['owner'],   ucfirst($a['status'])
            ], $appointments)
        ]
    ]);
}

/* Summary Stats */
function getSummaryStats(): never
{
    global $pdo;

    $today = date('Y-m-d');

    $salesStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS count,
            COALESCE(SUM(total_amount), 0) AS revenue
        FROM transactions
        WHERE status = 'completed' AND DATE(created_at) = :today
    ");
    $salesStmt->execute([':today' => $today]);
    $sales = $salesStmt->fetch();

    $yStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS revenue
        FROM transactions
        WHERE status = 'completed'
          AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ");
    $yStmt->execute();
    $yesterday = (float)$yStmt->fetchColumn();

    $lowStmt  = $pdo->query("
        SELECT COUNT(*) FROM products
        WHERE status = 'active'
          AND (stock_qty = 0 OR (reorder_level > 0 AND stock_qty <= reorder_level))
    ");
    $lowCount = (int)$lowStmt->fetchColumn();

    $apptStmt = $pdo->query("
        SELECT COUNT(*), SUM(status='confirmed') AS confirmed
        FROM appointments WHERE date = CURDATE() AND status != 'deleted'
    ");
    $appts = $apptStmt->fetch(PDO::FETCH_NUM);

    $custStmt  = $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'");
    $custCount = (int)$custStmt->fetchColumn();

    $curRevenue = (float)$sales['revenue'];
    $growth     = $yesterday > 0
        ? round((($curRevenue - $yesterday) / $yesterday) * 100, 1)
        : null;

    sendJson(true, 'Summary stats retrieved.', [
        'sales_today' => [
            'count'      => (int)$sales['count'],
            'revenue'    => round($curRevenue, 2),
            'growth_pct' => $growth,
            'yesterday'  => round($yesterday, 2)
        ],
        'low_stock_count'    => $lowCount,
        'today_appointments' => (int)($appts[0] ?? 0),
        'active_customers'   => $custCount
    ]);
}

/* Csv Export */
function exportReport(string $type): never
{
    global $pdo;

    ob_start();

    switch ($type) {
        case 'daily':
        case 'weekly':
        case 'monthly':     getSalesReport($type);    break;
        case 'inventory':   getInventoryReport();     break;
        case 'appointment': getAppointmentReport();   break;
        default:
            ob_end_clean();
            sendJson(false, 'Invalid report type for export.', null, 400);
    }

    $jsonOutput = ob_get_clean();
    $payload    = json_decode($jsonOutput, true);

    if (!$payload || !$payload['success'] || empty($payload['data']['table'])) {
        sendJson(false, 'Report generation failed.', null, 500);
    }

    $table    = $payload['data']['table'];
    $columns  = $table['columns'];
    $rows     = $table['rows'];
    $range    = $payload['data']['range'] ?? [];
    $dateSlug = isset($range['from'])
        ? str_replace('-', '', $range['from']) . '_' . str_replace('-', '', $range['to'])
        : date('Ymd');

    $filename = "pawpos_{$type}_report_{$dateSlug}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, $columns);

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fputcsv($output, []);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s'), 'By:', $_SESSION['name'] ?? 'System']);

    fclose($output);
    exit;
}

/* Utility */
function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/* Auth / Session / Helpers */
function requireAuth(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        sendJson(false, 'Authentication required.', null, 401);
    }
    if (time() - (int)$_SESSION['login_time'] > SESSION_LIFETIME) {
        session_destroy();
        sendJson(false, 'Session expired. Please log in again.', null, 401);
    }
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

function sendJson(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
