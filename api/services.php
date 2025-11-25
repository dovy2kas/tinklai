<?php
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

function respond($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$DB_HOST = "localhost";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "stud";
$DB_PASS = 'stud';

$eid = isset($_GET['elektrikas']) ? (int)$_GET['elektrikas'] : 0;
if ($eid <= 0) respond(['ok'=>false,'error'=>'Trūksta elektriko ID'], 400);

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) respond(['ok'=>false,'error'=>'DB klaida'], 500);
$mysqli->set_charset('utf8mb4');

$sql = "
  SELECT
    p.paslauga                                        AS paslauga_id,
    MIN(p.kaina_bazine)                               AS kaina_bazine,
    COALESCE(NULLIF(MIN(p.tipine_trukme_min),0), 30)  AS tipine_trukme_min,
    MIN(s.pavadinimas)                                AS pavadinimas,
    MIN(s.aprasas)                                    AS aprasas
  FROM Pasiula p
  JOIN Paslauga s ON s.id = p.paslauga
  WHERE p.elektriko_profilis = ?
  GROUP BY p.paslauga
  ORDER BY MIN(s.pavadinimas) ASC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $eid);
$stmt->execute();
$res = $stmt->get_result();

$services = [];
while ($row = $res->fetch_assoc()) {
  $services[] = [
    'paslauga'          => (int)$row['paslauga_id'],
    'pavadinimas'       => $row['pavadinimas'],
    'aprasas'           => $row['aprasas'],
    'kaina_bazine'      => $row['kaina_bazine'],
    'tipine_trukme_min' => (int)$row['tipine_trukme_min'],
  ];
}
$stmt->close();

respond([
  'ok'         => true,
  'elektrikas' => $eid,
  'n'          => null,
  'paslaugos'  => $services,
]);
