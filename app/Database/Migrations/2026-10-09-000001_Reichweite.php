<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Einstellung `reichweite_tage` (Bestellreichweite für den Bestellvorschlag), Default 30.
 */
class Reichweite extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "INSERT IGNORE INTO einstellungen (schluessel, wert, created_at, updated_at) VALUES ('reichweite_tage', '30', NOW(), NOW())",
        );
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM einstellungen WHERE schluessel = 'reichweite_tage'");
    }
}
