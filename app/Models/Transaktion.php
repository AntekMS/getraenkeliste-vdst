<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Transaktion, in der jeder fehlgeschlagene Query eine Exception wirft (CI4 wirft in
 * Transaktionen sonst nicht und committet Teilergebnisse). Bei jedem Fehler: Rollback, Exception weiter.
 * Einzige Implementierung im Projekt (auch BuchungService nutzt sie über BuchungModel).
 */
trait Transaktion
{
    public function transaktion(callable $arbeit): void
    {
        $vorher = (bool) $this->db->transException; // BaseConnection::__get liest die geschützte Eigenschaft
        $this->db->transException(true);
        $this->db->transBegin();

        try {
            $arbeit();
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        } finally {
            $this->db->transException($vorher);
            $this->db->resetTransStatus(); // strikter Modus: sonst bleibt transStatus nach einem Fehler dauerhaft false
        }
    }
}
