<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../cors.php';
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
RBAC::enforce($payload['role'], 'student.join_queue');

// Connect to database
$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get student ID
$student_stmt = $conn->prepare("SELECT id FROM students WHERE user_id = :user_id");
$student_stmt->execute([':user_id' => $payload['user_id']]);

if ($student_stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'User is not registered as a student']);
    exit;
}

$student = $student_stmt->fetch(PDO::FETCH_ASSOC);
$student_id = $student['id'];

// Route
if ($request_method === 'POST') {
    joinQueue($conn, $student_id);
} 
elseif ($request_method === 'GET') {
    viewQueuePosition($conn, $student_id);
} 
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function joinQueue($conn, $student_id) {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['exam_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: exam_id']);
        exit;
    }

    $exam_id = (int)$data['exam_id'];

    try {
        // Verify exam exists and is not closed
        $verify_stmt = $conn->prepare("
            SELECT id, status FROM exams WHERE id = :exam_id
        ");
        $verify_stmt->execute([':exam_id' => $exam_id]);

        if ($verify_stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Exam not found']);
            exit;
        }

        $exam = $verify_stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam['status'] === 'closed') {
            http_response_code(403);
            echo json_encode(['error' => 'This exam is closed']);
            exit;
        }

        // Check if student is eligible (on exam_list)
        $eligible_stmt = $conn->prepare("
            SELECT id FROM exam_list 
            WHERE exam_id = :exam_id AND student_id = :student_id
        ");
        $eligible_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        if ($eligible_stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'You are not eligible for this exam']);
            exit;
        }

        // Check if already in queue
        $check_stmt = $conn->prepare("
            SELECT id, status FROM queue 
            WHERE exam_id = :exam_id AND student_id = :student_id
        ");
        $check_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        if ($check_stmt->rowCount() > 0) {
            $queue_entry = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($queue_entry['status'] !== 'attended') {
                http_response_code(409);
                echo json_encode(['error' => 'Already in queue for this exam']);
                exit;
            }
        }

        // Add to queue
        $join_stmt = $conn->prepare("
            INSERT INTO queue (exam_id, student_id, status)
            VALUES (:exam_id, :student_id, 'waiting')
        ");

        $join_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        http_response_code(201);
        echo json_encode([
            'message' => 'Successfully joined queue',
            'exam_id' => $exam_id,
            'status' => 'waiting'
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to join queue: ' . $e->getMessage()]);
    }
}

function viewQueuePosition($conn, $student_id) {
    $exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

    if (!$exam_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: exam_id']);
        exit;
    }

    try {
        // Get student's queue entry
        $student_stmt = $conn->prepare("
            SELECT id, status, joined_at FROM queue
            WHERE exam_id = :exam_id AND student_id = :student_id
        ");
        $student_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        if ($student_stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'You are not in the queue for this exam']);
            exit;
        }

        $student_queue = $student_stmt->fetch(PDO::FETCH_ASSOC);

        // Get position (count people ahead)
        $position_stmt = $conn->prepare("
            SELECT COUNT(*) as position FROM queue
            WHERE exam_id = :exam_id 
            AND status = 'waiting'
            AND joined_at < :joined_at
        ");
        $position_stmt->execute([
            ':exam_id' => $exam_id,
            ':joined_at' => $student_queue['joined_at']
        ]);

        $position_result = $position_stmt->fetch(PDO::FETCH_ASSOC);
        $position = $position_result['position'] + 1; // 1-indexed

        // Get total waiting
        $total_stmt = $conn->prepare("
            SELECT COUNT(*) as total FROM queue
            WHERE exam_id = :exam_id AND status = 'waiting'
        ");
        $total_stmt->execute([':exam_id' => $exam_id]);
        $total_result = $total_stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'message' => 'Queue position retrieved',
            'exam_id' => $exam_id,
            'position' => $position,
            'total_waiting' => $total_result['total'],
            'status' => $student_queue['status'],
            'joined_at' => $student_queue['joined_at']
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve position: ' . $e->getMessage()]);
    }
}
?>
