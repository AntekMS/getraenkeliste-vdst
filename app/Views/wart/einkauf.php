<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Einkauf<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$basis       = 'wart/' . $bereich['schluessel'];
$ampelKlasse = ['ok' => 'badge-status-gruen', 'niedrig' => 'badge-status-amber', 'leer' => 'badge-status-rot', 'negativ' => 'badge-status-rot'];
$ampelText   = ['ok' => 'OK', 'niedrig' => 'Niedrig', 'leer' => 'Leer', 'negativ' => 'Negativ'];
$zahl        = static fn (float $wert): string => number_format($wert, 1, ',', '.');
$prozent     = static fn (?float $wert): string => $wert === null ? '—' : number_format($wert, 1, ',', '.') . ' %';
$tage        = static fn (int $n): string => $n === 1 ? '1 Tag' : $n . ' Tage';
$reicht      = static fn (array $a): string => $a['bestand'] <= 0 ? 'leer' : ($a['reichweite'] === null ? '—' : $tage($a['reichweite']));
$vorschlag   = static fn (array $v): string => $v['kisten'] === null
    ? $v['stueck'] . ' Stück'
    : ($v['kisten'] === 1 ? '1 Kiste' : $v['kisten'] . ' Kisten') . ' (= ' . $v['stueck'] . ' Stück)';
