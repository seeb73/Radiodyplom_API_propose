<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/radiodyplom_apikey_bridge_lib.php';

final class RadiodyplomApikeyBridgeLibTest extends TestCase
{
    // --- missingRequiredParam ------------------------------------------

    public function testAllRequiredPresent_returnsNull(): void
    {
        $params = [
            'qso_date' => '2026-08-14', 'time_on' => '12:00', 'callsign' => 'SP1ABC',
            'band' => '20m', 'mode' => 'SSB', 'station_callsign' => 'SP3SEB',
        ];
        $this->assertNull(missingRequiredParam($params, array_keys($params)));
    }

    public function testMissingField_returnsItsName(): void
    {
        $params = ['qso_date' => '2026-08-14', 'callsign' => 'SP1ABC'];
        $this->assertSame('time_on', missingRequiredParam($params, ['qso_date', 'time_on', 'callsign']));
    }

    public function testEmptyStringField_treatedAsMissing(): void
    {
        // Klucz obecny, ale pusty - to samo co brak (np. ?band=&mode=SSB...).
        $params = ['band' => '', 'mode' => 'SSB'];
        $this->assertSame('band', missingRequiredParam($params, ['band', 'mode']));
    }

    public function testFirstMissingWins_deterministicOrder(): void
    {
        $params = [];
        $this->assertSame('qso_date', missingRequiredParam($params, ['qso_date', 'time_on', 'callsign']));
    }

    // --- isEventStillActive ---------------------------------------------

    public function testWellWithinWindow_active(): void
    {
        $end = new DateTimeImmutable('2026-08-16 23:59:00', new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('2026-08-12 10:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(isEventStillActive($end, $now));
    }

    public function testExactlyAtMarginBoundary_stillActive(): void
    {
        // Koniec 2026-08-09 23:59, margines 1 dzien -> deadline 2026-08-10 23:59.
        // "Teraz" DOKLADNIE na deadline - >= wiec wciaz aktywna.
        $end = new DateTimeImmutable('2026-08-09 23:59:00', new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('2026-08-10 23:59:00', new DateTimeZone('UTC'));
        $this->assertTrue(isEventStillActive($end, $now));
    }

    public function testJustPastMargin_notActive(): void
    {
        // Realny bug SP8SAN, ale sprawdzany TU z marginesem: akcja skonczona
        // 2026-08-09 23:59, "teraz" 2026-08-11 00:00 - to JUZ ponad dobe po
        // deadline (2026-08-10 23:59), wiec ma zostac odrzucona.
        $end = new DateTimeImmutable('2026-08-09 23:59:00', new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('2026-08-11 00:00:00', new DateTimeZone('UTC'));
        $this->assertFalse(isEventStillActive($end, $now));
    }

    public function testWithinMarginWindow_stillActiveEvenThoughFormallyEnded(): void
    {
        // Akcja formalnie skonczona kilka godzin temu, ale w granicach
        // 1-dniowego marginesu - to jest CEL tego marginesu (ktos konczacy
        // sesje tuz po polnocy nie traci punktow).
        $end = new DateTimeImmutable('2026-08-09 23:59:00', new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('2026-08-10 06:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(isEventStillActive($end, $now));
    }

    public function testCustomMarginDays_respected(): void
    {
        $end = new DateTimeImmutable('2026-08-09 23:59:00', new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('2026-08-13 12:00:00', new DateTimeZone('UTC'));
        $this->assertFalse(isEventStillActive($end, $now, 1)); // domyslny margines - juz nieaktywna
        $this->assertTrue(isEventStillActive($end, $now, 7));  // tydzien marginesu - jeszcze aktywna
    }

    // --- filterActiveEventIds --------------------------------------------

    public function testFilterActiveEventIds_mixedList_onlyActiveReturned(): void
    {
        $now = new DateTimeImmutable('2026-08-13 12:00:00', new DateTimeZone('UTC'));
        $events = [
            ['id' => 297, 'end_utc' => new DateTimeImmutable('2026-08-16 23:59:00', new DateTimeZone('UTC'))], // aktywna
            ['id' => 299, 'end_utc' => new DateTimeImmutable('2026-08-09 23:59:00', new DateTimeZone('UTC'))], // dawno skonczona
            ['id' => 305, 'end_utc' => new DateTimeImmutable('2026-08-20 23:59:00', new DateTimeZone('UTC'))], // aktywna
        ];
        $this->assertSame([297, 305], filterActiveEventIds($events, $now));
    }

    public function testFilterActiveEventIds_emptyInput_emptyOutput(): void
    {
        $now = new DateTimeImmutable('2026-08-13 12:00:00', new DateTimeZone('UTC'));
        $this->assertSame([], filterActiveEventIds([], $now));
    }

    public function testFilterActiveEventIds_preservesInputOrder(): void
    {
        $now = new DateTimeImmutable('2026-08-13 12:00:00', new DateTimeZone('UTC'));
        $events = [
            ['id' => 'b', 'end_utc' => new DateTimeImmutable('2026-08-20 00:00:00', new DateTimeZone('UTC'))],
            ['id' => 'a', 'end_utc' => new DateTimeImmutable('2026-08-25 00:00:00', new DateTimeZone('UTC'))],
        ];
        $this->assertSame(['b', 'a'], filterActiveEventIds($events, $now));
    }
}
