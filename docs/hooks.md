# Hook-Oberfläche der Basis

Blitz & Donner Forms ist so gebaut, dass Add-ons sich einhängen, ohne dass die Basis sie kennt. Diese Datei ist der Vertrag: Jeder hier dokumentierte Hook bleibt über Minor-Versionen stabil. Alle Hooks tragen das Präfix `bdfrms_`. Grundsatz: Wo der Vorgänger «Blitz & Donner Formular» Krypto, Audit-Log oder ClamAV aufrief, steht in der Basis ein Hook.

## Abwehrkette

### `bdfrms_submit_chain` (Filter)

Erweitert oder ersetzt die Stufen der Abwehrkette beim Formular-Submit. Basis-Stufen in Ausführungsreihenfolge: `nonce`, `token` (HMAC mit Honeypot-Bindung), `honeypot`, `rate_limit`, `captcha`.

Signatur: `apply_filters( 'bdfrms_submit_chain', array $chain, array $form_attrs )` in `BDFRMS_Submit_Handler::handle()`. Jede Stufe ist `Stufen-ID => callable( array $form_attrs )` und liefert `true` (bestanden), einen `BDFRMS_Submit_Handler::STATUS_ERR_*`-Slug oder `array{0:Slug, 1:Detail}` (Abbruch mit Redirect zur Formularseite).

## Dateien

### `bdfrms_upload_precondition` (Filter)

Vorbedingung für Datei-Uploads eines Submits, bevor Dateien verarbeitet werden. Der Vorgänger prüfte hier die ClamAV-Erreichbarkeit. Rückgabe eines `WP_Error` lehnt den Submit ab; die Meldung wird dem Absender angezeigt.

Signatur: `apply_filters( 'bdfrms_upload_precondition', true, array $schema, array $form_attrs )` in `BDFRMS_Submit_Handler::handle()`.

### `bdfrms_validate_file` (Filter)

Validierung der Tmp-Datei durch Add-ons, nach der statischen Prüfung (Endung, finfo-MIME, accept) und VOR der Ablage. Der Vorgänger scannte hier mit ClamAV. Rückgabe eines `WP_Error` lehnt die Datei ab.

Signatur: `apply_filters( 'bdfrms_validate_file', true, array $file, string $real_mime, array $field )` in `BDFRMS_Submit_Handler::process_file_field()`.

### `bdfrms_store_file` (Filter)

Übernimmt die Datei-Ablage vollständig. Gibt ein Add-on etwas anderes als `null` zurück, fasst die Basis die Datei nicht mehr an (Security-Add-on: verschlüsselter privater Storage ausserhalb der Web-Wurzel). Basis-Verhalten: Uploads-Ordner `bdfrms-files/`, Zufallsname ohne Endung, `.htaccess`-Schutz.

Signatur: `apply_filters( 'bdfrms_store_file', null, array $file, array $context )` in `BDFRMS_File_Storage::store()`. `$context` = `{field_name, mime, ext}`. Erwartete Rückgabe wie die Basis: `array{file_id:int, storage_id:string}` oder `WP_Error`.

### `bdfrms_delete_file` (Filter)

Gegenstück zu `bdfrms_store_file` beim Löschen. Ein Add-on, das die Ablage übernommen hat, löscht hier seine eigene Ablage und gibt `true` zurück; `null` lässt die Basis löschen.

Signatur: `apply_filters( 'bdfrms_delete_file', null, int $file_id )` in `BDFRMS_File_Storage::delete()`.

### `bdfrms_export_file` (Filter)

Datei-Auslieferung im Download-Endpoint. Ein Add-on, das die Ablage übernommen hat, streamt die Datei selbst, beendet die Anfrage und gibt `true` zurück.

Signatur: `apply_filters( 'bdfrms_export_file', false, array $row )` in `BDFRMS_File_Storage::handle_download()`.

### `bdfrms_uploaded_file_post_check` (Filter)

Optionaler externer Post-Check nach der Ablage (z. B. zusätzlicher AV, DLP). Rückgabe eines `WP_Error` löscht die Datei wieder und lehnt sie ab.

Signatur: `apply_filters( 'bdfrms_uploaded_file_post_check', null, int $file_id, string $real_mime, array $field )` in `BDFRMS_Submit_Handler::process_file_field()`.

## Speichern

### `bdfrms_store_submission_payload` (Filter)

Feld-Datensatz unmittelbar vor dem INSERT in `{prefix}bdfrms_submissions`, VOR dem Label-Snapshot (nur Feldwerte). Das Security-Add-on ersetzt hier als vertraulich markierte Werte (Schema-Flag `sensitive`) durch verschlüsselte Envelopes.

Signatur: `apply_filters( 'bdfrms_store_submission_payload', array $payload, array $context, array $schema, array $form_attrs )` in `BDFRMS_Submit_Handler::handle()`. `$context` = `{post_id, form_id, form_title}`.

### `bdfrms_after_submission_insert` (Action)

