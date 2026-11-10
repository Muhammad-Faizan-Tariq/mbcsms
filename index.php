<?php
/**
 * MBC SMS - Main Entry Point
 * Redirects to login or dashboard based on session
 */

require_once __DIR__ . '/api/config.php';

session_name(SESSION_NAME);
session_start();

// Check if logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    $redirectUrl = match ($_SESSION['role']) {
        'manager' => '/ints/manager/SMSDashboard',
        'agent' => '/ints/agent/SMSDashboard',
        'client' => '/ints/client/SMSDashboard',
        default => '/ints/client/SMSDashboard'
    };
    header("Location: $redirectUrl");
} else {
    header("Location: /ints/login");
}
exit;
