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

// Check permission - must be professor
RBAC::enforce($payload['role'], 'professor.create_course');

// Connect to database
$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get professor ID from user_id
$prof_stmt = $conn->prepare("SELECT id FROM professors WHERE user_id = :user_id");
$prof_stmt->execute([':user_id' => $payload['user_id']]);

if ($prof_stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'User is not registered as a professor']);
    exit;
}

$professor = $prof_stmt->fetch(PDO::FETCH_ASSOC);
$professor_id = $professor['id'];

// Route to appropriate handler
if ($request_method === 'POST') {
    createCourse($conn, $professor_id);
} 
elseif ($request_method === 'GET') {
    listCourses($conn, $professor_id);
} 
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function createCourse($conn, $professor_id) {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['name']) || !isset($data['code'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: name, code']);
        exit;
    }

    $name = trim($data['name']);
    $code = trim($data['code']);
    $description = isset($data['description']) ? trim($data['description']) : null;

    // Validation
    if (strlen($name) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'Course name must be at least 3 characters']);
        exit;
    }

    if (strlen($code) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Course code must be at least 2 characters']);
        exit;
    }

    try {
        // Check for duplicate course code for this professor
        $check_stmt = $conn->prepare("
            SELECT id FROM courses WHERE professor_id = :professor_id AND LOWER(code) = LOWER(:code)
        ");
        $check_stmt->execute([':professor_id' => $professor_id, ':code' => $code]);

        if ($check_stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'You already have a course with the code "' . $code . '"']);
            exit;
        }

        // Create course
        $stmt = $conn->prepare("
            INSERT INTO courses (professor_id, name, code, description)
            VALUES (:professor_id, :name, :code, :description)
        ");

        $stmt->execute([
            ':professor_id' => $professor_id,
            ':name' => $name,
            ':code' => $code,
            ':description' => $description
        ]);

        $course_id = $conn->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'message' => 'Course created successfully',
            'course_id' => $course_id,
            'name' => $name,
            'code' => $code,
            'description' => $description
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create course: ' . $e->getMessage()]);
    }
}

function listCourses($conn, $professor_id) {
    try {
        $stmt = $conn->prepare("
            SELECT id, name, code, description, created_at, updated_at
            FROM courses
            WHERE professor_id = :professor_id
            ORDER BY created_at DESC
        ");

        $stmt->execute([':professor_id' => $professor_id]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'message' => 'Courses retrieved successfully',
            'count' => count($courses),
            'courses' => $courses
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve courses: ' . $e->getMessage()]);
    }
}
?>
