<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Schwund<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$basis   = 'wart/' . $bereich['schluessel'];
$datum   = static fn (string $zeit): string => (new DateTimeImmutable($zeit))->format('d.m.Y');
$spanne  = static fn (array $z): string => $datum($z['von']) . ' – ' . $datum($z['bis']);
$prozent = static fn (?float $wert): string => $wert === null ? '—' : number_format($wert, 1, ',', '.') . ' %';
$punkte  = static fn (float $wert): string => number_format(abs($wert), 1, ',', '.') . ' Prozentpunkte';
$k       = $kennzahlen;
$z       = $k['zeitraum'] ?? null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Schwund <?= esc($bereich['name']) ?></h1>
</div>

<?php if ($zeitraeume === []): ?>
    <div class="alert alert-info" role="status">
        Noch keine abgeschlossene Auszählung – Schwund wird nach der ersten Auszählung ausgewertet.
    </div>
<?php else: ?>
    <section class="mb-4 kennzahlen" aria-labelledby="kennzahlen-titel">
        <h2 class="h5" id="kennzahlen-titel">Letzter abgeschlossener Zeitraum <span class="text-muted fw-normal">(<?= esc($spanne($z)) ?>)</span></h2>
        <?php if ($k['start']): ?>
            <p class="text-muted"><i class="bi bi-info-circle" aria-hidden="true"></i> Start – noch kein Schwund auswertbar</p>
        <?php endif; ?>
        <div class="row row-cols-1 row-cols-md-3 g-3">
            <div class="col">
                <div class="card stat-tile h-100"><div class="card-body">
                    <div class="stat-tile-label">Erfasster Schwund</div>
                    <div class="stat-tile-value"><?= esc(formatiere_cent($z['erfasst_cent'])) ?></div>
                    <div class="anteil-vergleich"><?= esc($z['erfasst_menge']) ?> Stück</div>
                </div></div>
            </div>
            <div class="col">
                <div class="card stat-tile h-100"><div class="card-body">
                    <div class="stat-tile-label">Unerklärte Differenz</div>
                    <div class="stat-tile-value"><?= esc(formatiere_cent($z['unerklaert_cent'])) ?></div>
                    <div class="anteil-vergleich"><?= esc($z['unerklaert_menge']) ?> Stück</div>
                    <div class="anteil-vergleich">Überschuss: <?= esc(formatiere_cent($z['ueberschuss_cent'])) ?> (<?= esc($z['ueberschuss_menge']) ?> Stück)</div>
                </div></div>
            </div>
            <div class="col">
                <div class="card stat-tile h-100"><div class="card-body">
                    <div class="stat-tile-label">Schwundquote</div>
                    <div class="stat-tile-value"><?= esc($k['start'] ? '—' : $prozent($z['quote'])) ?></div>
                    <div class="anteil-vergleich">
                        <?php if ($k['start']): ?>
                            Start
                        <?php elseif ($k['delta'] === null): ?>
                            Vergleich zum Zeitraum davor: —
                        <?php elseif ($k['delta'] > 0): ?>
                            <span class="text-danger"><i class="bi bi-arrow-up" aria-hidden="true"></i> schlechter: +<?= esc($punkte($k['delta'])) ?></span> zum Zeitraum davor
                        <?php elseif ($k['delta'] < 0): ?>
                            <span class="text-success"><i class="bi bi-arrow-down" aria-hidden="true"></i> besser: −<?= esc($punkte($k['delta'])) ?></span> zum Zeitraum davor
                        <?php else: ?>
                            unverändert zum Zeitraum davor (0,0 Prozentpunkte)
                        <?php endif; ?>
                    </div>
                </div></div>
            </div>
        </div>
    </section>

    <section class="mb-4 verlauf" aria-labelledby="verlauf-titel">
        <h2 class="h5" id="verlauf-titel">Verlauf der letzten 6 Zeiträume</h2>
        <div class="diagramm-rahmen mb-3" hidden>
            <canvas class="js-diagramm" data-diagramm="<?= esc($diagramm, 'attr') ?>" aria-label="Erfasster und unerklärter Schwund je Zeitraum als Säulendiagramm" role="img"></canvas>
        </div>
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                    <tr>
                        <th>Zeitraum</th>
                        <th class="text-end">Erfasst</th>
                        <th class="text-end">Unerklärt</th>
                        <th class="text-end">Überschuss</th>
                        <th class="text-end">Quote</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zeitraeume as $zeile): ?>
                        <tr>
                            <td data-label="Zeitraum"><?= esc($spanne($zeile)) ?><?= $zeile['art'] === 'start' ? ' <span class="badge-status badge-status-neutral">Start</span>' : '' ?></td>
                            <td data-label="Erfasst" class="text-end"><?= esc(formatiere_cent($zeile['erfasst_cent'])) ?></td>
                            <td data-label="Unerklärt" class="text-end"><?= esc(formatiere_cent($zeile['unerklaert_cent'])) ?></td>
                            <td data-label="Überschuss" class="text-end"><?= esc(formatiere_cent($zeile['ueberschuss_cent'])) ?></td>
                            <td data-label="Quote" class="text-end"><?= esc($prozent($zeile['quote'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="mb-4 top-artikel" aria-labelledby="top-titel">
        <h2 class="h5" id="top-titel">Artikel mit dem meisten Schwund (letzte 6 Zeiträume)</h2>
        <?php if ($top === []): ?>
            <p class="text-muted">Kein Schwund in den letzten Zeiträumen.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-stack align-middle">
                    <thead>
                        <tr>
                            <th>Artikel</th>
                            <th class="text-end">erfasst (Menge)</th>
                            <th class="text-end">unerklärt (Menge)</th>
                            <th class="text-end">gesamt</th>
                            <th class="text-end">Quote</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top as $a): ?>
                            <tr>
                                <td data-label="Artikel"><a href="<?= base_url($basis . '/schwund?artikel=' . (int) $a['artikel_id']) ?>#artikel-verlauf"><?= esc($a['name']) ?></a></td>
                                <td data-label="erfasst (Menge)" class="text-end"><?= esc($a['erfasst_menge']) ?></td>
                                <td data-label="unerklärt (Menge)" class="text-end"><?= esc($a['unerklaert_menge']) ?></td>
                                <td data-label="gesamt" class="text-end"><?= esc(formatiere_cent($a['gesamt_cent'])) ?></td>
                                <td data-label="Quote" class="text-end"><?= esc($prozent($a['quote'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($gewaehlt !== null): ?>
            <div id="artikel-verlauf" class="mt-3">
                <h3 class="h6">Verlauf: <?= esc($artikelName) ?></h3>
                <div class="table-responsive">
                    <table class="table table-stack align-middle">
                        <thead>
                            <tr>
                                <th>Zeitraum</th>
                                <th class="text-end">erfasst (Menge)</th>
                                <th class="text-end">unerklärt (Menge)</th>
                                <th class="text-end">gesamt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verlauf as $zeile): ?>
                                <tr>
                                    <td data-label="Zeitraum"><?= esc($spanne($zeile)) ?></td>
                                    <td data-label="erfasst (Menge)" class="text-end"><?= esc($zeile['erfasst_menge']) ?></td>
                                    <td data-label="unerklärt (Menge)" class="text-end"><?= esc($zeile['unerklaert_menge']) ?></td>
                                    <td data-label="gesamt" class="text-end"><?= esc(formatiere_cent($zeile['gesamt_cent'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="mb-4 laufend" aria-labelledby="laufend-titel">
    <h2 class="h5" id="laufend-titel">Laufender Zeitraum</h2>
    <p class="mb-0">Seit dem letzten Abschluss bereits erfasst: <?= esc($laufend['menge']) ?> Stück / <?= esc(formatiere_cent($laufend['cent'])) ?></p>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<script src="<?= base_url('js/statistik.js') ?>?v=2"></script>
<?= $this->endSection() ?>
