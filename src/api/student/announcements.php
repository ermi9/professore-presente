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

RBAC::enforce($payload['role'], 'student.view_announcements');

$db = new Database();
$conn = $db->connect();

$s = $conn->prepare("SELECT id FROM students WHERE user_id = :uid");
$s->execute([':uid' => $payload['user_id']]);
if ($s->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Not a student']); exit; }
$student_id = (int)$s->fetch(PDO::FETCH_ASSOC)['id'];

try {
    $stmt = $conn->prepare("
        SELECT a.id, a.message, a.created_at,
               c.name AS course_name, c.code AS course_code,
               u.username AS professor_name
        FROM announcements a
        JOIN courses c ON a.course_id = c.id
        JOIN professors p ON a.professor_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN student_courses sc ON sc.course_id = c.id
        WHERE sc.student_id = :sid
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([':sid' => $student_id]);
    echo json_encode(['announcements' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (PDOException $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
?>
