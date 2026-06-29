<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwt_handler = new JWTHandler();
$token = $jwt_handler->getTokenFromHeader();

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing authorization token']);
    exit;
}

$payload = $jwt_handler->verify($token);

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

RBAC::enforce($payload['role'], 'student.list_exams');

$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$student_stmt = $conn->prepare("SELECT id FROM students WHERE user_id = :user_id");
$student_stmt->execute([':user_id' => $payload['user_id']]);

if ($student_stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'User is not registered as a student']);
    exit;
}

$student_id = (int)$student_stmt->fetch(PDO::FETCH_ASSOC)['id'];

try {
    // Fetch all exams for courses the student is enrolled in,
    // along with their roster and queue status for each.
    $stmt = $conn->prepare("
        SELECT e.id, e.exam_date, e.exam_time, e.status, e.description,
               c.name  AS course_name,
               c.code  AS course_code,
               u.username AS professor_name,
               r.room_number,
               CASE WHEN el.id IS NOT NULL THEN true ELSE false END AS on_roster,
               q.status AS queue_status
        FROM student_courses sc
        JOIN courses c    ON sc.course_id  = c.id
        JOIN professors p ON c.professor_id = p.id
        JOIN users u      ON p.user_id     = u.id
        JOIN exams e      ON e.course_id   = c.id
        LEFT JOIN rooms r      ON e.room_id    = r.id
        LEFT JOIN exam_list el ON el.exam_id   = e.id AND el.student_id = :sid1
        LEFT JOIN queue q      ON q.exam_id    = e.id AND q.student_id  = :sid2
        WHERE sc.student_id = :sid3
        ORDER BY e.exam_date ASC, e.exam_time ASC
    ");

    $stmt->execute([':sid1' => $student_id, ':sid2' => $student_id, ':sid3' => $student_id]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($exams as &$e) {
        $e['on_roster'] = (bool)$e['on_roster'];
    }

    http_response_code(200);
    echo json_encode([
        'message' => 'Exams retrieved successfully',
        'count'   => count($exams),
        'exams'   => $exams
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve exams: ' . $e->getMessage()]);
}
?>
