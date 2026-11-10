<?php
/**
 * MBC SMS - SMS CDR (Call Detail Records) API
 * View SMS records, statistics, exports
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
            case 'datatable':
                getCdrDatatable();
                break;
            case 'stats':
                getCdrStats();
                break;
            case 'export':
                exportCdr();
                break;
            default:
                getCdrDatatable();
        }
        break;
    case 'POST':
        // For Jasmin webhook - receive SMS
        if ($action === 'receive') {
            receiveSms();
        } else {
            jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Get CDR for DataTables
 */
function getCdrDatatable() {
    global $db, $userId, $role;
    
    $draw = (int)($_GET['draw'] ?? 1);
    $start = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    $search = $_GET['search']['value'] ?? '';
    
    // Filters
    $dateFrom = sanitize($_GET['fdate1'] ?? date('Y-m-d 00:00:00'));
    $dateTo = sanitize($_GET['fdate2'] ?? date('Y-m-d 23:59:59'));
    $rangeId = (int)($_GET['frange'] ?? 0);
    $agentId = (int)($_GET['fagent'] ?? 0);
    $clientId = (int)($_GET['fclient'] ?? 0);
    $number = sanitize($_GET['fnum'] ?? '');
    $cli = sanitize($_GET['fcli'] ?? '');
    
    // Group by
    $groupBy = [];
    if (!empty($_GET['fgdate'])) $groupBy[] = 'DATE(date_time)';
    if (!empty($_GET['fgmonth'])) $groupBy[] = "DATE_FORMAT(date_time, '%Y-%m')";
    if (!empty($_GET['fgrange'])) $groupBy[] = 'range_id';
    if (!empty($_GET['fgagent'])) $groupBy[] = 'user_id';
    if (!empty($_GET['fgnumber'])) $groupBy[] = 'number';
    if (!empty($_GET['fgcli'])) $groupBy[] = 'cli';
    
    $where = "date_time BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    // Role-based filtering
    if ($role === 'manager') {
        // Manager sees all SMS from their ranges
        $where .= " AND range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)";
        $params[] = $userId;
        
        if ($agentId) {
            $where .= " AND user_id = ?";
            $params[] = $agentId;
        }
    } elseif ($role === 'agent') {
        // Agent sees their SMS and their clients' SMS
        $where .= " AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE parent_id = ?))";
        $params[] = $userId;
        $params[] = $userId;
        
        if ($clientId) {
            $where .= " AND user_id = ?";
            $params[] = $clientId;
        }
    } else {
        // Client sees only their SMS
        $where .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    if ($rangeId) {
        $where .= " AND range_id = ?";
        $params[] = $rangeId;
    }
    if ($number) {
        $where .= " AND number LIKE ?";
        $params[] = "%$number%";
    }
    if ($cli) {
        $where .= " AND cli LIKE ?";
        $params[] = "%$cli%";
    }
    if ($search) {
        $where .= " AND (number LIKE ? OR cli LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Count total
    $countQuery = "SELECT COUNT(*) as count FROM sms_cdr WHERE $where";
    $stmt = $db->query($countQuery, $params);
    $recordsTotal = (int)$stmt->fetch()['count'];
    
    // Get data
    if (!empty($groupBy)) {
        // Grouped query
        $groupClause = "GROUP BY " . implode(', ', $groupBy);
        $selectFields = "DATE(date_time) as date, 
                         COALESCE(r.range_name, 'Unknown') as range_name,
                         COALESCE(c.number, '-') as number,
                         COALESCE(c.cli, '-') as cli,
                         COALESCE(u.username, '-') as username,
                         SUM(sms_count) as sms_count,
                         sms_type,
                         currency,
                         SUM(my_payout) as my_payout,
                         SUM(user_payout) as user_payout,
                         SUM(profit) as profit";
    } else {
        $groupClause = "";
        $selectFields = "c.*, r.range_name, u.username";
    }
    
    $dataQuery = "SELECT $selectFields 
                  FROM sms_cdr c 
                  LEFT JOIN sms_ranges r ON c.range_id = r.id 
                  LEFT JOIN users u ON c.user_id = u.id 
                  WHERE $where 
                  $groupClause
                  ORDER BY c.date_time DESC 
                  LIMIT $start, $length";
    
    $stmt = $db->query($dataQuery, $params);
    $data = $stmt->fetchAll();
    
    // Format for DataTables
    $result = [];
    foreach ($data as $row) {
        $typeBadge = $row['sms_type'] === 'test' ? 
            '<span class="label label-warning">Test</span>' : 
            '<span class="label label-success">General</span>';
        
        $result[] = [
            $row['date_time'] ?? $row['date'],
            $row['range_name'] ?? '-',
            $row['number'],
            $row['cli'] ?? '-',
            $row['username'] ?? '-',
            $row['sms_count'],
            $typeBadge,
            $row['currency'],
            number_format((float)$row['my_payout'], 4),
            number_format((float)$row['user_payout'], 4)
        ];
    }
    
    // Add totals row
    $totalsQuery = "SELECT 
                        SUM(sms_count) as total_sms,
                        currency,
                        SUM(my_payout) as total_my_payout,
                        SUM(user_payout) as total_user_payout,
                        SUM(profit) as total_profit
                    FROM sms_cdr c 
                    WHERE $where 
                    GROUP BY currency WITH ROLLUP";
    
    $stmt = $db->query($totalsQuery, $params);
    $totals = $stmt->fetchAll();
    
    $totalsData = ['usd_in' => 0, 'eur_in' => 0, 'gbp_in' => 0, 
                   'usd_out' => 0, 'eur_out' => 0, 'gbp_out' => 0,
                   'usd_profit' => 0, 'eur_profit' => 0, 'gbp_profit' => 0,
                   'total_sms' => 0];
    
    foreach ($totals as $t) {
        if ($t['currency'] === 'USD') {
            $totalsData['usd_in'] = $t['total_my_payout'];
            $totalsData['usd_out'] = $t['total_user_payout'];
            $totalsData['usd_profit'] = $t['total_profit'];
        } elseif ($t['currency'] === 'EUR') {
            $totalsData['eur_in'] = $t['total_my_payout'];
            $totalsData['eur_out'] = $t['total_user_payout'];
            $totalsData['eur_profit'] = $t['total_profit'];
        } elseif ($t['currency'] === 'GBP') {
            $totalsData['gbp_in'] = $t['total_my_payout'];
            $totalsData['gbp_out'] = $t['total_user_payout'];
            $totalsData['gbp_profit'] = $t['total_profit'];
        }
        $totalsData['total_sms'] = $t['total_sms'];
    }
    
    // Append totals as last row
    $result[] = [
        $totalsData['usd_in'], $totalsData['eur_in'], $totalsData['gbp_in'],
        $totalsData['usd_out'], $totalsData['eur_out'], $totalsData['gbp_out'],
        $totalsData['usd_profit'], $totalsData['eur_profit'], $totalsData['gbp_profit'],
        $totalsData['total_sms']
    ];
    
    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsTotal,
        'data' => $result
    ]);
}

