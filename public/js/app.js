/**
 * VDSt Getränkeliste - gemeinsames JavaScript
 * Darkmode-Umschalter und automatisches Ausblenden von Flash-Bannern.
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
