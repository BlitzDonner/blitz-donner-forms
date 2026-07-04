<?php
/**
 * Submit-Handling der Basis.
 *
 * Etappe-1-Gerüst: definiert den Ablauf (Abwehrkette -> Validierung ->
 * Persistenz) und die Hook-Oberfläche. Die vollständige, auditierte
 * Schema-Validierung und der Datei-Pfad werden in Etappe 2 aus dem
 * Bestand («Blitz & Donner Formular», bis 2.9.3) übernommen.
 *
 * Abwehrkette: Honeypot -> Nonce -> Rate-Limit -> Captcha. Add-ons können
 * über den Filter `bdf_submit_chain` weitere Stufen einhängen oder
 * bestehende ersetzen. Jede Stufe ist ein Callable und liefert
 * true (weiter) oder einen Status-Slug (Abbruch).
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submit-Endpoint mit erweiterbarer Abwehrkette.
 */
class BDF_Submit_Handler {

	const STATUS_OK                      = 'success';
	const STATUS_ERR_REQUEST             = 'err_request';
	const STATUS_ERR_NONCE               = 'err_nonce';
	const STATUS_ERR_SPAM                = 'err_spam';
	const STATUS_ERR_RATE                = 'err_rate';
	const STATUS_ERR_VALIDATION          = 'err_validation';
	const STATUS_ERR_FILE                = 'err_file';
	const STATUS_ERR_PERSIST             = 'err_persist';
	const STATUS_ERR_CAPTCHA             = 'err_captcha';
	const STATUS_ERR_CAPTCHA_UNREACHABLE = 'err_captcha_unreachable';

	/**
	 * Name des Honeypot-Felds im Frontend-Formular.
	 */
	const HONEYPOT_FIELD = 'bdf_website_extra';

	/**
	 * Rate-Limit: maximale Submits pro IP im Zeitfenster.
	 */
	const RATE_LIMIT_MAX    = 5;
	const RATE_LIMIT_WINDOW = 300; // Sekunden.

	/**
	 * Hooks registrieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_bdf_submit', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_bdf_submit', array( __CLASS__, 'handle' ) );
		add_action( 'admin_init', array( 'BDF_Install', 'maybe_upgrade' ) );
	}

	// Abschnitt: Abwehrkette.

	/**
	 * Standard-Stufen der Abwehrkette, erweiterbar via `bdf_submit_chain`.
	 *
	 * @param array<string,mixed> $form_attrs Attribute des bdf/form-Blocks.
	 * @return array<string,callable> Stufen-ID => Callable.
	 */
	public static function defense_chain( array $form_attrs ) {
		$chain = array(
			'honeypot'   => array( __CLASS__, 'stage_honeypot' ),
			'nonce'      => array( __CLASS__, 'stage_nonce' ),
			'rate_limit' => array( __CLASS__, 'stage_rate_limit' ),
			'captcha'    => array( __CLASS__, 'stage_captcha' ),
		);

		/**
		 * Abwehrkette erweitern oder umbauen.
		 *
		 * Jede Stufe: Stufen-ID => callable( array $form_attrs ). Rückgabe
		 * der Stufe: true (bestanden) oder ein STATUS_ERR_*-Slug (Abbruch,
		 * wird dem Absender als Fehlermeldung angezeigt). Die Reihenfolge
		 * des Arrays ist die Ausführungsreihenfolge.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,callable> $chain      Stufen der Basis.
		 * @param array<string,mixed>    $form_attrs Attribute des bdf/form-Blocks.
		 */
		$filtered = apply_filters( 'bdf_submit_chain', $chain, $form_attrs );

		return is_array( $filtered ) ? $filtered : $chain;
	}

	/**
	 * Kette ausführen. Bricht bei der ersten nicht bestandenen Stufe ab.
	 *
	 * @param array<string,mixed> $form_attrs Block-Attribute.
	 * @return true|string true oder STATUS_ERR_*-Slug.
	 */
	public static function run_defense_chain( array $form_attrs ) {
		foreach ( self::defense_chain( $form_attrs ) as $stage_id => $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			$result = call_user_func( $callback, $form_attrs );
			if ( true !== $result ) {
				return is_string( $result ) ? $result : self::STATUS_ERR_REQUEST;
			}
		}
		return true;
	}

	/**
	 * Stufe: Honeypot. Das versteckte Feld muss leer sein.
	 *
	 * @param array<string,mixed> $form_attrs Block-Attribute.
	 * @return true|string
	 */
	public static function stage_honeypot( array $form_attrs ) {
		$value = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Teil der Abwehrkette vor dem Nonce-Check.
		return '' === trim( $value ) ? true : self::STATUS_ERR_SPAM;
	}

