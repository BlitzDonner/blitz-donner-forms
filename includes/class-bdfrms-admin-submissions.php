<?php
/**
 * Einsendungen im Backend: Liste, Einzelansicht, Löschen, CSV-Export.
 *
 * Etappe-1-Gerüst mit vollständiger Hook-Oberfläche:
 *  - `bdfrms_render_field_value`  Anzeige eines Feldwerts in der Einzelansicht
 *  - `bdfrms_submission_actions`  Aktionsknöpfe der Einzelansicht
 *  - `bdfrms_export_cell`         Zellwert im CSV-Export
 *
 * Die komfortable Liste (Filter, Suche, Spaltenwahl) folgt in Etappe 2 aus
 * dem Bestand.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend für Einsendungen: Liste, Detail, Löschen, Export.
 */
class BDFRMS_Admin_Submissions {

	const MENU_SLUG = 'bdfrms-submissions';

	/**
	 * Hooks registrieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bdf_delete_submission', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_bdf_export_csv', array( __CLASS__, 'handle_export_csv' ) );
	}

	/**
	 * Menüpunkt registrieren.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Formulare', 'blitz-donner-forms' ),
			__( 'Formulare', 'blitz-donner-forms' ),
			BDFRMS_Capabilities::CAP_VIEW_SUBMISSIONS,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-feedback',
			26
		);
	}

	/**
	 * Tabellenname mit WP-Präfix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bdfrms_submissions';
	}

	/**
	 * Router: Einzelansicht oder Liste.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_VIEW_SUBMISSIONS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blitz-donner-forms' ), 403 );
		}
		$submission_id = isset( $_GET['submission'] ) ? absint( wp_unslash( $_GET['submission'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Leseansicht, Berechtigung oben geprüft.
		if ( $submission_id > 0 ) {
			self::render_detail( $submission_id );
			return;
		}
		self::render_list();
	}

	/**
	 * Liste der Einsendungen (neueste zuerst).
	 *
	 * @return void
	 */
	protected static function render_list() {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT id, created_at, form_title, form_id, post_id FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus table_name(), keine Nutzereingabe.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Einsendungen', 'blitz-donner-forms' ); ?></h1>
			<?php if ( BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_EXPORT_SUBMISSIONS ) ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdfrms_export_csv' ), 'bdfrms_export_csv' ) ); ?>">
						<?php esc_html_e( 'CSV-Export', 'blitz-donner-forms' ); ?>
					</a>
				</p>
			<?php endif; ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'Noch keine Einsendungen.', 'blitz-donner-forms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nr.', 'blitz-donner-forms' ); ?></th>
							<th><?php esc_html_e( 'Eingang', 'blitz-donner-forms' ); ?></th>
							<th><?php esc_html_e( 'Formular', 'blitz-donner-forms' ); ?></th>
							<th><?php esc_html_e( 'Seite', 'blitz-donner-forms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page' => self::MENU_SLUG,
												'submission' => (int) $row['id'],
											),
											admin_url( 'admin.php' )
										)
									);
									?>
												">
										#<?php echo (int) $row['id']; ?>
									</a>
								</td>
								<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
								<td><?php echo esc_html( '' !== (string) $row['form_title'] ? (string) $row['form_title'] : (string) $row['form_id'] ); ?></td>
								<td><?php echo esc_html( get_the_title( (int) $row['post_id'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Einzelansicht einer Einsendung.
	 *
	 * @param int $submission_id Zeilen-ID.
	 * @return void
	 */
	protected static function render_detail( $submission_id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $submission_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus table_name().
		if ( ! $row ) {
			wp_die( esc_html__( 'Einsendung nicht gefunden.', 'blitz-donner-forms' ), 404 );
		}

		$payload = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$actions = array();
		if ( BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_DELETE_SUBMISSIONS ) ) {
			$actions['delete'] = array(
				'label' => __( 'Löschen', 'blitz-donner-forms' ),
				'url'   => wp_nonce_url(
					admin_url( 'admin-post.php?action=bdfrms_delete_submission&submission=' . (int) $submission_id ),
					'bdfrms_delete_submission_' . (int) $submission_id
				),
				'class' => 'button button-link-delete',
			);
		}

		/**
		 * Aktionsknöpfe der Einzelansicht erweitern.
		 *
		 * Jeder Eintrag: Aktions-ID => array{label:string,url:string,class:string}.
		 * Add-ons ergänzen eigene Aktionen (z. B. «Entschlüsselt anzeigen»
		 * im Security-Add-on) und prüfen ihre Capability selbst.
		 *
		 * @since 0.1.0
		 *
		 * @param array $actions Aktionen der Basis.
		 * @param array $row     Zeile aus {prefix}bdfrms_submissions.
		 */
		$actions = apply_filters( 'bdfrms_submission_actions', $actions, $row );
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %d: submission ID. */
				echo esc_html( sprintf( __( 'Einsendung #%d', 'blitz-donner-forms' ), (int) $submission_id ) );
				?>
			</h1>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) ); ?>">&larr; <?php esc_html_e( 'Zurück zur Liste', 'blitz-donner-forms' ); ?></a>
			</p>
			<table class="widefat striped" style="max-width:800px;">
				<tbody>
					<tr>
						<th style="width:200px;"><?php esc_html_e( 'Eingang', 'blitz-donner-forms' ); ?></th>
						<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
					</tr>
					<?php foreach ( $payload as $field_name => $value ) : ?>
						<tr>
							<th><?php echo esc_html( (string) $field_name ); ?></th>
							<td>
								<?php
								$display = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );

								/**
								 * Anzeige eines Feldwerts in der Einzelansicht.
								 *
								 * Das Security-Add-on maskiert hier verschlüsselte
								 * Werte oder entschlüsselt sie Capability-abhängig.
								 * Rückgabe: anzeigefertiger Klartext (wird escaped).
								 *
								 * @since 0.1.0
								 *
								 * @param string $display    Anzeigewert der Basis.
								 * @param string $field_name Feldname.
								 * @param mixed  $value      Gespeicherter Rohwert.
								 * @param array  $row        Zeile aus {prefix}bdfrms_submissions.
								 */
								$display = apply_filters( 'bdfrms_render_field_value', $display, (string) $field_name, $value, $row );
								echo esc_html( (string) $display );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<?php foreach ( $actions as $action ) : ?>
					<a class="<?php echo esc_attr( isset( $action['class'] ) ? (string) $action['class'] : 'button' ); ?>" href="<?php echo esc_url( (string) $action['url'] ); ?>">
						<?php echo esc_html( (string) $action['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Einsendung löschen (admin_post_bdf_delete_submission).
	 *
	 * @return void
	 */
	public static function handle_delete() {
		if ( ! BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_DELETE_SUBMISSIONS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blitz-donner-forms' ), 403 );
		}
		$submission_id = isset( $_GET['submission'] ) ? absint( wp_unslash( $_GET['submission'] ) ) : 0;
		check_admin_referer( 'bdfrms_delete_submission_' . $submission_id );

		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => $submission_id ), array( '%d' ) );

		/**
		 * Läuft nach dem Löschen einer Einsendung.
		 *
		 * Add-ons räumen hier ihre Begleitdaten auf (Dateien, Audit-Einträge,
		 * CRM-Verknüpfungen).
		 *
		 * @since 0.1.0
		 *
		 * @param int $submission_id Gelöschte Zeilen-ID.
		 */
		do_action( 'bdfrms_submission_deleted', $submission_id );

		wp_safe_redirect( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * CSV-Export aller Einsendungen (admin_post_bdf_export_csv).
	 *
	 * @return void
	 */
	public static function handle_export_csv() {
		if ( ! BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_EXPORT_SUBMISSIONS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blitz-donner-forms' ), 403 );
		}
		check_admin_referer( 'bdfrms_export_csv' );

		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname aus table_name(), keine Nutzereingabe.

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bdfrms-einsendungen-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8-BOM, damit Excel Umlaute korrekt liest.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Spalten: feste Metadaten + Vereinigungsmenge aller Payload-Felder.
		$field_names = array();
		$payloads    = array();
		foreach ( $rows as $row ) {
			$payload                      = json_decode( (string) $row['payload'], true );
			$payloads[ (int) $row['id'] ] = is_array( $payload ) ? $payload : array();
			$field_names                  = array_merge( $field_names, array_keys( $payloads[ (int) $row['id'] ] ) );
		}
		$field_names = array_values( array_unique( $field_names ) );

		fputcsv( $out, array_merge( array( 'id', 'created_at', 'form_title' ), $field_names ) );

		foreach ( $rows as $row ) {
			$line = array( (int) $row['id'], (string) $row['created_at'], (string) $row['form_title'] );
			foreach ( $field_names as $field_name ) {
				$value = isset( $payloads[ (int) $row['id'] ][ $field_name ] ) ? $payloads[ (int) $row['id'] ][ $field_name ] : '';
				$cell  = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );

				/**
				 * Zellwert im CSV-Export.
				 *
				 * Das Security-Add-on erzwingt hier Berechtigungen und
				 * liefert entschlüsselte oder maskierte Werte.
				 *
				 * @since 0.1.0
				 *
				 * @param string $cell       Zellwert der Basis.
				 * @param string $field_name Feldname (Spalte).
				 * @param array  $row        Zeile aus {prefix}bdfrms_submissions.
				 */
				$cell = apply_filters( 'bdfrms_export_cell', $cell, $field_name, $row );

				// Schutz vor CSV-Injection in Tabellenkalkulationen.
				if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@' ), true ) ) {
					$cell = "'" . $cell;
				}
				$line[] = $cell;
			}
			fputcsv( $out, $line );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream-Export.
		exit;
	}
}
