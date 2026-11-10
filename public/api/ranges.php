<?php
/**
 * MBC SMS - SMS Ranges API
 * Manager: Create, Read, Update, Delete SMS ranges
 */

require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$db = Database::getInstance();

// Only manager can manage ranges
if ($role !== 'manager' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Only managers can manage ranges'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':
                listRanges();
                break;
            case 'get':
                getRange();
                break;
            case 'datatable':
                getRangesDatatable();
                break;
            case 'dropdown':
                getRangesDropdown();
                break;
            default:
                listRanges();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create':
                createRange();
                break;
            case 'update':
                updateRange();
                break;
            case 'delete':
                deleteRange();
                break;
            case 'upload':
                uploadNumbers();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * List all ranges (for current manager)
 */
function listRanges() {
    global $db, $userId, $role;
    
    if ($role === 'manager') {
        $stmt = $db->query(
            "SELECT r.*, 
                    (SELECT COUNT(*) FROM sms_numbers WHERE range_id = r.id) as total_numbers,
                    (SELECT COUNT(*) FROM sms_numbers WHERE range_id = r.id AND status = 'available') as available_numbers
             FROM sms_ranges r WHERE r.manager_id = ? ORDER BY r.created_at DESC",
            [$userId]
        );
    } else {
        // Agents and clients see available ranges
        $stmt = $db->query(
            "SELECT id, range_name, prefix, currency, payout_1_1, payout_7_1, payout_7_7, payout_30_45, 
                    available_numbers, status 
             FROM sms_ranges WHERE status = 'active' AND available_numbers > 0 ORDER BY range_name"
        );
    }
    
    $ranges = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $ranges]);
}

/**
 * Get single range details
 */
function getRange() {
    global $db, $userId, $role;
    
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'Range ID required'], 400);
    }
    
    if ($role === 'manager') {
        $stmt = $db->query(
            "SELECT * FROM sms_ranges WHERE id = ? AND manager_id = ?",
            [$id, $userId]
        );
    } else {
        $stmt = $db->query(
            "SELECT id, range_name, prefix, currency, payout_1_1, payout_7_1, payout_7_7, payout_30_45, status 
             FROM sms_ranges WHERE id = ? AND status = 'active'",
            [$id]
        );
    }
    
    $range = $stmt->fetch();
    if (!$range) {
        jsonResponse(['error' => 'Range not found'], 404);
    }
    
    jsonResponse(['success' => true, 'data' => $range]);
}

/**
 * Get ranges for DataTables
 */
function getRangesDatatable() {
    global $db, $userId;
    
    // DataTables parameters
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    $search = $_GET['search']['value'] ?? '';
    $orderColumn = (int)($_GET['order'][0]['column'] ?? 0);
    $orderDir = $_GET['order'][0]['dir'] ?? 'asc';
    
    $columns = ['range_name', 'prefix', 'total_numbers', 'test_number', 'currency', 'payout_1_1', 'payout_7_1', 'payout_7_7', 'payout_30_45', 'memo'];
    $orderBy = $columns[$orderColumn] ?? 'range_name';
    
    // Total records
    $stmt = $db->query("SELECT COUNT(*) as count FROM sms_ranges WHERE manager_id = ?", [$userId]);
    $recordsTotal = (int)$stmt->fetch()['count'];
    
    // Filtered records
    $where = "manager_id = ?";
    $params = [$userId];
    if ($search) {
        $where .= " AND (range_name LIKE ? OR prefix LIKE ? OR memo LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM sms_ranges WHERE $where", $params);
    $recordsFiltered = (int)$stmt->fetch()['count'];
    
    // Get data
    $stmt = $db->query(
        "SELECT r.*, 
                (SELECT COUNT(*) FROM sms_numbers WHERE range_id = r.id) as total_numbers,
                (SELECT COUNT(*) FROM sms_numbers WHERE range_id = r.id AND status = 'available') as available_numbers
         FROM sms_ranges r WHERE $where ORDER BY $orderBy $orderDir LIMIT $start, $length",
        $params
    );
    $data = $stmt->fetchAll();
    
    // Format data
    $result = [];
    foreach ($data as $row) {
        $result[] = [
            $row['range_name'],
            $row['prefix'],
            $row['total_numbers'],
            $row['test_number'],
            $row['currency'],
            $row['payout_1_1'] ?? 'N/A',
            $row['payout_7_1'] ?? 'N/A',
            $row['payout_7_7'] ?? 'N/A',
            $row['payout_30_45'] ?? 'N/A',
            $row['memo'] ?? '',
            '<a href="#" class="btn btn-mini btn-info" onclick="viewNumbers(' . $row['id'] . ')"><i class="icon-eye-open"></i></a> ' .
            '<a href="#" class="btn btn-mini btn-warning" onclick="editRange(' . $row['id'] . ')"><i class="icon-edit"></i></a> ' .
            '<a href="#" class="btn btn-mini btn-danger" onclick="deleteRange(' . $row['id'] . ')"><i class="icon-trash"></i></a>'
        ];
    }
    
    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $result
    ]);
}

