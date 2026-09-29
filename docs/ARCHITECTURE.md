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

## ADR-009: Standardy kodu i analiza statyczna

**Decyzja.**
- PHPCS: ruleset `WordPress` (WPCS 3.x: Core + Docs + Extra) + `PHPCompatibilityWP` (`testVersion 8.1-`),
  `minimum_wp_version 6.5`, prefiksy `trmz`/`Terminarz`, text domain `terminarz`. Wyłączony
  `WordPress.Files.FileName` dla `src/` i `tests/php/` (nazwy plików PSR-4 zamiast `class-*.php`, ADR-001).
  W testach złagodzone reguły dokumentacji; bootstrap/config suity WP może definiować stałe WordPressa.
- PHPStan 2.x, **poziom 6, bez baseline'u**, z `szepeviktor/phpstan-wordpress` (przez `phpstan/extension-installer`)
  i `php-stubs/woocommerce-stubs` (dla integracji w M6). Analizowane: `terminarz.php`, `src/`, `tests/php/unit`
  (testy integracyjne zależą od klas biblioteki testów WP, których stubów nie ma). Stałe `TRMZ_*` dla PHPStana
  w `tests/phpstan/bootstrap.php`. `composer phpstan` podnosi limit pamięci do 1G (stuby WP/WC są duże).
- JS/CSS: ESLint 9 (flat config `eslint.config.cjs` rozszerzający domyślny z `@wordpress/scripts`, reguła
  `@wordpress/i18n-text-domain` = `terminarz`, reguły Playwright dla `tests/e2e`) i stylelint
  (`@wordpress/stylelint-config/scss-stylistic`, klasy CSS muszą zaczynać się od `trmz-` lub `wp-block-terminarz-`).

## ADR-010: Testy E2E (Playwright)

**Decyzja.**
- `@playwright/test` + `@wordpress/e2e-test-utils-playwright`, własny `playwright.config.js` (nie domyślny z wp-scripts,
  żeby kontrolować katalog testów `tests/e2e/specs`, reportery i `webServer`).
- Cel: środowisko **tests** `http://localhost:8889` (`.wp-env.tests.json`) — izolowane od ręcznej pracy na :8888,
  więc testy mogą swobodnie zmieniać stan (np. deaktywować WooCommerce). Nadpisywalne przez `WP_BASE_URL`.
  `webServer` uruchamia `npm run env:start:tests`, jeśli witryna nie odpowiada.
- Global setup loguje admina przez REST (`RequestUtils.setupRest()`), zapisuje storage state w `artifacts/`,
  ustawia stan bazowy (Terminarz i WooCommerce aktywne) i zapamiętuje rozmiar `debug.log`.
- `debug.log` czytany przez HTTP (`/wp-content/debug.log` w środowisku testowym) — bez dostępu do Dockera;
  smoke test sprawdza tylko wpisy powstałe w trakcie przebiegu. Lista wyjątków dla znanych wpisów zewnętrznych
  (`IGNORED` w `tests/e2e/utils/debug-log.js`) jest pusta i ma pozostać krótka.
- Helpery `activateWooCommerce()` / `deactivateWooCommerce()` (`tests/e2e/utils/woocommerce.js`); testy, które
  wyłączają WooCommerce, przywracają go po sobie.
- Jeden worker, bez równoległości (wspólna instancja WordPressa); w CI 1 retry, raport HTML jako artefakt.

## ADR-011: CI w GitHub Actions

**Decyzja.** Jeden workflow `ci.yml` (PR i push do `develop`/`main`) z niezależnymi jobami: `lint`, `phpstan`,
`unit` (macierz PHP 8.1 i 8.3), `build` (+ generowanie `.pot`), `integration` (wp-env tests + `composer test:integration`),
`e2e` (wp-env tests + Playwright, raport jako artefakt). Joby na `ubuntu-latest`; narzędzia PHP na hoście w wersji 8.1
(`config.platform.php` = 8.1), w kontenerach wp-env również PHP 8.1. Cache: `ramsey/composer-install` i `setup-node`
(`cache: npm`). Dla PR anulowane są poprzednie przebiegi tej samej gałęzi. Uprawnienia workflow: `contents: read`.

**Konsekwencje.** Joby wp-env są najwolniejsze (pobieranie obrazów, WordPressa i WooCommerce przy każdym przebiegu);
w razie potrzeby można dodać cache `~/.wp-env`.

## ADR-012: Schemat bazy danych i migracje

**Kontekst.** Rezerwacje wymagają zapytań zakresowych i atomowego zajmowania slotów — CPT/postmeta się do tego nie nadają.