	/**
	 * Stufe: Nonce.
	 *
	 * @param array<string,mixed> $form_attrs Block-Attribute.
	 * @return true|string
	 */
	public static function stage_nonce( array $form_attrs ) {
		$nonce = isset( $_POST['bdf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bdf_nonce'] ) ) : '';
		return wp_verify_nonce( $nonce, 'bdf_submit' ) ? true : self::STATUS_ERR_NONCE;
	}

	/**
	 * Stufe: Rate-Limit pro IP (Transient-Zähler).
	 *
	 * @param array<string,mixed> $form_attrs Block-Attribute.
	 * @return true|string
	 */
	public static function stage_rate_limit( array $form_attrs ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}
		$key   = 'bdf_rate_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return self::STATUS_ERR_RATE;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Stufe: Friendly Captcha (nur wenn für das Formular wirksam).
	 *
	 * @param array<string,mixed> $form_attrs Block-Attribute.
	 * @return true|string
	 */
	public static function stage_captcha( array $form_attrs ) {
		if ( ! BDF_Captcha::is_active_for_form( $form_attrs ) ) {
			return true;
		}
		$token  = isset( $_POST[ BDF_Captcha::RESPONSE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ BDF_Captcha::RESPONSE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce-Stufe läuft in derselben Kette.
		$verify = BDF_Captcha::verify( $token );

		if ( 'pass' === $verify['result'] ) {
			return true;
		}
		if ( 'unreachable' === $verify['result'] ) {
			$settings = BDF_Captcha::get_settings();
			// soft: bei gestörtem Anbieter durchlassen; strict: fail-closed.
			return ( 'strict' === $settings['mode'] ) ? self::STATUS_ERR_CAPTCHA_UNREACHABLE : true;
		}
		return self::STATUS_ERR_CAPTCHA;
	}

	// Abschnitt: Submit-Endpoint.

	/**
	 * Anonymer Submit-Endpoint (admin_post_bdf_submit).
	 *
	 * Etappe-1-Gerüst: Kette + Persistenz. Die Schema-Validierung gegen die
	 * Block-Definition folgt in Etappe 2 mit den Formular-Blöcken.
	 *
	 * @return void
	 */
	public static function handle() {
		$post_id = isset( $_POST['bdf_post_id'] ) ? absint( wp_unslash( $_POST['bdf_post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce prüft die Abwehrkette.
		$form_id = isset( $_POST['bdf_form_id'] ) ? sanitize_key( wp_unslash( $_POST['bdf_form_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $post_id <= 0 || '' === $form_id ) {
			self::redirect_with_status( self::STATUS_ERR_REQUEST, $post_id );
		}

		// Etappe 2: Block-Attribute aus dem gespeicherten Post-Content lesen.
		$form_attrs = array();

		$chain_result = self::run_defense_chain( $form_attrs );
		if ( true !== $chain_result ) {
			self::redirect_with_status( $chain_result, $post_id );
		}

		// Etappe 2: Felder gegen das Formular-Schema validieren (Bestandscode).
		$payload = array();

		$submission_id = self::insert_submission( $post_id, $form_id, '', $payload );
		if ( $submission_id <= 0 ) {
			self::redirect_with_status( self::STATUS_ERR_PERSIST, $post_id );
		}

		self::redirect_with_status( self::STATUS_OK, $post_id );
	}

	/**
	 * Einsendung speichern. Zentrale Persistenz-Stelle der Basis.
	 *
	 * @param int                 $post_id    Post mit dem Formular-Block.
	 * @param string              $form_id    Formular-ID (Block-Attribut).
	 * @param string              $form_title Formular-Titel.
	 * @param array<string,mixed> $payload    Validierter Feld-Datensatz.
	 * @return int Zeilen-ID oder 0 bei Fehler.
	 */
	public static function insert_submission( $post_id, $form_id, $form_title, array $payload ) {
		global $wpdb;

		$context = array(
			'post_id'    => (int) $post_id,
			'form_id'    => (string) $form_id,
			'form_title' => (string) $form_title,
		);

		/**
		 * Feld-Datensatz vor dem Speichern verändern.
		 *
		 * Läuft unmittelbar vor dem INSERT in {prefix}bdf_submissions. Das
		 * Security-Add-on ersetzt hier Klartextwerte durch verschlüsselte
		 * Envelopes. Der Rückgabewert wird als JSON in `payload` gespeichert.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $payload Feld-Datensatz (Feldname => Wert).
		 * @param array               $context {post_id, form_id, form_title}.
		 */
		$payload = apply_filters( 'bdf_store_submission_payload', $payload, $context );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bdf_submissions',
			array(
				'created_at' => current_time( 'mysql' ),
				'post_id'    => (int) $post_id,
				'form_id'    => (string) $form_id,
				'form_title' => (string) $form_title,
				'payload'    => (string) wp_json_encode( $payload ),
				'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		$submission_id = (int) $wpdb->insert_id;

		/**
		 * Läuft nach erfolgreicher Persistenz einer Einsendung.
		 *
		 * Anschlusspunkt für Benachrichtigungen und Add-ons (Mautic/CRM,
		 * Team-Workflows). Der Payload ist der GESPEICHERTE Datensatz –
		 * nach `bdf_store_submission_payload`, also ggf. verschlüsselt.
		 *
		 * @since 0.1.0
		 *
		 * @param int                 $submission_id Zeilen-ID in {prefix}bdf_submissions.
		 * @param array<string,mixed> $payload       Gespeicherter Datensatz.
		 * @param array               $context       {post_id, form_id, form_title}.
		 */
		do_action( 'bdf_submission_stored', $submission_id, $payload, $context );

		return $submission_id;
	}

	/**
	 * Redirect zurück zur Formular-Seite mit Status-Slug.
	 *
	 * @param string $status  STATUS_*-Slug.
	 * @param int    $post_id Ziel-Post.
	 * @return void
	 */
	protected static function redirect_with_status( $status, $post_id ) {
		$url = $post_id > 0 ? get_permalink( $post_id ) : home_url( '/' );
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		wp_safe_redirect( add_query_arg( 'bdf_status', rawurlencode( $status ), $url ) );
		exit;
	}
}
