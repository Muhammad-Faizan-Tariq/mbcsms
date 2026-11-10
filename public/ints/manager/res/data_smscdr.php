<?php
/**
 * MBC SMS - SMS CDR Data for DataTables
 * Server-side processing for SMS CDR Reports and Stats
 */

require_once __DIR__ . '/../../api/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// DataTables parameters
$draw = (int)($_GET['draw'] ?? 1);
$start = (int)($_GET['iDisplayStart'] ?? 0);
$length = (int)($_GET['iDisplayLength'] ?? 25);
$search = $_GET['sSearch'] ?? '';

// Filters from frontend
$dateFrom = sanitize($_GET['fdate1'] ?? date('Y-m-d 00:00:00'));
$dateTo = sanitize($_GET['fdate2'] ?? date('Y-m-d 23:59:59'));
$rangeId = (int)($_GET['frange'] ?? 0);
$agentId = (int)($_GET['fagent'] ?? 0);
$number = sanitize($_GET['fnum'] ?? '');
$cli = sanitize($_GET['fcli'] ?? '');

// Group by options
$groupBy = [];
if (!empty($_GET['fgdate'])) $groupBy[] = 'DATE(c.date_time)';
if (!empty($_GET['fgmonth'])) $groupBy[] = "DATE_FORMAT(c.date_time, '%Y-%m')";
if (!empty($_GET['fgrange'])) $groupBy[] = 'c.range_id';
if (!empty($_GET['fgagent'])) $groupBy[] = 'c.user_id';
if (!empty($_GET['fgnumber'])) $groupBy[] = 'c.number';
if (!empty($_GET['fgcli'])) $groupBy[] = 'c.cli';

// Build WHERE clause
$where = "c.date_time BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

// Role-based filtering
if ($role === 'manager') {
    $where .= " AND c.range_id IN (SELECT id FROM sms_ranges WHERE manager_id = ?)";
    $params[] = $userId;
    
    if ($agentId) {
        $where .= " AND c.user_id = ?";
        $params[] = $agentId;
    }
} elseif ($role === 'agent') {
    $where .= " AND (c.user_id = ? OR c.user_id IN (SELECT id FROM users WHERE parent_id = ?))";
    $params[] = $userId;
    $params[] = $userId;
} else {
    $where .= " AND c.user_id = ?";
    $params[] = $userId;
}

if ($rangeId) {
    $where .= " AND c.range_id = ?";
    $params[] = $rangeId;
}
if ($number) {
    $where .= " AND c.number LIKE ?";
    $params[] = "%$number%";
}
if ($cli) {
    $where .= " AND c.cli LIKE ?";
    $params[] = "%$cli%";
}
if ($search) {
    $where .= " AND (c.number LIKE ? OR c.cli LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Count total
$stmt = $db->query("SELECT COUNT(*) as count FROM sms_cdr c WHERE $where", $params);
$recordsTotal = (int)$stmt->fetch()['count'];

// Get data with grouping
if (!empty($groupBy)) {
    $groupClause = "GROUP BY " . implode(', ', $groupBy);
    $selectFields = "DATE(c.date_time) as date, 
                     COALESCE(r.range_name, 'Unknown') as range_name,
                     COALESCE(c.number, '-') as number,
                     COALESCE(c.cli, '-') as cli,
                     COALESCE(u.username, '-') as username,
                     SUM(c.sms_count) as sms_count,
                     c.sms_type,
                     c.currency,
                     SUM(c.my_payout) as my_payout,
                     SUM(c.user_payout) as user_payout,
                     SUM(c.profit) as profit";
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

// Calculate totals for footer
$totalsQuery = "SELECT 
                    SUM(c.sms_count) as total_sms,
                    c.currency,
                    SUM(c.my_payout) as total_my_payout,
                    SUM(c.user_payout) as total_user_payout,
                    SUM(c.profit) as total_profit
                FROM sms_cdr c 
                WHERE $where 
                GROUP BY c.currency WITH ROLLUP";

$stmt = $db->query($totalsQuery, $params);
$totals = $stmt->fetchAll();

$totalsData = ['usd_in' => 0, 'eur_in' => 0, 'gbp_in' => 0, 
               'usd_out' => 0, 'eur_out' => 0, 'gbp_out' => 0,
               'usd_profit' => 0, 'eur_profit' => 0, 'gbp_profit' => 0,
               'total_sms' => 0];

foreach ($totals as $t) {
    $currency = strtolower($t['currency'] ?? '');
    if ($currency === 'usd') {
        $totalsData['usd_in'] = number_format((float)$t['total_my_payout'], 4);
        $totalsData['usd_out'] = number_format((float)$t['total_user_payout'], 4);
        $totalsData['usd_profit'] = number_format((float)$t['total_profit'], 4);
    } elseif ($currency === 'eur') {
        $totalsData['eur_in'] = number_format((float)$t['total_my_payout'], 4);
        $totalsData['eur_out'] = number_format((float)$t['total_user_payout'], 4);
        $totalsData['eur_profit'] = number_format((float)$t['total_profit'], 4);
    } elseif ($currency === 'gbp') {
        $totalsData['gbp_in'] = number_format((float)$t['total_my_payout'], 4);
        $totalsData['gbp_out'] = number_format((float)$t['total_user_payout'], 4);
        $totalsData['gbp_profit'] = number_format((float)$t['total_profit'], 4);
    }
    $totalsData['total_sms'] = (int)$t['total_sms'];
}

// Append totals as last row (for DataTables footer)
$result[] = [
    $totalsData['usd_in'], $totalsData['eur_in'], $totalsData['gbp_in'],
    $totalsData['usd_out'], $totalsData['eur_out'], $totalsData['gbp_out'],
    $totalsData['usd_profit'], $totalsData['eur_profit'], $totalsData['gbp_profit'],
    $totalsData['total_sms']
];

jsonResponse([
    'sEcho' => $draw,
    'iTotalRecords' => $recordsTotal,
    'iTotalDisplayRecords' => $recordsTotal,
    'aaData' => $result
]);
