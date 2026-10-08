<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Artikelbild;
use App\Libraries\BildAbgelehnt;
use App\Models\ArtikelModel;
use App\Models\BereichModel;
use App\Models\KategorieModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Kategorien und Artikel. Nur aktive Bereiche (der Kiosk ist bis Stufe 3 ausgeblendet):
 * Alles, was zu einem inaktiven Bereich gehört, ist hier 404.
 *
 * Reine Sortier-Aktionen (hoch/runter) werden nicht protokolliert (Rauschen, kein fachlicher Inhalt).
 */
class StammdatenController extends BaseController
{
    private const MELDUNG_KATEGORIE_ARCHIVIERT = 'Die Kategorie ist archiviert.';

    public function index(): string
    {
        $archiv = $this->request->getGet('archiviert') === 'ja';
        $katModel     = new KategorieModel();
        $artikelModel = new ArtikelModel();
        $bereiche     = [];

        foreach ((new BereichModel())->aktive() as $bereich) {
            $kategorien = [];
            $abfrage    = $katModel->where('bereich_id', $bereich['id']);

            if (! $archiv) {
                $abfrage->where('archiviert_at', null);
            }

            foreach ($abfrage->orderBy('archiviert_at IS NOT NULL', '', false)->orderBy('sortierung')->orderBy('id')->findAll() as $kategorie) {
                $artikel = $artikelModel->where('kategorie_id', $kategorie['id']);

                if (! $archiv) {
                    $artikel->where('archiviert_at', null);
                }

                $kategorie['artikel'] = $artikel->orderBy('archiviert_at IS NOT NULL', '', false)->orderBy('sortierung')->orderBy('id')->findAll();
                $kategorien[]         = $kategorie;
            }

            $bereich['kategorien'] = $kategorien;
            $bereiche[]            = $bereich;
        }

        return view('admin/stammdaten/index', ['bereiche' => $bereiche, 'archiviert' => $archiv]);
    }

    // ---- Kategorien -------------------------------------------------------

    public function kategorieAnlegen(): RedirectResponse
    {
        $bereichId = (int) $this->request->getPost('bereich_id');
        $this->aktiverBereich($bereichId);
        $name = trim((string) $this->request->getPost('name'));

        if ($fehler = $this->pruefeKategorieName($name)) {
            return $this->zurueckZurListe()->with('error', $fehler);
        }

        $model = new KategorieModel();
        $id    = 0;

        $model->transaktion(function () use ($model, $bereichId, $name, &$id): void {
            $id = (int) $model->insert(['bereich_id' => $bereichId, 'name' => $name, 'sortierung' => $model->naechsteSortierung($bereichId)], true);
            service('protokollierer')->schreibe($this->adminId(), 'angelegt', 'kategorien', $id, null, ['bereich_id' => $bereichId, 'name' => $name]);
        });

        return $this->zurueckZurListe()->with('success', 'Kategorie angelegt.');
    }

    public function kategorieSpeichern(int $id): RedirectResponse
    {
        $kategorie = $this->kategorie($id);
        $name      = trim((string) $this->request->getPost('name'));
        $fehler    = $kategorie['archiviert_at'] !== null ? self::MELDUNG_KATEGORIE_ARCHIVIERT : $this->pruefeKategorieName($name);

        if ($fehler !== null) {
            return $this->zurueckZurListe()->with('error', $fehler);
        }

        if ($name !== $kategorie['name']) {
            $model = new KategorieModel();

            $model->transaktion(function () use ($model, $id, $kategorie, $name): void {
                $model->update($id, ['name' => $name]);
                service('protokollierer')->schreibe($this->adminId(), 'geaendert', 'kategorien', $id, ['name' => $kategorie['name']], ['name' => $name]);
            });
        }

        return $this->zurueckZurListe()->with('success', 'Gespeichert.');
    }

    public function kategorieVerschieben(int $id, string $richtung): RedirectResponse
    {
        $this->kategorie($id);
        (new KategorieModel())->verschiebe($id, $richtung);

        return $this->zurueckZurListe();
    }

