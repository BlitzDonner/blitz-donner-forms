<?php
/**
 * Datei-Ablage der Basis (Klartext).
 *
 * Uploads landen im Uploads-Ordner unter `bdfrms-files/` mit Zufallsnamen und
 * ohne Dateiendung; ein .htaccess verbietet den Direktzugriff (Apache).
 * Download läuft ausschliesslich über den berechtigungsgeprüften
 * admin_post-Endpoint, nie über eine öffentliche URL.
 *
 * Erweiterungspunkt: Der Filter `bdfrms_store_file` erlaubt Add-ons, die
 * Ablage komplett zu übernehmen (das Security-Add-on legt Dateien
 * verschlüsselt ausserhalb der Web-Wurzel ab). Liefert ein Add-on ein
 * Ergebnis, fasst die Basis die Datei nicht mehr an.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klartext-Datei-Ablage mit Add-on-Übernahmepunkt.
 */
class BDFRMS_File_Storage {

	/**
	 * Unterordner im Uploads-Verzeichnis.
	 */
	const STORAGE_SUBDIR = 'bdfrms-files';

	/**
	 * Hooks registrieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_bdf_download', array( __CLASS__, 'handle_download' ) );
	}

	// Abschnitt: Schema / Pfade.

	/**
	 * Tabellenname mit WP-Präfix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bdfrms_files';
	}

	/**
	 * Absoluter Storage-Root im Uploads-Verzeichnis.
	 *
	 * @return string Pfad ohne Slash am Ende.
	 */
	public static function storage_root() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::STORAGE_SUBDIR;
	}

	/**
	 * Storage-Verzeichnis anlegen und gegen Direktzugriff schützen
	 * (.htaccess für Apache, leeres index.php gegen Directory-Listing).
	 *
	 * @return bool true wenn das Verzeichnis existiert und beschreibbar ist.
	 */
	public static function ensure_storage_directory() {
		$root = self::storage_root();
		if ( ! wp_mkdir_p( $root ) ) {
			return false;
		}

		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Apache 2.4-Syntax; auf nginx greift der fehlende Direktlink
			// (Zufallsname ohne Endung) plus der admin_post-only-Download.
			file_put_contents( $htaccess, "Require all denied\n" );
		}

		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}

		return is_writable( $root );
	}

	// Abschnitt: Speichern.

	/**
	 * Validierten Upload ablegen und Metadaten-Zeile schreiben.
	 *
	 * Läuft VOR dem Insert der Einsendung (submission_id = 0); der Handler
	 * verbindet die Datei nach erfolgreicher Persistenz über
	 * attach_to_submission().
	 *
	 * @param array{tmp_name:string,name:string,type:string,size:int} $file       Validierter Upload (bereits durch Security-Validierung).
	 * @param string                                                  $field_name Technischer Feldname.
	 * @param string                                                  $mime       Verifizierter finfo-MIME.
	 * @param string                                                  $ext        Verifizierte Dateiendung.
	 * @return array{file_id:int,storage_id:string}|WP_Error
	 */
	public static function store( array $file, $field_name, $mime = '', $ext = '' ) {
		$context = array(
			'field_name' => (string) $field_name,
			'mime'       => (string) $mime,
			'ext'        => (string) $ext,
		);

		/**
		 * Datei-Ablage übernehmen.
		 *
		 * Gibt ein Add-on hier etwas anderes als null zurück, übernimmt es
		 * die Ablage vollständig (z. B. verschlüsselter privater Storage im
		 * Security-Add-on). Erwartetes Ergebnis wie bei der Basis:
		 * array{file_id:int,storage_id:string} oder WP_Error.
		 *
		 * @since 0.1.0
		 *
		 * @param null|array|WP_Error $result  null = Basis übernimmt.
		 * @param array               $file    Validierter Upload ($_FILES-Eintrag).
		 * @param array               $context {field_name, mime, ext}.
		 */
		$handled = apply_filters( 'bdfrms_store_file', null, $file, $context );
		if ( null !== $handled ) {
			return $handled;
		}

		if ( ! self::ensure_storage_directory() ) {
			return new WP_Error( 'bdfrms_storage_unavailable', __( 'Ablageverzeichnis nicht beschreibbar.', 'blitz-donner-forms' ) );
		}

		$storage_id = wp_generate_password( 32, false, false );
		$storage_id = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $storage_id ) );
		$storage_id = str_pad( substr( $storage_id, 0, 32 ), 32, '0' );

		$target = self::storage_root() . '/' . $storage_id;

		$moved = move_uploaded_file( $file['tmp_name'], $target );
		if ( ! $moved ) {
			// Fallback für Nicht-Upload-Kontexte (z. B. Tests, WP-CLI).
			$moved = copy( $file['tmp_name'], $target );
		}
		if ( ! $moved ) {
			return new WP_Error( 'bdfrms_store_failed', __( 'Datei konnte nicht abgelegt werden.', 'blitz-donner-forms' ) );
		}
		chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- feste Rechte auf eigener Ablage.

		global $wpdb;
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'submission_id' => 0,
				'field_name'    => sanitize_key( (string) $field_name ),
				'original_name' => sanitize_file_name( (string) $file['name'] ),
				'mime'          => sanitize_text_field( '' !== $context['mime'] ? $context['mime'] : (string) $file['type'] ),
				'size_bytes'    => (int) $file['size'],
				'sha256'        => (string) hash_file( 'sha256', $target ),
				'storage_id'    => $storage_id,
				'storage_path'  => self::STORAGE_SUBDIR . '/' . $storage_id,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_delete_file( $target );
			return new WP_Error( 'bdfrms_store_failed', __( 'Datei-Metadaten konnten nicht gespeichert werden.', 'blitz-donner-forms' ) );
		}

		return array(
			'file_id'    => (int) $wpdb->insert_id,
			'storage_id' => $storage_id,
		);
	}

	/**
	 * Gespeicherte Dateien nach erfolgreichem Submission-Insert verbinden.
	 *
	 * @param array<int,int> $file_ids      Datei-IDs aus store().
	 * @param int            $submission_id Zeilen-ID in {prefix}bdfrms_submissions.
	 * @return void
	 */
	public static function attach_to_submission( array $file_ids, $submission_id ) {
		global $wpdb;
		foreach ( $file_ids as $fid ) {
			$wpdb->update(
				self::table_name(),
				array( 'submission_id' => (int) $submission_id ),
				array( 'id' => (int) $fid ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Datei samt Metadaten-Zeile löschen (Aufräumen nach Validierungsfehlern
	 * oder beim Löschen einer Einsendung).
	 *
	 * @param int $file_id Zeilen-ID in {prefix}bdfrms_files.
	 * @return void
	 */
	public static function delete( $file_id ) {
		/**
		 * Löschen einer Datei übernehmen (Gegenstück zu `bdfrms_store_file`).
		 *
		 * Ein Add-on, das die Ablage übernommen hat, löscht hier seine
		 * eigene Ablage und gibt true zurück; die Basis fasst dann nichts
		 * mehr an.
		 *
		 * @since 0.1.0
		 *
		 * @param null|bool $handled null = Basis löscht.
		 * @param int       $file_id Datei-ID.
		 */
		$handled = apply_filters( 'bdfrms_delete_file', null, (int) $file_id );
		if ( null !== $handled ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $file_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus table_name().
		if ( ! $row ) {
			return;
		}
		$path = self::storage_root() . '/' . basename( (string) $row['storage_id'] );
		if ( is_readable( $path ) ) {
			wp_delete_file( $path );
		}
		$wpdb->delete( self::table_name(), array( 'id' => (int) $file_id ), array( '%d' ) );
	}

	/**
	 * Alle Dateien einer Einsendung löschen.
	 *
	 * @param int $submission_id Zeilen-ID in {prefix}bdfrms_submissions.
	 * @return void
	 */
	public static function delete_for_submission( $submission_id ) {
		global $wpdb;
		$table = self::table_name();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE submission_id = %d", (int) $submission_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus table_name().
		foreach ( $ids as $fid ) {
			self::delete( (int) $fid );
		}
	}

	// Abschnitt: Download.

	/**
	 * Berechtigungsgeprüfter Datei-Download (admin_post_bdf_download).
	 *
	 * @return void
	 */
	public static function handle_download() {
		if ( ! BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_VIEW_SUBMISSIONS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blitz-donner-forms' ), 403 );
		}

		$file_id = isset( $_GET['file_id'] ) ? absint( wp_unslash( $_GET['file_id'] ) ) : 0;
		check_admin_referer( 'bdfrms_download_' . $file_id );

		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $file_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus table_name().
		if ( ! $row ) {
			wp_die( esc_html__( 'Datei nicht gefunden.', 'blitz-donner-forms' ), 404 );
		}

		/**
		 * Datei-Auslieferung übernehmen (Gegenstück zu `bdfrms_store_file`).
		 *
		 * Ein Add-on, das die Ablage übernommen hat, liefert die Datei hier
		 * selbst aus (streamt und beendet die Anfrage) und gibt true zurück.
		 *
		 * @since 0.1.0
		 *
		 * @param bool  $handled false = Basis liefert aus.
		 * @param array $row     Metadaten-Zeile aus {prefix}bdfrms_files.
		 */
		$handled = apply_filters( 'bdfrms_export_file', false, $row );
		if ( true === $handled ) {
			exit;
		}

		$path = self::storage_root() . '/' . basename( (string) $row['storage_id'] );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Datei nicht lesbar.', 'blitz-donner-forms' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $row['original_name'] ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}
}
