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

RBAC::enforce($payload['role'], 'professor.view_enrolled_students');

$db = new Database();
$conn = $db->connect();

$s = $conn->prepare("SELECT id FROM professors WHERE user_id = :uid");
$s->execute([':uid' => $payload['user_id']]);
if ($s->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Not a professor']); exit; }
$professor_id = (int)$s->fetch(PDO::FETCH_ASSOC)['id'];

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
if (!$exam_id) { http_response_code(400); echo json_encode(['error' => 'exam_id required']); exit; }

// verify the exam belongs to this professor
$chk = $conn->prepare("
    SELECT e.id FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.id = :eid AND c.professor_id = :pid
");
$chk->execute([':eid' => $exam_id, ':pid' => $professor_id]);
if ($chk->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Exam not found']); exit; }

try {
    $stmt = $conn->prepare("
        SELECT u.username, u.email, el.added_at,
               COALESCE(q.status, 'not in queue') AS queue_status,
               q.joined_at
        FROM exam_list el
        JOIN students s ON el.student_id = s.id
        JOIN users u ON s.user_id = u.id
        LEFT JOIN queue q ON q.exam_id = el.exam_id AND q.student_id = el.student_id
        WHERE el.exam_id = :eid
        ORDER BY u.username
    ");
    $stmt->execute([':eid' => $exam_id]);
    echo json_encode(['students' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (PDOException $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
?>
