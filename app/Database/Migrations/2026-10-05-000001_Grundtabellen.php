<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Grundtabellen extends Migration
{
    private const OPTIONEN = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $this->db->query('CREATE TABLE bereiche (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            schluessel VARCHAR(30) NOT NULL,
            name VARCHAR(100) NOT NULL,
            verwalter_rolle VARCHAR(30) NOT NULL,
            aktiv TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bereiche_schluessel (schluessel)
        ) ' . self::OPTIONEN);

        $this->db->query("CREATE TABLE personen (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            vorname VARCHAR(100) NOT NULL DEFAULT '',
            nachname VARCHAR(100) NOT NULL DEFAULT '',
            anzeigename VARCHAR(200) NOT NULL,
            gruppe ENUM('aktiv','ah','sonstige') NOT NULL DEFAULT 'sonstige',
            typ ENUM('mitglied','sammelkonto') NOT NULL DEFAULT 'mitglied',
            benutzername VARCHAR(100) NULL,
            passwort_hash VARCHAR(255) NULL,
            passwort_wechsel_erzwingen TINYINT(1) NOT NULL DEFAULT 0,
            login_fehlversuche INT UNSIGNED NOT NULL DEFAULT 0,
            login_gesperrt_bis DATETIME NULL,
            pin_hash VARCHAR(255) NULL,
            pin_fehlversuche INT UNSIGNED NOT NULL DEFAULT 0,
            pin_gesperrt_bis DATETIME NULL,
            archiviert_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_personen_benutzername (benutzername)
        ) " . self::OPTIONEN);

        $this->db->query("CREATE TABLE person_rollen (
            person_id INT UNSIGNED NOT NULL,
            rolle ENUM('getraenkewart','kioskwart','kassenwart','admin') NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (person_id, rolle),
            CONSTRAINT fk_person_rollen_person FOREIGN KEY (person_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) " . self::OPTIONEN);

        $this->db->query('CREATE TABLE anmelde_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id INT UNSIGNED NOT NULL,
            selector CHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            gueltig_bis DATETIME NOT NULL,
            zuletzt_genutzt_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_anmelde_tokens_selector (selector),
            KEY idx_anmelde_tokens_person (person_id),
            CONSTRAINT fk_anmelde_tokens_person FOREIGN KEY (person_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) ' . self::OPTIONEN);

        $this->db->query("CREATE TABLE einstellungen (
            schluessel VARCHAR(50) NOT NULL,
            wert VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (schluessel)
        ) " . self::OPTIONEN);

        $this->db->query('CREATE TABLE protokoll (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id INT UNSIGNED NULL,
            aktion VARCHAR(100) NOT NULL,
            tabelle VARCHAR(50) NOT NULL,
            datensatz_id INT UNSIGNED NULL,
            alt JSON NULL,
            neu JSON NULL,
            erfolgt_at DATETIME NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_protokoll_erfolgt (erfolgt_at),
            KEY idx_protokoll_person (person_id),
            CONSTRAINT fk_protokoll_person FOREIGN KEY (person_id) REFERENCES personen (id) ON DELETE RESTRICT
        ) ' . self::OPTIONEN);
    }

    public function down(): void
    {
        foreach (['protokoll', 'einstellungen', 'anmelde_tokens', 'person_rollen', 'personen', 'bereiche'] as $tabelle) {
            $this->db->query('DROP TABLE IF EXISTS ' . $tabelle);
        }
    }
}
