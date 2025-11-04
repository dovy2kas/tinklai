<?php
session_start();
mb_internal_encoding('UTF-8');

$DB_HOST = "db";
$DB_PORT = 3306;
$DB_NAME = "tinklai";
$DB_USER = "tinklai";
$DB_PASS = getenv('DB_PASS') ?: '';

function flash_and_redirect($key, $msg, $redirect = null) {
  $_SESSION[$key] = $msg;
  header("Location: " . ($redirect ?: $_SERVER['PHP_SELF']));
  exit;
}

function build_week_schedule_from_post($key = 'grafikas'): array {
  $result = [];
  if (!isset($_POST[$key]) || !is_array($_POST[$key])) return $result;
  for ($d = 1; $d <= 7; $d++) {
    if (!isset($_POST[$key][$d])) continue;
    $row = $_POST[$key][$d];
    $enabled = isset($row['enabled']);
    $from = isset($row['nuo']) ? trim($row['nuo']) : '';
    $to   = isset($row['iki']) ? trim($row['iki']) : '';
    if ($enabled && $from !== '' && $to !== '') {
      $result[(string)$d] = [[$from, $to]];
    }
  }
  return $result;
}

function handle_photos_upload($input = 'nuotraukos', $targetDir = __DIR__ . '/uploads'): array {
  if (!isset($_FILES[$input])) return [];
  if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
  $files = $_FILES[$input];
  $saved = [];
  $count = is_array($files['name']) ? count($files['name']) : 0;
  for ($i=0; $i<$count && count($saved)<3; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
    $type = mime_content_type($files['tmp_name'][$i]);
    if (!preg_match('#^image/(jpeg|png|webp)$#i', $type)) continue;
    $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
      if ($type === 'image/jpeg') $ext = 'jpg';
      elseif ($type === 'image/png') $ext = 'png';
      elseif ($type === 'image/webp') $ext = 'webp';
      else continue;
    }
    $fname = 'work_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = rtrim($targetDir,'/').'/'.$fname;
    if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
      $saved[] = 'uploads/'.$fname;
    }
  }
  return $saved;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email   = filter_input(INPUT_POST, 'el_pastas', FILTER_VALIDATE_EMAIL);
  $tel     = trim($_POST['tel'] ?? '');
  $pass    = $_POST['slaptazodis'] ?? '';
  $pass2   = $_POST['slaptazodis_pakartoti'] ?? '';
  $vardas  = trim($_POST['vardas'] ?? '');
  $pavarde = trim($_POST['pavarde'] ?? '');
  $miestas = trim($_POST['miestas'] ?? '');
  $role    = ($_POST['role'] ?? 'NAUDOTOJAS') === 'ELEKTRIKAS' ? 'ELEKTRIKAS' : 'NAUDOTOJAS';

  if (!$email) flash_and_redirect('flash_error', "Neteisingas el. pašto formatas.");
  if (strlen($pass) < 8) flash_and_redirect('flash_error', "Slaptažodis turi būti bent 8 simbolių.");
  if ($pass !== $pass2) flash_and_redirect('flash_error', "Slaptažodžiai nesutampa.");
  if ($vardas === '' || $pavarde === '' || $miestas === '' || $tel === '') {
    flash_and_redirect('flash_error', "Užpildyk visus privalomus laukus.");
  }

  $cv = trim($_POST['cv_trumpas'] ?? '');
  $nuotraukos = $role === 'ELEKTRIKAS' ? handle_photos_upload('nuotraukos') : [];
  $grafikas   = $role === 'ELEKTRIKAS' ? build_week_schedule_from_post('grafikas') : [];

  $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
  if ($mysqli->connect_errno) {
    flash_and_redirect('flash_error', "Nepavyko prisijungti prie DB: " . $mysqli->connect_error);
  }
  $mysqli->set_charset('utf8mb4');

  $mysqli->begin_transaction();
  try {
    $stmt = $mysqli->prepare("SELECT id FROM Naudotojas WHERE el_pastas = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
      $stmt->close();
      $mysqli->rollback();
      flash_and_redirect('flash_error', "Toks el. paštas jau registruotas.");
    }
    $stmt->close();

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare("
      INSERT INTO Naudotojas (el_pastas, slaptazodis, vardas, pavarde, miestas, tel, role)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssssss", $email, $hash, $vardas, $pavarde, $miestas, $tel, $role);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
      throw new Exception("Nepavyko sukurti naudotojo.");
    }
    $userId = $stmt->insert_id;
    $stmt->close();

    if ($role === 'ELEKTRIKAS') {
      $cvParam   = ($cv !== '') ? $cv : null;
      $photosStr = !empty($nuotraukos) ? json_encode($nuotraukos, JSON_UNESCAPED_UNICODE) : null;
      $schedStr  = !empty($grafikas) ? json_encode($grafikas, JSON_UNESCAPED_UNICODE) : null;

      $stmt = $mysqli->prepare("
        INSERT INTO ElektrikoProfilis (id, statusas, cv, nuotraukos, savaites_grafikas, rodomas_viesai)
        VALUES (?, 'LAUKIANTIS', ?, ?, ?, 0)
      ");
      $stmt->bind_param("isss", $userId, $cvParam, $photosStr, $schedStr);
      $stmt->execute();
      if ($stmt->affected_rows !== 1) {
        throw new Exception("Nepavyko sukurti elektriko profilio.");
      }
      $stmt->close();
    }

    $mysqli->commit();
    $mysqli->close();
    flash_and_redirect('flash_success', "Paskyra sukurta. Galite prisijungti.", 'login.php');

  } catch (Throwable $e) {
    $mysqli->rollback();
    $mysqli->close();
    flash_and_redirect('flash_error', "Klaida registruojant: " . $e->getMessage());
  }
}
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Registruotis</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
</head>
<body class="bg-bg min-h-screen flex items-center justify-center p-6">
  <div class="max-w-3xl w-full bg-fg rounded-xl shadow-md overflow-hidden">
    <div class="p-8">
      <h2 class="text-2xl font-semibold mb-6 text-purple">Registruotis</h2>

      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3">
          <?= htmlspecialchars($_SESSION['flash_error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_error']); ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3">
          <?= htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_success']); ?>
        </div>
      <?php endif; ?>

      <form id="form-register" class="space-y-8" method="post" enctype="multipart/form-data">

        <fieldset class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <legend class="col-span-1 md:col-span-2 text-sm font-semibold text-fg-font/70 mb-1">Paskyros duomenys</legend>

          <div class="md:col-span-1">
            <label for="email" class="text-sm font-medium text-fg-font">El. paštas</label>
            <input id="email" name="el_pastas" type="email" required
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="vardas@pavyzdys.lt">
          </div>

          <div class="md:col-span-1">
            <label for="tel" class="text-sm font-medium text-fg-font">Telefono numeris</label>
            <input id="tel" name="tel" type="tel"
                   pattern="^\+?[0-9\s\-()]{6,}$"
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="+370 6xx xxxxx" required>
          </div>

          <div>
            <label for="password" class="text-sm font-medium text-fg-font">Slaptažodis</label>
            <input id="password" name="slaptazodis" type="password" required minlength="8"
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="Mažiausiai 8 simboliai">
          </div>

          <div>
            <label for="password2" class="text-sm font-medium text-fg-font">Pakartok slaptažodį</label>
            <input id="password2" name="slaptazodis_pakartoti" type="password" required minlength="8"
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="Pakartok slaptažodį">
          </div>

          <div>
            <label for="vardas" class="text-sm font-medium text-fg-font">Vardas</label>
            <input id="vardas" name="vardas" type="text" required
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="Vardenis">
          </div>

          <div>
            <label for="pavarde" class="text-sm font-medium text-fg-font">Pavardė</label>
            <input id="pavarde" name="pavarde" type="text" required
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="Pavardenis">
          </div>

          <div class="md:col-span-1">
            <label for="miestas" class="text-sm font-medium text-fg-font">Miestas</label>
            <input id="miestas" name="miestas" type="text" required
                   class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                   placeholder="Vilnius">
          </div>

          <div class="md:col-span-1">
            <span class="text-sm font-medium text-fg-font">Rolė</span>
            <div class="mt-2 flex items-center gap-6">
              <label class="inline-flex items-center gap-2">
                <input type="radio" name="role" value="NAUDOTOJAS" class="accent-indigo-600" checked>
                <span class="text-fg-font">Naudotojas</span>
              </label>
              <label class="inline-flex items-center gap-2">
                <input type="radio" name="role" value="ELEKTRIKAS" class="accent-indigo-600">
                <span class="text-fg-font">Elektrikas</span>
              </label>
            </div>
          </div>
        </fieldset>

        <fieldset id="electrician-section" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">
          <legend class="col-span-1 md:col-span-2 text-sm font-semibold text-fg-font/70 mb-1">Elektriko profilis</legend>

          <div class="md:col-span-2">
            <label for="cv_trumpas" class="text-sm font-medium text-fg-font">Trumpas CV / aprašymas</label>
            <textarea id="cv_trumpas" name="cv_trumpas" rows="5"
                      class="mt-1 block w-full rounded px-3 py-2 focus:outline-none bg-fg-light text-fg-font"
                      placeholder="Patirtis, kvalifikacijos, specializacijos..."></textarea>
          </div>

          <div class="md:col-span-2">
            <label for="nuotraukos" class="text-sm font-medium text-fg-font">Darbų nuotraukos (iki 3)</label>
            <input id="nuotraukos" name="nuotraukos[]" type="file" accept="image/*" multiple
                   class="mt-1 block w-full rounded px-3 py-2 bg-fg-light text-fg-font">
            <p class="text-xs text-fg-font/70 mt-1">Leidžiama įkelti iki 3 nuotraukų. Jei įkelsi daugiau, bus saugomos pirmos 3.</p>
          </div>

          <div class="md:col-span-2">
            <span class="text-sm font-medium text-fg-font">Savaitės grafikas</span>
            <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div class="border rounded p-3 bg-fg-light">
                <label class="font-medium">Pirmadienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[1][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[1][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[1][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
              <div class="border rounded p-3 bg-fg-light">
                <label class="font-medium">Antradienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[2][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[2][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[2][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
              <div class="border rounded p-3 bg-fg-light">
                <label class="font-medium">Trečiadienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[3][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[3][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[3][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
              <div class="border rounded p-3 bg-fg-light">
                <label class="font-medium">Ketvirtadienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[4][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[4][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[4][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
              <div class="border rounded p-3 bg-fg-light">
                <label class="font-medium">Penktadienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[5][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[5][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[5][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
              <div class="border rounded p-3 bg-fg-light">
                <label class="font-medium">Šeštadienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[6][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[6][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[6][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
              <div class="border rounded p-3 bg-fg-light md:col-span-2">
                <label class="font-medium">Sekmadienis</label>
                <div class="mt-2 flex items-center gap-2">
                  <input type="checkbox" name="grafikas[7][enabled]" class="accent-indigo-600">
                  <input type="time" name="grafikas[7][nuo]" class="rounded px-2 py-1 bg-fg text-fg-font">
                  <span>–</span>
                  <input type="time" name="grafikas[7][iki]" class="rounded px-2 py-1 bg-fg text-fg-font">
                </div>
              </div>
            </div>
          </div>

        </fieldset>

        <div class="flex items-center justify-between">
          <span class="text-fg-font">Jau turi paskyrą? <a href="login.php" class="text-cyan underline">Prisijungti</a></span>
          <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded">Registruotis</button>
        </div>
      </form>
    </div>
  </div>

  <div id="app-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div id="app-modal-overlay" class="absolute inset-0 bg-black/50"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-describedby="modal-desc"
           class="w-full max-w-md rounded-xl bg-fg shadow-lg">
        <div class="flex items-start justify-between p-4 border-b border-bg">
          <h3 id="modal-title" class="text-lg font-semibold text-fg-font">Pranešimas</h3>
          <button id="modal-close" type="button" class="p-2 rounded hover:bg-bg" aria-label="Uždaryti">✕</button>
        </div>
        <div class="p-4">
          <p id="modal-desc" class="text-fg-font"></p>
        </div>
        <div class="p-4 pt-0 flex justify-end gap-2">
          <button id="modal-ok" type="button" class="px-4 py-2 bg-indigo-600 text-white rounded">Gerai</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const roleInputs = document.querySelectorAll('input[name="role"]');
      const electricianSection = document.getElementById('electrician-section');
      const toggleSection = () => {
        const role = document.querySelector('input[name="role"]:checked')?.value;
        electricianSection.classList.toggle('hidden', role !== 'ELEKTRIKAS');
      };
      roleInputs.forEach(r => r.addEventListener('change', toggleSection));
      toggleSection();

      const modal = document.getElementById('app-modal');
      const overlay = document.getElementById('app-modal-overlay');
      const closeBtn = document.getElementById('modal-close');
      const okBtn = document.getElementById('modal-ok');
      const desc = document.getElementById('modal-desc');
      let lastFocused = null;

      function openModal(message) {
        lastFocused = document.activeElement;
        desc.textContent = message;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        okBtn.focus();
        function trap(e){
          const focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
          const first = focusables[0];
          const last = focusables[focusables.length - 1];
          if (e.key === 'Tab') {
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
          } else if (e.key === 'Escape') closeModal();
        }
        modal.addEventListener('keydown', trap);
        modal.dataset.trap = '1';
      }
      function closeModal() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        if (lastFocused) lastFocused.focus();
      }
      overlay.addEventListener('click', closeModal);
      closeBtn.addEventListener('click', closeModal);
      okBtn.addEventListener('click', closeModal);

      const form = document.getElementById('form-register');
      form.addEventListener('submit', (e) => {
        const p1 = document.getElementById('password').value;
        const p2 = document.getElementById('password2').value;
        if (p1 !== p2) {
          e.preventDefault();
          openModal('Slaptažodžiai nesutampa. Patikrink ir bandyk dar kartą.');
        }
      });

      const photos = document.getElementById('nuotraukos');
      if (photos) {
        photos.addEventListener('change', () => {
          if (photos.files.length > 3) {
            openModal('Leidžiama įkelti iki 3 nuotraukas.');
          }
        });
      }
    })();
  </script>
</body>
</html>
