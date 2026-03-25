<?php
/**
 * Plugin Name: FC2 BLOG to WordPress Importer
 * Plugin URI: https://next-season.net
 * Description: Import FC2 BLOG posts to WordPress via WP-CLI
 * Version: 0.1.0
 * Author: NExT-Season
 * Author URI: https://next-season.net
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fc2blog2wp
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register WP-CLI command if WP-CLI is available
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class/fc2_html_parser.php';
	require_once __DIR__ . '/class/fc2blog2wp_class.php';
	require_once __DIR__ . '/class/fc2blog2wp_command.php';
	WP_CLI::add_command( 'fc2', 'FC2Blog2WP_Command' );
}
