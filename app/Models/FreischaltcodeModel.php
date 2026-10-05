<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use DateInterval;
use DateTimeImmutable;

/**
 * Einmal-Freischaltcodes für Tablets: 8 Ziffern, 15 Minuten gültig, in der DB nur als sha256.
 */
class FreischaltcodeModel extends Model
{
    use Transaktion;

    public const GUELTIG_MINUTEN = 15;

    private const DATUMSFORMAT = 'Y-m-d H:i:s';

    protected $table         = 'freischaltcodes';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['code_hash', 'gueltig_bis', 'erstellt_von_id', 'eingeloest_at'];

    /**
     * Legt einen Code an und liefert ihn (Klartext, genau einmal) – immer 8 Zeichen, führende Nullen erlaubt.
     */
    public function erzeuge(int $adminId, DateTimeImmutable $jetzt): string
    {
        $code = str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);

        $this->insert([
            'code_hash'       => hash('sha256', $code),
            'gueltig_bis'     => $jetzt->add(new DateInterval('PT' . self::GUELTIG_MINUTEN . 'M'))->format(self::DATUMSFORMAT),
            'erstellt_von_id' => $adminId,
        ]);

        return $code;
    }

    /**
     * Löst den Code atomar ein: nur ein UPDATE mit Bedingung (nicht eingelöst, nicht abgelaufen);
     * genau ein Aufrufer gewinnt. Läuft in der Transaktion des Aufrufers.
     */
    public function loeseEin(string $code, DateTimeImmutable $jetzt): bool
    {
        $zeit = $jetzt->format(self::DATUMSFORMAT);

        $this->where('code_hash', hash('sha256', $code))
            ->where('eingeloest_at IS NULL', null, false)
            ->where('gueltig_bis >=', $zeit)
            ->set(['eingeloest_at' => $zeit])
            ->update();

        return $this->db->affectedRows() === 1;
    }
}
