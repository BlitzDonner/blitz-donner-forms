<?php
/**
 * Einstellungsseite als Karten-Cockpit.
 *
 * Jede Funktionsgruppe ist eine Karte in einer Registry. Die Basis bringt
 * die Karten «Spam-Schutz» (Friendly Captcha), «Berechtigungen» und
 * «Erweiterungen» mit; Add-ons ergänzen eigene Karten über den Filter
 * `bdfrms_settings_cards`, ohne diese Datei anzufassen.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einstellungsseite als erweiterbares Karten-Cockpit.
 */
class BDFRMS_Admin_Settings {

	const PAGE_SLUG = 'bdfrms-settings';

	/**
	 * Hooks registrieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bdfrms_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Menüpunkt registrieren.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			BDFRMS_Admin_Submissions::MENU_SLUG,
			__( 'Einstellungen', 'blitz-donner-forms' ),
			__( 'Einstellungen', 'blitz-donner-forms' ),
			BDFRMS_Capabilities::CAP_MANAGE_SETTINGS,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Karten-Registry der Einstellungsseite.
	 *
	 * @return array<string,array{title:string,render:callable,save:callable|null}>
	 */
	public static function cards() {
		$cards = array(
			'captcha'      => array(
				'title'  => __( 'Spam-Schutz', 'blitz-donner-forms' ),
				'render' => array( __CLASS__, 'render_card_captcha' ),
				'save'   => array( __CLASS__, 'save_card_captcha' ),
			),
			'capabilities' => array(
				'title'  => __( 'Berechtigungen', 'blitz-donner-forms' ),
				'render' => array( __CLASS__, 'render_card_capabilities' ),
				'save'   => array( __CLASS__, 'save_card_capabilities' ),
			),
			'extensions'   => array(
				'title'  => __( 'Erweiterungen', 'blitz-donner-forms' ),
				'render' => array( __CLASS__, 'render_card_extensions' ),
				'save'   => null,
			),
		);

		/**
		 * Karten der Einstellungsseite erweitern.
		 *
		 * Add-ons registrieren hier eigene Karten. Jeder Eintrag:
		 * Karten-ID => array{title:string, render:callable, save:callable|null}.
		 * `render` gibt das Karten-HTML aus; `save` verarbeitet den
		 * zugehörigen POST-Teil beim zentralen Speichern (bereits nonce- und
		 * berechtigungsgeprüft).
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,array{title:string,render:callable,save:callable|null}> $cards Karten der Basis.
		 */
		$filtered = apply_filters( 'bdfrms_settings_cards', $cards );

		return is_array( $filtered ) ? $filtered : $cards;
	}

