<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ArtikelModel;
use App\Models\BereichModel;
use App\Models\BuchungModel;
use App\Models\PersonModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Buchen (idempotent über die vorgang_id) und Stornieren. Wer stornieren darf,
 * prüft der Controller; hier gelten nur Frist, Einfrierung und Doppel-Storno.
 */
class BuchungService
{
    private const QUELLEN        = ['web', 'tablet'];
    private const MAX_POSITIONEN = 30;
    private const MAX_MENGE      = 99;
    private const MAX_TEXT       = 255;
    private const MELDUNG_ABRECHNUNG = 'Gerade wird abgerechnet – bitte gleich erneut versuchen.';
    private const UUID_V4        = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const FORMAT         = 'Y-m-d H:i:s';

    /**
     * Neue Vorgangs-ID (UUID v4) aus kryptografisch sicherem Zufall.
     */
    public static function neueVorgangId(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex  = bin2hex($b);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * @param list<array{artikel_id: int, menge: int}> $positionen
     *
     * @return array{vorgang_id: string, konto_id: int, positionen: list<array{artikel_id: int, name: string, menge: int, einzelpreis_cent: int}>, summe_cent: int, zusammenfassung: string, gebucht_at: string, wiederholt: bool, storniert: bool}
     */
    public function bucheVorgang(string $vorgangId, int $kontoId, ?int $gebuchtVonId, ?int $geraetId, string $quelle, array $positionen): array
    {
        if (preg_match(self::UUID_V4, $vorgangId) !== 1) {
            throw new BuchungAbgelehnt('Ungültiger Vorgang.');
        }

        if (! in_array($quelle, self::QUELLEN, true)) {
            throw new InvalidArgumentException('Unzulässige Buchungsquelle: ' . $quelle);
        }

        // Wiederholung zuerst: das gespeicherte Ergebnis gilt auch, wenn ein Artikel inzwischen archiviert ist.
        $bereitsGebucht = $this->wiederholung($vorgangId, $kontoId, $gebuchtVonId);

        if ($bereitsGebucht !== null) {
            return $bereitsGebucht;
        }

        $summiert = $this->summiere($positionen);

        $konto = (new PersonModel())->find($kontoId);

        if ($konto === null || $konto['archiviert_at'] !== null) {
            throw new BuchungAbgelehnt('Dieses Konto ist nicht buchbar. Nicht gebucht.');
        }

        $artikelModel = new ArtikelModel();
        $zeilen       = [];
        $bereichIds   = [];

        foreach ($summiert as $artikelId => $menge) {
            $artikel = $artikelModel->findeBuchbar($artikelId);

            if ($artikel === null) {
                $vorhanden = $artikelModel->find($artikelId);

                throw new BuchungAbgelehnt($vorhanden === null
                    ? 'Ein Artikel ist nicht mehr buchbar. Nicht gebucht.'
                    : '„' . $vorhanden['name'] . '“ ist nicht mehr buchbar. Nicht gebucht.');
            }

            $zeilen[] = [
                'vorgang_id'       => $vorgangId,
                'konto_id'         => $kontoId,
                'artikel_id'       => $artikelId,
                'menge'            => $menge,
                'einzelpreis_cent' => (int) $artikel['preis_cent'],
                'quelle'           => $quelle,
                'gebucht_von_id'   => $gebuchtVonId,
                'geraet_id'        => $geraetId,
            ];
            $bereichIds[] = (int) $artikel['bereich_id'];
        }

        $model = new BuchungModel();

        try {
            $model->transaktion(function () use ($zeilen, $model, $bereichIds, $vorgangId): void {
                // Entscheidung 1: erst die Bereiche sperren, dann „jetzt“ und den Stichtag lesen.
                // Ein paralleler Abschluss ist damit entweder schon committet (→ geprüft) oder wartet.
                $this->sperreBereiche($bereichIds, $vorgangId);
                $jetzt = service('uhr')->jetzt();

                foreach (array_unique($bereichIds) as $bereichId) {
                    if (service('zeitraeume')->istEingefroren($jetzt, $bereichId)) {
                        throw new BuchungAbgelehnt('Dieser Zeitraum ist abgeschlossen. Nicht gebucht.');
                    }
                }

                foreach ($zeilen as $zeile) {
                    $zeile['gebucht_at'] = $jetzt->format(self::FORMAT);

                    if ($model->insert($zeile) === false) {
                        throw new DatabaseException('Buchung konnte nicht gespeichert werden.');
                    }
                }
            });
        } catch (DatabaseException $e) {
            // Wettlauf: ein paralleler Request mit derselben vorgang_id war schneller.
            // Rollback ist erfolgt; das gespeicherte Ergebnis (mit Besitzprüfung) beantwortet den Request.
            $bereitsGebucht = $this->wiederholung($vorgangId, $kontoId, $gebuchtVonId);

            if ($bereitsGebucht !== null) {
                return $bereitsGebucht;
            }

            throw self::sperrfehlerAbgelehnt($e) ?? $e;
        }

        return $this->ergebnis($this->zeilen($vorgangId), false);
    }

    /**
     * @param list<array{name: string, menge: int, einzelpreis_cent: int}> $positionen
     */
    public static function zusammenfassung(array $positionen): string
    {
        helper('betrag');

        $teile = [];
        $summe = 0;

        foreach ($positionen as $p) {
            $teile[] = $p['menge'] . '× ' . $p['name'];
            $summe  += $p['menge'] * $p['einzelpreis_cent'];
        }

        return implode(', ', $teile) . ' – ' . formatiere_cent($summe);
    }

    /**
     * Nur nicht stornierte Positionen; null, wenn es keine gibt.
     *
     * @return ?array{vorgang_id: string, konto_id: int, positionen: list<array{artikel_id: int, name: string, menge: int, einzelpreis_cent: int}>, summe_cent: int, zusammenfassung: string, gebucht_at: string, wiederholt: bool, storniert: bool}
     */
    public function vorgang(string $vorgangId): ?array
    {
        $zeilen = array_values(array_filter($this->zeilen($vorgangId), static fn (array $z): bool => $z['storniert_at'] === null));

        return $zeilen === [] ? null : $this->ergebnis($zeilen, false);
    }

    public function storniereVorgang(string $vorgangId, ?int $stornoVonId): void
    {
        $alle = $this->zeilen($vorgangId);

        if ($alle === []) {
            throw new BuchungAbgelehnt('Unbekannter Vorgang.');
        }

        $offen = array_values(array_filter($alle, static fn (array $z): bool => $z['storniert_at'] === null));

        if ($offen === []) {
            throw new BuchungAbgelehnt('Bereits storniert.');
        }

        $this->storniere($offen, $stornoVonId);
    }

    public function storniereBuchung(int $buchungId, ?int $stornoVonId): void
    {
        $buchung = $this->zeilenQuery()->where('b.id', $buchungId)->get()->getRowArray();

        if ($buchung === null) {
            throw new BuchungAbgelehnt('Unbekannte Buchung.');
        }

        if ($buchung['storniert_at'] !== null) {
            throw new BuchungAbgelehnt('Bereits storniert.');
        }

        $this->storniere([$buchung], $stornoVonId);
    }

    /**
     * Eine Transaktion: Bereiche sperren, dann Einfrieren (vor der Frist) prüfen, dann markieren.
     * Alles oder nichts; die Bedingung `storniert_at IS NULL` fängt einen parallelen Storno ab.
     *
     * @param non-empty-list<array<string, mixed>> $zeilen Buchungszeilen inkl. bereich_id (alle desselben Vorgangs)
     */
    private function storniere(array $zeilen, ?int $stornoVonId, ?string $grund = null, bool $alsWart = false): void
    {
        try {
            (new BuchungModel())->transaktion(function () use ($zeilen, $stornoVonId, $grund, $alsWart): void {
                $this->sperreBereiche(array_map(static fn (array $z): int => (int) $z['bereich_id'], $zeilen), $zeilen[0]['vorgang_id']);
                $jetzt = service('uhr')->jetzt();

                foreach ($zeilen as $z) {
                    if (service('zeitraeume')->istEingefroren(self::zeit($z['gebucht_at']), (int) $z['bereich_id'])) {
                        throw new BuchungAbgelehnt('Dieser Zeitraum ist abgeschlossen.');
                    }
                }

                if (! $alsWart && ! StornoFrist::istOffen(self::zeit($zeilen[0]['gebucht_at']), $jetzt, service('einstellungen')->int('storno_frist_min'))) {
                    throw new BuchungAbgelehnt('Die Storno-Frist ist abgelaufen.');
                }

                $zeitpunkt = $jetzt->format(self::FORMAT);

                foreach ($zeilen as $z) {
                    db_connect()->table('buchungen')
                        ->where('id', (int) $z['id'])
                        ->where('storniert_at', null)
                        ->update(['storniert_at' => $zeitpunkt, 'storniert_von_id' => $stornoVonId, 'storno_grund' => $grund, 'updated_at' => $zeitpunkt]);

                    if (db_connect()->affectedRows() !== 1) {
                        throw new BuchungAbgelehnt('Bereits storniert.');
                    }

                    if ($alsWart) {
                        service('protokollierer')->schreibe((int) $stornoVonId, 'storniert', 'buchungen', (int) $z['id'], null, ['storniert_at' => $zeitpunkt, 'storno_grund' => $grund]);
                    }
                }
            });
        } catch (DatabaseException $e) {
            throw self::sperrfehlerAbgelehnt($e) ?? $e;
        }
    }

    /**
     * Storno durch den Wart: ohne Storno-Frist (Einfrieren und Bereichssperre gelten), Grund Pflicht, protokolliert.
     */
    public function storniereAlsWart(int $buchungId, int $wartId, string $grund): void
    {
        $grund = trim($grund);

        if ($grund === '') {
            throw new BuchungAbgelehnt('Bitte einen Grund angeben.');
        }

        if (mb_strlen($grund) > self::MAX_TEXT) {
            throw new BuchungAbgelehnt('Der Grund ist zu lang (höchstens ' . self::MAX_TEXT . ' Zeichen).');
        }

        $buchung = $this->zeilenQuery()->where('b.id', $buchungId)->get()->getRowArray();

        if ($buchung === null) {
            throw new BuchungAbgelehnt('Unbekannte Buchung.');
        }

        if ($buchung['storniert_at'] !== null) {
            throw new BuchungAbgelehnt('Bereits storniert.');
        }

        $this->storniere([$buchung], $wartId, $grund, true);
    }

    /**
     * Korrekturbuchung des Warts (Konto, Artikel, ±Menge ≠ 0, Bemerkung Pflicht): aktueller Preis, neue Vorgangs-ID,
     * Bereichssperre als erste Anweisung der Transaktion, Einfrieren geprüft, protokolliert. Archivierte Artikel sind erlaubt.
     *
     * @param ?int $bereichId wenn gesetzt, muss der Artikel zu diesem Bereich gehören
     *
     * @return array{vorgang_id: string, konto_id: int, positionen: list<array{artikel_id: int, name: string, menge: int, einzelpreis_cent: int}>, summe_cent: int, zusammenfassung: string, gebucht_at: string, wiederholt: bool, storniert: bool}
     */
    public function bucheKorrektur(int $kontoId, int $artikelId, int $menge, string $bemerkung, int $wartId, ?int $bereichId = null): array
    {
        $bemerkung = trim($bemerkung);

        if ($menge === 0 || abs($menge) > self::MAX_MENGE) {
            throw new BuchungAbgelehnt('Bitte eine Menge zwischen -' . self::MAX_MENGE . ' und ' . self::MAX_MENGE . ' (nicht 0) angeben.');
        }

        if ($bemerkung === '') {
            throw new BuchungAbgelehnt('Bitte eine Bemerkung angeben.');
        }

        if (mb_strlen($bemerkung) > self::MAX_TEXT) {
            throw new BuchungAbgelehnt('Die Bemerkung ist zu lang (höchstens ' . self::MAX_TEXT . ' Zeichen).');
        }

        $konto = (new PersonModel())->find($kontoId);

        if ($konto === null || $konto['archiviert_at'] !== null) {
            throw new BuchungAbgelehnt('Dieses Konto ist nicht buchbar. Nicht gebucht.');
        }

        $artikel = db_connect()->table('artikel a')
            ->select('a.id, a.name, a.preis_cent, k.bereich_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('a.id', $artikelId)
            ->get()->getRowArray();

        if ($artikel === null || ($bereichId !== null && (int) $artikel['bereich_id'] !== $bereichId)) {
            throw new BuchungAbgelehnt('Bitte einen Artikel dieses Bereichs wählen.');
        }

        $vorgangId = self::neueVorgangId();
        $model     = new BuchungModel();

        try {
            $model->transaktion(function () use ($model, $artikel, $kontoId, $artikelId, $menge, $bemerkung, $wartId, $vorgangId): void {
                $this->sperreBereiche([(int) $artikel['bereich_id']], $vorgangId);
                $jetzt = service('uhr')->jetzt();

                if (service('zeitraeume')->istEingefroren($jetzt, (int) $artikel['bereich_id'])) {
                    throw new BuchungAbgelehnt('Dieser Zeitraum ist abgeschlossen. Nicht gebucht.');
                }

                $zeile = [
                    'vorgang_id'       => $vorgangId,
                    'konto_id'         => $kontoId,
                    'artikel_id'       => $artikelId,
                    'menge'            => $menge,
                    'einzelpreis_cent' => (int) $artikel['preis_cent'],
                    'quelle'           => 'korrektur',
                    'gebucht_von_id'   => $wartId,
                    'geraet_id'        => null,
                    'gebucht_at'       => $jetzt->format(self::FORMAT),
                    'bemerkung'        => $bemerkung,
                ];
                $id = $model->insert($zeile, true);

                if ($id === false) {
                    throw new DatabaseException('Buchung konnte nicht gespeichert werden.');
                }

                service('protokollierer')->schreibe($wartId, 'korrektur', 'buchungen', (int) $id, null, $zeile);
            });
        } catch (DatabaseException $e) {
            throw self::sperrfehlerAbgelehnt($e) ?? $e;
        }

        return $this->ergebnis($this->zeilen($vorgangId), false);
    }

    /**
     * Lock-Wait-Timeout/Deadlock (MySQL 1205/1213) als fachliche Meldung (S2-R2), sonst null.
     */
    public static function sperrfehlerAbgelehnt(DatabaseException $e): ?BuchungAbgelehnt
    {
        if (in_array($e->getCode(), [1205, 1213], true) || str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), 'Deadlock')) {
            return new BuchungAbgelehnt(self::MELDUNG_ABRECHNUNG);
        }

        return null;
    }

