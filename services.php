<?php
mb_internal_encoding('UTF-8');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash($key, $msg = null) {
  if ($msg === null) { if (!empty($_SESSION[$key])) { $m = $_SESSION[$key]; unset($_SESSION[$key]); return $m; } return ''; }
  $_SESSION[$key] = $msg;
}
function redirect($to){ header("Location: $to"); exit; }

if (empty($_SESSION['user_id'])) {
  redirect('login.php');
}

$DB_HOST = "localhost";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "stud";
$DB_PASS = 'stud';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo "DB klaida: " . h($mysqli->connect_error);
  exit;
}
$mysqli->set_charset('utf8mb4');

$userId = (int)$_SESSION['user_id'];
$stmt = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
  session_unset(); session_destroy();
  redirect('login.php');
}
if ($user['role'] !== 'ELEKTRIKAS') {
  flash('flash_error', 'Prieiga tik elektrikams.');
  redirect('home.php');
}
$loggedIn = true;
$role = $user['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_service') {
  $paslaugaId = isset($_POST['paslauga']) ? (int)$_POST['paslauga'] : 0;
  $kaina = isset($_POST['kaina_bazine']) ? trim($_POST['kaina_bazine']) : '';
  $trukme = isset($_POST['tipine_trukme_min']) ? (int)$_POST['tipine_trukme_min'] : 0;

  if ($paslaugaId <= 0 || $kaina === '' || !is_numeric($kaina) || $trukme <= 0) {
    flash('flash_error', 'Patikrink įvestis: pasirink paslaugą, įvesk kainą ir trukmę (min).');
    redirect('services.php');
  }

  $stmt = $mysqli->prepare("SELECT id FROM Paslauga WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $paslaugaId);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$exists) {
    flash('flash_error', 'Paslauga nerasta.');
    redirect('services.php');
  }

  $stmt = $mysqli->prepare("
      INSERT INTO Pasiula (elektriko_profilis, paslauga, kaina_bazine, tipine_trukme_min)
      VALUES (?, ?, ?, ?)
  ");
  $price = (float)number_format((float)$kaina, 2, '.', '');
  $stmt->bind_param("iidi", $userId, $paslaugaId, $price, $trukme);
  $ok = $stmt->execute();
  $errno = $stmt->errno;
  $err   = $stmt->error;
  $stmt->close();

  if ($ok) {
    flash('flash_success','Paslauga pridėta.');
  } else {
    if ($errno === 1062) {
      flash('flash_error','Ši paslauga jau yra jūsų sąraše.');
    } else {
      flash('flash_error','Nepavyko pridėti paslaugos: '.h($err));
    }
  }
  redirect('services.php');
}

$stmt = $mysqli->prepare("
  SELECT p.paslauga, s.pavadinimas, s.aprasas, p.kaina_bazine, p.tipine_trukme_min
  FROM Pasiula p
  JOIN Paslauga s ON s.id = p.paslauga
  WHERE p.elektriko_profilis = ?
  ORDER BY s.pavadinimas ASC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$offers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $mysqli->prepare("
  SELECT s.id, s.pavadinimas
  FROM Paslauga s
  WHERE s.id NOT IN (SELECT paslauga FROM Pasiula WHERE elektriko_profilis = ?)
  ORDER BY s.pavadinimas ASC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$availableServices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <title>Mano paslaugos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com/"></script>
  <script src="./static/js/colors.js"></script>
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body class="bg-bg min-h-screen">

  <nav class="bg-fg border-purple border-b-2 mb-10">
    <div class="max-w-screen-xl flex flex-wrap items-center justify-between mx-auto p-4">
      <span class="text-2xl font-bold text-purple">Elektrikus vienijanti sistema</span>
      <div class="hidden w-full md:block md:w-auto">
        <ul class="font-medium flex flex-col md:flex-row md:space-x-8">
          <li>
            <a href="home.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
              Pagrindinis
            </a>
          </li>
          <?php if ($role === 'ELEKTRIKAS'): ?>
            <li>
              <a href="services.php" class="block py-2 px-3 text-pink transition duration-150 ease-in">
                Mano paslaugos
              </a>
            </li>
            <li><a href="manage_reservations.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Valdyti rezervacijas</a></li>
            <li><a href="calendar.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Mano kalendorius</a></li>
          <?php endif; ?>
          <li><a href="faq.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">DUK</a></li>
          <?php if ($loggedIn): ?>
            <li>
              <form action="logout.php" method="post">
                <button class="py-2 px-3 rounded-sm bg-red-600 text-comment hover:text-pink transition duration-150 ease-in">
                  Atsijungti
                </button>
              </form>
            </li>
          <?php else: ?>
            <li>
              <a href="login.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Prisijungti
              </a>
            </li>
            <li>
              <a href="register.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Registruotis
              </a>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <main class="max-w-screen-xl mx-auto p-6">
    <?php if ($m = flash('flash_success')): ?>
      <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3"><?= h($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('flash_error')): ?>
      <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3"><?= h($m) ?></div>
    <?php endif; ?>

    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold text-purple">Mano paslaugos</h1>
      <button id="btn-add" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition duration-150 ease-in">Pridėti paslaugą</button>
    </div>

    <?php if (empty($offers)): ?>
      <p class="text-fg-font/80 mb-4">Kol kas neteikiate jokių paslaugų.</p>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($offers as $row): ?>
          <div class="rounded-xl bg-fg shadow p-4">
            <h3 class="text-lg font-semibold text-fg-font mb-1"><?= h($row['pavadinimas']) ?></h3>
            <?php if (!empty($row['aprasas'])): ?>
              <p class="text-sm text-fg-font/80 mb-2"><?= h($row['aprasas']) ?></p>
            <?php endif; ?>
            <div class="text-sm text-fg-font/90">
              <div><span class="font-medium">Kaina:</span> <?= number_format((float)$row['kaina_bazine'], 2, '.', ' ') ?> €</div>
              <div><span class="font-medium">Trukmė:</span> <?= (int)$row['tipine_trukme_min'] ?> min</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <div id="modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="w-full max-w-md rounded-xl bg-fg shadow-lg">
        <div class="flex items-center justify-between p-4 border-b border-bg">
          <h2 class="text-lg font-semibold text-fg-font">Pridėti paslaugą</h2>
          <button id="modal-close" class="p-2 rounded text-fg-font hover:text-pink transition-colors duration-200" aria-label="Uždaryti">
            <i class="bx bx-x text-2xl leading-none"></i>
          </button>
        </div>
        <form method="post" class="p-4 space-y-4">
          <input type="hidden" name="action" value="add_service">
          <div>
            <label class="text-sm font-medium text-fg-font">Paslauga</label>
            <select name="paslauga" required class="mt-1 block w-full rounded px-3 py-2 bg-fg-light text-fg-font">
              <option value="">— Pasirink —</option>
              <?php foreach ($availableServices as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['pavadinimas']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($availableServices)): ?>
              <p class="text-xs text-fg-font/70 mt-1">Visos paslaugos jau pridėtos. Papildyti paslaugų katalogą gali administratorius.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="text-sm font-medium text-fg-font">Kaina (€)</label>
            <input type="number" name="kaina_bazine" step="0.01" min="0" required
                   class="mt-1 block w-full rounded px-3 py-2 bg-fg-light text-fg-font" placeholder="0.00">
          </div>
          <div>
            <label class="text-sm font-medium text-fg-font">Tipinė trukmė (min)</label>
            <input type="number" name="tipine_trukme_min" step="1" min="1" required
                   class="mt-1 block w-full rounded px-3 py-2 bg-fg-light text-fg-font" placeholder="60">
          </div>
          <div class="pt-2 flex justify-end gap-2">
            <button type="button" id="modal-cancel" class="px-4 py-2 bg-fg-light rounded text-fg-font hover:bg-comment transition duration-150 ease-in">Atšaukti</button>
            <button type="submit" class="px-4 py-2 bg-pink text-fg-font rounded hover:bg-green transition duration-150 ease-in">Pridėti</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const modal = document.getElementById('modal');
      const openBtn = document.getElementById('btn-add');
      const closeBtn = document.getElementById('modal-close');
      const cancelBtn = document.getElementById('modal-cancel');

      function openModal(){ modal.classList.remove('hidden'); }
      function closeModal(){ modal.classList.add('hidden'); }

      openBtn?.addEventListener('click', openModal);
      closeBtn?.addEventListener('click', closeModal);
      cancelBtn?.addEventListener('click', closeModal);
      modal.addEventListener('click', (e) => {
        if (e.target === modal.firstElementChild) closeModal();
      });
    })();
  </script>
</body>
</html>
