<?php
mb_internal_encoding('UTF-8');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'httponly' => true,
  'samesite' => 'Lax', 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$DB_HOST = "localhost";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "stud";
$DB_PASS = 'stud';

if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($mysqli->connect_errno) { http_response_code(500); echo "DB klaida: ".h($mysqli->connect_error); exit; }
$mysqli->set_charset('utf8mb4');

$uid  = (int)$_SESSION['user_id'];
$role = null; $vardas = null;

$stmt = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
  $vardas = $row['vardas'];
  $role   = $row['role'];
}
$stmt->close();

if ($role !== 'ELEKTRIKAS') {
  http_response_code(403);
  echo "Tik elektrikai gali peržiūrėti ir keisti kalendorių.";
  exit;
}

$elektrikasId = $uid;

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$errors = [];
$success = null;

function is_valid_time(string $t): bool {
  if (!preg_match('/^([0-2]\d):([0-5]\d)$/', $t, $m)) return false;
  $h = (int)$m[1];
  $m2 = (int)$m[2];
  if ($h > 23) return false;
  return true;
}

function is_30_step_time(string $t): bool {
  if (!preg_match('/^(\d{2}):(\d{2})$/', $t, $m)) return false;
  $min = (int)$m[2];
  return $min === 0 || $min === 30;
}

function render_time_select(string $name, string $selected = '', bool $allowEmpty = false, string $classes = ''): void {
  echo '<select name="'.h($name).'" class="'.h($classes).'">';
  if ($allowEmpty) {
    echo '<option value="">—</option>';
  }
  for ($h = 0; $h < 24; $h++) {
    foreach ([0, 30] as $m) {
      $value = sprintf('%02d:%02d', $h, $m);
      $sel   = ($selected === $value) ? ' selected' : '';
      $label = $value;
      echo '<option value="'.h($value).'"'.$sel.'>'.h($label).'</option>';
    }
  }
  echo '</select>';
}

