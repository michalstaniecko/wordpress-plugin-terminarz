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
