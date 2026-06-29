<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');

$request_method = $_SERVER['REQUEST_METHOD'];

// Get token
$jwt_handler = new JWTHandler();
$token = $jwt_handler->getTokenFromHeader();

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing authorization token']);
    exit;
}

// Verify token
$payload = $jwt_handler->verify($token);

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

// Check permission
RBAC::enforce($payload['role'], 'admin.create_professor');

// Connect to database
$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Handle different HTTP methods
if ($request_method === 'POST') {
    // Create professor
    createProfessor($conn);
} 
elseif ($request_method === 'GET') {
    // List professors
    listProfessors($conn);
} 
else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function createProfessor($conn) {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: username, email, password']);
        exit;
    }

    $username = trim($data['username']);
    $email = trim($data['email']);
    $password = $data['password'];
    $department = isset($data['department']) ? trim($data['department']) : null;

    // Validation
    if (strlen($username) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'Username must be at least 3 characters']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address']);
        exit;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    try {
        // Check if username or email exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
        $check_stmt->execute([':username' => $username, ':email' => $email]);

        if ($check_stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Username or email already exists']);
            exit;
        }

        // Create user
        $user_stmt = $conn->prepare("
            INSERT INTO users (username, email, password_hash, role)
            VALUES (:username, :email, :password_hash, :role)
        ");

        $user_stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $password_hash,
            ':role' => 'professor'
        ]);

        $user_id = $conn->lastInsertId();

        // Create professor record
        $prof_stmt = $conn->prepare("
            INSERT INTO professors (user_id, department)
            VALUES (:user_id, :department)
        ");

        $prof_stmt->execute([
            ':user_id' => $user_id,
            ':department' => $department
        ]);

        $professor_id = $conn->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'message' => 'Professor created successfully',
            'professor_id' => $professor_id,
            'user_id' => $user_id,
            'username' => $username,
            'email' => $email,
            'department' => $department
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create professor: ' . $e->getMessage()]);
    }
}

function listProfessors($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT p.id, u.username, u.email, p.department, p.created_at
            FROM professors p
            JOIN users u ON p.user_id = u.id
            ORDER BY p.created_at DESC
        ");

        $stmt->execute();
        $professors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'message' => 'Professors retrieved successfully',
            'count' => count($professors),
            'professors' => $professors
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve professors: ' . $e->getMessage()]);
    }
}
?>
