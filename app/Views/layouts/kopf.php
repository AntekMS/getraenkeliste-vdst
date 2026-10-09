<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- Darkmode VOR dem CSS setzen, sonst kurzes Aufblitzen im falschen Theme -->
<script>
    (function () {
        var stored = localStorage.getItem('vdst-theme');
        var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
</script>

<link rel="icon" href="<?= base_url('favicon.ico') ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- VDSt Design-System (einzige Theme-Quelle) -->
<link href="<?= base_url('css/app.css') ?>?v=7" rel="stylesheet">
