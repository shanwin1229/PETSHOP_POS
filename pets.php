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
if (!defined('VACC_WARN_DAYS'))   define('VACC_WARN_DAYS',   30);
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 28800);

const ALLOWED_SPECIES = ['Dog', 'Cat', 'Rabbit', 'Bird', 'Hamster', 'Fish', 'Others'];
const ALLOWED_GENDERS = ['Male', 'Female'];

/* Routing */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

switch ($method) {

    case 'GET':
        switch ($action) {
            case 'by_owner':  getPetsByOwner();     break;
            case 'vacc_due':  getPetsWithDueVacc(); break;
            default:
                $id ? getPet($id) : getAllPets();
        }
        break;

    case 'POST':
        requireRole(['Admin', 'Cashier']);
        switch ($action) {
            case 'add_vacc': addVaccination(); break;
            default:         createPet();
        }
        break;

    case 'PUT':
        requireRole(['Admin', 'Cashier']);
        switch ($action) {
            case 'update_vacc':
                updateVaccination();
                break;
            default:
                if (!$id) jsonResponse(false, 'Pet ID is required.', null, 400);
                updatePet($id);
        }
        break;

    case 'DELETE':
        requireRole(['Admin']);
        switch ($action) {
            case 'delete_vacc': deleteVaccination(); break;
            default:
                if (!$id) jsonResponse(false, 'Pet ID is required.', null, 400);
                deletePet($id);
        }
        break;

    default:
        jsonResponse(false, 'Method not allowed.', null, 405);
}

