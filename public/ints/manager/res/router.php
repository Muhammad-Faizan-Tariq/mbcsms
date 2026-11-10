<?php
/**
 * MBC SMS - Manager Panel Resource Router
 * Routes all AJAX requests to the main API
 */

// Get the requested action
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$file = basename($path, '.php');

// Map file names to API endpoints
$apiMap = [
    'aj_agents' => 'users',
    'aj_smsranges' => 'ranges',
    'data_smscdr' => 'cdr',
    'aj_clients' => 'users',
];

// Include the appropriate API file
$apiEndpoint = $apiMap[$file] ?? $file;
$apiFile = __DIR__ . '/../../../../api/' . $apiEndpoint . '.php';

if (file_exists($apiFile)) {
    // Set action based on file name
    $_GET['action'] = $_GET['action'] ?? match($file) {
        'aj_agents' => 'dropdown',
        'aj_smsranges' => 'dropdown',
        'aj_clients' => 'dropdown',
        'data_smscdr' => 'datatable',
        default => 'list'
    };
    
    // Set role filter for agents dropdown
    if ($file === 'aj_agents') {
        $_GET['role'] = 'agent';
    }
    if ($file === 'aj_clients') {
        $_GET['role'] = 'client';
    }
    
    require_once $apiFile;
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'API endpoint not found']);
}
