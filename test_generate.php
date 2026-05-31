<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/app.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

echo "All includes OK\n";

// Test session_assignments
$stmt = $pdo->query("SELECT COUNT(*) FROM session_assignments");
echo "Sessions to schedule: " . $stmt->fetchColumn() . "\n";

// Test functions exist
echo "sendNotification exists: " . (function_exists('sendNotification') ? 'YES' : 'NO') . "\n";
echo "fail exists: " . (function_exists('fail') ? 'YES' : 'NO') . "\n";