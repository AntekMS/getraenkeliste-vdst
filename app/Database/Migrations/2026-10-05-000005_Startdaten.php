<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Startdaten. Werte sind bewusst hier wiederholt (keine Kopplung an App-Klassen).
 */
class Startdaten extends Migration
{
    public function up(): void
    {
        $jetzt = date('Y-m-d H:i:s');

        $this->db->table('bereiche')->insertBatch([
            ['schluessel' => 'getraenke', 'name' => 'Getränke', 'verwalter_rolle' => 'getraenkewart', 'aktiv' => 1, 'created_at' => $jetzt, 'updated_at' => $jetzt],
            ['schluessel' => 'kiosk', 'name' => 'Fuxenkiosk', 'verwalter_rolle' => 'kioskwart', 'aktiv' => 0, 'created_at' => $jetzt, 'updated_at' => $jetzt],
        ]);

        foreach (['Couleur', 'Bund'] as $name) {
            $this->db->table('personen')->insert([
                'vorname' => '', 'nachname' => '', 'anzeigename' => $name,
                'typ' => 'sammelkonto', 'gruppe' => 'sonstige',
                'created_at' => $jetzt, 'updated_at' => $jetzt,
            ]);
        }

        $einstellungen = [
            'storno_frist_min'  => '10',
            'tablet_timeout_s'  => '30',
            'vereinsname'       => 'Verein deutscher Studenten zu Erlangen',
            'erinnerung_tage'   => '31',
            'inbetriebnahme_at' => $jetzt,
        ];

        foreach ($einstellungen as $schluessel => $wert) {
            $this->db->table('einstellungen')->insert([
                'schluessel' => $schluessel, 'wert' => $wert, 'created_at' => $jetzt, 'updated_at' => $jetzt,
            ]);
        }
    }

    public function down(): void
    {
        // Nichts zu tun: die Down-Schritte von 000001-000004 entfernen die Tabellen samt Startdaten.
    }
}
