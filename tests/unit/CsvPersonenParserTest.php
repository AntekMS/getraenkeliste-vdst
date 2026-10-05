<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\CsvPersonenParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CsvPersonenParserTest extends CIUnitTestCase
{
    private const KOPF = "vorname;nachname;gruppe;benutzername\n";

    public function test_gueltige_datei(): void
    {
        $ergebnis = (new CsvPersonenParser())->parse(self::KOPF . "Anna;Beispiel;aktiv;Anna.B\nBen;Test;sonstige;ben123\n", [], []);

        $this->assertNull($ergebnis['fehler']);
        $this->assertSame([
            ['zeile' => 2, 'vorname' => 'Anna', 'nachname' => 'Beispiel', 'gruppe' => 'aktiv', 'benutzername' => 'anna.b', 'fehler' => null],
            ['zeile' => 3, 'vorname' => 'Ben', 'nachname' => 'Test', 'gruppe' => 'sonstige', 'benutzername' => 'ben123', 'fehler' => null],
        ], $ergebnis['zeilen']);
    }

    public function test_windows_1252_umlaute(): void
    {
        $ergebnis = (new CsvPersonenParser())->parse(self::KOPF . "J\xFCrgen;M\xFCller;AH;jmueller", [], []);

        $this->assertSame('Jürgen', $ergebnis['zeilen'][0]['vorname']);
        $this->assertSame('Müller', $ergebnis['zeilen'][0]['nachname']);
        $this->assertSame('ah', $ergebnis['zeilen'][0]['gruppe']);
        $this->assertNull($ergebnis['zeilen'][0]['fehler']);
    }

    public function test_utf8_umlaute_bleiben_erhalten(): void
    {
        $ergebnis = (new CsvPersonenParser())->parse(self::KOPF . 'Jürgen;Müller;aktiv;jmueller', [], []);

        $this->assertSame('Jürgen', $ergebnis['zeilen'][0]['vorname']);
    }

    public function test_bom_und_crlf_und_leerzeilen(): void
    {
        $inhalt   = "\xEF\xBB\xBFVorname;Nachname;Gruppe;Benutzername\r\n\r\nAnna;Beispiel;Aktiv;anna\r\n;;;\r\nBen;Test;ah;ben\r\n\r\n";
        $ergebnis = (new CsvPersonenParser())->parse($inhalt, [], []);

        $this->assertNull($ergebnis['fehler']);
        $this->assertCount(3, $ergebnis['zeilen']);
        $this->assertSame('aktiv', $ergebnis['zeilen'][0]['gruppe']);
        $this->assertSame('Vor- und Nachname erforderlich', $ergebnis['zeilen'][1]['fehler']);
        $this->assertSame('ben', $ergebnis['zeilen'][2]['benutzername']);
    }

    public function test_falsche_kopfzeile(): void
    {
        $ergebnis = (new CsvPersonenParser())->parse("name;gruppe\nAnna;aktiv\n", [], []);

        $this->assertSame('Kopfzeile muss lauten: vorname;nachname;gruppe;benutzername', $ergebnis['fehler']);
        $this->assertSame([], $ergebnis['zeilen']);
        $this->assertSame(CsvPersonenParser::MELDUNG_KOPFZEILE, (new CsvPersonenParser())->parse('', [], [])['fehler']);
    }

    public function test_dubletten_in_db_und_datei(): void
    {
        $inhalt = self::KOPF
            . "Anna;Eins;aktiv;vergeben\n"
            . "Ben;Zwei;aktiv;neu1\n"
            . "Carl;Drei;aktiv;NEU1\n"
            . "Dora;Vier;aktiv;dora\n"
            . "Emil;Fuenf;aktiv;x\n";

        $ergebnis = (new CsvPersonenParser())->parse($inhalt, ['vergeben'], ['dora vier']);

        $this->assertSame([
            'Benutzername existiert bereits',
            null,
            'Benutzername doppelt in der Datei',
            'Person existiert bereits',
            'Benutzername ungültig',
        ], array_column($ergebnis['zeilen'], 'fehler'));
    }

    public function test_unbekannte_gruppe(): void
    {
        $ergebnis = (new CsvPersonenParser())->parse(self::KOPF . "Anna;Eins;kiosk;anna\n", [], []);

        $this->assertSame('Gruppe unbekannt', $ergebnis['zeilen'][0]['fehler']);
    }
}