/**
 * Get CDR Statistics
 */
function getCdrStats() {
    global $db, $userId, $role;
    
    $period = sanitize($_GET['period'] ?? 'today');
    $groupBy = sanitize($_GET['group'] ?? 'day');
    
    // Determine date range
    switch ($period) {
        case 'today':
            $dateFrom = date('Y-m-d 00:00:00');
            $dateTo = date('Y-m-d 23:59:59');
            break;
        case 'yesterday':
            $dateFrom = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $dateTo = date('Y-m-d 23:59:59', strtotime('-1 day'));
            break;
        case 'week':
            $dateFrom = date('Y-m-d 00:00:00', strtotime('-7 days'));
            $dateTo = date('Y-m-d 23:59:59');
            break;
        case 'month':
            $dateFrom = date('Y-m-01 00:00:00');
            $dateTo = date('Y-m-t 23:59:59');
            break;
        case 'last_month':
            $dateFrom = date('Y-m-01 00:00:00', strtotime('-1 month'));
            $dateTo = date('Y-m-t 23:59:59', strtotime('-1 month'));
            break;
        default:
            $dateFrom = sanitize($_GET['from'] ?? date('Y-m-d 00:00:00'));
            $dateTo = sanitize($_GET['to'] ?? date('Y-m-d 23:59:59'));
    }
    
    $where = "date_time BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    if ($role === 'manager') {
        $where .= " AND range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)";
        $params[] = $userId;
    } else {
        $where .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    // Group by clause
    switch ($groupBy) {
        case 'hour':
            $groupClause = "DATE(date_time), HOUR(date_time)";
            $selectGroup = "DATE(date_time) as date, HOUR(date_time) as hour";
            break;
        case 'day':
            $groupClause = "DATE(date_time)";
            $selectGroup = "DATE(date_time) as date";
            break;
        case 'range':
            $groupClause = "range_id";
            $selectGroup = "range_id";
            break;
        case 'user':
            $groupClause = "user_id";
            $selectGroup = "user_id";
            break;
        case 'type':
            $groupClause = "sms_type";
            $selectGroup = "sms_type";
            break;
        default:
            $groupClause = "DATE(date_time)";
            $selectGroup = "DATE(date_time) as date";
    }
    
    $query = "SELECT $selectGroup,
                     COUNT(*) as total_sms,
                     SUM(sms_count) as sms_count,
                     currency,
                     SUM(my_payout) as my_payout,
                     SUM(user_payout) as user_payout,
                     SUM(profit) as profit
              FROM sms_cdr 
              WHERE $where 
              GROUP BY $groupClause, currency
              ORDER BY date_time DESC";
    
    $stmt = $db->query($query, $params);
    $stats = $stmt->fetchAll();
    
    jsonResponse(['success' => true, 'stats' => $stats, 'period' => ['from' => $dateFrom, 'to' => $dateTo]]);
}

