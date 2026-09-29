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

## 2026-09-29 — #18 endpoint tworzenia rezerwacji

- **Zrobione:** `Rest\BookingsController` — `POST /terminarz/v1/bookings`: schemat i sanitizacja danych klienta, zgoda
  wymagana, honeypot `website`, nonce `wp_rest` dla zalogowanych, ponowna weryfikacja slotu i atomowa rezerwacja
  (konkretny zasób lub `any`), 201 z `public_id`, 400/404/409 z przetłumaczonymi komunikatami. `Customer` + `note`, `user_id`
  (zapisywane w istniejących kolumnach). Testy integracyjne (27): sukces + hook `trmz_booking_created`, `any`, 409, sloty spoza
  harmonogramu, walidacja, sanitizacja XSS, nonce, auto-potwierdzanie.
- **Decyzje:** ADR-021. Status początkowy przez filtr `trmz_auto_confirm_bookings` (domyślnie `pending`) — #21 (ustawienia)
  jeszcze niezmergowane. Start tylko z jawnym offsetem. Token anulowania nie jest zwracany.
- **Ryzyka:** limit zapytań dochodzi w #19. Token anulowania dostępny tylko w chwili rezerwacji (M7 musi to uwzględnić).

## 2026-09-29 — #19 limit zapytań dla endpointów zapisu

- **Zrobione:** `Infrastructure\RateLimiter` (okno stałe, transienty, klucz HMAC z solą WP), `Rest\RequestLimit`
  (IP z `REMOTE_ADDR` + filtr `trmz_client_ip`, filtr `trmz_rate_limit` domyślnie 5/10 min, 429 + `Retry-After`),
  podpięte do `POST /bookings`; `Services::rate_limiter()`. Testy integracyjne: 6. żądanie → 429 z `Retry-After: 600`,
  odliczanie i reset okna, liczenie nieudanych prób, per IP i ignorowanie `X-Forwarded-For`, filtry, wyłączenie limitu,
  brak limitu dla menedżerów, IP nie jest zapisywane jawnie.
- **Decyzje:** ADR-022. Liczone są wszystkie poprawne składniowo żądania (także 409). Menedżerowie
  (`trmz_manage_bookings`) bez limitu.
- **Ryzyka:** nieatomowa inkrementacja; za proxy bez filtra `trmz_client_ip` wszyscy dzielą jeden limit (do opisania w M8).

## 2026-09-29 — #20 endpointy administracyjne rezerwacji

- **Zrobione:** `Rest\AdminBookingsController`: `GET /bookings` (filtry, wyszukiwanie, sortowanie, paginacja z `X-WP-Total`),
  `GET /bookings/{id}`, `POST /bookings/{id}/confirm|cancel|reschedule` — tylko `trmz_manage_bookings`.
  `BookingRepository::search()`/`count()` + `BookingCriteria`. Testy integracyjne (401 anonim / 403 subskrybent i redaktor bez
  uprawnienia na wszystkich trasach, reprezentacja, filtry i paginacja, maszyna stanów + hook, przeniesienie + hook, 409/422/400),
  unit `BookingCriteria`, E2E `tests/e2e/specs/rest-api.spec.js` (smoke REST na prawdziwym WP: katalog, walidacja, 401/200).
- **Decyzje:** ADR-023. `{id}` = wewnętrzne ID (panel), domyślne sortowanie po starcie rosnąco.
- **Ryzyka:** wyszukiwanie `LIKE '%…%'` po imieniu/e-mailu nie używa indeksu — przy bardzo dużej liczbie rezerwacji może
  wymagać ograniczenia zakresem dat.

## 2026-09-29 — #21 menu admina i strona ustawień

