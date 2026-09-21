# Bericht: Bugfix – fehlendes `method="POST"` bei Netlify-Formularen

Branch: `main` (unabhängig vom WordPress.org-Review)

## Problem
Für Netlify-Formulare ergänzte das Plugin `data-netlify="true"`, einen eindeutigen Namen und das versteckte `form-name`-Feld, aber nicht `method="POST"`. Netlify Forms fängt ausschließlich POST-Requests ab. Ohne explizites `method` (oder mit `method="get"`) sendet der Browser per GET. Die Einsendung wirkt erfolgreich, erreicht Netlifys Formular-Backend aber nie.

## Änderungen
1. `lib/class-content2html-generator.php`, `prepareFormForNetlify()`: direkt nach der `data-netlify`-Prüfung wurde ein Block ergänzt. Er setzt `method="POST"`, wenn das Attribut fehlt oder nicht (case-insensitiv) `post` ist. Der Erklärungskommentar steht im Code.
2. `includes/class-content2html-forms.php`: Der Docblock am Dateianfang nennt jetzt auch `method="POST"` in der Netlify-Strategie.

## Prüfung
- `php -l lib/class-content2html-generator.php`: keine Syntaxfehler
- `php -l includes/class-content2html-forms.php`: keine Syntaxfehler
- Testskript (Reflection auf `prepareFormForNetlify()`, ohne WordPress-Konstruktor):

| Fall | Ausgangs-HTML | Ergebnis | method-Attribute |
|---|---|---|---|
| a) | kein `method` | `method="POST"` ergänzt | 1 |
| b) | `method="get"` / `"GET"` | auf `method="POST"` korrigiert | 1 |
| c) | `method="POST"` | unverändert | 1 |
| c2) | `method="post"` (klein) | unverändert (Schreibweise bleibt) | 1 |

Es entstehen keine doppelten Attribute.

## Hinweise
- Formulare mit kleingeschriebenem `method="post"` werden bewusst nicht angefasst, da HTML das Attribut case-insensitiv behandelt.
- Nicht committet. Änderungen liegen im Working Tree von `main`.
