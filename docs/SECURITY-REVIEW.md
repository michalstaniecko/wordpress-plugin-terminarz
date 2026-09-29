# Przegląd bezpieczeństwa — Terminarz 1.0 (#47)

Data: 2026-09-29. Zakres: `terminarz.php`, `uninstall.php`, `src/**` (PHP), `blocks/**` (JS bloku).
Poza zakresem: `vendor/`, `node_modules/`, `build/` (artefakt `blocks/`), `tests/`, WooCommerce i rdzeń WordPressa.

## Metoda

1. Ręczny przegląd kodu wg listy kontrolnej z #47 (nonces, capabilities, escaping, sanitizacja, SQL,
   `permission_callback`, tokeny, rate limit, dane w REST, CSV injection, bezpośredni dostęp do plików).
2. Niezależny przegląd drugim agentem (code-reviewer, tylko odczyt). Każde jego ustalenie zweryfikowano
   w kodzie przed przyjęciem; poniżej tylko potwierdzone.
3. PHPCS (WordPress Coding Standards 3, w tym `WordPress.Security.*` i `WordPress.DB.*`) — zero błędów;
   przejrzano każde `phpcs:ignore` / wykluczenie reguł bezpieczeństwa (sekcja „Wyłączenia PHPCS”).
4. Testy regresyjne dla każdej poprawki (PHPUnit integracyjne, Jest, E2E).

## Wyniki

| # | Waga | Problem | Status |
|---|---|---|---|
| H1 | Wysoka | Stored XSS / kradzież danych przez podrobiony kontener bloku (`data-trmz-config`) | **Poprawione** |
| M1 | Średnia | Za reverse proxy bez `trmz_client_ip` wszyscy klienci dzielą jeden limit zapytań (DoS rezerwacji) | Udokumentowane (readme.txt, FAQ); #113 |
| M2 | Średnia | Masowe blokowanie terminów anonimowymi rezerwacjami `pending`; rotacja adresów IPv6 | Częściowo poprawione (limit per /64); #112 (`needs-human`) |
| L1 | Niska | Przekierowanie na `payment_url` akceptowało dowolny host http(s) | **Poprawione** |
| L2 | Niska | Publiczne `GET /availability` bez limitu i cache (wzmocnienie obciążenia) | #114 |
| L3 | Niska | Wysyłka e-maili z treścią (imię) od atakującego na dowolny adres | #112 |
| I1 | Info | Klucz tokenów anulowania zależy od `AUTH_KEY`/`AUTH_SALT` | Udokumentowane (readme.txt, ADR-042) |
| I2 | Info | Wiersze szczegółów rezerwacji z filtra wypisywane bez escapingu | **Poprawione** (`wp_kses_post`) |
| I3 | Info | Linki w treści zgody mogą mieć `target` bez `rel="noopener"` | Zaakceptowane (tekst od administratora; przeglądarki domyślnie stosują `noopener` dla `_blank`) |
| I4 | Info | Ustawienia (e-mail powiadomień, usuwanie danych) chronione `trmz_manage_bookings`, nie `manage_options` | Zaakceptowane (capability ma tylko administrator); uwaga w readme/FAQ przy nadawaniu innym rolom |
| I5 | Info | Strona anulowania bez ochrony przed osadzaniem w ramce | **Poprawione** |
| I6 | Info | Licznik limitu zapytań nieatomowy (transient) | Zaakceptowane (ADR-022; możliwe przekroczenie o kilka żądań przy równoległych atakach) |
| I7 | Info | Pliki `src/` bez ochrony przed bezpośrednim wywołaniem | **Poprawione** (`defined( 'ABSPATH' ) || exit;`) |

### H1 — podrobiona konfiguracja bloku (poprawione)

**Problem.** `view.js` montował aplikację w każdym elemencie `.wp-block-terminarz-booking[data-trmz-config]`
i ufał konfiguracji z atrybutu. Użytkownicy bez `unfiltered_html` (Współpracownik, Autor, administrator witryny
w multisite) mogą wstawić do treści `<div class="wp-block-terminarz-booking" data-trmz-config='…'>` — kses zezwala na
`class` i dowolne `data-*`. Pole `consentHtml` było renderowane przez `dangerouslySetInnerHTML` → XSS w sesji
redaktora/administratora oglądającego podgląd; pierwszy kontener na stronie ustalał też `restRoot`, więc dane klientów
(imię, e-mail, telefon) mogły trafić na serwer atakującego.

