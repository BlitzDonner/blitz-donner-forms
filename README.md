# Blitz & Donner Forms

Block-native Formulare für den WordPress-Editor. Gratis-Basis für WordPress.org, erweiterbar durch Add-ons über [plugins.blitzdonner.ch](https://plugins.blitzdonner.ch).

## Status

Etappe 2 – Basis-Extraktion abgeschlossen. Formular- und Feld-Blöcke, Submit-Pfad mit Abwehrkette, Benachrichtigung, Erscheinungsbild-System, Entwurfs-Speicherung und Einsendungen-Backend sind aus dem Bestand «Blitz & Donner Formular» übernommen und auf bdfrms umbenannt. Verschlüsselung, Audit-Log und ClamAV sind durch Hooks ersetzt – sie kommen als Security-Add-on (Etappe 3).

## Kennungen

| Ebene | Wert |
|---|---|
| Slug / Textdomain | `blitz-donner-forms` |
| Code-Präfix | `bdfrms_` / Klassen `BDFRMS_` |
| Block-Namespace | `bdfrms/*` |
| DB-Tabellen | `{prefix}bdfrms_submissions`, `{prefix}bdfrms_files` |
| Lizenz | GPL-2.0-or-later |

## Architektur

Die Basis ist eigenständig vollwertig und kennt ihre Add-ons nicht. Add-ons (Security, Compliance, Mautic/CRM, Team) hängen sich ausschliesslich über die dokumentierte Hook-Oberfläche ein – siehe [docs/hooks.md](docs/hooks.md).

Grundsatz: Jede Stelle, an der der Vorgänger Krypto, Audit-Log oder ClamAV aufruft, ist in der Basis ein Hook. Verschlüsselung, Audit und Virenscan liefert das Security-Add-on.

## Theming über theme.json

Die Formularfarben lassen sich site-weit über `settings.custom.bdfrms` in der theme.json setzen (Kaskade: Feld-Block → Formular-Block → theme.json → eingebauter Default). Token-Namen in Kebab-Schreibweise, je `light-*` und `dark-*`:

```json
{
    "settings": {
        "custom": {
            "bdfrms": {
                "light-label": "#6e6e73",
                "light-border-focus": "#0071e3",
                "dark-bg": "#2c2c2e"
            }
        }
    }
}
```

Verfügbare Tokens: `label`, `text`, `placeholder`, `bg`, `border`, `border-focus`, `submit-bg`, `submit-text`, `form-shell`. Die Blöcke deklarieren `example`-Vorschauen und erscheinen damit im Stilbuch des Site Editors. Über diese Schnittstelle kann auch ein Design-System-Werkzeug (z.B. der Design-System-Generator) die Formulare aus theme.json speisen.

## Entwicklung

```bash
composer install       # Dev-Tools (PHPCS mit WordPress Coding Standards)
composer run lint      # PHP-Syntaxprüfung
composer run phpcs     # Coding-Standards-Prüfung
```

Updates der Basis kommen ausschliesslich von WordPress.org; die Basis enthält keinen Update-Client. Einziger optionaler externer Dienst ist Friendly Captcha (in `readme.txt` deklariert).
