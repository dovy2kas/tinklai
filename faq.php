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

$role = null;
$vardas = null;
$loggedIn = !empty($_SESSION['user_id']);
$uid = null;

if ($loggedIn) {
  $uid = (int)$_SESSION['user_id'];
  $stmt = $mysqli->prepare("SELECT vardas, role FROM Naudotojas WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  if ($row = $stmt->get_result()->fetch_assoc()) {
    $vardas = $row['vardas'];
    $role   = $row['role'];
  }
  $stmt->close();
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="lt">
<head>
  <meta charset="utf-8" />
  <title>DUK – Elektrikus vienijanti sistema</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="./static/js/colors.js"></script>
</head>
<body class="bg-bg min-h-screen">

  <nav class="bg-fg border-purple border-b-2 mb-10">
    <div class="max-w-screen-xl flex flex-wrap items-center justify-between mx-auto p-4">
      <span class="text-2xl font-bold text-purple">Elektrikus vienijanti sistema</span>
      <div class="hidden w-full md:block md:w-auto">
        <ul class="font-medium flex flex-col md:flex-row md:space-x-8">
          <li>
            <a href="home.php"
               class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
              Pagrindinis
            </a>
          </li>

          <?php if ($role === 'ADMIN'): ?>
            <li>
              <a href="manage_electricians.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Elektrikų tvirtinimas
              </a>
            </li>
          <?php endif; ?>

          <?php if ($role === 'ELEKTRIKAS'): ?>
            <li>
              <a href="services.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Mano paslaugos
              </a>
            </li>
            <li>
              <a href="manage_reservations.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Valdyti rezervacijas
              </a>
            </li>
            <li>
              <a href="calendar.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Mano kalendorius
              </a>
            </li>
          <?php endif; ?>

          <?php if ($role === "NAUDOTOJAS"): ?>
            <li>
              <a href="reservations.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Mano rezervacijos
              </a>
            </li>
          <?php endif; ?>

          <li>
            <a href="faq.php"
               class="block py-2 px-3 text-pink font-semibold hover:text-pink transition duration-150 ease-in">
              DUK
            </a>
          </li>

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
              <a href="login.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Prisijungti
              </a>
            </li>
            <li>
              <a href="register.php"
                 class="block py-2 px-3 text-comment hover:text-pink transition duration-150 ease-in">
                Registruotis
              </a>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>

  <main class="max-w-screen-xl mx-auto px-6 pb-12">
    <h1 class="text-2xl md:text-3xl font-bold text-fg-font mb-6">
      Dažniausiai užduodami klausimai (DUK)
    </h1>

    <section class="space-y-4">

      <div class="bg-fg rounded-xl shadow p-4 md:p-5">
        <h2 class="text-xl font-semibold text-fg-font mb-3">Bendra informacija</h2>
        <div class="space-y-2">
          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Ar galiu naudotis sistema be prisijungimo?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Taip. Neprisijungęs vartotojas gali matyti visus patvirtintus elektrikus, jų miestus, paslaugas,
              bazines kainas ir darbų trukmę. Prisijungimas reikalingas tik tada, kai nori užsirezervuoti laiką
              arba palikti atsiliepimą.
            </div>
          </details>

          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kas gali registruotis sistemoje?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Registruotis gali tiek paprasti vartotojai (klientai), tiek elektrikai. Registracijos metu pasirinksi,
              kokį vaidmenį nori turėti.
            </div>
          </details>
        </div>
      </div>

      <div class="bg-fg rounded-xl shadow p-4 md:p-5">
        <h2 class="text-xl font-semibold text-fg-font mb-3">Naudotojams (klientams)</h2>
        <div class="space-y-2">
          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kaip užsirezervuoti konsultaciją pas elektriką?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              <ol class="list-decimal list-inside space-y-1">
                <li>Prisijunk prie sistemos kaip naudotojas.</li>
                <li>Pagrindiniame puslapyje išsirink elektriką (gali filtruoti pagal miestą ir paslaugą).</li>
                <li>Paspausk „Rezervuoti konsultaciją“.</li>
                <li>Pasirink paslaugą, datą ir laisvą laiką, patvirtink rezervaciją.</li>
              </ol>
            </div>
          </details>

          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kaip palikti atsiliepimą apie elektriko darbą?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Atsiliepimą gali palikti tik tada, jei su elektriku turėjai patvirtintą ar įvykdytą rezervaciją.
              Tuomet prie to elektriko kortelės matysi mygtuką „Palikti atsiliepimą“. Vienam elektrikui gali
              parašyti tik vieną atsiliepimą.
            </div>
          </details>

          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kur galiu pamatyti savo rezervacijas?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Prisijungęs naudotojas turi meniu punktą „Mano rezervacijos“. Čia matysi visas būsimas ir buvusias
              konsultacijas bei jų būseną.
            </div>
          </details>
        </div>
      </div>

      <div class="bg-fg rounded-xl shadow p-4 md:p-5">
        <h2 class="text-xl font-semibold text-fg-font mb-3">Elektrikams</h2>
        <div class="space-y-2">
          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kaip tapti matomu sistemoje kaip elektrikas?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Užsiregistruok kaip elektrikas, užpildyk profilį (CV, darbų nuotraukos, miestas) ir palauk,
              kol administratorius patvirtins tavo profilį. Tik patvirtinti elektrikai matomi viešame sąraše.
            </div>
          </details>

          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kur galiu suvesti savo paslaugas ir įkainius?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Prisijungęs kaip elektrikas, meniu matysi punktą „Mano paslaugos“. Čia gali pridėti paslaugas,
              nurodyti aprašymą, bazinę kainą ir tipinę trukmę minutėmis.
            </div>
          </details>

          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kaip valdyti rezervacijas ir savo kalendorių?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Elektrikas turi du pagrindinius puslapius:
              <ul class="list-disc list-inside space-y-1 mt-1">
                <li><strong>„Valdyti rezervacijas“</strong> – čia matomos visos užklausos, gali jas patvirtinti arba atmesti.</li>
                <li><strong>„Mano kalendorius“</strong> – čia matomas savaitės grafikas ir suplanuotos konsultacijos.</li>
              </ul>
            </div>
          </details>
        </div>
      </div>

      <div class="bg-fg rounded-xl shadow p-4 md:p-5">
        <h2 class="text-xl font-semibold text-fg-font mb-3">Administratoriams</h2>
        <div class="space-y-2">
          <details class="group">
            <summary class="cursor-pointer font-semibold text-fg-font flex items-center justify-between">
              <span>Kaip tvirtinami elektrikų profiliai?</span>
              <span class="ml-4 text-sm text-fg-font/60 group-open:hidden">+</span>
              <span class="ml-4 text-sm text-fg-font/60 hidden group-open:inline">−</span>
            </summary>
            <div class="mt-2 text-sm text-fg-font/80">
              Administratorius prisijungęs mato meniu punktą „Elektrikų tvirtinimas“. Čia gali peržiūrėti naujus
              arba atnaujintus profilius, juos patvirtinti arba atmesti (pvz., jei nuotraukos netinkamos).
            </div>
          </details>
        </div>
      </div>

    </section>
  </main>
</body>
</html>
