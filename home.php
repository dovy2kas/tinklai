<?php
mb_internal_encoding('UTF-8');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'httponly' => true,
  'samesite' => 'Lax', 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) { http_response_code(500); echo "DB klaida: ".h($mysqli->connect_error); exit; }
$mysqli->set_charset('utf8mb4');

$role = null; $vardas = null; $loggedIn = !empty($_SESSION['user_id']);
if ($loggedIn) {
  $uid = (int)$_SESSION['user_id'];
  $stmt = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  if ($row = $stmt->get_result()->fetch_assoc()) {
    $vardas = $row['vardas']; $role = $row['role'];
  }
  $stmt->close();
}

$stmt = $mysqli->prepare("
  SELECT e.id AS elektrikas_id, n.vardas, n.pavarde, n.miestas, e.cv, e.nuotraukos
  FROM ElektrikoProfilis e
  JOIN Naudotojas n ON n.id = e.id
  WHERE e.statusas = 'PATVIRTINTAS'
  ORDER BY e.id DESC
  LIMIT 30
");
$stmt->execute();
$feed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();

function photos_from_json($json, $max = 3): array {
  if (!$json) return [];
  $arr = json_decode($json, true);
  if (!is_array($arr)) return [];
  $arr = array_values(array_filter($arr, fn($x) => is_string($x) && $x !== ''));
  return array_slice($arr, 0, $max);
}
?>
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <title>Elektrikus vienijanti sistema</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <style>
    .aspect-square{position:relative;width:100%;padding-bottom:100%}
    .aspect-square > img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:.75rem}
  </style>
</head>
<body class="bg-bg min-h-screen" data-logged-in="<?= $loggedIn ? '1' : '0' ?>">

  <nav class="bg-fg border-purple border-b-2 mb-10">
    <div class="max-w-screen-xl flex flex-wrap items-center justify-between mx-auto p-4">
      <span class="text-2xl font-bold text-purple">Elektrikus vienijanti sistema</span>
      <div class="hidden w-full md:block md:w-auto">
        <ul class="font-medium flex flex-col md:flex-row md:space-x-8">
          <li><a href="home.php" class="block py-2 px-3 text-pink transition duration-150 ease-in">Pagrindinis</a></li>
          <?php if ($role === 'ADMIN'): ?>
            <li><a href="manage_electricians.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Elektrikų tvirtinimas</a></li>
          <?php endif; ?>
          <?php if ($role === 'ELEKTRIKAS'): ?>
            <li><a href="services.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Mano paslaugos</a></li>
            <li><a href="manage_reservations.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Valdyti rezervacijas</a></li>
          <?php endif; ?>
          <?php if ($role === "NAUDOTOJAS"): ?>
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

  <main class="max-w-screen-xl mx-auto px-6 pb-12">
    <?php if (empty($feed)): ?>
      <div class="rounded-xl bg-fg p-6 shadow text-fg-font/80">Kol kas nėra viešų patvirtintų elektrikų.</div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($feed as $card): ?>
          <?php
            $eid = (int)$card['elektrikas_id'];
            $ph  = photos_from_json($card['nuotraukos'], 3);
            $cv  = trim((string)$card['cv']);
            if ($cv !== '' && mb_strlen($cv) > 180) $cv = mb_substr($cv, 0, 250) . '…';
            $reserveHref = !empty($_SESSION['user_id'])
              ? ('reserve.php?elektrikas='.$eid)
              : ('login.php?redirect='.urlencode('reserve.php?elektrikas='.$eid));
          ?>
          <article class="rounded-xl bg-fg shadow overflow-hidden flex flex-col h-full">
            <div class="p-4 flex items-center justify-between gap-2">
              <div>
                <h3 class="text-lg font-semibold text-fg-font"><?= h($card['vardas'].' '.$card['pavarde']) ?></h3>
                <p class="text-sm text-fg-font/70"><?= h($card['miestas']) ?></p>
              </div>
              <div class="flex gap-2">
                <button
                  type="button"
                  class="px-3 py-2 rounded bg-fg-light hover:bg-comment text-sm text-fg-font js-view-services transition duration-150 ease-in"
                  data-elektrikas="<?= $eid ?>">
                  Peržiūrėti paslaugas
                </button>
              </div>
            </div>

            <?php if (!empty($ph)): ?>
              <div class="grid grid-cols-3 gap-1 p-2">
                <?php foreach ($ph as $i => $src): ?>
                  <div class="aspect-square col-span-<?= $i === 0 ? '3 md:2' : '1' ?>">
                    <img src="<?= h($src) ?>" alt="Elektriko darbo nuotrauka">
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="p-4 pt-0 text-fg-font/60 text-sm">Nėra darbų nuotraukų.</div>
            <?php endif; ?>

            <?php if ($cv !== ''): ?>
              <div class="px-4 pb-4 text-sm text-fg-font/90"><?= nl2br(h($cv)) ?></div>
            <?php endif; ?>

            <?php if ($loggedIn && $role !== 'ELEKTRIKAS'): ?>
              <div class="mt-auto p-4">
                <button
                  type="button"
                  class="js-reserve-link px-3 py-2 rounded bg-purple text-white hover:bg-green text-sm text-center w-full transition duration-150 ease-in"
                  data-elektrikas="<?= (int)$eid ?>">
                  Rezervuoti konsultaciją
                </button>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <div id="svc-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/50"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="svc-title"
           class="w-full max-w-2xl rounded-xl bg-fg shadow-lg">
        <div class="flex items-center justify-between p-4 border-b border-purple">
          <h3 id="svc-title" class="text-lg font-semibold text-fg-font">Paslaugos</h3>
            <button id="svc-close" type="button"
            class="p-2 rounded text-fg-font hover:text-pink transition-colors duration-200"
            aria-label="Uždaryti">
            <i class="bx bx-x text-2xl leading-none"></i>
            </button>
        </div>
        <div class="p-0">
          <div id="svc-loading" class="p-6 text-fg-font/80">Kraunama…</div>
          <div id="svc-content" class="divide-y divide-bg hidden"></div>
          <div id="svc-error" class="p-6 text-red hidden"></div>
        </div>
        <div class="p-4 flex justify-end gap-2">
          <a id="svc-reserve" href="#" class="px-4 py-2 bg-indigo-600 text-white rounded hidden">Rezervuoti konsultaciją</a>
          <button type="button" class="px-4 py-2 bg-fg-light rounded text-fg-font hover:bg-comment transition duration-150 ease-in" id="svc-ok">Uždaryti</button>
        </div>
      </div>
    </div>
  </div>