/* Read: Get All Pets */
function getAllPets(): never
{
    global $pdo;

    $search  = sanitizeString($_GET['search']  ?? '');
    $species = sanitizeString($_GET['species'] ?? '');
    $gender  = sanitizeString($_GET['gender']  ?? '');
    $ownerId = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;
    $sortRaw = sanitizeString($_GET['sort']     ?? 'name_asc');
    $page    = max(1, (int)($_GET['page']   ?? 1));
    $limit   = min(1000, max(1, (int)($_GET['limit'] ?? 12)));
    $offset  = ($page - 1) * $limit;

    $sortMap = [
        'name_asc'  => 'p.name ASC',
        'name_desc' => 'p.name DESC',
        'owner_asc' => 'c.first_name ASC, c.last_name ASC',
        'recent'    => 'p.created_at DESC',
        'species'   => 'p.species ASC, p.name ASC'
    ];
    $orderBy = $sortMap[$sortRaw] ?? 'p.name ASC';

    $where  = ["p.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $where[]           = "(p.name LIKE :search
                                OR p.breed LIKE :search
                                OR CONCAT(c.first_name,' ',c.last_name) LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($species !== '' && in_array($species, ALLOWED_SPECIES, true)) {
        $where[]            = 'p.species = :species';
        $params[':species'] = $species;
    }

    if ($gender !== '' && in_array($gender, ALLOWED_GENDERS, true)) {
        $where[]           = 'p.gender = :gender';
        $params[':gender'] = $gender;
    }

    if ($ownerId !== null) {
        $where[]             = 'p.owner_id = :owner_id';
        $params[':owner_id'] = $ownerId;
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM pets p
         LEFT JOIN customers c ON c.id = p.owner_id
         WHERE {$whereSQL}"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            p.id,
            p.name,
            p.species,
            p.breed,
            p.age,
            p.gender,
            p.color,
            p.birthdate,
            p.weight,
            p.owner_id,
            CONCAT(c.first_name, ' ', c.last_name) AS owner_name,
            c.contact                               AS owner_contact,
            p.notes,
            p.status,
            p.created_at
        FROM pets p
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

    $pets = $stmt->fetchAll();
    // The Pets UI edits and displays vaccination rows, so return the records
    // with the list instead of only aggregate counts.
    $pets = array_map(fn($pet) => enrichPet($pdo, $pet, true), $pets);

    jsonResponse(true, 'Pets retrieved.', [
        'pets'       => $pets,
        'pagination' => buildPagination($total, $page, $limit)
    ]);
}

/* Read: Get Single Pet */
function getPet(int $id): never
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            p.id, p.name, p.species, p.breed, p.age, p.gender,
            p.color, p.birthdate, p.weight, p.owner_id, p.notes, p.status, p.created_at,
            CONCAT(c.first_name,' ',c.last_name) AS owner_name,
            c.contact                            AS owner_contact,
            c.email                              AS owner_email
        FROM pets p
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE p.id = :id AND p.status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $pet = $stmt->fetch();

    if (!$pet) {
        jsonResponse(false, "Pet #{$id} not found.", null, 404);
    }

    jsonResponse(true, 'Pet retrieved.', enrichPet($pdo, $pet, true));
}

/* Read: Pets By Owner */
function getPetsByOwner(): never
{
    global $pdo;

    $ownerId = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
    if ($ownerId <= 0) {
        jsonResponse(false, 'owner_id is required.', null, 400);
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.species, p.breed, p.age, p.gender,
               p.color, p.birthdate, p.weight, p.owner_id, p.notes, p.status, p.created_at,
               CONCAT(c.first_name,' ',c.last_name) AS owner_name,
               c.contact AS owner_contact
        FROM pets p
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE p.owner_id = :owner_id AND p.status != 'deleted'
        ORDER BY p.name ASC
    ");
    $stmt->execute([':owner_id' => $ownerId]);
    $pets = $stmt->fetchAll();
    $pets = array_map(fn($pet) => enrichPet($pdo, $pet, false), $pets);

    jsonResponse(true, count($pets) . ' pet(s) found.', [
        'owner_id' => $ownerId,
        'pets'     => $pets
    ]);
}

/* Read: Pets With Due Vaccinations */
function getPetsWithDueVacc(): never
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            p.id            AS pet_id,
            p.name          AS pet_name,
            p.species,
            p.owner_id,
            CONCAT(c.first_name,' ',c.last_name) AS owner_name,
            c.contact       AS owner_contact,
            v.id            AS vacc_id,
            v.name          AS vaccine_name,
            v.date          AS date_given,
            v.due_date,
            v.status,
            DATEDIFF(v.due_date, CURDATE()) AS days_until_due
        FROM vaccinations v
        JOIN pets p     ON p.id = v.pet_id
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE p.status != 'deleted'
          AND v.status != 'done'
          AND v.due_date IS NOT NULL
          AND v.due_date <= DATE_ADD(CURDATE(), INTERVAL :warn_days DAY)
        ORDER BY v.due_date ASC
    ");
    $stmt->execute([':warn_days' => VACC_WARN_DAYS]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['days_until_due'] = (int)$row['days_until_due'];
        $row['vacc_status']    = computeVaccStatus($row['due_date']);
    }
    unset($row);

    jsonResponse(true, count($rows) . ' due vaccination(s) found.', [
        'vaccinations' => $rows,
        'count'        => count($rows)
    ]);
}

/* Write: Create Pet */
function createPet(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validatePetInput($body);

    try {
        $petId = db_transaction(function (PDO $pdo) use ($data) {
            $pdo->prepare("
                INSERT INTO pets
                    (name, species, breed, age, gender, color,
                     birthdate, weight, owner_id, notes,
                     status, created_at, updated_at)
                VALUES
                    (:name, :species, :breed, :age, :gender, :color,
                     :birthdate, :weight, :owner_id, :notes,
                     'active', NOW(), NOW())
            ")->execute([
                ':name'      => $data['name'],
                ':species'   => $data['species'],
                ':breed'     => $data['breed']     ?: null,
                ':age'       => $data['age'],
                ':gender'    => $data['gender'],
                ':color'     => $data['color']     ?: null,
                ':birthdate' => $data['birthdate'] ?: null,
                ':weight'    => $data['weight']    ?: null,
                ':owner_id'  => $data['owner_id'],
                ':notes'     => $data['notes']     ?: null
            ]);

            $petId = (int)$pdo->lastInsertId();

            if (!empty($data['vaccinations'])) {
                insertVaccinations($pdo, $petId, $data['vaccinations']);
            }

            return $petId;
        });
    } catch (Throwable $e) {
        error_log('[PAWPOS] Create pet error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to register pet. Please try again.', null, 500);
    }

    $pet = fetchPetById($pdo, $petId);

    logAuditAction('PET_CREATE', "Registered pet '{$data['name']}' (ID: {$petId}), owner #{$data['owner_id']}.");

    jsonResponse(true, "Pet '{$data['name']}' registered successfully.", $pet, 201);
}

/* Write: Update Pet */
function updatePet(int $id): never
{
    global $pdo;

    $existing = fetchPetById($pdo, $id);
    if (!$existing) {
        jsonResponse(false, "Pet #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $data = validatePetInput($body, $id);

    try {
        db_transaction(function (PDO $pdo) use ($id, $data) {
            $pdo->prepare("
                UPDATE pets
                SET name      = :name,
                    species   = :species,
                    breed     = :breed,
                    age       = :age,
                    gender    = :gender,
                    color     = :color,
                    birthdate = :birthdate,
                    weight    = :weight,
                    owner_id  = :owner_id,
                    notes     = :notes,
                    updated_at= NOW()
                WHERE id = :id
            ")->execute([
                ':name'      => $data['name'],
                ':species'   => $data['species'],
                ':breed'     => $data['breed']     ?: null,
                ':age'       => $data['age'],
                ':gender'    => $data['gender'],
                ':color'     => $data['color']     ?: null,
                ':birthdate' => $data['birthdate'] ?: null,
                ':weight'    => $data['weight']    ?: null,
                ':owner_id'  => $data['owner_id'],
                ':notes'     => $data['notes']     ?: null,
                ':id'        => $id
            ]);

            if (array_key_exists('vaccinations', $data)) {
                $pdo->prepare('DELETE FROM vaccinations WHERE pet_id = :id')
                    ->execute([':id' => $id]);
                if (!empty($data['vaccinations'])) {
                    insertVaccinations($pdo, $id, $data['vaccinations']);
                }
            }
        });
    } catch (Throwable $e) {
        error_log('[PAWPOS] Update pet error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to update pet record. Please try again.', null, 500);
    }

    $updated = fetchPetById($pdo, $id);

    logAuditAction('PET_UPDATE', "Updated pet '{$data['name']}' (ID: {$id}).");

    jsonResponse(true, "Pet '{$data['name']}' updated successfully.", $updated);
}

/* Write: Delete Pet */
function deletePet(int $id): never
{
    global $pdo;

    $pet = fetchPetById($pdo, $id);
    if (!$pet) {
        jsonResponse(false, "Pet #{$id} not found.", null, 404);
    }

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $pdo->prepare("UPDATE pets SET status = 'deleted', updated_at = NOW() WHERE id = :id")
        ->execute([':id' => $id]);

    logAuditAction('PET_DELETE', "Deleted pet '{$pet['name']}' (ID: {$id}).");

    jsonResponse(true, "Pet '{$pet['name']}' record deleted.");
}

/* Vaccination Crud */
function addVaccination(): never
{
    global $pdo;

    $body = getJsonBody();
    validateCsrfFromBody($body);

    $petId    = (int)($body['pet_id'] ?? 0);
    $vaccData = validateVaccInput($body);

    if ($petId <= 0) jsonResponse(false, 'A valid pet_id is required.', null, 422);

    $pet = fetchPetById($pdo, $petId);
    if (!$pet) {
        jsonResponse(false, "Pet #{$petId} not found.", null, 404);
    }

    $stmt = $pdo->prepare("
        INSERT INTO vaccinations (pet_id, name, date, due_date, status, created_at)
        VALUES (:pet_id, :name, :date, :due_date, :status, NOW())
    ");
    $stmt->execute([
        ':pet_id'   => $petId,
        ':name'     => $vaccData['name'],
        ':date'     => $vaccData['date']     ?: null,
        ':due_date' => $vaccData['due_date'] ?: null,
        ':status'   => $vaccData['status']
    ]);

    $vaccId = (int)$pdo->lastInsertId();

    logAuditAction(
        'VACC_ADD',
        "Added vaccination '{$vaccData['name']}' for pet #{$petId} (vacc ID: {$vaccId})."
    );

    jsonResponse(true, "Vaccination '{$vaccData['name']}' added.", [
        'id'      => $vaccId,
        'pet_id'  => $petId,
        ...$vaccData
    ], 201);
}

function updateVaccination(): never
{
    global $pdo;

    $body   = getJsonBody();
    validateCsrfFromBody($body);

    $vaccId   = (int)($body['vacc_id'] ?? 0);
    $vaccData = validateVaccInput($body);

    if ($vaccId <= 0) jsonResponse(false, 'A valid vacc_id is required.', null, 422);

    $check = $pdo->prepare('SELECT id FROM vaccinations WHERE id = :id LIMIT 1');
    $check->execute([':id' => $vaccId]);
    if (!$check->fetch()) {
        jsonResponse(false, "Vaccination #{$vaccId} not found.", null, 404);
    }

    $pdo->prepare("
        UPDATE vaccinations
        SET name     = :name,
            date     = :date,
            due_date = :due_date,
            status   = :status
        WHERE id = :id
    ")->execute([
        ':name'     => $vaccData['name'],
        ':date'     => $vaccData['date']     ?: null,
        ':due_date' => $vaccData['due_date'] ?: null,
        ':status'   => $vaccData['status'],
        ':id'       => $vaccId
    ]);

    logAuditAction('VACC_UPDATE', "Updated vaccination #{$vaccId}.");

    jsonResponse(true, "Vaccination #{$vaccId} updated.", ['id' => $vaccId, ...$vaccData]);
}

function deleteVaccination(): never
{
    global $pdo;

    $body   = getJsonBody();
    validateCsrfFromBody($body);

    $vaccId = (int)($body['vacc_id'] ?? 0);
    if ($vaccId <= 0) jsonResponse(false, 'A valid vacc_id is required.', null, 422);

    $stmt = $pdo->prepare('SELECT id, name FROM vaccinations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $vaccId]);
    $vacc = $stmt->fetch();

    if (!$vacc) {
        jsonResponse(false, "Vaccination #{$vaccId} not found.", null, 404);
    }

    $pdo->prepare('DELETE FROM vaccinations WHERE id = :id')->execute([':id' => $vaccId]);

    logAuditAction('VACC_DELETE', "Deleted vaccination '{$vacc['name']}' (ID: {$vaccId}).");

    jsonResponse(true, "Vaccination '{$vacc['name']}' deleted.");
}

/* Vaccination Helpers */
function insertVaccinations(PDO $pdo, int $petId, array $vaccinations): void
{
    $stmt = $pdo->prepare("
        INSERT INTO vaccinations (pet_id, name, date, due_date, status, created_at)
        VALUES (:pet_id, :name, :date, :due_date, :status, NOW())
    ");

    foreach ($vaccinations as $vacc) {
        $name    = sanitizeString($vacc['name']     ?? '');
        $date    = sanitizeString($vacc['date']     ?? '');
        $dueDate = sanitizeString($vacc['due_date'] ?? $vacc['due'] ?? '');
        $status  = in_array($vacc['status'] ?? '', ['done','due'], true)
                   ? $vacc['status'] : 'due';

        if ($name === '') continue;

        $stmt->execute([
            ':pet_id'   => $petId,
            ':name'     => $name,
            ':date'     => $date    ?: null,
            ':due_date' => $dueDate ?: null,
            ':status'   => $status
        ]);
    }
}

function getVaccinations(PDO $pdo, int $petId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, date, due_date, status
         FROM vaccinations
         WHERE pet_id = :pid
         ORDER BY due_date ASC, created_at ASC'
    );
    $stmt->execute([':pid' => $petId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id']          = (int)$row['id'];
        $row['vacc_status'] = computeVaccStatus($row['due_date']);
        $row['days_to_due'] = $row['due_date']
            ? (int)ceil((strtotime($row['due_date']) - time()) / 86400)
            : null;
    }
    unset($row);

    return $rows;
}

function computeVaccStatus(?string $dueDate): string
{
    if (!$dueDate) return 'none';
    $daysLeft = (int)ceil((strtotime($dueDate) - time()) / 86400);
    if ($daysLeft < 0)               return 'overdue';
    if ($daysLeft <= VACC_WARN_DAYS) return 'upcoming';
    return 'ok';
}

function getVaccSummary(array $vaccinations): array
{
    $done     = 0;
    $due      = 0;
    $overdue  = 0;
    $upcoming = 0;

    foreach ($vaccinations as $v) {
        $status = $v['vacc_status'] ?? computeVaccStatus($v['due_date']);
        match ($status) {
            'ok'       => $done++,
            'due'      => $due++,
            'overdue'  => $overdue++,
            'upcoming' => $upcoming++,
            default    => null
        };
    }

    return [
        'total'    => count($vaccinations),
        'done'     => $done,
        'due'      => $due,
        'overdue'  => $overdue,
        'upcoming' => $upcoming
    ];
}

/* Validation */
function validatePetInput(array $body, ?int $editId = null): array
{
    $errors = [];

    $name      = sanitizeString($body['name']      ?? '');
    $species   = sanitizeString($body['species']   ?? '');
    $breed     = sanitizeString($body['breed']     ?? '');
    $age       = '';
    $gender    = sanitizeString($body['gender']    ?? '');
    $color     = sanitizeString($body['color']     ?? '');
    $birthdate = sanitizeString($body['birthdate'] ?? '');
    $weight    = isset($body['weight']) && $body['weight'] !== '' ? (float)$body['weight'] : null;
    $ownerId   = (int)($body['owner_id'] ?? 0);
    $notes     = sanitizeString($body['notes']     ?? '');
    $vaccinations = isset($body['vaccinations']) && is_array($body['vaccinations'])
                    ? $body['vaccinations'] : null;

    if ($name === '') $errors[] = 'Pet name is required.';
    if (strlen($name) > 100) $errors[] = 'Pet name must be 100 characters or fewer.';

    if (!in_array($species, ALLOWED_SPECIES, true)) {
        $errors[] = 'Species must be one of: ' . implode(', ', ALLOWED_SPECIES) . '.';
    }

    if (!in_array($gender, ALLOWED_GENDERS, true)) {
        $errors[] = 'Gender must be "Male" or "Female".';
    }

    if ($ownerId <= 0) $errors[] = 'A valid owner_id is required.';

    if ($birthdate === '') {
        $errors[] = 'Birthdate is required.';
    } elseif (!isValidDate($birthdate)) {
        $errors[] = 'Birthdate must be in YYYY-MM-DD format.';
    } elseif ($birthdate > date('Y-m-d')) {
        $errors[] = 'Birthdate cannot be in the future.';
    } else {
        $age = formatAgeFromBirthdate($birthdate);
    }

    if ($weight !== null && $weight < 0) {
        $errors[] = 'Weight cannot be negative.';
    }

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    return [
        'name' => $name,
        'species' => $species,
        'breed' => $breed,
        'age' => $age,
        'gender' => $gender,
        'color' => $color,
        'birthdate' => $birthdate,
        'weight' => $weight,
        'owner_id' => $ownerId,
        'notes' => $notes,
        'vaccinations' => $vaccinations
    ];
}

function validateVaccInput(array $body): array
{
    $errors  = [];
    $name    = sanitizeString($body['name']     ?? '');
    $date    = sanitizeString($body['date']     ?? '');
    $dueDate = sanitizeString($body['due_date'] ?? $body['due'] ?? '');
    $status  = sanitizeString($body['status']   ?? 'due');

    if ($name === '') $errors[] = 'Vaccine name is required.';

    if ($date !== '' && !isValidDate($date)) {
        $errors[] = 'date must be in YYYY-MM-DD format.';
    }

    if ($dueDate !== '' && !isValidDate($dueDate)) {
        $errors[] = 'due_date must be in YYYY-MM-DD format.';
    }

    if (!in_array($status, ['done', 'due'], true)) {
        $errors[] = 'Status must be "done" or "due".';
    }

    if ($errors) {
        jsonResponse(false, implode(' ', $errors), ['errors' => $errors], 422);
    }

    return [
        'name' => $name,
        'date' => $date,
        'due_date' => $dueDate,
        'status' => $status
    ];
}

/* Enrichment & Fetch Helpers */
function enrichPet(PDO $pdo, array $pet, bool $includeVacc = false): array
{
    $pet['id']       = (int)$pet['id'];
    $pet['owner_id'] = (int)$pet['owner_id'];
    $pet['weight']   = $pet['weight'] !== null ? (float)$pet['weight'] : null;
    if (!empty($pet['birthdate']) && isValidDate((string)$pet['birthdate'])) {
        $pet['age'] = formatAgeFromBirthdate((string)$pet['birthdate']);
    }

    if ($includeVacc) {
        $vaccs = getVaccinations($pdo, $pet['id']);
        $pet['vaccinations'] = $vaccs;
        $pet['vacc_summary'] = getVaccSummary($vaccs);
    } else {
        $countStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done
             FROM vaccinations WHERE pet_id = :id"
        );
        $countStmt->execute([':id' => $pet['id']]);
        $counts = $countStmt->fetch();
        $pet['vacc_total'] = (int)$counts['total'];
        $pet['vacc_done']  = (int)$counts['done'];
    }

    return $pet;
}

function fetchPetById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.species, p.breed, p.age, p.gender,
               p.color, p.birthdate, p.weight, p.owner_id, p.notes, p.status, p.created_at,
               CONCAT(c.first_name,' ',c.last_name) AS owner_name,
               c.contact AS owner_contact
        FROM pets p
        LEFT JOIN customers c ON c.id = p.owner_id
        WHERE p.id = :id AND p.status != 'deleted'
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichPet($pdo, $row, true) : null;
}

/* Utility */
function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function formatAgeFromBirthdate(string $birthdate): string
{
    $birth = new DateTimeImmutable($birthdate);
    $today = new DateTimeImmutable('today');
    $diff  = $birth->diff($today);

    if ($diff->y > 0) {
        return $diff->y . ' year' . ($diff->y === 1 ? '' : 's')
            . ($diff->m > 0 ? ' ' . $diff->m . ' month' . ($diff->m === 1 ? '' : 's') : '');
    }
    if ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m === 1 ? '' : 's');
    }
    return $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
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
