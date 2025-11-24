<?php
mb_internal_encoding('UTF-8');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'httponly' => true,
  'samesite' => 'Lax', 'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

if (empty($_SESSION['user_id'])) {
  header('Location: login.php?redirect='.urlencode('manage_electricians.php'));
  exit;
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) { http_response_code(500); echo "DB klaida: ".h($mysqli->connect_error); exit; }
$mysqli->set_charset('utf8mb4');

$uid = (int)$_SESSION['user_id'];
$role = null; $vardas = null;

$st = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
$st->bind_param("i", $uid);
$st->execute();
if ($row = $st->get_result()->fetch_assoc()) { $vardas = $row['vardas']; $role = $row['role']; }
$st->close();

if ($role !== 'ADMIN') {
  http_response_code(403);
  echo "Prieiga draudžiama.";
  exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'pending'; // pending | approved | all
if (!in_array($tab, ['pending','approved','all'], true)) $tab = 'pending';

$sql = "
  SELECT e.id AS elektrikas_id, e.statusas, e.savaites_grafikas,
         n.vardas, n.pavarde, n.miestas, n.el_pastas
  FROM ElektrikoProfilis e
  JOIN Naudotojas n ON n.id = e.id
";
if ($tab === 'pending') {
  $sql .= " WHERE e.statusas <> 'PATVIRTINTAS' ";
} elseif ($tab === 'approved') {
  $sql .= " WHERE e.statusas = 'PATVIRTINTAS' ";
}
$sql .= " ORDER BY e.id DESC LIMIT 200";

$res = $mysqli->query($sql);
$list = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <title>Elektrikų tvirtinimas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <style>
    .status-chip{padding:.125rem .5rem;border-radius:.5rem;font-size:.75rem;font-weight:600;display:inline-block}
    .chip-PATVIRTINTAS{background:rgba(34,197,94,.15);color:#22c55e}
    .chip-PENDING,.chip-LAUKIA,.chip-NEPATVIRTINTAS{background:rgba(234,179,8,.15);color:#fbbf24}
    .chip-ATMESTAS{background:rgba(239,68,68,.15);color:#ef4444}
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
        <li><a href="manage_electricians.php" class="block py-2 px-3 text-pink transition duration-150 ease-in">Elektrikų tvirtinimas</a></li>
        <li><a href="faq.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">DUK</a></li>
        <li>
          <form action="logout.php" method="post">
            <button class="py-2 px-3 rounded-sm bg-red-600 text-comment hover:text-pink transition duration-150 ease-in">Atsijungti</button>
          </form>
        </li>
      </ul>
    </div>
  </div>
</nav>

<header class="max-w-screen-xl mx-auto p-6">
  <h1 class="text-3xl font-bold text-purple">Elektrikų tvirtinimas</h1>
</header>

<main class="max-w-screen-xl mx-auto px-6 pb-12">
  <div class="mb-4 flex items-center gap-2">
    <a href="?tab=pending" class="px-3 py-2 rounded <?= $tab==='pending'?'bg-pink text-fg-font':'bg-fg text-fg-font hover:bg-comment' ?>">Laukiantys</a>
    <a href="?tab=approved" class="px-3 py-2 rounded <?= $tab==='approved'?'bg-pink text-fg-font':'bg-fg text-fg-font hover:bg-comment' ?>">Patvirtinti</a>
    <a href="?tab=all" class="px-3 py-2 rounded <?= $tab==='all'?'bg-pink text-fg-font':'bg-fg text-fg-font hover:bg-comment' ?>">Visi</a>
  </div>

  <?php if (empty($list)): ?>
    <div class="rounded-xl bg-fg p-6 shadow text-fg-font/80">Sąrašas tuščias.</div>
  <?php else: ?>
    <div class="rounded-xl bg-fg p-4 shadow">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
          <thead class="text-fg-font/70 border-b border-bg">
            <tr>
              <th class="py-2 px-3">#</th>
              <th class="py-2 px-3">Vardas</th>
              <th class="py-2 px-3">Miestas</th>
              <th class="py-2 px-3">El. paštas</th>
              <th class="py-2 px-3">Būsena</th>
              <th class="py-2 px-3 text-right">Veiksmai</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-bg">
            <?php foreach ($list as $e): ?>
              <?php
                $eid = (int)$e['elektrikas_id'];
                $status = (string)$e['statusas'];
                $isApproved = ($status === 'PATVIRTINTAS');
              ?>
              <tr class="align-top text-fg-font" data-row-id="<?= $eid ?>">
                <td class="py-3 px-3">#<?= $eid ?></td>
                <td class="py-3 px-3"><?= h(($e['vardas'] ?? '').' '.($e['pavarde'] ?? '')) ?></td>
                <td class="py-3 px-3"><?= h($e['miestas'] ?? '') ?></td>
                <td class="py-3 px-3"><?= h($e['el_pastas'] ?? '') ?></td>
                <td class="py-3 px-3">
                  <span class="status-chip <?= 'chip-'.h($status) ?> js-status"><?= h($status) ?></span>
                </td>
                <td class="py-3 px-3 text-right whitespace-nowrap">
                  <?php if ($isApproved): ?>
                    <button
                      class="js-admin-action px-3 py-1.5 rounded bg-pink hover:bg-red text-white transition duration-150 ease-in"
                      data-id="<?= $eid ?>" data-action="unapprove">Panaikinti patvirtinimą</button>
                  <?php else: ?>
                    <button
                      class="js-admin-action px-3 py-1.5 rounded bg-pink text-fg-font hover:bg-green transition duration-150 ease-in"
                      data-id="<?= $eid ?>" data-action="approve">Patvirtinti</button>
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

<div id="adm-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <div id="adm-overlay" class="absolute inset-0 bg-black/50"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div role="dialog" aria-modal="true" aria-labelledby="adm-title"
         class="w-full max-w-lg rounded-xl bg-fg shadow-lg">
      <div class="flex items-center justify-between p-4 border-b border-purple">
        <h3 id="adm-title" class="text-lg font-semibold text-fg-font">Patvirtinti veiksmą</h3>
        <button id="adm-close" type="button"
          class="p-2 rounded text-fg-font hover:text-pink transition-colors duration-200"
          aria-label="Uždaryti">
          <i class="bx bx-x text-2xl leading-none"></i>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <p id="adm-text" class="text-fg-font"></p>
        <div id="adm-error" class="text-red-600 text-sm hidden"></div>
      </div>
      <div class="p-4 flex justify-end gap-2 border-t border-purple">
        <button type="button" id="adm-cancel"
          class="px-4 py-2 bg-fg-light rounded text-fg-font hover:bg-comment transition duration-150 ease-in">
          Atšaukti
        </button>
        <button type="button" id="adm-submit"
          class="px-4 py-2 bg-pink text-fg-font rounded hover:bg-green transition duration-150 ease-in">
          Vykdyti
        </button>
      </div>
    </div>
  </div>
</div>

<script>window.__CSRFM__ = <?= json_encode($csrf) ?>;</script>
<script src="./static/js/manage_electricians.js" defer></script>
</body>
</html>
