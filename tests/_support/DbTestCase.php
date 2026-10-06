<?php

namespace Tests\Support;

use App\Models\ArtikelModel;
use App\Models\KategorieModel;
use App\Models\PersonModel;
use App\Models\PersonRolleModel;
use App\Libraries\Anmeldung;
use App\Libraries\Uhr;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Basis für Tests mit Datenbank: Migrationen laufen vor jedem Test frisch
 * gegen getraenkeliste_test (inkl. Startdaten). Hilfsmethoden für Personen,
 * Rollen, Artikel und angemeldete Requests.
 */
abstract class DbTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private static int $personZaehler = 0;

    /**
     * Standard: Mitglied, Passwort geheim123, PIN 1234, kein Pflichtwechsel.
     * Schlüssel `passwort` und `pin` (null = keine PIN) werden gehasht (Kosten 4 für Testtempo).
     *
     * @param array<string, mixed> $werte
     */
    protected function personAnlegen(array $werte = []): int
    {
        $n = ++self::$personZaehler;

        $passwort = $werte['passwort'] ?? 'geheim123';
        $pin      = array_key_exists('pin', $werte) ? $werte['pin'] : '1234';
        unset($werte['passwort'], $werte['pin']);

        $zeile = array_merge([
            'vorname'                    => 'Test',
            'nachname'                   => 'Person' . $n,
            'anzeigename'                => 'Test Person' . $n,
            'gruppe'                     => 'aktiv',
            'typ'                        => 'mitglied',
            'benutzername'               => 'test' . $n,
            'passwort_hash'              => password_hash($passwort, PASSWORD_DEFAULT, ['cost' => 4]),
            'pin_hash'                   => $pin === null ? null : password_hash($pin, PASSWORD_DEFAULT, ['cost' => 4]),
            'passwort_wechsel_erzwingen' => 0,
        ], $werte);

        return (int) (new PersonModel())->insert($zeile, true);
    }

    protected function rolleGeben(int $personId, string $rolle): void
    {
        (new PersonRolleModel())->insert(['person_id' => $personId, 'rolle' => $rolle]);
    }

    /**
     * Legt bei Bedarf die Kategorie „Bier“ im Bereich getraenke an.
     * Standard: „Helles“, 0,5 l, 150 Cent.
     *
     * @param array<string, mixed> $werte
     */
    protected function artikelAnlegen(array $werte = []): int
    {
        if (! isset($werte['kategorie_id'])) {
            $kategorie = db_connect()->table('kategorien')->where('name', 'Bier')->get()->getRowArray();

            if ($kategorie === null) {
                $bereichId = (int) db_connect()->table('bereiche')->where('schluessel', 'getraenke')->get()->getRow()->id;
                $kategorie = ['id' => (new KategorieModel())->insert(['bereich_id' => $bereichId, 'name' => 'Bier'], true)];
            }

            $werte['kategorie_id'] = (int) $kategorie['id'];
        }

        return (int) (new ArtikelModel())->insert(array_merge([
            'name' => 'Helles', 'einheit' => '0,5 l', 'preis_cent' => 150,
        ], $werte), true);
    }

    protected function bereichId(string $schluessel): int
    {
        return (int) db_connect()->table('bereiche')->where('schluessel', $schluessel)->get()->getRow()->id;
    }

    /**
     * Legt eine Auszählung direkt in der DB an (Standard: abgeschlossen, Bereich getraenke, art regulaer).
     *
     * @param array<string, mixed> $werte
     */
    protected function auszaehlungAnlegen(string $stichtag, string $status = 'abgeschlossen', string $bereich = 'getraenke', array $werte = []): int
    {
        $zeile = array_merge([
            'bereich_id'       => $this->bereichId($bereich),
            'art'              => 'regulaer',
            'stichtag'         => $stichtag,
            'zeitraum_von'     => '2026-01-01 00:00:00',
            'status'           => $status,
            'erstellt_von_id'  => $werte['erstellt_von_id'] ?? $this->personAnlegen(),
            'abgeschlossen_at' => $status === 'abgeschlossen' ? $stichtag : null,
        ], $werte);

        db_connect()->table('auszaehlungen')->insert($zeile);

        return (int) db_connect()->insertID();
    }

    /**
     * Fixiert „jetzt“ für `service('uhr')`, z. B. '2026-10-05 12:00:00' (Europe/Berlin).
     */
    protected function uhrStellen(string $zeit): void
    {
        Services::injectMock('uhr', new Uhr($zeit));
    }

    /**
     * Shared Services (Uhr-Mock, Einstellungs-Cache) dürfen nicht in den nächsten Test lecken.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->resetServices();
    }

    /**
     * Führt `$arbeit` aus, während eine zweite Verbindung die Bereichszeile per `SELECT … FOR UPDATE` hält und die
     * Verbindung des Dienstes `innodb_lock_wait_timeout = 1` hat (S2-R2: Lock-Wait-Timeout wie beim laufenden Abschluss).
     */
    protected function beiGesperrtemBereich(int $bereichId, callable $arbeit): void
    {
        $zweite = \Config\Database::connect('tests', false);
        $zweite->transBegin();
        $zweite->query('SELECT id FROM bereiche WHERE id = ? FOR UPDATE', [$bereichId]);
        db_connect()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $arbeit();
        } finally {
            db_connect()->query('SET SESSION innodb_lock_wait_timeout = 50');
            $zweite->transRollback();
            $zweite->close();
        }
    }

    protected function alsAngemeldet(int $personId): static
    {
        return $this->withSession([...$this->angemeldeteSitzung($personId), 'csrf_test_name' => 'test-token']);
    }

    /**
     * Session-Daten einer angemeldeten Person (inkl. Fingerabdruck des aktuellen Passwort-Hashes).
     *
     * @return array<string, mixed>
     */
    protected function angemeldeteSitzung(int $personId): array
    {
        $hash = (new PersonModel())->find($personId)['passwort_hash'] ?? null;

        return ['person_id' => $personId, Anmeldung::SITZUNG_FINGERABDRUCK => Anmeldung::fingerabdruck($hash)];
    }

    /**
     * @return array<string, string>
     */
    protected function csrf(): array
    {
        return ['csrf_test_name' => 'test-token'];
    }
}