/**
 * Export CDR to CSV/Excel
 */
function exportCdr() {
    global $db, $userId, $role;
    
    $format = sanitize($_GET['format'] ?? 'csv');
    $dateFrom = sanitize($_GET['fdate1'] ?? date('Y-m-d 00:00:00'));
    $dateTo = sanitize($_GET['fdate2'] ?? date('Y-m-d 23:59:59'));
    
    $where = "date_time BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    if ($role === 'manager') {
        $where .= " AND range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)";
        $params[] = $userId;
    } else {
        $where .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    $stmt = $db->query(
        "SELECT c.*, r.range_name, u.username 
         FROM sms_cdr c 
         LEFT JOIN sms_ranges r ON c.range_id = r.id 
         LEFT JOIN users u ON c.user_id = u.id 
         WHERE $where 
         ORDER BY c.date_time DESC",
        $params
    );
    $data = $stmt->fetchAll();
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sms_cdr_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Range', 'Number', 'CLI', 'User', 'SMS Count', 'Type', 'Currency', 'My Payout', 'User Payout', 'Profit']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['date_time'],
                $row['range_name'],
                $row['number'],
                $row['cli'],
                $row['username'],
                $row['sms_count'],
                $row['sms_type'],
                $row['currency'],
                $row['my_payout'],
                $row['user_payout'],
                $row['profit']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    jsonResponse(['success' => true, 'data' => $data]);
}

/**
 * Receive SMS from Jasmin Gateway (Webhook)
 * This endpoint is called by Jasmin when an SMS is received
 */
function receiveSms() {
    global $db;
    
    // Get input from Jasmin
    $input = getJsonInput();
    if (empty($input)) {
        $input = $_POST;
    }
    
    // Jasmin sends: from (CLI), to (number), message, timestamp
    $number = sanitize($input['to'] ?? $input['number'] ?? '');
    $cli = sanitize($input['from'] ?? $input['cli'] ?? '');
    $message = $input['message'] ?? '';
    $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');
    
    if (empty($number)) {
        jsonResponse(['error' => 'Number is required'], 400);
    }
    
    // Find the number in our database
    $stmt = $db->query(
        "SELECT n.*, r.*, r.id as range_id, n.assigned_to as user_id
         FROM sms_numbers n 
         JOIN sms_ranges r ON n.range_id = r.id 
         WHERE n.number = ?",
        [$number]
    );
    $numberData = $stmt->fetch();
    
    if (!$numberData) {
        // Number not found - ignore or log
        error_log("SMS received for unknown number: $number");
        jsonResponse(['success' => false, 'message' => 'Number not found'], 404);
    }
    
    // Determine SMS type
    $smsType = ($numberData['test_number'] === $number || $numberData['is_test']) ? 'test' : 'general';
    
    // Calculate payouts
    $myPayout = (float)$numberData['payout_1_1']; // Default to 1/1
    $userPayout = 0;
    
    // Get user's payout rate (if agent/client has custom rate)
    if ($numberData['assigned_to']) {
        // For now, use the range's payout rate
        // In production, you might have custom rates per user
        $userPayout = $myPayout * 0.9; // Example: 90% of manager's rate
    }
    
    $profit = $myPayout - $userPayout;
    
    // Insert CDR record
    $db->query(
        "INSERT INTO sms_cdr (date_time, range_id, number, cli, user_id, sms_count, sms_type, currency, my_payout, user_payout, profit) 
         VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)",
        [$timestamp, $numberData['range_id'], $number, $cli, $numberData['assigned_to'], $smsType, $numberData['currency'], $myPayout, $userPayout, $profit]
    );
    
    // Update balances
    if ($myPayout > 0) {
        // Credit manager's balance
        $stmt = $db->query("SELECT manager_id FROM sms_ranges WHERE id = ?", [$numberData['range_id']]);
        $range = $stmt->fetch();
        if ($range) {
            updateBalance($range['manager_id'], $numberData['currency'], $myPayout, 'credit');
        }
    }
    
    if ($userPayout > 0 && $numberData['assigned_to']) {
        // Credit user's balance
        updateBalance($numberData['assigned_to'], $numberData['currency'], $userPayout, 'credit');
    }
    
    // Notify user if assigned
    if ($numberData['assigned_to']) {
        createNotification(
            $numberData['assigned_to'],
            'SMS Received',
            "SMS received on $number from $cli",
            'info'
        );
    }
    
    logActivity($numberData['assigned_to'], 'sms_received', "SMS received on $number");
    
    jsonResponse([
        'success' => true,
        'message' => 'SMS processed successfully',
        'cdr_id' => $db->lastInsertId(),
        'payout' => [
            'my_payout' => $myPayout,
            'user_payout' => $userPayout,
            'currency' => $numberData['currency']
        ]
    ]);
}
