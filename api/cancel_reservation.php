<?php
// api/cancel_reservation.php
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

$body = file_get_contents('php://input');
$req  = json_decode($body, true);
if (!is_array($req)) respond(['ok'=>false,'error'=>'Neteisingas JSON'], 400);

$csrf = $req['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) respond(['ok'=>false,'error'=>'CSRF klaida'], 403);

$id  = isset($req['id']) ? (int)$req['id'] : 0;
if ($id <= 0) respond(['ok'=>false,'error'=>'Neteisingas ID'], 400);

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) respond(['ok'=>false,'error'=>'DB klaida'], 500);
$mysqli->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];

// Check ownership + status
$stmt = $mysqli->prepare("SELECT statusas, pradzia FROM Rezervacija WHERE id = ? AND naudotojas = ? LIMIT 1");
$stmt->bind_param("ii", $id, $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) respond(['ok'=>false,'error'=>'Rezervacija nerasta'], 404);

$pradziaTs = strtotime($row['pradzia']);
$allowed = in_array($row['statusas'], ['LAUKIA','PATVIRTINTA'], true) && ($pradziaTs === false || $pradziaTs > time());
if (!$allowed) respond(['ok'=>false,'error'=>'Negalima atšaukti šios rezervacijos'], 400);

// Cancel → ATMESTA (matches your enum)
$new = 'ATMESTA';
$u = $mysqli->prepare("UPDATE Rezervacija SET statusas = ? WHERE id = ? AND naudotojas = ? LIMIT 1");
$u->bind_param("sii", $new, $id, $uid);
$ok = $u->execute();
$err = $u->error;
$u->close();

if (!$ok) respond(['ok'=>false,'error'=>'Atšaukti nepavyko: '.$err], 500);

respond(['ok'=>true,'id'=>$id,'statusas'=>$new]);