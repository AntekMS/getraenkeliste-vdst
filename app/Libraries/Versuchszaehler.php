<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atomarer Fehlversuch-Zähler für Login (`login_*`) und PIN (`pin_*`) in `personen`.
 *
 * Jeder Versuch wird VOR der Hash-Prüfung per einzelnem UPDATE beansprucht (kein Lesen und Zurückschreiben),
 * sodass parallele Requests die 5er-Sperre nicht umgehen. Semantik: der 5. Versuch setzt die Sperre auf
 * jetzt + 5 Minuten und den Zähler zurück auf 0; während der Sperre wird nichts beansprucht und nichts verändert.
 * Ein erfolgreicher Versuch setzt Zähler und Sperre zurück ({@see erfolg()}).
 */
final class Versuchszaehler
{
    private const PRAEFIXE     = ['login', 'pin'];
    private const DATUMSFORMAT = 'Y-m-d H:i:s';

    private readonly string $zaehler;
    private readonly string $sperre;

    public function __construct(string $praefix)
    {
        if (! in_array($praefix, self::PRAEFIXE, true)) {
            throw new InvalidArgumentException("Unbekannter Zähler: {$praefix}");
        }

        $this->zaehler = $praefix . '_fehlversuche';
        $this->sperre  = $praefix . '_gesperrt_bis';
    }

    /**
     * Beansprucht atomar einen Versuch. false = gesperrt (Zähler bleibt unverändert) oder Fehler (fail closed).
     */
    public function beanspruchen(int $personId, DateTimeImmutable $jetzt): bool
    {
        $db = db_connect();

        // MySQL wertet SET von links nach rechts aus: die Sperre wird aus dem ALTEN Zählerstand berechnet,
        // danach wird der Zähler hochgezählt bzw. beim sperrenden Versuch auf 0 gesetzt.
        $ok = $db->query(
            "UPDATE personen
             SET {$this->sperre} = IF({$this->zaehler} + 1 >= ?, ?, NULL),
                 {$this->zaehler} = IF({$this->zaehler} + 1 >= ?, 0, {$this->zaehler} + 1)
             WHERE id = ? AND ({$this->sperre} IS NULL OR {$this->sperre} <= ?)",
            [
                PinSperre::MAX_FEHLVERSUCHE,
                $jetzt->modify('+' . PinSperre::SPERRE_MINUTEN . ' minutes')->format(self::DATUMSFORMAT),
                PinSperre::MAX_FEHLVERSUCHE,
                $personId,
                $jetzt->format(self::DATUMSFORMAT),
            ],
        );

        return $ok !== false && $db->affectedRows() === 1;
    }

    /**
     * Ist die Person gerade gesperrt (z. B. weil der eben beanspruchte Versuch die Sperre ausgelöst hat)?
     */
    public function gesperrt(int $personId, DateTimeImmutable $jetzt): bool
    {
        $zeile = db_connect()->table('personen')->select($this->sperre)->where('id', $personId)->get()->getRowArray();
        $bis   = $zeile[$this->sperre] ?? null;

        return PinSperre::istGesperrt(
            $bis === null ? null : new DateTimeImmutable($bis, new DateTimeZone('Europe/Berlin')),
            $jetzt,
        );
    }

    /**
     * Erfolgreiche Prüfung: Zähler und Sperre zurücksetzen. false = Schreiben fehlgeschlagen.
     */
    public function erfolg(int $personId): bool
    {
        return db_connect()->table('personen')->where('id', $personId)
            ->update([$this->zaehler => 0, $this->sperre => null]);
    }
}
