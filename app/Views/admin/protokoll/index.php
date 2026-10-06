<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Protokoll<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$liste = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '<span class="text-muted">–</span>';
    }

    $daten = json_decode($json, true);

    if (! is_array($daten)) {
        return esc($json);
    }

    $html = '<dl class="mb-0 small">';

    foreach ($daten as $schluessel => $wert) {
        $text  = is_scalar($wert) ? (string) $wert : ($wert === null ? '–' : json_encode($wert, JSON_UNESCAPED_UNICODE));
        $html .= '<dt class="d-inline">' . esc((string) $schluessel) . '</dt> <dd class="d-inline ms-1 me-3">' . esc($text) . '</dd>';
    }

    return $html . '</dl>';
};
?>
<h1 class="h3 mb-3">Protokoll</h1>

<form method="get" action="<?= base_url('admin/protokoll') ?>" class="row g-2 mb-3">
    <div class="col-12 col-md-auto">
        <label for="person" class="form-label mb-0 small">Person</label>
        <select class="form-select" id="person" name="person">
            <option value="">Alle</option>
            <?php foreach ($personen as $p): ?>
                <option value="<?= esc($p['id']) ?>" <?= (int) $filter['person'] === (int) $p['id'] ? 'selected' : '' ?>>
                    <?= esc($p['anzeigename']) ?><?= $p['archiviert_at'] !== null ? ' (archiviert)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-auto">
        <label for="tabelle" class="form-label mb-0 small">Tabelle</label>
        <select class="form-select" id="tabelle" name="tabelle">
            <option value="">Alle</option>
            <?php foreach ($tabellen as $t): ?>
                <option value="<?= esc($t) ?>" <?= $filter['tabelle'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-auto">
        <label for="von" class="form-label mb-0 small">Von</label>
        <input type="date" class="form-control" id="von" name="von" value="<?= esc((string) $filter['von']) ?>">
    </div>
    <div class="col-6 col-md-auto">
        <label for="bis" class="form-label mb-0 small">Bis</label>
        <input type="date" class="form-control" id="bis" name="bis" value="<?= esc((string) $filter['bis']) ?>">
    </div>
    <div class="col-12 col-md-auto d-flex align-items-end"><button type="submit" class="btn btn-outline-vdst">Filtern</button></div>
</form>

<?php if ($eintraege === []): ?>
    <p class="text-muted">Keine Einträge gefunden.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-top">
            <thead class="table-vdst">
                <tr><th>Zeit</th><th>Person</th><th>Aktion</th><th>Tabelle</th><th>ID</th><th>Alt</th><th>Neu</th></tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $e): ?>
                    <tr>
                        <td class="text-nowrap"><?= esc(date('d.m.Y H:i:s', strtotime($e['erfolgt_at']))) ?></td>
                        <td><?= $e['person_id'] === null ? 'System' : esc((string) $e['anzeigename']) ?></td>
                        <td><?= esc($e['aktion']) ?></td>
                        <td><?= esc($e['tabelle']) ?></td>
                        <td><?= esc((string) $e['datensatz_id']) ?></td>
                        <td><?= $liste($e['alt']) ?></td>
                        <td><?= $liste($e['neu']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links('default', 'bootstrap_full') ?>
<?php endif; ?>
<?= $this->endSection() ?>
