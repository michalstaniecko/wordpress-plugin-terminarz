# Terminarz

Plugin WordPress do rezerwacji terminów online dla firm usługowych (gabinety, sale, instruktorzy),
z opcjonalną płatnością przez WooCommerce i automatycznymi powiadomieniami.

Wymagania: PHP 8.1+, WordPress 6.5+, WooCommerce 8.0+ (opcjonalnie — bez niego plugin działa bez płatności).

## Uruchomienie środowiska deweloperskiego

Potrzebne: Docker, Node.js 20+, PHP 8.1+ i Composer (na hoście — do lintów i testów jednostkowych).

```sh
composer install
npm install
npm run build
npm run env:start
```

`npm run env:start` uruchamia dwa niezależne środowiska [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env):

| Środowisko | Konfiguracja | Adres | Przeznaczenie |
|---|---|---|---|
| development | `.wp-env.json` | http://localhost:8888 | praca ręczna |
| tests | `.wp-env.tests.json` | http://localhost:8889 | testy integracyjne PHPUnit i E2E |

Logowanie do panelu: `admin` / `password` (domyślne, testowe dane wp-env).
W obu środowiskach aktywne są Terminarz (katalog `wp-content/plugins/terminarz`) i WooCommerce (najnowsza stabilna wersja),
a `WP_DEBUG` i `WP_DEBUG_LOG` są włączone (`wp-content/debug.log`, wyświetlanie błędów wyłączone).
Lokalne nadpisania: `.wp-env.override.json` / `.wp-env.tests.override.json` (ignorowane przez git).

Przydatne komendy:

| Cel | Komenda |
|---|---|
| Start obu środowisk | `npm run env:start` (tylko jedno: `env:start:dev` / `env:start:tests`) |
| Zatrzymanie | `npm run env:stop` |
| Usunięcie środowisk | `npm run env:destroy` |
| WP-CLI | `npm run env:cli -- wp plugin list` / `npm run env:tests:cli -- wp plugin list` |
| Build bloków | `npm run build` (tryb watch: `npm start`) |
| Lint JS/CSS | `npm run lint` |
| Plik `.pot` | `npm run i18n` (WP-CLI i18n z Composera, bez Dockera) |
| Testy PHP (unit + integration) | `composer test` (unit bez Dockera: `composer test:unit`; integration: `composer test:integration`, wymaga `npm run env:start:tests`) |
| Testy E2E | `npm run test:e2e` |

Szczegóły architektury: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Dziennik postępu: [`PROGRESS.md`](PROGRESS.md).
