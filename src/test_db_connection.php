<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config/Database.php';

$database = new Database();
$conn = $database->connect();

if ($conn) {
    echo "✓ Database connection successful!\n";
    echo "Connected to: " . getenv('DB_NAME') . "\n";
} else {
    echo "✗ Database connection failed!\n";
    exit(1);
}
?>
