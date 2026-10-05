<!DOCTYPE html>
<html lang="de">
<head>
    <title><?= $this->renderSection('title') ?> | VDSt Getränkeliste</title>
    <?= $this->include('layouts/kopf') ?>
</head>
<body class="login-page">
<div class="login-container">
    <div class="login-header">
        <div>
            <h1>VDSt Getränkeliste</h1>
            <p>Verein deutscher Studenten zu Erlangen</p>
        </div>
        <button type="button" class="app-theme-toggle js-theme-toggle" title="Darkmode umschalten"
                aria-label="Darkmode umschalten" aria-pressed="false">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>
    </div>

    <div class="login-body">
        <?= $this->include('layouts/meldungen') ?>
        <?= $this->renderSection('content') ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('js/app.js') ?>?v=1"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
