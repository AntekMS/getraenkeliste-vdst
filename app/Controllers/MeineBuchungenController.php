<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\BuchungAbgelehnt;
use App\Libraries\BuchungService;
use App\Libraries\StornoFrist;
use App\Models\BereichModel;
use App\Models\BuchungModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Eigene Buchungen im laufenden Zeitraum je Bereich (ab dem letzten abgeschlossenen Stichtag bzw. der
 * Inbetriebnahme) mit offenem Betrag, darunter frühere Zeiträume mit eigener Summe. Stornos werden
 * bewusst nicht protokolliert.
 */
class MeineBuchungenController extends BaseController
{
    public function index(): string
    {
        $ich        = (int) service('anmeldung')->person()['id'];
        $zeitraeume = service('zeitraeume');
        $jetzt      = service('uhr')->jetzt();
        $frist      = service('einstellungen')->int('storno_frist_min');
        $model      = new BuchungModel();
        $zeit       = static fn (array $z): DateTimeImmutable => new DateTimeImmutable($z['gebucht_at'], new DateTimeZone('Europe/Berlin'));
        $offen      = static fn (array $z): bool => $z['storniert_at'] === null
            && ! $zeitraeume->istEingefroren($zeit($z), (int) $z['bereich_id'])
            && StornoFrist::istOffen($zeit($z), $jetzt, $frist);

        $bereiche = [];
        $sammel   = [];
        $fruehere = [];

        foreach ((new BereichModel())->aktive() as $b) {
            $id       = (int) $b['id'];
            $ab       = $zeitraeume->beginn($id);
            $inklusiv = $zeitraeume->beginnInklusiv($id);

            $bereiche[] = [
                'name'   => $b['name'],
                'zeilen' => $model->fuerKonto($ich, $ab, $inklusiv, $id),
                'betrag' => $model->offenerBetrag($ich, $b['schluessel'], $ab, $inklusiv),
            ];
            $sammel = [...$sammel, ...$model->vonPersonAufSammelkonten($ich, $ab, $inklusiv, $id)];

            $zeitraeumeDesBereichs = array_map(static fn (array $f): array => [
                'von'   => $f['von'],
                'bis'   => $f['bis'],
                'summe' => $model->summeImZeitraum($ich, $id, $f['von'], $f['bis'], $f['von_inklusiv']),
            ], $zeitraeume->fruehere($id));

            if ($zeitraeumeDesBereichs !== []) {
                $fruehere[] = ['name' => $b['name'], 'zeitraeume' => $zeitraeumeDesBereichs];
            }
        }

        // Neueste zuerst über alle Bereiche (wie je Bereich in der Abfrage).
        usort($sammel, static fn (array $x, array $y): int => [$y['gebucht_at'], (int) $y['id']] <=> [$x['gebucht_at'], (int) $x['id']]);

        return view('meine_buchungen/index', [
            'bereiche'    => $bereiche,
            'sammel'      => $sammel,
            'fruehere'    => $fruehere,
            'stornierbar' => $offen,
        ]);
    }

    public function storno(string $id): ResponseInterface
    {
        $ich     = (int) service('anmeldung')->person()['id'];
        $buchung = (new BuchungModel())->find((int) $id);

        // Unbekannte und fremde Buchungen sehen gleich aus.
        if ($buchung === null || ((int) $buchung['konto_id'] !== $ich && (int) ($buchung['gebucht_von_id'] ?? 0) !== $ich)) {
            return $this->response->setStatusCode(403)->setBody(view('errors/keine_berechtigung'));
        }

        try {
            (new BuchungService())->storniereBuchung((int) $buchung['id'], $ich);
        } catch (BuchungAbgelehnt $e) {
            return redirect()->to(site_url('meine-buchungen'))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('meine-buchungen'))->with('success', 'Buchung storniert.');
    }
}
