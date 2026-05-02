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
RBAC::enforce($payload['role'], 'student.enroll_course');

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

// Parse request URI for course_id if enrolling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri_parts = explode('/', trim($uri, '/'));

// Route
if ($request_method === 'POST') {
    // Enrolling in a course
    enrollInCourse($conn, $student_id);
} 
elseif ($request_method === 'GET') {
    // List enrolled courses
    listEnrolledCourses($conn, $student_id);
} 
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function enrollInCourse($conn, $student_id) {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (empty($data['course_code'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: course_code']);
        exit;
    }

    $course_code = trim($data['course_code']);

    try {
        // Look up course by code (case-insensitive)
        $verify_stmt = $conn->prepare("SELECT id FROM courses WHERE LOWER(code) = LOWER(:course_code)");
        $verify_stmt->execute([':course_code' => $course_code]);

        if ($verify_stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'No course found with code "' . $course_code . '"']);
            exit;
        }

        $course_id = (int)$verify_stmt->fetch(PDO::FETCH_ASSOC)['id'];

        // Check if already enrolled
        $check_stmt = $conn->prepare("
            SELECT id FROM student_courses 
            WHERE student_id = :student_id AND course_id = :course_id
        ");
        $check_stmt->execute([':student_id' => $student_id, ':course_id' => $course_id]);

        if ($check_stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Already enrolled in this course']);
            exit;
        }

        // Enroll student
        $enroll_stmt = $conn->prepare("
            INSERT INTO student_courses (student_id, course_id)
            VALUES (:student_id, :course_id)
        ");

        $enroll_stmt->execute([':student_id' => $student_id, ':course_id' => $course_id]);

        http_response_code(201);
        echo json_encode([
            'message' => 'Successfully enrolled in course',
            'course_id' => $course_id
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to enroll: ' . $e->getMessage()]);
    }
}

function listEnrolledCourses($conn, $student_id) {
    try {
        $stmt = $conn->prepare("
            SELECT c.id, c.name, c.code, c.description, 
                   u.username as professor_name, sc.enrolled_at
            FROM student_courses sc
            JOIN courses c ON sc.course_id = c.id
            JOIN professors p ON c.professor_id = p.id
            JOIN users u ON p.user_id = u.id
            WHERE sc.student_id = :student_id
            ORDER BY sc.enrolled_at DESC
        ");

        $stmt->execute([':student_id' => $student_id]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'message' => 'Enrolled courses retrieved successfully',
            'count' => count($courses),
            'courses' => $courses
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve courses: ' . $e->getMessage()]);
    }
}
?>
