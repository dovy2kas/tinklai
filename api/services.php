<?php
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['error' => 'DB klaida']);
  exit;
}
$mysqli->set_charset('utf8mb4');

$eid = isset($_GET['elektrikas']) ? (int)$_GET['elektrikas'] : 0;
if ($eid <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Neteisingas elektriko ID']);
  exit;
}

$stmt = $mysqli->prepare("
  SELECT n.vardas, n.pavarde, n.miestas
  FROM ElektrikoProfilis e
  JOIN Naudotojas n ON n.id = e.id
  WHERE e.id = ? AND e.rodomas_viesai = 1 AND e.statusas = 'PATVIRTINTAS'
  LIMIT 1
");
$stmt->bind_param("i", $eid);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$header) {
  http_response_code(404);
  echo json_encode(['error' => 'Elektrikas nerastas ar nerodomas viešai']);
  exit;
}

$stmt = $mysqli->prepare("
  SELECT s.pavadinimas, s.aprasas, p.kaina_bazine, p.tipine_trukme_min
  FROM Pasiula p
  JOIN Paslauga s ON s.id = p.paslauga
  WHERE p.elektriko_profilis = ?
  ORDER BY s.pavadinimas ASC
");
$stmt->bind_param("i", $eid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$mysqli->close();

echo json_encode([
  'elektrikas' => $eid,
  'n' => $header,
  'paslaugos' => $rows,
], JSON_UNESCAPED_UNICODE);
