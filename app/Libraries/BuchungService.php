<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ArtikelModel;
use App\Models\BuchungModel;
use App\Models\PersonModel;
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
    private const UUID_V4        = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const FORMAT         = 'Y-m-d H:i:s';

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
        }

        $this->vorDemSchreiben($vorgangId);

        $jetzt = service('uhr')->jetzt()->format(self::FORMAT);
        $model = new BuchungModel();

        try {
            $this->transaktion(static function () use ($zeilen, $model, $jetzt): void {
                foreach ($zeilen as $zeile) {
                    $zeile['gebucht_at'] = $jetzt;

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

            throw $e;
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

        $this->pruefeStornierbar($alle[0]['gebucht_at']);
        $this->markiere(array_column($offen, 'id'), $stornoVonId);
    }

    public function storniereBuchung(int $buchungId, ?int $stornoVonId): void
    {
        $buchung = (new BuchungModel())->find($buchungId);

        if ($buchung === null) {
            throw new BuchungAbgelehnt('Unbekannte Buchung.');
        }

        if ($buchung['storniert_at'] !== null) {
            throw new BuchungAbgelehnt('Bereits storniert.');
        }

        $this->pruefeStornierbar($buchung['gebucht_at']);
        $this->markiere([$buchungId], $stornoVonId);
    }

    private function pruefeStornierbar(string $gebuchtAt): void
    {
        $gebucht = new DateTimeImmutable($gebuchtAt, new DateTimeZone('Europe/Berlin'));

        // Stufe 1: es gibt noch keinen Stichtag; Stufe 2 liefert hier das Datum.
        $letzterStichtag = null;

        if (ZeitraumErmittler::istEingefroren($gebucht, $letzterStichtag)) {
            throw new BuchungAbgelehnt('Dieser Zeitraum ist abgeschlossen.');
        }

        if (! StornoFrist::istOffen($gebucht, service('uhr')->jetzt(), service('einstellungen')->int('storno_frist_min'))) {
            throw new BuchungAbgelehnt('Die Storno-Frist ist abgelaufen.');
        }
    }

    /**
     * Alles oder nichts; die Bedingung `storniert_at IS NULL` fängt einen parallelen Storno ab.
     *
     * @param list<int|string> $ids
     */
    private function markiere(array $ids, ?int $stornoVonId): void
    {
        $jetzt = service('uhr')->jetzt()->format(self::FORMAT);

        $this->transaktion(static function () use ($ids, $stornoVonId, $jetzt): void {
            foreach ($ids as $id) {
                $builder = db_connect()->table('buchungen')
                    ->where('id', (int) $id)
                    ->where('storniert_at', null);
                $builder->update(['storniert_at' => $jetzt, 'storniert_von_id' => $stornoVonId, 'storno_grund' => null, 'updated_at' => $jetzt]);

                if (db_connect()->affectedRows() !== 1) {
                    throw new BuchungAbgelehnt('Bereits storniert.');
                }
            }
        });
    }

    /**
     * Testnaht: läuft nach den Prüfungen, direkt vor der Buchungs-Transaktion.
     */
    protected function vorDemSchreiben(string $vorgangId): void
    {
    }

    /**
     * Transaktion, in der jeder fehlgeschlagene Query eine Exception wirft (CI4 wirft in
     * Transaktionen sonst nicht und committet Teilergebnisse). Bei jedem Fehler: Rollback, Exception weiter.
     */
    private function transaktion(callable $arbeit): void
    {
        $db = db_connect();
        $db->transException(true);
        $db->transBegin();

        try {
            $arbeit();
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->transException(false);
        }
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
        return db_connect()->table('buchungen b')
            ->select('b.*, a.name AS artikel_name')
            ->join('artikel a', 'a.id = b.artikel_id')
            ->where('b.vorgang_id', $vorgangId)
            ->orderBy('b.id')
            ->get()->getResultArray();
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