    public function kategorieArchivieren(int $id): RedirectResponse
    {
        $kategorie = $this->kategorie($id);

        if ($kategorie['archiviert_at'] !== null) {
            return $this->zurueckZurListe()->with('error', self::MELDUNG_KATEGORIE_ARCHIVIERT);
        }

        $jetzt = service('uhr')->jetzt()->format('Y-m-d H:i:s');
        $model = new KategorieModel();

        $model->transaktion(function () use ($model, $id, $jetzt): void {
            $model->update($id, ['archiviert_at' => $jetzt]);
            service('protokollierer')->schreibe($this->adminId(), 'archiviert', 'kategorien', $id, ['archiviert_at' => null], ['archiviert_at' => $jetzt]);
        });

        return $this->zurueckZurListe()->with('success', 'Kategorie archiviert. Ihre Artikel sind nicht mehr buchbar.');
    }

    // ---- Artikel ----------------------------------------------------------

    public function artikelNeu(): string|RedirectResponse
    {
        $kategorie = $this->kategorie((int) $this->request->getGet('kategorie'));

        if ($kategorie['archiviert_at'] !== null) {
            return $this->zurueckZurListe()->with('error', self::MELDUNG_KATEGORIE_ARCHIVIERT);
        }

        return view('admin/stammdaten/artikel_formular', ['artikel' => null, 'kategorie' => $kategorie, 'kategorien' => $this->waehlbareKategorien()]);
    }

    public function artikelAnlegen(): RedirectResponse
    {
        $kategorie = $this->kategorie((int) $this->request->getPost('kategorie_id'));

        if ($kategorie['archiviert_at'] !== null) {
            return $this->zurueckZurListe()->with('error', self::MELDUNG_KATEGORIE_ARCHIVIERT);
        }

        [$daten, $fehler] = $this->pruefeArtikel();

        $zurueck = redirect()->to(site_url('admin/artikel/neu?kategorie=' . $kategorie['id']));

        if ($fehler !== null) {
            return $zurueck->withInput()->with('error', $fehler);
        }

        [$neueDatei, $bildFehler] = $this->schreibeHochgeladenesBild();

        if ($bildFehler !== null) {
            return $zurueck->withInput()->with('fehler', ['bild' => $bildFehler]);
        }

        $model = new ArtikelModel();
        $daten = ['kategorie_id' => (int) $kategorie['id']] + $daten;

        service('artikelbild')->wechsle($neueDatei, function () use ($model, $daten, $neueDatei): ?string {
            $id = (int) $model->insert($daten + ['sortierung' => $model->naechsteSortierung($daten['kategorie_id'])], true);
            service('protokollierer')->schreibe($this->adminId(), 'angelegt', 'artikel', $id, null, $daten);

            return $neueDatei === null ? null : service('artikelbild')->uebernehme($id, $neueDatei, $this->adminId());
        });

        return $this->zurueckZurListe()->with('success', 'Artikel angelegt.');
    }

    public function artikelBearbeiten(int $id): string
    {
        $artikel = $this->artikel($id);

        return view('admin/stammdaten/artikel_formular', [
            'artikel'    => $artikel,
            'kategorie'  => $this->kategorie((int) $artikel['kategorie_id']),
            'kategorien' => $this->waehlbareKategorien(),
        ]);
    }

