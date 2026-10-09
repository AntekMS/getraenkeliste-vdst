/**
 * VDSt Getränkeliste - gemeinsames JavaScript
 * Darkmode-Umschalter, automatisches Ausblenden von Flash-Bannern, Drucken und Rückfragen (data-confirm).
 */

// Icon/aria-pressed aller .js-theme-toggle-Buttons an den aktuellen Theme-Wert angleichen
function syncThemeToggleIcons(theme) {
    document.querySelectorAll('.js-theme-toggle').forEach(function (button) {
        const icon = button.querySelector('.bi');
        icon.classList.toggle('bi-moon-stars', theme !== 'dark');
        icon.classList.toggle('bi-sun', theme === 'dark');
        button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    });
}

function setTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    try {
        localStorage.setItem('vdst-theme', theme);
    } catch (e) {
        // Speicher gesperrt: Theme gilt nur für diese Seite
    }
    syncThemeToggleIcons(theme);
}

function bindThemeToggle(button) {
    button.addEventListener('click', function () {
        const current = document.documentElement.getAttribute('data-bs-theme');
        setTheme(current === 'dark' ? 'light' : 'dark');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    // Flash-Banner nach 5 Sekunden ausblenden (Opt-in über .js-auto-dismiss)
    document.querySelectorAll('.alert.js-auto-dismiss').forEach(function (alert) {
        setTimeout(function () {
            if (alert && alert.parentNode && window.bootstrap) {
                new bootstrap.Alert(alert).close();
            }
        }, 5000);
    });

    document.querySelectorAll('.js-theme-toggle').forEach(bindThemeToggle);
    syncThemeToggleIcons(document.documentElement.getAttribute('data-bs-theme'));
});

// Druck-Button (Opt-in über data-print), ohne Inline-Handler wegen CSP.
// Optional data-print-bereich="<Selektor>": nur dieses Element drucken (Druck-CSS .druck-auswahl/.druck-ziel in app.css).
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-print]');
    if (!button) {
        return;
    }
    const ziel = button.dataset.printBereich ? document.querySelector(button.dataset.printBereich) : null;
    if (ziel) {
        document.documentElement.classList.add('druck-auswahl');
        ziel.classList.add('druck-ziel');
        window.addEventListener('afterprint', function () {
            document.documentElement.classList.remove('druck-auswahl');
            ziel.classList.remove('druck-ziel');
        }, { once: true });
    }
    window.print();
});

// Rückfrage vor folgenreichen Aktionen (Opt-in über data-confirm an Submit-Knopf oder Formular), ohne Inline-Handler wegen CSP
document.addEventListener('click', function (event) {
    const knopf = event.target.closest('button[data-confirm], input[type="submit"][data-confirm]');
    if (knopf && !window.confirm(knopf.dataset.confirm)) {
        event.preventDefault();
    }
});

// Gesperrt wird erst beim tatsächlichen Absenden (nach der Browser-Validierung), nicht schon beim Klick.
document.addEventListener('submit', function (event) {
    const formular = event.target;
    if (formular.matches('form[data-confirm]') && !window.confirm(formular.dataset.confirm)) {
        event.preventDefault();
        return;
    }
    if (event.defaultPrevented) {
        return;
    }
    const knopf = event.submitter;
    if (knopf && (knopf.hasAttribute('data-confirm') || formular.hasAttribute('data-confirm'))) {
        sperreKnopf(knopf);
    }
});

// Knopf sperren (kein zweiter Abschluss per Doppelklick); verzögert, damit der Knopfwert noch ins Formular gelangt.
function sperreKnopf(knopf) {
    setTimeout(function () {
        if (knopf.disabled) {
            return;
        }
        knopf.disabled = true;
        knopf.setAttribute('data-gesperrt', '1');
        knopf.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1 js-sperr-spinner" role="status" aria-hidden="true"></span>');
    }, 0);
}

// Zurück/Vor aus dem Seitencache (bfcache): gesperrte Knöpfe wieder freigeben
window.addEventListener('pageshow', function (event) {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('[data-gesperrt]').forEach(function (knopf) {
        knopf.disabled = false;
        knopf.removeAttribute('data-gesperrt');
        knopf.querySelectorAll('.js-sperr-spinner').forEach(function (spinner) {
            spinner.remove();
        });
    });
});