**Poprawka (ADR-048).** Konfiguracja jest renderowana w elemencie potomnym
`<script type="application/json" class="trmz-booking__config">` (JSON z `JSON_HEX_TAG | JSON_HEX_AMP`, więc nie
może zamknąć elementu). `readConfig()` akceptuje wyłącznie bezpośrednie dziecko typu `SCRIPT` — tego kses nigdy nie
przepuszcza autorom bez `unfiltered_html`. Kontener bez takiego elementu jest ignorowany.
Testy: `tests/js/config.test.js` (podrobiony atrybut, element niebędący skryptem, zagnieżdżony skrypt),
`BookingBlockTest` (brak `data-trmz-config`, JSON nie wychodzi z elementu), E2E edytora.

### M1 — limit zapytań za proxy (udokumentowane, #113)

Zaufany jest tylko `REMOTE_ADDR` (nagłówki proxy można sfałszować). Za CDN/reverse proxy wszyscy klienci mają adres
proxy i dzielą limit 5 żądań / 10 min. readme.txt (FAQ) opisuje filtr `trmz_client_ip` z przykładem. Wykrywanie
błędnej konfiguracji (Site Health) — #113.

### M2/L3 — spam rezerwacji (częściowo poprawione, #112)

Poprawione: limit liczony dla sieci /64 adresu IPv6 (`RequestLimit::client_key()`), bo jeden abonent kontroluje zwykle
całą /64. Pozostałe mechanizmy (limit na e-mail, wygasanie niepotwierdzonych rezerwacji, CAPTCHA) zmieniają zachowanie
biznesowe — #112 z etykietą `needs-human`.

### L1 — adres płatności (poprawione)

`BookingsController::start_payment()` przyjmuje `payment_url` tylko gdy `wp_validate_redirect()` go akceptuje
(host witryny albo host dodany filtrem `allowed_redirect_hosts`, np. dla zewnętrznej strony płatności). Inaczej
rezerwacja jest anulowana, a klient dostaje błąd 500 `trmz_payment_unavailable`. Test:
`OrderPaymentsTest::test_off_site_payment_url_needs_an_allowed_redirect_host`.

### I5 — strona anulowania w ramce (poprawione)

Nagłówki `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`
(obok istniejących `noindex`, `no-referrer`, `nocache_headers()`); sprawdzane w E2E `booking-cancel-link.spec.js`.

### I7 — bezpośredni dostęp (poprawione)

Każdy plik w `src/` ma po deklaracji przestrzeni nazw `defined( 'ABSPATH' ) || exit;` (wcześniej pliki zawierały
wyłącznie definicje klas — bez skutków ubocznych, ale wywołanie bezpośrednie mogło ujawnić ścieżkę w komunikacie
o błędzie przy `display_errors`). `terminarz.php` i `uninstall.php` miały już ochronę. Bootstrap testów jednostkowych
definiuje `ABSPATH`.

## Obszary sprawdzone bez uwag

- **Nonces i capabilities.** Wszystkie akcje `admin-post` przechodzą przez `Admin\Screen::handle()`:
  `current_user_can( 'trmz_manage_bookings' )`, `check_admin_referer( 'trmz_<akcja>' )`, metoda z białej listy,
  `wp_safe_redirect`. Eksport CSV (`BookingsPage::authorize_export()`), zapis ustawień (`options.php`
  z `option_page_capability_trmz_settings`), e-maile (zapis/przywrócenie/test) — capability + nonce. Linki akcji
  z `wp_nonce_url`. Brak handlerów `admin-ajax` i akcji zbiorczych. Strony menu wymagają capability, `render()`
  sprawdza ją ponownie.
- **REST.** Każda trasa ma `permission_callback`. `__return_true`-podobne (`public_read_permissions_check`) tylko dla
  GET usług, zasobów i dostępności. Zapis publiczny (`POST /bookings`) — nonce `wp_rest` dla zalogowanych (CSRF),
  limit zapytań, honeypot, wymagana zgoda. Trasy admina — `trmz_manage_bookings`. Schematy argumentów (typ, wzorzec,
  enum, zakresy, `maxLength`, `format: email`) + `sanitize_callback`.
- **Ujawnianie danych.** Publiczne odpowiedzi nie zawierają PII, wewnętrznych ID ani tokenów; `public_id` to 128 bitów
  z `random_bytes`. `ErrorMapper` zwraca ogólne komunikaty (bez treści wyjątków i SQL).
