<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Controllers\Concerns\WartEingaben;
use App\Libraries\StatistikRechner;
use App\Models\AuszaehlungModel;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;

/**
 * Seite „Einkauf“ des Warts (Statistik-Spec 2): Bestellliste, Bestand mit Reichweite, Anteil Couleur/Bund, Verlauf,
 * Lieferhistorie. Dünn: Zahlen kommen aus `statistik()`; `ansicht` wird nur als skalarer Text weitergereicht,
 * der Service prüft sie (ungültig/bereichsfremd → `alle`). Unbekannte/inaktive Bereiche sind 404.
 */
class EinkaufController extends BaseController
{
    use WartEingaben;

    private const FENSTER_TAGE = 28;

    public function index(string $bereichSchluessel): string
    {
        $bereich   = $this->bereich($bereichSchluessel);
        $bereichId = (int) $bereich['id'];
        $statistik = service('statistik');
        $einkauf   = $statistik->einkauf($bereichId);
        $ansicht   = $this->text($this->request->getGet('ansicht'));
        $negativ   = false;
        $bestellen = [];

        foreach ($einkauf['kategorien'] as $kategorie) {
            $zeilen = array_values(array_filter($kategorie['artikel'], static fn (array $a): bool => $a['vorschlag'] !== null));

            if ($zeilen !== []) {
                $bestellen[] = ['kategorie_name' => $kategorie['kategorie_name'], 'artikel' => $zeilen];
            }

            foreach ($kategorie['artikel'] as $artikel) {
                $negativ = $negativ || $artikel['ampel'] === 'negativ';
            }
        }

        return view('wart/einkauf', [
            'bereich'     => $bereich,
            'einkauf'     => $einkauf,
            'bestellen'   => $bestellen,
            'negativ'     => $negativ,
            'anteile'     => $this->anteile($bereichId),
            'verlauf'     => $statistik->wochenverbrauch($bereichId, $ansicht === '' ? 'alle' : $ansicht),
            'optionen'    => $statistik->ansichtOptionen($bereichId),
            'lieferungen' => $statistik->lieferhistorie($bereichId),
        ]);
    }

    /** Alte Bestandsseite: dauerhaft auf „Einkauf“ (inaktiver Bereich bleibt 404). */
    public function bestand(string $bereichSchluessel): RedirectResponse
    {
        $bereich = $this->bereich($bereichSchluessel);

        return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/einkauf'), 301);
    }

    /**
     * Kacheln „letzte 28 Tage“ (vs. 28 Tage davor) und „letzter abgeschlossener Zeitraum“ (vs. vorheriger, falls vorhanden).
     *
     * @return list<array{titel: string, zeitraum: ?string, jetzt: array<string, mixed>, davor: ?array<string, mixed>}>
     */
    private function anteile(int $bereichId): array
    {
        $statistik = service('statistik');
        $jetzt     = service('uhr')->jetzt();
        $grenze    = $jetzt->modify('-' . self::FENSTER_TAGE . ' days');
        $kacheln   = [[
            'titel'    => 'Letzte 28 Tage',
            'zeitraum' => null,
            'jetzt'    => $this->prozente($statistik->anteile($bereichId, $grenze, false, $jetzt)),
            'davor'    => $this->prozente($statistik->anteile($bereichId, $grenze->modify('-' . self::FENSTER_TAGE . ' days'), false, $grenze)),
        ]];

        $abgeschlossen = (new AuszaehlungModel())->letzteMitZeitraum($bereichId, 2);

        if ($abgeschlossen !== []) {
            $wert = static function (array $auszaehlung) use ($statistik, $bereichId): array {
                $z = $auszaehlung['zeitraum'];

                return $statistik->anteile($bereichId, $z['von'], $z['von_inklusiv'], $z['bis']);
            };
            $z = $abgeschlossen[0]['zeitraum'];

            $kacheln[] = [
                'titel'    => 'Letzter abgeschlossener Zeitraum',
                'zeitraum' => $this->datum($z['von']) . ' – ' . $this->datum($z['bis']),
                'jetzt'    => $this->prozente($wert($abgeschlossen[0])),
                'davor'    => isset($abgeschlossen[1]) ? $this->prozente($wert($abgeschlossen[1])) : null,
            ];
        }

        return $kacheln;
    }

    /**
     * @param array{menge: array{mitglieder: int, couleur: int, bund: int}, cent: array{mitglieder: int, couleur: int, bund: int}} $werte
     *
     * @return array{menge: array<string, ?float>, cent: array<string, ?float>, roh: array{menge: array<string, int>, cent: array<string, int>}} Anteile in Prozent und die Rohwerte
     */
    private function prozente(array $werte): array
    {
        return ['menge' => StatistikRechner::anteile($werte['menge']), 'cent' => StatistikRechner::anteile($werte['cent']), 'roh' => $werte];
    }

    private function datum(DateTimeImmutable $zeit): string
    {
        return $zeit->format('d.m.Y');
    }
}
