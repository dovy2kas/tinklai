<?php
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

session_start();

function json_exit(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$elektrikas = isset($_GET['elektrikas']) ? (int)$_GET['elektrikas'] : 0;
if ($elektrikas <= 0) {
    json_exit([
        'ok'    => false,
        'error' => 'Neteisingas elektriko identifikatorius.',
        'code'  => 'BAD_ELEKTRIKAS',
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

$stmt = $mysqli->prepare("
    SELECT e.id,
           n.vardas,
           n.pavarde,
           n.miestas,
           AVG(a.reitingas) AS avg_rating,
           COUNT(a.id)      AS reviews_count
    FROM ElektrikoProfilis e
    JOIN Naudotojas n ON n.id = e.id
    LEFT JOIN Rezervacija r ON r.elektriko_profilis = e.id
    LEFT JOIN Atsiliepimas a ON a.rezervacija = r.id
    WHERE e.id = ?
    GROUP BY e.id
    LIMIT 1
");
$stmt->bind_param('i', $elektrikas);
$stmt->execute();
$infoRes = $stmt->get_result();
$info = $infoRes->fetch_assoc();
$stmt->close();

if (!$info) {
    json_exit([
        'ok'    => false,
        'error' => 'Elektrikas nerastas.',
        'code'  => 'NOT_FOUND',
    ], 404);
}

$stmt = $mysqli->prepare("
    SELECT a.id,
           a.reitingas,
           a.komentaras,
           a.sukurta,
           u.vardas,
           u.pavarde,
           p.pavadinimas AS paslauga_pavadinimas
    FROM Atsiliepimas a
    JOIN Rezervacija r ON a.rezervacija = r.id
    JOIN Naudotojas u ON u.id = a.autorius
    LEFT JOIN Paslauga p ON p.id = r.paslauga
    WHERE r.elektriko_profilis = ?
    ORDER BY a.sukurta DESC
    LIMIT 100
");
$stmt->bind_param('i', $elektrikas);
$stmt->execute();
$listRes = $stmt->get_result();
$reviews = [];
while ($row = $listRes->fetch_assoc()) {
    $reviews[] = [
        'id'        => (int)$row['id'],
        'rating'    => (int)$row['reitingas'],
        'comment'   => $row['komentaras'],
        'created'   => $row['sukurta'],
        'author'    => trim($row['vardas'] . ' ' . $row['pavarde']),
        'service'   => $row['paslauga_pavadinimas'] ?? null,
    ];
}
$stmt->close();
$mysqli->close();

json_exit([
    'ok'       => true,
    'elektrikas' => [
        'id'            => (int)$info['id'],
        'vardas'        => $info['vardas'],
        'pavarde'       => $info['pavarde'],
        'miestas'       => $info['miestas'],
        'avg_rating'    => $info['avg_rating'] !== null ? (float)$info['avg_rating'] : null,
        'reviews_count' => (int)$info['reviews_count'],
    ],
    'reviews'  => $reviews,
]);
