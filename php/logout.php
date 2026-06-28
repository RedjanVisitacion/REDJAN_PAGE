<?php
require_once __DIR__ . '/route_helpers.php';
session_start();
session_unset();
session_destroy();
header('Location: ' . rpsv_route_path('login'));
exit;
?>
