# [EPIC] Plugin „Terminarz” — system rezerwacji terminów v1.0

## Cel

Plugin WordPress pozwalający firmom usługowym (gabinety, sale, instruktorzy) udostępniać klientom
rezerwację terminów online, z opcjonalną płatnością przez WooCommerce i automatycznymi powiadomieniami.

## Użytkownicy i scenariusze

**Administrator / właściciel firmy**
- Definiuje zasoby (osoba, sala, urządzenie) i usługi (nazwa, czas trwania, cena, bufor po usłudze).
- Ustala godziny pracy zasobu, przerwy, wyjątki (urlopy, święta, dni z innymi godzinami).
- Przegląda, filtruje, potwierdza, przenosi i anuluje rezerwacje; eksportuje je do CSV.

**Klient**
- Na stronie z blokiem „Rezerwacja” wybiera usługę → zasób (lub „dowolny”) → dzień → wolny slot.
- Podaje dane kontaktowe, akceptuje zgodę, opcjonalnie płaci (zaliczka lub całość).
- Dostaje e-mail z potwierdzeniem i przypomnienie przed wizytą; może anulować rezerwację linkiem z maila
  (w granicach limitu czasowego ustawionego przez admina).

## Zakres (milestone'y)

1. **M1 — Fundamenty:** szkielet pluginu, wp-env, PHPUnit, Playwright, PHPCS, PHPStan, GitHub Actions.
2. **M2 — Model danych i silnik dostępności:** zasoby, usługi, harmonogramy, wyjątki, rezerwacje (własne tabele);
   silnik wyliczający wolne sloty (czysta logika domenowa, pokryta testami, w tym DST i strefy czasowe);
   atomowe zajmowanie slotu.
3. **M3 — REST API:** endpointy dostępności (publiczne, tylko odczyt) i rezerwacji (zapis z weryfikacją i limitem zapytań).
4. **M4 — Panel admina:** ekrany zasobów, usług, harmonogramów, lista rezerwacji z filtrami, eksport CSV, ustawienia.
5. **M5 — Blok frontendowy:** blok Gutenberga „Rezerwacja” (React) z konfiguracją w edytorze,
   dostępny (WCAG 2.2 AA, obsługa klawiatury), responsywny.
6. **M6 — Płatności WooCommerce:** rezerwacja tworzy zamówienie; statusy zamówienia synchronizują status rezerwacji;
   wstrzymanie slotu na czas płatności z wygasaniem; działanie bez WooCommerce.
7. **M7 — Powiadomienia:** e-maile (potwierdzenie, przypomnienie przez WP-Cron / Action Scheduler, anulowanie)
   z edytowalnymi szablonami; link anulowania z bezpiecznym tokenem.
8. **M8 — Wydanie 1.0:** i18n (.pot + tłumaczenie PL), `readme.txt`, uninstall, testy na multisite,
   przegląd bezpieczeństwa, paczka ZIP budowana w CI.

## Wymagania niefunkcjonalne

- PHP 8.1+, WordPress 6.5+, WooCommerce 8.0+ (opcjonalnie).
- Brak podwójnych rezerwacji także przy równoczesnych żądaniach (test współbieżności wymagany).
- Endpoint dostępności odpowiada < 300 ms dla 30 dni i 10 zasobów na standardowym wp-env.
- Zgodność z WordPress Coding Standards; zero błędów PHPStan na poziomie 6.
- Dane osobowe klientów: integracja z narzędziami eksportu/usuwania danych osobowych WordPressa.

## Poza zakresem v1.0

Synchronizacja z Google/Outlook Calendar, SMS, rezerwacje cykliczne, wiele lokalizacji, aplikacja mobilna.
(Można utworzyć dla nich issues z etykietą `later`, bez milestone'u.)

## Kryteria akceptacji epiku

- [ ] Wszystkie issues z milestone'ów M1–M8 zamknięte
- [ ] Pełna ścieżka E2E zielona: admin konfiguruje zasób i usługę → klient rezerwuje i płaci w sandboxie →
      przychodzi e-mail → admin widzi rezerwację → klient anuluje przez link → slot znów wolny
- [ ] Ta sama ścieżka działa bez aktywnego WooCommerce (bez kroku płatności)
- [ ] CI buduje instalowalną paczkę ZIP
- [ ] Otwarty PR `develop → main` z podsumowaniem wydania i listą założeń (`assumption`) do przeglądu

## Instrukcje dla Claude

1. Zacznij od przeczytania `CLAUDE.md`.
2. Utwórz milestone'y M1–M8, a następnie podziel każdy na issues (zwykle 4–10 na milestone)
   z kryteriami akceptacji, etykietami i zależnościami. Podlinkuj je w komentarzu pod tym epikiem.
3. Realizuj milestone'y po kolei. Po zakończeniu każdego napisz komentarz pod epikiem:
   co zostało dostarczone, jakie założenia przyjęto, co wymaga uwagi człowieka.
4. Nie czekaj na akceptację między milestone'ami, chyba że issue ma etykietę `needs-human`
   blokującą dalszą pracę.
