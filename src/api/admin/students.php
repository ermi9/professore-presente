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

RBAC::enforce($payload['role'], 'admin.list_students');

$db = new Database();
$conn = $db->connect();

try {
    $stmt = $conn->query("
        SELECT s.id, u.username, u.email, u.created_at,
               COUNT(DISTINCT sc.course_id) AS enrolled_courses
        FROM students s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN student_courses sc ON sc.student_id = s.id
        GROUP BY s.id, u.username, u.email, u.created_at
        ORDER BY u.created_at DESC
    ");
    echo json_encode(['students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (PDOException $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
?>
