<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';

$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: email, password']);
    exit;
}

$email = trim($data['email']);
$password = $data['password'];

$database = new Database();
$conn = $database->connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    $client_ip = getClientIP();
    
    $rate_limit_stmt = $conn->prepare("SELECT failed_attempts, locked_until FROM login_attempts WHERE ip_address = :ip_address");
    $rate_limit_stmt->execute([':ip_address' => $client_ip]);
    
    if ($rate_limit_stmt->rowCount() > 0) {
        $attempt = $rate_limit_stmt->fetch(PDO::FETCH_ASSOC);
        if ($attempt['locked_until'] && strtotime($attempt['locked_until']) > time()) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many failed login attempts. Try again later.']);
            exit;
        }
    }

    $stmt = $conn->prepare("SELECT id, username, email, password_hash, role FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);

    if ($stmt->rowCount() === 0) {
        recordFailedLogin($conn, $client_ip);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!password_verify($password, $user['password_hash'])) {
        recordFailedLogin($conn, $client_ip);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }
    
    $clear_stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = :ip_address");
    $clear_stmt->execute([':ip_address' => $client_ip]);

    $jwt_handler = new JWTHandler();
    $token = $jwt_handler->create([
        'user_id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role']
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user_type' => $user['role'],
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Login failed']);
}

function recordFailedLogin($conn, $ip_address) {
    $check_stmt = $conn->prepare("SELECT id, failed_attempts FROM login_attempts WHERE ip_address = :ip_address");
    $check_stmt->execute([':ip_address' => $ip_address]);
    
    if ($check_stmt->rowCount() > 0) {
        $attempt = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $new_attempts = $attempt['failed_attempts'] + 1;
        
        if ($new_attempts >= 5) {
            $locked_until = date('Y-m-d H:i:s', time() + (15 * 60));
            $update_stmt = $conn->prepare("UPDATE login_attempts SET failed_attempts = :attempts, locked_until = :locked_until, last_attempt = NOW() WHERE ip_address = :ip_address");
            $update_stmt->execute([':attempts' => $new_attempts, ':locked_until' => $locked_until, ':ip_address' => $ip_address]);
        } else {
            $update_stmt = $conn->prepare("UPDATE login_attempts SET failed_attempts = :attempts, last_attempt = NOW() WHERE ip_address = :ip_address");
            $update_stmt->execute([':attempts' => $new_attempts, ':ip_address' => $ip_address]);
        }
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, failed_attempts) VALUES (:ip_address, 1)");
        $insert_stmt->execute([':ip_address' => $ip_address]);
    }
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}
