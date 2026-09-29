# Architektura — plugin „Terminarz”

Dziennik decyzji architektonicznych w formie ADR-lite. Każdy wpis: kontekst → decyzja → konsekwencje.
Nowe decyzje dopisujemy na końcu; nieaktualne oznaczamy jako „Zastąpione przez ADR-NNN”.

## ADR-001: Warstwy kodu i autoload PSR-4

**Kontekst.** Silnik dostępności i reguły kolizji muszą być testowalne czystym PHPUnit, bez WordPressa.

**Decyzja.** Kod w `src/` z autoloadem Composer PSR-4 (`Terminarz\` → `src/`), podzielony na:

- `Domain/` — czysta logika domenowa; **zakaz** wywołań funkcji/klas WordPressa.
- `Infrastructure/` — integracja z WP (hooki, cykl życia, capability, i18n, a później repozytoria na `$wpdb`).
- `Rest/`, `Admin/` — adaptery wejścia (REST API, ekrany admina).
- `Integrations/WooCommerce/` — opcjonalna integracja ładowana tylko, gdy WooCommerce jest aktywny.

**Konsekwencje.** Plugin wymaga `vendor/autoload.php` — w repozytorium po `composer install`, w paczce ZIP
budowanej przez CI (`composer install --no-dev`). Brak autoloadera daje komunikat w adminie zamiast fatal error.

## ADR-002: Bootstrap i sprawdzanie wymagań

**Decyzja.** `terminarz.php` zawiera tylko nagłówek, stałe (`TRMZ_VERSION`, `TRMZ_FILE`, …), sprawdzenie
minimalnych wersji PHP 8.1 / WP 6.5 oraz obecności autoloadera i wywołanie `Terminarz\Plugin::instance()->boot()`.
Plik jest pisany składnią parsowalną przez stare PHP, żeby na zbyt starym PHP pokazać `admin_notice`
zamiast błędu parsera. Sprawdzenie następuje **przed** załadowaniem autoloadera Composera (jego `platform_check.php`
kończyłby się błędem krytycznym).

## ADR-003: Kontener modułów

**Decyzja.** `Terminarz\Plugin` przechowuje listę obiektów implementujących `Terminarz\Infrastructure\Module`
(`register(): void` — tylko rejestracja hooków). `boot()` jest idempotentne. Bez zewnętrznego kontenera DI —
zależności składamy ręcznie w `Plugin::default_modules()`; w razie wzrostu złożoności można wprowadzić fabrykę.

## ADR-004: Capability `trmz_manage_bookings`

**Decyzja.** Wszystkie akcje administracyjne chroni własna capability `trmz_manage_bookings`
(`Terminarz\Infrastructure\Capabilities::MANAGE_BOOKINGS`). Aktywacja nadaje ją roli `administrator`
(przy aktywacji sieciowej na multisite — w każdej istniejącej witrynie). Deaktywacja niczego nie usuwa;
capability zdejmuje dopiero `uninstall.php` (`Capabilities::revoke()`), zgodnie z ustawieniem usuwania danych.

**Konsekwencje.** Witryny utworzone w sieci *po* aktywacji sieciowej nie dostaną capability automatycznie —
do obsłużenia w M8 (testy multisite).

## ADR-005: Środowiska wp-env (dev 8888, tests 8889)

**Kontekst.** `@wordpress/env` 11.x oznaczył wbudowane środowisko testowe (`testsEnvironment`, kontenery `tests-*`)
jako przestarzałe i zaleca osobny plik konfiguracyjny uruchamiany z `--config`.

**Decyzja.**
- `.wp-env.json` — środowisko deweloperskie na porcie 8888; `.wp-env.tests.json` — osobne, izolowane środowisko
  testowe na porcie 8889 (własne kontenery i baza). Oba z `"testsEnvironment": false`.
- Plugin montowany przez `mappings` jako `wp-content/plugins/terminarz` (stały slug niezależny od nazwy katalogu repo),
  aktywowany skryptem `lifecycleScripts.afterStart`.
- WooCommerce montowany przez `mappings` z `woocommerce.latest-stable.zip` jako `wp-content/plugins/woocommerce`
  (stały slug; `woocommerce.zip` z wordpress.org potrafi wskazywać wersję beta) i aktywowany w `afterStart`.
  Testy, które wymagają środowiska bez WooCommerce, deaktywują go jawnie (helper E2E w #6).
- `phpVersion: 8.1` — testy integracyjne i E2E działają na minimalnej wspieranej wersji PHP; `core: null` (najnowszy WP).
- `WP_DEBUG` + `WP_DEBUG_LOG` włączone, `WP_DEBUG_DISPLAY` wyłączone (notice'y trafiają do `debug.log`,
  nie psują odpowiedzi REST/HTML; smoke test E2E sprawdza log).

**Konsekwencje.** `npm run env:start` uruchamia dwa środowiska (dłuższy start); CI startuje tylko środowisko testowe.

## ADR-006: Generowanie `.pot` bez Dockera

**Decyzja.** `npm run i18n` → `composer run i18n` → `wp i18n make-pot` z pakietu `wp-cli/i18n-command` (dev-dependency).
Dzięki temu `.pot` generuje się lokalnie i w CI bez uruchamiania wp-env. Plik `languages/terminarz.pot` jest wersjonowany.

## ADR-007: Build bloków

**Decyzja.** `@wordpress/scripts` z `--webpack-src-dir=blocks --output-path=build`. Do czasu powstania pierwszego bloku (M5)
`blocks/index.js` jest pustym punktem wejścia, żeby `npm run build` przechodził; wynik nie jest nigdzie ładowany.

## ADR-008: Dwie suity PHPUnit

**Decyzja.**
- PHPUnit **9.6** + `yoast/phpunit-polyfills` 4.x — najwyższa wersja PHPUnit w pełni wspierana przez bibliotekę testów
  WordPressa; działa na PHP 8.1–8.4.
- `unit` (`phpunit.xml.dist`, `tests/php/unit`) — bez WordPressa i bazy; bootstrap ładuje wyłącznie autoloader Composera.
  Tu trafiają testy `src/Domain`. `composer test:unit` działa bez Dockera.
- `integration` (`phpunit-integration.xml.dist`, `tests/php/integration`) — biblioteka testów WordPressa dostarczana przez
  wp-env (`WP_TESTS_DIR`, zgodna z wersją core w kontenerze), uruchamiana w kontenerze `cli` środowiska testowego:
  `composer test:integration`. Plugin ładowany na `muplugins_loaded`.
- Własny `tests/php/integration/wp-tests-config.php` z prefiksem tabel `wptests_` — domyślny config wp-env używa `wp_`,
  a instalator suity czyści tabele o tym prefiksie, co zniszczyłoby witrynę E2E w tej samej bazie. Parametry bazy
  i `ABSPATH` można nadpisać zmiennymi środowiskowymi (`WORDPRESS_DB_*`, `TRMZ_TESTS_ABSPATH`, `WP_TESTS_DIR`),
  żeby uruchomić suitę poza wp-env.
- Odejście od rekomendacji `wp-phpunit/wp-phpunit`: biblioteka testów z wp-env jest zawsze zgodna z wersją core,
  a pakiet Composera trzeba by synchronizować ręcznie.
- `composer test` = unit + integration (wymaga uruchomionego `npm run env:start:tests`).
