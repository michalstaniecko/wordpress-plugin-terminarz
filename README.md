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
| Lint PHP (WPCS) | `composer lint` / `composer lint:fix` |
| Analiza statyczna (PHPStan, poziom 6) | `composer phpstan` |
| Lint JS/CSS (ESLint + stylelint) | `npm run lint` / `npm run lint:fix` |
| Plik `.pot` | `npm run i18n` (WP-CLI i18n z Composera, bez Dockera) |
| Tłumaczenia | `npm run i18n:update-po` (nowe stringi do `.po`), `npm run i18n:compile` (`.mo`/`.l10n.php`/JSON, po `npm run build`), `composer i18n:check` (ADR-047) |
| Testy PHP (unit + integration) | `composer test` (unit bez Dockera: `composer test:unit`; integration: `composer test:integration`, wymaga `npm run env:start:tests`; tylko test współbieżności: `composer test:integration -- --group concurrency`; multisite: `composer test:integration:multisite`) |
| Paczka ZIP | `npm run plugin-zip` (po `npm run build`; wynik w `dist/`), `npm run plugin-zip:test` (zawartość + instalacja na czystym WP w wp-env :8890) |
| Testy E2E (Playwright, środowisko tests :8889) | `npm run test:e2e` (pierwszy raz: `npx playwright install chromium`; raport: `playwright-report/`) |

Szczegóły architektury: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Dziennik postępu: [`PROGRESS.md`](PROGRESS.md).

## CI (GitHub Actions)

Workflow [`.github/workflows/ci.yml`](.github/workflows/ci.yml) uruchamia się dla PR i pushy do `develop` i `main`
(oraz ręcznie — `workflow_dispatch`). Status checks:

| Check | Co sprawdza |
|---|---|
| `Lint (PHP + JS/CSS)` | `composer lint` (WPCS 3 + PHPCompatibilityWP) i `npm run lint` (ESLint + stylelint) |
| `PHPStan (level 6)` | `composer phpstan` — zero błędów, bez baseline'u |
| `PHPUnit unit (PHP 8.1)`, `PHPUnit unit (PHP 8.3)` | `composer test:unit` na minimalnej i nowszej wersji PHP |
| `Build` | `npm run build`, testy JS, kontrola tłumaczeń (`composer i18n:check`, aktualność plików skompilowanych) |
| `PHPUnit integration (wp-env)` | `composer test:integration` w środowisku testowym wp-env (PHP 8.1, najnowszy WordPress); osobny krok: test współbieżności `--group concurrency` (równoległe procesy WP-CLI, brak podwójnych rezerwacji) |
| `E2E (Playwright)` | `npm run test:e2e` na wp-env; raport HTML i ślady jako artefakt `playwright-report` (14 dni) |

Zależności Composera i npm są cache'owane (`ramsey/composer-install`, `actions/setup-node` z `cache: npm`).
Każdy z tych checków musi być zielony przed mergem PR do `develop`.
