<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');

$request_method = $_SERVER['REQUEST_METHOD'];

// Get and validate JWT token
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

// Check permission
RBAC::enforce($payload['role'], 'professor.manage_queue');

// Connect to database
$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get professor ID
$prof_stmt = $conn->prepare("SELECT id FROM professors WHERE user_id = :user_id");
$prof_stmt->execute([':user_id' => $payload['user_id']]);

if ($prof_stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'User is not registered as a professor']);
    exit;
}

$professor = $prof_stmt->fetch(PDO::FETCH_ASSOC);
$professor_id = $professor['id'];

if ($request_method === 'PATCH') {
    markAttended($conn, $professor_id);
}
elseif ($request_method === 'PUT') {
    callStudent($conn, $professor_id);
}
elseif ($request_method === 'GET') {
    viewQueue($conn, $professor_id);
}
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function callStudent($conn, $professor_id) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['exam_id']) || !isset($data['student_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: exam_id, student_id']);
        exit;
    }

    $exam_id    = (int)$data['exam_id'];
    $student_id = (int)$data['student_id'];

    try {
        $verify_stmt = $conn->prepare("
            SELECT e.id FROM exams e
            JOIN courses c ON e.course_id = c.id
            WHERE e.id = :exam_id AND c.professor_id = :professor_id
        ");
        $verify_stmt->execute([':exam_id' => $exam_id, ':professor_id' => $professor_id]);

        if ($verify_stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Exam does not belong to you']);
            exit;
        }

        $update_stmt = $conn->prepare("
            UPDATE queue SET status = 'called'
            WHERE exam_id = :exam_id AND student_id = :student_id AND status = 'waiting'
        ");
        $update_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        if ($update_stmt->rowCount() === 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Student is not in waiting status']);
            exit;
        }

        http_response_code(200);
        echo json_encode([
            'message'    => 'Student called',
            'exam_id'    => $exam_id,
            'student_id' => $student_id,
            'status'     => 'called'
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to call student: ' . $e->getMessage()]);
    }
}

function markAttended($conn, $professor_id) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['exam_id']) || !isset($data['student_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: exam_id, student_id']);
        exit;
    }

    $exam_id = (int)$data['exam_id'];
    $student_id = (int)$data['student_id'];

    try {
        // Verify exam belongs to this professor
        $verify_stmt = $conn->prepare("
            SELECT e.id FROM exams e
            JOIN courses c ON e.course_id = c.id
            WHERE e.id = :exam_id AND c.professor_id = :professor_id
        ");
        $verify_stmt->execute([':exam_id' => $exam_id, ':professor_id' => $professor_id]);

        if ($verify_stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Exam does not belong to you']);
            exit;
        }

        // Check if student is in queue
        $check_stmt = $conn->prepare("
            SELECT id FROM queue
            WHERE exam_id = :exam_id AND student_id = :student_id
        ");
        $check_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        if ($check_stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Student not in queue for this exam']);
            exit;
        }

        // Mark as attended
        $update_stmt = $conn->prepare("
            UPDATE queue
            SET status = 'attended', attended_at = NOW()
            WHERE exam_id = :exam_id AND student_id = :student_id
        ");

        $update_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        http_response_code(200);
        echo json_encode([
            'message' => 'Student marked as attended',
            'exam_id' => $exam_id,
            'student_id' => $student_id,
            'status' => 'attended'
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update: ' . $e->getMessage()]);
    }
}

function viewQueue($conn, $professor_id) {
    $exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

    if (!$exam_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: exam_id']);
        exit;
    }

    try {
        // Verify exam belongs to this professor
        $verify_stmt = $conn->prepare("
            SELECT e.id FROM exams e
            JOIN courses c ON e.course_id = c.id
            WHERE e.id = :exam_id AND c.professor_id = :professor_id
        ");
        $verify_stmt->execute([':exam_id' => $exam_id, ':professor_id' => $professor_id]);

        if ($verify_stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Exam does not belong to you']);
            exit;
        }

        // Get queue
        $queue_stmt = $conn->prepare("
            SELECT q.id, q.student_id, u.username, u.email, q.status, q.joined_at,
                   ROW_NUMBER() OVER (ORDER BY q.joined_at) as position
            FROM queue q
            JOIN students s ON q.student_id = s.id
            JOIN users u ON s.user_id = u.id
            WHERE q.exam_id = :exam_id
            ORDER BY q.joined_at
        ");

        $queue_stmt->execute([':exam_id' => $exam_id]);
        $queue = $queue_stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'message' => 'Queue retrieved',
            'exam_id' => $exam_id,
            'count' => count($queue),
            'queue' => $queue
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve queue: ' . $e->getMessage()]);
    }
}
?>
