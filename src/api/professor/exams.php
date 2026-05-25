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
RBAC::enforce($payload['role'], 'professor.create_exam');

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

// Route
if ($request_method === 'POST') {
    createExam($conn, $professor_id);
} 
elseif ($request_method === 'GET') {
    listExams($conn, $professor_id);
} 
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function createExam($conn, $professor_id) {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['course_code']) || !isset($data['exam_date']) || !isset($data['exam_time'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: course_code, exam_date, exam_time']);
        exit;
    }

    $course_code = trim($data['course_code']);
    $exam_date   = $data['exam_date']; // YYYY-MM-DD
    $exam_time   = $data['exam_time']; // HH:MM:SS
    $description = isset($data['description']) ? trim($data['description']) : null;

    // Validate and cast room_id only if provided
    $room_id = null;
    if (isset($data['room_id']) && $data['room_id'] !== '' && $data['room_id'] !== null) {
        if (!ctype_digit((string)$data['room_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'room_id must be a positive integer']);
            exit;
        }
        $room_id = (int)$data['room_id'];
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }

    // Validate time format
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $exam_time)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid time format. Use HH:MM:SS']);
        exit;
    }

    try {
        // Look up the course by code and make sure it belongs to this professor
        $verify_stmt = $conn->prepare("
            SELECT id FROM courses
            WHERE LOWER(code) = LOWER(:course_code) AND professor_id = :professor_id
        ");
        $verify_stmt->execute([':course_code' => $course_code, ':professor_id' => $professor_id]);

        if ($verify_stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'No course with code "' . $course_code . '" found in your courses']);
            exit;
        }

        $course_id = (int)$verify_stmt->fetch(PDO::FETCH_ASSOC)['id'];

        // Create exam
        $stmt = $conn->prepare("
            INSERT INTO exams (course_id, room_id, exam_date, exam_time, description, status)
            VALUES (:course_id, :room_id, :exam_date, :exam_time, :description, 'not_started')
        ");

        $stmt->execute([
            ':course_id' => $course_id,
            ':room_id' => $room_id,
            ':exam_date' => $exam_date,
            ':exam_time' => $exam_time,
            ':description' => $description
        ]);

        $exam_id = $conn->lastInsertId();

        // Auto-populate exam_list from all students enrolled in this course
        $roster_stmt = $conn->prepare("
            INSERT INTO exam_list (exam_id, student_id)
            SELECT :exam_id, sc.student_id
            FROM student_courses sc
            WHERE sc.course_id = :course_id
            ON CONFLICT (exam_id, student_id) DO NOTHING
        ");
        $roster_stmt->execute([':exam_id' => $exam_id, ':course_id' => $course_id]);
        $enrolled_count = $roster_stmt->rowCount();

        http_response_code(201);
        echo json_encode([
            'message'       => 'Exam created successfully',
            'exam_id'       => $exam_id,
            'course_id'     => $course_id,
            'exam_date'     => $exam_date,
            'exam_time'     => $exam_time,
            'room_id'       => $room_id,
            'status'        => 'not_started',
            'enrolled_count' => $enrolled_count
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create exam: ' . $e->getMessage()]);
    }
}

function listExams($conn, $professor_id) {
    try {
        $stmt = $conn->prepare("
            SELECT e.id, e.course_id, c.name as course_name, c.code as course_code,
                   e.room_id, r.room_number, e.exam_date, e.exam_time, 
                   e.description, e.status, e.created_at
            FROM exams e
            JOIN courses c ON e.course_id = c.id
            LEFT JOIN rooms r ON e.room_id = r.id
            WHERE c.professor_id = :professor_id
            ORDER BY e.exam_date DESC, e.exam_time DESC
        ");

        $stmt->execute([':professor_id' => $professor_id]);
        $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'message' => 'Exams retrieved successfully',
            'count' => count($exams),
            'exams' => $exams
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve exams: ' . $e->getMessage()]);
    }
}
?>
