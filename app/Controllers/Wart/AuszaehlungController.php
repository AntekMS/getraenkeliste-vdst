<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Controllers\Concerns\WartEingaben;
use App\Libraries\AuszaehlungAbgelehnt;
use App\Libraries\AuszaehlungRechner;
use App\Libraries\AuszaehlungService;
use App\Libraries\Berechtigung;
use App\Models\ArtikelModel;
use App\Models\AuszaehlungModel;
use App\Models\AuszaehlungPositionModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Auszählung des Warts: Soll-Vorschlag, Entwurf und Abschluss; Liste der abgeschlossenen Auszählungen mit Download
 * und „Datei neu erzeugen“. Der Bereich kommt aus der Route (Recht über den Filter); unbekannte oder inaktive Bereiche sind 404.
 */
class AuszaehlungController extends BaseController
{
    use WartEingaben;

    private const MELDUNG_STICHTAG = 'Bitte einen gültigen Stichtag angeben.';

    /** Obergrenze für einen gezählten Stückwert (auch nach Kisten × Gebinde). */
    private const MAX_STUECK = 999999;

    public function index(string $bereichSchluessel): string
    {
        $bereich   = $this->bereich($bereichSchluessel);
        $bereichId = (int) $bereich['id'];
        $entwurf   = (new AuszaehlungModel())->entwurf($bereichId);
        $jetzt     = service('uhr')->jetzt();
        $fehler    = session()->getFlashdata('fehler') ?? [];

        $angefragt = $this->text($this->request->getGet('stichtag'));
        $stichtag  = $angefragt === '' ? null : $this->stichtag($angefragt);

        if ($angefragt !== '' && $stichtag === null) {
            $fehler['stichtag'] = self::MELDUNG_STICHTAG;
        }

        $alt = $this->text(old('stichtag', ''));

        if ($stichtag === null && $alt !== '') {
            $stichtag = $this->stichtag($alt);
        }

        $stichtag ??= $entwurf !== null ? $this->stichtag(substr((string) $entwurf['stichtag'], 0, 16)) : null;
        $stichtag ??= $this->aktuelleMinute($jetzt);

        $meldung = AuszaehlungRechner::pruefeStichtag($stichtag, service('zeitraeume')->letzterStichtag($bereichId), $jetzt);

        if ($meldung !== null && ! isset($fehler['stichtag'])) {
            $fehler['stichtag'] = $meldung;
        }

        $gespeichert = [];

        if ($entwurf !== null) {
            foreach ((new AuszaehlungPositionModel())->fuer((int) $entwurf['id']) as $p) {
                $gespeichert[(int) $p['artikel_id']] = $p['ist'] === null ? '' : (string) $p['ist'];
            }
        }

        $vorschlag = service('auszaehlungen')->vorschlag($bereichId, $stichtag);
        $gebinde   = $this->gebinde(array_column($vorschlag, 'artikel_id'));
        $hatAlt    = old('aktion') !== null;
        $gruppen   = [];
        $summe     = ['artikel' => 0, 'gezaehlt' => 0, 'abweichend' => 0, 'cent' => 0, 'neu' => false];

        foreach ($vorschlag as $p) {
            $zeile = $this->zeile($p, $gebinde[(int) $p['artikel_id']] ?? null, $hatAlt, $gespeichert);
            $gruppen[$p['kategorie_id']] ??= ['kategorie_name' => $p['kategorie_name'], 'positionen' => []];
            $gruppen[$p['kategorie_id']]['positionen'][] = $zeile;

            $summe['artikel']++;
            $summe['neu'] = $summe['neu'] || (bool) $p['start'];

            if ($zeile['abweichung'] === null) {
                continue;
            }

            $summe['gezaehlt']++;

            // „Neu“-Artikel (start): Abweichung zählt nicht als Schwund, daher weder in Anzahl noch Geldsumme.
            if (! $p['start'] && $zeile['abweichung'] !== 0) {
                $summe['abweichend']++;
                $summe['cent'] += $zeile['abweichung'] * (int) $p['preis_cent'];
            }
        }

        return view('wart/auszaehlung_formular', [
            'bereich'     => $bereich,
            'gruppen'     => array_values($gruppen),
            'summe'       => $summe,
            'nachFehler'  => $hatAlt,
            'stichtag'    => $stichtag->format('Y-m-d\TH:i'),
            'bemerkung'   => $this->text(old('bemerkung', $entwurf['bemerkung'] ?? '')),
            'hatEntwurf'  => $entwurf !== null,
            'fehler'      => $fehler,
        ]);
    }

