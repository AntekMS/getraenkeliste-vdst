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
            'person'  => (int) $this->request->getGet('person') ?: null,
            'tabelle' => trim((string) $this->request->getGet('tabelle')) ?: null,
            'von'     => $this->datum($this->request->getGet('von')),
            'bis'     => $this->datum($this->request->getGet('bis')),
        ];

        $model    = new ProtokollModel();
        $seite     = max(1, (int) $this->request->getGet('page'));
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

    private function datum(mixed $wert): ?string
    {
        $wert = trim((string) $wert);
        $tag  = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $tag !== false && $tag->format('Y-m-d') === $wert ? $wert : null;
    }
}
