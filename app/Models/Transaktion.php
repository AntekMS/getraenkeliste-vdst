<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Transaktion, in der jeder fehlgeschlagene Query eine Exception wirft (CI4 wirft in
 * Transaktionen sonst nicht und committet Teilergebnisse). Bei jedem Fehler: Rollback, Exception weiter.
 */
trait Transaktion
{
    public function transaktion(callable $arbeit): void
    {
        $this->db->transException(true);
        $this->db->transBegin();

        try {
            $arbeit();
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        } finally {
            $this->db->transException(false);
            $this->db->resetTransStatus();
        }
    }
}
