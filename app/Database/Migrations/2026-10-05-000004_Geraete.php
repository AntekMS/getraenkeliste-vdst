<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Geraete extends Migration
{
    private const OPTIONEN = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $this->db->query('CREATE TABLE geraete (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            zuletzt_gesehen_at DATETIME NULL,
            gesperrt_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_geraete_token (token_hash)
        ) ' . self::OPTIONEN);

        $this->db->query('CREATE TABLE freischaltcodes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_hash CHAR(64) NOT NULL,
            gueltig_bis DATETIME NOT NULL,
            erstellt_von_id INT UNSIGNED NOT NULL,
            eingeloest_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_freischaltcodes_code (code_hash),
            KEY idx_freischaltcodes_erstellt_von (erstellt_von_id),
            CONSTRAINT fk_freischaltcodes_person FOREIGN KEY (erstellt_von_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) ' . self::OPTIONEN);

        $this->db->query('ALTER TABLE buchungen ADD CONSTRAINT fk_buchungen_geraet FOREIGN KEY (geraet_id) REFERENCES geraete (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE buchungen DROP FOREIGN KEY fk_buchungen_geraet');
        $this->db->query('DROP TABLE IF EXISTS freischaltcodes');
        $this->db->query('DROP TABLE IF EXISTS geraete');
    }
}
