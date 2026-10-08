<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuszaehlungModel;
use App\Models\AuszaehlungPositionModel;
use App\Models\BereichModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Auszählung (Spec 7.3): Vorschlag des Solls je Artikel, Entwurf, Abschluss und Excel-Datei. Der Zeitraum reicht vom Beginn
 * (`zeitraeume()`, exklusiv bzw. inklusiv bei der Inbetriebnahme) bis zum Stichtag inklusive.
 */
class AuszaehlungService
{
    public const MAX_BEMERKUNG = 1000;
    public const MELDUNG_IST = 'Bitte eine Zahl ab 0 eintragen.';
    public const MELDUNG_IST_FEHLT = 'Bitte für jeden Artikel eintragen, wie viel du gezählt hast.';
    public const MELDUNG_IST_LEER = 'Bitte eintragen.';

    /**
     * Soll je Artikel (Entscheidung 7: bestandsführend; archivierte nur mit Bestand oder Aktivität im Zeitraum),
     * sortiert nach Kategorie und Artikel; `ist` ist immer null, `preis_cent` der aktuelle Verkaufspreis.
     *
     * @return list<array<string, mixed>> Position (AuszaehlungRechner::position) + artikel_id, name, einheit, kategorie_id, kategorie_name, preis_cent
     */
    public function vorschlag(int $bereichId, DateTimeImmutable $stichtag): array
    {
        $db      = db_connect();
        $artikel = $db->table('artikel a')
            ->select('a.id, a.name, a.einheit, a.preis_cent, a.archiviert_at, k.id AS kategorie_id, k.name AS kategorie_name')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)->where('a.bestand_fuehren', 1)
            ->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')
            ->get()->getResultArray();

        if ($artikel === []) {
            return [];
        }

        $ids      = array_map(static fn (array $a): int => (int) $a['id'], $artikel);
        $aggregat = service('bestand')->aggregat($bereichId, $ids, $stichtag);

        // Start = keine Position in der letzten abgeschlossenen Auszählung: Der Anfangsbestand 0 ist dann unbekannt,
        // die Differenz zählt nicht als Schwund (auch wenn der Artikel in einer älteren Auszählung schon gezählt wurde).
        $frueher = [];
        $letzte  = (new AuszaehlungModel())->letzteAbgeschlossene($bereichId);

        if ($letzte !== null) {
            foreach ($db->table('auszaehlung_positionen')->select('artikel_id')
                ->where('auszaehlung_id', $letzte['id'])->whereIn('artikel_id', $ids)->get()->getResultArray() as $p) {
                $frueher[(int) $p['artikel_id']] = true;
            }
        }

        $ergebnis = [];

        foreach ($artikel as $a) {
            $id       = (int) $a['id'];
            $g        = $aggregat[$id];
            $position = AuszaehlungRechner::position(
                $g['anfangsbestand'], $g['lieferungen'], $g['schwund_erfasst'], $g['korrekturen'], $g['verkauft'],
                null, (int) $a['preis_cent'], ! isset($frueher[$id]),
            );

            if ($a['archiviert_at'] !== null && ! $this->hatAktivitaet($position)) {
                continue;
            }

            $ergebnis[] = $position + [
                'artikel_id'     => $id,
                'name'           => (string) $a['name'],
                'einheit'        => (string) $a['einheit'],
                'kategorie_id'   => (int) $a['kategorie_id'],
                'kategorie_name' => (string) $a['kategorie_name'],
                'preis_cent'     => (int) $a['preis_cent'],
            ];
        }

