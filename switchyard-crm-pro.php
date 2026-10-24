<?php
/**
 * Plugin Name:       Switchyard Pro
 * Plugin URI:        https://github.com/jgalea/switchyard-crm-pro
 * Description:       LMS, membership and advanced ecommerce features for Switchyard.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  switchyard-crm
 * Author:            Jean Galea
 * Author URI:        https://jeangalea.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/jgalea/switchyard-crm-pro
 * Text Domain:       switchyard-crm-pro
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const SWITCHYARD_PRO_VERSION = '1.0.0';

define( 'SWITCHYARD_PRO_FILE', __FILE__ );
define( 'SWITCHYARD_PRO_DIR', plugin_dir_path( __FILE__ ) );

require_once SWITCHYARD_PRO_DIR . 'vendor/autoload.php';

( new Switchyard\Pro\Updater() )->register_hooks();

/**
 * The free plugin owns the engine, so this one does nothing until it loads.
 * switchyard_loaded fires after the container is wired, which is the only point
 * at which the services this plugin needs actually exist.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( Switchyard\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Switchyard CRM Pro needs the free Switchyard CRM plugin to be installed and active.', 'switchyard-crm-pro' )
					);
				}
			);

			return;
		}

		add_action( 'switchyard_loaded', array( Switchyard\Pro\Plugin::class, 'boot' ) );
	},
	1
);