	/**
	 * Seite rendern: alle registrierten Karten in Registrier-Reihenfolge.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blitz-donner-forms' ), 403 );
		}
		$saved = isset( $_GET['bdfrms_saved'] ) ? '1' === sanitize_text_field( wp_unslash( $_GET['bdfrms_saved'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige einer Erfolgsmeldung.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Blitz & Donner Forms – Einstellungen', 'blitz-donner-forms' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Einstellungen gespeichert.', 'blitz-donner-forms' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bdfrms_save_settings" />
				<?php wp_nonce_field( 'bdfrms_save_settings' ); ?>
				<?php foreach ( self::cards() as $card_id => $card ) : ?>
					<div class="card" style="max-width:720px;margin-bottom:16px;">
						<h2><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<?php
						if ( isset( $card['render'] ) && is_callable( $card['render'] ) ) {
							call_user_func( $card['render'] );
						}
						?>
					</div>
				<?php endforeach; ?>
				<?php submit_button( __( 'Speichern', 'blitz-donner-forms' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Zentrales Speichern: reicht den POST an die save-Callables aller
	 * Karten weiter (eine Nonce, eine Berechtigungsprüfung).
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! BDFRMS_Capabilities::user_can( BDFRMS_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'blitz-donner-forms' ), 403 );
		}
		check_admin_referer( 'bdfrms_save_settings' );

		foreach ( self::cards() as $card ) {
			if ( isset( $card['save'] ) && is_callable( $card['save'] ) ) {
				call_user_func( $card['save'] );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'bdfrms_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// Abschnitt: Karte: Spam-Schutz (Friendly Captcha).

	/**
	 * Karte «Spam-Schutz» rendern.
	 *
	 * @return void
	 */
	public static function render_card_captcha() {
		$s = BDFRMS_Captcha::get_settings();
		?>
		<p class="description"><?php esc_html_e( 'Friendly Captcha ist ein Spam-Schutz ohne Cookies und ohne Tracking (Proof-of-Work, Verarbeitung in der EU). Bei Aktivierung wird zur Prüfung der Eingabe der externe Dienst Friendly Captcha aufgerufen.', 'blitz-donner-forms' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Aktiv', 'blitz-donner-forms' ); ?></th>
				<td><label><input type="checkbox" name="bdfrms_captcha[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Friendly Captcha global einschalten', 'blitz-donner-forms' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="bdfrms-captcha-site-key"><?php esc_html_e( 'Site-Key', 'blitz-donner-forms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="bdfrms-captcha-site-key" name="bdfrms_captcha[site_key]" value="<?php echo esc_attr( $s['site_key'] ); ?>" autocomplete="off" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="bdfrms-captcha-api-key"><?php esc_html_e( 'API-Key', 'blitz-donner-forms' ); ?></label></th>
				<td><input type="password" class="regular-text" id="bdfrms-captcha-api-key" name="bdfrms_captcha[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>" autocomplete="off" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Modus', 'blitz-donner-forms' ); ?></th>
				<td>
					<label><input type="radio" name="bdfrms_captcha[mode]" value="soft" <?php checked( 'soft', $s['mode'] ); ?> /> <?php esc_html_e( 'Soft – bei nicht erreichbarem Anbieter durchlassen', 'blitz-donner-forms' ); ?></label><br />
					<label><input type="radio" name="bdfrms_captcha[mode]" value="strict" <?php checked( 'strict', $s['mode'] ); ?> /> <?php esc_html_e( 'Strict – bei nicht erreichbarem Anbieter ablehnen', 'blitz-donner-forms' ); ?></label>
				</td>
			</tr>
		</table>
		<details style="margin-top:12px;">
			<summary><?php esc_html_e( 'Textbaustein für die Datenschutzerklärung (kopierbar)', 'blitz-donner-forms' ); ?></summary>
			<textarea readonly rows="10" style="width:100%;margin-top:8px;" onclick="this.select();"><?php echo esc_textarea( BDFRMS_Captcha::privacy_text_snippet() ); ?></textarea>
		</details>
		<details style="margin-top:8px;">
			<summary><?php esc_html_e( 'Interessenabwägung (LIA) als interne Vorlage (kopierbar)', 'blitz-donner-forms' ); ?></summary>
			<textarea readonly rows="10" style="width:100%;margin-top:8px;" onclick="this.select();"><?php echo esc_textarea( BDFRMS_Captcha::lia_text_snippet() ); ?></textarea>
		</details>
		<?php
	}

	/**
	 * Karte «Spam-Schutz» speichern.
	 *
	 * @return void
	 */
	public static function save_card_captcha() {
		if ( ! isset( $_POST['bdfrms_captcha'] ) || ! is_array( $_POST['bdfrms_captcha'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in handle_save().
			return;
		}
		$raw = map_deep( wp_unslash( $_POST['bdfrms_captcha'] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		BDFRMS_Captcha::update_settings( $raw );
	}

	// Abschnitt: Karte: Berechtigungen.

	/**
	 * Matrix Rollen x Capabilities (inkl. Add-on-Caps aus der Registry).
	 *
	 * @return void
	 */
	public static function render_card_capabilities() {
		global $wp_roles;
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return;
		}
		$registry = BDFRMS_Capabilities::registry();
		?>
		<p class="description"><?php esc_html_e( 'Welche Rolle darf was? Administratoren behalten über manage_options immer vollen Zugriff.', 'blitz-donner-forms' ); ?></p>
		<table class="widefat striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Berechtigung', 'blitz-donner-forms' ); ?></th>
					<?php foreach ( $wp_roles->roles as $role_slug => $role_data ) : ?>
						<th><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $registry as $cap => $meta ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $meta['title'] ); ?></strong><br />
							<span class="description"><?php echo esc_html( $meta['description'] ); ?></span>
						</td>
						<?php foreach ( $wp_roles->roles as $role_slug => $role_data ) : ?>
							<td>
								<input type="checkbox"
									name="bdfrms_caps[<?php echo esc_attr( $role_slug ); ?>][<?php echo esc_attr( $cap ); ?>]"
									value="1"
									<?php checked( ! empty( $role_data['capabilities'][ $cap ] ) ); ?> />
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Karte «Berechtigungen» speichern.
	 *
	 * @return void
	 */
	public static function save_card_capabilities() {
		global $wp_roles;
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return;
		}
		$posted = array();
		if ( isset( $_POST['bdfrms_caps'] ) && is_array( $_POST['bdfrms_caps'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce in handle_save().
			$posted = map_deep( wp_unslash( $_POST['bdfrms_caps'] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		foreach ( $wp_roles->roles as $role_slug => $_ ) {
			foreach ( BDFRMS_Capabilities::all_caps() as $cap ) {
				$enabled = ! empty( $posted[ $role_slug ][ $cap ] );
				BDFRMS_Capabilities::set_role_cap( $role_slug, $cap, $enabled );
			}
		}
	}

	// Abschnitt: Karte: Erweiterungen.

	/**
	 * Dezenter Hinweis auf die Add-ons (WP.org-Guideline 5: keine gesperrten
	 * Dummy-Funktionen, kein Upsell-Druck – eine Karte, ein Link).
	 *
	 * @return void
	 */
	public static function render_card_extensions() {
		?>
		<p>
			<?php esc_html_e( 'Blitz & Donner Forms lässt sich mit Add-ons erweitern – zum Beispiel um Verschlüsselung, Audit-Log und Virenscan (Security-Add-on).', 'blitz-donner-forms' ); ?>
			<a href="https://plugins.blitzdonner.ch" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Übersicht der Add-ons', 'blitz-donner-forms' ); ?></a>
		</p>
		<?php
	}
}
