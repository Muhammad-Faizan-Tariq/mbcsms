<?php
/**
 * MBC SMS - Export SMS CDR
 * Export CDR data to CSV/Excel
 */

require_once __DIR__ . '/../../api/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    die('Unauthorized');
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get filters from POST
$dateFrom = sanitize($_POST['fdate1'] ?? date('Y-m-d 00:00:00'));
$dateTo = sanitize($_POST['fdate2'] ?? date('Y-m-d 23:59:59'));
$rangeId = (int)($_POST['frange'] ?? 0);
$agentId = (int)($_POST['fagent'] ?? 0);
$number = sanitize($_POST['fnum'] ?? '');
$cli = sanitize($_POST['fcli'] ?? '');

$where = "c.date_time BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

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

// Output CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sms_cdr_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Date', 'Range', 'Number', 'CLI', 'User', 'SMS Count', 'Type', 'Currency', 'My Payout', 'User Payout', 'Profit']);

foreach ($data as $row) {
    fputcsv($output, [
        $row['date_time'],
        $row['range_name'] ?? '-',
        $row['number'],
        $row['cli'] ?? '-',
        $row['username'] ?? '-',
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
