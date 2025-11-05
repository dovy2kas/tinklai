<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true,
    'samesite' => 'Lax', 'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  ]);
  session_start();
}

$loggedIn = !empty($_SESSION['user_id']);
$role = $role ?? null;
$vardas = $vardas ?? null;

if ($loggedIn && $role === null) {
  $needClose = false;
  if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $DB_HOST = "db"; $DB_PORT = 3306; $DB_NAME = "tinklai"; $DB_USER = "tinklai"; $DB_PASS = getenv('DB_PASS') ?: '';
    $mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    if (!$mysqli->connect_errno) { $mysqli->set_charset('utf8mb4'); $needClose = true; }
  }
  if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_errno) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) { $vardas = $row['vardas']; $role = $row['role']; }
    $stmt->close();
    if ($needClose) { $mysqli->close(); }
  }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

if (empty($_SESSION['user_id'])) {
  header('Location: login.php?redirect='.urlencode('manage_reservations.php'));
  exit;
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) { http_response_code(500); echo "DB klaida: ".h($mysqli->connect_error); exit; }
$mysqli->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];
$vardas = null; $role = null;

$stmt = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) { $vardas = $row['vardas']; $role = $row['role']; }
$stmt->close();

if ($role !== 'ELEKTRIKAS') {
  http_response_code(403);
  echo "Prieiga draudžiama.";
  exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$allowed = ['','LAUKIA','PATVIRTINTA','ATMESTA','IVYKDYTA'];
if (!in_array($statusFilter, $allowed, true)) $statusFilter = '';

$sql = "
  SELECT
    r.id, r.naudotojas, r.elektriko_profilis, r.paslauga,
    r.pradzia, r.pabaiga, r.statusas, r.pastabos,
    s.pavadinimas AS paslaugos_pavadinimas,
    u.vardas AS n_vardas, u.pavarde AS n_pavarde, u.miestas AS n_miestas
  FROM Rezervacija r
  LEFT JOIN Paslauga s ON s.id = r.paslauga
  LEFT JOIN Naudotojas u ON u.id = r.naudotojas
  WHERE r.elektriko_profilis = ?
";
$types = "i"; $params = [$uid];
if ($statusFilter !== '') { $sql .= " AND r.statusas = ? "; $types .= "s"; $params[] = $statusFilter; }
$sql .= " ORDER BY r.pradzia DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();

function fmtDt($s){
  if (!$s) return '';
  $ts = strtotime($s);
  if ($ts === false) return h($s);
  return date('Y-m-d H:i', $ts);
}
?>
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <title>Valdyti rezervacijas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <style>
    .status-chip{padding:.125rem .5rem;border-radius:.5rem;font-size:.75rem;font-weight:600;display:inline-block}
    .chip-LAUKIA{background:rgba(234,179,8,.15);color:#fbbf24}
    .chip-PATVIRTINTA{background:rgba(34,197,94,.15);color:#22c55e}
    .chip-IVYKDYTA{background:rgba(59,130,246,.15);color:#3b82f6}
    .chip-ATMESTA{background:rgba(239,68,68,.15);color:#ef4444}
    .modal-hidden{display:none}
  </style>
</head>
<body class="bg-bg min-h-screen" data-logged-in="1">
<nav class="bg-fg border-purple border-b-2 mb-10">
  <div class="max-w-screen-xl flex flex-wrap items-center justify-between mx-auto p-4">
    <span class="text-2xl font-bold text-purple">Elektrikus vienijanti sistema</span>
    <div class="hidden w-full md:block md:w-auto">
      <ul class="font-medium flex flex-col md:flex-row md:space-x-8">
        <li><a href="home.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Pagrindinis</a></li>

        <?php if ($role === 'ELEKTRIKAS'): ?>
          <li><a href="services.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Mano paslaugos</a></li>
          <li><a href="manage_reservations.php" class="block py-2 px-3 text-pink">Valdyti rezervacijas</a></li>
        <?php endif; ?>

        <?php if ($role === 'NAUDOTOJAS'): ?>
          <li><a href="reservations.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Mano rezervacijos</a></li>
        <?php endif; ?>

        <?php if ($loggedIn): ?>
          <li>
            <form action="logout.php" method="post">
              <button class="py-2 px-3 rounded-sm bg-red-600 text-comment hover:text-pink transition duration-150 ease-in">Atsijungti</button>
            </form>
          </li>
        <?php else: ?>
          <li><a href="login.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Prisijungti</a></li>
          <li><a href="register.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Registruotis</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

  <header class="max-w-screen-xl mx-auto p-6">
    <h1 class="text-3xl font-bold text-purple">Rezervacijų valdymas</h1>
  </header>

  <main class="max-w-screen-xl mx-auto px-6 pb-12">
    <form class="mb-4 flex items-center gap-3" method="get" action="manage_reservations.php">
      <label for="status" class="text-sm text-fg-font">Būsena</label>
      <select id="status" name="status" class="rounded px-3 py-2 bg-fg-light text-fg-font">
        <option value="" <?= $statusFilter===''?'selected':'' ?>>— Visos —</option>
        <?php foreach (array_slice($allowed,1) as $st): ?>
          <option value="<?= h($st) ?>" <?= $statusFilter===$st?'selected':'' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="px-3 py-2 rounded bg-pink text-fg-font hover:bg-green transition duration-150 ease-in" type="submit">Filtruoti</button>
    </form>

    <?php if (empty($list)): ?>
      <div class="rounded-xl bg-fg p-6 shadow text-fg-font/80">Šiuo metu neturi jokių rezervacijų.</div>
    <?php else: ?>
      <div class="rounded-xl bg-fg p-4 shadow">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm text-left">
            <thead class="text-fg-font/70 border-b border-bg">
              <tr>
                <th class="py-2 px-3">#</th>
                <th class="py-2 px-3">Klientas</th>
                <th class="py-2 px-3">Miestas</th>
                <th class="py-2 px-3">Paslauga</th>
                <th class="py-2 px-3">Pradžia</th>
                <th class="py-2 px-3">Pabaiga</th>
                <th class="py-2 px-3">Būsena</th>
                <th class="py-2 px-3">Pastabos</th>
                <th class="py-2 px-3 text-right">Veiksmai</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-bg">
              <?php foreach ($list as $r): ?>
                <?php
                  $canAct = in_array($r['statusas'], ['LAUKIA'], true);
                  $cname = trim(($r['n_vardas'] ?? '').' '.($r['n_pavarde'] ?? ''));
                ?>
                <tr class="align-top text-fg-font" data-row-id="<?= (int)$r['id'] ?>">
                  <td class="py-3 px-3"><?= (int)$r['id'] ?></td>
                  <td class="py-3 px-3"><?= $cname !== '' ? h($cname) : 'Naudotojas #'.(int)$r['naudotojas'] ?></td>
                  <td class="py-3 px-3"><?= h($r['n_miestas'] ?? '') ?></td>
                  <td class="py-3 px-3"><?= h($r['paslaugos_pavadinimas'] ?? ('Paslauga #'.(int)$r['paslauga'])) ?></td>
                  <td class="py-3 px-3"><?= fmtDt($r['pradzia']) ?></td>
                  <td class="py-3 px-3"><?= fmtDt($r['pabaiga']) ?></td>
                  <td class="py-3 px-3">
                    <span class="status-chip <?= 'chip-'.h($r['statusas']) ?> js-status"><?= h($r['statusas']) ?></span>
                  </td>
                  <td class="py-3 px-3 text-fg-font/80 js-notes"><?= nl2br(h($r['pastabos'] ?? '')) ?></td>
                  <td class="py-3 px-3 text-right whitespace-nowrap">
                    <?php if ($canAct): ?>
                      <button
                        class="js-open-modal px-3 py-1.5 rounded bg-green text-fg-font hover:bg-green/80 transition"
                        data-id="<?= (int)$r['id'] ?>"
                        data-action="confirm"
                      >Patvirtinti</button>
                      <button
                        class="js-open-modal px-3 py-1.5 rounded bg-red-600 text-white hover:bg-red-700 transition ml-2"
                        data-id="<?= (int)$r['id'] ?>"
                        data-action="deny"
                      >Atmesti</button>
                    <?php else: ?>
                      <span class="text-fg-font/50 text-xs">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </main>

  <div id="manage-modal" class="fixed inset-0 z-50 modal-hidden" aria-hidden="true">
    <div id="manage-overlay" class="absolute inset-0 bg-black/50"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="manage-title"
           class="w-full max-w-lg rounded-xl bg-fg shadow-lg">
        <div class="flex items-center justify-between p-4 border-b border-purple">
          <h3 id="manage-title" class="text-lg font-semibold text-fg-font">Patvirtinti arba atmesti</h3>
          <button id="manage-close" type="button"
            class="p-2 rounded text-fg-font hover:text-pink transition-colors duration-200"
            aria-label="Uždaryti">
            <i class="bx bx-x text-2xl leading-none"></i>
          </button>
        </div>
        <div class="p-4 space-y-3">
          <p id="manage-text" class="text-fg-font"></p>
          <label class="block text-sm text-fg-font/80 mb-1" for="manage-comment">Komentaras (pasirinktinai)</label>
          <textarea id="manage-comment" class="w-full rounded bg-fg-light text-fg-font p-3" rows="4" placeholder="Įrašykite komentarą klientui..."></textarea>
          <div id="manage-error" class="text-red-600 text-sm hidden"></div>
        </div>
        <div class="p-4 flex justify-end gap-2 border-t border-purple">
          <button type="button" id="manage-cancel"
            class="px-4 py-2 bg-fg-light rounded text-fg-font hover:bg-comment transition duration-150 ease-in">
            Atšaukti
          </button>
          <button type="button" id="manage-submit"
            class="px-4 py-2 bg-pink text-fg-font rounded hover:bg-green transition duration-150 ease-in">
            Vykdyti
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>window.__CSRFM__ = <?= json_encode($csrf) ?>;</script>
  <script src="./static/js/manage_reservations.js" defer></script>
</body>
</html>
