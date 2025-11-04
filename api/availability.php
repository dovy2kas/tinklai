<?php
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

function respond($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) respond(['error'=>'DB klaida'], 500);
$mysqli->set_charset('utf8mb4');

$eid = isset($_GET['elektrikas']) ? (int)$_GET['elektrikas'] : 0;
$date = isset($_GET['date']) ? trim($_GET['date']) : '';

if ($eid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  respond(['error' => 'Neteisingi parametrai'], 400);
}

$stmt = $mysqli->prepare("
  SELECT e.savaites_grafikas, e.statusas, e.rodomas_viesai
  FROM ElektrikoProfilis e
  WHERE e.id = ?
  LIMIT 1
");
$stmt->bind_param("i", $eid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || $row['statusas'] !== 'PATVIRTINTAS' || (int)$row['rodomas_viesai'] !== 1) {
  respond(['error' => 'Elektrikas nerastas arba nerodomas viešai'], 404);
}

$grafikas = [];
if (!empty($row['savaites_grafikas'])) {
  $decoded = json_decode($row['savaites_grafikas'], true);
  if (is_array($decoded)) $grafikas = $decoded;
}

$ts = strtotime($date . ' 00:00:00');
$weekday = (int)date('N', $ts);

if (empty($grafikas[(string)$weekday]) || !is_array($grafikas[(string)$weekday])) {
  respond(['elektrikas' => $eid, 'date' => $date, 'slots' => []]);
}

$intervals = $grafikas[(string)$weekday];

$stmt = $mysqli->prepare("
  SELECT pradzia, pabaiga
  FROM Rezervacija
  WHERE elektriko_profilis = ?
    AND DATE(pradzia) = ?
    AND statusas IN ('LAUKIA','PATVIRTINTA','IVYKDYTA')
");
$stmt->bind_param("is", $eid, $date);
$stmt->execute();
$resBusy = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$busy = array_map(function($r){
  return [
    strtotime($r['pradzia']),
    strtotime($r['pabaiga'])
  ];
}, $resBusy);

$slotLen = 30 * 60;
$slots = [];

foreach ($intervals as $pair) {
  if (!is_array($pair) || count($pair) < 2) continue;
  [$from, $to] = $pair;
  if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) continue;

  $start = strtotime("$date $from:00");
  $end   = strtotime("$date $to:00");

  for ($t = $start; $t + $slotLen <= $end; $t += $slotLen) {
    $slotStart = $t;
    $slotEnd   = $t + $slotLen;

    if ($slotStart < time() && date('Y-m-d', $slotStart) === date('Y-m-d')) {
      continue;
    }

    $overlap = false;
    foreach ($busy as [$bStart, $bEnd]) {
      if ($slotStart < $bEnd && $slotEnd > $bStart) { $overlap = true; break; }
    }
    if (!$overlap) {
      $slots[] = date('H:i', $slotStart);
    }
  }
}

respond(['elektrikas' => $eid, 'date' => $date, 'slots' => $slots]);
