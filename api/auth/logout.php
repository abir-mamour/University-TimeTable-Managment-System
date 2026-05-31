<?php
require_once dirname(__DIR__, 2) . '/config/app.php';
session_start();
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit();
?>