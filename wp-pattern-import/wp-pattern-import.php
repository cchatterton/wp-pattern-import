<?php
/**
 * Plugin Name: WP Pattern Import
 * Description: Imports repeated HTML patterns from approved source pages into WordPress posts using saved scraping recipes.
 * Version: 0.13.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/cchatterton/wp-pattern-import
 * Author: AlphaSys
 * Author URI: https://alphasys.com.au
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-pattern-import
 * AlphaSys Controller API: 1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPI_VERSION', '0.13.0' );
define( 'WPI_PLUGIN_FILE', __FILE__ );
define( 'WPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPI_CRON_HOOK', 'wpi_daily_import_event' );

require_once WPI_PLUGIN_DIR . 'includes/scanner.php';
require_once WPI_PLUGIN_DIR . 'includes/importer.php';
require_once WPI_PLUGIN_DIR . 'includes/cron.php';
require_once WPI_PLUGIN_DIR . 'includes/admin.php';
require_once WPI_PLUGIN_DIR . 'includes/controller-client.php';

asuc_client_register( __FILE__, 'wp-pattern-import' );

register_activation_hook( __FILE__, 'wpi_activate' );
register_deactivation_hook( __FILE__, 'wpi_deactivate' );

/**
 * Ensure cron state follows any existing recipe when the plugin is activated.
 */
function wpi_activate() {
	$recipe = get_option( 'wpi_recipe', array() );
	$schedule = isset( $recipe['schedule'] ) ? $recipe['schedule'] : 'manual';
	$schedule_time = isset( $recipe['schedule_time'] ) ? $recipe['schedule_time'] : '02:00';
	wpi_update_schedule( $schedule, $schedule_time );
}

/**
 * Clear scheduled imports when the plugin is deactivated.
 */
function wpi_deactivate() {
	wpi_clear_schedule();
}
