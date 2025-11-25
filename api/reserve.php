<?php
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'httponly' => true,
  'samesite' => 'Lax', 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function respond($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

if (empty($_SESSION['user_id'])) {
  respond(['ok'=>false,'error'=>'Neprisijungęs naudotojas. Prisijunk ir bandyk dar kartą.'], 401);
}

$DB_HOST = "localhost";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "stud";
$DB_PASS = 'stud';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) respond(['ok'=>false,'error'=>'DB klaida'], 500);
$mysqli->set_charset('utf8mb4');

$body = file_get_contents('php://input');
$req = json_decode($body, true);
if (!is_array($req)) respond(['ok'=>false,'error'=>'Neteisingas JSON'], 400);

$uid       = (int)$_SESSION['user_id'];
$eid       = isset($req['elektrikas']) ? (int)$req['elektrikas'] : 0;
$paslauga  = isset($req['paslauga']) ? (int)$req['paslauga'] : 0;
$date      = isset($req['date']) ? trim($req['date']) : '';
$start     = isset($req['start']) ? trim($req['start']) : '';
$note      = isset($req['pastabos']) ? trim($req['pastabos']) : '';

if ($eid<=0 || $paslauga<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !preg_match('/^\d{2}:\d{2}$/',$start)) {
  respond(['ok'=>false,'error'=>'Neteisingi parametrai'], 400);
}

$stmt = $mysqli->prepare("
  SELECT e.savaites_grafikas, e.statusas
  FROM ElektrikoProfilis e
  WHERE e.id = ? LIMIT 1
");
$stmt->bind_param("i", $eid);
$stmt->execute();
$prof = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$prof || $prof['statusas']!=='PATVIRTINTAS') {
  respond(['ok'=>false,'error'=>'Elektriko profilis nerastas arba nepatvirtintas.'], 404);
}

$stmt = $mysqli->prepare("
  SELECT p.tipine_trukme_min
  FROM Pasiula p
  WHERE p.elektriko_profilis = ? AND p.paslauga = ? LIMIT 1
");
$stmt->bind_param("ii", $eid, $paslauga);
$stmt->execute();
$svc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$svc) respond(['ok'=>false,'error'=>'Elektrikas neteikia tokios paslaugos'], 400);

$durMin = (int)$svc['tipine_trukme_min'];
if ($durMin <= 0) $durMin = 30;

$startTs = strtotime("$date $start:00");
if ($startTs === false) respond(['ok'=>false,'error'=>'Negalima data/laikas'], 400);
$endTs   = $startTs + $durMin*60;

if ($startTs <= time()) {
  respond(['ok'=>false,'error'=>'Pasirinktas laikas jau praėjo'], 400);
}

$grafikas = [];
if (!empty($prof['savaites_grafikas'])) {
  $tmp = json_decode($prof['savaites_grafikas'], true);
  if (is_array($tmp)) $grafikas = $tmp;
}
$weekday = (int)date('N', strtotime($date));

$norm = function($node){
  $out=[];
  if (is_array($node) && isset($node[0]) && is_array($node[0])) {
    foreach ($node as $pair){
      $f=$pair[0]??null; $t=$pair[1]??null;
      if (is_string($f)&&is_string($t)&&preg_match('/^\d{2}:\d{2}$/',$f)&&preg_match('/^\d{2}:\d{2}$/',$t)) $out[] = [$f,$t];
    }
    return $out;
  }
  if (is_array($node) && array_key_exists('nuo',$node) && array_key_exists('iki',$node)) {
    $enabled = !array_key_exists('enabled',$node) ? true : (bool)$node['enabled'];
    if ($enabled) {
      $f=$node['nuo']; $t=$node['iki'];
      if (is_string($f)&&is_string($t)&&preg_match('/^\d{2}:\d{2}$/',$f)&&preg_match('/^\d{2}:\d{2}$/',$t)) $out[] = [$f,$t];
    }
    return $out;
  }
  if (is_array($node) && isset($node[0]) && is_array($node[0]) && array_key_exists('nuo',$node[0])) {
    foreach ($node as $it){
      $enabled = !array_key_exists('enabled',$it) ? true : (bool)$it['enabled'];
      $f=$it['nuo']??null; $t=$it['iki']??null;
      if ($enabled && is_string($f)&&is_string($t)&&preg_match('/^\d{2}:\d{2}$/',$f)&&preg_match('/^\d{2}:\d{2}$/',$t)) $out[] = [$f,$t];
    }
    return $out;
  }
  return $out;
};

$dayNode = $grafikas[$weekday] ?? $grafikas[(string)$weekday] ?? null;
$intervals = $norm($dayNode);
if (empty($intervals)) respond(['ok'=>false,'error'=>'Šią dieną elektrikas nedirba'], 400);

$fits = false;
foreach ($intervals as [$f,$t]) {
  $iStart = strtotime("$date $f:00");
  $iEnd   = strtotime("$date $t:00");
  if ($iStart===false||$iEnd===false||$iEnd<=$iStart) continue;
  if ($startTs >= $iStart && $endTs <= $iEnd) { $fits = true; break; }
}
if (!$fits) respond(['ok'=>false,'error'=>'Pasirinktas laikas nepatenka į darbo grafiką'], 400);

$stmt = $mysqli->prepare("
  SELECT pradzia, pabaiga
  FROM Rezervacija
  WHERE elektriko_profilis = ?
    AND DATE(pradzia) = ?
    AND statusas IN ('LAUKIA','PATVIRTINTA','IVYKDYTA')
");
$stmt->bind_param("is", $eid, $date);
$stmt->execute();
$busy = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($busy as $b) {
  $bStart = strtotime($b['pradzia']);
  $bEnd   = strtotime($b['pabaiga']);
  if ($startTs < $bEnd && $endTs > $bStart) {
    respond(['ok'=>false,'error'=>'Laikas jau užimtas'], 409);
  }
}

$pradzia = date('Y-m-d H:i:s', $startTs);
$pabaiga = date('Y-m-d H:i:s', $endTs);
$status  = 'LAUKIA';

$stmt = $mysqli->prepare("
  INSERT INTO Rezervacija (naudotojas, elektriko_profilis, paslauga, pradzia, pabaiga, statusas, pastabos)
  VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("iiissss", $uid, $eid, $paslauga, $pradzia, $pabaiga, $status, $note);
$ok = $stmt->execute();
$err = $stmt->error;
$id  = $stmt->insert_id;
$stmt->close();

if (!$ok) respond(['ok'=>false,'error'=>'Nepavyko sukurti rezervacijos: '.$err], 500);

respond(['ok'=>true,'id'=>$id,'pradzia'=>$pradzia,'pabaiga'=>$pabaiga,'statusas'=>$status], 201);