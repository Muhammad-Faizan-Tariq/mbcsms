<?php
/**
 * MBC SMS - SMS Numbers API
 * Manage number assignments, requests
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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':
                listNumbers();
                break;
            case 'datatable':
                getNumbersDatatable();
                break;
            case 'available':
                getAvailableNumbers();
                break;
            case 'my':
                getMyNumbers();
                break;
            case 'requests':
                getMyRequests();
                break;
            default:
                listNumbers();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'request':
                requestNumbers();
                break;
            case 'assign':
                assignNumbers();
                break;
            case 'unassign':
                unassignNumbers();
                break;
            case 'approve':
                approveRequest();
                break;
            case 'reject':
                rejectRequest();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * List all numbers (manager view)
 */
function listNumbers() {
    global $db, $userId, $role;
    
    $rangeId = (int)($_GET['range_id'] ?? 0);
    
    if ($role === 'manager') {
        if ($rangeId) {
            $stmt = $db->query(
                "SELECT n.*, u.username as assigned_username, r.range_name 
                 FROM sms_numbers n 
                 LEFT JOIN users u ON n.assigned_to = u.id 
                 LEFT JOIN sms_ranges r ON n.range_id = r.id 
                 WHERE n.range_id = ? AND r.manager_id = ?
                 ORDER BY n.number",
                [$rangeId, $userId]
            );
        } else {
            $stmt = $db->query(
                "SELECT n.*, u.username as assigned_username, r.range_name 
                 FROM sms_numbers n 
                 LEFT JOIN users u ON n.assigned_to = u.id 
                 LEFT JOIN sms_ranges r ON n.range_id = r.id 
                 WHERE r.manager_id = ?
                 ORDER BY r.range_name, n.number",
                [$userId]
            );
        }
    } elseif ($role === 'agent') {
        // Agent sees numbers assigned to them or their clients
        $stmt = $db->query(
            "SELECT n.*, u.username as assigned_username, r.range_name 
             FROM sms_numbers n 
             LEFT JOIN users u ON n.assigned_to = u.id 
             LEFT JOIN sms_ranges r ON n.range_id = r.id 
             WHERE n.assigned_to = ? OR n.assigned_to IN (SELECT id FROM users WHERE parent_id = ?)
             ORDER BY r.range_name, n.number",
            [$userId, $userId]
        );
    } else {
        // Client sees only their numbers
        $stmt = $db->query(
            "SELECT n.*, r.range_name 
             FROM sms_numbers n 
             LEFT JOIN sms_ranges r ON n.range_id = r.id 
             WHERE n.assigned_to = ?
             ORDER BY r.range_name, n.number",
            [$userId]
        );
    }
    
    $numbers = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $numbers]);
}

/**
 * Get numbers for DataTables
 */
