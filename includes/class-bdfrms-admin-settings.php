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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Admin-Stylesheet auf den Plugin-Seiten laden (Karten-Cockpit, Listen).
	 *
	 * @param string $hook_suffix Aktueller Admin-Screen.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'bdfrms' ) ) {
			return;
		}
		wp_enqueue_style(
			'bdfrms-admin',
			BDFRMS_PLUGIN_URL . 'assets/admin-submissions.css',
			array(),
			BDFRMS_PLUGIN_VERSION
		);
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
				'status' => array( __CLASS__, 'status_card_captcha' ),
			),
			'extensions'   => array(
				'title'  => __( 'Erweiterungen', 'blitz-donner-forms' ),
				'render' => array( __CLASS__, 'render_card_extensions' ),
				'save'   => null,
				'status' => array( __CLASS__, 'status_card_extensions' ),
			),
			// Bewusst zuletzt (Entscheid Stefan 05.07.2026): Solo-Betreiber
			// brauchen die Karte nie; Add-on-Caps erscheinen automatisch drin.
			'capabilities' => array(
				'title'  => __( 'Berechtigungen', 'blitz-donner-forms' ),
				'render' => array( __CLASS__, 'render_card_capabilities' ),
				'save'   => array( __CLASS__, 'save_card_capabilities' ),
				'status' => null,
			),
		);

		/**
		 * Karten der Einstellungsseite erweitern.
		 *
		 * Alle Karten rendern als zugeklappte Aufklapp-Elemente mit
		 * Status-Etikette in der Titelzeile (Muster des Vorgänger-Plugins).
		 * Jeder Eintrag: Karten-ID => array{title:string, render:callable,
		 * save:callable|null, status:callable|null}. `render` gibt das
		 * Karten-HTML aus; `save` verarbeitet den zugehörigen POST-Teil beim
		 * zentralen Speichern (bereits nonce- und berechtigungsgeprüft);
		 * `status` liefert array{state:'on'|'off'|'warn'|'neutral',
		 * text:string} für die Etikette oder null (keine Etikette).
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,array{title:string,render:callable,save:callable|null,status:callable|null}> $cards Karten der Basis.
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
		<div class="wrap bdfrms-admin bdfrms-settings">
			<h1><?php esc_html_e( 'Blitz & Donner Forms – Einstellungen', 'blitz-donner-forms' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Einstellungen gespeichert.', 'blitz-donner-forms' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bdfrms_save_settings" />
				<?php wp_nonce_field( 'bdfrms_save_settings' ); ?>
				<?php foreach ( self::cards() as $card_id => $card ) : ?>
					<details class="bdfrms-settings-card" id="bdfrms-<?php echo esc_attr( sanitize_key( (string) $card_id ) ); ?>">
						<summary>
							<h2><?php echo esc_html( (string) $card['title'] ); ?></h2>
							<?php
							if ( isset( $card['status'] ) && is_callable( $card['status'] ) ) {
								$status = call_user_func( $card['status'] );
								if ( is_array( $status ) && isset( $status['state'], $status['text'] ) ) {
									echo wp_kses_post( self::summary_badge( (string) $status['state'], (string) $status['text'] ) );
								}
							}
							?>
						</summary>
						<?php
						if ( isset( $card['render'] ) && is_callable( $card['render'] ) ) {
							call_user_func( $card['render'] );
						}
						?>
					</details>
				<?php endforeach; ?>
				<?php submit_button( __( 'Speichern', 'blitz-donner-forms' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Baut die Status-Etikette für die Titelzeile einer zugeklappten Karte
	 * (Muster aus dem Vorgänger-Plugin).
	 *
	 * @param string $state on|off|warn|neutral (Farbe).
	 * @param string $text  Sichtbarer Kurztext.
	 * @return string HTML (escaped).
	 */
	private static function summary_badge( $state, $text ) {
		$allowed = array( 'on', 'off', 'warn', 'neutral' );
		if ( ! in_array( $state, $allowed, true ) ) {
			$state = 'neutral';
		}
		return '<span class="bdfrms-card-badge bdfrms-card-badge--' . esc_attr( $state ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Status-Etikette der Spam-Schutz-Karte.
	 *
	 * @return array{state:string,text:string}
	 */
	public static function status_card_captcha() {
		if ( BDFRMS_Captcha::is_configured() ) {
			return array(
				'state' => 'on',
				'text'  => __( 'Aktiv', 'blitz-donner-forms' ),
			);
		}
		if ( BDFRMS_Captcha::is_enabled_but_incomplete() ) {
			return array(
				'state' => 'warn',
				'text'  => __( 'Unvollständig', 'blitz-donner-forms' ),
			);
		}
		return array(
			'state' => 'off',
			'text'  => __( 'Nicht aktiv', 'blitz-donner-forms' ),
		);
	}

	/**
	 * Status-Etikette der Erweiterungen-Karte: zählt Add-on-Karten, die
	 * sich über den Filter registriert haben.
	 *
	 * @return array{state:string,text:string}
	 */
	public static function status_card_extensions() {
		$base  = array( 'captcha', 'extensions', 'capabilities' );
		$extra = array_diff( array_keys( self::cards() ), $base );
		if ( count( $extra ) > 0 ) {
			return array(
				'state' => 'on',
				/* translators: %d: Anzahl installierter Add-ons. */
				'text'  => sprintf( _n( '%d Add-on', '%d Add-ons', count( $extra ), 'blitz-donner-forms' ), count( $extra ) ),
			);
		}
		return array(
			'state' => 'neutral',
			'text'  => __( 'Keine installiert', 'blitz-donner-forms' ),
		);
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
		<details style="margin-top:12px;margin-bottom:12px;">
			<summary><?php esc_html_e( 'Anleitung: So kommst du zu den beiden Schlüsseln', 'blitz-donner-forms' ); ?></summary>
			<ol>
				<li>
					<?php esc_html_e( 'Erstelle ein Konto bei Friendly Captcha und melde dich im Dashboard an:', 'blitz-donner-forms' ); ?>
					<a href="https://app.friendlycaptcha.eu/dashboard" target="_blank" rel="noopener noreferrer">app.friendlycaptcha.eu</a>
				</li>
				<li><?php esc_html_e( 'Lege unter «Applications» mit «+ New Application» eine Anwendung für deine Website an.', 'blitz-donner-forms' ); ?></li>
				<li><?php esc_html_e( 'Den Site-Key findest du danach direkt unter dem Namen der Anwendung – er beginnt immer mit «FC». Trage ihn unten bei «Site-Key» ein.', 'blitz-donner-forms' ); ?></li>
				<li><?php esc_html_e( 'Den API-Key erstellst du im Dashboard unter «Account → API Keys». Er authentifiziert die serverseitige Prüfung der Einsendungen und bleibt geheim – trage ihn unten bei «API-Key» ein und teile ihn nirgends.', 'blitz-donner-forms' ); ?></li>
				<li><?php esc_html_e( 'Schalte «Aktiv» ein und speichere. Pro Formular kannst du das Captcha zusätzlich im Formular-Block übersteuern (Einstellung «Spam-Schutz»).', 'blitz-donner-forms' ); ?></li>
			</ol>
			<p class="description">
				<?php esc_html_e( 'Ausführliche Anleitung des Anbieters:', 'blitz-donner-forms' ); ?>
				<a href="https://developer.friendlycaptcha.com/docs/v2/getting-started/setup" target="_blank" rel="noopener noreferrer">developer.friendlycaptcha.com</a>
			</p>
		</details>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Aktiv', 'blitz-donner-forms' ); ?></th>
				<td><label><input type="checkbox" name="bdfrms_captcha[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Friendly Captcha global einschalten', 'blitz-donner-forms' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="bdfrms-captcha-site-key"><?php esc_html_e( 'Site-Key', 'blitz-donner-forms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="bdfrms-captcha-site-key" name="bdfrms_captcha[site_key]" value="<?php echo esc_attr( $s['site_key'] ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Öffentlicher Schlüssel deiner Anwendung, beginnt mit «FC». Wird im Formular sichtbar ausgegeben.', 'blitz-donner-forms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bdfrms-captcha-api-key"><?php esc_html_e( 'API-Key', 'blitz-donner-forms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="bdfrms-captcha-api-key" name="bdfrms_captcha[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Geheimer Schlüssel für die serverseitige Prüfung («Account → API Keys»). Verlässt den Server nie und erscheint nirgends im Formular.', 'blitz-donner-forms' ); ?></p>
				</td>
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
		<p class="description"><?php esc_html_e( 'Damit können z.B. Redaktorinnen Einsendungen sehen oder exportieren, ohne Administratorrechte zu haben. Welche Rolle darf was? Administratoren behalten immer vollen Zugriff. Add-ons ergänzen hier eigene Berechtigungen.', 'blitz-donner-forms' ); ?></p>
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
