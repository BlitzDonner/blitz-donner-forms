<?php
/**
 * Submit handling für Blitz & Donner Forms.
 *
 * Sicherheitsrelevant: Alle externen Eingaben (POST/FILES/SERVER) werden
 * über BDFRMS_Security validiert. Anonymer Endpoint (admin_post_nopriv).
 *
 * Übernommen aus dem Bestand «Blitz & Donner Formular» (bis 2.9.3). Wo der
 * Vorgänger Krypto, Audit-Log oder ClamAV aufrief, steht hier ein Hook –
 * siehe docs/hooks.md.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submit-Endpoint mit erweiterbarer Abwehrkette.
 */
class BDFRMS_Submit_Handler {

	const STATUS_OK                      = 'success';
	const STATUS_ERR_REQUEST             = 'err_request';
	const STATUS_ERR_NONCE               = 'err_nonce';
	const STATUS_ERR_TOKEN               = 'err_token';
	const STATUS_ERR_SPAM                = 'err_spam';
	const STATUS_ERR_RATE                = 'err_rate';
	const STATUS_ERR_SCHEMA              = 'err_schema';
	const STATUS_ERR_DUPLICATE           = 'err_duplicate';
	const STATUS_ERR_VALIDATION          = 'err_validation';
	const STATUS_ERR_FILE                = 'err_file';
	const STATUS_ERR_PERSIST             = 'err_persist';
	const STATUS_ERR_EXTERNAL            = 'err_external';
	const STATUS_ERR_CRYPTO              = 'err_crypto';
	const STATUS_ERR_VIRUS               = 'err_virus';
	const STATUS_ERR_CAPTCHA             = 'err_captcha';
	const STATUS_ERR_CAPTCHA_UNREACHABLE = 'err_captcha_unreachable';

	/**
	 * Statusslug => i18n-Text. Verhindert, dass Angreifer beliebige Notices
	 * via URL platzieren können (H5).
	 *
	 * @return array<string,string>
	 */
	private static function status_messages() {
		return array(
			self::STATUS_OK                      => __( 'Danke! Das Formular wurde erfolgreich gesendet.', 'blitz-donner-forms' ),
			self::STATUS_ERR_REQUEST             => __( 'Ungültige Formularanfrage.', 'blitz-donner-forms' ),
			self::STATUS_ERR_NONCE               => __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut absenden.', 'blitz-donner-forms' ),
			self::STATUS_ERR_TOKEN               => __( 'Sitzung abgelaufen. Bitte Seite neu laden und erneut absenden.', 'blitz-donner-forms' ),
			self::STATUS_ERR_SPAM                => __( 'Die Anfrage wurde als Spam erkannt.', 'blitz-donner-forms' ),
			self::STATUS_ERR_RATE                => __( 'Zu viele Anfragen. Bitte warte kurz und versuche es erneut.', 'blitz-donner-forms' ),
			self::STATUS_ERR_SCHEMA              => __( 'Formularschema nicht gefunden.', 'blitz-donner-forms' ),
			self::STATUS_ERR_DUPLICATE           => __( 'Doppelte technische Feldnamen im Formular. Bitte eines der betroffenen Felder duplizieren oder Label bzw. Platzhalter anpassen.', 'blitz-donner-forms' ),
			self::STATUS_ERR_VALIDATION          => __( 'Das Formular wurde nicht übermittelt. Bitte prüfe die Hinweise und sende erneut.', 'blitz-donner-forms' ),
			self::STATUS_ERR_FILE                => __( 'Eine hochgeladene Datei wurde abgelehnt. Das Formular wurde in diesem Fall nicht übermittelt; es wurde kein neuer Eintrag gespeichert.', 'blitz-donner-forms' ),
			self::STATUS_ERR_PERSIST             => __( 'Speichern fehlgeschlagen. Bitte versuche es erneut.', 'blitz-donner-forms' ),
			self::STATUS_ERR_EXTERNAL            => __( 'Die Anfrage konnte nicht verarbeitet werden.', 'blitz-donner-forms' ),
			self::STATUS_ERR_CRYPTO              => __( 'Verschlüsselung ist auf diesem Server nicht eingerichtet. Bitte den Administrator kontaktieren.', 'blitz-donner-forms' ),
			self::STATUS_ERR_VIRUS               => __( 'Eine hochgeladene Datei wurde vom Virenscanner als schädlich erkannt.', 'blitz-donner-forms' ),
			self::STATUS_ERR_CAPTCHA             => __( 'Der Spam-Schutz wurde nicht bestätigt. Bitte schliesse die Spam-Prüfung im Formular ab und sende erneut.', 'blitz-donner-forms' ),
			self::STATUS_ERR_CAPTCHA_UNREACHABLE => __( 'Der Spam-Schutz ist derzeit nicht verfügbar. Bitte versuche es in einigen Minuten erneut.', 'blitz-donner-forms' ),
		);
	}

	/**
	 * Valid status slugs.
	 *
	 * @return array<int,string>
	 */
	private static function valid_status_slugs() {
		return array_keys( self::status_messages() );
	}

	/**
	 * Public Helper: Gibt den i18n-Text für einen Status-Slug zurück oder leer.
	 *
	 * @param string $slug Status-Slug.
	 * @return string
	 */
	public static function status_message_for( $slug ) {
		$map = self::status_messages();
		return isset( $map[ $slug ] ) ? $map[ $slug ] : '';
	}

	/**
	 * Handle a form submission.
	 *
	 * @return void
	 */
	public static function handle() {
		$post_id    = isset( $_POST['bdfrms_post_id'] ) ? absint( wp_unslash( $_POST['bdfrms_post_id'] ) ) : 0;
		$form_id    = isset( $_POST['bdfrms_form_id'] ) ? sanitize_key( wp_unslash( $_POST['bdfrms_form_id'] ) ) : '';
		$instance   = isset( $_POST['bdfrms_instance_id'] ) ? sanitize_key( wp_unslash( $_POST['bdfrms_instance_id'] ) ) : '0';
		$ip_address = BDFRMS_Security::get_client_ip();

		if ( ! $post_id || ! $form_id ) {
			BDFRMS_Security::log_event( 'submit_invalid_request' );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_REQUEST );
		}

		$form_attrs        = BDFRMS_Plugin::get_form_block_attributes_from_post( $post_id, $form_id );
		$thank_you_page_id = isset( $form_attrs['thankYouPageId'] ) ? absint( $form_attrs['thankYouPageId'] ) : 0;
		$hp_field          = BDFRMS_Security::honeypot_field_name( $post_id, $form_id, $instance );