function getNumbersDatatable() {
    global $db, $userId, $role;
    
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    $search = $_GET['search']['value'] ?? '';
    $rangeId = (int)($_GET['range_id'] ?? 0);
    $assignedTo = (int)($_GET['assigned_to'] ?? 0);
    
    $where = "1=1";
    $params = [];
    
    if ($role === 'manager') {
        $where .= " AND r.manager_id = ?";
        $params[] = $userId;
        
        if ($rangeId) {
            $where .= " AND n.range_id = ?";
            $params[] = $rangeId;
        }
    } elseif ($role === 'agent') {
        $where .= " AND (n.assigned_to = ? OR n.assigned_to IN (SELECT id FROM users WHERE parent_id = ?))";
        $params[] = $userId;
        $params[] = $userId;
    } else {
        $where .= " AND n.assigned_to = ?";
        $params[] = $userId;
    }
    
    if ($assignedTo) {
        $where .= " AND n.assigned_to = ?";
        $params[] = $assignedTo;
    }
    
    if ($search) {
        $where .= " AND (n.number LIKE ? OR r.range_name LIKE ? OR u.username LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Total
    $stmt = $db->query("SELECT COUNT(*) as count FROM sms_numbers n LEFT JOIN sms_ranges r ON n.range_id = r.id LEFT JOIN users u ON n.assigned_to = u.id WHERE $where", $params);
    $recordsTotal = (int)$stmt->fetch()['count'];
    
    // Data
    $stmt = $db->query(
        "SELECT n.*, r.range_name, u.username as assigned_username, u.role as assigned_role
         FROM sms_numbers n 
         LEFT JOIN sms_ranges r ON n.range_id = r.id 
         LEFT JOIN users u ON n.assigned_to = u.id 
         WHERE $where 
         ORDER BY n.number ASC 
         LIMIT $start, $length",
        $params
    );
    $data = $stmt->fetchAll();
    
    $result = [];
    foreach ($data as $row) {
        $status = $row['status'];
        $statusBadge = $status === 'available' ? '<span class="label label-success">Available</span>' :
                      ($status === 'assigned' ? '<span class="label label-info">Assigned</span>' : '<span class="label label-warning">Reserved</span>');
        
        $testBadge = $row['is_test'] ? ' <span class="label label-important">Test</span>' : '';
        
        $result[] = [
            $row['range_name'],
            $row['number'] . $testBadge,
            $row['assigned_username'] ?? '-',
            $statusBadge,
            $row['assigned_at'] ?? '-',
            $role === 'manager' ? 
                '<a href="#" class="btn btn-mini btn-info" onclick="viewNumber(' . $row['id'] . ')"><i class="icon-eye-open"></i></a>' : ''
        ];
    }
    
    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsTotal,
        'data' => $result
    ]);
}

/**
 * Get available numbers for request
 */
function getAvailableNumbers() {
    global $db;
    
    $rangeId = (int)($_GET['range_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 10);
    
    if (!$rangeId) {
        jsonResponse(['error' => 'Range ID required'], 400);
    }
    
    $stmt = $db->query(
        "SELECT n.*, r.range_name, r.currency, r.payout_1_1, r.payout_7_1, r.payout_7_7, r.payout_30_45
         FROM sms_numbers n 
         JOIN sms_ranges r ON n.range_id = r.id 
         WHERE n.range_id = ? AND n.status = 'available' AND r.status = 'active'
         ORDER BY n.number LIMIT ?",
        [$rangeId, $limit]
    );
    
    $numbers = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $numbers]);
}

/**
 * Get my assigned numbers
 */
function getMyNumbers() {
    global $db, $userId;
    
    $stmt = $db->query(
        "SELECT n.*, r.range_name, r.prefix, r.currency, r.payout_1_1, r.payout_7_1, r.payout_7_7, r.payout_30_45
         FROM sms_numbers n 
         JOIN sms_ranges r ON n.range_id = r.id 
         WHERE n.assigned_to = ?
         ORDER BY r.range_name, n.number",
        [$userId]
    );
    
    $numbers = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $numbers]);
}

/**
 * Get my number requests
 */
function getMyRequests() {
    global $db, $userId, $role;
    
    $status = sanitize($_GET['status'] ?? '');
    
    $where = "user_id = ?";
    $params = [$userId];
    
    if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
        $where .= " AND status = ?";
        $params[] = $status;
    }
    
    $stmt = $db->query(
        "SELECT nr.*, r.range_name, r.prefix 
         FROM number_requests nr 
         JOIN sms_ranges r ON nr.range_id = r.id 
         WHERE $where 
         ORDER BY nr.created_at DESC",
        $params
    );
    
    $requests = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $requests]);
}

/**
 * Request numbers (agent/client)
 */