    /**
     * Anzeigewerte einer Zeile: Eingabe aus `old()` (nach einem Fehler) oder dem Entwurf; Gebinde-Artikel zeigen einen
     * Stückwert als Kisten/einzeln (⌊n/gebinde⌋, Rest). `abweichung` = gezählt − laut System, null = nicht (gültig) gezählt.
     *
     * @param array<string, mixed>   $p
     * @param array<int, string>     $gespeichert
     *
     * @return array<string, mixed>
     */
    private function zeile(array $p, ?int $gebinde, bool $hatAlt, array $gespeichert): array
    {
        $id     = (int) $p['artikel_id'];
        $altIst = $hatAlt ? old('ist', null, false) : null;
        $ist    = $hatAlt ? $this->text(is_array($altIst) ? ($altIst[$id] ?? '') : '') : ($gespeichert[$id] ?? '');
        $zeile  = $p + ['gebinde' => $gebinde, 'wert' => trim($ist), 'kisten' => '', 'einzeln' => ''];

        if ($gebinde === null) {
            $gezaehlt = $this->stueck('', $zeile['wert'], null);
            $gezaehlt = is_int($gezaehlt) ? $gezaehlt : null;
        } else {
            $kisten  = $hatAlt ? old('kisten', null, false) : null;
            $einzeln = $hatAlt ? old('einzeln', null, false) : null;
            $kisten  = is_array($kisten) ? $kisten : [];
            $einzeln = is_array($einzeln) ? $einzeln : [];

            if (array_key_exists($id, $kisten) || array_key_exists($id, $einzeln)) {
                $zeile['kisten']  = trim($this->text($kisten[$id] ?? ''));
                $zeile['einzeln'] = trim($this->text($einzeln[$id] ?? ''));
            } elseif (ctype_digit($zeile['wert'])) {
                $zeile['kisten']  = (string) intdiv((int) $zeile['wert'], $gebinde);
                $zeile['einzeln'] = (string) ((int) $zeile['wert'] % $gebinde);
            }

            $gezaehlt = $this->stueck($zeile['kisten'], $zeile['einzeln'], $gebinde);
            $gezaehlt = is_int($gezaehlt) ? $gezaehlt : null;
        }

        return $zeile + ['gezaehlt' => $gezaehlt, 'abweichung' => $gezaehlt === null ? null : $gezaehlt - (int) $p['soll']];
    }

    /**
     * Gebindegröße je Artikel (nur > 0), eine Abfrage.
     *
     * @param list<int|string> $ids
     *
     * @return array<int, int>
     */
    private function gebinde(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $gebinde = [];

        foreach ((new ArtikelModel())->select('id, gebinde_groesse')->whereIn('id', $ids)->findAll() as $a) {
            if ((int) $a['gebinde_groesse'] > 0) {
                $gebinde[(int) $a['id']] = (int) $a['gebinde_groesse'];
            }
        }

        return $gebinde;
    }

