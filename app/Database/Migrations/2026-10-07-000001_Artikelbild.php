<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Artikelbilder (Task 11): Dateiname unter writable/artikelbilder/ (nur aus der DB, nie aus dem Request) und
 * Version für den Cache-Buster der Bild-URL.
 */
class Artikelbild extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE artikel
            ADD COLUMN bild_datei VARCHAR(64) NULL AFTER bestand_fuehren,
            ADD COLUMN bild_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER bild_datei');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE artikel DROP COLUMN bild_version, DROP COLUMN bild_datei');
    }
}