function requestNumbers() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $rangeId = (int)($input['range_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 1);
    $payTerm = sanitize($input['pay_term'] ?? '1/1');
    $notes = sanitize($input['notes'] ?? '');
    
    if (!$rangeId || $quantity < 1) {
        jsonResponse(['error' => 'Range ID and valid quantity required'], 400);
    }
    
    // Check range availability
    $stmt = $db->query(
        "SELECT * FROM sms_ranges WHERE id = ? AND status = 'active' AND available_numbers >= ?",
        [$rangeId, $quantity]
    );
    $range = $stmt->fetch();
    
    if (!$range) {
        jsonResponse(['error' => 'Range not available or insufficient numbers'], 400);
    }
    
    // Create request
    $db->query(
        "INSERT INTO number_requests (user_id, range_id, quantity, pay_term, notes, status) VALUES (?, ?, ?, ?, ?, 'pending')",
        [$userId, $rangeId, $quantity, $payTerm, $notes]
    );
    
    $requestId = $db->lastInsertId();
    
    // Notify manager
    $stmt = $db->query("SELECT id FROM users WHERE role = 'manager' AND id = ?", [$range['manager_id']]);
    $manager = $stmt->fetch();
    
    if ($manager) {
        createNotification(
            $manager['id'],
            'New Number Request',
            "User requested $quantity numbers from range: " . $range['range_name'],
            'info'
        );
    }
    
    logActivity($userId, 'number_request', "Requested $quantity numbers from range: " . $range['range_name']);
    
    jsonResponse(['success' => true, 'message' => 'Request submitted successfully', 'request_id' => $requestId]);
}

/**
 * Assign numbers to user (manager)
 */
function assignNumbers() {
    global $db, $userId, $role;
    
    // Both managers and agents can assign numbers
    // Managers assign to agents/clients, Agents assign to their clients
    if (!in_array($role, ['manager', 'agent'])) {
        jsonResponse(['error' => 'Only managers and agents can assign numbers'], 403);
    }
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $userTo = (int)($input['user_id'] ?? $input['client'] ?? 0);
    $rangeId = (int)($input['range_id'] ?? $input['range'] ?? 0);
    $quantity = (int)($input['quantity'] ?? $input['qty'] ?? 1);
    $numberIds = $input['number_ids'] ?? [];
    $payTerm = sanitize($input['pay_term'] ?? $input['payterm'] ?? '1/1');
    $customPayout = (float)($input['payout'] ?? 0); // Custom payout rate from agent
    
    if (!$userTo || (!$rangeId && empty($numberIds))) {
        jsonResponse(['error' => 'User ID and range or specific numbers required'], 400);
    }
    
    // Verify user exists and is under the assigner
    if ($role === 'manager') {
        $stmt = $db->query(
            "SELECT * FROM users WHERE id = ? AND status = 'active' AND (role = 'agent' OR role = 'client')",
            [$userTo]
        );
    } else {
        // Agent can only assign to their own clients
        $stmt = $db->query(
            "SELECT * FROM users WHERE id = ? AND status = 'active' AND role = 'client' AND parent_id = ?",
            [$userTo, $userId]
        );
    }
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        jsonResponse(['error' => 'Target user not found'], 404);
    }
    
    // Get the payout rate based on pay_term
    // Map payterm values from form to payout columns
    $payTermMap = [
        '1' => '1/1',    // Daily
        '2' => '7/1',    // Weekly
        '3' => '7/7',    // Weekly7
        '4' => '14/1',   // BiWeekly
        '5' => '30/1',   // BiWeekly30
        '6' => '30/15',  // Monthly15
        '7' => '30/30',  // Monthly30
        '8' => '30/45',  // Monthly45
        '9' => '30/60',  // Monthly60
        '1/1' => '1/1',
        '7/1' => '7/1',
        '7/7' => '7/7',
        '30/45' => '30/45'
    ];
    
    $normalizedPayTerm = $payTermMap[$payTerm] ?? '1/1';
    
    $payoutColumn = 'payout_1_1'; // default
    if ($normalizedPayTerm === '7/1') {
        $payoutColumn = 'payout_7_1';
    } elseif ($normalizedPayTerm === '7/7') {
        $payoutColumn = 'payout_7_7';
    } elseif ($normalizedPayTerm === '30/45') {
        $payoutColumn = 'payout_30_45';
    }
    
    // Determine payout rate: custom payout takes precedence
    if ($customPayout > 0) {
        $payoutRate = $customPayout;
    } else {
        // Get payout from range based on pay term
        $stmt = $db->query("SELECT $payoutColumn as payout FROM sms_ranges WHERE id = ?", [$rangeId]);
        $rangePayout = $stmt->fetch();
        $payoutRate = (float)($rangePayout['payout'] ?? 0);
    }
    
    $db->beginTransaction();
    
    try {
        if (!empty($numberIds)) {
            // Assign specific numbers
            foreach ($numberIds as $nid) {
                $db->query(
                    "UPDATE sms_numbers SET assigned_to = ?, assigned_at = NOW(), status = 'assigned', pay_term = ?, payout_rate = ? WHERE id = ? AND status = 'available'",
                    [$userTo, $normalizedPayTerm, $payoutRate, $nid]
                );
            }
        } else {
            // Assign from range - verify range belongs to manager (or agent has access)
            if ($role === 'manager') {
                $stmt = $db->query(
                    "SELECT id FROM sms_ranges WHERE id = ? AND manager_id = ?",
                    [$rangeId, $userId]
                );
            } else {
                // Agent can assign from ranges they have numbers in
                $stmt = $db->query(
                    "SELECT id FROM sms_ranges WHERE id = ? AND status = 'active'",
                    [$rangeId]
                );
            }
            
            if (!$stmt->fetch()) {
                $db->rollBack();
                jsonResponse(['error' => 'Range not found or access denied'], 404);
            }
            
            $stmt = $db->query(
                "SELECT id FROM sms_numbers WHERE range_id = ? AND status = 'available' LIMIT ?",
                [$rangeId, $quantity]
            );
            $numbers = $stmt->fetchAll();
            
            if (count($numbers) < $quantity) {
                $db->rollBack();
                jsonResponse(['error' => 'Not enough available numbers'], 400);
            }
            
            foreach ($numbers as $n) {
                $db->query(
                    "UPDATE sms_numbers SET assigned_to = ?, assigned_at = NOW(), status = 'assigned', pay_term = ?, payout_rate = ? WHERE id = ?",
                    [$userTo, $normalizedPayTerm, $payoutRate, $n['id']]
                );
            }
            
            // Update range available count
            $db->query(
                "UPDATE sms_ranges SET available_numbers = available_numbers - ? WHERE id = ?",
                [$quantity, $rangeId]
            );
        }
        
        $db->commit();
        
        createNotification($userTo, 'Numbers Assigned', "You have been assigned numbers", 'success');
        logActivity($userId, 'numbers_assigned', "Assigned numbers to user: " . $targetUser['username']);
        
        jsonResponse(['success' => true, 'message' => 'Numbers assigned successfully']);
        
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Failed to assign numbers'], 500);
    }
}