/**
 * Get ranges for dropdown
 */
function getRangesDropdown() {
    global $db, $userId, $role;
    
    $search = $_GET['q'] ?? '';
    $page = (int)($_GET['page'] ?? 1);
    $max = (int)($_GET['max'] ?? 25);
    $offset = ($page - 1) * $max;
    
    if ($role === 'manager') {
        $where = "manager_id = ?";
        $params = [$userId];
    } else {
        $where = "status = 'active' AND available_numbers > 0";
        $params = [];
    }
    
    if ($search) {
        $where .= " AND (range_name LIKE ? OR prefix LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $stmt = $db->query(
        "SELECT id, range_name as title FROM sms_ranges WHERE $where ORDER BY range_name LIMIT $max OFFSET $offset",
        $params
    );
    $results = $stmt->fetchAll();
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM sms_ranges WHERE $where", $params);
    $total = (int)$stmt->fetch()['count'];
    
    jsonResponse([
        'results' => array_map(function($r) {
            return ['id' => $r['id'], 'title' => $r['title']];
        }, $results),
        'pagination' => ['more' => ($offset + $max) < $total]
    ]);
}

/**
 * Create new range
 */
function createRange() {
    global $db, $userId;
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $rangeName = sanitize($input['rangename'] ?? '');
    $prefix = sanitize($input['prefix'] ?? '');
    $currency = sanitize($input['currency'] ?? 'USD');
    $payout11 = !empty($input['payout_1_1']) ? (float)$input['payout_1_1'] : null;
    $payout71 = !empty($input['payout_7_1']) ? (float)$input['payout_7_1'] : null;
    $payout77 = !empty($input['payout_7_7']) ? (float)$input['payout_7_7'] : null;
    $payout3045 = !empty($input['payout_30_45']) ? (float)$input['payout_30_45'] : null;
    $testNumber = sanitize($input['testnumber'] ?? '');
    $memo = sanitize($input['memo'] ?? '');
    
    if (empty($rangeName) || empty($prefix) || empty($currency)) {
        jsonResponse(['error' => 'Range name, prefix, and currency are required'], 400);
    }
    
    if ($payout11 === null) {
        jsonResponse(['error' => '1/1 payout is required'], 400);
    }
    
    $db->query(
        "INSERT INTO sms_ranges (manager_id, range_name, prefix, currency, payout_1_1, payout_7_1, payout_7_7, payout_30_45, test_number, memo, status) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
        [$userId, $rangeName, $prefix, $currency, $payout11, $payout71, $payout77, $payout3045, $testNumber, $memo]
    );
    
    $rangeId = $db->lastInsertId();
    
    logActivity($userId, 'range_created', "Created range: $rangeName (ID: $rangeId)");
    
    jsonResponse(['success' => true, 'message' => 'Range created successfully', 'id' => $rangeId]);
}

/**
 * Update range
 */
function updateRange() {
    global $db, $userId;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'Range ID required'], 400);
    }
    
    // Verify ownership
    $stmt = $db->query("SELECT * FROM sms_ranges WHERE id = ? AND manager_id = ?", [$id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Range not found'], 404);
    }
    
    $fields = [];
    $params = [];
    
    $allowedFields = ['range_name', 'prefix', 'currency', 'payout_1_1', 'payout_7_1', 'payout_7_7', 'payout_30_45', 'test_number', 'memo', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $params[] = sanitize($input[$field]);
        }
    }
    
    if (empty($fields)) {
        jsonResponse(['error' => 'No fields to update'], 400);
    }
    
    $params[] = $id;
    $params[] = $userId;
    
    $db->query(
        "UPDATE sms_ranges SET " . implode(', ', $fields) . " WHERE id = ? AND manager_id = ?",
        $params
    );
    
    logActivity($userId, 'range_updated', "Updated range ID: $id");
    
    jsonResponse(['success' => true, 'message' => 'Range updated successfully']);
}

