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

## 2026-09-29 — #7 GitHub Actions CI

- **Zrobione:** `.github/workflows/ci.yml` z jobami lint, phpstan, unit (PHP 8.1/8.3), build, integration (wp-env), e2e
  (wp-env + Playwright, raport jako artefakt), cache Composera i npm; opis status checks w README.
- **Decyzje:** ADR-011.
- **Ryzyka:** czas jobów wp-env; zależność od zewnętrznych pobrań (obrazy Dockera, wordpress.org) — możliwe sporadyczne
  niepowodzenia sieciowe.

## 2026-09-29 — #8 schemat bazy danych z wersjonowaniem

- **Zrobione:** `Infrastructure\Database\Schema` (6 tabel InnoDB przez `dbDelta`, `tables()`, `install()`, `drop()`),
  `Infrastructure\Database\Migrator` (moduł, `plugins_loaded` + aktywacja, blokada migracji, akcja `trmz_schema_migrated`),
  testy integracyjne (InnoDB, idempotentna instalacja na czystej bazie, unikalność `active_start_utc`, blokada migracji).
- **Decyzje:** ADR-012 (harmonogramy w czasie lokalnym, momenty w UTC, `public_id`, `active_start_utc` + UNIQUE, tabele per witryna).
- **Ryzyka:** dbDelta nie usuwa kolumn/indeksów — przyszłe zmiany destrukcyjne wymagają jawnych kroków migracji.
  Migracja podczas żądania frontowego po aktualizacji pluginu (jednorazowo, pod blokadą).

## 2026-09-29 — #9 encje i value objects domeny

- **Zrobione:** `src/Domain/Model`: `TimeRange`, `LocalTime`, `TimeWindow`, `WeeklySchedule`, `ScheduleException`,
  `BookableResource`, `Service`, `Customer`, `Booking`, `BookingStatus` (enum + maszyna stanów), `Slot`;
  `src/Domain/Exception`: `DomainError`, `InvalidValue`, `InvalidStatusTransition`. Testy unit (walidacja, macierz przejść,
  blokowanie slotu przez wstrzymanie płatności).
