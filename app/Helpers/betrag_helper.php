<?php

declare(strict_types=1);

/**
 * Betrags-Helper - Komma-Eingaben normalisieren, in Cent wandeln, Cent formatieren.
 *
 * Geldbeträge werden überall als Cent (INT) geführt.
 */

if (!function_exists('normalisiere_betrag')) {
    /**
     * Normalisiert deutsche Betrag-Eingaben für die Validierung.
     *
     * "10,50" → "10.50", "1.234,56" → "1234.56", "1.000" → "1000";
     * ein einzelner Punkt als Dezimaltrenner bleibt erhalten ("10.50" → "10.50",
     * "1.5" → "1.5"). Der Punkt wird nur dann als Tausendertrenner entfernt,
     * wenn die Eingabe wie Tausendergruppen aussieht (z.B. "1.000", "1.234.567").
     */
    function normalisiere_betrag(?string $eingabe): ?string
    {
        if ($eingabe === null) {
            return null;
        }

        $eingabe = trim($eingabe);

        if (str_contains($eingabe, ',')) {
            // Komma = Dezimaltrenner, Punkt(e) = Tausendertrenner
            $eingabe = str_replace('.', '', $eingabe);
            $eingabe = str_replace(',', '.', $eingabe);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $eingabe)) {
            // Nur Punkte in Tausendergruppen ("1.000", "1.234.567") → Tausendertrenner
            $eingabe = str_replace('.', '', $eingabe);
        }

        return $eingabe;
    }
}

if (!function_exists('betrag_in_cent')) {
    /**
     * Wandelt eine Betragseingabe ("1,50", "1.000,00") ohne Float in Cent.
     * Ungültige oder negative Eingaben liefern null.
     */
    function betrag_in_cent(?string $eingabe): ?int
    {
        $normalisiert = normalisiere_betrag($eingabe);

        if ($normalisiert === null || preg_match('/^\d+(\.\d{1,2})?$/', $normalisiert) !== 1) {
            return null;
        }

        [$euro, $cent] = array_pad(explode('.', $normalisiert), 2, '0');

        // Mehr als 7 Euro-Stellen sind nie ein sinnvoller Betrag und würden (int) überlaufen lassen.
        if (strlen($euro) > 7) {
            return null;
        }

        return (int) $euro * 100 + (int) str_pad($cent, 2, '0');
    }
}

if (!function_exists('formatiere_cent')) {
    /**
     * Formatiert Cent für die Anzeige im deutschen Format ("1.234,56 €").
     */
    function formatiere_cent(int $cent): string
    {
        $vorzeichen = $cent < 0 ? '-' : '';
        $betrag     = abs($cent);

        return $vorzeichen . number_format(intdiv($betrag, 100), 0, ',', '.')
            . ',' . str_pad((string) ($betrag % 100), 2, '0', STR_PAD_LEFT) . ' €';
    }
}
