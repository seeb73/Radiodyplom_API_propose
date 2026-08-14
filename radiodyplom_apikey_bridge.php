<?php
/**
 * Referencyjna implementacja: APIKEY (konto, nie per-akcja) + automatyczne
 * rozpoznanie WSZYSTKICH aktywnych akcji dyplomowych danego użytkownika.
 *
 * To jest PROPOZYCJA dla Tobiasza (autor radiodyplom.pl) - hipotetyczny
 * schemat bazy, on sam dostosuje nazwy tabel/kolumn i sposób zapisu QSO do
 * swojej prawdziwej bazy. Cel: rozwiązać problem "jeden klucz na akcję nie
 * skaluje się przy kilkunastu akcjach naraz" - tu klucz jest per KONTO,
 * serwer sam rozdziela jedno przychodzące QSO do wszystkich pasujących
 * akcji (ten sam duch co jego istniejący demon UDP - "jeden push, wszystkie
 * aktywne eventy naraz").
 *
 * Hipotetyczny schemat:
 *   apikeys(id, user_id, apikey)
 *   events(id, user_id, start_utc, end_utc, ...)   -- "akcje dyplomowe"
 *
 * (Skąd user_id, jak wygląda tabela users - to już nie nasza sprawa, zakładamy
 * że po prostu istnieje i events.user_id/apikeys.user_id się do niej odnoszą.)
 *
 * Przepływ:
 *   1. APIKEY: najpierw POST["apikey"] (tak wołałby to uLogger/inny logger
 *      programowo). Jeśli go brak - zamiast błędu pokazujemy prosty
 *      formularz HTML do ręcznego wklejenia klucza (operator otwierający
 *      ten URL wprost w przeglądarce, np. do testu).
 *   2. Rozpoznanie user_id po apikey.
 *   3. SELECT aktywnych akcji TEGO user_id z events - "aktywna" = jeszcze
 *      nie zakończona, z MARGINESEM 1 dnia po end_utc (żeby ktoś kończący
 *      sesję tuż po północy / z opóźnieniem w wysyłce nie stracił punktów
 *      z powodu paru godzin poślizgu).
 *   4. Dla KAŻDEJ znalezionej akcji: zapisujemy przesłane QSO. Operator
 *      NIE wybiera do której - dostaje wszystkie, którym akurat pasuje.
 *   5. Odpowiedź JSON - ile akcji znaleziono, do ilu faktycznie zapisano -
 *      ten sam duch co telemetria po stronie uLoggera (maksimum informacji
 *      zwrotnej, żeby dało się to zdiagnozować zdalnie).
 *
 * Parametry GET zgodne z WCZEŚNIEJ udokumentowanym qso_upload.php Tobiasza:
 *   wymagane: qso_date, time_on, callsign, band, mode, station_callsign
 *   opcjonalne: submode, report_sent/rst_sent, report_received/rst_rcvd,
 *               operator, owner_callsign
 */

declare(strict_types=1);
require_once __DIR__ . '/radiodyplom_apikey_bridge_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 1. APIKEY: POST, albo formularz ręcznego wpisania ---------------------

$apikey = $_POST['apikey'] ?? null;

if ($apikey === null) {
    // Brak POST-a (np. ktoś otworzył ten URL wprost w przeglądarce) -
    // pokazujemy formularz zamiast suchego błędu. Formularz POST-uje sam
    // do siebie, więc dalszy kod (walidacja klucza) działa identycznie
    // niezależnie od tego, skąd apikey przyszedł.
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="pl"><head><meta charset="utf-8"><title>radiodyplom.pl - klucz API</title></head>
    <body>
        <h1>Wklej swój klucz API</h1>
        <form method="post">
            <input type="text" name="apikey" placeholder="RDPL-xxxxxx-xxxxxx-xxxxxx-xxxxxx" size="40" required>
            <button type="submit">Zapisz</button>
        </form>
    </body></html>
    <?php
    exit;
}

// --- 2. Połączenie z bazą + rozpoznanie user_id -----------------------------
// UWAGA Tobiasz: dane połączenia / sposób trzymania hasła do bazy - Twoja
// sprawa, tu tylko szkielet.
$pdo = new PDO('mysql:host=localhost;dbname=radiodyplom;charset=utf8mb4', 'DB_USER', 'DB_PASS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare('SELECT user_id FROM apikeys WHERE apikey = :apikey LIMIT 1');
$stmt->execute(['apikey' => $apikey]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    respond(['success' => false, 'message' => 'Nieprawidłowy klucz API'], 403);
}
$userId = (int) $row['user_id'];

// --- 3. Wymagane parametry QSO ----------------------------------------------

$required = ['qso_date', 'time_on', 'callsign', 'band', 'mode', 'station_callsign'];
$missing = missingRequiredParam($_GET, $required);
if ($missing !== null) {
    respond(['success' => false, 'message' => "Brak wymaganego parametru: $missing"], 400);
}

// --- 4. Aktywne akcje tego usera, z 1-dniowym marginesem po końcu ----------
// Pobieramy WSZYSTKIE akcje usera (SQL nie liczy dat) i filtrujemy w PHP
// przez filterActiveEventIds() - ta sama, PRZETESTOWANA (patrz
// radiodyplom_apikey_bridge_lib_test.php) logika co niżej w testach,
// zamiast powtarzać arytmetykę dat osobno w SQL. Świadomie NIE
// filtrujemy tu po start_utc (submitowanie QSO na żywo, tuż po
// zalogowaniu - w praktyce nie zdarzy się submit przed startem akcji) -
// jeśli Tobiasz woli to też sprawdzać, to już tylko dodatkowy WHERE niżej.
$stmt = $pdo->prepare('SELECT id, end_utc FROM events WHERE user_id = :user_id');
$stmt->execute(['user_id' => $userId]);
$events = array_map(
    static fn(array $row) => ['id' => $row['id'], 'end_utc' => new DateTimeImmutable($row['end_utc'], new DateTimeZone('UTC'))],
    $stmt->fetchAll(PDO::FETCH_ASSOC)
);
$activeEventIds = filterActiveEventIds($events, new DateTimeImmutable('now', new DateTimeZone('UTC')));

if (empty($activeEventIds)) {
    respond(['success' => false, 'message' => 'Brak aktywnych akcji dla tego konta', 'foundActions' => 0]);
}

// --- 5. Pętla - to samo QSO do WSZYSTKICH znalezionych akcji ---------------
// Operator nie wybiera do której - dostaje wszystkie, którym pasuje
// (ten sam wzorzec co uLogger po stronie klienta).

$savedTo = [];
$skipped = [];

foreach ($activeEventIds as $eventId) {
    // UWAGA Tobiasz: tu wstawiasz swój prawdziwy insert/logikę QSO (Twoja
    // baza, Twoje reguły duplikatów/normalizacji znaku itd. - dokładnie to,
    // co już masz w processLog.php/qso_upload.php). Zakładam, że masz
    // gdzieś funkcję/metodę do tego - to tylko miejsce w pętli, gdzie ją
    // wołasz, per event_id.
    $ok = true; // = insertQso($eventId, $_GET);

    if ($ok) {
        $savedTo[] = $eventId;
    } else {
        $skipped[] = $eventId;
    }
}

respond([
    'success' => count($savedTo) > 0,
    'foundActions' => count($activeEventIds),
    'savedTo' => $savedTo,
    'skipped' => $skipped,
]);