**Decyzja.**
- Własne tabele InnoDB z prefiksem witryny: `{prefix}trmz_resources`, `trmz_services`, `trmz_service_resources` (pivot
  z kolejnością), `trmz_schedules`, `trmz_schedule_exceptions`, `trmz_bookings`. Definicje w
  `Terminarz\Infrastructure\Database\Schema` (format `dbDelta`), wersja w stałej `Schema::VERSION` i opcji `trmz_db_version`.
- Migracja: `Migrator` (moduł) na `plugins_loaded` (priorytet 5), gdy wersja w opcji ≠ wersja w kodzie, oraz przy aktywacji.
  Równoległe żądania serializuje blokada `trmz_db_migration_lock` (`add_option` jest atomowe; blokada starsza niż 5 min
  jest przejmowana). Po migracji akcja `trmz_schema_migrated`. Zmiana schematu = edycja `CREATE TABLE` + podbicie `VERSION`
  (dbDelta dodaje kolumny/indeksy, ale nie usuwa — usunięcia wymagają jawnego kroku migracji).
- Czas: momenty (`start_utc`, `end_utc`, `buffer_end_utc`, `hold_expires_at`, `created_at`, `updated_at`) jako `DATETIME` w UTC.
  Wyjątek: harmonogram tygodniowy (`weekday` ISO 1–7, `start_time`/`end_time` `TIME`) i wyjątki (`start_date`/`end_date`
  `DATE`, godziny w JSON `intervals`) są w **czasie lokalnym witryny** — to reguły „ścienne”, które przy zmianie czasu
  (DST) mają zachować godzinę lokalną; zamiana na UTC następuje w silniku dostępności.
- `trmz_schedules`: wiersz = przedział (`kind` `work`/`break`) dla zasobu i dnia tygodnia.
  `trmz_schedule_exceptions`: `resource_id` NULL = wyjątek globalny; `kind` `closed`/`custom_hours`; zakres dat włącznie.
- `trmz_bookings`: `public_id` (losowy, unikalny, do użycia w REST/URL zamiast sekwencyjnego `id`), `active_start_utc`
  (= `start_utc` dla rezerwacji blokujących slot, NULL dla anulowanych/wygasłych) z `UNIQUE (resource_id, active_start_utc)`
  — ostatnia linia obrony przed podwójną rezerwacją (NULL-e się nie kolidują). `cancel_token_hash` przechowuje tylko skrót
  tokenu anulowania. Indeks `resource_range (resource_id, start_utc, buffer_end_utc, status)` pod zapytania o zajętość.
