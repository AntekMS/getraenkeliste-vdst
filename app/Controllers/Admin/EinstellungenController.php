<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\EinstellungDefinition;
use CodeIgniter\HTTP\RedirectResponse;

class EinstellungenController extends BaseController
{
    public function index(): string
    {
        return $this->formular([], []);
    }

    /**
     * Zuerst alles validieren, erst dann speichern: bei einem Fehler wird nichts geaendert.
     * Fehlende oder nicht aenderbare Felder (z. B. `inbetriebnahme_at`) werden ignoriert.
     */
    public function speichern(): string|RedirectResponse
    {
        $werte   = [];
        $fehler  = [];
        $einstellungen = service('einstellungen');

        foreach (EinstellungDefinition::aenderbar() as $schluessel) {
            $roh = $this->request->getPost($schluessel);

            if (! is_string($roh)) {
                continue;
            }

            $wert           = trim($roh);
            $werte[$schluessel] = $wert;
            $meldung        = EinstellungDefinition::validiere($schluessel, $wert);

            if ($meldung !== null) {
                $fehler[$schluessel] = $meldung;
            }
        }

        if ($fehler !== []) {
            return $this->formular($werte, $fehler);
        }

        $adminId = (int) service('anmeldung')->person()['id'];

        foreach ($werte as $schluessel => $wert) {
            $meldung = $einstellungen->setze($schluessel, $wert, $adminId);

            if ($meldung !== null) {
                return $this->formular($werte, [$schluessel => $meldung]);
            }
        }

        return redirect()->to(site_url('admin/einstellungen'))->with('success', 'Einstellungen gespeichert.');
    }

    /**
     * @param array<string, string> $eingaben
     * @param array<string, string> $fehler
     */
    private function formular(array $eingaben, array $fehler): string
    {
        $einstellungen = service('einstellungen');
        $felder        = [];

        foreach (EinstellungDefinition::aenderbar() as $schluessel) {
            $definition = EinstellungDefinition::DEFINITIONEN[$schluessel];
            $felder[]   = [
                'schluessel' => $schluessel,
                'label'      => $definition['label'],
                'typ'        => $definition['typ'],
                'hinweis'    => $definition['typ'] === 'int'
                    ? "Ganze Zahl von {$definition['min']} bis {$definition['max']}."
                    : "{$definition['min']} bis {$definition['max']} Zeichen.",
                'wert'       => $eingaben[$schluessel] ?? $einstellungen->text($schluessel),
                'fehler'     => $fehler[$schluessel] ?? null,
            ];
        }

        return view('admin/einstellungen/index', [
            'felder'         => $felder,
            'inbetriebnahme' => $einstellungen->inbetriebnahme(),
        ]);
    }
}