Nach erfolgreicher Persistenz. Anschlusspunkt für Add-ons (Audit, Mautic/CRM, Team). Der Payload ist der GESPEICHERTE Datensatz – nach `bdfrms_store_submission_payload`, also gegebenenfalls verschlüsselt, inklusive `_bdfrms_labels`.

Signatur: `do_action( 'bdfrms_after_submission_insert', int $submission_id, array $payload, array $form_attrs, int $post_id, string $form_id )`.

### `bdfrms_submission_deleted` (Action)

Nach dem Löschen einer Einsendung im Backend (die Basis hat ihre Datei-Anhänge bereits aufgeräumt). Add-ons räumen ihre Begleitdaten auf.

Signatur: `do_action( 'bdfrms_submission_deleted', int $submission_id )` in `BDFRMS_Admin_Submissions::handle_delete()`.

## Anzeige, Mail und Export

### `bdfrms_render_field_value` (Filter)

Anzeigewert eines Felds in der Einzelansicht. Das Security-Add-on maskiert verschlüsselte Werte oder entschlüsselt sie Capability-abhängig. Die Rückgabe wird von der Basis escaped.

Signatur: `apply_filters( 'bdfrms_render_field_value', string $display, string $field_name, mixed $value, array $row )` in `BDFRMS_Admin_Submissions::render_detail()`.

### `bdfrms_notification_field_value` (Filter)

Anzeigewert eines Felds in der Benachrichtigungs-Mail. Rückgabe eines Strings übernimmt; `null` lässt die Basis formatieren (das Security-Add-on maskiert hier Envelopes).

Signatur: `apply_filters( 'bdfrms_notification_field_value', null, mixed $value )` in `BDFRMS_Submit_Handler::format_notification_field_value()`.

### `bdfrms_export_cell` (Filter)

Zellwert im CSV-Export und in der Textdatei des ZIP-Einzelexports. Das Security-Add-on erzwingt hier Berechtigungen und liefert entschlüsselte oder maskierte Werte.

Signatur: `apply_filters( 'bdfrms_export_cell', string $cell, string $field_name, array $row )` in `BDFRMS_Admin_Submissions`.

## Backend

### `bdfrms_settings_cards` (Filter)

Karten-Registry der Einstellungsseite. Add-ons ergänzen eigene Karten: `Karten-ID => array{title:string, render:callable, save:callable|null}`. `save` läuft beim zentralen Speichern, bereits nonce- und berechtigungsgeprüft.

Signatur: `apply_filters( 'bdfrms_settings_cards', array $cards )` in `BDFRMS_Admin_Settings::cards()`.

### `bdfrms_submission_actions` (Filter)

Aktionsknöpfe der Einzelansicht: `Aktions-ID => array{label:string, url:string, class:string}`. Basis: ZIP-Export, Löschen. Add-ons prüfen ihre Capability selbst.

Signatur: `apply_filters( 'bdfrms_submission_actions', array $actions, array $row )` in `BDFRMS_Admin_Submissions::render_detail()`.

### `bdfrms_capabilities` (Filter)

Capability-Registry: `Cap-Slug => array{title:string, description:string}`. Add-on-Caps erscheinen automatisch in der Berechtigungs-Matrix der Einstellungsseite. Basis-Caps: `bdfrms_view_submissions`, `bdfrms_delete_submissions`, `bdfrms_export_submissions`, `bdfrms_manage_settings`.

Signatur: `apply_filters( 'bdfrms_capabilities', array $caps )` in `BDFRMS_Capabilities::registry()`.

## Lebenszyklus und Ereignisse

### `bdfrms_activated` (Action)

Nach der Basis-Installation (Tabellen, Storage-Verzeichnis, Default-Caps). Add-ons legen hier eigene Tabellen oder Defaults an (das Security-Add-on z. B. die Audit-Tabelle).

Signatur: `do_action( 'bdfrms_activated' )` in `BDFRMS_Install::activate()`.

### `bdfrms_security_event` (Action)

Zentrales Sicherheits-Ereignis (jeder `BDFRMS_Security::log_event()`-Aufruf: Nonce-/Token-Fehler, Honeypot-Treffer, Rate-Limit, Captcha-Ergebnisse, Datei-Ablehnungen, `submission_insert`). Das Security-Add-on speist daraus sein tamper-evidentes Audit-Log.

Signatur: `do_action( 'bdfrms_security_event', string $type, array $context )` in `BDFRMS_Security::log_event()`.

## Weitere Hooks aus dem Bestand

Unverändert übernommen und stabil: `bdfrms_submit_button_validation` (externe Validierung vor dem Speichern), `bdfrms_after_server_validation`, `bdfrms_store_ip_pre` und `bdfrms_pseudonymize_ip` (DSGVO), `bdfrms_store_user_agent`, `bdfrms_rate_limit_max`, `bdfrms_trusted_proxies`, `bdfrms_allowed_mimes`, `bdfrms_blocked_extension_tokens`, `bdfrms_inner_blocks_allowed_html`, `bdfrms_success_inner_blocks_allowed_html`, `bdfrms_form_schema_markup_sources`, `bdfrms_webkit_datetime_fallback`. Signaturen stehen als Docblocks an den Aufrufstellen.
