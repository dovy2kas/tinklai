<?php
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

session_start();

function json_exit(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_exit([
        'ok'    => false,
        'error' => 'Neleistinas užklausos metodas.',
        'code'  => 'BAD_METHOD',
    ], 405);
}

if (empty($_SESSION['user_id'])) {
    json_exit([
        'ok'    => false,
        'error' => 'Turite prisijungti, kad galėtumėte palikti atsiliepimą.',
        'code'  => 'UNAUTHENTICATED',
    ], 401);
}

$uid = (int)$_SESSION['user_id'];

$csrfSession = $_SESSION['csrf'] ?? '';
$csrfPost    = $_POST['csrf'] ?? '';

if (!$csrfSession || !$csrfPost || !hash_equals($csrfSession, (string)$csrfPost)) {
    json_exit([
        'ok'    => false,
        'error' => 'Neteisingas saugos žetonas (CSRF). Pabandykite perkrauti puslapį.',
        'code'  => 'CSRF',
    ], 400);
}

$elektrikas = (int)($_POST['elektrikas'] ?? 0);
$rating     = (int)($_POST['rating'] ?? 0);
$comment    = trim((string)($_POST['comment'] ?? ''));

if ($elektrikas <= 0) {
    json_exit([
        'ok'    => false,
        'error' => 'Neteisingas elektriko identifikatorius.',
        'code'  => 'BAD_ELEKTRIKAS',
    ], 400);
}

if ($rating < 1 || $rating > 5) {
    json_exit([
        'ok'    => false,
        'error' => 'Įvertinimas turi būti nuo 1 iki 5.',
        'code'  => 'BAD_RATING',
    ], 400);
}

if ($comment === '') {
    json_exit([
        'ok'    => false,
        'error' => 'Prašome parašyti bent trumpą komentarą.',
        'code'  => 'EMPTY_COMMENT',
    ], 400);
}

if (mb_strlen($comment) > 2000) {
    json_exit([
        'ok'    => false,
        'error' => 'Komentaras per ilgas (daugiau nei 2000 simbolių).',
        'code'  => 'COMMENT_TOO_LONG',
    ], 400);
}

$DB_HOST = "localhost";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "stud";
$DB_PASS = 'stud';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
    json_exit([
        'ok'    => false,
        'error' => 'Vidinė DB klaida: ' . $mysqli->connect_error,
        'code'  => 'DB_CONNECT',
    ], 500);
}
$mysqli->set_charset('utf8mb4');

$stmt = $mysqli->prepare("SELECT role FROM Naudotojas WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    json_exit([
        'ok'    => false,
        'error' => 'Naudotojas nerastas.',
        'code'  => 'USER_NOT_FOUND',
    ], 400);
}

if ($row['role'] !== 'NAUDOTOJAS') {
    json_exit([
        'ok'    => false,
        'error' => 'Tik NAUDOTOJAS gali palikti atsiliepimą.',
        'code'  => 'BAD_ROLE',
    ], 403);
}

$stmt = $mysqli->prepare("
    SELECT r.id
    FROM Rezervacija r
    WHERE r.naudotojas = ?
      AND r.elektriko_profilis = ?
      AND r.statusas IN ('PATVIRTINTA','IVYKDYTA')
    LIMIT 1
");
$stmt->bind_param('ii', $uid, $elektrikas);
$stmt->execute();
$hasAnyReservation = (bool)$stmt->get_result()->fetch_row();
$stmt->close();

if (!$hasAnyReservation) {
    json_exit([
        'ok'    => false,
        'error' => 'Negalite palikti atsiliepimo šiam elektrikui.',
        'code'  => 'NOT_ELIGIBLE',
    ], 403);
}

$stmt = $mysqli->prepare("
    SELECT 1
    FROM Rezervacija r
    JOIN Atsiliepimas a ON a.rezervacija = r.id
    WHERE r.naudotojas = ?
      AND r.elektriko_profilis = ?
      AND a.autorius = ?
    LIMIT 1
");
$stmt->bind_param('iii', $uid, $elektrikas, $uid);
$stmt->execute();
$alreadyReviewed = (bool)$stmt->get_result()->fetch_row();
$stmt->close();

if ($alreadyReviewed) {
    json_exit([
        'ok'    => false,
        'error' => 'Jau esate palikę atsiliepimą šiam elektrikui.',
        'code'  => 'ALREADY_REVIEWED',
    ], 400);
}

$stmt = $mysqli->prepare("
    SELECT r.id
    FROM Rezervacija r
    LEFT JOIN Atsiliepimas a ON a.rezervacija = r.id
    WHERE r.naudotojas = ?
      AND r.elektriko_profilis = ?
      AND r.statusas IN ('PATVIRTINTA','IVYKDYTA')
      AND a.id IS NULL
    ORDER BY r.pradzia DESC
    LIMIT 1
");
$stmt->bind_param('ii', $uid, $elektrikas);
$stmt->execute();
$res = $stmt->get_result();
$rezRow = $res->fetch_assoc();
$stmt->close();

if (!$rezRow) {
    json_exit([
        'ok'    => false,
        'error' => 'Jau esate palikę atsiliepimą šiam elektrikui.',
        'code'  => 'ALREADY_REVIEWED',
    ], 400);
}

$rezId = (int)$rezRow['id'];

$stmt = $mysqli->prepare("
    INSERT INTO Atsiliepimas (rezervacija, autorius, reitingas, komentaras, sukurta)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->bind_param('iiis', $rezId, $uid, $rating, $comment);

if (!$stmt->execute()) {
    if ($mysqli->errno === 1062) {
        json_exit([
            'ok'    => false,
            'error' => 'Jau esate palikę atsiliepimą šiam elektrikui.',
            'code'  => 'ALREADY_REVIEWED',
        ], 400);
    }

    $err = $mysqli->error;
    $stmt->close();
    json_exit([
        'ok'    => false,
        'error' => 'Nepavyko išsaugoti atsiliepimo. Bandykite dar kartą.',
        'debug' => $err,
        'code'  => 'INSERT_FAIL',
    ], 500);
}

$stmt->close();

json_exit([
    'ok'          => true,
    'rezervacija' => $rezId,
]);
