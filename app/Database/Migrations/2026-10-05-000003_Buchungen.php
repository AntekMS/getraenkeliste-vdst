<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Buchungen extends Migration
{
    private const OPTIONEN = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        // geraet_id: FK folgt in 000004, sobald `geraete` existiert.
        $this->db->query("CREATE TABLE buchungen (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            vorgang_id CHAR(36) NOT NULL,
            konto_id INT UNSIGNED NOT NULL,
            artikel_id INT UNSIGNED NOT NULL,
            menge INT NOT NULL,
            einzelpreis_cent INT UNSIGNED NOT NULL,
            quelle ENUM('tablet','web','korrektur') NOT NULL,
            gebucht_von_id INT UNSIGNED NULL,
            geraet_id INT UNSIGNED NULL,
            gebucht_at DATETIME NOT NULL,
            storniert_at DATETIME NULL,
            storniert_von_id INT UNSIGNED NULL,
            storno_grund VARCHAR(255) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_buchungen_vorgang_artikel (vorgang_id, artikel_id),
            KEY idx_buchungen_konto_zeit (konto_id, gebucht_at),
            KEY idx_buchungen_artikel_zeit (artikel_id, gebucht_at),
            KEY idx_buchungen_gebucht_von (gebucht_von_id),
            KEY idx_buchungen_geraet (geraet_id),
            KEY idx_buchungen_storniert_von (storniert_von_id),
            CONSTRAINT fk_buchungen_konto FOREIGN KEY (konto_id) REFERENCES personen (id) ON DELETE RESTRICT,
            CONSTRAINT fk_buchungen_artikel FOREIGN KEY (artikel_id) REFERENCES artikel (id) ON DELETE RESTRICT,
            CONSTRAINT fk_buchungen_gebucht_von FOREIGN KEY (gebucht_von_id) REFERENCES personen (id) ON DELETE RESTRICT,
            CONSTRAINT fk_buchungen_storniert_von FOREIGN KEY (storniert_von_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) " . self::OPTIONEN);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS buchungen');
    }
}
