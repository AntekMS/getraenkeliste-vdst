<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Stammdaten extends Migration
{
    private const OPTIONEN = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $this->db->query('CREATE TABLE kategorien (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bereich_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            sortierung INT NOT NULL DEFAULT 0,
            archiviert_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_kategorien_bereich (bereich_id),
            CONSTRAINT fk_kategorien_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche (id) ON DELETE RESTRICT
        ) ' . self::OPTIONEN);

        $this->db->query("CREATE TABLE artikel (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kategorie_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            preis_cent INT UNSIGNED NOT NULL,
            einheit VARCHAR(50) NOT NULL DEFAULT '',
            gebinde_groesse INT UNSIGNED NULL,
            mindestbestand INT NOT NULL DEFAULT 0,
            bestand_fuehren TINYINT(1) NOT NULL DEFAULT 1,
            sortierung INT NOT NULL DEFAULT 0,
            archiviert_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_artikel_kategorie (kategorie_id),
            CONSTRAINT fk_artikel_kategorie FOREIGN KEY (kategorie_id) REFERENCES kategorien (id) ON DELETE RESTRICT
        ) " . self::OPTIONEN);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS artikel');
        $this->db->query('DROP TABLE IF EXISTS kategorien');
    }
}
