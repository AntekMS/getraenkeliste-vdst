<?php
$anmeldung = service('anmeldung');
$person    = $anmeldung->person();
$rollen    = $anmeldung->rollen();
$pfad      = uri_string();
$aktiv     = static fn (string $praefix): string => str_starts_with($pfad, $praefix) ? 'active' : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <title><?= $this->renderSection('title') ?> | VDSt Getränkeliste</title>
    <?= $this->include('layouts/kopf') ?>
</head>
<body class="app-body">

<!-- Sidebar: ab lg feste Spalte, darunter Offcanvas-Drawer -->
<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-label="Hauptnavigation">
    <div class="app-sidebar-brand">
        <a href="<?= base_url('buchen') ?>">
            <img src="<?= base_url('img/vdst-logo.svg') ?>" alt="" class="app-sidebar-logo">
            VDSt Getränkeliste
            <span>Verein deutscher Studenten zu Erlangen</span>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
                data-bs-target="#appSidebar" aria-label="Navigation schließen"></button>
    </div>

    <nav class="app-sidebar-nav">
        <a class="app-nav-link <?= $aktiv('buchen') ?>" href="<?= base_url('buchen') ?>">
            <i class="bi bi-cup-straw" aria-hidden="true"></i> Buchen
        </a>
        <a class="app-nav-link <?= $aktiv('meine-buchungen') ?>" href="<?= base_url('meine-buchungen') ?>">
            <i class="bi bi-journal-text" aria-hidden="true"></i> Meine Buchungen
        </a>
        <a class="app-nav-link <?= $aktiv('konto') ?>" href="<?= base_url('konto') ?>">
            <i class="bi bi-person-gear" aria-hidden="true"></i> Konto
        </a>

        <?php if (\App\Libraries\Berechtigung::darf($rollen, \App\Libraries\Berechtigung::ADMIN)): ?>
            <div class="app-nav-group">Verwaltung</div>
            <a class="app-nav-link app-nav-sub <?= $aktiv('admin/personen') ?>" href="<?= base_url('admin/personen') ?>">
                <i class="bi bi-people" aria-hidden="true"></i> Personen
            </a>
            <a class="app-nav-link app-nav-sub <?= $aktiv('admin/artikel') ?>" href="<?= base_url('admin/artikel') ?>">
                <i class="bi bi-tags" aria-hidden="true"></i> Getränke &amp; Preise
            </a>
            <a class="app-nav-link app-nav-sub <?= $aktiv('admin/tablets') ?>" href="<?= base_url('admin/tablets') ?>">
                <i class="bi bi-tablet" aria-hidden="true"></i> Tablets
            </a>
            <a class="app-nav-link app-nav-sub <?= $aktiv('admin/einstellungen') ?>" href="<?= base_url('admin/einstellungen') ?>">
                <i class="bi bi-gear" aria-hidden="true"></i> Einstellungen
            </a>
            <a class="app-nav-link app-nav-sub <?= $aktiv('admin/protokoll') ?>" href="<?= base_url('admin/protokoll') ?>">
                <i class="bi bi-clipboard-data" aria-hidden="true"></i> Protokoll
            </a>
        <?php endif; ?>
    </nav>

    <div class="app-sidebar-foot">
        <span class="app-sidebar-user">
            <i class="bi bi-person-circle" aria-hidden="true"></i>
            <?= esc($person['anzeigename'] ?? '') ?>
        </span>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="app-theme-toggle js-theme-toggle" title="Darkmode umschalten"
                    aria-label="Darkmode umschalten" aria-pressed="false">
                <i class="bi bi-moon-stars" aria-hidden="true"></i>
            </button>
            <form action="<?= base_url('logout') ?>" method="post" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-logout">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Abmelden
                </button>
            </form>
        </div>
    </div>
</aside>

<div class="app-content">
    <!-- Mobile Topbar -->
    <header class="app-topbar d-lg-none">
        <button type="button" class="app-topbar-btn" data-bs-toggle="offcanvas" data-bs-target="#appSidebar"
                aria-controls="appSidebar" title="Navigation öffnen" aria-label="Navigation öffnen">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>
        <a class="app-topbar-brand" href="<?= base_url('buchen') ?>">
            <img src="<?= base_url('img/vdst-logo.svg') ?>" alt="" class="app-topbar-logo">
            VDSt Getränkeliste
        </a>
        <button type="button" class="app-topbar-btn js-theme-toggle" title="Darkmode umschalten"
                aria-label="Darkmode umschalten" aria-pressed="false">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>
    </header>

    <?= $this->include('layouts/meldungen') ?>

    <main class="main-content">
        <?= $this->renderSection('content') ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('js/app.js') ?>?v=1"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
