<?php
/**
 * Plugin Name:       Viney Post QR Codes
 * Description:       Automatically generates downloadable QR codes for selected post types.
 * Version:           1.0.0
 * Requires PHP:      8.2
 * Author:            Viney
 * Author URI:        https://www.itsviney.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       viney-post-qr-codes
 *
 * @package Viney\PostQRCodes
 */

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( class_exists( \Viney\PostQRCodes\Plugin::class ) ) {
	\Viney\PostQRCodes\Plugin::instance();
	register_activation_hook( __FILE__, array( \Viney\PostQRCodes\Plugin::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( \Viney\PostQRCodes\Plugin::class, 'deactivate' ) );
}
