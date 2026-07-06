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

RBAC::enforce($payload['role'], 'professor.manage_timetable');

$db = new Database();
$conn = $db->connect();

$s = $conn->prepare("SELECT id FROM professors WHERE user_id = :uid");
$s->execute([':uid' => $payload['user_id']]);
if ($s->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Not a professor']); exit; }
$professor_id = (int)$s->fetch(PDO::FETCH_ASSOC)['id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $s = $conn->prepare("
        SELECT ts.id, ts.course_id, c.name AS course_name, c.code AS course_code,
               ts.day_of_week, ts.start_time, ts.end_time, ts.room
        FROM timetable_slots ts
        JOIN courses c ON ts.course_id = c.id
        WHERE c.professor_id = :pid
        ORDER BY ts.day_of_week, ts.start_time
    ");
    $s->execute([':pid' => $professor_id]);
    echo json_encode(['slots' => $s->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['course_id'], $data['day_of_week'], $data['start_time'], $data['end_time'])) {
        http_response_code(400); echo json_encode(['error' => 'Missing required fields']); exit;
    }

    $chk = $conn->prepare("SELECT id FROM courses WHERE id = :cid AND professor_id = :pid");
    $chk->execute([':cid' => (int)$data['course_id'], ':pid' => $professor_id]);
    if ($chk->rowCount() === 0) { http_response_code(403); echo json_encode(['error' => 'Course not found']); exit; }

    try {
        $ins = $conn->prepare("
            INSERT INTO timetable_slots (course_id, day_of_week, start_time, end_time, room)
            VALUES (:cid, :dow, :st, :et, :room)
        ");
        $ins->execute([
            ':cid'  => (int)$data['course_id'],
            ':dow'  => (int)$data['day_of_week'],
            ':st'   => $data['start_time'],
            ':et'   => $data['end_time'],
            ':room' => isset($data['room']) && $data['room'] !== '' ? $data['room'] : null
        ]);
        http_response_code(201);
        echo json_encode(['slot_id' => (int)$conn->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode(['error' => 'This time slot already exists for that course']);
    }

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['slot_id'])) { http_response_code(400); echo json_encode(['error' => 'Missing slot_id']); exit; }

    // make sure this slot belongs to this professor's course
    $chk = $conn->prepare("
        SELECT ts.id FROM timetable_slots ts
        JOIN courses c ON ts.course_id = c.id
        WHERE ts.id = :sid AND c.professor_id = :pid
    ");
    $chk->execute([':sid' => (int)$data['slot_id'], ':pid' => $professor_id]);
    if ($chk->rowCount() === 0) { http_response_code(404); echo json_encode(['error' => 'Slot not found']); exit; }

    $conn->prepare("DELETE FROM timetable_slots WHERE id = :sid")->execute([':sid' => (int)$data['slot_id']]);
    echo json_encode(['message' => 'Deleted']);

} else {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
}
?>
