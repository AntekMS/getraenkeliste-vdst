<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Korrekturbuchungen wirken standardmäßig nur auf den Betrag: `bestandswirksam = 0` nimmt eine Buchung aus
 * Bestand, Soll und Verkauf heraus. Bestehende Zeilen bleiben 1 (bisheriges Verhalten); web/tablet immer 1.
 */
class Bestandswirksam extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE buchungen ADD COLUMN bestandswirksam TINYINT(1) NOT NULL DEFAULT 1 AFTER bemerkung');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE buchungen DROP COLUMN bestandswirksam');
    }
}
