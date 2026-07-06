<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$jwt = new JWTHandler();
$token = $jwt->getTokenFromHeader();
if (!$token) { http_response_code(401); echo json_encode(['error' => 'Missing token']); exit; }

$payload = $jwt->verify($token);
if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Invalid token']); exit; }

RBAC::enforce($payload['role'], 'admin.view_stats');

$db = new Database();
$conn = $db->connect();

try {
    $stats = [];

    foreach ([
        'professors' => "SELECT COUNT(*) FROM professors",
        'students'   => "SELECT COUNT(*) FROM students",
        'courses'    => "SELECT COUNT(*) FROM courses",
        'exams'      => "SELECT COUNT(*) FROM exams"
    ] as $key => $sql) {
        $stats[$key] = (int)$conn->query($sql)->fetchColumn();
    }

    echo json_encode($stats);

} catch (PDOException $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
?>
