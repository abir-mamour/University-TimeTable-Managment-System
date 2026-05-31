<?php
// ─── Detect base URL automatically ───────────
$protocol = (!empty($_SERVER['HTTPS']) 
             && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// ─── Updated folder name ──────────────────────
define('BASE_URL',  $protocol . '://' . $host . '/TimeTable');
define('BASE_PATH', dirname(__DIR__));

// ─── Roles ────────────────────────────────────
define('ROLE_STUDENT',   'student');
define('ROLE_PROFESSOR', 'professor');
define('ROLE_ADMIN',     'admin');

// ─── DB ───────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'timetable_db');
define('DB_USER', 'root');
define('DB_PASS', '');
?>