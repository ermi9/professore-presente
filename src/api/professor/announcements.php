<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../security/JWTHandler.php';
require_once __DIR__ . '/../../security/RBAC.php';

header('Content-Type: application/json');

$jwt = new JWTHandler();
$token = $jwt->getTokenFromHeader();
if (!$token) { http_response_code(401); echo json_encode(['error' => 'Missing token']); exit; }

$payload = $jwt->verify($token);
if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Invalid token']); exit; }

RBAC::enforce($payload['role'], 'professor.manage_announcements');

$db = new Database();
$conn = $db->connect();

$s = $conn->prepare("SELECT id FROM professors WHERE user_id = :uid");
$s->execute([':uid' => $payload['user_id']]);
if ($s->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Not a professor']); exit; }
$professor_id = (int)$s->fetch(PDO::FETCH_ASSOC)['id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $s = $conn->prepare("
        SELECT a.id, a.message, a.created_at, c.name AS course_name, c.code AS course_code
        FROM announcements a
        JOIN courses c ON a.course_id = c.id
        WHERE a.professor_id = :pid
        ORDER BY a.created_at DESC
    ");
    $s->execute([':pid' => $professor_id]);
    echo json_encode(['announcements' => $s->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['course_id'], $data['message']) || trim($data['message']) === '') {
        http_response_code(400); echo json_encode(['error' => 'course_id and message are required']); exit;
    }

    $chk = $conn->prepare("SELECT id FROM courses WHERE id = :cid AND professor_id = :pid");
    $chk->execute([':cid' => (int)$data['course_id'], ':pid' => $professor_id]);
    if ($chk->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Course not found']); exit; }

    try {
        $ins = $conn->prepare("
            INSERT INTO announcements (professor_id, course_id, message)
            VALUES (:pid, :cid, :msg)
        ");
        $ins->execute([
            ':pid' => $professor_id,
            ':cid' => (int)$data['course_id'],
            ':msg' => trim($data['message'])
        ]);
        http_response_code(201);
        echo json_encode(['announcement_id' => (int)$conn->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
    }

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['announcement_id'])) { http_response_code(400); echo json_encode(['error' => 'Missing announcement_id']); exit; }

    $chk = $conn->prepare("SELECT id FROM announcements WHERE id = :aid AND professor_id = :pid");
    $chk->execute([':aid' => (int)$data['announcement_id'], ':pid' => $professor_id]);
    if ($chk->rowCount() === 0) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $conn->prepare("DELETE FROM announcements WHERE id = :aid")->execute([':aid' => (int)$data['announcement_id']]);
    echo json_encode(['message' => 'Deleted']);

} else {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
}
?>
