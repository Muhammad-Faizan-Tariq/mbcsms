<?php
/**
 * MBC SMS - SMS Ranges Data for DataTables
 * Server-side processing for SMS Ranges table
 */

require_once __DIR__ . '/../../../../api/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Only managers can view ranges
if ($role !== 'manager') {
    jsonResponse(['error' => 'Access denied'], 403);
}

// DataTables parameters
$draw = (int)($_GET['draw'] ?? 1);
$start = (int)($_GET['iDisplayStart'] ?? 0);
$length = (int)($_GET['iDisplayLength'] ?? 25);
$search = $_GET['sSearch'] ?? '';

// Build WHERE clause
$where = "manager_id = ?";
$params = [$userId];

if ($search) {
    $where .= " AND (range_name LIKE ? OR prefix LIKE ? OR memo LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Count total
$stmt = $db->query("SELECT COUNT(*) as count FROM sms_ranges WHERE $where", $params);
$recordsTotal = (int)$stmt->fetch()['count'];

// Get data
$dataQuery = "SELECT * FROM sms_ranges WHERE $where ORDER BY id DESC LIMIT $start, $length";

$stmt = $db->query($dataQuery, $params);
$data = $stmt->fetchAll();

// Format for DataTables
$result = [];
foreach ($data as $row) {
    $statusBadge = $row['status'] === 'active' ? 
        '<span class="label label-success">Active</span>' : 
        ($row['status'] === 'exhausted' ? 
            '<span class="label label-danger">Exhausted</span>' : 
            '<span class="label label-warning">Inactive</span>');
    
    $actionBtns = '<a href="#" class="btn btn-mini btn-info" onclick="editRange(' . $row['id'] . '); return false;"><i class="icon-edit"></i></a> ';
    $actionBtns .= '<a href="#" class="btn btn-mini btn-danger" onclick="deleteRange(' . $row['id'] . '); return false;"><i class="icon-trash"></i></a>';
    
    $result[] = [
        $row['range_name'],
        $row['prefix'],
        number_format($row['available_numbers']) . '/' . number_format($row['total_numbers']),
        $row['test_number'] ?? '-',
        $row['currency'],
        $row['payout_1_1'] ? number_format((float)$row['payout_1_1'], 4) : '-',
        $row['payout_7_1'] ? number_format((float)$row['payout_7_1'], 4) : '-',
        $row['payout_7_7'] ? number_format((float)$row['payout_7_7'], 4) : '-',
        $row['payout_30_45'] ? number_format((float)$row['payout_30_45'], 4) : '-',
        $row['memo'] ?? '-',
        $statusBadge . ' ' . $actionBtns
    ];
}

jsonResponse([
    'sEcho' => $draw,
    'iTotalRecords' => $recordsTotal,
    'iTotalDisplayRecords' => $recordsTotal,
    'aaData' => $result
]);