    /**
     * Erste Abfrage jeder Schreib-Transaktion (Entscheidung 1). Danach wird der Stichtag frisch gelesen:
     * Der Cache könnte aus der Zeit vor der Sperre stammen.
     *
     * @param list<int> $bereichIds
     */
    private function sperreBereiche(array $bereichIds, string $vorgangId): void
    {
        (new BereichModel())->sperre($bereichIds);
        service('zeitraeume')->vergiss();
        $this->vorDemSchreiben($vorgangId);
    }

    private static function zeit(string $wert): DateTimeImmutable
    {
        return new DateTimeImmutable($wert, new DateTimeZone('Europe/Berlin'));
    }

    /**
     * Testnaht: läuft in der Schreib-Transaktion (Buchen und Storno) direkt nach der Bereichssperre,
     * vor Stichtag-Prüfung und Schreiben.
     */
    protected function vorDemSchreiben(string $vorgangId): void
    {
    }

    /**
     * Prüft Form und Mengen der Positionen und addiert doppelte Artikel.
     *
     * @param array<mixed> $positionen
     *
     * @return array<int, int> artikel_id => Menge, in Reihenfolge des ersten Auftretens
     */
    private function summiere(array $positionen): array
    {
        if ($positionen === []) {
            throw new BuchungAbgelehnt('Der Warenkorb ist leer.');
        }

        if (count($positionen) > self::MAX_POSITIONEN) {
            throw new BuchungAbgelehnt('Zu viele Positionen.');
        }

        $summiert = [];

        foreach ($positionen as $p) {
            if (! is_array($p) || ! isset($p['artikel_id'], $p['menge']) || ! is_int($p['artikel_id']) || ! is_int($p['menge'])) {
                throw new BuchungAbgelehnt('Ungültige Position. Nicht gebucht.');
            }

            if ($p['menge'] < 1 || $p['menge'] > self::MAX_MENGE) {
                throw new BuchungAbgelehnt('Ungültige Menge. Nicht gebucht.');
            }

            $summiert[$p['artikel_id']] = ($summiert[$p['artikel_id']] ?? 0) + $p['menge'];

            if ($summiert[$p['artikel_id']] > self::MAX_MENGE) {
                throw new BuchungAbgelehnt('Ungültige Menge. Nicht gebucht.');
            }
        }

        return $summiert;
    }

