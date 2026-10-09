<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Controllers\Concerns\WartEingaben;
use DateTimeImmutable;

/**
 * Seite „Schwund“ des Warts (Statistik-Spec 3): Kennzahlen des letzten abgeschlossenen Zeitraums, Verlauf der letzten
 * 6 Zeiträume, Top-10-Artikel, laufender Zeitraum. Dünn: Zahlen kommen aus `statistik()`. `artikel` wird nur als
 * reine Ziffernfolge akzeptiert; der Service lässt bereichsfremde Artikel leer (kein Verlauf, nie 500).
 */
class SchwundController extends BaseController
{
    use WartEingaben;

    private const ZEITRAEUME = 6;

    public function index(string $bereichSchluessel): string
    {
        $bereich   = $this->bereich($bereichSchluessel);
        $bereichId = (int) $bereich['id'];
        $statistik = service('statistik');
        $zeitraeume = $statistik->schwundZeitraeume($bereichId, self::ZEITRAEUME);

        $artikelText = $this->text($this->request->getGet('artikel'));
        $artikelId   = preg_match('/^[1-9][0-9]{0,9}$/D', $artikelText) === 1 ? (int) $artikelText : 0;
        $top         = $statistik->schwundArtikel($bereichId, self::ZEITRAEUME, 10);
        $verlauf     = [];
        $gewaehlt    = null;

        if ($artikelId > 0) {
            $verlauf = $statistik->schwundArtikelVerlauf($bereichId, $artikelId, self::ZEITRAEUME);

            if ($verlauf !== []) {
                $gewaehlt = $artikelId;
            }
        }

        return view('wart/schwund', [
            'bereich'    => $bereich,
            'zeitraeume' => $zeitraeume,
            'kennzahlen' => $this->kennzahlen($zeitraeume),
            'diagramm'   => $this->diagramm($zeitraeume),
            'top'        => $top,
            'gewaehlt'   => $gewaehlt,
            'verlauf'    => $verlauf,
            'artikelName' => $gewaehlt === null ? '' : $statistik->artikelName($bereichId, $gewaehlt),
            'laufend'    => $statistik->schwundLaufend($bereichId),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $zeitraeume neueste zuerst
     *
     * @return ?array{zeitraum: array<string, mixed>, start: bool, delta: ?float}
     */
    private function kennzahlen(array $zeitraeume): ?array
    {
        if ($zeitraeume === []) {
            return null;
        }

        $jetzt = $zeitraeume[0];
        $delta = null;

        if ($jetzt['art'] !== 'start' && $jetzt['quote'] !== null && isset($zeitraeume[1]) && $zeitraeume[1]['quote'] !== null) {
            $delta = round(round($jetzt['quote'], 1) - round($zeitraeume[1]['quote'], 1), 1);
        }

        return ['zeitraum' => $jetzt, 'start' => $jetzt['art'] === 'start', 'delta' => $delta];
    }

    /**
     * Gestapeltes Säulendiagramm, älteste Zeiträume links; Euro mit zwei Nachkommastellen.
     *
     * @param list<array<string, mixed>> $zeitraeume neueste zuerst
     */
    private function diagramm(array $zeitraeume): string
    {
        $aufsteigend = array_reverse($zeitraeume);
        $euro        = static fn (int $cent): float => round($cent / 100, 2);

        return json_encode([
            'typ'    => 'saeulen-gestapelt',
            'labels' => array_map(fn (array $z): string => $this->bereichText($z) . ($z['art'] === 'start' ? ' (Start)' : ''), $aufsteigend),
            'reihen' => [
                ['name' => 'Erfasst', 'werte' => array_map(static fn (array $z): float => $euro((int) $z['erfasst_cent']), $aufsteigend)],
                ['name' => 'Unerklärt', 'werte' => array_map(static fn (array $z): float => $euro((int) $z['unerklaert_cent']), $aufsteigend)],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $zeitraum
     */
    private function bereichText(array $zeitraum): string
    {
        return (new DateTimeImmutable($zeitraum['von']))->format('d.m.Y') . ' – ' . (new DateTimeImmutable($zeitraum['bis']))->format('d.m.Y');
    }
}
