<?php
@date_default_timezone_set('Asia/Manila');

$DB_HOST = 'your-db-host';
$DB_USER = 'your-db-user';
$DB_PASS = 'your-db-password';
$DB_NAME = 'your-db-name';

if (!extension_loaded('mysqli')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server configuration error: MySQLi extension is not enabled.']);
    exit;
}

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$conn->set_charset('utf8mb4');
@ $conn->query("SET time_zone = '+08:00'");
?>
