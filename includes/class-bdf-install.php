<?php
/**
 * Installation und Schema.
 *
 * Legt bei der Aktivierung die beiden Basis-Tabellen an, erstellt das
 * Upload-Verzeichnis und vergibt die Default-Capabilities. Bewusst KEINE
 * Audit-Tabelle – die gehört zum Security-Add-on und wird dort installiert.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installations- und Schema-Routinen der Basis.
 */
class BDF_Install {

	/**
	 * Schema-Version der Basis-Tabellen. Bei Schemaänderungen erhöhen und in
	 * maybe_upgrade() eine Migration ergänzen.
	 */
	const DB_VERSION = 1;

	const OPTION_DB_VERSION = 'bdf_db_version';

	/**
	 * Plugin-Aktivierung: Tabellen, Storage-Verzeichnis, Capabilities.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_submissions_table();
		self::install_files_table();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

		BDF_File_Storage::ensure_storage_directory();
		BDF_Capabilities::bootstrap_defaults();

		/**
		 * Läuft nach der Basis-Installation. Add-ons können hier eigene
		 * Tabellen oder Defaults anlegen, wenn sie vor der Basis aktiviert
		 * wurden und auf deren Schema aufbauen.
		 *
		 * @since 0.1.0
		 */
		do_action( 'bdf_activated' );
	}

	/**
	 * Submissions-Tabelle anlegen/aktualisieren (dbDelta).
	 *
	 * Die Basis speichert den Feld-Datensatz (`payload`) als Klartext-JSON.
	 * Add-ons können ihn über den Filter `bdf_store_submission_payload`
	 * vor dem Speichern ersetzen (z. B. durch verschlüsselte Envelopes).
	 *
	 * @return void
	 */
	public static function install_submissions_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'bdf_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			post_id BIGINT UNSIGNED NOT NULL,
			form_id VARCHAR(190) NOT NULL,
			form_title VARCHAR(255) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent TEXT NULL,
			PRIMARY KEY (id),
			KEY form_lookup (post_id, form_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Datei-Metadaten-Tabelle anlegen/aktualisieren (dbDelta).
	 *
	 * Ohne Krypto-Spalten: Die Basis legt Dateien im Klartext ab. Das
	 * Security-Add-on übernimmt die Ablage komplett über den Filter
	 * `bdf_store_file` und führt seine eigenen Metadaten.
	 *
	 * @return void
	 */
	public static function install_files_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'bdf_files';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field_name VARCHAR(190) NOT NULL DEFAULT '',
			original_name VARCHAR(255) NOT NULL DEFAULT '',
			mime VARCHAR(120) NOT NULL DEFAULT '',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sha256 CHAR(64) NOT NULL DEFAULT '',
			storage_id CHAR(32) NOT NULL DEFAULT '',
			storage_path VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY submission_idx (submission_id),
			KEY storage_idx (storage_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schema-Migrationen nach Plugin-Updates (ohne erneute Aktivierung).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$ver = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $ver >= self::DB_VERSION ) {
			return;
		}
		self::install_submissions_table();
		self::install_files_table();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}
}