/**
 * Unassign numbers
 */
function unassignNumbers() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    $numberIds = $input['number_ids'] ?? [];
    
    if (empty($numberIds)) {
        jsonResponse(['error' => 'Number IDs required'], 400);
    }
    
    $db->beginTransaction();
    
    try {
        foreach ($numberIds as $nid) {
            if ($role === 'manager') {
                $db->query(
                    "UPDATE sms_numbers SET assigned_to = NULL, assigned_at = NULL, status = 'available' WHERE id = ?",
                    [$nid]
                );
            } else {
                // Agent/Client can only unassign their own numbers
                $db->query(
                    "UPDATE sms_numbers SET assigned_to = NULL, assigned_at = NULL, status = 'available' WHERE id = ? AND assigned_to = ?",
                    [$nid, $userId]
                );
            }
        }
        
        // Update range counts
        $stmt = $db->query(
            "SELECT DISTINCT range_id FROM sms_numbers WHERE id IN (" . implode(',', array_fill(0, count($numberIds), '?')) . ")",
            $numberIds
        );
        $ranges = $stmt->fetchAll();
        
        foreach ($ranges as $r) {
            $stmt = $db->query(
                "SELECT COUNT(*) as available FROM sms_numbers WHERE range_id = ? AND status = 'available'",
                [$r['range_id']]
            );
            $count = $stmt->fetch();
            $db->query("UPDATE sms_ranges SET available_numbers = ? WHERE id = ?", [$count['available'], $r['range_id']]);
        }
        
        $db->commit();
        
        logActivity($userId, 'numbers_unassigned', "Unassigned " . count($numberIds) . " numbers");
        
        jsonResponse(['success' => true, 'message' => 'Numbers unassigned successfully']);
        
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Failed to unassign numbers'], 500);
    }
}

