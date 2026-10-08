<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Bestand extends Migration
{
    private const OPTIONEN = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        // Begründung einer Korrekturbuchung (Entscheidung 4)
        $this->db->query('ALTER TABLE buchungen ADD COLUMN bemerkung VARCHAR(255) NULL AFTER storno_grund');

        $this->db->query("CREATE TABLE bestandsbewegungen (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            artikel_id INT UNSIGNED NOT NULL,
            art ENUM('lieferung','schwund','korrektur') NOT NULL,
            menge INT NOT NULL,
            einkaufspreis_cent INT NULL,
            bemerkung VARCHAR(255) NULL,
            person_id INT UNSIGNED NOT NULL,
            erfolgt_at DATETIME NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_bewegungen_artikel_zeit (artikel_id, erfolgt_at),
            KEY idx_bewegungen_person (person_id),
            CONSTRAINT fk_bewegungen_artikel FOREIGN KEY (artikel_id) REFERENCES artikel (id) ON DELETE RESTRICT,
            CONSTRAINT fk_bewegungen_person FOREIGN KEY (person_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) " . self::OPTIONEN);

        $this->db->query("CREATE TABLE auszaehlungen (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bereich_id INT UNSIGNED NOT NULL,
            art ENUM('start','regulaer') NOT NULL,
            stichtag DATETIME NOT NULL,
            zeitraum_von DATETIME NOT NULL,
            status ENUM('entwurf','abgeschlossen') NOT NULL,
            erstellt_von_id INT UNSIGNED NOT NULL,
            abgeschlossen_at DATETIME NULL,
            datei_pfad VARCHAR(255) NULL,
            bemerkung TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_auszaehlungen_bereich_status (bereich_id, status, stichtag),
            KEY idx_auszaehlungen_erstellt_von (erstellt_von_id),
            CONSTRAINT fk_auszaehlungen_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche (id) ON DELETE RESTRICT,
            CONSTRAINT fk_auszaehlungen_erstellt_von FOREIGN KEY (erstellt_von_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) " . self::OPTIONEN);

        // ist ist im Entwurf NULL (noch nicht gezählt); start = Artikel hatte keine frühere Position (Entscheidung 3)
        $this->db->query('CREATE TABLE auszaehlung_positionen (
            auszaehlung_id INT UNSIGNED NOT NULL,
            artikel_id INT UNSIGNED NOT NULL,
            anfangsbestand INT NOT NULL,
            lieferungen INT NOT NULL,
            schwund_erfasst INT NOT NULL,
            korrekturen INT NOT NULL,
            verkauft INT NOT NULL,
            soll INT NOT NULL,
            ist INT NULL,
            differenz INT NOT NULL,
            start TINYINT(1) NOT NULL DEFAULT 0,
            preis_cent INT NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (auszaehlung_id, artikel_id),
            KEY idx_positionen_artikel (artikel_id),
            CONSTRAINT fk_positionen_auszaehlung FOREIGN KEY (auszaehlung_id) REFERENCES auszaehlungen (id) ON DELETE RESTRICT,
            CONSTRAINT fk_positionen_artikel FOREIGN KEY (artikel_id) REFERENCES artikel (id) ON DELETE RESTRICT
        ) ' . self::OPTIONEN);
    }

    public function down(): void
    {
        foreach (['auszaehlung_positionen', 'auszaehlungen', 'bestandsbewegungen'] as $tabelle) {
            $this->db->query("DROP TABLE IF EXISTS {$tabelle}");
        }

        $this->db->query('ALTER TABLE buchungen DROP COLUMN bemerkung');
    }
}
