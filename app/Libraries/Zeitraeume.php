<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuszaehlungModel;
use App\Models\BereichModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Zeiträume je Bereich (Spec 6.1): der laufende Zeitraum beginnt exklusiv am Stichtag der letzten
 * **abgeschlossenen** Auszählung, ohne Auszählung inklusiv bei der Inbetriebnahme. Alles mit
 * Zeitpunkt ≤ letztem Stichtag ist eingefroren. Je Request gecacht; `vergiss()` leert den Cache
 * (nach einem Abschluss und vor jeder Prüfung unter Bereichssperre).
 */
class Zeitraeume
{
    /** @var array<int, list<array<string, mixed>>> bereich_id => abgeschlossene Auszählungen, neueste zuerst */
    private array $abgeschlossene = [];

    public function letzterStichtag(int $bereichId): ?DateTimeImmutable
    {
        $letzte = $this->abgeschlossene($bereichId)[0] ?? null;

        return $letzte === null ? null : self::zeit($letzte['stichtag']);
    }

    public function beginn(int $bereichId): DateTimeImmutable
    {
        return $this->letzterStichtag($bereichId) ?? service('einstellungen')->inbetriebnahme();
    }

    /**
     * true nur ohne abgeschlossene Auszählung (Beginn = Inbetriebnahme gehört dazu).
     */
    public function beginnInklusiv(int $bereichId): bool
    {
        return $this->letzterStichtag($bereichId) === null;
    }

    public function istEingefroren(DateTimeImmutable $zeitpunkt, int $bereichId): bool
    {
        return ZeitraumErmittler::istEingefroren($zeitpunkt, $this->letzterStichtag($bereichId));
    }

    /**
     * Abgeschlossene Zeiträume, neueste zuerst. `von` ist exklusiv, außer beim ersten Zeitraum
     * (Inbetriebnahme, `von_inklusiv`); `bis` (Stichtag) ist inklusiv.
     *
     * @return list<array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool, auszaehlung_id: int}>
     */
    public function fruehere(int $bereichId): array
    {
        $auszaehlungen = $this->abgeschlossene($bereichId);
        $liste         = [];

        foreach ($auszaehlungen as $i => $a) {
            $vorherige = $auszaehlungen[$i + 1] ?? null;
            $liste[]   = [
                'von'            => $vorherige === null ? service('einstellungen')->inbetriebnahme() : self::zeit($vorherige['stichtag']),
                'bis'            => self::zeit($a['stichtag']),
                'von_inklusiv'   => $vorherige === null,
                'auszaehlung_id' => (int) $a['id'],
            ];
        }

        return $liste;
    }

    /**
     * Volle Kalendertage vom letzten Stichtag (sonst von der Inbetriebnahme) bis „jetzt“.
     */
    public function tageSeitLetztemAbschluss(int $bereichId): int
    {
        $beginn = $this->beginn($bereichId)->setTime(0, 0);
        $heute  = service('uhr')->jetzt()->setTime(0, 0);

        return max(0, (int) $beginn->diff($heute)->format('%r%a'));
    }

    /**
     * Erinnerungen für das Banner: je aktivem Bereich, in dem die Rollen Auszählungen durchführen dürfen
     * und der letzte Stichtag (sonst Inbetriebnahme) mehr als `erinnerung_tage` Tage zurückliegt.
     * Ohne das Recht in irgendeinem Bereich entsteht keine weitere Abfrage.
     *
     * @param list<string> $rollen
     *
     * @return list<array{schluessel: string, name: string, tage: int, hat_auszaehlung: bool}>
     */
    public function erinnerungen(array $rollen): array
    {
        $recht = Berechtigung::AUSZAEHLUNG_DURCHFUEHREN;

        if (! Berechtigung::darf($rollen, $recht, 'getraenke') && ! Berechtigung::darf($rollen, $recht, 'kiosk')) {
            return [];
        }

        $schwelle = service('einstellungen')->int('erinnerung_tage');
        $liste    = [];

        foreach ((new BereichModel())->aktive() as $bereich) {
            if (! Berechtigung::darf($rollen, $recht, $bereich['schluessel'])) {
                continue;
            }

            $id   = (int) $bereich['id'];
            $tage = $this->tageSeitLetztemAbschluss($id);

            if ($tage > $schwelle) {
                $liste[] = [
                    'schluessel'      => $bereich['schluessel'],
                    'name'            => $bereich['name'],
                    'tage'            => $tage,
                    'hat_auszaehlung' => $this->letzterStichtag($id) !== null,
                ];
            }
        }

        return $liste;
    }

    public function vergiss(): void
    {
        $this->abgeschlossene = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function abgeschlossene(int $bereichId): array
    {
        return $this->abgeschlossene[$bereichId] ??= (new AuszaehlungModel())->abgeschlossene($bereichId);
    }

    private static function zeit(string $wert): DateTimeImmutable
    {
        return new DateTimeImmutable($wert, new DateTimeZone('Europe/Berlin'));
    }
}