- **Decyzje:** ADR-015. Założenia (etykieta `assumption` w #9): nazwa `BookableResource`, okna nie przechodzą przez północ,
  wyjątek „inne godziny” zastępuje też przerwy, przejścia statusów jak w ADR-015, bez `needs_attention`.
- **Ryzyka:** brak nowych tekstów dla użytkownika (komunikaty wyjątków nietłumaczone — adaptery muszą je mapować).
  Walidacja e-maila przez `filter_var` może różnić się od `is_email()` WordPressa — adapter sanitizuje wcześniej.

## 2026-09-29 — #11 silnik dostępności

- **Zrobione:** `src/Domain/Availability`: `AvailabilityEngine` (`find_slots()`), `AvailabilityQuery` (+ `for_service()`),
  `ResourceCalendar`, `BusyIntervals::from_bookings()`, `WallClock` (lokalne → UTC z regułami DST). Testy unit: godziny
  pracy, przerwy, bufor (nowej i istniejącej rezerwacji), sąsiadujące rezerwacje, nakładające się zajętości, wyjątki
  (pierwszeństwo zasobu), wyprzedzenie, horyzont w dniach lokalnych, krok siatki, sortowanie, wiele zasobów, wydajność.
- **Decyzje:** ADR-016 (siatka od początku okna, horyzont w dniach lokalnych, reguły DST, jawne filtrowanie zajętości).
- **Poprawka:** `autoload-dev` mapuje `Terminarz\Tests\Unit\` na `tests/php/unit/` (katalog małymi literami — na Linuksie PSR-4 nie znajdował klas bazowych testów).
- **Ryzyka:** test wydajności mierzy czas (< 300 ms przy ~7 ms lokalnie) — na bardzo wolnym runnerze CI mógłby być niestabilny.

## 2026-09-29 — #12 testy DST i stref czasowych silnika dostępności

- **Zrobione:** `tests/php/unit/Domain/Availability/DstAndTimezoneTest.php`: Europe/Warsaw (29.03 — brak 02:00–03:00,
  granice okien w luce, czas trwania/bufor/wyprzedzenie w czasie rzeczywistym; 25.10 — powtórzona godzina dwa razy
  bez duplikatów, zajętość w drugim wystąpieniu), America/New_York (obie zmiany, dzień lokalny ≠ dzień UTC, horyzont),
  Australia/Lord_Howe (DST 30 min), Pacific/Kiritimati (UTC+14, data lokalna o dzień przed UTC, horyzont), stały offset
  `+02:00`; niezmienniki całoroczne (każda realna godzina 2026 dokładnie raz) dla 6 stref; monotoniczność `WallClock`.
- **Decyzje:** bez zmian w silniku — reguły z ADR-016 przeszły wszystkie przypadki (PHP 8.1 i 8.3 w CI).
- **Ryzyka:** wyniki zależą od bazy stref czasowych (tzdata) w PHP; zmiana przepisów dla testowanych stref w przyszłych
  wersjach tzdata mogłaby wymagać aktualizacji oczekiwań (daty testowe to 2026).

## 2026-09-29 — #10 repozytoria zasobów, usług, harmonogramów i wyjątków

- **Zrobione:** interfejsy `Domain\Repository\{Resource,Service,Schedule,ScheduleException}Repository`, wyjątki domeny
  `EntityNotFound`, `EntityInUse`; implementacje `Infrastructure\Persistence\Wpdb*Repository`, helper
  `Infrastructure\Database\Transaction` (savepointy, retry deadlocków), `DatabaseError`. Testy integracyjne CRUD,
  kolejności przypisań, harmonogramu z przerwami (round-trip, 1 zapytanie dla wielu zasobów), wyjątków w zakresie dat
  (globalne + zasobu, rozwijanie zakresów), transakcji/savepointów.
- **Decyzje:** ADR-013. Usuwanie zasobu/usługi z rezerwacjami zablokowane (`EntityInUse`).
- **Ryzyka:** kolumny spoza modelu domeny (opis, kolejność, waluta) jeszcze nieedytowalne — M4. Tryb savepointów
  w testach oznacza, że prawdziwe `START TRANSACTION` jest testowane dopiero testem współbieżności (#14).

## 2026-09-29 — #13 repozytorium rezerwacji i atomowe zajmowanie slotu

- **Zrobione:** `Domain\Repository\BookingRepository` + `WpdbBookingRepository` (atomowe `create()`/`reschedule()`:
  blokada wiersza zasobu, kontrola nakładania z buforami, wygaszanie przeterminowanych wstrzymań, UNIQUE jako ostatnia linia
  obrony → `SlotUnavailable`), `change_status()` z walidacją przejść i zwalnianiem slotu, `busy_ranges()`, `in_range()`,
  `expire_holds()`, `attach_order()`. Warstwa `Application`: `BookingService`, `Reservation`, `Clock`, `EventDispatcher`;
  `Infrastructure\WpEventDispatcher`, `Infrastructure\Services`. Testy integracyjne repozytorium i serwisu.
  Autoload-dev: `Terminarz\Tests\Integration\` → `tests/php/integration/`.
- **Decyzje:** ADR-014 (w tym hooki `trmz_booking_*`). Sniff `FunctionCommentThrowTag.WrongNumber` wyłączony (@throws
  dokumentuje też wyjątki propagowane).
- **Ryzyka:** `BookingService::reserve()` bez polityki slotu nie sprawdza godzin pracy — podpina ją #15; REST (M3) musi
  korzystać z serwisu z polityką. Wygaszenie wstrzymania w `create()` nie emituje zdarzenia.

## 2026-09-29 — #14 test współbieżności — brak podwójnych rezerwacji

- **Zrobione:** `DoubleBookingTest` (`@group concurrency`) + worker `tests/php/concurrency/worker.php`: 12 równoległych
  procesów WP-CLI z barierą czasową; scenariusze: ten sam slot (1 sukces), nakładające się różne starty/usługi (1 sukces),
  różne zasoby (wszystkie sukcesy). Osobny krok w CI (`--group concurrency`), opis w README.
- **Decyzje:** ADR-017 (numer 016 zajęty przez silnik dostępności). Kontrola negatywna wykonana ręcznie (bez blokady
  zasobu test wykrywa podwójne rezerwacje).
- **Ryzyka:** test trwa ~20–30 s (bariera 8 s × 3 scenariusze); na bardzo wolnym runnerze może być potrzebne podniesienie
  `TRMZ_CONCURRENCY_BARRIER`. Test pisze do tabel witryny E2E (sprzątane po sobie).

## 2026-09-29 — #15 AvailabilityService — dostępność dla usługi i „dowolnego” zasobu

- **Zrobione:** `Application\AvailabilityService` (`slots`, `any_resource_slots`, `free_resources_at`, `is_available`),
  `Application\AvailabilitySettings` (strategie `order` / `least_busy`), `BookingService::use_availability()` i `reserve_any()`,
  `BookingRepository::busy_ranges()` z `exclude_id`, `SlotUnavailable::for_any_resource()`, wiring w `Services`
  (ustawienia z `wp_timezone()` + opcja `trmz_settings` + filtr). Testy integracyjne (harmonogram/przerwy/siatka, bufory,
  wykluczenie przenoszonej rezerwacji, wyjątki, strategie, walidacja rezerwacji, `reserve_any`, liczba zapytań ≤ 6,
  ustawienia z WP) i benchmark 30 dni × 10 zasobów (~21 ms; próg 300 ms, w CI 1000 ms przez `TRMZ_BENCH_MAX_MS`).
- **Decyzje:** ADR-018 (+ podsumowanie publicznego API dla M3).
- **Ryzyka:** domyślne ustawienia bez minimalnego wyprzedzenia i horyzontu — do ustalenia w M4 (ustawienia).
  Strategia `least_busy` liczy obciążenie tylko z pobranego zakresu zajętości.

## 2026-09-29 — #16 publiczne endpointy katalogu usług i zasobów

- **Zrobione:** warstwa `src/Rest`: `RestModule` (rejestracja na `rest_api_init`), bazowy `Controller` (namespace
  `terminarz/v1`, publiczny/administracyjny `permission_callback`, formatowanie czasu ISO 8601), `ErrorMapper` (wyjątki
  domeny → `WP_Error` z przetłumaczonym komunikatem i statusem HTTP). `GET /services`, `GET /services/{id}`,
  `GET /resources?service=` — tylko aktywne, tylko pola ze schematu. `Services::set_instance()` dla testów.
  Testy integracyjne (`tests/php/integration/Rest`, baza `RestTestCase` z własnym serwerem REST i zegarem).
- **Decyzje:** ADR-019. Nieaktywna usługa = 404 (jak nieistniejąca). `/resources` bez `service` zwraca wszystkie aktywne
  zasoby; z `service` — w kolejności preferencji przypisania. Brak opisu i waluty w katalogu (poza modelem domeny — M4/M6).
- **Ryzyka:** brak paginacji katalogu (zakładamy niewielką liczbę usług/zasobów).

## 2026-09-29 — #17 endpoint dostępności

- **Zrobione:** `Rest\AvailabilityController` — `GET /terminarz/v1/availability?service=&resource=<id>|any&from=&to=`:
  walidacja (daty, zakres ≤ 31 dni, usługa aktywna, zasób przypisany i aktywny), odpowiedź zgrupowana po dniach lokalnych
  (wszystkie dni zakresu), czasy ISO 8601 z offsetem witryny + `start_utc`, `Cache-Control: no-store` (filtr
  `trmz_availability_cache_max_age`). Testy integracyjne: grupowanie, `any`, zajęte/przeszłe sloty, DST (offset +01:00 → +02:00),
  walidacja, nagłówki cache, benchmark REST (~11 ms, próg `TRMZ_BENCH_MAX_MS`).
- **Decyzje:** ADR-020. `from`/`to` wymagane (bez domyślnego „dziś”); dla `any` pole `resource` slotu = `null`.
- **Ryzyka:** przy włączonym cache (filtr) klient może zobaczyć zajęty już slot — rezerwacja zwróci wtedy 409.
