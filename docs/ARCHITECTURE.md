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

**Decyzja.** `@wordpress/scripts` z `--webpack-src-dir=blocks --output-path=build`; punkty wejścia są wykrywane z plików
`block.json` (placeholder `blocks/index.js` usunięty w M5, #29). `build/` nie jest wersjonowany — buduje go CI (joby build,
integration, e2e) i pakowanie wydania (M8). Własny `webpack.config.js` rozszerza konfigurację domyślną tylko o alias
`trmz-bundled-api-fetch` (ADR-031).

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
  w `tests/phpstan/bootstrap.php`. `composer phpstan` podnosi limit pamięci do 2G (stuby WP/WC są duże; 1G przestał wystarczać w CI w M6).
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

## ADR-016: Silnik dostępności (`Terminarz\Domain\Availability`)

**Kontekst.** Wolne sloty muszą uwzględniać harmonogram lokalny, wyjątki, przerwy, bufory, istniejące rezerwacje,
minimalne wyprzedzenie, horyzont i zmianę czasu — deterministycznie i bez WordPressa (testy czystym PHPUnit).

**Decyzja.**
- Wejście: `AvailabilityQuery` — lista `ResourceCalendar` (id zasobu, `WeeklySchedule`, wyjątki zasobu i globalne,
  zajęte przedziały UTC **już z buforami**), czas trwania i bufor usługi (`for_service()`), strefa witryny (`DateTimeZone`),
  zakres dat lokalnych `from`–`to` (włącznie, maks. 366 dni), `now`, minimalne wyprzedzenie (min), maksymalny horyzont
  (dni lokalne, `null` = bez limitu), krok siatki (domyślnie 15 min). `now` i strefa są wstrzykiwane.
  Wyjście: `AvailabilityEngine::find_slots()` → `list<Slot>` (UTC, `[start, start + czas trwania)`), posortowane
  po starcie, potem po id zasobu.
- Zajętość jest jawna: `BusyIntervals::from_bookings($bookings, $now)` bierze tylko rezerwacje, które blokują slot w chwili
  `now` (`Booking::blocks_slot_at()` — statusy aktywne, `pending_payment` tylko przed `hold_expires_at`) i zwraca
  `blocked_range()` (termin + bufor). Repozytorium może też zwracać gotowe przedziały, o ile stosuje tę samą regułę.
- Okna dnia: wyjątek zasobu > wyjątek globalny > harmonogram tygodniowy minus przerwy.
- Slot w chwili `s` blokuje `[s, s + czas + bufor)`; musi mieścić się w całości (z buforem) w oknie pracy,
  zaczynać się nie wcześniej niż `now + wyprzedzenie` i nie nakładać się na zajętość (półotwarte — sąsiadujące terminy są OK).
- Siatka: starty co `krok` liczone od początku każdego okna; po kolizji skok do pierwszego punktu siatki ≥ końca zajętości.
- Horyzont: ostatni dostępny dzień = lokalna data `now` + N dni (dni liczone w strefie witryny, nie w UTC).
- **DST** (`WallClock`): granice okien lokalnych przeliczane na UTC regułą monotoniczną — godzina powtórzona
  (cofnięcie zegara) → **pierwsze** wystąpienie; godzina nieistniejąca (przesunięcie do przodu) → **chwila zmiany czasu**
  (pierwsza istniejąca godzina po luce). Sloty generowane są na osi czasu rzeczywistego (UTC) wewnątrz tak przeliczonych
  okien: w dniu zmiany „na letni” okno 00:00–06:00 daje 5 godzin slotów i żadnego o 02:xx, w dniu zmiany „na zimowy” —
  7 godzin, a powtórzona godzina lokalna pojawia się dwa razy jako różne chwile UTC (bez duplikatów). Własna implementacja,
  bo `DateTimeImmutable` przesuwa nieistniejące godziny o długość luki, a wybór dla godzin powtórzonych zależy od wersji PHP.
- Wydajność: zajętość sortowana i scalana raz, przeglądana wskaźnikiem tylko do przodu — koszt liniowy
  (okna + sloty + rezerwacje). 30 dni × 10 zasobów ≈ kilka ms (test pilnuje < 300 ms).

**Konsekwencje.** Harmonogramy i wyjątki są w czasie lokalnym (ADR-012), więc zmiana strefy witryny przesuwa godziny
w UTC, ale nie istniejące rezerwacje (te są w UTC). Wyjątki z zakresem dat (`start_date`–`end_date` w bazie) repozytorium
rozwija do obiektów `ScheduleException` per dzień.

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

## ADR-014: Atomowe zajmowanie slotu i warstwa aplikacyjna rezerwacji

**Kontekst.** Dwa równoległe żądania nie mogą zarezerwować nakładających się terminów (z buforami) tego samego zasobu.

**Decyzja.**
- `Domain\Repository\BookingRepository` (implementacja `Infrastructure\Persistence\WpdbBookingRepository`):
  `create()` i `reschedule()` w transakcji InnoDB (`Transaction::run()`):
  1. `SELECT id FROM trmz_resources WHERE id = %d FOR UPDATE` — blokada wiersza zasobu serializuje zapisy per zasób
     (różne zasoby nie czekają na siebie);
  2. zwykły odczyt nakładających się aktywnych rezerwacji (`active_start_utc IS NOT NULL`, `start_utc < nowy_koniec_z_buforem`,
     `buffer_end_utc > nowy_start`) — snapshot REPEATABLE READ powstaje dopiero po uzyskaniu blokady, więc widzi rezerwacje
     zatwierdzone przez poprzedniego posiadacza blokady; bez odczytów blokujących zakresy (unikamy gap locków i deadlocków);
  3. `pending_payment` z wygasłym `hold_expires_at` nie blokuje — jest po drodze oznaczana jako `expired` (UPDATE po PK),
     każda inna kolizja → `Domain\Exception\SlotUnavailable`;
  4. INSERT/UPDATE; błąd duplikatu indeksu `resource_active_start` → `SlotUnavailable` (nie 500). Błędy są tłumione
     (`suppress_errors`), żeby oczekiwany konflikt nie trafiał do logu PHP.
  Kolejność blokad zawsze: wiersz zasobu → wiersze rezerwacji. Deadlock / lock wait timeout → ponowienie całej transakcji.
- Zmiana statusu (`change_status()`) waliduje przejście maszyną stanów `BookingStatus`; status nieaktywny zeruje
  `active_start_utc` (zwalnia slot). `busy_ranges()` zwraca zajętość (termin + bufor) wielu zasobów jednym zapytaniem, tą samą
  regułą co `Booking::blocks_slot_at()`. `expire_holds()` — dla przyszłego crona (M6).
