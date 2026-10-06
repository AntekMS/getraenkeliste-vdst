<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AdminProtokollTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen('2026-10-05 12:00:00');
        $this->admin = $this->personAnlegen(['anzeigename' => 'Chef Admin']);
        $this->rolleGeben($this->admin, 'admin');
    }

    /**
     * @param ?array<string, mixed> $alt
     * @param ?array<string, mixed> $neu
     */
    private function eintrag(?int $person, string $tabelle, string $zeit, ?array $alt = null, ?array $neu = null, string $aktion = 'geaendert'): void
    {
        db_connect()->table('protokoll')->insert([
            'person_id' => $person, 'aktion' => $aktion, 'tabelle' => $tabelle, 'datensatz_id' => 1,
            'alt' => $alt === null ? null : json_encode($alt), 'neu' => $neu === null ? null : json_encode($neu),
            'erfolgt_at' => $zeit,
        ]);
    }

    private function seite(string $pfad = 'admin/protokoll'): string
    {
        return $this->alsAngemeldet($this->admin)->get($pfad)->getBody();
    }

    public function test_zeigt_neueste_zuerst_mit_namen_und_alt_neu(): void
    {
        $this->eintrag($this->admin, 'artikel', '2026-10-05 10:00:00', ['preis_cent' => 150], ['preis_cent' => 200], 'preis_geaendert');
        $this->eintrag(null, 'einstellungen', '2026-10-05 11:00:00', null, ['wert' => '<b>x</b>']);

        $body = $this->seite();

        $this->assertLessThan(strpos($body, 'preis_geaendert'), strpos($body, '<td>einstellungen</td>'));
        $this->assertStringContainsString('Chef Admin', $body);
        $this->assertStringContainsString('System', $body);
        $this->assertStringContainsString('preis_cent', $body);
        $this->assertStringNotContainsString('<b>x</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $body);
    }

    public function test_filter_nach_tabelle_person_und_datum(): void
    {
        $this->eintrag($this->admin, 'artikel', '2026-10-01 10:00:00');
        $this->eintrag($this->admin, 'kategorien', '2026-10-03 10:00:00');
        $this->eintrag(null, 'einstellungen', '2026-10-05 10:00:00');

        $nachTabelle = $this->seite('admin/protokoll?tabelle=artikel');
        $this->assertStringContainsString('<td>artikel</td>', $nachTabelle);
        $this->assertStringNotContainsString('<td>kategorien</td>', $nachTabelle);

        $nachPerson = $this->seite('admin/protokoll?person=' . $this->admin);
        $this->assertStringNotContainsString('<td>einstellungen</td>', $nachPerson);
        $this->assertStringContainsString('<td>kategorien</td>', $nachPerson);

        $nachDatum = $this->seite('admin/protokoll?von=2026-10-02&bis=2026-10-03');
        $this->assertStringContainsString('<td>kategorien</td>', $nachDatum);
        $this->assertStringNotContainsString('<td>artikel</td>', $nachDatum);
        $this->assertStringNotContainsString('<td>einstellungen</td>', $nachDatum);
    }

    public function test_50_je_seite(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->eintrag($this->admin, 'artikel', '2026-10-05 10:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00');
        }

        $this->assertSame(50, substr_count($this->seite(), '<td>artikel</td>'));
        $this->assertSame(10, substr_count($this->seite('admin/protokoll?page=2'), '<td>artikel</td>'));
    }
}
