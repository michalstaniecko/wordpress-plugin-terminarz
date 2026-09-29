# Dziennik postępu

Jeden wpis na zamknięte issue: data, numer issue, co zrobione, decyzje, znane ryzyka.

## 2026-09-29 — #2 szkielet pluginu, autoload PSR-4 i bootstrap

- **Zrobione:** `terminarz.php` (nagłówek + bootstrap ze sprawdzeniem PHP 8.1 / WP 6.5 / autoloadera),
  `composer.json` z PSR-4 `Terminarz\` → `src/`, `Terminarz\Plugin` (kontener modułów), kontrakt `Module`,
  moduł `I18n`, `Capabilities` (`trmz_manage_bookings`), `Lifecycle` (aktywacja/deaktywacja),
  katalogi warstw, `languages/`, `docs/ARCHITECTURE.md` (ADR-001…004), `.gitignore`, `.editorconfig`, `.distignore`.
- **Decyzje:** ADR-001…ADR-004. `composer.lock` wersjonowany (powtarzalne buildy).
- **Ryzyka:** testy, lint i phpstan powstają w #4/#5, CI w #7 — dla tego issue weryfikacja ograniczona do
  `php -l` i ręcznego przeglądu; test integracyjny aktywacji dochodzi w #4. Capability dla witryn tworzonych
  po aktywacji sieciowej (multisite) — M8.

## 2026-09-29 — #3 środowisko wp-env i konfiguracja npm

- **Zrobione:** `package.json` (skrypty `env:start`/`env:stop`/`env:destroy`, `build`, `start`, `lint`, `test:e2e`, `i18n`),
  `.wp-env.json` (dev, 8888) i `.wp-env.tests.json` (tests, 8889) z WooCommerce latest-stable, `WP_DEBUG`/`WP_DEBUG_LOG`;
  placeholder `blocks/index.js`; `wp-cli/i18n-command` i wygenerowany `languages/terminarz.pot`; README z instrukcją.
  Poprawka bootstrapu: komunikaty o wymaganiach tłumaczone dopiero w `admin_notices` (bez wczesnego ładowania tłumaczeń).
- **Decyzje:** ADR-005 (dwa pliki konfiguracyjne zamiast przestarzałego `testsEnvironment`, stałe slugi przez `mappings`,
  PHP 8.1 w kontenerach), ADR-006 (.pot przez Composer), ADR-007 (placeholder bloków).
- **Ryzyka:** WooCommerce „latest-stable” zmienia się w czasie — testy mogą zacząć się sypać po wydaniu WC
  (ew. przypięcie wersji później). WooCommerce 11.x zapisuje w `debug.log` notice `_load_textdomain_just_in_time`
  — smoke test E2E (#6) musi to uwzględnić. Skrypty `lint`/`test:e2e` są konfigurowane w #5/#6.

## 2026-09-29 — #4 PHPUnit: suity unit i integration

- **Zrobione:** PHPUnit 9.6 + polyfills, `phpunit.xml.dist` (unit) i `phpunit-integration.xml.dist` (integration),
  bootstrapy, własny `wp-tests-config.php` (prefiks `wptests_`), skrypty `composer test`, `test:unit`, `test:integration`,
  autoload-dev `Terminarz\Tests\`. Testy: `PluginTest` (unit), `LifecycleTest` (integration — capability po aktywacji,
  deaktywacja jej nie zdejmuje, `revoke()` czyści wszystkie role).
- **Decyzje:** ADR-008 (biblioteka testów WP z wp-env zamiast `wp-phpunit/wp-phpunit`, osobny prefiks tabel).
- **Ryzyka:** suita integration wymaga Dockera/wp-env; poza wp-env trzeba ustawić `WP_TESTS_DIR` i `WORDPRESS_DB_*`.

## 2026-09-29 — #5 PHPCS (WPCS), PHPStan poziom 6 i lint JS/CSS

- **Zrobione:** `phpcs.xml.dist` (WPCS 3.4 + PHPCompatibilityWP), `phpstan.neon.dist` (poziom 6, phpstan-wordpress,
  stuby WooCommerce), `eslint.config.cjs`, `.stylelintrc.json`; skrypty `composer lint`, `lint:fix`, `phpstan`,
  `npm run lint`, `lint:fix`. Cały obecny kod przechodzi bez błędów i bez baseline'u.
- **Decyzje:** ADR-009 (wyłączenie `WordPress.Files.FileName` dla PSR-4, prefiks klas CSS, zakres analizy PHPStana).
- **Ryzyka:** `phpcompatibility-wp` 2.1 opiera się na PHPCompatibility 9.3 (nie zna części składni PHP 8.x —
  wykrywa głównie użycie zbyt nowych funkcji; realną zgodność zapewnia macierz PHP w CI). Testy integracyjne
  nie są analizowane przez PHPStana.

## 2026-09-29 — #6 Playwright E2E

- **Zrobione:** `playwright.config.js` (baseURL :8889, `webServer` → `env:start:tests`), global setup (logowanie admina,
  storage state, stan bazowy pluginów, offset `debug.log`), helpery `debug-log.js` i `woocommerce.js`,
  smoke testy: plugin aktywny na liście pluginów, działa po deaktywacji WooCommerce, brak PHP notice/warning w `debug.log`.
  Skrypty `test:e2e`, `test:e2e:debug`, `test:e2e:install`.
- **Decyzje:** ADR-010 (środowisko tests :8889, odczyt `debug.log` przez HTTP, seryjne wykonanie).
- **Ryzyka:** odczyt `debug.log` przez HTTP działa tylko dlatego, że środowisko testowe serwuje `wp-content/`
  — gdyby wp-env to zmienił, trzeba przejść na `wp-env run cli cat`. Sprawdzone negatywnie (wstrzyknięty
  `E_USER_WARNING` powoduje porażkę testu).
