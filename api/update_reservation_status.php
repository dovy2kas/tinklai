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

if (empty($_SESSION['user_id'])) respond(['ok'=>false,'error'=>'Reikia prisijungti'], 401);

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) respond(['ok'=>false,'error'=>'DB klaida'], 500);
$mysqli->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];

$role = null;
$st = $mysqli->prepare("SELECT role FROM Naudotojas WHERE id = ? LIMIT 1");
$st->bind_param("i", $uid);
$st->execute();
if ($row = $st->get_result()->fetch_assoc()) $role = $row['role'];
$st->close();

if ($role !== 'ELEKTRIKAS') respond(['ok'=>false,'error'=>'Prieiga draudžiama'], 403);

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!is_array($req)) respond(['ok'=>false,'error'=>'Neteisingas JSON'], 400);

$csrf = $req['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) respond(['ok'=>false,'error'=>'CSRF klaida'], 403);

$id = isset($req['id']) ? (int)$req['id'] : 0;
$action = isset($req['action']) ? trim($req['action']) : '';
$comment = isset($req['comment']) ? trim($req['comment']) : '';

if ($id <= 0 || !in_array($action, ['confirm','deny'], true)) {
  respond(['ok'=>false,'error'=>'Neteisingi parametrai'], 400);
}

$st = $mysqli->prepare("SELECT statusas, pastabos, pradzia FROM Rezervacija WHERE id = ? AND elektriko_profilis = ? LIMIT 1");
$st->bind_param("ii", $id, $uid);
$st->execute();
$cur = $st->get_result()->fetch_assoc();
$st->close();

if (!$cur) respond(['ok'=>false,'error'=>'Rezervacija nerasta'], 404);

if (!in_array($cur['statusas'], ['LAUKIA','PATVIRTINTA'], true)) {
  respond(['ok'=>false,'error'=>'Šios rezervacijos statuso keisti negalima'], 400);
}

$newStatus = $action === 'confirm' ? 'PATVIRTINTA' : 'ATMESTA';

$u = $mysqli->prepare("UPDATE Rezervacija SET statusas = ?, pastabos = ? WHERE id = ? AND elektriko_profilis = ? LIMIT 1");
$u->bind_param("ssii", $newStatus, $comment, $id, $uid);
$ok = $u->execute();
$err = $u->error;
$u->close();

if (!$ok) respond(['ok'=>false,'error'=>'Nepavyko atnaujinti: '.$err], 500);

respond(['ok'=>true,'id'=>$id,'statusas'=>$newStatus,'pastabos'=>$comment]);