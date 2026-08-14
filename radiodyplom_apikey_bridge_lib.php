<?php
/**
 * Czysta logika (bez bazy/HTTP) wydzielona z radiodyplom_apikey_bridge.php,
 * żeby dało się ją przetestować bez PDO/serwera - ten sam duch co reszta
 * tego projektu (RadioDyplomTimeFilter po stronie uLoggera): logika osobno,
 * I/O to cienka warstwa dookoła.
 *
 * Patrz radiodyplom_apikey_bridge_lib_test.php.
 */

declare(strict_types=1);

/**
 * Zwraca nazwę PIERWSZEGO brakującego/pustego wymaganego pola, albo null
 * jeśli wszystkie są obecne. Kolejność sprawdzania = kolejność w $required,
 * żeby komunikat błędu był deterministyczny (przydatne w testach i dla
 * operatora patrzącego na odpowiedź).
 */
function missingRequiredParam(array $params, array $required): ?string {
    foreach ($required as $field) {
        if (!isset($params[$field]) || $params[$field] === '') {
            return $field;
        }
    }
    return null;
}

/**
 * "Aktywna" = koniec akcji + margines >= teraz. Margines domyślnie 1 dzień
 * (zgłoszenie: ktoś kończący sesję tuż po północy / z drobnym opóźnieniem w
 * wysyłce nie ma stracić punktów z powodu paru godzin poślizgu). Świadomie
 * NIE sprawdza startu akcji tutaj - patrz komentarz w
 * radiodyplom_apikey_bridge.php przy właściwym zapytaniu SQL.
 */
function isEventStillActive(DateTimeImmutable $endUtc, DateTimeImmutable $nowUtc, int $marginDays = 1): bool {
    $deadline = $endUtc->modify("+{$marginDays} day");
    return $deadline >= $nowUtc;
}

/**
 * $events - lista ['id' => mixed, 'end_utc' => DateTimeImmutable]. Zwraca
 * TYLKO id-eki tych, dla których isEventStillActive() jest true - kolejność
 * wejściowa zachowana.
 */
function filterActiveEventIds(array $events, DateTimeImmutable $nowUtc, int $marginDays = 1): array {
    $result = [];
    foreach ($events as $event) {
        if (isEventStillActive($event['end_utc'], $nowUtc, $marginDays)) {
            $result[] = $event['id'];
        }
    }
    return $result;
}
