<?php
/**
 * MBC SMS - SMPP Webhook API
 * Receives incoming SMS from Jasmin SMPP Gateway
 * Processes CDR, calculates payouts, updates balances
 */

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'receive';

// For receive action, only accept POST requests
if ($action === 'receive' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed. Use POST for receive action'], 405);
}

// Verify API key for receive action (Jasmin will call this with API key)
if ($action === 'receive') {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    $validApiKey = getenv('SMPP_WEBHOOK_KEY') ?: 'MBC_WEBHOOK_SECURE_2026';

    if ($apiKey !== $validApiKey) {
        jsonResponse(['error' => 'Invalid API key'], 401);
    }
}

// Get JSON input
$input = getJsonInput();
if (empty($input)) {
    $input = array_merge($_GET, $_POST);
}

switch ($action) {
    case 'receive':
        receiveSms($input);
        break;
    case 'status':
        // Require authentication for status
        requireAuth();
        getSmppStatus();
        break;
    case 'stats':
        // Require authentication for stats
        requireAuth();
        getSmppStats();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Receive incoming SMS from Jasmin
 */
function receiveSms($data) {
    global $db;
    
    // Extract SMS details from Jasmin webhook
    $messageId = sanitize($data['message_id'] ?? uniqid('sms_', true));
    $sourceAddr = sanitize($data['source_addr'] ?? $data['from'] ?? '');
    $destAddr = sanitize($data['destination_addr'] ?? $data['to'] ?? '');
    $shortMessage = sanitize($data['short_message'] ?? $data['message'] ?? '');
    $submitDate = $data['submit_date'] ?? $data['timestamp'] ?? date('Y-m-d H:i:s');
    $connectorId = sanitize($data['connector_id'] ?? '');
    
    if (empty($sourceAddr) || empty($destAddr)) {
        jsonResponse(['error' => 'Missing source or destination'], 400);
    }
    
    // Log raw SMPP CDR
    $db->query(
        "INSERT INTO smpp_cdr (message_id, source_addr, destination_addr, short_message, message_status, submit_date, connector_id)
         VALUES (?, ?, ?, ?, 'delivered', ?, ?)",
        [$messageId, $sourceAddr, $destAddr, $shortMessage, $submitDate, $connectorId]
    );
    $smppCdrId = $db->lastInsertId();
    
    // Find the number and its owner
    $stmt = $db->query(
        "SELECT n.*, r.id as range_id, r.range_name, r.manager_id, r.currency,
                r.payout_1_1, r.payout_7_1, r.payout_7_7, r.payout_30_45,
                n.pay_term, n.payout_rate
         FROM sms_numbers n
         JOIN sms_ranges r ON n.range_id = r.id
         WHERE n.number = ? AND n.status = 'assigned'",
        [$destAddr]
    );
    $number = $stmt->fetch();
    
    if (!$number) {
        // Number not found or not assigned - log but don't error
        logActivity(null, 'sms_unknown_number', "SMS received for unknown/unassigned number: $destAddr");
        jsonResponse([
            'success' => true,
            'message' => 'SMS logged but number not assigned',
            'smpp_cdr_id' => $smppCdrId
        ]);
    }
    
    // Get the user hierarchy
    $clientId = $number['assigned_to'];
    
    // Get client details
    $stmt = $db->query("SELECT id, username, parent_id, role FROM users WHERE id = ?", [$clientId]);
    $client = $stmt->fetch();
    
    if (!$client) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    // Get agent (client's parent)
    $agentId = null;
    $agentPayout = 0;
    if ($client['parent_id']) {
        $stmt = $db->query("SELECT id, username, parent_id FROM users WHERE id = ?", [$client['parent_id']]);
        $agent = $stmt->fetch();
        $agentId = $agent['id'] ?? null;
    }
    
    // Get manager (agent's parent or directly from range)
    $managerId = $number['manager_id'];
    
    // Calculate payouts based on pay_term
    $userPayout = (float)($number['payout_rate'] ?: $number['payout_1_1']);
    
    // Determine payout column based on pay_term
    $payTerm = $number['pay_term'] ?: '1/1';
    switch ($payTerm) {
        case '7/1':
            $userPayout = (float)($number['payout_7_1'] ?: $number['payout_1_1']);
            break;
        case '7/7':
            $userPayout = (float)($number['payout_7_7'] ?: $number['payout_1_1']);
            break;
        case '30/45':
            $userPayout = (float)($number['payout_30_45'] ?: $number['payout_1_1']);
            break;
        default:
            $userPayout = (float)$number['payout_1_1'];
    }
    
    // Calculate hierarchy payouts (example distribution)
    // Total payout = user_payout (client gets 100% of their rate)
    // Agent gets percentage override, Manager gets percentage override
    // Adjust these percentages based on your business model
    
    $clientPayout = $userPayout;  // Client gets full rate
    $agentPayoutRate = 0.10;       // Agent gets 10% override
    $managerPayoutRate = 0.05;     // Manager gets 5% override
    
    $agentPayout = $agentId ? ($userPayout * $agentPayoutRate) : 0;
    $managerPayout = $userPayout * $managerPayoutRate;
    
    // Insert into main CDR
    $db->query(
        "INSERT INTO sms_cdr 
         (message_id, source, destination, message, user_id, range_id, 
          user_payout, agent_payout, my_payout, currency, smpp_message_id, 
          smpp_status, connector_id, processed_at, date_time)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered', ?, NOW(), NOW())",
        [
            $messageId,
            $sourceAddr,
            $destAddr,
            $shortMessage,
            $clientId,
            $number['range_id'],
            $clientPayout,
            $agentPayout,
            $managerPayout,
            $number['currency'],
            $messageId,
            $connectorId
        ]
    );
    $cdrId = $db->lastInsertId();
    
    // Update SMPP CDR with link
    $db->query("UPDATE smpp_cdr SET cdr_id = ? WHERE id = ?", [$cdrId, $smppCdrId]);
    
    // Update balances
    $db->beginTransaction();
    
    try {
        // Update client balance
        $db->query(
            "INSERT INTO user_balances (user_id, currency, balance) 
             VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE balance = balance + ?",
            [$clientId, $number['currency'], $clientPayout, $clientPayout]
        );
        
        // Update agent balance
        if ($agentId && $agentPayout > 0) {
            $db->query(
                "INSERT INTO user_balances (user_id, currency, balance) 
                 VALUES (?, ?, ?) 
                 ON DUPLICATE KEY UPDATE balance = balance + ?",
                [$agentId, $number['currency'], $agentPayout, $agentPayout]
            );
        }
        
        // Update manager balance
        if ($managerPayout > 0) {
            $db->query(
                "INSERT INTO user_balances (user_id, currency, balance) 
                 VALUES (?, ?, ?) 
                 ON DUPLICATE KEY UPDATE balance = balance + ?",
                [$managerId, $number['currency'], $managerPayout, $managerPayout]
            );
        }
        
        // Update SMPP config stats
        $db->query(
            "UPDATE smpp_config SET total_received = total_received + 1, last_received_at = NOW() WHERE status = 'active'"
        );
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        logActivity(null, 'smpp_balance_error', "Failed to update balances for SMS $messageId: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to update balances'], 500);
    }
    
    // Log activity
    logActivity($clientId, 'sms_received', "SMS received on {$number['range_name']}: $destAddr from $sourceAddr");
    
    // Create notifications
    createNotification($clientId, 'SMS Received', "New SMS on $destAddr from $sourceAddr", 'info');
    
    if ($agentId) {
        createNotification($agentId, 'Client SMS', "Client received SMS on $destAddr", 'info');
    }
    
    // Return success
    jsonResponse([
        'success' => true,
        'message' => 'SMS processed successfully',
        'data' => [
            'cdr_id' => $cdrId,
            'smpp_cdr_id' => $smppCdrId,
            'number' => $destAddr,
            'range' => $number['range_name'],
            'client_id' => $clientId,
            'agent_id' => $agentId,
            'manager_id' => $managerId,
            'payouts' => [
                'client' => $clientPayout,
                'agent' => $agentPayout,
                'manager' => $managerPayout,
                'currency' => $number['currency']
            ]
        ]
    ]);
}

/**
 * Get SMPP server status
 */
function getSmppStatus() {
    global $db;
    
    // Check if Jasmin is running
    $jasminRunning = false;
    $pid = exec('pgrep -f jasmind');
    if ($pid) {
        $jasminRunning = true;
    }
    
    // Get config
    $stmt = $db->query("SELECT * FROM smpp_config WHERE status = 'active' LIMIT 1");
    $config = $stmt->fetch();
    
    // Get recent stats
    $stmt = $db->query("SELECT COUNT(*) as count FROM smpp_cdr WHERE submit_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $hourlyCount = (int)$stmt->fetch()['count'];
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM smpp_cdr WHERE submit_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $dailyCount = (int)$stmt->fetch()['count'];
    
    jsonResponse([
        'success' => true,
        'status' => [
            'jasmin_running' => $jasminRunning,
            'smpp_port' => 2775,
            'config' => $config ? [
                'name' => $config['config_name'],
                'host' => $config['inbound_host'],
                'port' => $config['inbound_port'],
                'total_received' => (int)$config['total_received'],
                'last_received' => $config['last_received_at']
            ] : null,
            'stats' => [
                'hourly' => $hourlyCount,
                'daily' => $dailyCount
            ]
        ]
    ]);
}

/**
 * Get SMPP statistics for dashboard
 */
function getSmppStats() {
    global $db, $userId, $role;
    
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    
    $stats = [];
    
    if ($role === 'manager') {
        // Manager sees all
        $stmt = $db->query("SELECT COUNT(*) as count FROM smpp_cdr WHERE DATE(submit_date) = ?", [$today]);
        $stats['today'] = (int)$stmt->fetch()['count'];
        
        $stmt = $db->query("SELECT COUNT(*) as count FROM smpp_cdr WHERE DATE_FORMAT(submit_date, '%Y-%m') = ?", [$thisMonth]);
        $stats['month'] = (int)$stmt->fetch()['count'];
        
        // By range
        $stmt = $db->query(
            "SELECT r.range_name, COUNT(*) as count
             FROM smpp_cdr s
             JOIN sms_cdr c ON s.cdr_id = c.id
             JOIN sms_ranges r ON c.range_id = r.id
             WHERE DATE_FORMAT(s.submit_date, '%Y-%m') = ?
             GROUP BY r.id
             ORDER BY count DESC
             LIMIT 5",
            [$thisMonth]
        );
        $stats['by_range'] = $stmt->fetchAll();
        
    } elseif ($role === 'agent') {
        // Agent sees their numbers + clients
        $stmt = $db->query(
            "SELECT COUNT(*) as count FROM smpp_cdr s
             JOIN sms_cdr c ON s.cdr_id = c.id
             WHERE DATE(s.submit_date) = ?
             AND (c.user_id = ? OR c.user_id IN (SELECT id FROM users WHERE parent_id = ?))",
            [$today, $userId, $userId]
        );
        $stats['today'] = (int)$stmt->fetch()['count'];
        
    } else {
        // Client sees only their numbers
        $stmt = $db->query(
            "SELECT COUNT(*) as count FROM smpp_cdr s
             JOIN sms_cdr c ON s.cdr_id = c.id
             WHERE DATE(s.submit_date) = ? AND c.user_id = ?",
            [$today, $userId]
        );
        $stats['today'] = (int)$stmt->fetch()['count'];
    }
    
    jsonResponse(['success' => true, 'stats' => $stats]);
}
