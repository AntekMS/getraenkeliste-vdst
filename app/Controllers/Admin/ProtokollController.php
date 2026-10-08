<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PersonModel;
use App\Models\ProtokollModel;
use DateTimeImmutable;

class ProtokollController extends BaseController
{
    private const JE_SEITE = 50;

    public function index(): string
    {
        $filter = [
            'person'  => $this->ganzzahl($this->request->getGet('person')) ?: null,
            'tabelle' => $this->text($this->request->getGet('tabelle')),
            'von'     => $this->datum($this->request->getGet('von')),
            'bis'     => $this->datum($this->request->getGet('bis')),
        ];

        $model     = new ProtokollModel();
        $gesamt    = (new ProtokollModel())->gefiltert($filter)->countAllResults();
        $letzte    = max(1, (int) ceil($gesamt / self::JE_SEITE));
        $seite     = min($letzte, max(1, $this->ganzzahl($this->request->getGet('page'))));
        $eintraege = $model->gefiltert($filter)->paginate(self::JE_SEITE, 'default', $seite);
        $pager     = $model->pager;
        $pager->only(['person', 'tabelle', 'von', 'bis']);

        return view('admin/protokoll/index', [
            'eintraege' => $eintraege,
            'pager'     => $pager,
            'filter'    => $filter,
            'personen'  => (new PersonModel())->orderBy('nachname')->orderBy('vorname')->findAll(),
            'tabellen'  => $model->tabellen(),
        ]);
    }

    /** Nur Ziffern (max. 9), sonst 0: schützt vor Array-Parametern und Überlauf. */
    private function ganzzahl(mixed $wert): int
    {
        return is_string($wert) && ctype_digit($wert) && strlen($wert) <= 9 ? (int) $wert : 0;
    }

    private function text(mixed $wert): ?string
    {
        return is_string($wert) && trim($wert) !== '' ? trim($wert) : null;
    }

    private function datum(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = trim($wert);
        $tag  = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $tag !== false && $tag->format('Y-m-d') === $wert ? $wert : null;
    }
}
