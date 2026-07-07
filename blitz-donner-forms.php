<?php
/**
 * Plugin Name: Blitz & Donner Forms
 * Description: Block-native forms for the WordPress editor. Build forms like any other content, manage submissions in the backend – with server-side validation, honeypot, rate limiting and optional Friendly Captcha.
 * Version: 0.8.2
 * Plugin URI: https://plugins.blitzdonner.ch
 * Author: Blitz & Donner
 * Author URI: https://www.blitzdonner.ch
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Text Domain: blitz-donner-forms
 * Domain Path: /languages
 * Update URI: https://plugins.blitzdonner.ch/blitz-donner-forms
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BDFRMS_PLUGIN_FILE', __FILE__ );
define( 'BDFRMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDFRMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BDFRMS_PLUGIN_VERSION', '0.8.2' );

// Reihenfolge wichtig: Capabilities und Security zuerst, dann alles, was sie nutzt.
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-capabilities.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-security.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-captcha.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-file-storage.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-field-renderer.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-plugin.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-submit-handler.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-install.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-admin-submissions.php';
require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-admin-settings.php';

/**
 * Lädt Übersetzungen gemäss WordPress-Locale (Einstellungen → Allgemein →
 * Sprache der Website). Mehrsprachige Plugins können den Filter `locale`
 * setzen; dieser Hook läuft danach auf `init`.
 *
 * @return void
 */
function bdfrms_load_textdomain() {
	load_plugin_textdomain(
		'blitz-donner-forms',
		false,
		dirname( plugin_basename( BDFRMS_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'bdfrms_load_textdomain', 1 );

register_activation_hook( __FILE__, array( 'BDFRMS_Install', 'activate' ) );

BDFRMS_Security::boot();
BDFRMS_File_Storage::boot();
BDFRMS_Plugin::boot();
BDFRMS_Admin_Submissions::boot();
BDFRMS_Admin_Settings::boot();

/*
 * Interims-Update-Client (nur bis zur WordPress.org-Freigabe):
 * Solange die Basis über plugins.blitzdonner.ch ausgeliefert wird, holt
 * sie ihre Updates von dort – im Public-Modus ohne Lizenz-Token, signiert
 * (Ed25519) wie alle BD-Auslieferungen. Der WordPress.org-Build enthält
 * die Client-Datei NICHT (Verzeichnis-Richtlinie: keine eigenen
 * Update-Mechanismen); dieser Block bleibt dann wirkungslos. Früh auf
 * plugins_loaded, damit der Filter vor dem Befüllen des
 * update_plugins-Transients registriert ist (Lehre aus dem Vorgänger).
 */
if ( file_exists( BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-update-client.php' ) ) {
	require_once BDFRMS_PLUGIN_DIR . 'includes/class-bdfrms-update-client.php';
	add_action(
		'plugins_loaded',
		static function () {
			$GLOBALS['bdfrms_update_client'] = new BDFRMS_Update_Client(
				array(
					'plugin_file' => BDFRMS_PLUGIN_FILE,
					'slug'        => 'blitz-donner-forms',
					'server_url'  => 'https://plugins.blitzdonner.ch',
					'version'     => BDFRMS_PLUGIN_VERSION,
					'public'      => true,
				)
			);
		},
		1
	);
}
