<?php
/**
 * MBC SMS - AJAX Agents Dropdown
 * For Manager Panel - Select2 dropdown
 */

require_once __DIR__ . '/../../api/config.php';

session_name(SESSION_NAME);
session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in'] || $_SESSION['role'] !== 'manager') {
    jsonResponse(['results' => [], 'pagination' => ['more' => false]]);
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

$search = sanitize($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$max = (int)($_GET['max'] ?? 25);
$offset = ($page - 1) * $max;

$where = "role = 'agent' AND parent_id = ? AND status = 'active'";
$params = [$userId];

if ($search) {
    $where .= " AND (username LIKE ? OR (SELECT full_name FROM user_profiles WHERE user_id = users.id) LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $db->query(
    "SELECT id, username as title FROM users WHERE $where ORDER BY username LIMIT $max OFFSET $offset",
    $params
);
$results = $stmt->fetchAll();

$stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE $where", $params);
$total = (int)$stmt->fetch()['count'];

jsonResponse([
    'results' => array_map(function($r) {
        return ['id' => $r['id'], 'title' => $r['title']];
    }, $results),
    'pagination' => ['more' => ($offset + $max) < $total]
]);
