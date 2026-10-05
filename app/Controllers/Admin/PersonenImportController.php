<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\CsvPersonenParser;
use App\Libraries\EinmalPasswort;
use App\Models\PersonModel;
use CodeIgniter\HTTP\RedirectResponse;

class PersonenImportController extends BaseController
{
    private const MAX_BYTES    = 1048576;
    private const ENDUNGEN     = ['csv', 'txt'];
    private const SESSION_KEY  = 'import_zeilen';

    public function form(): string
    {
        return view('admin/personen/import');
    }

    public function vorschau(): string|RedirectResponse
    {
        $zurueck = redirect()->to(site_url('admin/personen/import'));
        $datei   = $this->request->getFile('datei');

        // Kein isValid(): das verlangt is_uploaded_file() und lässt sich in Tests nicht erfüllen.
        if ($datei === null || $datei->getError() !== UPLOAD_ERR_OK || $datei->getSize() === 0) {
            return $zurueck->with('error', 'Bitte eine CSV-Datei auswählen.');
        }

        if (! in_array(strtolower($datei->getClientExtension()), self::ENDUNGEN, true)) {
            return $zurueck->with('error', 'Nur .csv oder .txt erlaubt.');
        }

        if ($datei->getSize() > self::MAX_BYTES) {
            return $zurueck->with('error', 'Die Datei ist größer als 1 MB.');
        }

        $personen = new PersonModel();
        $ergebnis = (new CsvPersonenParser())->parse(
            (string) file_get_contents($datei->getTempName()),
            $personen->vorhandeneBenutzernamen(),
            $personen->vorhandeneNamen(),
        );

        if ($ergebnis['fehler'] !== null) {
            return $zurueck->with('error', $ergebnis['fehler']);
        }

        if ($ergebnis['zeilen'] === []) {
            return $zurueck->with('error', 'Die Datei enthält keine Personen.');
        }

        session()->set(self::SESSION_KEY, $ergebnis['zeilen']);

        return view('admin/personen/import_vorschau', [
            'zeilen' => $ergebnis['zeilen'],
            'gueltig' => count(array_filter($ergebnis['zeilen'], static fn (array $z): bool => $z['fehler'] === null)),
        ]);
    }

    public function ausfuehren(): RedirectResponse
    {
        $zeilen = session(self::SESSION_KEY);
        $zurueck = redirect()->to(site_url('admin/personen/import'));

        if (! is_array($zeilen)) {
            return $zurueck->with('error', 'Keine Vorschau vorhanden. Bitte die Datei erneut hochladen.');
        }

        $gueltig = array_values(array_filter($zeilen, static fn (array $z): bool => $z['fehler'] === null));

        if ($gueltig === []) {
            return $zurueck->with('error', 'Keine fehlerfreien Zeilen zum Importieren.');
        }

        $model        = new PersonModel();
        $adminId      = (int) service('anmeldung')->person()['id'];
        $liste        = [];
        $uebersprungen = 0;

        $model->transaktion(function () use ($model, $gueltig, $adminId, &$liste, &$uebersprungen): void {
            $namen = array_fill_keys($model->vorhandeneNamen(), true);

            foreach ($gueltig as $z) {
                $schluessel = mb_strtolower($z['vorname'] . ' ' . $z['nachname']);

                // Zwischenzeitlich vergeben (zweiter Admin, Doppelklick): überspringen statt abbrechen.
                if ($model->benutzernameVergeben($z['benutzername']) || isset($namen[$schluessel])) {
                    $uebersprungen++;

                    continue;
                }

                $daten = [
                    'vorname'      => $z['vorname'],
                    'nachname'     => $z['nachname'],
                    'anzeigename'  => trim($z['vorname'] . ' ' . $z['nachname']),
                    'gruppe'       => $z['gruppe'],
                    'benutzername' => $z['benutzername'],
                ];
                $passwort = EinmalPasswort::erzeuge();
                $id       = $model->legeMitgliedAn($daten, $passwort);

                $namen[$schluessel] = true;
                service('protokollierer')->schreibe($adminId, 'importiert', 'personen', $id, null, $daten);
                $liste[] = ['name' => $daten['anzeigename'], 'benutzername' => $daten['benutzername'], 'passwort' => $passwort];
            }
        });

        session()->remove(self::SESSION_KEY);

        if ($liste === []) {
            return $zurueck->with('error', 'Alle Zeilen waren inzwischen vergeben. Es wurde niemand angelegt.');
        }

        $antwort = redirect()->to(site_url('admin/personen/einmalpasswoerter'))->with('einmalpasswoerter', $liste);

        return $uebersprungen > 0 ? $antwort->with('success', "{$uebersprungen} Zeile(n) übersprungen, da inzwischen vergeben.") : $antwort;
    }
}