/**
 * Approve number request (manager)
 */
function approveRequest() {
    global $db, $userId, $role;
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can approve requests'], 403);
    }
    
    $input = getJsonInput();
    $requestId = (int)($input['request_id'] ?? 0);
    
    if (!$requestId) {
        jsonResponse(['error' => 'Request ID required'], 400);
    }
    
    $stmt = $db->query("SELECT * FROM number_requests WHERE id = ? AND status = 'pending'", [$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        jsonResponse(['error' => 'Request not found or already processed'], 404);
    }
    
    // Assign numbers
    $stmt = $db->query(
        "SELECT id FROM sms_numbers WHERE range_id = ? AND status = 'available' LIMIT ?",
        [$request['range_id'], $request['quantity']]
    );
    $numbers = $stmt->fetchAll();
    
    if (count($numbers) < $request['quantity']) {
        jsonResponse(['error' => 'Not enough available numbers'], 400);
    }
    
    $db->beginTransaction();

    try {
        // Get the payout rate based on pay_term
        $payoutColumn = 'payout_1_1'; // default
        if ($request['pay_term'] === '7/1') {
            $payoutColumn = 'payout_7_1';
        } elseif ($request['pay_term'] === '7/7') {
            $payoutColumn = 'payout_7_7';
        } elseif ($request['pay_term'] === '30/45') {
            $payoutColumn = 'payout_30_45';
        }

        foreach ($numbers as $n) {
            $db->query(
                "UPDATE sms_numbers SET assigned_to = ?, assigned_at = NOW(), status = 'assigned', pay_term = ?, payout_rate = (SELECT $payoutColumn FROM sms_ranges WHERE id = ?) WHERE id = ?",
                [$request['user_id'], $request['pay_term'], $request['range_id'], $n['id']]
            );
        }
        
        $db->query(
            "UPDATE sms_ranges SET available_numbers = available_numbers - ? WHERE id = ?",
            [$request['quantity'], $request['range_id']]
        );
        
        $db->query(
            "UPDATE number_requests SET status = 'approved', processed_by = ?, processed_at = NOW() WHERE id = ?",
            [$userId, $requestId]
        );
        
        $db->commit();
        
        createNotification($request['user_id'], 'Request Approved', "Your number request has been approved", 'success');
        logActivity($userId, 'request_approved', "Approved request ID: $requestId");
        
        jsonResponse(['success' => true, 'message' => 'Request approved and numbers assigned']);
        
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Failed to process request'], 500);
    }
}

/**
 * Reject number request
 */
function rejectRequest() {
    global $db, $userId, $role;
    
    if ($role !== 'manager') {
        jsonResponse(['error' => 'Only managers can reject requests'], 403);
    }
    
    $input = getJsonInput();
    $requestId = (int)($input['request_id'] ?? 0);
    $reason = sanitize($input['reason'] ?? '');
    
    if (!$requestId) {
        jsonResponse(['error' => 'Request ID required'], 400);
    }
    
    $stmt = $db->query("SELECT * FROM number_requests WHERE id = ? AND status = 'pending'", [$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        jsonResponse(['error' => 'Request not found'], 404);
    }
    
    $db->query(
        "UPDATE number_requests SET status = 'rejected', notes = CONCAT(notes, ' | Rejection: ?'), processed_by = ?, processed_at = NOW() WHERE id = ?",
        [$reason, $userId, $requestId]
    );
    
    createNotification($request['user_id'], 'Request Rejected', "Your number request has been rejected", 'error');
    logActivity($userId, 'request_rejected', "Rejected request ID: $requestId");
    
    jsonResponse(['success' => true, 'message' => 'Request rejected']);
}