- Multisite: tabele per witryna (`$wpdb->prefix`). Witryna bez tabel dostaje je leniwie przy pierwszym żądaniu (brak opcji
  wersji → migracja); pełna obsługa multisite w M8 (#61).
- `Schema::drop()` usuwa tabele i opcję — do użycia przez `uninstall.php`, gdy użytkownik zaznaczy usuwanie danych.
- PHPCS: reguły `DirectDatabaseQuery.*` wyłączone dla `src/Infrastructure/Database` i `src/Infrastructure/Persistence`
  (świadomie bez object cache — dane rezerwacji nie mogą być nieaktualne); reguły `PreparedSQL` pozostają aktywne.

**Konsekwencje.** Testy integracyjne: biblioteka testów WP zamienia `CREATE TABLE` w teście na `CREATE TEMPORARY TABLE`,
dlatego testy „czystej instalacji” używają osobnego prefiksu (`wptests_fresh_`), a prawdziwe tabele suity powstają
w bootstrapie (`plugins_loaded`).

## ADR-015: Model domeny (`Terminarz\Domain\Model`)

**Decyzja.**
- Obiekty niemutowalne: właściwości `public readonly`, walidacja w konstruktorach (wyjątek `Domain\Exception\InvalidValue`,
  wszystkie wyjątki domeny implementują `Domain\Exception\DomainError`). Zmiana = nowa instancja (`with_*()`).
  Komunikaty wyjątków są dla programisty (angielskie, nietłumaczone); adaptery REST/admin mapują je na przetłumaczone teksty.
- Czas absolutny: `TimeRange` — półotwarty `[start, end)`, oba końce normalizowane do **UTC** w konstruktorze.
  Czas lokalny (harmonogramy): `LocalTime` (minuty od północy 0–1440, format `HH:MM`, `24:00` = koniec dnia) i
  `TimeWindow` (`[start, end)` w obrębie jednego dnia — okna przez północ nie są wspierane).
- `WeeklySchedule`: godziny pracy i przerwy per dzień tygodnia ISO (1 = poniedziałek … 7 = niedziela);
  `windows_for()` = godziny pracy minus przerwy. Godziny pracy jednego dnia nie mogą się nakładać.
- `ScheduleException` (nie `Throwable` — „wyjątek od harmonogramu”): data lokalna `Y-m-d`, `resource_id` lub `null`
  (globalny), okna zastępcze; brak okien = dzień zamknięty. Okna wyjątku zastępują **zarówno godziny pracy, jak i przerwy**
  danego dnia. Wyjątek zasobu ma pierwszeństwo przed globalnym (stosuje silnik, #11).
- `BookableResource` zamiast `Resource` (`resource` jest słowem zastrzeżonym „soft” w PHP).
- `Service`: czas trwania 1–1440 min, cena `price_minor` (int, grosze; waluta sklepu nie jest częścią domeny),
  bufor po usłudze 0–1440 min.
- `Booking`: opcjonalny `public_id` (losowy identyfikator publiczny), `range` (UTC, bez bufora) + `buffer_after_minutes` (kopia bufora usługi z chwili rezerwacji, więc edycja
  usługi nie przesuwa istniejących blokad); `blocked_range()` = termin + bufor. `pending_payment` wymaga `hold_expires_at`.
  `blocks_slot_at($now)` — status aktywny i (dla `pending_payment`) wstrzymanie jeszcze nie wygasło; dzięki temu
  wygasłe wstrzymanie przestaje blokować slot od razu, zanim zadanie wygaszające zmieni status na `expired`.
- `BookingStatus` (enum, wartości zapisywane w bazie): maszyna stanów
  `pending_payment → pending | confirmed | cancelled | expired`, `pending → confirmed | cancelled`,
  `confirmed → cancelled | completed`; `cancelled`, `expired`, `completed` są końcowe. `is_active()` = pending,
  pending_payment, confirmed. Status `needs_attention` (np. płatność po wygaśnięciu wstrzymania) **nie** jest dodany —
  decyzja należy do M6 (dodanie przypadku enuma i przejść jest wsteczne kompatybilne).
- PHPCompatibility 9.3 nie rozumie enumów — dla plików z enumami wyłączone są dwie reguły w `phpcs.xml.dist`.

## ADR-013: Repozytoria i transakcje

**Decyzja.**
- Interfejsy repozytoriów w `Terminarz\Domain\Repository` (bez WordPressa), implementacje na `$wpdb` w
  `Terminarz\Infrastructure\Persistence` (`Wpdb*Repository`, wspólna baza `WpdbRepository`). Zapytania wyłącznie przez
  `$wpdb->prepare()` lub helpery `insert/update/delete`. Błąd bazy → `Infrastructure\Database\DatabaseError`
  (RuntimeException); naruszenie reguł domeny → wyjątki domeny (`EntityNotFound`, `EntityInUse`).
- `ResourceRepository`/`ServiceRepository`: CRUD; `save()` bez ID = INSERT, z ID = UPDATE (brak wiersza → `EntityNotFound`).
  Usunięcie zasobu/usługi, do których odwołują się rezerwacje (dowolny status), jest blokowane (`EntityInUse`) —
  zachowawczo, żeby nie gubić historii; usunięcie zasobu kasuje jego harmonogram, wyjątki i przypisania do usług.
  Kolumny spoza modelu domeny (`description`, `sort_order`, `user_id`, `currency`) mają wartości domyślne — do obsłużenia
  w panelu admina (M4).
- `ServiceRepository::assign_resources()` zapisuje kolejność preferencji (`sort_order` w pivocie) — wykorzystywaną
  przy wyborze „dowolnego” zasobu.
- `ScheduleRepository::for_resources()` i `ScheduleExceptionRepository::in_range()` ładują dane dla wielu zasobów jednym
  zapytaniem (brak N+1 w dostępności). Wiersze wyjątków obejmujące zakres dat są rozwijane do jednego
  `ScheduleException` na dzień (współdzielą ID wiersza).
- `Infrastructure\Database\Transaction::run()` — transakcja InnoDB (COMMIT / ROLLBACK + ponowne rzucenie wyjątku,
  do 3 prób przy deadlocku / lock wait timeout). Zagnieżdżenie → `SAVEPOINT`. Filtr `trmz_db_inside_external_transaction`
  (w bootstrapie testów integracyjnych = `true`) wymusza savepointy także na najwyższym poziomie, bo suita WP otwiera
  transakcję na każdy test, a drugi `START TRANSACTION` zatwierdziłby dane testu.
