# Radiodyplom.pl — APIKEY per konto + auto-wykrywanie aktywnych akcji

Referencyjna propozycja dla Tobiasza (autor radiodyplom.pl) — sposób na wysyłkę QSO przez API bez konieczności generowania osobnego klucza dla każdej akcji dyplomowej.

## Problem

Obecny model API (`qso_upload.php`) wymaga osobnego `apikey` na każdą akcję dyplomową. Dla operatora biorącego udział w kilkunastu akcjach naraz oznacza to kilkanaście kluczy i kilkanaście requestów na jedno zalogowane QSO — nie do utrzymania po stronie loggera ani w UI ustawień.

## Propozycja

Jeden `apikey` **per konto**, nie per akcja. Serwer sam rozpoznaje, do których aktywnych akcji użytkownika pasuje przesłane QSO, i zapisuje je do wszystkich naraz — dokładnie tak, jak już działa istniejący demon UDP radiodyplom.pl ("jeden push, wszystkie aktywne eventy naraz").

## Zawartość

| Plik | Co robi |
|---|---|
| `radiodyplom_apikey_bridge.php` | Właściwy endpoint: uwierzytelnienie przez `apikey`, walidacja parametrów QSO, pętla po aktywnych akcjach. |
| `radiodyplom_apikey_bridge_lib.php` | Czysta logika bez bazy/HTTP (walidacja, filtrowanie po dacie) — testowalna w izolacji. |
| `RadiodyplomApikeyBridgeLibTest.php` | 12 testów PHPUnit dla powyższego. `phpunit RadiodyplomApikeyBridgeLibTest.php` |

## Przepływ

1. **Auth** — `POST apikey`. Brak POST-a (np. ktoś otwiera URL wprost w przeglądarce) → prosty formularz HTML do ręcznego wklejenia klucza.
2. **Rozpoznanie użytkownika** — `apikey` → `user_id` (hipotetyczna tabela `apikeys(id, user_id, apikey)`).
3. **Znalezienie aktywnych akcji** — wszystkie akcje danego `user_id` z tabeli `events(id, user_id, start_utc, end_utc, ...)`, z **marginesem 1 dnia po zakończeniu** (żeby ktoś kończący sesję tuż po północy nie stracił punktów).
4. **Zapis** — to samo QSO trafia do **wszystkich** znalezionych akcji, operator niczego nie wybiera.
5. **Odpowiedź** — JSON z liczbą znalezionych/zapisanych akcji, żeby dało się to zdiagnozować zdalnie.

Parametry GET (QSO) zgodne z już udokumentowanym `qso_upload.php`:
wymagane `qso_date, time_on, callsign, band, mode, station_callsign`; opcjonalne `submode, report_sent/rst_sent, report_received/rst_rcvd, operator, owner_callsign`.

## Uwaga Tobiasz

Wszystkie miejsca oznaczone `// UWAGA Tobiasz` w `radiodyplom_apikey_bridge.php`:
- dane połączenia z bazą,
- prawdziwy insert QSO (duplikaty, normalizacja znaku — to, co już jest w `processLog.php`),
- ewentualnie realne nazwy tabel/kolumn, jeśli inne niż hipotetyczne.

## Status

Prototyp/propozycja — nieużywany produkcyjnie. Schemat bazy jest **hipotetyczny**, do dostosowania.
