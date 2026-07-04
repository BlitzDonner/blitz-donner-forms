<?php
/**
 * Deinstallation: entfernt Tabellen, Optionen und Capabilities der Basis.
 *
 * Läuft nur beim Löschen des Plugins über den Plugin-Bildschirm, nie bei
 * der Deaktivierung. Abgelegte Dateien im Uploads-Ordner bleiben bewusst
 * erhalten – Nutzdaten löscht WordPress nicht stillschweigend.
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdfrms_submissions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Deinstallation.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdfrms_files" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Deinstallation.

delete_option( 'bdfrms_db_version' );
delete_option( 'bdfrms_captcha_settings' );

// Capabilities aus allen Rollen entfernen.
$bdfrms_caps = array(
	'bdfrms_view_submissions',
	'bdfrms_delete_submissions',
	'bdfrms_export_submissions',
	'bdfrms_manage_settings',
);

$bdfrms_roles = wp_roles();
foreach ( $bdfrms_roles->roles as $bdfrms_role_slug => $bdfrms_role_data ) {
	$bdfrms_role = get_role( $bdfrms_role_slug );
	if ( ! $bdfrms_role ) {
		continue;
	}
	foreach ( $bdfrms_caps as $bdfrms_cap ) {
		$bdfrms_role->remove_cap( $bdfrms_cap );
	}
}
