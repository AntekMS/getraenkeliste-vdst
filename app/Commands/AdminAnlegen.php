<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Anmelderegeln;
use App\Models\PersonModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AdminAnlegen extends BaseCommand
{
    protected $group       = 'Getränkeliste';
    protected $name        = 'admin:anlegen';
    protected $description = 'Legt einen Admin an (Vorname, Nachname, Benutzername, Passwort).';

    public function run(array $params)
    {
        $vorname  = trim((string) CLI::prompt('Vorname'));
        $nachname = trim((string) CLI::prompt('Nachname'));

        $benutzername = Anmelderegeln::benutzernameNormalisieren((string) CLI::prompt('Benutzername'));

        if ($benutzername === null) {
            CLI::error('Ungültiger Benutzername (3 bis 40 Zeichen: a-z, 0-9, Punkt, Unterstrich, Bindestrich).');

            return EXIT_ERROR;
        }

        $passwort = (string) CLI::prompt('Passwort');
        $fehler   = Anmelderegeln::passwortFehler($passwort);

        if ($fehler !== null) {
            CLI::error($fehler);

            return EXIT_ERROR;
        }

        if ($passwort !== (string) CLI::prompt('Passwort wiederholen')) {
            CLI::error('Die Passwörter stimmen nicht überein.');

            return EXIT_ERROR;
        }

        $model = new PersonModel();

        if ($model->where('benutzername', $benutzername)->first() !== null) {
            CLI::error("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");

            return EXIT_ERROR;
        }

        $model->legeAdminAn($vorname, $nachname, $benutzername, $passwort);
        CLI::write("Admin \"{$benutzername}\" angelegt. Die PIN wird beim ersten Login abgefragt.", 'green');

        return EXIT_SUCCESS;
    }
}