- Warstwa aplikacyjna `Terminarz\Application` (bez funkcji WordPressa; zależności wstrzykiwane):
  `BookingService` (`reserve()`, `reschedule()`, `change_status()`, `cancel()`, `expire_holds()`, `verify_cancel_token()`),
  `Clock` (`SystemClock`, `FixedClock`), `EventDispatcher` (implementacja `Infrastructure\WpEventDispatcher` → `do_action`),
  `Reservation` (rezerwacja + jednorazowo jawny token anulowania; w bazie tylko SHA-256).
  `reserve()` sprawdza: usługa istnieje i jest aktywna, zasób aktywny i przypisany do usługi, start w przyszłości,
  opcjonalna polityka slotu (`set_slot_policy()`, podpinana przez `AvailabilityService` w #15), potem atomowy `create()`.
  `public_id` = 32 znaki hex (128 bitów losowości).
- Zdarzenia (akcje WP): `trmz_booking_created` (Booking), `trmz_booking_status_changed` (Booking, BookingStatus poprzedni),
  `trmz_booking_rescheduled` (Booking, Booking poprzedni). Wygaszenie wstrzymania „po drodze” w `create()` nie emituje
  zdarzenia (robi to dopiero `expire_holds()`).
- `Infrastructure\Services` — ręczny composition root (ADR-003): `Services::instance()->booking_service()`, repozytoria itd.

**Konsekwencje.** Wymagane InnoDB (ADR-012). W testach integracyjnych transakcje działają na savepointach (ADR-013),
więc prawdziwą współbieżność sprawdza osobny test procesowy (#14).

## ADR-017: Test współbieżności rezerwacji

**Decyzja.** `tests/php/integration/Concurrency/DoubleBookingTest.php` (`@group concurrency`) uruchamia N (domyślnie 12,
min. 10) osobnych procesów `wp eval-file tests/php/concurrency/worker.php reserve …` — każdy z własnym połączeniem MySQL
i prawdziwymi transakcjami InnoDB (poza transakcją suity PHPUnit), na prawdziwych tabelach witryny wp-env. Procesy
synchronizuje bariera czasowa (wspólny znacznik czasu w przyszłości, domyślnie +8 s na bootstrap WordPressa); test
sprawdza, że nikt nie spóźnił się na barierę i że starty mieszczą się w 0,5 s. Scenariusze: ten sam slot (dokładnie
1 sukces), nakładające się sloty o różnych startach/usługach/długościach (dokładnie 1 sukces), różne zasoby
(wszystkie sukcesy — brak fałszywych konfliktów i deadlocków). Każda porażka musi być czystym `SlotUnavailable`.
Dane testu są usuwane w `tear_down_after_class`. Kontrola negatywna: po usunięciu blokady wiersza zasobu scenariusz
„różne starty” kończy się wieloma sukcesami (test to wykrywa).
W CI osobny krok joba `integration`. Zmienne: `TRMZ_CONCURRENCY_WORKERS`, `TRMZ_CONCURRENCY_BARRIER`, `TRMZ_SKIP_CONCURRENCY=1`.

## ADR-018: AvailabilityService i wybór „dowolnego” zasobu

**Decyzja.**
- Warstwa aplikacyjna w `src/Application` (namespace `Terminarz\Application`) — orkiestruje repozytoria (interfejsy
  z `Domain\Repository`) i czysty silnik (`Domain\Availability`), bez funkcji WordPressa; parametry z WP (strefa witryny,
  opcje) wstrzykuje `Infrastructure\Services` jako `AvailabilitySettings`.
- `AvailabilityService`:
  - `slots($service_id, $from, $to, ?$resource_id, ?$exclude_booking_id)` — sloty (UTC) każdego aktywnego zasobu usługi
    (lub jednego zasobu), posortowane po starcie i id zasobu;
  - `any_resource_slots($service_id, $from, $to)` — jeden slot na start, zasób wybrany strategią (przypisanie wstępne);
  - `free_resources_at($service_id, $start, ?$exclude)` — wolne zasoby dla startu w kolejności strategii;
  - `is_available($service_id, $resource_id, $start, ?$exclude)` — polityka slotu dla `BookingService`.
  Stała liczba zapytań niezależnie od liczby zasobów i dni (≤ 6: usługa, przypisania, zasoby, harmonogramy, wyjątki,
  zajętość) — brak N+1. Zakres dat walidowany (maks. 366 dni) przed dostępem do bazy. Zajętość pobierana z marginesem
  ±1 dzień wokół lokalnego zakresu (strefy/DST).
- Strategia „dowolny zasób” (`AvailabilitySettings::$strategy`, opcja `trmz_settings[any_resource_strategy]`):
  `order` (domyślna) — pierwszy wolny zasób wg kolejności preferencji z przypisania usługi (`sort_order` w pivocie);
  `least_busy` — wolny zasób z najmniejszą liczbą zajętych minut (z buforami) w danym dniu lokalnym, remis → kolejność.
  Przypisanie w `any_resource_slots()` jest tylko podpowiedzią; ostateczne następuje w `BookingService::reserve_any()`,
  który próbuje kolejnych kandydatów z `free_resources_at()` i przy przegranym wyścigu (`SlotUnavailable`) bierze następny.
- `BookingService::use_availability()` (wołane przez `Services::booking_service()`) włącza walidację każdej rezerwacji
  i przeniesienia względem silnika (godziny, wyjątki, wyprzedzenie, horyzont, siatka); przy przenoszeniu własny termin
  rezerwacji nie liczy się jako zajęty (`exclude_booking_id`, także w `BookingRepository::busy_ranges()`).
- Ustawienia: `wp_timezone()` + opcja `trmz_settings` (`min_lead_minutes` = 0, `max_horizon_days` = brak, `slot_step_minutes` = 15,
  `any_resource_strategy` = `order`); nieprawidłowe wartości → domyślne; filtr `trmz_availability_settings`.
  Formularz ustawień — M4.
- Benchmark (test integracyjny `@group benchmark`): 30 dni × 10 zasobów, ~1000 rezerwacji, `slots()` + `any_resource_slots()`:
  ~21 ms lokalnie; próg `TRMZ_BENCH_MAX_MS` (domyślnie 300 ms, w CI 1000 ms).

## Publiczne API warstwy domeny/aplikacji (dla adapterów REST/admin)

- Wejście: `Terminarz\Infrastructure\Services::instance()` → `availability_service()`, `booking_service()`, `resources()`,
  `services()`, `schedules()`, `schedule_exceptions()`, `bookings()`, `clock()`, `availability_settings()`.
- Wyjątki do mapowania na HTTP: `SlotUnavailable` → 409, `EntityNotFound` → 404, `InvalidValue` / `InvalidStatusTransition` → 400/422,
  `EntityInUse` → 409; `Infrastructure\Database\DatabaseError` → 500. Komunikaty wyjątków są angielskie (dla programisty) —
  adapter pokazuje własne, przetłumaczone teksty.
- Identyfikator rezerwacji na zewnątrz: `Booking::$public_id` (`BookingRepository::get_by_public_id()`); token anulowania
  dostępny tylko w `Reservation::$cancel_token` zaraz po rezerwacji, weryfikacja `BookingService::verify_cancel_token()`.
- Ustawienia: `Services::instance()->settings()` (`Infrastructure\Settings`, gettery z ADR-024), np. `->auto_confirm()`.
- Hooki: `trmz_booking_created`, `trmz_booking_status_changed`, `trmz_booking_rescheduled`, `trmz_schema_migrated`;
  filtry: `trmz_availability_settings`, `trmz_db_inside_external_transaction`.

## ADR-019: Warstwa REST (`Terminarz\Rest`, `terminarz/v1`)

**Decyzja.**
- Moduł `Rest\RestModule` (w `Plugin::default_modules()`) rejestruje kontrolery na `rest_api_init`. Kontrolery rozszerzają
  `Rest\Controller` (← `WP_REST_Controller`): namespace `terminarz/v1`, schemat (`get_item_schema()`), walidacja argumentów
  przez `args` (JSON Schema WordPressa), zależności z `Infrastructure\Services::instance()` pobierane w czasie żądania
  (testy podmieniają kontener przez `Services::set_instance()`).
- Każda trasa ma jawny `permission_callback`: publiczny odczyt → `Controller::public_read_permissions_check()` (nazwana metoda,
  nie `__return_true`); administracja → `manage_permissions_check()` (`trmz_manage_bookings`, 401 dla anonima / 403 dla
  zalogowanego bez uprawnienia — `rest_authorization_required_code()`).
- Odpowiedzi publiczne zawierają wyłącznie pola ze schematu (bez `user_id`, `sort_order`, buforów, danych innych klientów).
  Nieaktywne usługi/zasoby są publicznie nieodróżnialne od nieistniejących (404 `trmz_service_not_found`).
- Wyjątki → `Rest\ErrorMapper::to_wp_error()`: stały kod błędu + status HTTP + ogólny, przetłumaczony komunikat
  (`SlotUnavailable`/`EntityInUse` 409, `EntityNotFound` 404, `InvalidStatusTransition` 422, `InvalidValue` 400,
  `DatabaseError` 500). Angielskie komunikaty wyjątków (ADR-015) nigdy nie trafiają do klienta.
- Czasy w odpowiedziach: ISO 8601 z offsetem strefy witryny (`DATE_RFC3339`) + odpowiednik UTC (`…Z`).

**Konsekwencje.** Katalog nie zawiera jeszcze opisu usługi/zasobu ani waluty (kolumny spoza modelu domeny — ADR-013);
dojdą razem z panelem admina (M4) / WooCommerce (M6) jako nowe pola schematu (zmiana wstecznie kompatybilna).

## ADR-020: Endpoint dostępności (`GET /terminarz/v1/availability`)

**Decyzja.**
- Parametry: `service` (wymagany), `resource` = ID lub `any` (domyślnie), `from`/`to` — **daty lokalne** witryny `Y-m-d`
  (włącznie, maks. 31 dni; walidacja przed dostępem do bazy). Nieaktywna/nieistniejąca usługa → 404, zasób nieprzypisany,
  nieaktywny lub nieistniejący → 400 `trmz_invalid_resource`, błędne daty → 400 `trmz_invalid_date` / `trmz_invalid_range`.
- Odpowiedź: `days[]` zawiera **każdy** dzień zakresu (także bez slotów — UI kalendarza nie musi liczyć dni), sloty
  `{start, end, start_utc, resource}`; `start`/`end` w ISO 8601 z offsetem strefy witryny (offset zmienia się w dniu DST),
  `start_utc` jako źródło prawdy do wysłania przy rezerwacji. Dla `any` jeden slot na start i `resource: null` —
  przydział zasobu jest wstępny (ADR-018), ostateczny wybór następuje w chwili rezerwacji.
- Cache: domyślnie `Cache-Control: no-store` (dostępność zmienia się z każdą rezerwacją); filtr
  `trmz_availability_cache_max_age` (sekundy) pozwala na `public, max-age=N` — rezerwacja i tak weryfikuje slot ponownie.
- Wydajność: benchmark przez REST (30 dni × 10 zasobów, ~1200 rezerwacji) ~11 ms lokalnie; próg `TRMZ_BENCH_MAX_MS`.

## ADR-021: Tworzenie rezerwacji przez REST (`POST /terminarz/v1/bookings`)

**Decyzja.**
- Body (JSON): `service`, `resource` (`id` | `any`, domyślnie `any`), `start` (ISO 8601 **z jawnym offsetem** lub `Z`,
  np. `start_utc` slotu — czas lokalny bez offsetu jest niejednoznaczny przy DST, więc jest odrzucany), `name`, `email`,
  `phone`, `note`, `consent` (musi być `true`), `website` (honeypot). Walidacja schematem WP (`maxLength` = rozmiar kolumn,
  `format: email`, wzorzec telefonu), sanitizacja `sanitize_text_field` / `sanitize_email` / `sanitize_textarea_field`.
- Slot jest weryfikowany ponownie po stronie serwera: `BookingService::reserve()` / `reserve_any()` z polityką dostępności
  (ADR-018) i atomowym zapisem (ADR-014). Kody: 201, 400 (dane, zgoda, honeypot `trmz_rejected` bez szczegółów),
  404 (usługa), 409 (`trmz_slot_unavailable` — zajęty, poza godzinami, w przeszłości, poza siatką).
- `permission_callback` = `create_item_permissions_check()`: zalogowany użytkownik (uwierzytelnienie ciasteczkiem) musi
  wysłać poprawny nonce `wp_rest` (ochrona CSRF; przy Application Passwords nonce nie jest wymagany) + limit zapytań (ADR-022).
- Status początkowy: `confirmed`, gdy filtr `trmz_auto_confirm_bookings` (bool, `Service`) zwróci `true`, inaczej `pending`.
  Wartość domyślna filtra = ustawienie `auto_confirm` z panelu (`Settings::auto_confirm()`, ADR-024). `pending_payment` — M6.
- Odpowiedź publiczna: `public_id`, `status`, `service`, `resource` (przydzielony), `start`, `end`, `start_utc` — bez danych
  klienta, wewnętrznego ID i tokenu anulowania (token trafi do e-maila w M7).
- `Customer` rozszerzony o `note` i `user_id` (kolumny `customer_note`, `customer_user_id` istniały w schemacie) — zalogowany
  klient jest zapisywany z ID konta.

**Konsekwencje.** Jawny token anulowania istnieje tylko w `Reservation` zwracanym przez `BookingService` — REST go odrzuca,
więc M7 musi wysłać e-mail w tym samym żądaniu (np. `BookingService` przekaże token do powiadomień) albo wygenerować nowy
token przy wysyłce.

## ADR-022: Limit zapytań dla publicznych endpointów zapisu

**Decyzja.**
- `Infrastructure\RateLimiter` — okno stałe per (kubełek, klient) w transientach (object cache, jeśli jest). Klucz
  `trmz_rl_` + HMAC-SHA256(kubełek|IP, `wp_salt('nonce')`) — adres IP nie jest zapisywany. Czas z `Clock` (testowalny).
  Inkrementacja nie jest atomowa (odczyt → zapis): przy dużej współbieżności może przepuścić kilka dodatkowych żądań —
  akceptowalne dla ochrony przed nadużyciami.
- `Rest\RequestLimit::check($bucket, $request)` — wołane z `permission_callback` endpointów zapisu (po walidacji schematu,
  więc liczy się każde poprawne składniowo żądanie — także zakończone 409, co utrudnia sondowanie slotów).
  Kubełki: `booking_create` (M3), w M7 anulowanie (nowa stała, ten sam mechanizm).
- Limit: filtr `trmz_rate_limit` (`array{limit, window}`, kubełek, żądanie), domyślnie 5 / 600 s; `limit <= 0` wyłącza.
  Użytkownicy z `trmz_manage_bookings` nie są limitowani.
- IP: wyłącznie `REMOTE_ADDR`; nagłówki proxy tylko przez filtr `trmz_client_ip` (zaufany reverse proxy). Niepoprawny
  adres → wspólny klucz `unknown`.
- Przekroczenie: 429 `trmz_rate_limited` + nagłówek `Retry-After` (sekundy do końca okna). `permission_callback` nie może
  ustawiać nagłówków, więc filtr `rest_request_after_callbacks` (rejestrowany przez `RestModule`) zamienia ten błąd
  na odpowiedź z nagłówkiem.

**Konsekwencje.** Za reverse proxy/CDN bez skonfigurowanego filtra wszyscy klienci mają to samo `REMOTE_ADDR` i dzielą
limit — trzeba to opisać w dokumentacji wydania (M8, readme).

## ADR-023: Administracyjne endpointy rezerwacji

**Decyzja.**
- `Rest\AdminBookingsController` (wszystko za `manage_permissions_check()` — `trmz_manage_bookings`; 401 anonim, 403 bez
  uprawnienia): `GET /bookings` (filtry `status[]`, `service`, `resource`, `from`/`to` — daty lokalne startu, `search` —
  fragment imienia/e-maila lub dokładny `public_id`; `orderby` `start|created`, `order`, `page`, `per_page` ≤ 100; nagłówki
  `X-WP-Total`, `X-WP-TotalPages`), `GET /bookings/{id}`, `POST /bookings/{id}/confirm|cancel|reschedule`.
- `{id}` to wewnętrzne ID (tylko dla panelu); reprezentacja admina zawiera `public_id`, dane klienta, `allowed_transitions`
  (z maszyny stanów — UI może pokazać tylko dozwolone akcje), czasy lokalne i UTC; nigdy skrótu tokenu anulowania.
- Trasa `/bookings` jest współdzielona z publicznym `POST` (WordPress scala endpointy tej samej trasy); administracyjny
  `GET` rejestrowany jest bez `schema`, żeby schemat trasy pozostał publiczny.
- Zmiany statusu przez `BookingService::change_status()` (hook `trmz_booking_status_changed`); niedozwolone przejście → 422
  `trmz_invalid_status_transition`. Przeniesienie przez `BookingService::reschedule()` — atomowe (ADR-014) z weryfikacją
  dostępności z wykluczeniem własnego terminu (ADR-018), hook `trmz_booking_rescheduled`; konflikt/poza godzinami → 409,
  nieaktywna rezerwacja → 422, zasób spoza usługi → 400.
- Repozytorium: `BookingRepository::search()` / `count()` z `Domain\Repository\BookingCriteria` (walidowane filtry,
  kolumna sortowania z białej listy, `LIKE` z `esc_like`) — do reużycia przez listę w panelu (#25).

## ADR-024: Ustawienia pluginu i menu admina

**Kontekst.** Parametry dostępności (ADR-018), auto-potwierdzanie (M3), płatności (M6), powiadomienia (M5) i deinstalacja
czytają wspólne ustawienia; potrzebne jest jedno miejsce z domyślnymi wartościami, zakresami i sanitizacją.

**Decyzja.**
- `Terminarz\Infrastructure\Settings` — typowany widok opcji `trmz_settings` (jedna tablica, autoload): stałe `DEFAULTS`,
  `RANGES`, `CHOICES`, `FLAGS`; `Settings::load()` (lub `Services::instance()->settings()`, cache na kontener), gettery:
  `slot_step_minutes()` (5–240, 15), `min_lead_minutes()` (0–43200, 60), `max_horizon_days()` (0–730, 90),
  `customer_cancel_limit_hours()` (0–720, 24), `auto_confirm()` (false), `hold_minutes()` (5–120, 15),
  `payment_mode()` (`none`|`deposit`|`full`, `none`), `payments_enabled()` (tryb ≠ none **i** aktywny WooCommerce),
  `deposit_percent()` (1–100, 30), `any_resource_strategy()` (`order`|`least_busy`, `order`),
  `notification_email()` (pusty = `admin_email` witryny), `consent_text()` (HTML po `wp_kses_post`, pusty),
  `delete_data_on_uninstall()` (false).
- Odczyt: wartości nieprawidłowe/brakujące → domyślne (bez komunikatów). Zapis: `Settings::sanitize()` jako
  `sanitize_callback` (rejestracja na `init`, więc chroni też `update_option()` z kodu/WP-CLI): nieprawidłowa wartość →
  zostaje poprzednia + `add_settings_error`; brak checkboxa = false; brak innego klucza = bez zmian; nieznane klucze odrzucane.
- `Services::availability_settings()` buduje `AvailabilitySettings` z `Settings` (filtr `trmz_availability_settings` bez zmian).
  Zmiana domyślnych względem ADR-018: minimalne wyprzedzenie 0 → 60 min, horyzont brak → 90 dni.
- Admin: `Admin\Menu` (moduł) — rejestr stron `Admin\AdminPage` (`slug`, `menu_title`, `page_title`, `position`, `register`,
  `render`); top-level „Terminarz” (ikona `dashicons-calendar-alt`, pozycja 26) wskazuje na stronę o najniższej pozycji,
  każda strona to podmenu z capability `trmz_manage_bookings`. Kolejne ekrany dodaje się w `Plugin::default_modules()`:
  `new Menu( array( new SettingsPage(), new XyzPage() ) )`.
- `Admin\SettingsPage` (`admin.php?page=trmz-settings`, pozycja 90): Settings API (grupa `trmz_settings`, sekcje: reguły
  rezerwacji, płatności, powiadomienia, prywatność). `options.php` wymaga domyślnie `manage_options` — filtr
  `option_page_capability_trmz_settings` obniża to do `trmz_manage_bookings`. Tryb płatności jest widoczny zawsze,
  z ostrzeżeniem, gdy WooCommerce nie jest aktywny.

- REST: domyślna wartość filtra `trmz_auto_confirm_bookings` pochodzi z `Settings::auto_confirm()`.

**Konsekwencje.** Adaptery czytają ustawienia przez gettery, nigdy przez `get_option()` bezpośrednio. Wyprzedzenie
przechowywane w minutach (spójnie z ADR-018), choć issue mówiło o godzinach. Testy korzystające z
`Services` bez jawnego `AvailabilitySettings` podlegają domyślnemu horyzontowi 90 dni i wyprzedzeniu 60 min.

## ADR-025: Eksport i usuwanie danych osobowych (narzędzia prywatności WP)

**Decyzja.**
- Moduł `Admin\Privacy` rejestruje exporter i eraser (`wp_privacy_personal_data_exporters` / `_erasers`, ID
  `terminarz-bookings`) oraz sugerowany tekst polityki prywatności (`wp_add_privacy_policy_content` na `admin_init`).
- Dopasowanie po **dokładnym** adresie e-mail klienta (`customer_email`, porównanie wg kolacji — bez rozróżniania wielkości
  liter; indeks `customer_email`). `BookingRepository::find_by_customer_email($email, $limit, $offset, ?$keep_active_from)`
  i `replace_customer($id, Customer)`.
- Eksport: wszystkie rezerwacje (dowolny status), strony po 50, pola: publiczne ID, usługa, zasób, start/koniec
  (strefa witryny), status, dane kontaktowe, notatka, data utworzenia.
- Usuwanie (**założenie**, #27): anonimizacja danych klienta (`wp_privacy_anonymize_data()` → `[deleted]`,
  `deleted@site.invalid`, pusty telefon/notatka, bez powiązania z kontem) z zachowaniem rekordu (statystyki, historia
  zasobu). **Nadchodzące aktywne rezerwacje** (aktywny status i start ≥ teraz) są zachowywane bez zmian
  (`items_retained` + komunikat z ID) — administrator anuluje je i ponawia usuwanie. Eraser zawsze czyta od offsetu 0
  (zanonimizowane wiersze przestają pasować, zachowane są wykluczone z zapytania), więc numer strony nie ma znaczenia
  i pętla się kończy.
- Etykiety statusów i formatowanie dat dla ekranów/integracji: `Admin\Labels` (tłumaczenia poza domeną, ADR-015).

**Konsekwencje.** Skrót tokenu anulowania i ID zamówienia WooCommerce nie są danymi osobowymi i pozostają. Dane klienta
w zamówieniu WooCommerce obsługuje eraser WooCommerce (M6).

## ADR-026: Ekrany panelu admina (klasyczne, PRG) i zasoby

**Kontekst.** M4 potrzebuje kilku ekranów CRUD (zasoby, usługi, harmonogramy, rezerwacje). Do wyboru: klasyczne ekrany PHP
(`WP_List_Table` + formularze) albo aplikacja React na REST API.

**Decyzja.**
- **Klasyczne ekrany PHP** w `src/Admin/` — bez builda JS, zgodne z wyglądem WP, łatwe do testowania integracyjnie i
  dostępne bez JavaScriptu. REST (`terminarz/v1`) pozostaje dla bloku i integracji.
- `Admin\Screen` (abstrakcyjna, implementuje `AdminPage` z ADR-024): widoki wybierane parametrem `view`
  (`admin.php?page=<slug>&view=edit&id=…`); akcje zapisu wyłącznie przez `admin-post.php?action=trmz_<nazwa>`
  (`actions()` → metoda handlera). `Screen::handle()` sprawdza `current_user_can('trmz_manage_bookings')` i nonce
  `trmz_<nazwa>` (`check_admin_referer`), przekazuje handlerowi odsłonięty (`wp_unslash`) request, a handler — po
  sanitizacji każdego pola (`Admin\Input`) — zwraca URL przekierowania (Post/Redirect/Get). Wyjątki domeny/bazy
  nieobsłużone przez handler → ogólny komunikat. `render()` ponownie sprawdza capability.
- Komunikaty i wartości formularza z błędami przechodzą przez przekierowanie w `Admin\Notices` (transient per użytkownik,
  5 min); tekst jest tłumaczony w handlerze i escapowany przy wyświetlaniu.
- Linki akcji (usuń, aktywuj) to `wp_nonce_url()` do `admin-post.php` (GET + nonce, jak w core), formularze — POST.
- Handlery korzystają wyłącznie z repozytoriów/serwisów z `Services::instance()`, nigdy z `$wpdb`.
- **Zasoby** (`Admin\ResourcesPage`, `admin.php?page=trmz-resources`, pozycja 20): lista (`ResourcesListTable`),
  formularz (nazwa, typ, opis, kolejność, aktywny), aktywacja/dezaktywacja, usuwanie. Usunięcie zasobu z **jakimikolwiek**
  rezerwacjami jest blokowane przez repozytorium (ADR-013 — surowsze niż „przyszłe rezerwacje” z #22, zachowuje historię);
  komunikat kieruje do dezaktywacji. Dezaktywacja nie anuluje przyszłych rezerwacji — ekran ostrzega o ich liczbie.
- **Schemat v2**: kolumna `trmz_resources.type` (`person`|`room`|`device`, domyślnie `person`). `BookableResource` rozszerzony
  zachowawczo (opcjonalne argumenty na końcu konstruktora): `type`, `description`, `sort_order`; `with_active()`.
  Typ jest informacyjny — nie zmienia reguł dostępności.

**Konsekwencje.** Test migracji: `ALTER TABLE` (także na tabelach tymczasowych) niejawnie zatwierdza transakcję testu WP,
więc test aktualizacji schematu woła `Schema::install()` bez opcji wersji/blokady (inaczej wyciekłyby do kolejnych testów).

## ADR-027: Usługi w panelu i waluta ceny

**Decyzja.**
- `Admin\ServicesPage` (`admin.php?page=trmz-services`, pozycja 30) + `ServicesListTable`: CRUD usług (nazwa, opis,
  czas trwania 1–1440 min, bufor po usłudze 0–1440 min, cena ≥ 0, przypisane zasoby — checkboxy, kolejność, aktywna),
  aktywacja/dezaktywacja, usuwanie blokowane przy rezerwacjach (ADR-013). Kolejność preferencji zasobów przy „dowolnym
  zasobie” (ADR-018) = kolejność wyświetlania zasobów (nie kolejność kliknięć). Aktywna usługa bez zasobów → ostrzeżenie.
- `Service` rozszerzony zachowawczo o `description` i `sort_order` (kolumny istniały od v1) oraz `with_active()`.
- Cena: `Admin\Money` — wpisywana jako kwota dziesiętna (`150`, `150,50`, `1 200,00`), zapisywana w jednostkach
  podrzędnych (`price_minor`). Waluta i liczba miejsc po przecinku z WooCommerce (`get_woocommerce_currency()`,
  `wc_get_price_decimals()`), gdy jest aktywny. **Założenie (#23):** bez WooCommerce cena jest informacyjna (brak płatności),
  waluta pochodzi z filtra `trmz_currency` (domyślnie brak kodu), miejsca po przecinku z `trmz_price_decimals` (domyślnie 2).
  Kolumna `trmz_services.currency` pozostaje nieużywana (waluta nie jest częścią domeny, ADR-015).

**Konsekwencje.** Zmiana liczby miejsc po przecinku w WooCommerce po zapisaniu cen zmienia ich interpretację — do
opisania w dokumentacji M6.

## ADR-028: Harmonogram tygodniowy i wyjątki w panelu

**Decyzja.**
- `Admin\SchedulePage` (`admin.php?page=trmz-schedule&resource=<id>`, pozycja 24, „Godziny pracy”): tabela 7 dni,
  w każdym dowolna liczba przedziałów pracy i przerw (pola `type="time"`, czas lokalny witryny — informacja o strefie
  i bieżącym offsecie UTC na ekranie). **Bez JavaScriptu**: zapisane przedziały + puste wiersze (2 dla pracy, 1 dla przerw);
  po zapisie z wykorzystaniem wszystkich wierszy pojawiają się kolejne (maks. 10 na dzień). Koniec `00:00` = północ (24:00).
- Walidacja (`Admin\ScheduleForm`, komunikaty per dzień): niepełny wiersz, zły format, koniec ≤ początek, nakładające się
  godziny pracy, nakładające się przerwy, przerwa poza godzinami pracy. Zapis atomowo przez `ScheduleRepository::save()`.
- Wyjątki jako **okresy**: `Domain\Model\ScheduleExceptionPeriod` (zasób lub globalny, `start_date`–`end_date` włącznie,
  maks. 366 dni, okna zastępcze lub zamknięte, notatka) — jeden wiersz tabeli na okres (tabela od v1 obsługuje zakresy);
  silnik nadal dostaje dni (`in_range()` rozwija zakres — ADR-013, `ScheduleExceptionPeriod::days()`).
  Repozytorium: `get_period()`, `save_period()`, `periods(?ending_from)`, `conflicting_periods()`.
- `Admin\ExceptionsPage` (`admin.php?page=trmz-exceptions`, pozycja 26, „Dni wolne”) + `ExceptionsListTable`: lista
  nadchodzących (opcjonalnie z przeszłymi, filtr zakresu: wszystkie/globalne/zasób), formularz: dotyczy (wszystkie zasoby
  lub zasób), pierwszy/ostatni dzień, zamknięte / inne godziny (do 3 przedziałów), notatka. Obsługuje urlop zasobu (zakres
  dni), święto globalne i dzień z innymi godzinami.
- Nakładanie się: okresy tego samego zakresu (ten sam zasób albo oba globalne) nie mogą dzielić dnia — błąd z datami
  kolidującego okresu; wyjątek zasobu może nakładać się na globalny (ma pierwszeństwo, ADR-015).
- Wyjątek nie zmienia istniejących rezerwacji — ekran ostrzega o liczbie aktywnych rezerwacji w tych dniach.

**Konsekwencje.** Wyjątki zapisane wcześniej przez `save()` (jednodniowe) są widoczne jako okresy jednodniowe.

## ADR-029: Lista rezerwacji w panelu

**Decyzja.**
- `Admin\BookingsPage` (`admin.php?page=trmz-bookings`, pozycja 10 — cel pozycji „Terminarz” w menu) +
  `BookingsListTable`: widoki statusów z licznikami, filtry (usługa, zasób, daty lokalne startu od–do), wyszukiwanie
  (fragment imienia/e-maila lub dokładne `public_id`), sortowanie (start, data utworzenia), paginacja po 20 — przez
  `BookingRepository::search()/count()` i `BookingCriteria` (ADR-023). Filtry z zapytania czyta i sanitizuje
  `Admin\BookingFilters` (wspólne z eksportem CSV; `query_args()` odtwarza je w linkach). Daty zakresu to dni lokalne
  witryny → przedział UTC `[od 00:00, dzień po „do” 00:00)`.
- Wszystkie daty wyświetlane w strefie witryny (`wp_date()` z formatami witryny, `Admin\Labels`).
- Szczegóły (`view=view&id=`): dane rezerwacji i klienta, konto WP, zamówienie, wstrzymanie płatności; akcje jako
  formularze POST.
- Potwierdź/anuluj: `BookingService::change_status()` (maszyna stanów; niedozwolone przejście → komunikat), linki akcji
  tylko dla dozwolonych przejść. Po akcji powrót do strony, z której przyszło żądanie (`wp_get_referer()` — lista z filtrami
  lub szczegóły).
- Przeniesienie (`view=reschedule&id=&date=`): wybór dnia (poprzedni/następny), lista wolnych slotów wszystkich zasobów
  usługi z `AvailabilityService::slots(…, exclude_booking_id)` (własny termin nie blokuje); wartość slotu
  `"<timestamp UTC>:<id zasobu>"`; zapis `BookingService::reschedule()` — atomowy (ADR-014) z ponowną weryfikacją
  dostępności; przegrany wyścig (`SlotUnavailable`) → komunikat i powrót do wyboru, bez zmian. Obowiązują te same reguły
  co dla klienta (wyprzedzenie, horyzont, siatka) — panel ich nie omija.

**Konsekwencje.** Liczniki statusów to 6 dodatkowych zapytań `COUNT` na wyświetlenie listy (akceptowalne).

## ADR-030: Eksport rezerwacji do CSV

**Decyzja.**
- Przycisk „Eksportuj CSV” na liście rezerwacji prowadzi do `admin-post.php?action=trmz_export_bookings` z **aktywnymi
  filtrami listy** (`BookingFilters::query_args()`) i nonce `trmz_export_bookings`; `BookingsPage::authorize_export()`
  sprawdza `trmz_manage_bookings` i nonce, `export()` wysyła nagłówki (`text/csv; charset=utf-8`, `attachment`,
  `nosniff`, bez cache) i strumieniuje do `php://output` — hook `admin_post_` działa przed wysłaniem jakiejkolwiek treści.
- `Admin\BookingsCsvExporter::write($stream, $filters)`: BOM UTF-8, nagłówek, wiersze partiami po 100
  (`BookingRepository::search()` z offsetem, stabilne sortowanie start+id; `fflush` po partii) — pamięć stała niezależnie
  od liczby rezerwacji. Kolumny: ID publiczne, status, start/koniec (strefa witryny), start UTC, usługa, zasób, dane
  klienta, notatka, data utworzenia, zamówienie.
- Separator: filtr `trmz_csv_separator` (`,` domyślnie; dozwolone `,` `;` tab `|` — inne wartości → `,`); `fputcsv` z
  pustym znakiem ucieczki (RFC 4180).
- Ochrona przed CSV/formula injection: komórka zaczynająca się od `=`, `+`, `-`, `@`, tabulatora lub CR dostaje prefiks `'`
  (dotyczy też np. telefonu `+48…`).

**Konsekwencje.** Eksport z offsetem przy równoległych zmianach może pominąć/zdublować wiersz na granicy partii —
akceptowalne dla eksportu administracyjnego.

## ADR-031: Blok „Rezerwacja” (`terminarz/booking`)

**Decyzja.**
- Blok **dynamiczny** (`save: null`), `block.json` apiVersion 3 w `blocks/booking/`, rejestrowany z `build/booking` przez
  `Terminarz\Blocks\BookingBlock` (moduł, `register_block_type` + `render_callback`). Brak zbudowanych plików → blok nie
  jest rejestrowany (bez błędów). Integracyjne testy PHP wymagają `npm run build` (krok w CI).
- Atrybuty: `serviceIds` (int[], pusta = wszystkie aktywne usługi), `defaultServiceId` (0 = brak), `showResourcePicker`
  (bool, domyślnie `true`; `false` = „dowolny” zasób), `firstDayOfWeek` (-1 = ustawienie witryny `start_of_week`, 0–6).
  Serwer normalizuje atrybuty (`sanitize_attributes`) — treść wpisu można edytować ręcznie.
- Render: pusty kontener z `get_block_wrapper_attributes()` (klasy/stylowanie z `supports`) i konfiguracją JSON
  w `data-trmz-config` (escapowane przez `get_block_wrapper_attributes`) + `<noscript>`. Konfiguracja: `restRoot`,
  `nonce` (tylko dla zalogowanych), atrybuty, `today`/`lastDate` (strefa witryny, horyzont rezerwacji), `locale`,
  `currency`/`priceDecimals`, `consentHtml` (tekst zgody z ustawień przepuszczony przez `wp_kses` z listą elementów
  inline, domyślny tekst gdy pusty). Filtr `trmz_booking_block_config` (punkt rozszerzenia dla M6/M7).
- Front end: klasyczny `viewScript` (React z `@wordpress/element`) — stabilny od WP 6.5, bez Interactivity API/modułów.
- REST z przeglądarki: **prywatna kopia `@wordpress/api-fetch`** (alias `trmz-bundled-api-fetch` wbudowany w view.js)
  z `createRootURLMiddleware(restRoot)` i `createNonceMiddleware` wyłącznie dla zalogowanych. Skrypt `wp-api-fetch`
  z rdzenia dodaje nonce każdemu odwiedzającemu; nonce zapisany w stronie z cache wygasa i anonimowa rezerwacja
  kończyłaby się 403 `rest_cookie_invalid_nonce`. Edytor używa zwykłego `wp.apiFetch`.
- Tłumaczenia JS: `wp_set_script_translations( <uchwyty edytora i widoku>, 'terminarz', languages/ )`; `.pot` obejmuje
  źródła w `blocks/` (JS + `block.json`).

**Konsekwencje.** view.js jest większy o api-fetch (~kilka KB). Pliki JSON tłumaczeń muszą odpowiadać ścieżkom
skryptów z `build/` (do rozwiązania przy pakowaniu tłumaczeń w M8).

## ADR-032: Front end bloku rezerwacji — kroki, dane i dostępność

**Decyzja.**
- `blocks/booking/view.js` montuje `app/BookingApp` (React, `createRoot`) w każdym kontenerze. Kroki: usługa → osoba/zasób
  (lub „Dowolna dostępna”) → dzień → godzina → dane (`renderDetails`, #31). Krok usługi jest pomijany przy jednej usłudze,
  krok zasobu — gdy `showResourcePicker=false` (→ `any`) lub usługę wykonuje ≤ 1 zasób. Cały stan w `BookingApp`, więc
  „Wstecz” zachowuje wybory; zmiana usługi/zasobu czyści dzień i godzinę.
- Dostępność pobierana **miesiącami** (`monthRange`: od dziś, do `lastDate`, ≤ 31 dni) i buforowana per usługa/zasób/miesiąc;
  pusty miesiąc przy otwarciu kalendarza → automatycznie kolejny (maks. 2). `refreshAvailability()` czyści bufor (409, #31).
- Czas: daty jako łańcuchy `Y-m-d` strefy witryny (arytmetyka na północach UTC — strefa przeglądarki i DST nie przesuwają
  dni), godziny odczytywane wprost z ISO z offsetem witryny (`timeOf`), informacja o strefie z `timezone` odpowiedzi
  (`+02:00` → `UTC+02:00`). Wysyłany jest `start_utc` slotu.
- Dostępność (podstawa pod #32): nagłówek kroku (`h2`, `tabindex=-1`) dostaje fokus po każdej zmianie kroku (nie przy
  załadowaniu strony); postęp „Krok n z m”; komunikaty ładowania/wyników w jednym regionie `role=status` `aria-live=polite`;
  błędy `role=alert` powiązane przez `aria-describedby`. Usługi, zasoby i godziny to **natywne radio** w `role=radiogroup`
  (strzałki i Tab działają natywnie, bez własnego ARIA). Kalendarz: tabela `role=grid` wg wzorca APG (roving tabindex,
  strzałki, Home/End, PageUp/PageDown, także między miesiącami), dni bez terminów fokusowalne z `aria-disabled`,
  `aria-selected` na komórce, `aria-current=date`, pełna data w `aria-label` przycisku dnia.
- Style: klasy `trmz-*` (BEM), kolory dziedziczone z motywu (`currentcolor` + `color-mix`), cele ≥ 44 px (dni ≥ 32×40 px),
  widoczny fokus (`outline` 3 px).
- Logika czysta w `blocks/booking/lib/` (calendar, slots, format, config) — testy Jest w `tests/js/` (`npm run test:js`).

## ADR-033: Formularz rezerwacji w bloku i obsługa błędów

**Decyzja.**
- Krok „Twoje dane” (`app/DetailsStep`): imię i nazwisko, e-mail (wymagane), telefon, uwagi, zgoda (treść z ustawień,
  ADR-031) i honeypot `website` (poza ekranem, `aria-hidden`, `tabindex=-1`). Stan formularza trzyma `BookingApp`, więc
  powrót do wyboru godziny (także po 409) nie gubi danych.
- Walidacja klienta (`lib/form.js`, limity = schemat REST) z komunikatami przy polach (`aria-invalid`,
  `aria-describedby` → podpowiedź + błąd), fokus na pierwszym błędnym polu; serwer i tak waliduje ponownie.
- Mapowanie odpowiedzi `POST /bookings` (`mapBookingError`): 409 / `trmz_invalid_date` → powrót do kroku godziny
  z komunikatem i odświeżoną dostępnością; 429 → komunikat z czasem z `Retry-After` (minuty); 400
  `rest_invalid_param`/`rest_missing_callback_param`/`trmz_consent_required`/`trmz_invalid_customer` → błędy pól;
  403 `rest_cookie_invalid_nonce` → „sesja wygasła”; brak połączenia i inne → komunikat ogólny (`role=alert`).
- Sukces (201): ekran potwierdzenia z terminem z odpowiedzi serwera i statusem (`pending` → oczekuje na potwierdzenie,
  `confirmed` → potwierdzona, `pending_payment` → oczekuje na płatność), przycisk „Zarezerwuj kolejną wizytę”.
- Punkty rozszerzenia: `payment_url` w odpowiedzi 201 → przekierowanie (tylko `http(s)`, `safeRedirectUrl`) — dla M6;
  zdarzenie DOM `terminarz:booking-created` (bąbelkujące, `detail` = odpowiedź) na kontenerze bloku.
- E2E: mu-plugin `tests/e2e/mu-plugins/trmz-e2e.php` (mapowany tylko w `.wp-env.tests.json`, nieaktywny w PHPUnit)
  podnosi limit rezerwacji do 1000 / 10 min — wszystkie testy przeglądarkowe mają jedno IP. 429 w bloku testowane
  zamockowaną odpowiedzią, sam limiter — PHPUnit.

## ADR-034: Dostępność bloku rezerwacji (WCAG 2.2 AA) — weryfikacja

**Decyzja.**
- Wzorce z ADR-032/033 są obowiązujące: natywne radio (usługi, zasoby, godziny) zamiast własnych `role=radio` / przycisków
  z `aria-pressed` — mniej ARIA, poprawne ogłaszanie stanu i obsługa strzałek przez przeglądarkę; kalendarz `role=grid`
  z roving tabindex (jeden przystanek Tab); fokus na nagłówku kroku; jeden region `role=status`; błędy `role=alert`
  i `aria-describedby`; honeypot poza drzewem dostępności.
- Testy E2E `booking-block-a11y.spec.js`: `@axe-core/playwright` (tagi `wcag2a/aa`, `wcag21a/aa`, `wcag22aa`,
  zakres: kontener bloku) bez naruszeń na każdym kroku, także ze stanami błędów i na ekranie potwierdzenia; przejście
  całej ścieżki wyłącznie klawiaturą (Tab/Shift+Tab, Spacja, Enter, strzałki, Home/End, PageUp/PageDown); viewport
  320×640 bez przewijania poziomego i z celami ≥ 24×24 px.
- Kolory: blok dziedziczy kolor tekstu motywu, akcenty z `color-mix(currentcolor)`; stały jest tylko czerwony pasek
  błędu (element dekoracyjny, komunikat jest tekstem w kolorze motywu) — kontrast tekstu zależy od motywu.

**Konsekwencje.** axe sprawdza motyw testowy (Twenty Twenty-Five); motywy o niskim kontraście mogą obniżyć zgodność —
opisać w dokumentacji wydania (M8). Kalendarz nie jest dialogiem (osadzony w stronie), więc nie ma pułapki fokusu.

## ADR-035: Warstwa integracji WooCommerce (`Terminarz\Integrations\WooCommerce`)

**Kontekst.** WooCommerce jest zależnością opcjonalną: bez niego plugin musi działać bez błędów i bez płatności.
WooCommerce ładuje się po Terminarzu (kolejność alfabetyczna), więc przy `boot()` jego klas jeszcze nie ma.

**Decyzja.**
- `WooCommerce` (statyczny detektor) — jedyne miejsce decydujące o dostępności płatności: `is_loaded()` (klasa
  `WooCommerce` + `wc_create_order`), `version()` (`WC_VERSION`), `is_active()` (wersja ≥ `MIN_VERSION` = 8.0, filtr
  `trmz_woocommerce_active`), `is_outdated()`. `Settings::payments_enabled()` = tryb ≠ `none` **i** `WooCommerce::is_active()`.
- `WooCommerceModule` (moduł w `Plugin::default_modules()`): zawsze podpina `before_woocommerce_init` →
  `FeaturesUtil::declare_compatibility()` dla `custom_order_tables` (HPOS) i `cart_checkout_blocks`; na `plugins_loaded`
  (priorytet 20) rejestruje komponenty integracji (`Module[]`, `default_components()`) tylko przy aktywnym, wspieranym
  WooCommerce i wywołuje akcję `trmz_woocommerce_integration_loaded`. Zbyt stary WooCommerce → notice w adminie
  i ostrzeżenie w sekcji „Płatności” ustawień; płatności wyłączone.
- Integracja używa wyłącznie API CRUD WooCommerce (`wc_create_order`, `wc_get_order`, `$order->update_meta_data()`),
  nigdy postmeta zamówień — działa z HPOS i z magazynem na wpisach.
- Testy integracyjne: bootstrap ładuje WooCommerce z wp-env (po Terminarzu, jak na witrynie) i instaluje go
  (`WC_Install::install()` na `init` po Action Scheduler, tabele HPOS włączone). `TRMZ_TESTS_WOOCOMMERCE=0` uruchamia
  suitę bez WooCommerce (`composer test:integration:no-wc`, osobny krok CI). Grupy: `woocommerce` (pomijane bez WC),
  `no-woocommerce` (pomijane z WC).

**Konsekwencje.** Komponenty integracji nie mogą być używane przed `plugins_loaded`. Filtr `trmz_woocommerce_active`
pozwala wyłączyć płatności (np. w testach), ale nie „włączyć” ich bez WooCommerce. Pełna suita z WooCommerce jest
wolniejsza (hooki WC przy tworzeniu użytkowników/wpisów).

## ADR-036: Rezerwacja płatna tworzy zamówienie WooCommerce

**Kontekst.** Przy trybie płatności „zaliczka”/„całość” klient płaci online; slot musi być zajęty na czas płatności,
a porażka przy tworzeniu płatności nie może zostawić zablokowanego terminu.

**Decyzja.**
- Port aplikacyjny `Application\PaymentProvider::start_payment(Booking, Service): string` (URL płatności, wyjątek
  `PaymentFailed`) i `Application\PaymentAmount::due($price_minor, ?$deposit_percent)` (zaliczka = % ceny zaokrąglony
  „half up”, min. 1 jednostka). `Services::payment_provider()` zwraca dostawcę z filtra `trmz_payment_provider` tylko gdy
  `Settings::payments_enabled()`; integracja WC rejestruje `OrderPayments`.
- `POST /bookings`: usługa płatna (`price_minor > 0`) i dostępny dostawca → rezerwacja `pending_payment` z
  `hold_expires_at = teraz + Settings::hold_minutes()`, potem `start_payment()`. Odpowiedź 201 zawiera dodatkowo
  `payment_url` (`$order->get_checkout_payment_url()`, walidowany `wp_http_validate_url`) — blok przekierowuje (ADR-033).
  Usługa darmowa lub płatności wyłączone → dotychczasowy przepływ (`pending`/`confirmed`, bez `payment_url`).
- Porażka (`PaymentFailed`/dowolny `Exception`, niepoprawny URL) → rezerwacja anulowana (`cancel()`, slot zwolniony),
  akcja `trmz_payment_start_failed` (Booking, Exception), 500 `trmz_payment_unavailable` z przetłumaczonym komunikatem.
- Zamówienie (`OrderPayments`): `wc_create_order` (`status` pending, `created_via` = `terminarz`, `customer_id` = konto
  klienta lub gość), **jedna pozycja `WC_Order_Item_Product` bez produktu w katalogu** (nazwa = usługa, meta widoczne
  „Appointment”, „Resource”, przy zaliczce „Payment”; ukryte `_trmz_booking_id`), kwota traktowana jako ostateczna —
  `calculate_totals(false)` nie dolicza podatku; billing: imię (pierwsze słowo) / nazwisko (reszta), e-mail, telefon;
  meta zamówienia `_trmz_booking_id` i `_trmz_booking_public_id` (`OrderLink`), notatka z czasem wstrzymania;
  `BookingRepository::attach_order()`. Błąd po utworzeniu zamówienia → zamówienie `cancelled` z notatką.
- Powiązanie jest ważne tylko, gdy obie strony wskazują na siebie (`OrderLink::booking()`).
- Admin (`OrderAdmin`): metabox „Booking” na ekranie edycji zamówienia (ID ekranu z `wc_get_page_screen_id('shop-order')`
  przy HPOS, `shop_order` bez HPOS; tylko z `trmz_manage_bookings`); w szczegółach rezerwacji wiersz „Order” z linkiem do
  edycji zamówienia, statusem i kwotą — przez nowy filtr `trmz_admin_booking_details_rows` (wartości = escapowany HTML).

**Konsekwencje.** Pozycja bez produktu nie ma stawki podatku — sklepy rozliczające VAT od usług muszą to rozstrzygnąć
(założenie w #35). „Recalculate” w edycji zamówienia może doliczyć podatek, jeśli sklep ma włączone podatki. Goście
płacący po ponad 10 min od utworzenia zamówienia mogą zostać poproszeni przez WooCommerce o potwierdzenie e-maila
(domyślny mechanizm WC dla strony „order-pay”). Jawny token anulowania nadal nie trafia do odpowiedzi (M7).

## ADR-037: Wygasanie wstrzymań płatności i płatność po czasie

**Kontekst.** Rezerwacja `pending_payment` blokuje slot do `hold_expires_at`. Silnik dostępności i `create()` traktują
wygasłe wstrzymanie jako wolne od razu (ADR-014), ale status i zamówienie trzeba uporządkować, a płatność może dotrzeć
po czasie (bramka potwierdza z opóźnieniem). `expired` jest statusem końcowym (maszyna stanów, ADR-015).

**Decyzja.**
- `Infrastructure\HoldExpiryScheduler` (moduł rdzenia, działa także bez WooCommerce): akcja `trmz_expire_holds` co 5 min
  wołająca `BookingService::expire_holds()` partiami (100 × maks. 10). Action Scheduler (`as_schedule_recurring_action`,
  grupa `terminarz`, `unique`), gdy jest zainicjowany — sprawdzenie harmonogramu tylko w adminie/cronie/CLI/AJAX (jedno
  zapytanie), zdarzenie WP-Cron jest wtedy usuwane; w przeciwnym razie WP-Cron z interwałem `trmz_five_minutes`.
  Deaktywacja pluginu usuwa zadanie z obu mechanizmów.
- `Integrations\WooCommerce\OrderStatusSync`: `trmz_booking_status_changed` → `expired`: zamówienie `pending`/`failed`
  zostaje anulowane z notatką; inne statusy (np. `on-hold` przelewu) dostają tylko notatkę. Meta zamówienia
  `_trmz_slot_released` zapamiętuje, że slot zwolnił plugin.
- Płatność (`woocommerce_order_status_changed` → `processing`/`completed`):
  - rezerwacja `pending_payment` z ważnym wstrzymaniem → `confirmed`;
  - wstrzymanie już minęło (zadanie jeszcze nie działało) → najpierw `expired`, dalej jak niżej;
  - `expired` (lub `cancelled` zwolniona automatycznie — meta `_trmz_slot_released`) → **nowa rezerwacja**
    `BookingService::rebook()` (atomowo przez `BookingRepository::create()`, tylko kontrola kolizji i przeszłości —
    grafik/wyprzedzenie nie obowiązują, bo termin był poprawny przy wyborze), ta sama usługa/zasób/czas/bufor/klient/
    zamówienie; meta zamówienia wskazuje nową rezerwację, notatka. Stara zostaje `expired` (bez nowych przejść w maszynie stanów);
  - slot zajęty (lub rezerwacja anulowana ręcznie) → zamówienie `on-hold`, notatka, meta `_trmz_needs_attention`,
    jednorazowy e-mail na `Settings::notification_email()` i akcja `trmz_payment_needs_attention` (WC_Order, Booking,
    powód). Bez nowego statusu rezerwacji („needs-attention” z issue zastąpione statusem zamówienia) — decyzja o zwrocie
    należy do człowieka (#36: `assumption`, `needs-human`).
- Pętle: handlery sprawdzają bieżący stan (idempotencja) i ustawiają flagę `OrderStatusSync::is_syncing()` na czas
  własnych zmian; błędy domeny/bazy są logowane (`wc_get_logger`, źródło `terminarz`), nigdy nie przerywają płatności.

**Konsekwencje.** Po płatności po czasie klient ma nowy `public_id` (nowy token anulowania — M7 musi wysłać potwierdzenie
z danymi nowej rezerwacji; zdarzenie `trmz_booking_created` z rezerwacją `confirmed`). Stara rezerwacja pozostaje
w historii z tym samym `order_id`.

## ADR-038: Synchronizacja statusów zamówienia i rezerwacji

**Decyzja.** `Integrations\WooCommerce\OrderStatusSync` (rozszerza ADR-037) mapuje:

| Zdarzenie | Skutek |
|---|---|
| zamówienie → `processing`/`completed` | rezerwacja `pending_payment`/`pending` → `confirmed` (+ notatka); płatność po czasie — ADR-037 |
| zamówienie → `cancelled`/`failed`/`refunded` | aktywna rezerwacja → `cancelled` (slot zwolniony), meta `_trmz_slot_released=order_status`, notatka |
| rezerwacja → `cancelled` (panel, REST admina, klient w M7) | zamówienie `pending`/`failed` → `cancelled`; opłacone → tylko notatka „NIE zwrócono automatycznie” |
| rezerwacja `pending_payment` → `confirmed` ręcznie | notatka w nieopłaconym zamówieniu |
| rezerwacja → `expired` | ADR-037 |

- Hooki: `woocommerce_order_status_changed` (priorytet 20; jedno miejsce zamiast osobnych `woocommerce_order_status_*`)
  i `trmz_booking_status_changed` (priorytet 20). Idempotencja: każdy handler sprawdza bieżący status rezerwacji/zamówienia
  (powtórzone przejście nie zmienia niczego i nie wysyła zdarzeń). Brak pętli: flaga `OrderStatusSync::is_syncing()` na czas
  własnych zmian (zmiana zamówienia wywołana przez rezerwację nie wraca do rezerwacji i odwrotnie).
- Zamówienie jest brane pod uwagę tylko, gdy wskazuje na rezerwację, która wskazuje na nie (`OrderLink::booking()`);
  inne zamówienia sklepu są ignorowane.
- `failed` zwalnia slot, ale ponowna udana płatność tego samego zamówienia rezerwuje slot ponownie, jeśli jest wolny
  (meta `_trmz_slot_released`); płatność za rezerwację anulowaną ręcznie → `on-hold` + powiadomienie (ADR-037).
- Zwroty nigdy nie są automatyczne (decyzja człowieka).
- Prywatność: eksporter/eraser WooCommerce obsługują zamówienia same; meta zamówień Terminarza zawiera tylko
  identyfikatory rezerwacji (bez danych osobowych), a dane klienta w rezerwacjach obsługuje `Admin\Privacy` (ADR-025) —
  nic nie trzeba dodawać.

**Konsekwencje.** Klient, któremu płatność się nie powiodła, traci wstrzymanie od razu (zgodnie z issue); jeśli zapłaci
ponownie, a slot jest zajęty, zamówienie trafia do obsługi ręcznej.

## ADR-039: E2E płatności — testowa bramka i wymuszanie wygaśnięcia

**Decyzja.**
- `tests/e2e/mu-plugins/trmz-test-gateway.php` (mapowany tylko w `.wp-env.tests.json`; `/tests` jest w `.distignore`, więc
  nie trafia do paczki; pomijany w PHPUnit): bramka `trmz_test_gateway` („Test payment”) z wyborem wyniku — „Payment
  succeeds” → `payment_complete()`, „Payment fails” → zamówienie `failed` + komunikat. Bez żadnych kluczy i zewnętrznych usług.
- Ten sam mu-plugin wyłącza tryb „coming soon” nowych sklepów WooCommerce (`pre_option_woocommerce_coming_soon` = `no`),
  który ukrywał strony sklepu (w tym „Zapłać za zamówienie”) przed gośćmi, oraz rejestruje testową trasę
  `POST /trmz-e2e/v1/expire-hold` (`manage_options`): przesuwa `hold_expires_at` rezerwacji w przeszłość i uruchamia
  `trmz_expire_holds` — „porzucona płatność” bez czekania 15 minut.
- `tests/e2e/specs/booking-payment.spec.js` (serial): admin ustawia tryb „całość” w ustawieniach i płatną usługę w panelu →
  gość rezerwuje w bloku → przekierowanie na stronę „order-pay” (klasyczny formularz WooCommerce także przy blokowym
  checkoutcie) → płatność testowa → „order-received” → admin widzi rezerwację `confirmed`, link do zamówienia i metabox
  „Booking” w zamówieniu; porzucona płatność → wygaśnięcie → slot znów w `/availability`, rezerwacja „Expired”, zamówienie
  anulowane; nieudana płatność → komunikat i zwolniony slot; WooCommerce dezaktywowany przy włączonym trybie płatności →
  rezerwacja `pending` bez przekierowania. Na końcu tryb płatności `none` i brak PHP notice w `debug.log`.
- Odpowiedź `POST /bookings` jest przechwytywana przez `page.route()` (blok od razu przechodzi na stronę płatności,
  więc treści odpowiedzi nie da się odczytać po nawigacji).

**Konsekwencje.** Po zmianie mu-pluginów trzeba zrestartować środowisko testowe (`wp-env stop` + `npm run env:start:tests`).
Przy aktywnym sklepie motyw pokazuje dodatkowe elementy WooCommerce w nagłówku — test klawiatury bloku dopuszcza do 60
naciśnięć Tab przed dotarciem do bloku.

## ADR-040: Moduł powiadomień e-mail i edytowalne szablony

**Kontekst.** M7 wysyła e-maile do klienta i firmy (potwierdzenie, anulowanie, przypomnienie, płatność wymagająca
uwagi). Treści muszą być edytowalne, przetłumaczalne i bezpieczne (dane klienta w HTML).

**Decyzja.**
- Nowa przestrzeń `Terminarz\Notifications` (warstwa adaptera: używa funkcji WP, domena jej nie zna):
  `MessageType` (enum: `customer_pending`, `customer_confirmed`, `customer_cancelled`, `customer_reminder`, `admin_new`,
  `admin_cancelled`, `admin_payment_needs_attention`), `Template` (włączony, temat, treść), `Templates` (opcja
  `trmz_email_templates`, bez autoload), `Placeholders`, `BookingPlaceholders`, `Renderer`, `Mailer`
  (`Services::mailer()`).
- Opcja przechowuje tylko różnice: `[typ => [enabled, subject|null, body|null]]`; `null` = domyślny tekst tłumaczony
  w chwili wysyłki (nieedytowane szablony podążają za językiem witryny). Zapis tekstu równego domyślnemu = `null`.
- Placeholdery: `{customer_name}`, `{customer_email}`, `{customer_phone}`, `{customer_note}`, `{service_name}`,
  `{resource_name}`, `{start_date}`, `{start_time}`, `{end_time}`, `{price}`, `{status}`, `{booking_id}` (publiczny ID),
  `{cancel_url}`, `{site_name}`, `{site_url}`, `{admin_booking_url}`, `{order_number}`, `{order_url}`, `{reason}`.
  Daty/godziny: `wp_date()` w strefie witryny z formatami witryny.
- Bezpieczeństwo: temat przy zapisie `sanitize_text_field`, przy wysyłce wartości wstawiane „na surowo”, a wynik
  sprowadzany do jednej linii bez tagów (brak wstrzyknięcia nagłówków). Treść przy zapisie `wp_kses_post`; przy wysyłce
  wartości `esc_html` (+`nl2br`), URL-e `esc_url`, a całość ponownie `wp_kses_post`. Nieznane `{x}` zostają bez zmian.
- Wysyłka `wp_mail()` z nagłówkiem `Content-Type: text/html; charset=UTF-8` przekazanym w wywołaniu (nie globalny filtr
  `wp_mail_content_type`), prosty layout tabelaryczny z krótkimi liniami. Do klienta `Reply-To` = adres powiadomień,
  do firmy `Reply-To` = e-mail klienta. Filtry: `trmz_email` (argumenty `wp_mail`, `false` pomija), `trmz_email_html`
  (layout), `trmz_email_placeholders` (wartości).
- Ekran „Terminarz → E-maile” (`trmz-emails`, `Admin\EmailsPage`, PRG przez `Screen`: nonce + `trmz_manage_bookings`):
  lista, edycja (włącz/wyłącz, temat, treść), lista placeholderów, podgląd zapisanej wersji na przykładowych danych,
  „Wyślij testowy e-mail do mnie” (także gdy wyłączony), „Przywróć domyślny tekst” (przełącznik zostaje).
- `OrderStatusSync::notify_business()` używa szablonu `admin_payment_needs_attention` (`{reason}`, `{order_number}`,
  `{order_url}`).
- Przechwytywanie e-maili w testach: PHPUnit — `MockPHPMailer` z WP test suite (`tests_retrieve_phpmailer_instance()`,
  trait `Support\CapturedMails` dekoduje quoted-printable); E2E — mu-plugin `tests/e2e/mu-plugins/trmz-mail-catcher.php`
  (`pre_wp_mail` zapisuje do opcji `trmz_e2e_mails`, ostatnie 50; `GET/DELETE /trmz-e2e/v1/mails` dla `manage_options`;
  pomijany w PHPUnit) + `tests/e2e/utils/mails.js`. Mailpit odrzucony: dodatkowy kontener w wp-env/CI bez zysku
  dla asercji.

**Konsekwencje.** Każdy e-mail witryny testowej E2E (także WooCommerce) trafia do opcji zamiast do sieci. Zmiana
zestawu placeholderów wymaga aktualizacji `Placeholders::descriptions()` (test pilnuje, że domyślne szablony używają
tylko znanych nazw). Uninstall (M8) powinien usuwać opcję `trmz_email_templates`.
