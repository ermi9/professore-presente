<?php
require_once __DIR__ . '/../../config/Database.php';

header('Content-Type: application/json');

$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
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
$role = isset($data['role']) ? $data['role'] : 'student';

// Basic validation
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

if (!in_array($role, ['student', 'professor', 'admin'])) {
    $role = 'student';
}

// Connect to database
$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Hash password
$password_hash = password_hash($password, PASSWORD_BCRYPT);

try {
    // Check if username or email already exists
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
    $check_stmt->execute([':username' => $username, ':email' => $email]);

    if ($check_stmt->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Username or email already exists']);
        exit;
    }

    // Insert user
    $stmt = $conn->prepare("
        INSERT INTO users (username, email, password_hash, role)
        VALUES (:username, :email, :password_hash, :role)
    ");

    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $password_hash,
        ':role' => $role
    ]);

    $user_id = $conn->lastInsertId();

    // If student, create student record
    if ($role === 'student') {
        $student_stmt = $conn->prepare("INSERT INTO students (user_id) VALUES (:user_id)");
        $student_stmt->execute([':user_id' => $user_id]);
    }

    // If professor, create professor record
    if ($role === 'professor') {
        $prof_stmt = $conn->prepare("INSERT INTO professors (user_id) VALUES (:user_id)");
        $prof_stmt->execute([':user_id' => $user_id]);
    }

    http_response_code(201);
    echo json_encode([
        'message' => 'User registered successfully',
        'user_id' => $user_id,
        'username' => $username,
        'role' => $role
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
}
?>
