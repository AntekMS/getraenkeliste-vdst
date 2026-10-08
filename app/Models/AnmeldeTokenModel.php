<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use DateInterval;
use DateTimeImmutable;

/**
 * Remember-Token „angemeldet bleiben“ im Selector/Validator-Verfahren.
 * Cookiewert: `<selector>:<validator>` (hex); in der DB nur der Selector und sha256(validator).
 */
class AnmeldeTokenModel extends Model
{
    public const GUELTIG_TAGE = 90;

    // Selector-Spalte ist CHAR(24): 12 Byte hex
    private const SELECTOR_BYTES  = 12;
    private const VALIDATOR_BYTES = 32;
    private const DATUMSFORMAT    = 'Y-m-d H:i:s';

    protected $table         = 'anmelde_tokens';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['person_id', 'selector', 'token_hash', 'gueltig_bis', 'zuletzt_genutzt_at'];

    /**
     * Legt ein neues Token an und liefert den Cookiewert.
     */
    public function erzeuge(int $personId, DateTimeImmutable $jetzt): string
    {
        $selector  = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));

        $this->insert([
            'person_id'          => $personId,
            'selector'           => $selector,
            'token_hash'         => hash('sha256', $validator),
            'gueltig_bis'        => $jetzt->add(new DateInterval('P' . self::GUELTIG_TAGE . 'D'))->format(self::DATUMSFORMAT),
            'zuletzt_genutzt_at' => $jetzt->format(self::DATUMSFORMAT),
        ]);

        return $selector . ':' . $validator;
    }

    /**
     * Verbraucht das Token (einmalig nutzbar) und stellt ein neues aus. Unbekannt, abgelaufen
     * oder fehlerhaft: null (abgelaufene werden gelöscht). Passender Selector mit falschem
     * Validator gilt als Diebstahl: alle Tokens der Person werden gelöscht.
     *
     * @return ?array{person_id: int, cookie: string}
     */
    public function rotiere(string $cookie, DateTimeImmutable $jetzt): ?array
    {
        $teile = $this->teile($cookie);

        if ($teile === null) {
            return null;
        }

        [$selector, $validator] = $teile;
        $zeile                  = $this->where('selector', $selector)->first();

        if ($zeile === null) {
            return null;
        }

        $personId = (int) $zeile['person_id'];

        if (! hash_equals((string) $zeile['token_hash'], hash('sha256', $validator))) {
            $this->loescheFuerPerson($personId);

            return null;
        }

        $this->delete((int) $zeile['id']);

        if ($zeile['gueltig_bis'] < $jetzt->format(self::DATUMSFORMAT)) {
            return null;
        }

        return ['person_id' => $personId, 'cookie' => $this->erzeuge($personId, $jetzt)];
    }

    /**
     * Löscht nur das Token dieses Cookies (Abmelden); Fremdes/Ungültiges bleibt wirkungslos.
     */
    public function loescheCookie(string $cookie): void
    {
        $teile = $this->teile($cookie);

        if ($teile !== null) {
            $this->where('selector', $teile[0])->delete();
        }
    }

    /**
     * Löscht alle Remember-Tokens der Person (Passwortwechsel, Archivierung, Passwort-Reset).
     */
    public function loescheFuerPerson(int $personId): void
    {
        $this->where('person_id', $personId)->delete();
    }

    /**
     * @return ?array{0: string, 1: string}
     */
    private function teile(string $cookie): ?array
    {
        if (preg_match('/^([0-9a-f]{' . self::SELECTOR_BYTES * 2 . '}):([0-9a-f]{' . self::VALIDATOR_BYTES * 2 . '})$/', $cookie, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