$artikelName = static fn (array $a): string => esc($a['name']) . ($a['einheit'] === '' ? '' : ' <span class="text-muted small">' . esc($a['einheit']) . '</span>');
$arten       = ['mitglieder' => 'Mitglieder', 'couleur' => 'Couleur', 'bund' => 'Bund'];
$woche       = static fn (string $iso): string => 'KW ' . (int) substr($iso, strpos($iso, 'W') + 1);
$wochen      = $verlauf['wochen'];
$letzte      = array_key_last($wochen);
$diagramm    = json_encode([
    'labels' => array_map($woche, $wochen),
    'reihen' => $verlauf['reihen'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Einkauf <?= esc($bereich['name']) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-vdst" href="<?= base_url($basis . '/bewegung') ?>">
            <i class="bi bi-dash-circle" aria-hidden="true"></i> Schwund/Korrektur
        </a>
        <a class="btn btn-vdst" href="<?= base_url($basis . '/lieferung') ?>">
            <i class="bi bi-box-seam" aria-hidden="true"></i> Lieferung erfassen
        </a>
    </div>
</div>

<section class="card mb-4 bestellliste" aria-labelledby="bestellliste-titel">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="h5 mb-0" id="bestellliste-titel">Bestellliste für die nächste Lieferung</h2>
            <?php if ($bestellen !== []): ?>
                <button type="button" class="btn btn-outline-vdst btn-sm no-print" data-print data-print-bereich=".bestellliste">
                    <i class="bi bi-printer" aria-hidden="true"></i> Bestellliste drucken
                </button>
            <?php endif; ?>
        </div>
        <p class="text-muted small mb-2">
            Grundlage: Verbrauch der letzten 28 Tage, Reichweite <?= esc($einkauf['reichweite_tage']) ?> Tage (änderbar in den Einstellungen).
            <?php if ($einkauf['grundlage_tage'] < 28): ?>
                <br><i class="bi bi-info-circle" aria-hidden="true"></i> Grundlage erst <?= esc($tage($einkauf['grundlage_tage'])) ?>.
            <?php endif; ?>
        </p>

        <?php if ($bestellen === []): ?>
            <p class="mb-0">Gerade muss nichts bestellt werden.</p>
        <?php endif; ?>

        <?php foreach ($bestellen as $gruppe): ?>
            <h3 class="h6 mt-3"><?= esc($gruppe['kategorie_name']) ?></h3>
            <div class="table-responsive">
                <table class="table table-stack align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Artikel</th>
                            <th class="text-end">Bestand</th>
                            <th class="text-end">reicht noch (Tage)</th>
                            <th class="text-end">Vorschlag</th>
                            <th class="text-end">letzter Einkaufspreis je Stück</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gruppe['artikel'] as $a): ?>
                            <tr>
                                <td data-label="Artikel"><?= $artikelName($a) ?></td>
                                <td data-label="Bestand" class="text-end"><?= esc($a['bestand']) ?></td>
                                <td data-label="reicht noch (Tage)" class="text-end"><?= esc($reicht($a)) ?></td>
                                <td data-label="Vorschlag" class="text-end fw-semibold"><?= esc($vorschlag($a['vorschlag'])) ?></td>
                                <td data-label="letzter Einkaufspreis je Stück" class="text-end"><?= $a['letzter_ek_cent'] === null ? '—' : esc(formatiere_cent($a['letzter_ek_cent'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-4 bestand-tabelle" aria-labelledby="bestand-titel">
    <h2 class="h5" id="bestand-titel">Bestand aller Artikel</h2>

    <?php if ($negativ): ?>
        <div class="alert alert-warning" role="alert">
            Achtung: Der Bestand ist negativ bei mindestens einem Artikel. Bitte Lieferungen oder die letzte Auszählung prüfen.
        </div>
    <?php endif; ?>

    <?php if ($einkauf['kategorien'] === []): ?>
        <p class="text-muted">Keine Artikel mit Bestandsführung.</p>
    <?php endif; ?>

    <?php foreach ($einkauf['kategorien'] as $gruppe): ?>
        <h3 class="h6 mt-3"><?= esc($gruppe['kategorie_name']) ?></h3>
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th class="text-end">Bestand</th>
                        <th class="text-end">Mindestbestand</th>
                        <th>Status</th>
                        <th class="text-end">Ø Verbrauch/Tag</th>
                        <th class="text-end">reicht noch ca.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gruppe['artikel'] as $a): ?>
                        <tr>
                            <td data-label="Artikel"><?= $artikelName($a) ?></td>
                            <td data-label="Bestand" class="text-end"><?= esc($a['bestand']) ?></td>
                            <td data-label="Mindestbestand" class="text-end"><?= esc($a['mindestbestand']) ?></td>
                            <td data-label="Status"><span class="badge-status <?= $ampelKlasse[$a['ampel']] ?>"><?= esc($ampelText[$a['ampel']]) ?></span></td>
                            <td data-label="Ø Verbrauch/Tag" class="text-end"><?= esc($zahl($a['tagesverbrauch'])) ?></td>
                            <td data-label="reicht noch ca." class="text-end"><?= esc($reicht($a)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</section>

<section class="mb-4 anteile" aria-labelledby="anteile-titel">
    <h2 class="h5" id="anteile-titel">Anteil Couleur / Bund</h2>
    <?php foreach ($anteile as $kachel): ?>
        <h3 class="h6 mt-3">
            <?= esc($kachel['titel']) ?>
            <?php if ($kachel['zeitraum'] !== null): ?>
                <span class="text-muted fw-normal">(<?= esc($kachel['zeitraum']) ?>)</span>
            <?php endif; ?>
        </h3>
        <div class="row row-cols-1 row-cols-md-3 g-3">
            <?php foreach ($arten as $art => $name): ?>
                <div class="col">
                    <div class="card stat-tile h-100">
                        <div class="card-body">
                            <div class="stat-tile-label"><?= esc($name) ?></div>
                            <dl class="anteil-werte">
                                <div>
                                    <dt>Menge</dt>
                                    <dd class="stat-tile-value"><?= esc($prozent($kachel['jetzt']['menge'][$art])) ?></dd>
                                </div>
                                <div>
                                    <dt>Umsatz</dt>
                                    <dd class="stat-tile-value"><?= esc($prozent($kachel['jetzt']['cent'][$art])) ?></dd>
                                </div>
                            </dl>
                            <div class="anteil-vergleich">
                                <?= esc($kachel['jetzt']['roh']['menge'][$art]) ?> Stück · <?= esc(formatiere_cent($kachel['jetzt']['roh']['cent'][$art])) ?>
                            </div>
                            <?php if ($kachel['davor'] !== null): ?>
                                <div class="anteil-vergleich">
                                    <?= $kachel['zeitraum'] === null ? '28 Tage davor' : 'Zeitraum davor' ?>:
                                    Menge <?= esc($prozent($kachel['davor']['menge'][$art])) ?> · Umsatz <?= esc($prozent($kachel['davor']['cent'][$art])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>

<section class="mb-4 verlauf" aria-labelledby="verlauf-titel">
    <h2 class="h5" id="verlauf-titel">Verbrauch je Woche (letzte 12 Wochen)</h2>
    <form method="get" action="<?= base_url($basis . '/einkauf') ?>" class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-6">
            <label for="ansicht" class="form-label">Ansicht</label>
            <select class="form-select" id="ansicht" name="ansicht">
                <option value="alle"<?= $verlauf['ansicht'] === 'alle' ? ' selected' : '' ?>>Alle Kategorien</option>
                <?php foreach ($optionen as $kategorie): ?>
                    <optgroup label="<?= esc($kategorie['name']) ?>">
                        <?php $wert = 'kategorie:' . $kategorie['id']; ?>
                        <option value="<?= esc($wert) ?>"<?= $verlauf['ansicht'] === $wert ? ' selected' : '' ?>>Kategorie <?= esc($kategorie['name']) ?> (je Artikel)</option>
                        <?php foreach ($kategorie['artikel'] as $a): ?>
                            <?php $wert = 'artikel:' . $a['id']; ?>
                            <option value="<?= esc($wert) ?>"<?= $verlauf['ansicht'] === $wert ? ' selected' : '' ?>><?= esc($a['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-vdst">Anzeigen</button>
        </div>
    </form>

    <?php if ($verlauf['reihen'] === []): ?>
        <p class="text-muted">Keine Artikel für diese Ansicht.</p>
    <?php else: ?>
        <div class="diagramm-rahmen mb-3" hidden>
            <canvas class="js-diagramm" data-diagramm="<?= esc($diagramm, 'attr') ?>" aria-label="Verbrauch je Woche als Liniendiagramm" role="img"></canvas>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Woche</th>
                        <?php foreach ($verlauf['reihen'] as $reihe): ?>
                            <th class="text-end"><?= esc($reihe['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wochen as $i => $iso): ?>
                        <tr>
                            <td><?= esc($woche($iso)) ?><?= $i === $letzte ? ' (bis heute)' : '' ?></td>
                            <?php foreach ($verlauf['reihen'] as $reihe): ?>
                                <td class="text-end"><?= esc($reihe['werte'][$i]) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="mb-4 lieferhistorie" aria-labelledby="lieferhistorie-titel">
    <h2 class="h5" id="lieferhistorie-titel">Lieferhistorie</h2>
    <?php if ($lieferungen === []): ?>
        <p class="text-muted">Noch keine Lieferungen erfasst.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-stack align-middle">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Artikel mit Menge</th>
                        <th class="text-end">Einkaufspreis je Stück</th>
                        <th>erfasst von</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lieferungen as $lieferung): ?>
                        <tr>
                            <td data-label="Datum"><?= esc((new DateTimeImmutable($lieferung['erfolgt_at']))->format('d.m.Y H:i')) ?></td>
                            <td data-label="Artikel mit Menge">
                                <?php foreach ($lieferung['zeilen'] as $z): ?>
                                    <div><?= esc($z['menge']) ?> × <?= esc($z['artikel']) ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="Einkaufspreis je Stück" class="text-end">
                                <?php foreach ($lieferung['zeilen'] as $z): ?>
                                    <div><?= $z['einkaufspreis_cent'] === null ? '—' : esc(formatiere_cent($z['einkaufspreis_cent'])) ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="erfasst von"><?= esc($lieferung['erfasst_von']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