/**
 * Delete range
 */
function deleteRange() {
    global $db, $userId;
    
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['error' => 'Range ID required'], 400);
    }
    
    // Verify ownership
    $stmt = $db->query("SELECT * FROM sms_ranges WHERE id = ? AND manager_id = ?", [$id, $userId]);
    $range = $stmt->fetch();
    if (!$range) {
        jsonResponse(['error' => 'Range not found'], 404);
    }
    
    // Check if there are assigned numbers
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM sms_numbers WHERE range_id = ? AND status = 'assigned'",
        [$id]
    );
    if ((int)$stmt->fetch()['count'] > 0) {
        jsonResponse(['error' => 'Cannot delete range with assigned numbers'], 400);
    }
    
    $db->query("DELETE FROM sms_ranges WHERE id = ? AND manager_id = ?", [$id, $userId]);
    
    logActivity($userId, 'range_deleted', "Deleted range: " . $range['range_name']);
    
    jsonResponse(['success' => true, 'message' => 'Range deleted successfully']);
}

/**
 * Upload numbers file
 */
function uploadNumbers() {
    global $db, $userId;
    
    $rangeId = (int)($_POST['range_id'] ?? 0);
    if (!$rangeId) {
        jsonResponse(['error' => 'Range ID required'], 400);
    }
    
    // Verify ownership
    $stmt = $db->query("SELECT * FROM sms_ranges WHERE id = ? AND manager_id = ?", [$rangeId, $userId]);
    $range = $stmt->fetch();
    if (!$range) {
        jsonResponse(['error' => 'Range not found'], 404);
    }
    
    if (!isset($_FILES['numbersfile']) || $_FILES['numbersfile']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'No file uploaded'], 400);
    }
    
    $file = $_FILES['numbersfile'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, ['txt', 'csv'])) {
        jsonResponse(['error' => 'Only .txt and .csv files are supported'], 400);
    }
    
    $content = file_get_contents($file['tmp_name']);
    $lines = explode("\n", $content);
    
    $numbers = [];
    $testNumbers = [];
    
    if ($extension === 'csv') {
        // CSV format: number,test_number
        $firstLine = true;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header
            if ($firstLine && (stripos($line, 'number') !== false || stripos($line, 'test') !== false)) {
                $firstLine = false;
                continue;
            }
            $firstLine = false;
            
            $parts = str_getcsv($line);
            if (!empty($parts[0])) {
                $number = trim($parts[0]);
                $isTest = isset($parts[1]) && strtolower(trim($parts[1])) === 'yes';
                $numbers[] = $number;
                if ($isTest) {
                    $testNumbers[] = $number;
                }
            }
        }
    } else {
        // TXT format: one number per line
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $numbers[] = $line;
            }
        }
    }
    
    if (empty($numbers)) {
        jsonResponse(['error' => 'No valid numbers found in file'], 400);
    }
    
    $db->beginTransaction();
    
    try {
        $inserted = 0;
        $duplicates = 0;
        
        foreach ($numbers as $number) {
            // Check if number already exists
            $stmt = $db->query("SELECT id FROM sms_numbers WHERE number = ?", [$number]);
            if ($stmt->fetch()) {
                $duplicates++;
                continue;
            }
            
            $isTest = in_array($number, $testNumbers) ? 1 : 0;
            $db->query(
                "INSERT INTO sms_numbers (range_id, number, is_test, status) VALUES (?, ?, ?, 'available')",
                [$rangeId, $number, $isTest]
            );
            $inserted++;
        }
        
        // Update range counts
        $stmt = $db->query(
            "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available 
             FROM sms_numbers WHERE range_id = ?",
            [$rangeId]
        );
        $counts = $stmt->fetch();
        
        $db->query(
            "UPDATE sms_ranges SET total_numbers = ?, available_numbers = ? WHERE id = ?",
            [$counts['total'], $counts['available'], $rangeId]
        );
        
        // Set test number if none set
        if (empty($range['test_number']) && !empty($testNumbers)) {
            $db->query("UPDATE sms_ranges SET test_number = ? WHERE id = ?", [$testNumbers[0], $rangeId]);
        }
        
        $db->commit();
        
        logActivity($userId, 'numbers_uploaded', "Uploaded $inserted numbers to range: " . $range['range_name']);
        
        jsonResponse([
            'success' => true,
            'message' => "Uploaded $inserted numbers successfully",
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'test_numbers' => $testNumbers
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Failed to upload numbers: ' . $e->getMessage()], 500);
    }
}
