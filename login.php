<?php
mb_internal_encoding('UTF-8');

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';


const REMEMBER_COOKIE = 'auth_token';
const REMEMBER_DAYS   = 30;
$APP_SECRET_KEY  = getenv('APP_SECRET_KEY') ?: '123';

$sessionLifetime = 0;
if (!empty($_COOKIE[REMEMBER_COOKIE])) {
  $sessionLifetime = REMEMBER_DAYS * 86400;
}
session_set_cookie_params([
  'lifetime' => $sessionLifetime,
  'path'     => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function flash($key, $msg = null) {
  if ($msg === null) {
    if (!empty($_SESSION[$key])) { $m = $_SESSION[$key]; unset($_SESSION[$key]); return $m; }
    return '';
  }
  $_SESSION[$key] = $msg;
}
function redirect($to) {
  header("Location: $to"); exit;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function issue_remember_cookie(int $userId, string $email, string $role): void {
  $exp = time() + (REMEMBER_DAYS * 86400);
  $nonce = bin2hex(random_bytes(8));
  $payload = $userId . '|' . $email . '|' . $role . '|' . $exp . '|' . $nonce;
  $sig = hash_hmac('sha256', $payload, $APP_SECRET_KEY);
  $token = base64_encode($payload . '|' . $sig);
  setcookie(REMEMBER_COOKIE, $token, [
    'expires'  => $exp,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  ]);
}
function clear_remember_cookie(): void {
  setcookie(REMEMBER_COOKIE, '', time()-3600, '/');
}
function try_auto_login_from_cookie(mysqli $db): bool {
  if (empty($_COOKIE[REMEMBER_COOKIE])) return false;
  $raw = base64_decode($_COOKIE[REMEMBER_COOKIE], true);
  if ($raw === false) return false;
  $parts = explode('|', $raw);
  if (count($parts) !== 6) return false;
  [$userId, $email, $role, $exp, $nonce, $sig] = $parts;
  if (!ctype_digit($userId) || !ctype_digit($exp)) return false;
  if ((int)$exp < time()) return false;
  $payload = $userId . '|' . $email . '|' . $role . '|' . $exp . '|' . $nonce;
  $calcSig = hash_hmac('sha256', $payload, $APP_SECRET_KEY);
  if (!hash_equals($calcSig, $sig)) return false;

  $stmt = $db->prepare("SELECT id, el_pastas, vardas, role FROM Naudotojas WHERE id = ? AND el_pastas = ? LIMIT 1");
  $uid = (int)$userId;
  $stmt->bind_param("is", $uid, $email);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['email']   = $row['el_pastas'];
    $_SESSION['vardas']  = $row['vardas'];
    $_SESSION['role']    = $row['role'];
    session_regenerate_id(true);
    return true;
  }
  return false;
}

if (!empty($_SESSION['user_id'])) {
  redirect('home.php');
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
  flash('flash_error', "DB klaida: " . h($mysqli->connect_error));
} else {
  $mysqli->set_charset('utf8mb4');

  if (empty($_SESSION['user_id']) && try_auto_login_from_cookie($mysqli)) {
    redirect('home.php');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember_me']);

    if (!$email || $password === '') {
      flash('flash_error', "Įvesk el. paštą ir slaptažodį.");
    } else {
      $stmt = $mysqli->prepare("SELECT id, el_pastas, slaptazodis, vardas, role FROM Naudotojas WHERE el_pastas = ? LIMIT 1");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        if (password_verify($password, $row['slaptazodis'])) {
          $_SESSION['user_id'] = (int)$row['id'];
          $_SESSION['email']   = $row['el_pastas'];
          $_SESSION['vardas']  = $row['vardas'];
          $_SESSION['role']    = $row['role'];
          session_regenerate_id(true);

          if ($remember) {
            issue_remember_cookie((int)$row['id'], $row['el_pastas'], $row['role']);
          } else {
            clear_remember_cookie();
          }

          $mysqli->close();
          redirect('home.php');
        } else {
          flash('flash_error', "Neteisingas slaptažodis.");
        }
      } else {
        flash('flash_error', "Toks el. paštas neregistruotas.");
      }
      $stmt->close();
    }
  }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Prisijungti</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
</head>
<body class="bg-bg min-h-screen flex items-center justify-center p-6">
  <div class="max-w-3xl w-full bg-fg rounded-xl shadow-md overflow-hidden grid">
    <div class="p-8">

      <h2 class="text-2xl font-semibold mb-2 text-purple">Prisijungti</h2>

      <?php if ($msg = flash('flash_error')): ?>
        <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3"><?= h($msg) ?></div>
      <?php endif; ?>

      <form id="form-login" class="space-y-4" method="post" novalidate>
        <div>
          <label for="login-email" class="text-sm font-medium text-fg-font">El. paštas</label>
          <input id="login-email" name="email" type="email" required
                 class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                 placeholder="vardas@pavyzdys.lt"
                 value="<?= isset($_POST['email']) ? h($_POST['email']) : '' ?>">
        </div>
        <div>
          <label for="login-password" class="text-sm font-medium text-fg-font">Slaptažodis</label>
          <input id="login-password" name="password" type="password" required
                 class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font">
        </div>
        <div class="flex items-center justify-between">
          <label class="flex items-center text-sm text-fg-font">
            <input id="remember-me" type="checkbox" name="remember_me" class="mr-2">Prisiminti mane
          </label>
          <span class="text-fg-font">Neturi paskyros? <a href="register.php" class="text-cyan">Registruotis</a></span>
        </div>
        <div>
          <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white rounded">Prisijungti</button>
        </div>
      </form>

    </div>
  </div>
</body>
</html>