function intervals_to_string(?array $intervals): string {
  if (!is_array($intervals) || !$intervals) return '';
  $parts = [];
  foreach ($intervals as $interval) {
    if (!is_array($interval) || count($interval) < 2) continue;
    $parts[] = $interval[0].'-'.$interval[1];
  }
  return implode(',', $parts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
    $errors[] = "Įvyko klaida. Perkrauk puslapį ir bandyk dar kartą.";
  } else {
    $scheduleWeek = [];
    for ($d = 1; $d <= 7; $d++) {
      $enabled = !empty($_POST['day_'.$d.'_enabled']);
      $from    = isset($_POST['day_'.$d.'_from']) ? trim((string)$_POST['day_'.$d.'_from']) : '';
      $to      = isset($_POST['day_'.$d.'_to'])   ? trim((string)$_POST['day_'.$d.'_to'])   : '';

      if (!$enabled) continue;

      if ($from === '' || $to === '') {
        $errors[] = "Nenurodytas pradžios arba pabaigos laikas dienai {$d}.";
        continue;
      }
      if (!is_valid_time($from) || !is_valid_time($to)) {
        $errors[] = "Blogas laiko formatas dienai {$d}.";
        continue;
      }
      if (!is_30_step_time($from) || !is_30_step_time($to)) {
        $errors[] = "Laikas dienai {$d} turi būti kas 30 min (pvz. 09:00, 09:30, 10:00).";
        continue;
      }
      if ($from >= $to) {
        $errors[] = "Pradžia turi būti ankstesnė už pabaigą dienai {$d}.";
        continue;
      }

      $scheduleWeek[(string)$d] = [[$from, $to]];
    }

    $specialDates = [];
    $dates = isset($_POST['extra_date']) ? (array)$_POST['extra_date'] : [];
    $froms = isset($_POST['extra_from']) ? (array)$_POST['extra_from'] : [];
    $tos   = isset($_POST['extra_to'])   ? (array)$_POST['extra_to']   : [];
    $count = max(count($dates), count($froms), count($tos));
    $today = date('Y-m-d');

    for ($i = 0; $i < $count; $i++) {
      $date = isset($dates[$i]) ? trim((string)$dates[$i]) : '';
      $from = isset($froms[$i]) ? trim((string)$froms[$i]) : '';
      $to   = isset($tos[$i])   ? trim((string)$tos[$i])   : '';

      if ($date === '' && $from === '' && $to === '') continue;

      if ($date === '') {
        $errors[] = "Nenurodyta data vienoje iš išimčių eilučių.";
        continue;
      }
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = "Blogas datos formatas: „".h($date)."“. Naudok YYYY-MM-DD.";
        continue;
      }

      if ($date < $today) {
        $errors[] = "Negalima kurti išimties praeityje ({$date}).";
        continue;
      }

      if ($from === '' && $to === '') {
        $specialDates[$date] = [];
        continue;
      }

      if ($from === '' || $to === '') {
        $errors[] = "Datai {$date} turi būti nurodyti ir pradžios, ir pabaigos laikai, arba palikti abu tušti.";
        continue;
      }
      if (!is_valid_time($from) || !is_valid_time($to)) {
        $errors[] = "Blogas laiko formatas datai {$date}.";
        continue;
      }
      if (!is_30_step_time($from) || !is_30_step_time($to)) {
        $errors[] = "Laikas datai {$date} turi būti kas 30 min (pvz. 09:00, 09:30, 10:00).";
        continue;
      }
      if ($from >= $to) {
        $errors[] = "Pradžia turi būti ankstesnė už pabaigą datai {$date}.";
        continue;
      }

      $specialDates[$date] = [[$from, $to]];
    }

    if (empty($errors)) {
      $payload = $scheduleWeek;
      if (!empty($specialDates)) {
        $payload['dates'] = $specialDates;
      }

      $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
      if ($json === false) {
        $errors[] = "Nepavyko išsaugoti grafiko (JSON klaida).";
      } else {
        $stmt = $mysqli->prepare("UPDATE ElektrikoProfilis SET savaites_grafikas = ? WHERE id = ?");
        $stmt->bind_param("si", $json, $elektrikasId);
        if ($stmt->execute()) {
          $success = "Grafikas sėkmingai išsaugotas.";
        } else {
          $errors[] = "DB klaida saugant grafiką: ".h($stmt->error);
        }
        $stmt->close();
      }
    }
  }
}

$currentScheduleWeek = [];
$currentSpecialDates = [];
$needsCleanup = false;

$stmt = $mysqli->prepare("SELECT savaites_grafikas FROM ElektrikoProfilis WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $elektrikasId);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
  if (!empty($row['savaites_grafikas'])) {
    $decoded = json_decode($row['savaites_grafikas'], true);
    if (is_array($decoded)) {
      foreach ($decoded as $k => $v) {
        if ($k === 'dates') {
          if (is_array($v)) {
            $today = date('Y-m-d');
            foreach ($v as $d => $intervals) {
              if ($d >= $today) {
                $currentSpecialDates[$d] = $intervals;
              } else {
                $needsCleanup = true;
              }
            }
          }
        } elseif (ctype_digit((string)$k)) {
          $currentScheduleWeek[(string)$k] = $v;
        }
      }
    }
  }
}
$stmt->close();

if ($needsCleanup) {
  $payload = $currentScheduleWeek;
  if (!empty($currentSpecialDates)) {
    $payload['dates'] = $currentSpecialDates;
  }
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($json !== false) {
    $stmt = $mysqli->prepare("UPDATE ElektrikoProfilis SET savaites_grafikas = ? WHERE id = ?");
    $stmt->bind_param("si", $json, $elektrikasId);
    $stmt->execute();
    $stmt->close();
  }
}

$mysqli->close();

