<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Liest die Personen-CSV (`vorname;nachname;gruppe;benutzername`) so, wie Excel sie speichert:
 * Windows-1252 oder UTF-8 (mit BOM), CRLF, Leerzeilen am Ende. Rein, ohne Datenbankzugriff.
 */
final class CsvPersonenParser
{
    public const MELDUNG_KOPFZEILE = 'Kopfzeile muss lauten: vorname;nachname;gruppe;benutzername';

    private const KOPFZEILE = ['vorname', 'nachname', 'gruppe', 'benutzername'];
    private const GRUPPEN   = ['aktiv', 'ah', 'sonstige'];

    /**
     * @param list<string> $vorhandeneBenutzernamen normalisierte Benutzernamen aus der Datenbank
     * @param list<string> $vorhandeneNamen         mb_strtolower("vorname nachname") der vorhandenen Personen
     *
     * @return array{zeilen: list<array{zeile: int, vorname: string, nachname: string, gruppe: string, benutzername: string, fehler: ?string}>, fehler: ?string}
     */
    public function parse(string $inhalt, array $vorhandeneBenutzernamen, array $vorhandeneNamen): array
    {
        $zeilen     = [];
        $kopfGelesen = false;
        $inDatei    = [];
        $nameInDatei = [];
        $inDb       = array_fill_keys($vorhandeneBenutzernamen, true);
        $bekannt    = array_fill_keys($vorhandeneNamen, true);

        foreach (explode("\n", $this->vorbereiten($inhalt)) as $index => $text) {
            if (trim($text) === '') {
                continue;
            }

            $felder = array_map('trim', str_getcsv($text, ';', '"', ''));

            if (! $kopfGelesen) {
                if (array_map('mb_strtolower', $felder) !== self::KOPFZEILE) {
                    return ['zeilen' => [], 'fehler' => self::MELDUNG_KOPFZEILE];
                }

                $kopfGelesen = true;

                continue;
            }

            $felder += ['', '', '', ''];

            if (implode('', $felder) === '') {
                continue; // Excel-Leerzeile wie ;;;
            }

            $vorname  = $felder[0];
            $nachname = $felder[1];
            $gruppe   = mb_strtolower($felder[2]);
            $name     = Anmelderegeln::benutzernameNormalisieren($felder[3]);
            $schluessel = mb_strtolower($vorname . ' ' . $nachname);

            $fehler = match (true) {
                $vorname === '' || $nachname === ''                            => 'Vor- und Nachname erforderlich',
                mb_strlen($vorname) > 100 || mb_strlen($nachname) > 100 || mb_strlen($vorname . ' ' . $nachname) > 200 => 'Name zu lang (max. 100 Zeichen)',
                ! in_array($gruppe, self::GRUPPEN, true)                       => 'Gruppe unbekannt',
                $name === null                                                 => 'Benutzername ungültig',
                isset($inDb[$name])                                            => 'Benutzername existiert bereits',
                isset($inDatei[$name])                                         => 'Benutzername doppelt in der Datei',
                isset($bekannt[$schluessel])     => 'Person existiert bereits',
                isset($nameInDatei[$schluessel])                             => 'Person doppelt in der Datei',
                default                                                        => null,
            };

            if ($fehler === null) {
                $nameInDatei[$schluessel] = true;
            }

            if ($name !== null) {
                $inDatei[$name] = true;
            }

            $zeilen[] = [
                'zeile'        => $index + 1,
                'vorname'      => $vorname,
                'nachname'     => $nachname,
                'gruppe'       => $gruppe,
                'benutzername' => $name ?? $felder[3],
                'fehler'       => $fehler,
            ];
        }

        if (! $kopfGelesen) {
            return ['zeilen' => [], 'fehler' => self::MELDUNG_KOPFZEILE];
        }

        return ['zeilen' => $zeilen, 'fehler' => null];
    }

    private function vorbereiten(string $inhalt): string
    {
        if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
            $inhalt = substr($inhalt, 3);
        }

        if (! mb_check_encoding($inhalt, 'UTF-8')) {
            $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $inhalt);
    }
}
