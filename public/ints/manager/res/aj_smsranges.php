<?php
/**
 * MBC SMS - AJAX SMS Ranges Dropdown
 * For Manager Panel - Select2 dropdown
 */

require_once __DIR__ . '/../../api/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    jsonResponse(['results' => [], 'pagination' => ['more' => false]]);
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

$search = sanitize($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$max = (int)($_GET['max'] ?? 25);
$offset = ($page - 1) * $max;

$where = "status = 'active'";
$params = [];

if ($role === 'manager') {
    $where .= " AND manager_id = ?";
    $params[] = $userId;
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
