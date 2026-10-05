<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\FreischaltcodeModel;
use App\Models\GeraetModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;

class TabletsController extends BaseController
{
    public function index(): string
    {
        return view('admin/tablets/index', [
            'geraete' => (new GeraetModel())->alle(),
            'code'    => session()->getFlashdata('freischaltcode'),
        ]);
    }

    /**
     * Der Code steht nur im Flash (genau eine Anzeige); protokolliert wird ohne Codewert.
     */
    public function codeErzeugen(): RedirectResponse
    {
        $jetzt  = service('uhr')->jetzt();
        $modell = new FreischaltcodeModel();
        $code   = '';
        $bis    = '';

        $modell->transaktion(function () use ($modell, $jetzt, &$code, &$bis): void {
            $code = $modell->erzeuge($this->adminId(), $jetzt);
            $id   = $modell->getInsertID();
            $bis  = $modell->find($id)['gueltig_bis'];
            service('protokollierer')->schreibe($this->adminId(), 'freischaltcode_erzeugt', 'freischaltcodes', $id, null, ['gueltig_bis' => $bis]);
        });

        $zeit = (new DateTimeImmutable($bis))->format('H:i');

        return redirect()->to(site_url('admin/tablets'))->with('freischaltcode', ['code' => $code, 'gueltig_bis' => $zeit]);
    }

    public function umbenennen(int $id): RedirectResponse
    {
        $geraet  = $this->geraet($id);
        $zurueck = redirect()->to(site_url('admin/tablets'));
        $name    = trim((string) $this->request->getPost('name'));

        if ($name === '' || mb_strlen($name) > 100) {
            return $zurueck->with('error', 'Bitte einen Namen mit höchstens 100 Zeichen angeben.');
        }

        if ($name !== $geraet['name']) {
            $modell = new GeraetModel();
            $modell->transaktion(function () use ($modell, $id, $geraet, $name): void {
                $modell->update($id, ['name' => $name]);
                service('protokollierer')->schreibe($this->adminId(), 'umbenannt', 'geraete', $id, ['name' => $geraet['name']], ['name' => $name]);
            });
        }

        return $zurueck->with('success', 'Gespeichert.');
    }

    public function sperren(int $id): RedirectResponse
    {
        $geraet  = $this->geraet($id);
        $zurueck = redirect()->to(site_url('admin/tablets'));

        if ($geraet['gesperrt_at'] !== null) {
            return $zurueck->with('error', 'Das Tablet ist bereits gesperrt.');
        }

        $jetzt  = service('uhr')->jetzt()->format('Y-m-d H:i:s');
        $modell = new GeraetModel();

        $modell->transaktion(function () use ($modell, $id, $jetzt): void {
            $modell->update($id, ['gesperrt_at' => $jetzt]);
            service('protokollierer')->schreibe($this->adminId(), 'gesperrt', 'geraete', $id, ['gesperrt_at' => null], ['gesperrt_at' => $jetzt]);
        });

        return $zurueck->with('success', 'Tablet gesperrt.');
    }

    private function adminId(): int
    {
        return (int) service('anmeldung')->person()['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function geraet(int $id): array
    {
        return (new GeraetModel())->find($id) ?? throw PageNotFoundException::forPageNotFound();
    }
}