    /**
     * Kisten × Gebinde + einzeln: null = beide leer, false = ungültige Eingabe (keine ganze Zahl ≥ 0, zu groß oder Kisten ohne Gebinde).
     */
    private function stueck(string $kisten, string $einzeln, ?int $gebinde): int|false|null
    {
        if ($kisten === '' && $einzeln === '') {
            return null;
        }

        foreach ([$kisten, $einzeln] as $wert) {
            if ($wert !== '' && preg_match('/^\d{1,6}$/', $wert) !== 1) {
                return false;
            }
        }

        if ($kisten !== '' && $gebinde === null) {
            return false;
        }

        $stueck = (int) $kisten * (int) $gebinde + (int) $einzeln;

        return $stueck > self::MAX_STUECK ? false : $stueck;
    }

    public function speichern(string $bereichSchluessel): RedirectResponse
    {
        $bereich = $this->bereich($bereichSchluessel);
        $zurueck = redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/auszaehlung'))->withInput();
        $aktion  = $this->text($this->request->getPost('aktion'));

        if (! in_array($aktion, ['entwurf', 'abschliessen'], true)) {
            return $zurueck->with('error', 'Unbekannte Aktion.');
        }

        $stichtag = $this->stichtag($this->text($this->request->getPost('stichtag')));

        if ($stichtag === null) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gespeichert.')->with('fehler', ['stichtag' => self::MELDUNG_STICHTAG]);
        }

        $roh   = $this->request->getPost('ist');
        $ist   = [];
        $fehler = [];

        foreach (is_array($roh) ? $roh : [] as $artikelId => $wert) {
            $wert = trim((string) (is_array($wert) ? '' : $wert));

            if (! ctype_digit((string) $artikelId)) {
                continue;
            }

            if ($wert === '') {
                $ist[(int) $artikelId] = null;
            } elseif (preg_match('/^\d{1,6}$/', $wert) === 1) {
                $ist[(int) $artikelId] = (int) $wert;
            } else {
                $fehler["ist.{$artikelId}"] = AuszaehlungService::MELDUNG_IST;
            }
        }

        // Gebinde-Artikel: „Kisten“ und „einzeln“ werden hier zu Stück (ist) zusammengerechnet; der Service kennt nur Stück.
        $kisten  = $this->request->getPost('kisten');
        $einzeln = $this->request->getPost('einzeln');
        $kisten  = is_array($kisten) ? $kisten : [];
        $einzeln = is_array($einzeln) ? $einzeln : [];
        $ids     = array_values(array_filter(array_unique(array_merge(array_keys($kisten), array_keys($einzeln))), static fn ($id): bool => ctype_digit((string) $id)));
        $gebinde = $this->gebinde($ids);

        foreach ($ids as $artikelId) {
            $stueck = $this->stueck(trim($this->text($kisten[$artikelId] ?? '')), trim($this->text($einzeln[$artikelId] ?? '')), $gebinde[(int) $artikelId] ?? null);

            if ($stueck === false) {
                $fehler["ist.{$artikelId}"] = AuszaehlungService::MELDUNG_IST;
            } else {
                $ist[(int) $artikelId] = $stueck;
                unset($fehler["ist.{$artikelId}"]);
            }
        }

