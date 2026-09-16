=== BillTo for WooCommerce ===
Contributors: billto
Tags: faktury, ksef, woocommerce, invoice, e-faktura
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Faktury VAT i KSeF dla zamówień WooCommerce przez BillTo: zamówienie trafia do BillTo, po opłaceniu powstaje faktura, PDF w koncie klienta.

== Description ==

BillTo for WooCommerce łączy sklep z kontem w [BillTo](https://billto.pl) - polskim systemem do fakturowania z obsługą KSeF.

* Każde zamówienie WooCommerce (albo tylko te z zaznaczonym „chcę fakturę") jest odwzorowywane jako zamówienie w BillTo.
* Gdy zamówienie przejdzie w status oznaczający zapłatę, BillTo wystawia fakturę VAT. Ponowienia i podwójne webhooki płatności nie tworzą duplikatów.
* Faktura może zostać automatycznie przekazana do KSeF.
* Klient otrzymuje fakturę e-mailem z BillTo, jako załącznik e-maila WooCommerce albo pobiera ją z „Moje konto" - do wyboru w ustawieniach.
* Pole NIP i „chcę fakturę" w koszyku (klasycznym i blokowym) z walidacją sumy kontrolnej.
* Rodzaje nabywców: osoby fizyczne i firmy z Polski, konsumenci z UE (faktura OSS, polski VAT poniżej progu albo bez faktury), firmy z UE (towary 0% WDT, usługi "np"), nabywcy spoza UE (0% eksport, "np"). Towar/usługa ustawiane na produkcie.
* Wybór serii numeracji (VAT, korekty, OSS) z konta BillTo.
* Zwroty z pozycjami tworzą faktury korygujące.
* Panel zamówienia pokazuje numer faktury, PDF, stronę publiczną z danymi do przelewu i status KSeF; dostępne są akcje ręczne.

Wtyczka komunikuje się z API BillTo (https://billto.pl/api/v1 albo https://sandbox.billto.pl/api/v1) i przesyła tam dane zamówień oraz nabywców w celu wystawienia faktury. Wymaga konta BillTo z dostępem do API.

== Installation ==

1. Zainstaluj i aktywuj wtyczkę (wymaga WooCommerce 8.2+ i PHP 8.0+).
2. W BillTo wygeneruj token: Ustawienia -> API tokens, z uprawnieniami orders:read, orders:write, invoices:read, invoices:write (oraz ksef:send, jeśli faktury mają trafiać do KSeF automatycznie).
3. W WooCommerce -> Ustawienia -> BillTo wklej token, wybierz środowisko i tryb fakturowania, zapisz, kliknij „Testuj połączenie".
4. Upewnij się, że zespół w BillTo ma domyślną serię faktur VAT (i serię KOR, jeśli włączasz korekty ze zwrotów).

== Frequently Asked Questions ==

= Kiedy powstaje faktura? =

Gdy zamówienie przechodzi w jeden ze statusów oznaczonych w ustawieniach jako „zapłata" (domyślnie „W realizacji" i „Zrealizowane"). Faktura dla zamówienia powstaje tylko raz.

= Czy faktura zostanie wysłana podwójnie? =

Nie. W trybie „e-mail z BillTo" wysyła ją BillTo, w pozostałych trybach BillTo nie wysyła nic, a wtyczka dołącza PDF do e-maila WooCommerce albo pokazuje link w koncie klienta.

= Co ze zwrotami? =

Zwrot z zaznaczonymi pozycjami tworzy w BillTo zamówienie korygujące i fakturę korygującą (ilości po zwrocie). Zwrot samej kwoty jest domyślnie księgowany jako proporcjonalna obniżka ceny każdej pozycji; można zamiast tego wybrać tylko notatkę i korektę ręczną.

= Sklep sprzedaje w cenach brutto, czy kwoty się zgodzą? =

Tak. Domyślnie wtyczka przesyła ceny brutto (tak jak ustawiono w WooCommerce), a BillTo wylicza netto i VAT od brutto, więc suma faktury równa się kwocie zapłaconej. Sklepy z cenami netto mogą przełączyć tryb w ustawieniach.

= Płatność za pobraniem - faktura opłacona przed odbiorem? =

Nie. Dla metod oznaczonych jako odroczone (domyślnie pobranie i przelew) faktura jest wystawiana jako nieopłacona, a wpłata dopisywana po przejściu zamówienia w wybrany status (domyślnie „Zrealizowane").

= Faktura 0% WDT bez sprawdzenia numeru VAT UE? =

Domyślnie wtyczka sprawdza numer nabywcy w VIES i przy nieaktywnym numerze nie wystawia faktury (notatka w zamówieniu). Można zamiast tego wystawić fakturę ze stawkami polskimi albo wyłączyć sprawdzanie.

== Changelog ==

= 1.0.0 =
* Pierwsze stabilne wydanie.
