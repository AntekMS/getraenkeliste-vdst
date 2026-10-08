<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EinstellungModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Betriebswerte aus der Tabelle `einstellungen`, je Request einmal geladen
 * (als Shared Service `einstellungen()`).
 */
final class Einstellungen
{
    /** @var ?array<string, string> */
    private ?array $werte = null;

    public function int(string $schluessel): int
    {
        return (int) $this->text($schluessel);
    }

    public function text(string $schluessel): string
    {
        return $this->alle()[$schluessel] ?? EinstellungDefinition::DEFINITIONEN[$schluessel]['default'] ?? '';
    }

    public function inbetriebnahme(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->text('inbetriebnahme_at'), new DateTimeZone('Europe/Berlin'));
    }

    /**
     * @return ?string Fehlertext oder null bei Erfolg
     */
    public function setze(string $schluessel, string $wert, int $adminId): ?string
    {
        $fehler = EinstellungDefinition::validiere($schluessel, $wert);

        if ($fehler !== null) {
            return $fehler;
        }

        $alt = $this->text($schluessel);

        if ($alt === $wert) {
            return null;
        }

        $model = new EinstellungModel();
        $db    = db_connect();

        $db->transStart();

        if ($model->find($schluessel) === null) {
            $model->insert(['schluessel' => $schluessel, 'wert' => $wert]);
        } else {
            $model->update($schluessel, ['wert' => $wert]);
        }

        service('protokollierer')->schreibe($adminId, 'geaendert', 'einstellungen', null, ['wert' => $alt], ['wert' => $wert]);

        $db->transComplete();

        if (! $db->transStatus()) {
            return 'Die Einstellung konnte nicht gespeichert werden.';
        }

        $this->werte = null;

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function alle(): array
    {
        if ($this->werte === null) {
            $this->werte = [];

            foreach ((new EinstellungModel())->findAll() as $zeile) {
                $this->werte[$zeile['schluessel']] = (string) $zeile['wert'];
            }
        }

        return $this->werte;
    }
}