$dayLabels = [
  1 => 'Pirmadienis',
  2 => 'Antradienis',
  3 => 'Trečiadienis',
  4 => 'Ketvirtadienis',
  5 => 'Penktadienis',
  6 => 'Šeštadienis',
  7 => 'Sekmadienis',
];
?>
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <title>Mano kalendorius</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body class="bg-bg min-h-screen" data-logged-in="1">

  <nav class="bg-fg border-purple border-b-2 mb-10">
    <div class="max-w-screen-xl flex flex-wrap items-center justify-between mx-auto p-4">
      <span class="text-2xl font-bold text-purple">Elektrikus vienijanti sistema</span>
      <div class="hidden w-full md:block md:w-auto">
        <ul class="font-medium flex flex-col md:flex-row md:space-x-8">
          <li><a href="home.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Pagrindinis</a></li>
          <li><a href="services.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Mano paslaugos</a></li>
          <li><a href="manage_reservations.php" class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">Valdyti rezervacijas</a></li>
          <li><a href="calendar.php" class="block py-2 px-3 text-pink transition duration-150 ease-in">Mano kalendorius</a></li>
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
    <h1 class="text-3xl font-bold text-purple">Mano kalendorius</h1>
    <p class="text-fg-font/80">
      Čia gali nustatyti savaitinį darbo grafiką ir konkrečių dienų išimtis.
    </p>
  </header>

  <main class="max-w-screen-xl mx-auto px-6 pb-12 space-y-8">

    <?php if (!empty($errors)): ?>
      <div class="mb-4 rounded border border-purple bg-fg text-red px-4 py-3">
          <?php foreach ($errors as $e):
            echo $e;
          endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3">
        <?= h($success) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-8">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

      <section class="rounded-xl bg-fg p-6 shadow">
        <h2 class="text-xl font-semibold text-fg-font mb-4">Savaitės darbo grafikas</h2>
        <p class="text-sm text-fg-font/75 mb-4">
          Pažymėk, kuriomis savaitės dienomis dirbi, ir pasirink laiką.
          Jei dienos nepažymi – tą dieną nedirbi.
        </p>

        <div class="space-y-3">
          <?php for ($d = 1; $d <= 7; $d++): ?>
            <?php
              $key   = (string)$d;
              $intervals = $currentScheduleWeek[$key] ?? [];
              $first = (is_array($intervals) && isset($intervals[0]) && is_array($intervals[0])) ? $intervals[0] : [null, null];
              $fromVal = $first[0] ?? '';
              $toVal   = $first[1] ?? '';
              $enabled = ($fromVal !== '' && $toVal !== '');
            ?>
            <div class="flex flex-col md:flex-row md:items-center gap-3 border border-bg rounded-lg px-3 py-2">
              <div class="flex items-center gap-2 md:w-52">
                <input
                  id="day_<?= $d ?>_enabled"
                  type="checkbox"
                  name="day_<?= $d ?>_enabled"
                  class="w-4 h-4 accent-purple-600"
                  <?= $enabled ? 'checked' : '' ?>
                />
                <label for="day_<?= $d ?>_enabled" class="text-sm font-medium text-fg-font">
                  <?= h($dayLabels[$d]) ?>
                </label>
              </div>
              <div class="flex items-center gap-2 flex-1">
                <label class="text-xs text-fg-font/70">Nuo</label>
                <?php render_time_select('day_'.$d.'_from', $fromVal, false, 'rounded px-2 py-1 bg-fg-light text-fg-font text-sm w-full md:w-auto'); ?>
                <label class="text-xs text-fg-font/70">Iki</label>
                <?php render_time_select('day_'.$d.'_to', $toVal, false, 'rounded px-2 py-1 bg-fg-light text-fg-font text-sm w-full md:w-auto'); ?>
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </section>

      <section class="rounded-xl bg-fg p-6 shadow">
        <h2 class="text-xl font-semibold text-fg-font mb-4">Konkrečių dienų išimtys</h2>
        <p class="text-sm text-fg-font/75 mb-3">
          Čia gali nurodyti konkrečias datas, kada darbo laikas skiriasi nuo įprasto.
          <br>
          Jei nurodysi datą, bet abu laiko laukus paliksi tuščius – tą dieną būsi visai nedirbantis.
        </p>

        <div id="special-rows" class="space-y-3">
          <?php
            $existingDates = $currentSpecialDates;
            ksort($existingDates);
            foreach ($existingDates as $date => $intervals):
              $first = (is_array($intervals) && isset($intervals[0]) && is_array($intervals[0])) ? $intervals[0] : [null, null];
              $fromVal = $first[0] ?? '';
              $toVal   = $first[1] ?? '';
          ?>
            <div class="flex flex-col md:flex-row md:items-center gap-2 special-row">
              <div class="flex-1 md:max-w-xs">
                <label class="block text-xs text-fg-font/70 mb-0.5">Data</label>
                <input type="date"
                       name="extra_date[]"
                       value="<?= h($date) ?>"
                       class="w-full rounded px-3 py-2 bg-fg-light text-fg-font text-sm" />
              </div>
              <div class="flex-1 flex items-center gap-2">
                <div class="flex-1">
                  <label class="block text-xs text-fg-font/70 mb-0.5">Nuo</label>
                  <?php render_time_select('extra_from[]', $fromVal, true, 'w-full rounded px-3 py-2 bg-fg-light text-fg-font text-sm'); ?>
                </div>
                <div class="flex-1">
                  <label class="block text-xs text-fg-font/70 mb-0.5">Iki</label>
                  <?php render_time_select('extra_to[]', $toVal, true, 'w-full rounded px-3 py-2 bg-fg-light text-fg-font text-sm'); ?>
                </div>
              </div>
              <button type="button"
                      class="remove-row px-3 py-2 rounded bg-red-600 text-white text-xs hover:bg-red-700 transition self-start md:self-end">
                Pašalinti
              </button>
            </div>
          <?php endforeach; ?>

          <div class="flex flex-col md:flex-row md:items-center gap-2 special-row">
            <div class="flex-1 md:max-w-xs">
              <label class="block text-xs text-fg-font/70 mb-0.5">Data</label>
              <input type="date"
                     name="extra_date[]"
                     value=""
                     class="w-full rounded px-3 py-2 bg-fg-light text-fg-font text-sm" />
            </div>
            <div class="flex-1 flex items-center gap-2">
              <div class="flex-1">
                <label class="block text-xs text-fg-font/70 mb-0.5">Nuo</label>
                <?php render_time_select('extra_from[]', '', true, 'w-full rounded px-3 py-2 bg-fg-light text-fg-font text-sm'); ?>
              </div>
              <div class="flex-1">
                <label class="block text-xs text-fg-font/70 mb-0.5">Iki</label>
                <?php render_time_select('extra_to[]', '', true, 'w-full rounded px-3 py-2 bg-fg-light text-fg-font text-sm'); ?>
              </div>
            </div>
            <button type="button"
                    class="remove-row px-3 py-2 rounded bg-red-600 text-white text-xs hover:bg-red-700 transition self-start md:self-end">
              Pašalinti
            </button>
          </div>
        </div>

        <button type="button"
                id="add-special"
                class="mt-4 px-4 py-2 rounded bg-purple text-white hover:bg-green transition duration-150 ease-in">
          Pridėti dar vieną datą
        </button>
      </section>

      <div class="pt-2">
        <button type="submit"
                class="px-4 py-2 rounded bg-pink text-fg-font hover:bg-green transition duration-150 ease-in">
          Išsaugoti grafiko pakeitimus
        </button>
      </div>
    </form>
  </main>

<script scr="./static/js/calendar.js"></script>
</body>
</html>
