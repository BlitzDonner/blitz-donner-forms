# Hook-Oberfläche der Basis

Blitz & Donner Forms ist so gebaut, dass Add-ons sich einhängen, ohne dass die Basis sie kennt. Diese Datei ist der Vertrag: Jeder hier dokumentierte Hook bleibt über Minor-Versionen stabil. Alle Hooks tragen das Präfix `bdfrms_`.

## Abwehrkette

### `bdfrms_submit_chain` (Filter)

Erweitert oder ersetzt die Stufen der Abwehrkette beim Formular-Submit. Basis-Stufen in Ausführungsreihenfolge: `honeypot`, `nonce`, `rate_limit`, `captcha`.

Signatur: `apply_filters( 'bdfrms_submit_chain', array $chain, array $form_attrs )` in `BDFRMS_Submit_Handler::defense_chain()`. Jede Stufe ist `Stufen-ID => callable( array $form_attrs )` und liefert `true` (bestanden) oder einen `BDFRMS_Submit_Handler::STATUS_ERR_*`-Slug (Abbruch).

## Speichern

### `bdfrms_store_submission_payload` (Filter)

Feld-Datensatz unmittelbar vor dem INSERT in `{prefix}bdfrms_submissions`. Das Security-Add-on ersetzt hier Klartextwerte durch verschlüsselte Envelopes.

Signatur: `apply_filters( 'bdfrms_store_submission_payload', array $payload, array $context )` in `BDFRMS_Submit_Handler::insert_submission()`. `$context` = `{post_id, form_id, form_title}`.

### `bdfrms_store_file` (Filter)

Übernimmt die Datei-Ablage vollständig. Gibt ein Add-on etwas anderes als `null` zurück, fasst die Basis die Datei nicht mehr an (Security-Add-on: verschlüsselter privater Storage ausserhalb der Web-Wurzel). Basis-Verhalten: Uploads-Ordner `bdfrms-files/`, Zufallsname ohne Endung, `.htaccess`-Schutz.

Signatur: `apply_filters( 'bdfrms_store_file', null, array $file, array $context )` in `BDFRMS_File_Storage::store()`. Erwartete Rückgabe wie die Basis: `array{file_id:int, storage_id:string}` oder `WP_Error`.

### `bdfrms_submission_stored` (Action)

Nach erfolgreicher Persistenz. Anschlusspunkt für Benachrichtigungen und Add-ons (Mautic/CRM, Team). Der Payload ist der gespeicherte Datensatz – nach `bdfrms_store_submission_payload`, also gegebenenfalls verschlüsselt.

Signatur: `do_action( 'bdfrms_submission_stored', int $submission_id, array $payload, array $context )`.

### `bdfrms_submission_deleted` (Action)

Nach dem Löschen einer Einsendung. Add-ons räumen Begleitdaten auf (Dateien, Audit-Einträge, CRM-Verknüpfungen).

Signatur: `do_action( 'bdfrms_submission_deleted', int $submission_id )`.

## Anzeige und Export

### `bdfrms_render_field_value` (Filter)

Anzeigewert eines Felds in der Einzelansicht. Das Security-Add-on maskiert verschlüsselte Werte oder entschlüsselt sie Capability-abhängig. Die Rückgabe wird von der Basis escaped.

Signatur: `apply_filters( 'bdfrms_render_field_value', string $display, string $field_name, mixed $value, array $row )` in `BDFRMS_Admin_Submissions::render_detail()`.

### `bdfrms_export_cell` (Filter)

Zellwert im CSV-Export. Das Security-Add-on erzwingt Berechtigungen und liefert entschlüsselte oder maskierte Werte.

Signatur: `apply_filters( 'bdfrms_export_cell', string $cell, string $field_name, array $row )` in `BDFRMS_Admin_Submissions::handle_export_csv()`.

### `bdfrms_export_file` (Filter)

Datei-Auslieferung im Download-Endpoint (Gegenstück zu `bdfrms_store_file`). Ein Add-on, das die Ablage übernommen hat, streamt die Datei selbst, beendet die Anfrage und gibt `true` zurück.

Signatur: `apply_filters( 'bdfrms_export_file', false, array $row )` in `BDFRMS_File_Storage::handle_download()`.

## Backend

### `bdfrms_settings_cards` (Filter)

Karten-Registry der Einstellungsseite. Add-ons ergänzen eigene Karten: `Karten-ID => array{title:string, render:callable, save:callable|null}`. `save` läuft beim zentralen Speichern, bereits nonce- und berechtigungsgeprüft.

Signatur: `apply_filters( 'bdfrms_settings_cards', array $cards )` in `BDFRMS_Admin_Settings::cards()`.

### `bdfrms_submission_actions` (Filter)

Aktionsknöpfe der Einzelansicht: `Aktions-ID => array{label:string, url:string, class:string}`. Add-ons prüfen ihre Capability selbst.

Signatur: `apply_filters( 'bdfrms_submission_actions', array $actions, array $row )` in `BDFRMS_Admin_Submissions::render_detail()`.

### `bdfrms_capabilities` (Filter)

Capability-Registry: `Cap-Slug => array{title:string, description:string}`. Add-on-Caps erscheinen automatisch in der Berechtigungs-Matrix der Einstellungsseite. Basis-Caps: `bdfrms_view_submissions`, `bdfrms_delete_submissions`, `bdfrms_export_submissions`, `bdfrms_manage_settings`.

Signatur: `apply_filters( 'bdfrms_capabilities', array $caps )` in `BDFRMS_Capabilities::registry()`.

## Lebenszyklus

### `bdfrms_activated` (Action)

Nach der Basis-Installation (Tabellen, Storage-Verzeichnis, Default-Caps). Add-ons legen hier eigene Tabellen oder Defaults an.

Signatur: `do_action( 'bdfrms_activated' )` in `BDFRMS_Install::activate()`.
