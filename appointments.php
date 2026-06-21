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
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);

const ALLOWED_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled'];
const STATUS_FLOW      = [
    'pending'   => ['confirmed', 'cancelled'],
    'confirmed' => ['completed', 'cancelled'],
    'completed' => [],
    'cancelled' => []
];

const SERVICES = [
    'Bath only',
    'Bath & trim',
    'Full groom',
    'Nail trim',
    'Ear cleaning',
    'Teeth brushing',
    'De-shedding treatment',
    'Sanitary trim',
    'Massage & spa'
];

const DURATION_MAP = [
    '30 mins'   => 30,
    '1 hour'    => 60,
    '1.5 hours' => 90,
    '2 hours'   => 120,
    '2.5 hours' => 150,
    '3 hours'   => 180
];

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {

    case 'GET':
        switch ($action) {
            case 'today':     getTodayAppointments(); break;
            case 'calendar':  getCalendarView();      break;
            case 'available': getAvailableSlots();    break;
            default:
                $id ? getAppointment($id) : getAppointments();
        }
        break;

    case 'POST':
        requireRole(['Admin', 'Cashier', 'Groomer']);
        bookAppointment();
        break;

    case 'PUT':
        requireRole(['Admin', 'Cashier', 'Groomer']);
        if (!$id) jsonResponse(false, 'Appointment ID is required.', null, 400);
        updateAppointment($id);
        break;

    case 'PATCH':
        requireRole(['Admin', 'Cashier', 'Groomer']);
        if (!$id) jsonResponse(false, 'Appointment ID is required.', null, 400);
        updateStatus($id);
        break;

    case 'DELETE':
        requireRole(['Admin', 'Cashier']);
        if (!$id) jsonResponse(false, 'Appointment ID is required.', null, 400);
        cancelAppointment($id);
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Appointments */
function getAppointments(): never
{
    global $pdo;

    $dateFrom = sanitizeString($_GET['date_from'] ?? '');
    $dateTo   = sanitizeString($_GET['date_to']   ?? '');
    $status   = sanitizeString($_GET['status']    ?? '');
    $groomer  = sanitizeString($_GET['groomer']   ?? '');
    $search   = sanitizeString($_GET['search']    ?? '');
    $petId    = isset($_GET['pet_id'])   ? (int)$_GET['pet_id']   : null;
    $ownerId  = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;
    $sortRaw  = sanitizeString($_GET['sort'] ?? 'date_asc');
    $page     = max(1, (int)($_GET['page']   ?? 1));
    $limit    = min(1000, max(1, (int)($_GET['limit'] ?? 10)));
    $offset   = ($page - 1) * $limit;

    $sortMap = [
        'date_asc'  => 'a.date ASC, a.time ASC',
        'date_desc' => 'a.date DESC, a.time DESC',
    ];
    $orderBy = $sortMap[$sortRaw] ?? 'a.date ASC, a.time ASC';

    $where  = ["a.status != 'deleted'"];
    $params = [];

    if ($dateFrom !== '') {
        $where[]              = 'a.date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]            = 'a.date <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    if (in_array($status, ALLOWED_STATUSES, true)) {
        $where[]           = 'a.status = :status';
        $params[':status'] = $status;
    }
    if ($groomer !== '') {
        $where[]            = 'a.groomer LIKE :groomer';
        $params[':groomer'] = '%' . $groomer . '%';
    }
    if ($petId !== null) {
        $where[]           = 'a.pet_id = :pet_id';
        $params[':pet_id'] = $petId;
    }
    if ($ownerId !== null) {
        $where[]             = 'p.owner_id = :owner_id';
        $params[':owner_id'] = $ownerId;
    }
    if ($search !== '') {
        $where[]           = "(p.name LIKE :search
                                OR CONCAT(c.first_name,' ',c.last_name) LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM appointments a
         LEFT JOIN pets p      ON p.id = a.pet_id
         LEFT JOIN customers c ON c.id = p.owner_id
         WHERE {$whereSQL}"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            a.id,
            a.pet_id,
            p.name                               AS pet_name,
            p.species,
            p.breed,
            p.owner_id,
            CONCAT(c.first_name,' ',c.last_name) AS owner_name,
            c.contact                            AS owner_contact,
            a.service,
            a.groomer,
            a.date,
            a.time,
            a.duration,
            a.status,
            a.notes,
            a.created_at
        FROM appointments a
        LEFT JOIN pets p      ON p.id = a.pet_id
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE {$whereSQL}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $appointments = $stmt->fetchAll();
    $appointments = array_map('enrichAppointment', $appointments);

    jsonResponse(true, 'Appointments retrieved.', [
        'appointments' => $appointments,
        'pagination'   => buildPagination($total, $page, $limit)
    ]);
}

/* Read: Get Single Appointment */
function getAppointment(int $id): never
{
    global $pdo;

    $appt = fetchAppointmentById($pdo, $id);
    if (!$appt) {
        jsonResponse(false, "Appointment #{$id} not found.", null, 404);
    }

    jsonResponse(true, 'Appointment retrieved.', $appt);
}

/* Read: Today'S Appointments */
function getTodayAppointments(): never
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            a.id, a.pet_id,
            p.name AS pet_name, p.species, p.breed,
            p.owner_id,
            CONCAT(c.first_name,' ',c.last_name) AS owner_name,
            c.contact AS owner_contact,
            a.service, a.groomer, a.date, a.time,
            a.duration, a.status, a.notes, a.created_at
        FROM appointments a
        LEFT JOIN pets p      ON p.id = a.pet_id
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE a.date = CURDATE() AND a.status != 'deleted'
        ORDER BY a.time ASC
    ");
    $stmt->execute();

    $appointments = $stmt->fetchAll();
    $appointments = array_map('enrichAppointment', $appointments);

    $summary = [
        'total'     => count($appointments),
        'pending'   => count(array_filter($appointments, fn($a) => $a['status'] === 'pending')),
        'confirmed' => count(array_filter($appointments, fn($a) => $a['status'] === 'confirmed')),
        'completed' => count(array_filter($appointments, fn($a) => $a['status'] === 'completed')),
        'cancelled' => count(array_filter($appointments, fn($a) => $a['status'] === 'cancelled'))
    ];

    jsonResponse(true, "Today's appointments retrieved.", [
        'date'         => date('Y-m-d'),
        'summary'      => $summary,
        'appointments' => $appointments
    ]);
}

/* Read: Calendar View */
function getCalendarView(): never
{
    global $pdo;

    $year  = max(2020, min(2099, (int)($_GET['year']  ?? date('Y'))));
    $month = max(1,    min(12,   (int)($_GET['month'] ?? date('n'))));

    $dateFrom = sprintf('%04d-%02d-01', $year, $month);
    $dateTo   = date('Y-m-t', strtotime($dateFrom));

    $stmt = $pdo->prepare("
        SELECT
            a.id, a.pet_id,
            p.name AS pet_name,
            a.service, a.groomer,
            a.date, a.time, a.duration, a.status,
            CONCAT(c.first_name,' ',c.last_name) AS owner_name
        FROM appointments a
        LEFT JOIN pets p      ON p.id = a.pet_id
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE a.date BETWEEN :from AND :to
          AND a.status NOT IN ('cancelled', 'deleted')
        ORDER BY a.date ASC, a.time ASC
    ");
    $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $grouped[$row['date']][] = $row;
    }

    $calendar = [];
    $current  = new DateTime($dateFrom);
    $end      = new DateTime($dateTo);

    while ($current <= $end) {
        $dateKey = $current->format('Y-m-d');
        $calendar[$dateKey] = [
            'date'   => $dateKey,
            'count'  => count($grouped[$dateKey] ?? []),
            'events' => $grouped[$dateKey]       ?? []
        ];
        $current->modify('+1 day');
    }

    jsonResponse(true, 'Calendar data retrieved.', [
        'year'     => $year,
        'month'    => $month,
        'calendar' => array_values($calendar)
    ]);
}

/* Read: Available Time Slots */
function getAvailableSlots(): never
{
    global $pdo;

    $groomer   = sanitizeString($_GET['groomer']  ?? '');
    $date      = sanitizeString($_GET['date']     ?? '');
    $duration  = sanitizeString($_GET['duration'] ?? '1 hour');
    $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;

    if ($groomer === '') jsonResponse(false, 'groomer is required.',     null, 400);
    if ($date    === '') jsonResponse(false, 'date is required.',        null, 400);
    if (!isValidDate($date)) jsonResponse(false, 'Invalid date format.', null, 422);

    $durationMins = DURATION_MAP[$duration] ?? 60;

    $sql    = "SELECT time, duration FROM appointments
               WHERE groomer = :groomer AND date = :date AND status NOT IN ('cancelled', 'deleted')";
    $params = [':groomer' => $groomer, ':date' => $date];

    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $booked = $stmt->fetchAll();

    $allSlots    = generateTimeSlots('08:00', '18:00', 30);
    $available   = [];
    $unavailable = [];

    foreach ($allSlots as $slot) {
        $slotMins    = timeToMinutes($slot);
        $slotEndMins = $slotMins + $durationMins;

        if ($slotEndMins > 1080) {
            $unavailable[] = ['time' => $slot, 'reason' => 'Too late for this duration'];
            continue;
        }

        $conflict = false;
        foreach ($booked as $b) {
            $bStart = timeToMinutes($b['time']);
            $bEnd   = $bStart + (DURATION_MAP[$b['duration']] ?? 60);

            if ($slotMins < $bEnd && $slotEndMins > $bStart) {
                $conflict = true;
                break;
            }
        }

        if ($conflict) {
            $unavailable[] = ['time' => $slot, 'reason' => 'Already booked'];
        } else {
            $available[] = $slot;
        }
    }

    jsonResponse(true, 'Available slots retrieved.', [
        'groomer'     => $groomer,
        'date'        => $date,
        'duration'    => $duration,
        'available'   => $available,
        'unavailable' => $unavailable,
        'total_slots' => count($allSlots)
    ]);
}

/* Write: Book Appointment */
function bookAppointment(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateAppointmentInput($body);

    $conflict = checkScheduleConflict(
        $pdo,
        $data['groomer'],
        $data['date'],
        $data['time'],
        $data['duration']
    );

    if ($conflict) {
        jsonResponse(false,
            "Schedule conflict: {$data['groomer']} already has an appointment at that time.",
            ['conflict' => $conflict],
            409
        );
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (pet_id, service, groomer, date, time, duration, status, notes, created_by, created_at, updated_at)
        VALUES
            (:pet_id, :service, :groomer, :date, :time, :duration, 'pending', :notes, :created_by, NOW(), NOW())
    ");

    $stmt->execute([
        ':pet_id'     => $data['pet_id'],
        ':service'    => $data['service'],
        ':groomer'    => $data['groomer'],
        ':date'       => $data['date'],
        ':time'       => $data['time'],
        ':duration'   => $data['duration'],
        ':notes'      => $data['notes'] ?: null,
        ':created_by' => currentUserId()
    ]);

    $newId = (int)$pdo->lastInsertId();
    $appt  = fetchAppointmentById($pdo, $newId);

    logAuditAction(
        'APPOINTMENT_CREATE',
        "Booked appointment #{$newId} for pet #{$data['pet_id']} — {$data['service']} on {$data['date']} at {$data['time']}."
    );

    jsonResponse(true, 'Appointment booked successfully.', $appt, 201);
}

/* Write: Update Appointment */
function updateAppointment(int $id): never
{
    global $pdo;

    $existing = fetchAppointmentById($pdo, $id);
    if (!$existing) {
        jsonResponse(false, "Appointment #{$id} not found.", null, 404);
    }

    if ($existing['status'] === 'completed') {
        jsonResponse(false, 'Completed appointments cannot be modified.', null, 409);
    }
    if ($existing['status'] === 'cancelled') {
        jsonResponse(false, 'Cancelled appointments cannot be modified.', null, 409);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validateAppointmentInput($body, $id);

    $conflict = checkScheduleConflict(
        $pdo,
        $data['groomer'],
        $data['date'],
        $data['time'],
        $data['duration'],
        $id
    );

    if ($conflict) {
        jsonResponse(false,
            "Schedule conflict: {$data['groomer']} already has an appointment at that time.",
            ['conflict' => $conflict],
            409
        );
    }

    $pdo->prepare("
        UPDATE appointments
        SET pet_id   = :pet_id,
            service  = :service,
            groomer  = :groomer,
            date     = :date,
            time     = :time,
            duration = :duration,
            notes    = :notes,
            updated_at = NOW()
        WHERE id = :id
    ")->execute([
        ':pet_id'   => $data['pet_id'],
        ':service'  => $data['service'],
        ':groomer'  => $data['groomer'],
        ':date'     => $data['date'],
        ':time'     => $data['time'],
        ':duration' => $data['duration'],
        ':notes'    => $data['notes'] ?: null,
        ':id'       => $id
    ]);

    $updated = fetchAppointmentById($pdo, $id);

    logAuditAction(
        'APPOINTMENT_UPDATE',
        "Updated appointment #{$id} — rescheduled to {$data['date']} at {$data['time']}."
    );

    jsonResponse(true, 'Appointment updated successfully.', $updated);
}

/* Write: Update Status */
function updateStatus(int $id): never
{
    global $pdo;

    $body      = getJsonBody();
    validateCsrfFromBody($body);

    $newStatus = sanitizeString($body['status'] ?? '');

    if (!in_array($newStatus, ALLOWED_STATUSES, true)) {
        jsonResponse(false, 'Invalid status. Allowed: ' . implode(', ', ALLOWED_STATUSES) . '.', null, 422);
    }

    $appt = fetchAppointmentById($pdo, $id);
    if (!$appt) {
        jsonResponse(false, "Appointment #{$id} not found.", null, 404);
    }

    $allowedNext = STATUS_FLOW[$appt['status']] ?? [];
    if (!in_array($newStatus, $allowedNext, true) && $newStatus !== $appt['status']) {
        jsonResponse(false, sprintf(
            'Cannot transition from "%s" to "%s". Allowed next statuses: %s.',
            $appt['status'],
            $newStatus,
            empty($allowedNext) ? 'none' : implode(', ', $allowedNext)
        ), null, 409);
    }

    $pdo->prepare(
        "UPDATE appointments SET status = :status, updated_at = NOW() WHERE id = :id"
    )->execute([':status' => $newStatus, ':id' => $id]);

    $updated = fetchAppointmentById($pdo, $id);

    logAuditAction(
        'APPOINTMENT_STATUS',
        "Appointment #{$id} status changed from '{$appt['status']}' to '{$newStatus}'."
    );

    jsonResponse(true, "Status updated to '{$newStatus}'.", $updated);
}

/* Write: Cancel Appointment */
function cancelAppointment(int $id): never
{
    global $pdo;

    $appt = fetchAppointmentById($pdo, $id);
    if (!$appt) {
        jsonResponse(false, "Appointment #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $pdo->prepare(
        "UPDATE appointments SET status = 'deleted', updated_at = NOW() WHERE id = :id"
    )->execute([':id' => $id]);
    logAuditAction('APPOINTMENT_DELETE', "Soft-deleted appointment #{$id}.");
    jsonResponse(true, "Appointment #{$id} archived.");
}

/* Schedule Conflict Detection */
function checkScheduleConflict(
    PDO    $pdo,
    string $groomer,
    string $date,
    string $time,
    string $duration,
    ?int   $excludeId = null
): ?array {
    $newStart = timeToMinutes($time);
    $newEnd   = $newStart + (DURATION_MAP[$duration] ?? 60);

    $sql    = "SELECT a.id, a.time, a.duration, p.name AS pet_name
               FROM appointments a
               LEFT JOIN pets p ON p.id = a.pet_id
               WHERE a.groomer = :groomer
                 AND a.date    = :date
                 AND a.status NOT IN ('cancelled', 'completed', 'deleted')";
    $params = [':groomer' => $groomer, ':date' => $date];

    if ($excludeId !== null) {
        $sql .= ' AND a.id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetchAll();

    foreach ($existing as $appt) {
        $existStart = timeToMinutes($appt['time']);
        $existEnd   = $existStart + (DURATION_MAP[$appt['duration']] ?? 60);

        if ($newStart < $existEnd && $newEnd > $existStart) {
            return [
                'appointment_id' => (int)$appt['id'],
                'pet_name'       => $appt['pet_name'],
                'time'           => $appt['time'],
                'duration'       => $appt['duration']
            ];
        }
    }

    return null;
}

function timeToMinutes(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

function generateTimeSlots(string $start, string $end, int $intervalMin): array
{
    $slots   = [];
    $current = timeToMinutes($start);
    $endMins = timeToMinutes($end);

    while ($current < $endMins) {
        $h       = intdiv($current, 60);
        $m       = $current % 60;
        $slots[] = sprintf('%02d:%02d', $h, $m);
        $current += $intervalMin;
    }

    return $slots;
}

/* Validation */
function validateAppointmentInput(array $body, ?int $editId = null): array
{
    $errors = [];

    $petId    = (int)($body['pet_id']  ?? 0);
    $service  = sanitizeString($body['service']  ?? '');
    $groomer  = sanitizeString($body['groomer']  ?? '');
    $date     = sanitizeString($body['date']     ?? '');
    $time     = sanitizeString($body['time']     ?? '');
    $duration = sanitizeString($body['duration'] ?? '1 hour');
    $notes    = sanitizeString($body['notes']    ?? '');

    if ($petId <= 0)    $errors[] = 'A valid pet_id is required.';
    if ($groomer === '') $errors[] = 'Groomer is required.';

    if (!in_array($service, SERVICES, true)) {
        $errors[] = 'Invalid service. Allowed: ' . implode(', ', SERVICES) . '.';
    }

    if ($date === '' || !isValidDate($date)) {
        $errors[] = 'A valid date (YYYY-MM-DD) is required.';
    }

    if ($date !== '' && $date < date('Y-m-d') && $editId === null) {
        $errors[] = 'Cannot book an appointment in the past.';
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        $errors[] = 'Time must be in HH:MM format.';
    }

    if (!array_key_exists($duration, DURATION_MAP)) {
        $errors[] = 'Invalid duration. Allowed: ' . implode(', ', array_keys(DURATION_MAP)) . '.';
    }

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    return [
        'pet_id' => $petId,
        'service' => $service,
        'groomer' => $groomer,
        'date' => $date,
        'time' => $time,
        'duration' => $duration,
        'notes' => $notes
    ];
}

/* Enrichment & Fetch Helpers */
function enrichAppointment(array $appt): array
{
    $appt['id']      = (int)$appt['id'];
    $appt['pet_id']  = (int)$appt['pet_id'];
    $appt['owner_id'] = isset($appt['owner_id']) ? (int)$appt['owner_id'] : null;

    $appt['allowed_transitions'] = STATUS_FLOW[$appt['status']] ?? [];
    $appt['is_today']  = $appt['date'] === date('Y-m-d');
    $appt['days_away'] = (int)ceil(
        (strtotime($appt['date']) - strtotime(date('Y-m-d'))) / 86400
    );

    return $appt;
}

function fetchAppointmentById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            a.id, a.pet_id,
            p.name AS pet_name, p.species, p.breed,
            p.owner_id,
            CONCAT(c.first_name,' ',c.last_name) AS owner_name,
            c.contact AS owner_contact,
            a.service, a.groomer, a.date, a.time,
            a.duration, a.status, a.notes, a.created_at, a.updated_at
        FROM appointments a
        LEFT JOIN pets p      ON p.id = a.pet_id
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE a.id = :id AND a.status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichAppointment($row) : null;
}

/* Utility */
function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

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

function isAdmin(): bool
{
    return ($_SESSION['role'] ?? '') === 'Admin';
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
