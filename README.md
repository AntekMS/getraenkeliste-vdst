# Getränkeliste VDSt

Getränkeliste des Vereins deutscher Studenten zu Erlangen (CodeIgniter 4.7, PHP 8.3, MySQL 8).
Mitglieder buchen ihren Verbrauch am Kühlschrank-Tablet oder am eigenen Handy.

## Lokal starten

```bash
docker compose up -d --build
docker exec getraenkeliste-web composer install
docker exec getraenkeliste-web vendor/bin/phpunit
```

- App: http://localhost:8090/
- phpMyAdmin: http://localhost:8091/
- Zugangsdaten und Basis-URL lassen sich über eine `.env` neben der `docker-compose.yml`
  überschreiben (`DB_PASS`, `MYSQL_ROOT_PASSWORD`, `APP_BASEURL`, `CI_ENVIRONMENT`);
  Vorlage für die App-Konfiguration: Datei `env`.

## Raspberry Pi

phpMyAdmin per lokaler, nicht versionierter `docker-compose.override.yml` abschalten
(z. B. dem Service `getraenkeliste-phpmyadmin` ein nicht aktiviertes Profil geben).

## Dokumentation

Design-Spec und Implementierungspläne: `docs/superpowers/`. Hinweise für die Entwicklung: `CLAUDE.md`.