- **Zrobione:** `Infrastructure\Settings` (opcja `trmz_settings`: domyślne, zakresy, sanitizacja, typowane gettery),
  `Services::settings()` i budowanie `AvailabilitySettings` z ustawień; `Admin\AdminPage`, `Admin\Menu` (rejestr podstron,
  top-level „Terminarz”, capability `trmz_manage_bookings`), `Admin\SettingsPage` (Settings API, filtr
  `option_page_capability_trmz_settings`, ostrzeżenie o braku WooCommerce); REST `POST /bookings` bierze domyślne auto-potwierdzanie z ustawień. Testy: jednostkowe `MenuTest`, integracyjne
  `SettingsTest` (domyślne, sanitizacja, granice, kses, zapis częściowy, uszkodzone wartości, spięcie z dostępnością)
  i `SettingsPageTest` (menu, capability, rejestracja, render z escapingiem); E2E `settings.spec.js`; `.pot`.
- **Decyzje:** ADR-024. Założenia (etykieta `assumption`): wyprzedzenie w minutach (domyślnie 60), horyzont 90 dni,
  nieprawidłowa wartość z formularza zachowuje poprzednią.
- **Ryzyka:** zmiana domyślnych wyprzedzenia/horyzontu wpływa na testy używające `Services` bez jawnych ustawień dostępności
  (np. przyszłe testy REST z datami > 90 dni od „teraz”). `consent_text` i `customer_cancel_limit_hours` nie są jeszcze
  używane (blok rezerwacji / anulowanie przez klienta), `delete_data_on_uninstall` czeka na `uninstall.php` (#45).

## 2026-09-29 — #27 eksport i usuwanie danych osobowych

- **Zrobione:** `Admin\Privacy` (moduł): exporter i eraser WP dla rezerwacji po e-mailu klienta (strony po 50), sugerowany
  tekst polityki prywatności. `BookingRepository::find_by_customer_email()` / `replace_customer()`. `Admin\Labels`
  (przetłumaczone statusy, daty w strefie witryny). Testy integracyjne (rejestracja, eksport z danymi i paginacją,
  anonimizacja z zachowaniem rekordu, zachowanie nadchodzących rezerwacji, paginacja erasera, tekst polityki).
- **Decyzje:** ADR-025. Anonimizacja zamiast usunięcia; nadchodzące aktywne rezerwacje zachowane (założenie, etykieta `assumption`).
- **Ryzyka:** dopasowanie tylko po dokładnym e-mailu — rezerwacje złożone z innym adresem przez to samo konto WP nie są
  objęte (zachowawczo; ewentualne rozszerzenie o `customer_user_id` do decyzji).

## 2026-09-29 — #22 zarządzanie zasobami

- **Zrobione:** baza ekranów admina `Admin\Screen` (PRG przez `admin-post.php`, nonce + capability, `Admin\Input`,
  `Admin\Notices`), `Admin\ResourcesPage` + `ResourcesListTable` (`trmz-resources`): lista, dodawanie/edycja (nazwa, typ,
  opis, kolejność, aktywny), aktywacja/dezaktywacja z ostrzeżeniem o przyszłych rezerwacjach, usuwanie (blokowane przy
  rezerwacjach). Schemat v2 (`trmz_resources.type`), `BookableResource` + `type`/`description`/`sort_order`.
  Testy integracyjne handlerów (tworzenie, sanitizacja, walidacja z zachowaniem danych, dezaktywacja, blokada usuwania,
  capability, nonce, escaping listy), test migracji schematu, unit test typu.
- **Decyzje:** ADR-026 (klasyczne ekrany PHP zamiast React).
- **Ryzyka:** kolumna „Usługi” na liście robi zapytanie na wiersz (akceptowalne przy małej liczbie zasobów).

## 2026-09-29 — #23 zarządzanie usługami

- **Zrobione:** `Admin\ServicesPage` + `ServicesListTable` (`trmz-services`): lista (czas, cena, bufor, zasoby, status),
  formularz z walidacją (czas 1–1440, bufor 0–1440, cena ≥ 0), checkboxy zasobów, aktywacja/dezaktywacja, usuwanie
  (blokowane przy rezerwacjach). `Admin\Money` (parsowanie/formatowanie ceny, waluta z WooCommerce lub filtra).
  `Service` + `description`/`sort_order`. Testy integracyjne (tworzenie z zasobami, 9 przypadków walidacji, darmowa
  usługa, aktualizacja przypisań, blokada usuwania, capability/nonce, escaping, parsowanie kwot).
- **Decyzje:** ADR-027. Bez WooCommerce cena informacyjna, waluta z `trmz_currency` (założenie, `assumption`).
- **Ryzyka:** zmiana liczby miejsc po przecinku w WooCommerce zmienia interpretację zapisanych cen.

## 2026-09-29 — #24 harmonogram tygodniowy, przerwy i wyjątki

- **Zrobione:** `Admin\SchedulePage` (`trmz-schedule`): godziny pracy i przerwy per dzień (wiele przedziałów, bez JS),
  informacja o strefie czasowej. `Admin\ScheduleForm` (walidacja nakładania, przerwy w godzinach pracy, format, 00:00 =
  północ). `Domain\Model\ScheduleExceptionPeriod` + metody repozytorium (`save_period`, `get_period`, `periods`,
  `conflicting_periods`). `Admin\ExceptionsPage` + `ExceptionsListTable` (`trmz-exceptions`): urlopy, święta globalne,
  dni z innymi godzinami, walidacja nakładania w tym samym zakresie, ostrzeżenie o rezerwacjach. Linki „Godziny pracy”
  i „Dni wolne” przy zasobach. Testy: unit (okres), integracyjne (repozytorium, zapis/walidacja harmonogramu, wyjątki
  i ich wpływ na dostępność, konflikty, capability/nonce, escaping, filtry listy).
- **Decyzje:** ADR-028 (okres = jeden wiersz, formularz bez JS).
- **Ryzyka:** przerwa obejmująca styk dwóch przedziałów pracy jest odrzucana (trzeba ją podzielić).

## 2026-09-29 — #25 lista rezerwacji z filtrami i akcjami

- **Zrobione:** `Admin\BookingsPage` (`trmz-bookings`, pierwszy ekran menu) + `BookingsListTable` + `BookingFilters`:
  lista z widokami statusów, filtrami (usługa, zasób, daty), wyszukiwaniem, sortowaniem i paginacją; szczegóły rezerwacji;
  potwierdzanie/anulowanie przez maszynę stanów; przenoszenie z listą wolnych slotów (bez własnego terminu) i atomowym
  zapisem. Daty w strefie witryny. Testy integracyjne (lista/escaping/filtry/wyszukiwanie, paginacja, filtry → UTC,
  potwierdź/anuluj + hook + zwolnienie slotu, powrót do listy z filtrami, sloty i przeniesienie, konflikt 409 →
  komunikat, szczegóły, capability/nonce).
- **Decyzje:** ADR-029. Przeniesienie w panelu podlega tym samym regułom dostępności co rezerwacja klienta.
- **Ryzyka:** brak akcji zbiorczych i ręcznego „zakończ” (completed) — poza zakresem issue.

## 2026-09-29 — #26 eksport rezerwacji do CSV

- **Zrobione:** `Admin\BookingsCsvExporter` (strumieniowanie partiami po 100, BOM UTF-8, separator z filtra
  `trmz_csv_separator`, ochrona przed formula injection), akcja `admin_post_trmz_export_bookings` w `BookingsPage`
  (capability + nonce, nagłówki pobierania), przycisk „Eksportuj CSV” zachowujący filtry listy. Testy integracyjne
  (BOM/nagłówek/czas lokalny/cudzysłowy i nowe linie, formula injection, filtry, partie > 100 bez duplikatów, separator,
  capability/nonce, link z filtrami).
- **Decyzje:** ADR-030.
- **Ryzyka:** offsetowe partie przy równoległych zmianach mogą pominąć/zdublować wiersz na granicy partii.

## 2026-09-29 — #28 E2E konfiguracji w panelu admina

- **Zrobione:** `tests/e2e/specs/admin-configuration.spec.js` (Playwright, tryb serial): admin tworzy zasób i usługę
  w panelu, ustawia godziny pracy (7 dni + przerwa) i dzień wolny zasobu, dostępność przez REST odzwierciedla harmonogram
  i wyjątek, rezerwacja tworzona przez REST, na liście: potwierdzenie (akcja wiersza), szczegóły, anulowanie, eksport CSV
  (BOM, filtr wyszukiwania, dane rezerwacji), brak PHP notice w `debug.log`. Dane z unikalnym sufiksem (powtarzalne
  przebiegi). Drobna poprawka: komunikat o strefie czasowej dla stref zapisanych jako offset (`UTC+00:00`).
- **Decyzje:** brak nowych (ADR-010).
- **Ryzyka:** środowisko testowe gromadzi dane z kolejnych przebiegów (zasoby/usługi E2E) — nie wpływa na asercje.

## 2026-09-29 — #29 rejestracja bloku „Rezerwacja”

- **Zrobione:** blok `terminarz/booking` (`blocks/booking/block.json`, apiVersion 3, dynamiczny) z atrybutami `serviceIds`,
  `defaultServiceId`, `showResourcePicker`, `firstDayOfWeek`; edytor z InspectorControls (usługi z `/terminarz/v1/services`,
  usługa domyślna, wybór zasobu, pierwszy dzień tygodnia) i statycznym podglądem. `Blocks\BookingBlock` rejestruje blok
  z `build/`, renderuje kontener z konfiguracją w `data-trmz-config`, ustawia tłumaczenia skryptów. `webpack.config.js`
  (alias api-fetch dla front endu), `npm run test:js` (Jest) + krok w CI, build przed testami integracyjnymi w CI.
  Testy: integracyjne PHP (rejestracja, tłumaczenia, render, nonce tylko dla zalogowanych, sanitizacja atrybutów,
  escaping zgody, filtr), JS unit (helpery edytora), E2E (wstawienie, konfiguracja i zapis bloku, konfiguracja na froncie).
- **Decyzje:** ADR-031 (blok dynamiczny, konfiguracja w data-atrybucie, prywatna kopia api-fetch bez nonce dla anonimowych).
- **Ryzyka:** JSON-y tłumaczeń JS muszą pasować do ścieżek w `build/` (M8).

## 2026-09-29 — #30 front end bloku: usługa, zasób, dzień i godzina

- **Zrobione:** aplikacja React (`blocks/booking/app/`: `BookingApp`, `Step`, `ChoiceList`, `Calendar`, `Summary`,
  `DetailsStep`, `api`) montowana przez `view.js`; kroki usługa → zasób/„dowolny” → dzień (kalendarz miesiąca, dni z wolnymi
  terminami) → godzina (grupy rano/popołudnie/wieczór) → podsumowanie; pobieranie przez prywatną kopię api-fetch, stany
  ładowania/błędu/pustej dostępności, informacja o strefie witryny, nawigacja wstecz z zachowaniem wyborów. Czysta logika
  w `lib/` (kalendarz, sloty, formatowanie, konfiguracja) z testami Jest; style bloku. E2E `booking-block-steps.spec.js`
  (kroki, fokus nagłówków, powrót z zachowaniem wyborów, „dowolny” zasób, pusty stan, pomijanie kroku zasobu).
- **Decyzje:** ADR-032 (natywne radio dla wyborów, kalendarz `grid` wg APG, dostępność pobierana miesiącami, automatyczne
  przejście do kolejnego miesiąca, gdy bieżący jest pusty).
- **Ryzyka:** godziny wyświetlane w formacie 24 h niezależnie od ustawień formatu czasu witryny.
