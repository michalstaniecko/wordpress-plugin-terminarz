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