- **Escaping.** Ekrany admina, tabele list, strona anulowania (każdy fragment przez `esc_*`), e-maile (wartości
  `esc_html`/`esc_url`, potem `wp_kses_post`; `strtr` w jednym przebiegu — znaczniki w danych klienta nie są
  rozwijane), temat e-maila bez tagów i nowych linii.
- **SQL.** Wyłącznie `$wpdb->prepare()`; `ORDER BY` i kierunek z literałów; `LIMIT/OFFSET` jako `%d`; `LIKE`
  z `esc_like()`; listy `IN()` generowane z tablic liczb; nazwy tabel ze stałych.
- **Token anulowania.** HMAC-SHA256 z 256-bitowego sekretu rezerwacji i soli witryny, `hash_equals` po walidacji
  formatu; GET bez skutków, anulowanie tylko POST (token w treści formularza jako ochrona CSRF); `no-referrer`,
  `noindex`, brak cache; nieprawidłowe tokeny i każde POST podlegają limitowi; nieznany `public_id` i zły token dają
  identyczną odpowiedź.
- **CSV.** `safe_cell()` poprzedza apostrofem komórki zaczynające się od `=`, `+`, `-`, `@`, tabulatora i CR (zestaw
  OWASP); `fputcsv` z cudzysłowami; stała nazwa pliku; `X-Content-Type-Options: nosniff`.
- **Przekierowania i niebezpieczne funkcje.** Tylko `wp_safe_redirect`; brak `unserialize`, `eval`, `extract`,
  żądań HTTP (SSRF), zapisu plików (poza `php://output`); jedyne dynamiczne wywołanie metody z białej listy.
- **WooCommerce.** Kwota liczona na serwerze (`PaymentAmount`), powiązanie zamówienie↔rezerwacja sprawdzane w obie
  strony, chronione meta `_trmz_*`, synchronizacja statusów idempotentna, bez automatycznych zwrotów.
- **Prywatność.** `To`/`Reply-To` przez `sanitize_email` + `is_email` (brak wstrzykiwania nagłówków); klucze limitu
  bez adresów IP (HMAC); eksporter i eraser danych osobowych; deinstalacja usuwa dane tylko na żądanie.

## Wyłączenia PHPCS (reguły bezpieczeństwa)

Wszystkie `phpcs:ignore` dla `WordPress.Security.*` i `WordPress.DB.*` mają komentarz z uzasadnieniem i zostały
uznane za zasadne:

- `WordPress.Security.NonceVerification` — odczyty parametrów widoku (`sanitize_key`/`absint`, bez skutków) w
  `Admin\Screen`, `BookingsPage`, `ExceptionsListTable`; strona anulowania (autoryzacja 256-bitowym tokenem z formularza).
- `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` — `Screen::handle()` i eksport CSV: capability
  i nonce sprawdzone wcześniej, każde pole sanitizowane przez `Admin\Input` / `BookingFilters`.
- `WordPress.Security.EscapeOutput.OutputNotEscaped` — dokument strony anulowania składany z escapowanych części.
  (Ignore w szczegółach rezerwacji usunięty — I2.)
- `WordPress.Security.EscapeOutput.ExceptionNotEscaped` — komunikaty wyjątków domeny/infrastruktury nigdy nie są
  wypisywane (adaptery zwracają przetłumaczone, escapowane teksty); wykluczenie w `phpcs.xml.dist` dla
  `src/Application`, `src/Infrastructure/Database|Persistence` z komentarzem.
- `WordPress.DB.PreparedSQL.*`, `WordPress.DB.DirectDatabaseQuery.*` — repozytoria własnych tabel: nazwy tabel ze
  stałych (identyfikatorów nie da się przygotować), wartości zawsze przez `prepare()`; fragmenty `WHERE` to literały lub
  generowane listy `%d`. Wykluczenia `DirectQuery`/`NoCaching`/`SchemaChange` w `phpcs.xml.dist` dla warstwy
  persystencji (ADR-012/013) z komentarzem.

## Zalecenia dla administratorów (readme.txt)

- Za reverse proxy/CDN ustawić filtr `trmz_client_ip` (M1).
- Zdefiniować `AUTH_KEY`/`AUTH_SALT` w `wp-config.php` (I1); ich zmiana unieważnia wysłane linki anulowania.
- `trmz_manage_bookings` nadawać tylko zaufanym rolom — daje dostęp do danych klientów i ustawień (I4).
- Dla zewnętrznej strony płatności dodać jej host filtrem `allowed_redirect_hosts` (L1).
