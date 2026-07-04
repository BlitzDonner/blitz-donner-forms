<?php
/**
 * Capability-Modell.
 *
 * Statt allen Plugin-Aktionen `manage_options` zu verlangen, vergibt das
 * Plugin eigene Capabilities. So können Site-Admins z. B. eine Redaktorin
 * mit Lese-Zugriff auf Einsendungen ausstatten, ohne ihr WP-Settings-Rechte
 * zu geben.
 *
 * Die Basis kennt: sehen, löschen, exportieren, Einstellungen. Add-ons
 * ergänzen eigene Capabilities über den Filter `bdfrms_capabilities`
 * (das Security-Add-on z. B. «entschlüsseln» getrennt von «herunterladen»).
 *
 * Default-Mapping bei Aktivierung: alle Caps -> Rolle `administrator`.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-Registry und Rollen-Zuweisung.
 */
class BDFRMS_Capabilities {

	const CAP_VIEW_SUBMISSIONS   = 'bdfrms_view_submissions';
	const CAP_DELETE_SUBMISSIONS = 'bdfrms_delete_submissions';
	const CAP_EXPORT_SUBMISSIONS = 'bdfrms_export_submissions';
	const CAP_MANAGE_SETTINGS    = 'bdfrms_manage_settings';

	/**
	 * Capability-Registry: Slug => Titel + Beschreibung für den Admin.
	 *
	 * @return array<string,array{title:string,description:string}>
	 */
	public static function registry() {
		$caps = array(
			self::CAP_VIEW_SUBMISSIONS   => array(
				'title'       => __( 'Einsendungen sehen', 'blitz-donner-forms' ),
				'description' => __( 'Einsendungen in Liste und Einzelansicht sehen.', 'blitz-donner-forms' ),
			),
			self::CAP_DELETE_SUBMISSIONS => array(
				'title'       => __( 'Löschen', 'blitz-donner-forms' ),
				'description' => __( 'Einsendungen löschen.', 'blitz-donner-forms' ),
			),
			self::CAP_EXPORT_SUBMISSIONS => array(
				'title'       => __( 'Exportieren', 'blitz-donner-forms' ),
				'description' => __( 'Einsendungen als CSV oder ZIP exportieren.', 'blitz-donner-forms' ),
			),
			self::CAP_MANAGE_SETTINGS    => array(
				'title'       => __( 'Einstellungen', 'blitz-donner-forms' ),
				'description' => __( 'Plugin-Einstellungen und Berechtigungen ändern.', 'blitz-donner-forms' ),
			),
		);

		/**
		 * Capability-Registry der Basis erweitern.
		 *
		 * Add-ons hängen hier eigene Capabilities an. Jeder Eintrag:
		 * Cap-Slug (mit Add-on-Präfix) => array{title,description}. Die
		 * Einstellungsseite rendert die Rollen-Zuweisung für alle
		 * registrierten Caps, auch die der Add-ons.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,array{title:string,description:string}> $caps Registry.
		 */
		$filtered = apply_filters( 'bdfrms_capabilities', $caps );

		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Alle registrierten Capability-Slugs.
	 *
	 * @return array<int,string>
	 */
	public static function all_caps() {
		return array_keys( self::registry() );
	}

	/**
	 * Verständliche Beschriftung pro Berechtigung.
	 *
	 * @param string $cap Capability-Slug.
	 * @return array{title:string,description:string}
	 */
	public static function cap_meta( $cap ) {
		$registry = self::registry();
		if ( isset( $registry[ $cap ] ) ) {
			return $registry[ $cap ];
		}
		return array(
			'title'       => $cap,
			'description' => '',
		);
	}

	/**
	 * Bei Plugin-Aktivierung initial alle Caps an `administrator` hängen.
	 *
	 * @return void
	 */
	public static function bootstrap_defaults() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::all_caps() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Bei Deaktivierung NICHT entfernen – sonst sind nach Re-Activate alle
	 * manuellen Zuweisungen weg. Entfernen nur in uninstall.php.
	 *
	 * @return void
	 */
	public static function remove_all_caps() {
		global $wp_roles;
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return;
		}
		foreach ( $wp_roles->roles as $role_name => $_ ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Komfort-Check.
	 *
	 * @param string $cap     Eine der CAP_* Konstanten oder eine Add-on-Cap.
	 * @param int    $user_id Optional. Standard: aktueller User.
	 * @return bool
	 */
	public static function user_can( $cap, $user_id = 0 ) {
		// Super-Admins und 'manage_options' dürfen alles (Bequemlichkeit).
		if ( $user_id > 0 ) {
			return user_can( $user_id, $cap ) || user_can( $user_id, 'manage_options' );
		}
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	/**
	 * Liste aller Rollen mit der angegebenen Cap, für Anzeige in Settings.
	 *
	 * @param string $cap Cap.
	 * @return array<int,string> Rollen-Slugs.
	 */
	public static function roles_with_cap( $cap ) {
		global $wp_roles;
		$out = array();
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return $out;
		}
		foreach ( $wp_roles->roles as $slug => $data ) {
			if ( ! empty( $data['capabilities'][ $cap ] ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Setzt für eine Rolle eine Cap an/aus.
	 *
	 * @param string $role_slug Rollen-Slug.
	 * @param string $cap       Cap.
	 * @param bool   $enabled   true = add_cap, false = remove_cap.
	 * @return bool true wenn Änderung erfolgreich.
	 */
	public static function set_role_cap( $role_slug, $cap, $enabled ) {
		if ( ! in_array( $cap, self::all_caps(), true ) ) {
			return false;
		}
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return false;
		}
		if ( $enabled ) {
			$role->add_cap( $cap );
		} else {
			$role->remove_cap( $cap );
		}
		return true;
	}
}