        if ($fehler !== []) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gespeichert.')->with('fehler', $fehler);
        }

        $dienst    = service('auszaehlungen');
        $argumente = [(int) $bereich['id'], (int) service('anmeldung')->person()['id'], $stichtag, $ist, $this->text($this->request->getPost('bemerkung'))];

        try {
            if ($aktion === 'entwurf') {
                $dienst->speichereEntwurf(...$argumente);

                return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/auszaehlung'))->with('success', 'Entwurf gespeichert.');
            }

            $id = $dienst->schliesseAb(...$argumente);
        } catch (AuszaehlungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage())->with('fehler', $e->fehler);
        }

        $weiter = redirect()->to($this->listeUrl($bereich))->with('success', 'Auszählung abgeschlossen.');

        // Review Focus 2: die Auszählung bleibt abgeschlossen, die Datei lässt sich in der Liste neu erzeugen.
        if ($dienst->dateiFehlt($id)) {
            $weiter->with('error', 'Die Excel-Datei konnte nicht erzeugt werden. Bitte „Datei neu erzeugen“ verwenden.');
        }

        return $weiter;
    }

    public function liste(string $bereichSchluessel): string
    {
        $bereich = $this->bereich($bereichSchluessel);

        return view('wart/auszaehlungen', [
            'bereich'       => $bereich,
            'auszaehlungen' => (new AuszaehlungModel())->liste((int) $bereich['id']),
            'darfErzeugen'  => Berechtigung::darf(service('anmeldung')->rollen(), Berechtigung::AUSZAEHLUNG_DURCHFUEHREN, (string) $bereich['schluessel']),
        ]);
    }

    /**
     * Pfad nur aus der DB, aufgelöst und geprüft unter `exporte/` (nie aus dem Request).
     */
    public function download(string $bereichSchluessel, string $id): DownloadResponse|RedirectResponse
    {
        $bereich     = $this->bereich($bereichSchluessel);
        $auszaehlung = $this->abgeschlossene($bereich, $id);
        $datei       = $auszaehlung['datei_pfad'] === null ? null : service('auszaehlungExport')->datei((string) $auszaehlung['datei_pfad']);

        if ($datei === null) {
            return redirect()->to($this->listeUrl($bereich))->with('error', 'Die Datei ist nicht vorhanden. Bitte „Datei neu erzeugen“ verwenden.');
        }

        return $this->response->download($datei, null, true)->setFileName(basename($datei));
    }

    public function neuErzeugen(string $bereichSchluessel, string $id): RedirectResponse
    {
        $bereich     = $this->bereich($bereichSchluessel);
        $auszaehlung = $this->abgeschlossene($bereich, $id);
        $liste       = redirect()->to($this->listeUrl($bereich));

        try {
            service('auszaehlungen')->dateiNeuErzeugen((int) $auszaehlung['id'], (int) service('anmeldung')->person()['id']);
        } catch (Throwable $e) {
            log_message('error', 'Excel-Datei der Auszählung {id} nicht erzeugt: {meldung}', ['id' => $auszaehlung['id'], 'meldung' => $e->getMessage()]);

            return $liste->with('error', 'Die Excel-Datei konnte nicht erzeugt werden.');
        }

        return $liste->with('success', 'Datei neu erzeugt.');
    }

    /**
     * Abgeschlossene Auszählung dieses Bereichs, sonst 404 (Entwurf, anderer Bereich, unbekannt).
     *
     * @param array<string, mixed> $bereich
     *
     * @return array<string, mixed>
     */
    private function abgeschlossene(array $bereich, string $id): array
    {
        $auszaehlung = (new AuszaehlungModel())->where('id', (int) $id)->where('bereich_id', (int) $bereich['id'])
            ->where('status', 'abgeschlossen')->first();

        if ($auszaehlung === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $auszaehlung;
    }

    /**
     * @param array<string, mixed> $bereich
     */
    private function listeUrl(array $bereich): string
    {
        return site_url('wart/' . $bereich['schluessel'] . '/auszaehlungen');
    }

    /**
     * `datetime-local` (JJJJ-MM-TTThh:mm, Sekunden werden verworfen) oder null bei ungültigem Format.
     */
    private function stichtag(string $wert): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', trim($wert), $m) !== 1) {
            return null;
        }

        $zeit = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m[1] . ' ' . $m[2], new DateTimeZone('Europe/Berlin'));
        $fehler = DateTimeImmutable::getLastErrors();

        if ($zeit === false || ($fehler !== false && ($fehler['warning_count'] > 0 || $fehler['error_count'] > 0))) {
            return null;
        }

        return $zeit;
    }

    private function aktuelleMinute(DateTimeImmutable $jetzt): DateTimeImmutable
    {
        return $jetzt->setTime((int) $jetzt->format('H'), (int) $jetzt->format('i'), 0);
    }
}