    public function artikelSpeichern(int $id): RedirectResponse
    {
        $artikel = $this->artikel($id);
        $zurueck = redirect()->to(site_url("admin/artikel/{$id}"));

        if ($artikel['archiviert_at'] !== null) {
            return $this->zurueckZurListe()->with('error', 'Archivierte Artikel können nicht bearbeitet werden.');
        }

        [$daten, $fehler] = $this->pruefeArtikel();

        // Zielkategorie: nur aktiver Bereich und nicht archiviert (oder die bisherige).
        $zielId = (int) $this->request->getPost('kategorie_id');

        if ($fehler === null && $zielId !== (int) $artikel['kategorie_id'] && ! array_key_exists($zielId, $this->waehlbareKategorien())) {
            $fehler = 'Bitte eine gültige Kategorie wählen.';
        }

        if ($fehler !== null) {
            return $zurueck->withInput()->with('error', $fehler);
        }

        $daten = ['kategorie_id' => $zielId] + $daten;
        $alt   = $neu = [];

        foreach ($daten as $feld => $wert) {
            if ($artikel[$feld] !== null ? (string) $artikel[$feld] !== (string) $wert : $wert !== null) {
                $alt[$feld] = $artikel[$feld];
                $neu[$feld] = $wert;
            }
        }

        // Ein neues Bild ersetzt das alte; „Bild entfernen“ zählt nur ohne neues Bild und nur, wenn eines da ist.
        $entfernen = $this->request->getPost('bild_entfernen') === '1' && $artikel['bild_datei'] !== null;
        $hochladen = $this->bildHochgeladen();

        if ($neu === [] && ! $hochladen && ! $entfernen) {
            return $zurueck->with('success', 'Keine Änderungen.');
        }

        [$neueDatei, $bildFehler] = $this->schreibeHochgeladenesBild();

        if ($bildFehler !== null) {
            return $zurueck->withInput()->with('fehler', ['bild' => $bildFehler]);
        }

        $model = new ArtikelModel();

        service('artikelbild')->wechsle($neueDatei, function () use ($model, $id, $alt, $neu, $neueDatei, $entfernen): ?string {
            $protokoll = service('protokollierer');
            $update    = $neu;

            if (isset($neu['kategorie_id'])) {
                $update['sortierung'] = $model->naechsteSortierung((int) $neu['kategorie_id']);
            }

            $altesBild = $neueDatei !== null || $entfernen ? service('artikelbild')->uebernehme($id, $neueDatei, $this->adminId()) : null;

            if ($update === []) {
                return $altesBild;
            }

            $model->update($id, $update);

            if (array_key_exists('preis_cent', $neu)) {
                $protokoll->schreibe($this->adminId(), 'preis_geaendert', 'artikel', $id, ['preis_cent' => (int) $alt['preis_cent']], ['preis_cent' => (int) $neu['preis_cent']]);
                unset($alt['preis_cent'], $neu['preis_cent']);
            }

            if ($neu !== []) {
                $protokoll->schreibe($this->adminId(), 'geaendert', 'artikel', $id, $alt, $neu);
            }

            return $altesBild;
        });

        return $zurueck->with('success', 'Gespeichert.');
    }

    public function artikelVerschieben(int $id, string $richtung): RedirectResponse
    {
        $this->artikel($id);
        (new ArtikelModel())->verschiebe($id, $richtung);

        return $this->zurueckZurListe();
    }

    public function artikelArchivieren(int $id): RedirectResponse
    {
        $artikel = $this->artikel($id);

        if ($artikel['archiviert_at'] !== null) {
            return $this->zurueckZurListe()->with('error', 'Der Artikel ist bereits archiviert.');
        }

        $jetzt = service('uhr')->jetzt()->format('Y-m-d H:i:s');
        $model = new ArtikelModel();

        $model->transaktion(function () use ($model, $id, $jetzt): void {
            $model->update($id, ['archiviert_at' => $jetzt]);
            service('protokollierer')->schreibe($this->adminId(), 'archiviert', 'artikel', $id, ['archiviert_at' => null], ['archiviert_at' => $jetzt]);
        });

        return $this->zurueckZurListe()->with('success', 'Artikel archiviert.');
    }

    // ---- Hilfen -----------------------------------------------------------

