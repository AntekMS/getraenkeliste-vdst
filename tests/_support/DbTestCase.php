<?php

namespace Tests\Support;

use App\Models\ArtikelModel;
use App\Models\KategorieModel;
use App\Models\PersonModel;
use App\Models\PersonRolleModel;
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

    protected function alsAngemeldet(int $personId): static
    {
        return $this->withSession(['person_id' => $personId, 'csrf_test_name' => 'test-token']);
    }

    /**
     * @return array<string, string>
     */
    protected function csrf(): array
    {
        return ['csrf_test_name' => 'test-token'];
    }
}