		// Abwehrkette: nonce -> token -> honeypot -> rate_limit -> captcha.
		// Jede Stufe liefert true (bestanden), einen STATUS_ERR_*-Slug oder
		// array{0:Slug,1:Detail}. Add-ons erweitern die Kette über den
		// Filter `bdfrms_submit_chain` (docs/hooks.md).
		$chain = array(
			// wp_verify_nonce statt check_admin_referer: letzteres killt den
			// Request mit dem WP-Default-403-HTML, sodass redirect_with_state
			// nie läuft. Wir wollen aber konsistent mit bdfrms_status zur
			// Form-Page zurück-redirecten (UX + nachvollziehbare Reject-Pfade).
			'nonce'      => static function () use ( $post_id, $form_id ) {
				$nonce_value = isset( $_POST['bdfrms_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bdfrms_nonce'] ) ) : '';
				if ( ! wp_verify_nonce( $nonce_value, 'bdfrms_submit_' . $form_id . '_' . $post_id ) ) {
					BDFRMS_Security::log_event(
						'submit_nonce_fail',
						array(
							'post_id' => $post_id,
							'form_id' => $form_id,
						)
					);
					return self::STATUS_ERR_NONCE;
				}
				return true;
			},
			// HMAC-Token (H3) inkl. honeypot-Feldname-Bindung.
			'token'      => static function () use ( $post_id, $form_id, $instance, $hp_field ) {
				$token = isset( $_POST['bdfrms_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bdfrms_token'] ) ) : '';
				if ( ! BDFRMS_Security::verify_token( $token, $post_id, $form_id, $instance, $hp_field ) ) {
					BDFRMS_Security::log_event(
						'submit_token_fail',
						array(
							'post_id' => $post_id,
							'form_id' => $form_id,
						)
					);
					return self::STATUS_ERR_TOKEN;
				}
				return true;
			},
			// Honeypot (H1): per Form-Instanz dynamisch.
			'honeypot'   => static function () use ( $post_id, $form_id, $hp_field ) {
				if ( ! empty( $_POST[ $hp_field ] ) ) {
					BDFRMS_Security::log_event(
						'submit_honeypot_hit',
						array(
							'post_id' => $post_id,
							'form_id' => $form_id,
						)
					);
					return self::STATUS_ERR_SPAM;
				}
				return true;
			},
			'rate_limit' => static function () use ( $form_id, $ip_address ) {
				if ( self::is_rate_limited( $form_id, $ip_address ) ) {
					BDFRMS_Security::log_event( 'submit_rate_limited', array( 'form_id' => $form_id ) );
					return self::STATUS_ERR_RATE;
				}
				return true;
			},
			// CAPTCHA (Friendly Captcha), NACH Rate-Limit, VOR Schema-/
			// Feldverarbeitung. Greift nur, wenn für dieses Formular aktiv
			// (global an + vollständig konfiguriert + captchaMode).
			'captcha'    => static function () use ( $post_id, $form_id, $form_attrs, $ip_address ) {
				return self::check_captcha( $post_id, $form_id, $form_attrs, $ip_address );
			},
		);

		/**
		 * Abwehrkette erweitern oder umbauen.
		 *
		 * Jede Stufe: Stufen-ID => callable( array $form_attrs ). Rückgabe:
		 * true (bestanden), ein STATUS_ERR_*-Slug oder array{0:Slug,1:Detail}
		 * (Abbruch mit Redirect). Die Array-Reihenfolge ist die
		 * Ausführungsreihenfolge.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,callable> $chain      Stufen der Basis.
		 * @param array<string,mixed>    $form_attrs Attribute des bdfrms/form-Blocks.
		 */
		$chain = apply_filters( 'bdfrms_submit_chain', $chain, $form_attrs );

		foreach ( (array) $chain as $stage_id => $stage ) {
			if ( ! is_callable( $stage ) ) {
				continue;
			}
			$result = call_user_func( $stage, $form_attrs );
			if ( true === $result ) {
				continue;
			}
			$status = is_array( $result ) ? (string) ( $result[0] ?? self::STATUS_ERR_REQUEST ) : (string) $result;
			$detail = is_array( $result ) ? (string) ( $result[1] ?? '' ) : '';
			self::redirect_with_state( $post_id, $form_id, $status, $detail );
		}

		/** Nur aus Block-Attributen (nicht aus POST), damit der Name nicht manipulierbar ist. */
		$form_title_stored = '';
		if ( ! empty( $form_attrs['formTitle'] ) && is_string( $form_attrs['formTitle'] ) ) {
			$form_title_stored = mb_substr( sanitize_text_field( $form_attrs['formTitle'] ), 0, 255 );
		}

		$draft_key_redirect = '';
		if ( ! empty( $_POST['bdfrms_draft_key'] ) ) {
			$raw_dk = sanitize_text_field( wp_unslash( $_POST['bdfrms_draft_key'] ) );
			if ( preg_match( '/^[0-9]+:[a-z0-9_-]+:[a-z0-9_-]+$/i', $raw_dk ) ) {
				$draft_key_redirect = $raw_dk;
			}
		}

		$schema = BDFRMS_Plugin::get_form_schema_from_post( $post_id, $form_id );
		if ( empty( $schema ) ) {
			BDFRMS_Security::log_event(
				'submit_schema_missing',
				array(
					'post_id' => $post_id,
					'form_id' => $form_id,
				)
			);
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_SCHEMA );
		}

		$seen_names = array();
		foreach ( $schema as $field ) {
			$n = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $n ) {
				continue;
			}
			if ( isset( $seen_names[ $n ] ) ) {
				BDFRMS_Security::log_event( 'submit_duplicate_field', array( 'name' => $n ) );
				self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_DUPLICATE );
			}
			$seen_names[ $n ] = true;
		}

		$schema_has_files = false;
		foreach ( $schema as $f ) {
			if ( ( $f['type'] ?? '' ) === 'file' ) {
				$schema_has_files = true;
				break;
			}
		}

		// Upload-Vorbedingung (nur falls File-Felder vorhanden). Der
		// Vorgänger prüfte hier die ClamAV-Erreichbarkeit; in der Basis
		// ist das ein Hook für Add-ons.
		if ( $schema_has_files ) {
			/**
			 * Vorbedingung für Datei-Uploads dieses Submits.
			 *
			 * Das Security-Add-on prüft hier z. B. Verschlüsselungs- und
			 * Virenscanner-Bereitschaft. Rückgabe eines WP_Error lehnt den
			 * Submit ab, bevor Dateien verarbeitet werden; die Fehlermeldung
			 * wird dem Absender angezeigt.
			 *
			 * @since 0.1.0
			 *
			 * @param true|WP_Error       $pre        true = Uploads erlaubt.
			 * @param array<int,array>    $schema     Formular-Schema.
			 * @param array<string,mixed> $form_attrs Attribute des bdfrms/form-Blocks.
			 */
			$pre = apply_filters( 'bdfrms_upload_precondition', true, $schema, $form_attrs );
			if ( is_wp_error( $pre ) ) {
				BDFRMS_Security::log_event( 'submit_upload_precondition_fail' );
				self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_FILE, $pre->get_error_message() );
			}
		}

		$payload          = array();
		$pending_file_ids = array();
		$errors           = array();

		foreach ( $schema as $field ) {
			$res = self::process_field_value( $field, $pending_file_ids );
			if ( is_wp_error( $res ) ) {
				$errors[] = array(
					'code'    => $res->get_error_code(),
					'message' => $res->get_error_message(),
				);
				continue;
			}
			// Die Basis speichert Klartext. Das `sensitive`-Flag bleibt im
			// Schema erhalten; das Security-Add-on verschlüsselt markierte
			// Werte über den Filter `bdfrms_store_submission_payload`.
			$payload[ $field['name'] ] = $res;
		}

		if ( ! empty( $errors ) ) {
			// Bereits verschlüsselte Datei-Reihen wieder löschen, damit keine Waisen entstehen.
			foreach ( $pending_file_ids as $fid ) {
				BDFRMS_File_Storage::delete( (int) $fid );
			}
			BDFRMS_Security::log_event( 'submit_validation_errors', array( 'count' => count( $errors ) ) );
			self::redirect_with_state(
				$post_id,
				$form_id,
				self::STATUS_ERR_VALIDATION,
				self::join_validation_errors( $errors )
			);
		}

		/**
		 * Extra validation hook for submit button flow.
		 *
		 * Werte im $payload sind bereits getypt und sanitisiert; trotzdem MUESSEN
		 * eingehakte Callbacks bei Ausgaben in HTML/SQL/Mail nochmal passend
		 * escapen.
		 *
		 * @param null|string|WP_Error $error      Validation result from previous callbacks.
		 * @param array<string,mixed>  $payload    Validated payload (without _bdfrms_labels at this point).
		 * @param array<int,array>     $schema     Form schema used for validation.
		 * @param array<string,mixed>  $form_attrs bdfrms/form block attributes.
		 * @param int                  $post_id    Post ID.
		 * @param string               $form_id    Form ID.
		 */
		$external_validation = apply_filters( 'bdfrms_submit_button_validation', null, $payload, $schema, $form_attrs, $post_id, $form_id );
		if ( is_wp_error( $external_validation ) ) {
			$message = $external_validation->get_error_message();
			BDFRMS_Security::log_event( 'submit_external_reject' );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_EXTERNAL, $message );
		}
		if ( is_string( $external_validation ) && '' !== trim( $external_validation ) ) {
			BDFRMS_Security::log_event( 'submit_external_reject' );
			self::redirect_with_state(
				$post_id,
				$form_id,
				self::STATUS_ERR_EXTERNAL,
				sanitize_text_field( $external_validation )
			);
		}

		/**
		 * Fires after server-side validation has completed successfully.
		 * Werte sind bereits sanitisiert; bei eigener Persistenz/Mail nochmal
		 * passend escapen.
		 *
		 * @param array<string,mixed> $payload    Validated payload.
		 * @param array<int,array>    $schema     Form schema used for validation.
		 * @param array<string,mixed> $form_attrs bdfrms/form block attributes.
		 * @param int                 $post_id    Post ID.
		 * @param string              $form_id    Form ID.
		 */
		do_action( 'bdfrms_after_server_validation', $payload, $schema, $form_attrs, $post_id, $form_id );

		/**
		 * Feld-Datensatz vor dem Speichern verändern.
		 *
		 * Läuft unmittelbar vor dem INSERT in {prefix}bdfrms_submissions,
		 * VOR dem Label-Snapshot (nur Feldwerte, keine Metadaten). Das
		 * Security-Add-on ersetzt hier als vertraulich markierte Werte
		 * durch verschlüsselte Envelopes.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $payload    Feld-Datensatz (Feldname => Wert).
		 * @param array<string,mixed> $context    {post_id, form_id, form_title}.
		 * @param array<int,array>    $schema     Formular-Schema (inkl. sensitive-Flag).
		 * @param array<string,mixed> $form_attrs Attribute des bdfrms/form-Blocks.
		 */
		$payload = apply_filters(
			'bdfrms_store_submission_payload',
			$payload,
			array(
				'post_id'    => $post_id,
				'form_id'    => $form_id,
				'form_title' => $form_title_stored,
			),
			$schema,
			$form_attrs
		);

		// Labels zum Zeitpunkt des Absendens (Snapshot für Backend, auch wenn sich das Formular später ändert).
		$label_snapshot = array();
		foreach ( $schema as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$label_snapshot[ $field['name'] ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['name'];
			}
		}
		$payload['_bdfrms_labels'] = $label_snapshot;

		global $wpdb;
		$table_name = $wpdb->prefix . 'bdfrms_submissions';

		/**
		 * IP optional pseudonymisieren oder ganz weglassen (DSGVO).
		 *
		 * @param string $ip Roh-IP (kann leer sein).
		 */
		$ip_to_store = apply_filters( 'bdfrms_store_ip_pre', $ip_address );
		$ip_to_store = BDFRMS_Security::maybe_pseudonymize_ip( (string) $ip_to_store );

		/**
		 * User-Agent optional weglassen.
		 *
		 * @param string $ua User-Agent.
		 */
		$ua_raw      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wird nach dem Filter sanitisiert und gekürzt.
		$ua_to_store = apply_filters( 'bdfrms_store_user_agent', $ua_raw );
		$ua_to_store = is_string( $ua_to_store ) ? mb_substr( sanitize_text_field( $ua_to_store ), 0, 1000 ) : '';

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'post_id'    => $post_id,
				'form_id'    => $form_id,
				'form_title' => $form_title_stored,
				'payload'    => wp_json_encode( $payload ),
				'ip_address' => (string) $ip_to_store,
				'user_agent' => $ua_to_store,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			BDFRMS_Security::log_event( 'submit_persist_fail' );
			// Verschlüsselte Files können nicht "verwaiste" Speicher sein.
			foreach ( $pending_file_ids as $fid ) {
				BDFRMS_File_Storage::delete( (int) $fid );
			}
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_PERSIST );
		}

		$submission_id = (int) $wpdb->insert_id;
		// Gespeicherte Files mit dieser Submission verbinden.
		if ( ! empty( $pending_file_ids ) ) {
			BDFRMS_File_Storage::attach_to_submission( $pending_file_ids, $submission_id );
		}
		// Der Vorgänger schrieb hier ins Audit-Log; das übernimmt das
		// Security-Add-on über `bdfrms_after_submission_insert`.
		BDFRMS_Security::log_event(
			'submission_insert',
			array(
				'submission_id' => $submission_id,
				'form_id'       => $form_id,
				'post_id'       => $post_id,
				'files'         => count( $pending_file_ids ),
			)
		);

		/**
		 * Fires after submission values were stored in database.
		 * Werte sind bereits sanitisiert; bei eigener Verarbeitung passend escapen.
		 *
		 * @param int                 $submission_id Inserted row ID in `{prefix}bdfrms_submissions`.
		 * @param array<string,mixed> $payload       Stored payload (including _bdfrms_labels).
		 * @param array<string,mixed> $form_attrs    bdfrms/form block attributes.
		 * @param int                 $post_id       Post ID.
		 * @param string              $form_id       Form ID.
		 */
		do_action( 'bdfrms_after_submission_insert', $submission_id, $payload, $form_attrs, $post_id, $form_id );

		self::send_notification_mail( $post_id, $form_id, $payload, $form_title_stored, $form_attrs );
		self::redirect_with_state(
			$post_id,
			$form_id,
			self::STATUS_OK,
			'',
			$draft_key_redirect,
			$thank_you_page_id
		);
	}

	/**
	 * WP_Error-Codes, bei denen eine Datei verworfen wurde und ein einheitlicher
	 * Hinweis (kein Speichern / gesamtes Formular nicht übermittelt) angehängt werden soll.
	 *
	 * @param string $code Fehlercode.
	 * @return bool
	 */
	private static function validation_error_code_is_file_rejection_with_global_hint( $code ) {
		return in_array(
			(string) $code,
			array(
				'bdfrms_file_ext',
				'bdfrms_file_accept',
				'bdfrms_file_mime',
				'bdfrms_file_invalid',
				'bdfrms_file_name',
				'bdfrms_size',
				'bdfrms_upload',
				'bdfrms_virus',
			),
			true
		);
	}

	/**
	 * Zusatztext: Datei nicht übernommen, Formular insgesamt nicht gesendet.
	 *
	 * @return string
	 */
	private static function file_rejection_form_not_sent_suffix() {
		return __(
			'Die Datei wurde nicht übernommen (nicht gespeichert) und zählt nicht zur Einsendung. Nach dem Neuladen der Seite ist die Auswahl im Datei-Feld leer — bitte wähle bei Bedarf eine zulässige Datei erneut. Das gesamte Formular wurde nicht übermittelt; es wurde kein neuer Eintrag gespeichert.',
			'blitz-donner-forms'
		);
	}

	/**
	 * Mehrere Validierungsfehler in einen kurzen, bereinigten Text giessen.
	 *
	 * @param array<int, string|array{code?:string, message?:string}> $errors Meldungen bzw. code+message.
	 * @return string
	 */
	private static function join_validation_errors( array $errors ) {
		$cleaned                 = array();
		$append_file_reject_hint = false;
		foreach ( $errors as $err ) {
			$code = '';
			if ( is_array( $err ) && isset( $err['message'] ) ) {
				$code = isset( $err['code'] ) ? (string) $err['code'] : '';
				if ( self::validation_error_code_is_file_rejection_with_global_hint( $code ) ) {
					$append_file_reject_hint = true;
				}
				$err = (string) $err['message'];
			} elseif ( ! is_string( $err ) ) {
				continue;
			}
			$err = wp_strip_all_tags( (string) $err );
			$err = preg_replace( '/\s+/', ' ', $err );
			$err = trim( $err );
			if ( '' !== $err ) {
				$cleaned[] = mb_substr( $err, 0, 200 );
			}
			if ( count( $cleaned ) >= 8 ) {
				break;
			}
		}
		$out = implode( ' ', $cleaned );
		if ( $append_file_reject_hint ) {
			$out = trim( $out . ' ' . self::file_rejection_form_not_sent_suffix() );
		}
		return mb_substr( $out, 0, 500 );
	}

	/**
	 * Redirect mit ausschliesslich serverseitig festgelegten Status-Slugs.
	 * Optional zusätzliche „err_detail" mit kurzen Validierungshinweisen
	 * (begrenzt + sanitisiert).
	 *
	 * @param int    $post_id           Post id.
	 * @param string $form_id           Form id.
	 * @param string $status_slug       Eine der STATUS_*-Konstanten.
	 * @param string $detail            Optionaler Detailtext (nur für Validierungsfehler).
	 * @param string $draft_key         Optional. IndexedDB-Schlüssel für Entwurf-Löschung nach Redirect.
	 * @param int    $thank_you_page_id Optional. Bei success: Seiten-ID für Weiterleitung.
	 * @return void
	 */
	private static function redirect_with_state(
		$post_id,
		$form_id,
		$status_slug,
		$detail = '',
		$draft_key = '',
		$thank_you_page_id = 0
	) {
		if ( ! in_array( $status_slug, self::valid_status_slugs(), true ) ) {
			$status_slug = self::STATUS_ERR_REQUEST;
		}

		$target = $post_id ? get_permalink( $post_id ) : home_url( '/' );
		if ( self::STATUS_OK === $status_slug && $thank_you_page_id > 0 ) {
			$page = get_post( $thank_you_page_id );
			if ( $page instanceof WP_Post && is_post_publicly_viewable( $page ) ) {
				$permalink = get_permalink( $page );
				if ( $permalink ) {
					$target = $permalink;
				}
			}
		}

		// bdfrms_status: synchron zu altem Verhalten ("success"/"error") für
		// vorhandene CSS-Klassen; bdfrms_code hält den vollen Slug für feinere
		// Logik / Templates.
		$visible_status = ( self::STATUS_OK === $status_slug ) ? 'success' : 'error';

		$args         = array(
			'bdfrms_status' => $visible_status,
			'bdfrms_code'   => $status_slug,
			'bdfrms_form'   => $form_id,
		);
		$detail_slugs = array(
			self::STATUS_ERR_VALIDATION,
			self::STATUS_ERR_FILE,
			self::STATUS_ERR_EXTERNAL,
			self::STATUS_ERR_CRYPTO,
		);
		if ( '' !== $detail && in_array( $status_slug, $detail_slugs, true ) ) {
			$args['bdfrms_detail'] = mb_substr( sanitize_text_field( $detail ), 0, 500 );
		}
		if ( '' !== $draft_key ) {
			$args['bdfrms_draft_key'] = $draft_key;
		}

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/**
	 * Send e-mail notification according to bdfrms/form block settings.
	 *
	 * Härte: Subject CRLF-strip, body wp_strip_all_tags + Limit pro Wert,
	 * explizit text/plain-Header, Empfänger/Absender nur aus Block-Attributen.
	 *
	 * @param int                 $post_id    Post id.
	 * @param string              $form_id    Form id.
	 * @param array<string,mixed> $payload    Form data.
	 * @param string              $form_title Optional display name.
	 * @param array<string,mixed> $form_attrs bdfrms/form block attributes.
	 * @return void
	 */
	private static function send_notification_mail( $post_id, $form_id, $payload, $form_title = '', array $form_attrs = array() ) {
		$enabled = ! empty( $form_attrs['emailNotificationEnabled'] );
		if ( ! $enabled ) {
			return;
		}

		$recipients = self::resolve_notification_recipients( $form_attrs );
		if ( empty( $recipients ) ) {
			return;
		}

		$labels  = isset( $payload['_bdfrms_labels'] ) && is_array( $payload['_bdfrms_labels'] ) ? $payload['_bdfrms_labels'] : array();
		$subject = self::build_notification_subject( $post_id, $form_id, $form_title, $form_attrs, $payload, $labels );
		$body    = self::build_notification_body( $payload, $labels );
		$headers = self::build_notification_headers( $form_attrs, $payload, $labels );

		wp_mail( $recipients, $subject, $body, $headers );
	}

	/**
	 * Resolve notification recipients.
	 *
	 * @param array<string,mixed> $form_attrs Block attributes.
	 * @return string Comma-separated valid recipient list for wp_mail.
	 */
	private static function resolve_notification_recipients( array $form_attrs ) {
		$out  = array();
		$raw  = '';
		$list = $form_attrs['emailRecipients'] ?? '';
		if ( is_array( $list ) ) {
			$raw = implode( ',', array_map( 'strval', $list ) );
		} elseif ( is_string( $list ) ) {
			$raw = $list;
		}
		$raw = preg_replace( "/[\r\n]+/", ' ', (string) $raw );
		foreach ( preg_split( '/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			$email = sanitize_email( (string) $part );
			if ( '' !== $email && is_email( $email ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		if ( empty( $out ) ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( '' !== $admin && is_email( $admin ) ) {
				$out[ strtolower( $admin ) ] = $admin;
			}
		}
		$out = array_slice( array_values( $out ), 0, 10 );

		return empty( $out ) ? '' : implode( ',', $out );
	}

	/**
	 * Build notification subject.
	 *
	 * @param int                  $post_id    Post id.
	 * @param string               $form_id    Form id.
	 * @param string               $form_title Display name.
	 * @param array<string,mixed>  $form_attrs Block attributes.
	 * @param array<string,mixed>  $payload    Submission payload.
	 * @param array<string,string> $labels    Field labels.
	 * @return string
	 */
	private static function build_notification_subject( $post_id, $form_id, $form_title, array $form_attrs, array $payload, array $labels ) {
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$custom   = isset( $form_attrs['emailSubject'] ) ? trim( (string) $form_attrs['emailSubject'] ) : '';

		if ( '' !== $custom ) {
			$subject = self::replace_notification_placeholders( $custom, $payload, $labels );
		} else {
			$form_title = is_string( $form_title ) ? trim( $form_title ) : '';
			if ( '' !== $form_title ) {
				$subj_base = sprintf(
					/* translators: 1: sprechender Formularname, 2: technische Formular-ID, 3: Beitrags-ID */
					__( 'Neues Formular: %1$s (%2$s), Beitrag %3$d', 'blitz-donner-forms' ),
					$form_title,
					$form_id,
					$post_id
				);
			} else {
				$subj_base = sprintf(
					/* translators: 1: form id, 2: post id */
					__( 'Neues Formular (%1$s) auf Beitrag %2$d', 'blitz-donner-forms' ),
					$form_id,
					$post_id
				);
			}
			$subject = '[' . $blogname . '] ' . $subj_base;
		}

		$subject = preg_replace( "/[\r\n]+/", ' ', (string) $subject );
		$subject = wp_strip_all_tags( (string) $subject );

		return mb_substr( (string) $subject, 0, 250 );
	}

	/**
	 * Build notification body.
	 *
	 * @param array<string,mixed>  $payload Submission payload.
	 * @param array<string,string> $labels  Field labels.
	 * @return string
	 */
	private static function build_notification_body( array $payload, array $labels ) {
		$lines = array();
		foreach ( $payload as $key => $value ) {
			if ( '_bdfrms_labels' === $key ) {
				continue;
			}
			$display_key = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$display_key = wp_strip_all_tags( (string) $display_key );
			$display_key = preg_replace( "/[\r\n]+/", ' ', (string) $display_key );
			$display_key = mb_substr( $display_key, 0, 120 );

			$value   = self::format_notification_field_value( $value );
			$lines[] = $display_key . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format notification field value.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private static function format_notification_field_value( $value ) {
		/**
		 * Anzeigewert eines Felds in der Benachrichtigungs-Mail.
		 *
		 * Add-ons formatieren hier eigene Wertformate – das Security-Add-on
		 * maskiert z. B. verschlüsselte Envelopes. Rückgabe eines Strings
		 * übernimmt; null lässt die Basis formatieren.
		 *
		 * @since 0.1.0
		 *
		 * @param null|string $formatted null = Basis formatiert.
		 * @param mixed       $value     Gespeicherter Rohwert.
		 */
		$formatted = apply_filters( 'bdfrms_notification_field_value', null, $value );
		if ( is_string( $formatted ) ) {
			$value = $formatted;
		} elseif ( is_array( $value ) && isset( $value['_ref'] ) && 0 === strpos( (string) $value['_ref'], 'bdfrms-file:' ) ) {
			$value = '[Datei #' . (int) $value['file_id'] . ' — Download im Admin]';
		} elseif ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( "/[\r\n]+/", "\n", (string) $value );

		return mb_substr( (string) $value, 0, 1000 );
	}

	/**
	 * Build notification headers.
	 *
	 * @param array<string,mixed>  $form_attrs Block attributes.
	 * @param array<string,mixed>  $payload    Submission payload.
	 * @param array<string,string> $labels     Field labels.
	 * @return array<int,string>
	 */
	private static function build_notification_headers( array $form_attrs, array $payload, array $labels = array() ) {
		$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from_email = self::resolve_notification_from_email( $form_attrs, $payload );
		$from_name  = self::resolve_notification_from_name( $form_attrs, $payload, $labels );

		$from_mailbox = self::format_mailbox_header( $from_name, $from_email );
		if ( '' !== $from_mailbox ) {
			$headers[] = 'From: ' . $from_mailbox;
		}

		return $headers;
	}

	/** Absender-Modus: feste Adresse im Block (nicht aus POST). */
	const EMAIL_FROM_CUSTOM_SENDER = 'bdfrms_custom_sender';

	/**
	 * From-Adresse: eigene Adresse, E-Mail-Feld der Einsendung oder Admin-E-Mail.
	 *
	 * @param array<string,mixed> $form_attrs Block attributes.
	 * @param array<string,mixed> $payload    Submission payload.
	 * @return string
	 */
	private static function resolve_notification_from_email( array $form_attrs, array $payload ) {
		$field = isset( $form_attrs['emailFromField'] ) ? sanitize_key( (string) $form_attrs['emailFromField'] ) : '';
		if ( self::EMAIL_FROM_CUSTOM_SENDER === $field ) {
			$custom = isset( $form_attrs['emailFromCustom'] ) ? sanitize_email( (string) $form_attrs['emailFromCustom'] ) : '';
			if ( '' !== $custom && is_email( $custom ) ) {
				return $custom;
			}
		} elseif ( '' !== $field && isset( $payload[ $field ] ) ) {
			$value = $payload[ $field ];
			// Nur skalare Werte taugen als Adresse; Envelopes von Add-ons
			// (Arrays) fallen automatisch auf die Admin-Adresse zurück.
			if ( is_scalar( $value ) ) {
				$email = sanitize_email( (string) $value );
				if ( '' !== $email && is_email( $email ) ) {
					return $email;
				}
			}
		}

		$admin = sanitize_email( (string) get_option( 'admin_email' ) );

		return ( '' !== $admin && is_email( $admin ) ) ? $admin : '';
	}

	/**
	 * From-Anzeigename: optional mit Platzhaltern, sonst Seitentitel.
	 *
	 * @param array<string,mixed>  $form_attrs Block attributes.
	 * @param array<string,mixed>  $payload    Submission payload.
	 * @param array<string,string> $labels     Field labels.
	 * @return string
	 */
	private static function resolve_notification_from_name( array $form_attrs, array $payload, array $labels ) {
		$custom = isset( $form_attrs['emailFromName'] ) ? trim( (string) $form_attrs['emailFromName'] ) : '';
		if ( '' !== $custom ) {
			$name = self::replace_notification_placeholders( $custom, $payload, $labels );
			$name = wp_strip_all_tags( (string) $name );
			$name = preg_replace( "/[\r\n]+/", ' ', (string) $name );
			$name = trim( $name );
			if ( '' !== $name ) {
				return mb_substr( $name, 0, 120 );
			}
		}

		return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Format mailbox header.
	 *
	 * @param string $name  Display name.
	 * @param string $email E-mail address.
	 * @return string
	 */
	private static function format_mailbox_header( $name, $email ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		$name = sanitize_text_field( (string) $name );
		$name = preg_replace( "/[\r\n]+/", ' ', $name );
		$name = trim( $name );
		if ( '' === $name ) {
			return $email;
		}

		return sprintf( '%s <%s>', $name, $email );
	}

	/**
	 * Ersetzt {{feldname}} und {{label_feldname}} in Betreff- und Absendernamen-Vorlagen.
	 *
	 * @param string               $template Subject template.
	 * @param array<string,mixed>  $payload  Submission payload.
	 * @param array<string,string> $labels   Field labels.
	 * @return string
	 */
	private static function replace_notification_placeholders( $template, array $payload, array $labels ) {
		$template = (string) $template;
		if ( '' === $template ) {
			return '';
		}
		return (string) preg_replace_callback(
			'/\{\{\s*(label_)?([a-z0-9_-]+)\s*\}\}/i',
			static function ( $matches ) use ( $payload, $labels ) {
				$is_label = ! empty( $matches[1] );
				$key      = sanitize_key( (string) $matches[2] );
				if ( '' === $key ) {
					return '';
				}
				if ( $is_label ) {
					if ( ! isset( $labels[ $key ] ) ) {
						return '';
					}
					$value = (string) $labels[ $key ];
				} elseif ( ! isset( $payload[ $key ] ) ) {
					return '';
				} else {
					$value = self::format_notification_field_value( $payload[ $key ] );
				}
				$value = preg_replace( "/[\r\n]+/", ' ', (string) $value );

				return mb_substr( (string) $value, 0, 120 );
			},
			$template
		);
	}

	/**
	 * Validate and sanitize one field.
	 *
	 * @param array<string,mixed> $field            Schema row.
	 * @param array<int,int>      $pending_file_ids Wird per Referenz gefüllt mit erfolgreichen Datei-IDs.
	 * @return string|array<string,mixed>|WP_Error  Wert (string), Datei-Referenz-Array oder Fehler.
	 */
	private static function process_field_value( array $field, array &$pending_file_ids = array() ) {
		$name     = $field['name'];
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$label    = $field['label'];

		if ( 'file' === $type ) {
			$res = self::process_file_field( $field );
			if ( ! is_wp_error( $res ) && is_array( $res ) && isset( $res['file_id'] ) ) {
				$pending_file_ids[] = (int) $res['file_id'];
			}
			return $res;
		}

		// Hidden mit lockedValue (M5): Wert komplett serverseitig setzen,
		// Client-Eingaben ignorieren.
		if ( 'hidden' === $type && ! empty( $field['locked'] ) ) {
			return isset( $field['hidden_value'] ) ? (string) $field['hidden_value'] : '';
		}

		$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wird direkt darunter typabhängig sanitisiert.

		if ( 'checkbox' === $type ) {
			$value = ! empty( $raw ) ? '1' : '0';
		} elseif ( 'textarea' === $type ) {
			$value = sanitize_textarea_field( (string) $raw );
		} elseif ( 'email' === $type ) {
			$value = sanitize_email( (string) $raw );
		} elseif ( 'hidden' === $type ) {
			$value = sanitize_text_field( (string) $raw );
			if ( isset( $field['hidden_value'] ) && '' !== (string) $field['hidden_value'] && (string) $field['hidden_value'] !== (string) $value ) {
				return new WP_Error( 'bdfrms_hidden', __( 'Ungültiges verstecktes Feld.', 'blitz-donner-forms' ) );
			}
		} else {
			$value = sanitize_text_field( (string) $raw );
		}

		if ( $required && '' === trim( (string) $value ) && 'checkbox' !== $type ) {
			return new WP_Error(
				'bdfrms_required',
				sprintf(
					/* translators: %s: field label */
					__( 'Bitte fülle das Feld "%s" aus.', 'blitz-donner-forms' ),
					$label
				)
			);
		}

		if ( 'checkbox' === $type && $required && '1' !== $value ) {
			return new WP_Error(
				'bdfrms_required',
				sprintf(
					/* translators: %s: field label */
					__( 'Bitte bestätige "%s".', 'blitz-donner-forms' ),
					$label
				)
			);
		}

		if ( 'email' === $type && '' !== $value && ! is_email( $value ) ) {
			return new WP_Error(
				'bdfrms_email',
				sprintf(
					/* translators: %s: field label */
					__( 'Das Feld "%s" enthält keine gültige E-Mail-Adresse.', 'blitz-donner-forms' ),
					$label
				)
			);
		}

		if ( 'url' === $type && '' !== $value ) {
			$url = esc_url_raw( $value );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error(
					'bdfrms_url',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" enthält keine gültige URL.', 'blitz-donner-forms' ),
						$label
					)
				);
			}
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return new WP_Error(
					'bdfrms_url',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" enthält keine gültige URL.', 'blitz-donner-forms' ),
						$label
					)
				);
			}
			$value = $url;
		}

		if ( 'number' === $type && '' !== $value ) {
			if ( ! is_numeric( $value ) ) {
				return new WP_Error(
					'bdfrms_number',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" muss eine Zahl sein.', 'blitz-donner-forms' ),
						$label
					)
				);
			}
			$num = floatval( $value );
			if ( isset( $field['min'] ) && '' !== $field['min'] && $num < floatval( $field['min'] ) ) {
				return new WP_Error( 'bdfrms_min', __( 'Zahl zu klein.', 'blitz-donner-forms' ) );
			}
			if ( isset( $field['max'] ) && '' !== $field['max'] && $num > floatval( $field['max'] ) ) {
				return new WP_Error( 'bdfrms_max', __( 'Zahl zu gross.', 'blitz-donner-forms' ) );
			}
			$value = (string) $num;
		}

		if ( 'range' === $type ) {
			$num = is_numeric( $value ) ? floatval( $value ) : floatval( $field['min'] ?? 0 );
			if ( isset( $field['min'] ) && $num < floatval( $field['min'] ) ) {
				$num = floatval( $field['min'] );
			}
			if ( isset( $field['max'] ) && $num > floatval( $field['max'] ) ) {
				$num = floatval( $field['max'] );
			}
			$value = (string) $num;
		}

		if ( 'date' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return new WP_Error( 'bdfrms_date', __( 'Ungültiges Datum.', 'blitz-donner-forms' ) );
			}
		}

		if ( 'time' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{2}:\d{2}/', $value ) ) {
				return new WP_Error( 'bdfrms_time', __( 'Ungültige Uhrzeit.', 'blitz-donner-forms' ) );
			}
		}

		if ( 'datetime' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value ) ) {
				return new WP_Error( 'bdfrms_datetime', __( 'Ungültiges Datum/Uhrzeit.', 'blitz-donner-forms' ) );
			}
		}

		if ( 'tel' === $type && '' !== $value ) {
			// Nur Ziffern, +, -, Leerzeichen, Klammern, Punkte, Schrägstriche; max 40 Zeichen.
			if ( ! preg_match( '/^[\d\+\-\s\(\)\.\/]{1,40}$/', $value ) ) {
				return new WP_Error(
					'bdfrms_tel',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" enthält eine ungültige Telefonnummer.', 'blitz-donner-forms' ),
						$label
					)
				);
			}
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) && ! empty( $field['options'] ) && '' !== $value ) {
			$opts = $field['options'];
			if ( ! in_array( $value, $opts, true ) ) {
				return new WP_Error( 'bdfrms_option', __( 'Ungültige Auswahl.', 'blitz-donner-forms' ) );
			}
		}

		// Generelles Längenlimit gegen Pufferaufblähung pro Feld.
		if ( is_string( $value ) && strlen( $value ) > 100000 ) {
			return new WP_Error( 'bdfrms_too_long', __( 'Eingabe zu lang.', 'blitz-donner-forms' ) );
		}

		return $value;
	}

	/**
	 * Datei-Upload-Pipeline:
	 *   1) PHP-Upload-Errors / Grössenlimit
	 *   2) Static-Validation: Endung, Doppel-Endung, finfo-MIME, accept-Match (BDFRMS_Security)
	 *   3) Add-on-Validierung via Filter `bdfrms_validate_file` (z. B. ClamAV im Security-Add-on)
	 *   4) Ablage über BDFRMS_File_Storage::store() (Filter `bdfrms_store_file` kann übernehmen)
	 *   5) Externer Post-Check-Filter (Custom-AV, Mehr-Augen)
	 *   6) Rückgabe einer "bdfrms-file:<id>"-Referenz, NICHT einer URL
	 *
	 * @param array<string,mixed> $field Schema.
	 * @return array{file_id:int,storage_id:string,_ref:string}|string|WP_Error
	 */
	private static function process_file_field( array $field ) {
		$name      = $field['name'];
		$required  = ! empty( $field['required'] );
		$label     = $field['label'];
		$max_mb    = isset( $field['max_size_mb'] ) ? (int) $field['max_size_mb'] : 8;
		$max_mb    = max( 1, $max_mb );
		$max_bytes = $max_mb * 1024 * 1024;
		$wp_max    = wp_max_upload_size();
		if ( $max_bytes > $wp_max ) {
			$max_bytes = $wp_max;
		}

		if ( empty( $_FILES[ $name ] ) || ( isset( $_FILES[ $name ]['error'] ) && UPLOAD_ERR_NO_FILE === (int) $_FILES[ $name ]['error'] ) ) {
			if ( $required ) {
				return new WP_Error(
					'bdfrms_file',
					sprintf(
						/* translators: %s: field label */
						__( 'Bitte wähle eine Datei für "%s".', 'blitz-donner-forms' ),
						$label
					)
				);
			}
			return '';
		}

		$file = $_FILES[ $name ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload-Array; Validierung folgt über BDFRMS_Security::validate_uploaded_file().
		if ( is_array( $file['name'] ?? null ) ) {
			BDFRMS_Security::log_event( 'file_reject_multi' );
			return new WP_Error( 'bdfrms_file', __( 'Mehrfach-Uploads sind nicht erlaubt.', 'blitz-donner-forms' ) );
		}
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			BDFRMS_Security::log_event( 'file_reject_php_error', array( 'error' => (int) $file['error'] ) );
			return new WP_Error( 'bdfrms_upload', __( 'Datei-Upload fehlgeschlagen.', 'blitz-donner-forms' ) );
		}
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			BDFRMS_Security::log_event(
				'file_reject_too_large',
				array(
					'size'  => (int) $file['size'],
					'limit' => (int) $max_bytes,
				)
			);
			return new WP_Error( 'bdfrms_size', __( 'Datei ist zu gross.', 'blitz-donner-forms' ) );
		}

		$accept     = isset( $field['accept'] ) ? (string) $field['accept'] : '';
		$validation = BDFRMS_Security::validate_uploaded_file( $file, $accept );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$ext       = $validation['ext'];
		$real_mime = $validation['mime'];

		/**
		 * Validierung der Tmp-Datei durch Add-ons, VOR der Ablage.
		 *
		 * Der Vorgänger scannte hier mit ClamAV; in der Basis ist das ein
		 * Hook. Rückgabe eines WP_Error lehnt die Datei ab; die Meldung
		 * wird dem Absender angezeigt.
		 *
		 * @since 0.1.0
		 *
		 * @param true|WP_Error       $valid     true = Datei zulässig.
		 * @param array               $file      $_FILES-Eintrag (tmp_name, name, size …).
		 * @param string              $real_mime finfo-MIME.
		 * @param array<string,mixed> $field     Schema-Feld.
		 */
		$file_check = apply_filters( 'bdfrms_validate_file', true, $file, $real_mime, $field );
		if ( is_wp_error( $file_check ) ) {
			BDFRMS_Security::log_event( 'file_reject_addon_check', array( 'msg' => sanitize_text_field( $file_check->get_error_message() ) ) );
			return $file_check;
		}

		$store = BDFRMS_File_Storage::store( $file, $name, $real_mime, $ext );
		if ( is_wp_error( $store ) ) {
			BDFRMS_Security::log_event( 'file_store_fail', array( 'msg' => $store->get_error_message() ) );
			return $store;
		}

		/**
		 * Optionaler externer Post-Check (z. B. zusätzlicher AV, externer DLP-Hook).
		 * Wenn der Filter WP_Error zurückgibt, wird die Datei aus dem Storage gelöscht.
		 *
		 * @param null|WP_Error      $error      Default null = ok.
		 * @param int                $file_id    Storage-File-ID.
		 * @param string             $real_mime  finfo-MIME.
		 * @param array<string,mixed>$field      Schema-Feld.
		 */
		$post_check = apply_filters( 'bdfrms_uploaded_file_post_check', null, (int) $store['file_id'], $real_mime, $field );
		if ( is_wp_error( $post_check ) ) {
			BDFRMS_File_Storage::delete( (int) $store['file_id'] );
			BDFRMS_Security::log_event( 'file_reject_post_check', array( 'msg' => sanitize_text_field( $post_check->get_error_message() ) ) );
			return $post_check;
		}

		return array(
			'file_id'    => (int) $store['file_id'],
			'storage_id' => (string) $store['storage_id'],
			'_ref'       => 'bdfrms-file:' . (int) $store['file_id'],
		);
	}

	/**
	 * CAPTCHA-Stufe (Friendly Captcha). Prueft das vom Frontend gelieferte
	 * Token serverseitig. Beide Erzwingungsmodi verlangen grundsaetzlich ein
	 * bestandenes CAPTCHA – fehlendes oder ungueltiges Token wird in beiden
	 * Faellen abgelehnt. Der einzige Unterschied liegt beim nicht erreichbaren
	 * Anbieter:
	 *   - soft (Default, «Mit Ausnahme bei Serverausfall»): Ist Friendly
	 *     Captcha nicht erreichbar, laesst der Submit trotzdem durch
	 *     (Ausfallsicherung), damit eine seltene Stoerung die Formulare nicht
	 *     blockiert.
	 *   - strict («Streng»): Auch bei nicht erreichbarem Anbieter wird
	 *     abgelehnt (fail-closed).
	 *
	 * Greift nur, wenn CAPTCHA fuer dieses Formular aktiv und konfiguriert ist.
	 * Laeuft als Stufe der Abwehrkette (bdfrms_submit_chain); das rohe Token
	 * wird nicht geloggt. Der Vorgaenger schrieb hier zusaetzlich ins
	 * Audit-Log – das liefert das Security-Add-on ueber das Event
	 * `bdfrms_security_event` (BDFRMS_Security::log_event).
	 *
	 * @param int                 $post_id    Post-ID.
	 * @param string              $form_id    Form-ID.
	 * @param array<string,mixed> $form_attrs bdfrms/form-Block-Attribute.
	 * @param string              $ip_address Bereits ermittelte Client-IP.
	 * @return true|string true (bestanden) oder STATUS_ERR_*-Slug.
	 */
	private static function check_captcha( $post_id, $form_id, array $form_attrs, $ip_address ) {
		if ( ! class_exists( 'BDFRMS_Captcha' ) || ! BDFRMS_Captcha::is_active_for_form( $form_attrs ) ) {
			return true;
		}

		$settings       = BDFRMS_Captcha::get_settings();
		$strict         = ( 'strict' === $settings['mode'] );
		$response_field = BDFRMS_Captcha::RESPONSE_FIELD;
		$token          = isset( $_POST[ $response_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $response_field ] ) ) : '';

		// Gemeinsamer Kontext fuer Events – ohne rohes Token, ohne Secret.
		$base_ctx = array(
			'form_id' => $form_id,
			'post_id' => $post_id,
			'mode'    => $strict ? 'strict' : 'soft',
		);

		// Kein Token vorhanden (Widget nicht geladen / nicht geloest / kein Skript).
		// Beide Modi lehnen ab – ein bestandenes CAPTCHA ist Pflicht.
		if ( '' === $token ) {
			BDFRMS_Security::log_event( 'captcha_fail', array_merge( $base_ctx, array( 'detail' => 'no_token' ) ) );
			return self::STATUS_ERR_CAPTCHA;
		}

		$verify = BDFRMS_Captcha::verify( $token, $ip_address );
		$result = $verify['result'];
		$detail = $verify['detail'];

		if ( 'pass' === $result ) {
			BDFRMS_Security::log_event( 'captcha_pass', $base_ctx );
			return true;
		}

		if ( 'unreachable' === $result ) {
			BDFRMS_Security::log_event( 'captcha_unreachable', array_merge( $base_ctx, array( 'detail' => $detail ) ) );
			if ( $strict ) {
				// strict: fail-closed – auch bei gestoertem Anbieter abgelehnt.
				return self::STATUS_ERR_CAPTCHA_UNREACHABLE;
			}
			// soft: Ausfallsicherung – Submit geht bei nicht erreichbarem
			// Anbieter ausnahmsweise ueber die uebrige Kette weiter.
			return true;
		}

		// result === 'fail' (ungueltiges/abgelaufenes/dupliziertes Token).
		// Beide Modi lehnen ab – ein bestandenes CAPTCHA ist Pflicht.
		BDFRMS_Security::log_event( 'captcha_fail', array_merge( $base_ctx, array( 'detail' => $detail ) ) );
		return self::STATUS_ERR_CAPTCHA;
	}

	/**
	 * Lightweight IP-based rate limit. Nutzt zentralen IP-Helper (H2).
	 *
	 * @param string $form_id    Form id.
	 * @param string $ip_address Bereits ermittelte Client-IP.
	 * @return bool
	 */
	private static function is_rate_limited( $form_id, $ip_address ) {
		if ( '' === $ip_address ) {
			return false;
		}

		$key        = 'bdfrms_rate_' . md5( $form_id . '|' . $ip_address );
		$window     = 10 * MINUTE_IN_SECONDS;
		$max_events = 5;
		/**
		 * Maximale Anzahl Submits pro IP/Form im Fenster (10 Min).
		 *
		 * @param int    $max_events Default 5.
		 * @param string $form_id    Form-ID.
		 */
		$max_events = (int) apply_filters( 'bdfrms_rate_limit_max', $max_events, $form_id );
		$max_events = max( 1, $max_events );

		$events = get_transient( $key );

		if ( ! is_array( $events ) ) {
			$events = array();
		}

		$cutoff = time() - $window;
		$events = array_values(
			array_filter(
				$events,
				static function ( $ts ) use ( $cutoff ) {
					return (int) $ts >= $cutoff;
				}
			)
		);

		if ( count( $events ) >= $max_events ) {
			return true;
		}

		$events[] = time();
		set_transient( $key, $events, $window );
		return false;
	}
}
