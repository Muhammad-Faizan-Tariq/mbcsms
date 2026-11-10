<?php
/**
 * MBC SMS - Test SMS API
 * Send test SMS messages
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
$action = $_GET['action'] ?? 'send';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'numbers':
                getTestNumbers();
                break;
            case 'history':
                getTestHistory();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'send':
                sendTestSms();
                break;
            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Get numbers available for testing
 */
function getTestNumbers() {
    global $db, $userId, $role;
    
    if ($role === 'manager') {
        // Manager sees all their range numbers
        $stmt = $db->query(
            "SELECT n.id, n.number, n.range_id, r.range_name, r.prefix, r.currency 
             FROM sms_numbers n 
             JOIN sms_ranges r ON n.range_id = r.id 
             WHERE r.manager_id = ? AND n.status = 'active'
             ORDER BY r.range_name, n.number",
            [$userId]
        );
    } elseif ($role === 'agent') {
        // Agent sees their numbers and their clients' numbers
        $stmt = $db->query(
            "SELECT n.id, n.number, n.range_id, r.range_name, r.prefix, r.currency 
             FROM sms_numbers n 
             JOIN sms_ranges r ON n.range_id = r.id 
             WHERE (n.assigned_to = ? OR n.assigned_to IN (SELECT id FROM users WHERE parent_id = ?))
             AND n.status = 'assigned'
             ORDER BY r.range_name, n.number",
            [$userId, $userId]
        );
    } else {
        // Client sees only their assigned numbers
        $stmt = $db->query(
            "SELECT n.id, n.number, n.range_id, r.range_name, r.prefix, r.currency 
             FROM sms_numbers n 
             JOIN sms_ranges r ON n.range_id = r.id 
             WHERE n.assigned_to = ? AND n.status = 'assigned'
             ORDER BY r.range_name, n.number",
            [$userId]
        );
    }
    
    $numbers = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $numbers]);
}

/**
 * Send test SMS
 */
function sendTestSms() {
    global $db, $userId, $role;
    
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    $numberId = (int)($input['number_id'] ?? 0);
    $destination = sanitize($input['destination'] ?? '');
    $message = $input['message'] ?? '';
    
    // Validation
    if (!$numberId) {
        jsonResponse(['error' => 'Number ID required'], 400);
    }
    
    if (!$destination || !preg_match('/^\+?[0-9]{10,15}$/', $destination)) {
        jsonResponse(['error' => 'Valid destination number required (10-15 digits, optional + prefix)'], 400);
    }
    
    if (!$message || strlen($message) < 1) {
        jsonResponse(['error' => 'Message required'], 400);
    }
    
    if (strlen($message) > 1600) {
        jsonResponse(['error' => 'Message too long (max 1600 characters)'], 400);
    }
    
    // Get the number and verify access
    if ($role === 'manager') {
        $stmt = $db->query(
            "SELECT n.*, r.range_name, r.prefix, r.currency, r.manager_id 
             FROM sms_numbers n 
             JOIN sms_ranges r ON n.range_id = r.id 
             WHERE n.id = ?",
            [$numberId]
        );
    } else {
        $stmt = $db->query(
            "SELECT n.*, r.range_name, r.prefix, r.currency 
             FROM sms_numbers n 
             JOIN sms_ranges r ON n.range_id = r.id 
             WHERE n.id = ? AND n.assigned_to = ?",
            [$numberId, $userId]
        );
    }
    
    $number = $stmt->fetch();
    
    if (!$number) {
        jsonResponse(['error' => 'Number not found or access denied'], 404);
    }
    
    // Verify manager access
    if ($role === 'manager' && $number['manager_id'] != $userId) {
        jsonResponse(['error' => 'Access denied'], 403);
    }
    
    // Calculate SMS count (GSM-7 vs UCS-2)
    $smsCount = calculateSmsCount($message);
    
    // Get rate for the number
    $stmt = $db->query(
        "SELECT payout_1_1, payout_7_1, payout_7_7, payout_30_45 FROM sms_ranges WHERE id = ?",
        [$number['range_id']]
    );
    $range = $stmt->fetch();
    
    if (!$range) {
        jsonResponse(['error' => 'Range not found'], 404);
    }
    
    // Use 1/1 rate for test SMS
    $rate = (float)$range['payout_1_1'];
    $currency = $number['currency'];
    $cost = $rate * $smsCount;
    
    // Create test SMS record
    $messageId = 'TEST-' . strtoupper(bin2hex(random_bytes(8)));
    
    $db->query(
        "INSERT INTO sms_cdr 
         (message_id, date_time, range_id, number, cli, destination, message, sms_count, sms_type, 
          user_id, currency, my_payout, user_payout, profit, status) 
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 'test', ?, ?, 0, ?, 0, 'pending')",
        [$messageId, $number['range_id'], $number['number'], $number['number'], $destination, 
         $message, $smsCount, $userId, $currency, $cost]
    );
    
    $cdrId = $db->lastInsertId();
    
    // TODO: Actually send via SMPP when integrated
    // For now, simulate success
    $db->query(
        "UPDATE sms_cdr SET status = 'delivered', delivered_at = NOW() WHERE id = ?",
        [$cdrId]
    );
    
    logActivity($userId, 'test_sms', "Sent test SMS from {$number['number']} to $destination");
    
    jsonResponse([
        'success' => true, 
        'message' => 'Test SMS submitted successfully',
        'data' => [
            'message_id' => $messageId,
            'from' => $number['number'],
            'to' => $destination,
            'sms_count' => $smsCount,
            'cost' => $cost,
            'currency' => $currency,
            'status' => 'delivered'
        ]
    ]);
}

/**
 * Get test SMS history
 */
function getTestHistory() {
    global $db, $userId;
    
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['start'] ?? 0);
    
    $stmt = $db->query(
        "SELECT c.*, r.range_name 
         FROM sms_cdr c 
         JOIN sms_ranges r ON c.range_id = r.id 
         WHERE c.user_id = ? AND c.sms_type = 'test' 
         ORDER BY c.date_time DESC 
         LIMIT ? OFFSET ?",
        [$userId, $limit, $offset]
    );
    
    $history = $stmt->fetchAll();
    
    // Get total count
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM sms_cdr WHERE user_id = ? AND sms_type = 'test'",
        [$userId]
    );
    $total = (int)$stmt->fetch()['count'];
    
    jsonResponse(['success' => true, 'data' => $history, 'total' => $total]);
}

/**
 * Calculate SMS count based on encoding
 */
function calculateSmsCount($message) {
    // Check if message contains GSM-7 characters only
    $gsm7chars = '@£$¥èéùìòÇ\\nØø\\rÅåΔ_ΦΓΛΩΠΨΣΘΞ^{}\\[~\\]|€!"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà ';
    
    $isGsm7 = true;
    for ($i = 0; $i < strlen($message); $i++) {
        if (strpos($gsm7chars, $message[$i]) === false) {
            $isGsm7 = false;
            break;
        }
    }
    
    $len = strlen($message);
    
    if ($isGsm7) {
        // GSM-7 encoding
        if ($len <= 160) return 1;
        return ceil($len / 153); // Concatenated SMS
    } else {
        // UCS-2 encoding (Unicode)
        if ($len <= 70) return 1;
        return ceil($len / 67); // Concatenated Unicode SMS
    }
}