    private function bildHochgeladen(): bool
    {
        $datei = $this->request->getFile('bild');

        return $datei !== null && $datei->getError() !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Prüft und schreibt ein hochgeladenes Bild (Feld `bild`), bevor die DB geändert wird.
     *
     * @return array{0: ?string, 1: ?string} neuer Dateiname (null = kein Upload) und Fehlertext für das Feld `bild`
     */
    private function schreibeHochgeladenesBild(): array
    {
        if (! $this->bildHochgeladen()) {
            return [null, null];
        }

        $datei = $this->request->getFile('bild');

        // Kein isValid(): das verlangt is_uploaded_file() und lässt sich in Tests nicht erfüllen.
        $fehler = match ($datei->getError()) {
            UPLOAD_ERR_OK                             => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => Artikelbild::MELDUNG_GROESSE,
            default                                   => 'Das Bild konnte nicht hochgeladen werden. Bitte erneut versuchen.',
        };

        if ($fehler !== null) {
            return [null, $fehler];
        }

        try {
            return [service('artikelbild')->schreibe($datei->getTempName(), (int) $datei->getSize()), null];
        } catch (BildAbgelehnt $e) {
            return [null, $e->getMessage()];
        }
    }

    private function adminId(): int
    {
        return (int) service('anmeldung')->person()['id'];
    }

    private function zurueckZurListe(): RedirectResponse
    {
        return redirect()->to(site_url('admin/stammdaten'));
    }

    /**
     * @return array<string, mixed>
     */
    private function aktiverBereich(int $id): array
    {
        $bereich = (new BereichModel())->where('aktiv', 1)->find($id);

        if ($bereich === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $bereich;
    }

    /**
     * @return array<string, mixed> Kategorie, 404 bei unbekannt oder inaktivem Bereich
     */
    private function kategorie(int $id): array
    {
        $kategorie = (new KategorieModel())->find($id);

        if ($kategorie === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->aktiverBereich((int) $kategorie['bereich_id']);

        return $kategorie;
    }

    /**
     * @return array<string, mixed> Artikel, 404 bei unbekannt oder inaktivem Bereich
     */
    private function artikel(int $id): array
    {
        $artikel = (new ArtikelModel())->find($id);

        if ($artikel === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->kategorie((int) $artikel['kategorie_id']);

        return $artikel;
    }

    /**
     * Ziele für Artikel: nicht archivierte Kategorien aktiver Bereiche.
     *
     * @return array<int, array<string, mixed>> Kategorie-ID => Kategorie inkl. `bereich_name`
     */
    private function waehlbareKategorien(): array
    {
        $zeilen = db_connect()->table('kategorien k')
            ->select('k.*, b.name AS bereich_name')
            ->join('bereiche b', 'b.id = k.bereich_id')
            ->where('b.aktiv', 1)->where('k.archiviert_at', null)
            ->orderBy('b.id')->orderBy('k.sortierung')->orderBy('k.id')
            ->get()->getResultArray();

        return array_column($zeilen, null, 'id');
    }

    private function pruefeKategorieName(string $name): ?string
    {
        return match (true) {
            $name === ''            => 'Bitte einen Namen eingeben.',
            mb_strlen($name) > 100  => 'Der Name darf höchstens 100 Zeichen lang sein.',
            default                 => null,
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string} Felder (ohne kategorie_id) und Fehlertext
     */
    private function pruefeArtikel(): array
    {
        $name    = trim((string) $this->request->getPost('name'));
        $einheit = trim((string) $this->request->getPost('einheit'));
        $preis   = betrag_in_cent((string) $this->request->getPost('preis'));
        $gebinde = trim((string) $this->request->getPost('gebinde_groesse'));
        $mindest = trim((string) $this->request->getPost('mindestbestand'));
        $mindest = $mindest === '' ? '0' : $mindest;

        $daten = [
            'name'            => $name,
            'preis_cent'      => $preis,
            'einheit'         => $einheit,
            'gebinde_groesse' => $gebinde === '' ? null : (ctype_digit($gebinde) && strlen($gebinde) <= 3 ? (int) $gebinde : -1),
            'mindestbestand'  => ctype_digit($mindest) && strlen($mindest) <= 6 ? (int) $mindest : -1,
            'bestand_fuehren' => $this->request->getPost('bestand_fuehren') === '1' ? 1 : 0,
        ];

        $fehler = match (true) {
            $name === ''                                  => 'Bitte einen Namen eingeben.',
            mb_strlen($name) > 100                        => 'Der Name darf höchstens 100 Zeichen lang sein.',
            $einheit === ''                               => 'Bitte eine Einheit eingeben, z. B. „0,5 l Flasche“.',
            mb_strlen($einheit) > 50                      => 'Die Einheit darf höchstens 50 Zeichen lang sein.',
            $preis === null                               => 'Bitte einen gültigen Preis eingeben, z. B. 1,50.',
            $daten['gebinde_groesse'] !== null && ($daten['gebinde_groesse'] < 1 || $daten['gebinde_groesse'] > 100)
                                                          => 'Die Gebindegröße muss leer oder eine Zahl von 1 bis 100 sein.',
            $daten['mindestbestand'] < 0                  => 'Der Mindestbestand muss eine Zahl von 0 bis 999999 sein.',
            default                                       => null,
        };

        return [$daten, $fehler];
    }
}
