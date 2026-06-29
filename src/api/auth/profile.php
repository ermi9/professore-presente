<?php
require_once __DIR__ . '/../../security/JWTHandler.php';

header('Content-Type: application/json');

$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get token from Authorization header
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

// Return user profile
http_response_code(200);
echo json_encode([
    'message' => 'Profile retrieved successfully',
    'user' => [
        'id' => $payload['user_id'],
        'username' => $payload['username'],
        'email' => $payload['email'],
        'role' => $payload['role']
    ]
]);
?>