    /**
     * @return ?array<string, mixed> gespeichertes Ergebnis mit wiederholt=true, null wenn der Vorgang neu ist
     */
    private function wiederholung(string $vorgangId, int $kontoId, ?int $gebuchtVonId): ?array
    {
        $zeilen = $this->zeilen($vorgangId);

        if ($zeilen === []) {
            return null;
        }

        $vonId = $zeilen[0]['gebucht_von_id'] === null ? null : (int) $zeilen[0]['gebucht_von_id'];

        if ((int) $zeilen[0]['konto_id'] !== $kontoId || $vonId !== $gebuchtVonId) {
            throw new BuchungAbgelehnt('Ungültiger Vorgang.');
        }

        return $this->ergebnis($zeilen, true);
    }

    /**
     * @return list<array<string, mixed>> alle Zeilen des Vorgangs inkl. Artikelname
     */
    private function zeilen(string $vorgangId): array
    {
        return $this->zeilenQuery()->where('b.vorgang_id', $vorgangId)->orderBy('b.id')->get()->getResultArray();
    }

    private function zeilenQuery(): BaseBuilder
    {
        return db_connect()->table('buchungen b')
            ->select('b.*, a.name AS artikel_name, k.bereich_id')
            ->join('artikel a', 'a.id = b.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id');
    }

    /**
     * @param list<array<string, mixed>> $zeilen
     *
     * @return array<string, mixed>
     */
    private function ergebnis(array $zeilen, bool $wiederholt): array
    {
        $positionen = array_map(static fn (array $z): array => [
            'artikel_id'       => (int) $z['artikel_id'],
            'name'             => $z['artikel_name'],
            'menge'            => (int) $z['menge'],
            'einzelpreis_cent' => (int) $z['einzelpreis_cent'],
        ], $zeilen);

        return [
            'vorgang_id'      => $zeilen[0]['vorgang_id'],
            'konto_id'        => (int) $zeilen[0]['konto_id'],
            'positionen'      => $positionen,
            'summe_cent'      => array_sum(array_map(static fn (array $p): int => $p['menge'] * $p['einzelpreis_cent'], $positionen)),
            'zusammenfassung' => self::zusammenfassung($positionen),
            'gebucht_at'      => $zeilen[0]['gebucht_at'],
            'wiederholt'      => $wiederholt,
            'storniert'       => array_filter($zeilen, static fn (array $z): bool => $z['storniert_at'] === null) === [],
        ];
    }
}