        return $ergebnis;
    }

    /**
     * Speichert den Entwurf des Bereichs (höchstens einer; ein vorhandener wird aktualisiert, seine Positionen ersetzt).
     * Bereichssperre zuerst (S2-R1); die Soll-Werte sind eine Momentaufnahme zum Speicherzeitpunkt.
     *
     * @param array<int|string, ?int> $ist artikel_id => Ist (null = noch nicht gezählt)
     *
     * @return int ID der Auszählung
     */
    public function speichereEntwurf(int $bereichId, int $wartId, DateTimeImmutable $stichtag, array $ist, ?string $bemerkung): int
    {
        $stichtag  = self::minute($stichtag);
        $bemerkung = $this->pruefeEingaben($ist, $bemerkung);
        $id        = 0;
        $model     = new AuszaehlungModel();

        $this->schreibend(function () use ($model, $bereichId, $wartId, $stichtag, $ist, $bemerkung, &$id): void {
            $kopf    = $this->kopf($bereichId, $stichtag, $bemerkung);
            $entwurf = $model->entwurf($bereichId);

            if ($entwurf === null) {
                $id = (int) $model->insert($kopf + ['bereich_id' => $bereichId, 'status' => 'entwurf', 'erstellt_von_id' => $wartId], true);
            } else {
                $id = (int) $entwurf['id'];
                $model->update($id, $kopf);
            }

            $this->schreibePositionen($id, $this->positionen($bereichId, $stichtag, $ist));
        });

        return $id;
    }

    /**
     * Schließt die Auszählung des Bereichs ab (Spec 7.3 Schritt 4) – eine kurze Transaktion (S2-R2): Bereich sperren,
     * Stichtag frisch prüfen, Soll **neu berechnen** (nie aus dem Entwurf übernehmen, Review Focus 5), Ist für jeden
     * Artikel Pflicht. Ein vorhandener Entwurf wird zur abgeschlossenen Auszählung, sonst wird eine neu angelegt;
     * `erstellt_von_id` ist danach, wer abgeschlossen hat.
     * Erst nach dem Commit entsteht die Excel-Datei; scheitert sie, bleibt die Auszählung abgeschlossen und
     * `datei_pfad` NULL (Review Focus 2; Hinweis über `dateiFehlt()`, Fehler im Log).
     *
     * @param array<int|string, ?int> $ist artikel_id => Ist
     *
     * @return int ID der abgeschlossenen Auszählung
     */
    public function schliesseAb(int $bereichId, int $wartId, DateTimeImmutable $stichtag, array $ist, ?string $bemerkung): int
    {
        $stichtag  = self::minute($stichtag);
        $bemerkung = $this->pruefeEingaben($ist, $bemerkung);
        $id        = 0;
        $model     = new AuszaehlungModel();

        $this->schreibend(function () use ($model, $bereichId, $wartId, $stichtag, $ist, $bemerkung, &$id): void {
            $kopf       = $this->kopf($bereichId, $stichtag, $bemerkung);
            $positionen = $this->positionen($bereichId, $stichtag, $ist);
            $fehlend    = [];

            foreach ($positionen as $p) {
                if ($p['ist'] === null) {
                    $fehlend["ist.{$p['artikel_id']}"] = self::MELDUNG_IST_LEER;
                }
            }

            if ($fehlend !== []) {
                throw new AuszaehlungAbgelehnt(self::MELDUNG_IST_FEHLT, $fehlend);
            }

            $abschluss = $kopf + [
                'status' => 'abgeschlossen', 'abgeschlossen_at' => service('uhr')->jetzt()->format('Y-m-d H:i:s'),
                'erstellt_von_id' => $wartId, 'datei_pfad' => null,
            ];
            $entwurf = $model->entwurf($bereichId);

            if ($entwurf === null) {
                $id = (int) $model->insert($abschluss + ['bereich_id' => $bereichId], true);
            } else {
                $id = (int) $entwurf['id'];
                $model->update($id, $abschluss);
            }

            $this->schreibePositionen($id, $positionen);

            service('protokollierer')->schreibe($wartId, 'abgeschlossen', 'auszaehlungen', $id, ['status' => $entwurf === null ? null : 'entwurf'], [
                'status' => 'abgeschlossen', 'art' => $kopf['art'], 'stichtag' => $kopf['stichtag'], 'zeitraum_von' => $kopf['zeitraum_von'],
            ]);
        });

        service('zeitraeume')->vergiss();

        try {
            $this->speichereDatei($id);
        } catch (Throwable $e) {
            log_message('error', 'Excel-Export der Auszählung {id} fehlgeschlagen: {meldung}', ['id' => $id, 'meldung' => $e->getMessage()]);
        }

        return $id;
    }

    /**
     * true, wenn die Auszählung keine Datei hat (z. B. weil der Export nach dem Abschluss scheiterte).
     */
    public function dateiFehlt(int $auszaehlungId): bool
    {
        $zeile = (new AuszaehlungModel())->find($auszaehlungId);

        return $zeile !== null && $zeile['datei_pfad'] === null;
    }

    /**
     * Erzeugt die Excel-Datei einer abgeschlossenen Auszählung neu (nur aus gespeicherten Daten, dieselben Werte),
     * speichert den Pfad und protokolliert `datei_erzeugt` (wenn eine Person angegeben ist).
     *
     * @throws RuntimeException wenn die Auszählung nicht abgeschlossen ist oder die Datei nicht geschrieben werden kann
     *
     * @return string Pfad relativ zur Export-Basis (`exporte/…`)
     */
    public function dateiNeuErzeugen(int $auszaehlungId, ?int $personId = null): string
    {
        $pfad = $this->speichereDatei($auszaehlungId);

        if ($personId !== null) {
            service('protokollierer')->schreibe($personId, 'datei_erzeugt', 'auszaehlungen', $auszaehlungId, null, ['datei_pfad' => $pfad]);
        }

        return $pfad;
    }

    /**
     * Erzeugt die Datei und speichert den Pfad. Hat sich der Name geändert, wird die alte Datei nach dem Update gelöscht
     * (nur wenn sie per `AuszaehlungExport::datei()` unter `exporte/` liegt).
     */
    private function speichereDatei(int $auszaehlungId): string
    {
        $model  = new AuszaehlungModel();
        $export = service('auszaehlungExport');
        $alt    = $model->find($auszaehlungId)['datei_pfad'] ?? null;
        $pfad   = $export->erzeuge($auszaehlungId);
        $model->update($auszaehlungId, ['datei_pfad' => $pfad]);

        if ($alt !== null && $alt !== $pfad && ($alteDatei = $export->datei((string) $alt)) !== null && $alteDatei !== $export->datei($pfad)) {
            @unlink($alteDatei);
        }

        return $pfad;
    }

    /**
     * Gemeinsame Vorprüfung vor der Transaktion: Bemerkung höchstens 1000 Zeichen, Ist nicht negativ.
     *
     * @param array<int|string, ?int> $ist
     */
    private function pruefeEingaben(array $ist, ?string $bemerkung): ?string
    {
        $bemerkung = $bemerkung === null || trim($bemerkung) === '' ? null : trim($bemerkung);
        $fehler    = [];

        if ($bemerkung !== null && mb_strlen($bemerkung) > self::MAX_BEMERKUNG) {
            $fehler['bemerkung'] = 'Die Bemerkung ist zu lang (höchstens ' . self::MAX_BEMERKUNG . ' Zeichen).';
        }

        foreach ($ist as $artikelId => $wert) {
            if ($wert !== null && $wert < 0) {
                $fehler["ist.{$artikelId}"] = self::MELDUNG_IST;
            }
        }

        if ($fehler !== []) {
            throw new AuszaehlungAbgelehnt('Bitte die markierten Felder prüfen. Nichts gespeichert.', $fehler);
        }

        return $bemerkung;
    }

    /**
     * Transaktion (R12); Lock-Wait-Timeout/Deadlock wird zur deutschen Meldung (S2-R2).
     * Die Arbeit muss mit `kopf()` beginnen (Bereichssperre als erste Anweisung, S2-R1).
     */
    private function schreibend(callable $arbeit): void
    {
        try {
            (new AuszaehlungModel())->transaktion($arbeit);
        } catch (DatabaseException $e) {
            if (BuchungService::sperrfehlerAbgelehnt($e) !== null) {
                throw new AuszaehlungAbgelehnt('Gerade wird abgerechnet – bitte gleich erneut versuchen.');
            }

            throw $e;
        }
    }

    /**
     * Sperrt den Bereich, prüft den Stichtag gegen den frisch gelesenen letzten Abschluss und liefert die Kopfdaten.
     *
     * @return array{art: string, stichtag: string, zeitraum_von: string, bemerkung: ?string}
     */
    private function kopf(int $bereichId, DateTimeImmutable $stichtag, ?string $bemerkung): array
    {
        (new BereichModel())->sperre([$bereichId]);
        service('zeitraeume')->vergiss();
        $this->nachDerSperre($bereichId);

        $zeitraeume = service('zeitraeume');
        $letzter    = $zeitraeume->letzterStichtag($bereichId);
        $meldung    = AuszaehlungRechner::pruefeStichtag($stichtag, $letzter, service('uhr')->jetzt());

        if ($meldung !== null) {
            throw new AuszaehlungAbgelehnt($meldung, ['stichtag' => $meldung]);
        }

        return [
            'art'          => $letzter === null ? 'start' : 'regulaer',
            'stichtag'     => $stichtag->format('Y-m-d H:i:s'),
            'zeitraum_von' => $zeitraeume->beginn($bereichId)->format('Y-m-d H:i:s'),
            'bemerkung'    => $bemerkung,
        ];
    }

    /**
     * Positionen frisch aus dem Vorschlag (unter der Sperre) mit den übergebenen Ist-Werten.
     *
     * @param array<int|string, ?int> $ist
     *
     * @return list<array<string, mixed>> Spalten von `auszaehlung_positionen` ohne auszaehlung_id
     */
    private function positionen(int $bereichId, DateTimeImmutable $stichtag, array $ist): array
    {
        $zeilen = [];

        foreach ($this->vorschlag($bereichId, $stichtag) as $p) {
            $wert     = $ist[$p['artikel_id']] ?? null;
            $position = AuszaehlungRechner::position(
                $p['anfangsbestand'], $p['lieferungen'], $p['schwund_erfasst'], $p['korrekturen'], $p['verkauft'],
                $wert, $p['preis_cent'], $p['start'],
            );
            $zeilen[] = [
                'artikel_id' => $p['artikel_id'],
                'anfangsbestand' => $position['anfangsbestand'], 'lieferungen' => $position['lieferungen'],
                'schwund_erfasst' => $position['schwund_erfasst'], 'korrekturen' => $position['korrekturen'],
                'verkauft' => $position['verkauft'], 'soll' => $position['soll'], 'ist' => $wert,
                'differenz' => $position['differenz'] ?? 0, 'start' => $position['start'] ? 1 : 0, 'preis_cent' => $p['preis_cent'],
            ];
        }

        return $zeilen;
    }

    /**
     * @param list<array<string, mixed>> $positionen
     */
    private function schreibePositionen(int $auszaehlungId, array $positionen): void
    {
        db_connect()->table('auszaehlung_positionen')->where('auszaehlung_id', $auszaehlungId)->delete();

        if ($positionen !== []) {
            (new AuszaehlungPositionModel())->insertBatch(array_map(
                static fn (array $p): array => ['auszaehlung_id' => $auszaehlungId] + $p,
                $positionen,
            ));
        }
    }

    private static function minute(DateTimeImmutable $zeit): DateTimeImmutable
    {
        return $zeit->setTime((int) $zeit->format('H'), (int) $zeit->format('i'), 0);
    }

    /**
     * Testnaht: läuft in der Transaktion (Entwurf und Abschluss) direkt nach der Bereichssperre, vor der Stichtag-Prüfung.
     */
    protected function nachDerSperre(int $bereichId): void
    {
    }

    /**
     * @param array<string, mixed> $p
     */
    private function hatAktivitaet(array $p): bool
    {
        return $p['soll'] !== 0 || $p['anfangsbestand'] !== 0 || $p['lieferungen'] !== 0
            || $p['schwund_erfasst'] !== 0 || $p['korrekturen'] !== 0 || $p['verkauft'] !== 0;
    }
}
