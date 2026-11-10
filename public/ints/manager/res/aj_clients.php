<?php
/**
 * MBC SMS - Clients Dropdown (Manager Panel)
 * Alias for aj_agents with client role
 */

$_GET['role'] = 'client';
$_GET['action'] = 'dropdown';
require_once __DIR__ . '/aj_agents.php';
