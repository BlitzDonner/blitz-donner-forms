<?php
/**
 * Plugin Name: Blitz & Donner Forms
 * Description: Block-native forms for the WordPress editor. Build forms like any other content, manage submissions in the backend – with server-side validation, honeypot, rate limiting and optional Friendly Captcha.
 * Version: 0.1.0
 * Plugin URI: https://plugins.blitzdonner.ch
 * Author: Blitz & Donner
 * Author URI: https://www.blitzdonner.ch
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Text Domain: blitz-donner-forms
 * Domain Path: /languages
 *
 * @package Blitz_Donner_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BDF_PLUGIN_FILE', __FILE__ );
define( 'BDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BDF_PLUGIN_VERSION', '0.1.0' );

// Reihenfolge wichtig: Capabilities zuerst, dann alles, was sie nutzt.
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-capabilities.php';
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-captcha.php';
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-file-storage.php';
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-submit-handler.php';
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-install.php';
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-admin-submissions.php';
require_once BDF_PLUGIN_DIR . 'includes/class-bdf-admin-settings.php';

/**
 * Lädt Übersetzungen gemäss WordPress-Locale (Einstellungen → Allgemein →
 * Sprache der Website). Mehrsprachige Plugins können den Filter `locale`
 * setzen; dieser Hook läuft danach auf `init`.
 *
 * @return void
 */
function bdf_load_textdomain() {
	load_plugin_textdomain(
		'blitz-donner-forms',
		false,
		dirname( plugin_basename( BDF_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'bdf_load_textdomain', 1 );

register_activation_hook( __FILE__, array( 'BDF_Install', 'activate' ) );

BDF_File_Storage::boot();
BDF_Submit_Handler::boot();
BDF_Admin_Submissions::boot();
BDF_Admin_Settings::boot();
