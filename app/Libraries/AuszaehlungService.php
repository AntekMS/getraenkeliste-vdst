<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuszaehlungModel;
use App\Models\AuszaehlungPositionModel;
use App\Models\BereichModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;

/**
 * Auszählung (Spec 7.3): Vorschlag des Solls je Artikel und Entwurf. Der Zeitraum reicht vom Beginn
 * (`zeitraeume()`, exklusiv bzw. inklusiv bei der Inbetriebnahme) bis zum Stichtag inklusive.
 */
class AuszaehlungService
{
    public const MAX_BEMERKUNG = 1000;
    public const MELDUNG_IST = 'Ist muss eine ganze Zahl ≥ 0 sein.';

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

        $frueher = [];

        foreach ($db->table('auszaehlung_positionen p')->select('p.artikel_id')->distinct()
            ->join('auszaehlungen au', 'au.id = p.auszaehlung_id')
            ->where('au.bereich_id', $bereichId)->where('au.status', 'abgeschlossen')
            ->whereIn('p.artikel_id', $ids)->get()->getResultArray() as $p) {
            $frueher[(int) $p['artikel_id']] = true;
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
        $stichtag  = $stichtag->setTime((int) $stichtag->format('H'), (int) $stichtag->format('i'), 0);
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

        $id    = 0;
        $model = new AuszaehlungModel();

        try {
            $model->transaktion(function () use ($model, $bereichId, $wartId, $stichtag, $ist, $bemerkung, &$id): void {
                (new BereichModel())->sperre([$bereichId]);
                service('zeitraeume')->vergiss();

                $zeitraeume = service('zeitraeume');
                $meldung    = AuszaehlungRechner::pruefeStichtag($stichtag, $zeitraeume->letzterStichtag($bereichId), service('uhr')->jetzt());

                if ($meldung !== null) {
                    throw new AuszaehlungAbgelehnt($meldung, ['stichtag' => $meldung]);
                }

                $art  = $zeitraeume->letzterStichtag($bereichId) === null ? 'start' : 'regulaer';
                $kopf = [
                    'art' => $art, 'stichtag' => $stichtag->format('Y-m-d H:i:s'),
                    'zeitraum_von' => $zeitraeume->beginn($bereichId)->format('Y-m-d H:i:s'), 'bemerkung' => $bemerkung,
                ];

                $entwurf = $model->entwurf($bereichId);

                if ($entwurf === null) {
                    $id = (int) $model->insert($kopf + ['bereich_id' => $bereichId, 'status' => 'entwurf', 'erstellt_von_id' => $wartId], true);
                } else {
                    $id = (int) $entwurf['id'];
                    $model->update($id, $kopf);
                    db_connect()->table('auszaehlung_positionen')->where('auszaehlung_id', $id)->delete();
                }

                $zeilen = [];

                foreach ($this->vorschlag($bereichId, $stichtag) as $p) {
                    $wert     = $ist[$p['artikel_id']] ?? null;
                    $position = AuszaehlungRechner::position(
                        $p['anfangsbestand'], $p['lieferungen'], $p['schwund_erfasst'], $p['korrekturen'], $p['verkauft'],
                        $wert, $p['preis_cent'], $p['start'],
                    );
                    $zeilen[] = [
                        'auszaehlung_id' => $id, 'artikel_id' => $p['artikel_id'],
                        'anfangsbestand' => $position['anfangsbestand'], 'lieferungen' => $position['lieferungen'],
                        'schwund_erfasst' => $position['schwund_erfasst'], 'korrekturen' => $position['korrekturen'],
                        'verkauft' => $position['verkauft'], 'soll' => $position['soll'], 'ist' => $wert,
                        'differenz' => $position['differenz'] ?? 0, 'start' => $position['start'] ? 1 : 0, 'preis_cent' => $p['preis_cent'],
                    ];
                }

                if ($zeilen !== []) {
                    (new AuszaehlungPositionModel())->insertBatch($zeilen);
                }
            });
        } catch (DatabaseException $e) {
            if (BuchungService::sperrfehlerAbgelehnt($e) !== null) {
                throw new AuszaehlungAbgelehnt('Gerade wird abgerechnet – bitte gleich erneut versuchen.');
            }

            throw $e;
        }

        return $id;
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
