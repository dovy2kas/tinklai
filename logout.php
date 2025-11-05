<?php
mb_internal_encoding('UTF-8');

const REMEMBER_COOKIE = 'auth_token';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function expire_cookie(string $name): void {
  setcookie($name, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  ]);
}

expire_cookie(REMEMBER_COOKIE);

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $params['path'] ?? '/',
    'domain'   => $params['domain'] ?? '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}
session_destroy();

session_start();
session_regenerate_id(true);

header('Location: home.php');
exit;
