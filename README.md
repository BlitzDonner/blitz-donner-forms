# Blitz & Donner Forms

Block-native Formulare für den WordPress-Editor. Gratis-Basis für WordPress.org, erweiterbar durch Add-ons über [plugins.blitzdonner.ch](https://plugins.blitzdonner.ch).

## Status

Etappe 1 – Gerüst. Aktivierbares Plugin-Skelett mit Datenbank-Schema, Capability-Modell, Friendly-Captcha-Integration, Einstellungs-Karten, Einsendungen-Backend und der vollständigen, dokumentierten Hook-Oberfläche für Add-ons. Die Formular- und Feld-Blöcke folgen in Etappe 2 (Übernahme aus dem Bestand «Blitz & Donner Formular»).

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

## Entwicklung

```bash
composer install       # Dev-Tools (PHPCS mit WordPress Coding Standards)
composer run lint      # PHP-Syntaxprüfung
composer run phpcs     # Coding-Standards-Prüfung
```

Updates der Basis kommen ausschliesslich von WordPress.org; die Basis enthält keinen Update-Client. Einziger optionaler externer Dienst ist Friendly Captcha (in `readme.txt` deklariert).
