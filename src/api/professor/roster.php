<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');

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

// Get exam_id from query string
$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : null;

if (!$exam_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing exam_id parameter']);
    exit;
}

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

// Check if file uploaded
if (!isset($_FILES['csv_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No CSV file uploaded']);
    exit;
}

$file = $_FILES['csv_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'File upload error']);
    exit;
}

$file_name = $file['name'];
if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'csv') {
    http_response_code(400);
    echo json_encode(['error' => 'File must be CSV format']);
    exit;
}

// Read CSV
$handle = fopen($file['tmp_name'], 'r');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read file']);
    exit;
}

$added = 0;
$errors = [];
$row_num = 0;

// Skip header
fgetcsv($handle);

while (($row = fgetcsv($handle)) !== false) {
    $row_num++;
    $identifier = trim($row[0] ?? '');

    if (empty($identifier)) {
        $errors[] = "Row $row_num: Empty identifier";
        continue;
    }

    try {
        // Find student
        $student_stmt = $conn->prepare("
            SELECT s.id FROM students s
            JOIN users u ON s.user_id = u.id
            WHERE u.email = :identifier 
               OR u.username = :identifier 
               OR s.student_id_number = :identifier
            LIMIT 1
        ");
        $student_stmt->execute([':identifier' => $identifier]);

        if ($student_stmt->rowCount() === 0) {
            $errors[] = "Row $row_num: Student not found ($identifier)";
            continue;
        }

        $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
        $student_id = $student['id'];

        // Check if already added
        $check_stmt = $conn->prepare("
            SELECT id FROM exam_list 
            WHERE exam_id = :exam_id AND student_id = :student_id
        ");
        $check_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);

        if ($check_stmt->rowCount() > 0) {
            continue;
        }

        // Add to exam_list
        $insert_stmt = $conn->prepare("
            INSERT INTO exam_list (exam_id, student_id)
            VALUES (:exam_id, :student_id)
        ");
        $insert_stmt->execute([':exam_id' => $exam_id, ':student_id' => $student_id]);
        $added++;

    } catch (Exception $e) {
        $errors[] = "Row $row_num: Error - " . $e->getMessage();
    }
}

fclose($handle);

http_response_code(200);
echo json_encode([
    'message' => 'Roster uploaded successfully',
    'exam_id' => $exam_id,
    'added' => $added,
    'total_errors' => count($errors),
    'errors' => $errors
]);
?>