<div id="resv-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <div class="absolute inset-0 bg-black/50"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div role="dialog" aria-modal="true" aria-labelledby="resv-title"
         class="w-full max-w-xl rounded-xl bg-fg shadow-lg">
      <div class="flex items-center justify-between p-4 border-b border-purple">
        <h3 id="resv-title" class="text-lg font-semibold text-fg-font">Pasirinkite laiką</h3>
        <button id="resv-close" type="button"
          class="p-2 rounded text-fg-font hover:text-pink transition-colors duration-200"
          aria-label="Uždaryti">
          <i class="bx bx-x text-2xl leading-none"></i>
        </button>
      </div>

      <div class="p-4 space-y-4">
        <div class="flex items-center gap-3">
          <label for="resv-service" class="text-sm text-fg-font whitespace-nowrap">Paslauga</label>
          <select id="resv-service" class="rounded px-3 py-2 bg-fg-light text-fg-font w-full">
            <option value="">— Pasirink —</option>
          </select>
        </div>

        <div class="flex items-center gap-3">
          <label for="resv-date" class="text-sm text-fg-font whitespace-nowrap">Data</label>
          <input id="resv-date" type="date" class="rounded px-3 py-2 bg-fg-light text-fg-font" />
        </div>

        <div id="resv-loading" class="text-fg-font/80 hidden">Kraunama…</div>
        <div id="resv-error" class="text-red hidden" role="status" aria-live="polite"></div>
        <div id="resv-slots" class="grid grid-cols-2 md:grid-cols-3 gap-2"></div>
      </div>

      <div class="p-4 flex justify-end gap-2 border-t border-purple">
        <button type="button"
                class="px-4 py-2 bg-fg-light rounded text-fg-font hover:bg-comment transition duration-150 ease-in"
                id="resv-cancel">Atšaukti</button>

        <button id="resv-next" type="button"
                class="px-4 py-2 bg-pink text-fg-font rounded disabled:opacity-50 hover:bg-green transition duration-150 ease-in"
                disabled>
          Tęsti
        </button>
      </div>
    </div>
  </div>
</div>

  <script src="./static/js/home.js" defer></script>
</body>
</html>
