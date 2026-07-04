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

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdf_submissions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Deinstallation.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdf_files" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Deinstallation.

delete_option( 'bdf_db_version' );
delete_option( 'bdf_captcha_settings' );

// Capabilities aus allen Rollen entfernen.
$bdf_caps = array(
	'bdf_view_submissions',
	'bdf_delete_submissions',
	'bdf_export_submissions',
	'bdf_manage_settings',
);

$bdf_roles = wp_roles();
foreach ( $bdf_roles->roles as $bdf_role_slug => $bdf_role_data ) {
	$bdf_role = get_role( $bdf_role_slug );
	if ( ! $bdf_role ) {
		continue;
	}
	foreach ( $bdf_caps as $bdf_cap ) {
		$bdf_role->remove_cap( $bdf_cap );
	}
}
