<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\BuchungAbgelehnt;
use App\Libraries\BuchungService;
use App\Libraries\StornoFrist;
use App\Models\BuchungModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Eigene Buchungen im laufenden Zeitraum (Stufe 1: ab Inbetriebnahme) mit offenem Betrag
 * je Bereich und Storno. Stornos werden bewusst nicht protokolliert.
 */
class MeineBuchungenController extends BaseController
{
    public function index(): string
    {
        $ich    = (int) service('anmeldung')->person()['id'];
        $ab     = service('einstellungen')->inbetriebnahme();
        $jetzt  = service('uhr')->jetzt();
        $frist  = service('einstellungen')->int('storno_frist_min');
        $model  = new BuchungModel();
        $offen  = static fn (array $z): bool => $z['storniert_at'] === null
            && StornoFrist::istOffen(new DateTimeImmutable($z['gebucht_at'], new DateTimeZone('Europe/Berlin')), $jetzt, $frist);

        $bereiche = [];

        foreach (db_connect()->table('bereiche')->where('aktiv', 1)->orderBy('id')->get()->getResultArray() as $b) {
            $bereiche[$b['schluessel']] = ['name' => $b['name'], 'zeilen' => [], 'betrag' => $model->offenerBetrag($ich, $b['schluessel'], $ab)];
        }

        foreach ($model->fuerKonto($ich, $ab) as $zeile) {
            if (isset($bereiche[$zeile['bereich_schluessel']])) {
                $bereiche[$zeile['bereich_schluessel']]['zeilen'][] = $zeile;
            }
        }

        return view('meine_buchungen/index', [
            'bereiche'  => $bereiche,
            'sammel'    => $model->vonPersonAufSammelkonten($ich, $ab),
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
