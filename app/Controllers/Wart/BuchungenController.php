<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Controllers\Concerns\WartEingaben;
use App\Libraries\BuchungAbgelehnt;
use App\Models\BuchungModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Buchungsverwaltung des Warts: Liste des laufenden Zeitraums, Storno mit Grund und Korrekturbuchung.
 * Der Bereich kommt aus der Route (Recht über den Filter); Fachregeln (Einfrieren, Sperre) prüft BuchungService.
 */
class BuchungenController extends BaseController
{
    use WartEingaben;

    private const JE_SEITE = 50;

    public function index(string $bereichSchluessel): string
    {
        $bereich    = $this->bereich($bereichSchluessel);
        $bereichId  = (int) $bereich['id'];
        $zeitraeume = service('zeitraeume');
        $filter     = [
            'person'  => $this->ganzzahl($this->request->getGet('person')) ?: null,
            'artikel' => $this->ganzzahl($this->request->getGet('artikel')) ?: null,
            'tag'     => $this->datum($this->request->getGet('tag')),
        ];
        $ab       = $zeitraeume->beginn($bereichId);
        $inklusiv = $zeitraeume->beginnInklusiv($bereichId);

        $model   = new BuchungModel();
        $gesamt  = (new BuchungModel())->imZeitraum($bereichId, $ab, $inklusiv, $filter)->countAllResults();
        $letzte  = max(1, (int) ceil($gesamt / self::JE_SEITE));
        $seite   = min($letzte, max(1, $this->ganzzahl($this->request->getGet('page'))));
        $zeilen  = $model->imZeitraum($bereichId, $ab, $inklusiv, $filter)->paginate(self::JE_SEITE, 'default', $seite);
        $pager   = $model->pager;
        $pager->only(['person', 'artikel', 'tag']);
        $zeit    = static fn (string $wert): DateTimeImmutable => new DateTimeImmutable($wert, new DateTimeZone('Europe/Berlin'));

        $eingefroren = [];

        foreach ($zeilen as $z) {
            $eingefroren[(int) $z['id']] = $zeitraeume->istEingefroren($zeit($z['gebucht_at']), $bereichId);
        }

        return view('wart/buchungen', [
            'bereich'     => $bereich,
            'zeilen'      => $zeilen,
            'eingefroren' => $eingefroren,
            'pager'       => $pager,
            'filter'      => $filter,
            'konten'      => $this->konten(),
            'artikel'     => $this->artikelGruppen($bereichId),
        ]);
    }

    public function storno(string $bereichSchluessel, string $id): RedirectResponse
    {
        $bereich = $this->bereich($bereichSchluessel);
        $zurueck = redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/buchungen'));
        $buchung = (int) $id;


        try {
            service('buchungen')->storniereAlsWart($buchung, $this->personId(), $this->text($this->request->getPost('grund')), (int) $bereich['id']);
        } catch (BuchungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage());
        }

        return $zurueck->with('success', 'Buchung storniert.');
    }

    public function korrekturForm(string $bereichSchluessel): string
    {
        $bereich = $this->bereich($bereichSchluessel);

        return view('wart/korrektur', [
            'bereich' => $bereich,
            'konten'  => $this->konten(),
            'artikel' => $this->artikelGruppen((int) $bereich['id']),
        ]);
    }

    public function korrektur(string $bereichSchluessel): RedirectResponse
    {
        $bereich = $this->bereich($bereichSchluessel);
        $zurueck = redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/korrektur'))->withInput();
        $menge   = trim($this->text($this->request->getPost('menge')));
        $fehler  = [];

        if (preg_match('/^-?\d{1,4}$/', $menge) !== 1) {
            $fehler['menge'] = 'Bitte eine ganze Zahl angeben, z. B. -2.';
        }

        if ($fehler !== []) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gebucht.')->with('fehler', $fehler);
        }

        try {
            $ergebnis = service('buchungen')->bucheKorrektur(
                $this->ganzzahl($this->request->getPost('konto_id')),
                $this->ganzzahl($this->request->getPost('artikel_id')),
                (int) $menge,
                $this->text($this->request->getPost('bemerkung')),
                $this->personId(),
                (int) $bereich['id'],
                $this->text($this->request->getPost('bestandswirksam')) === '1',
            );
        } catch (BuchungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/buchungen'))
            ->with('success', 'Korrektur gebucht: ' . $ergebnis['zusammenfassung']);
    }

    /**
     * @return list<array<string, mixed>> aktive Mitglieder und Sammelkonten (Couleur, Bund)
     */
    private function konten(): array
    {
        return (new PersonModel())->where('archiviert_at', null)->orderBy('typ')->orderBy('nachname')->orderBy('vorname')->findAll();
    }

    /**
     * @return list<array{kategorie: string, artikel: list<array<string, mixed>>}> Artikel des Bereichs inkl. archivierter
     */
    private function artikelGruppen(int $bereichId): array
    {
        $zeilen = db_connect()->table('artikel a')
            ->select('a.id, a.name, a.einheit, a.archiviert_at, k.id AS kategorie_id, k.name AS kategorie_name')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')
            ->get()->getResultArray();
        $gruppen = [];

        foreach ($zeilen as $z) {
            $gruppen[$z['kategorie_id']] ??= ['kategorie' => (string) $z['kategorie_name'], 'artikel' => []];
            $gruppen[$z['kategorie_id']]['artikel'][] = $z;
        }

        return array_values($gruppen);
    }

    /** Nur Ziffern (max. 9), sonst 0: schützt vor Array-Parametern und Überlauf. */
    private function ganzzahl(mixed $wert): int
    {
        return is_string($wert) && ctype_digit($wert) && strlen($wert) <= 9 ? (int) $wert : 0;
    }

    private function datum(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = trim($wert);
        $tag  = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $tag !== false && $tag->format('Y-m-d') === $wert ? $wert : null;
    }

    private function personId(): int
    {
        return (int) service('anmeldung')->person()['id'];
    }
}
