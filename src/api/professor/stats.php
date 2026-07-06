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

RBAC::enforce($payload['role'], 'professor.view_stats');

$db = new Database();
$conn = $db->connect();

$s = $conn->prepare("SELECT id FROM professors WHERE user_id = :uid");
$s->execute([':uid' => $payload['user_id']]);
if ($s->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Not a professor']); exit; }
$professor_id = (int)$s->fetch(PDO::FETCH_ASSOC)['id'];

try {
    $stats = [];

    $s = $conn->prepare("SELECT COUNT(*) FROM courses WHERE professor_id = :pid");
    $s->execute([':pid' => $professor_id]);
    $stats['courses'] = (int)$s->fetchColumn();

    $s = $conn->prepare("
        SELECT COUNT(DISTINCT sc.student_id)
        FROM student_courses sc
        JOIN courses c ON sc.course_id = c.id
        WHERE c.professor_id = :pid
    ");
    $s->execute([':pid' => $professor_id]);
    $stats['total_students'] = (int)$s->fetchColumn();

    $s = $conn->prepare("
        SELECT COUNT(*) FROM exams e
        JOIN courses c ON e.course_id = c.id
        WHERE c.professor_id = :pid AND e.exam_date >= CURRENT_DATE
    ");
    $s->execute([':pid' => $professor_id]);
    $stats['upcoming_exams'] = (int)$s->fetchColumn();

    $s = $conn->prepare("
        SELECT COUNT(*) FROM queue q
        JOIN exams e ON q.exam_id = e.id
        JOIN courses c ON e.course_id = c.id
        WHERE c.professor_id = :pid AND q.status IN ('waiting','called')
    ");
    $s->execute([':pid' => $professor_id]);
    $stats['queue_now'] = (int)$s->fetchColumn();

    echo json_encode($stats);

} catch (PDOException $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
?>
