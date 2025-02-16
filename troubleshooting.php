<?php
/**
 * Plugins primary file, in charge of including all other dependencies.
 *
 * @package SiteHealth\Troubleshooting
 *
 * @wordpress-plugin
 * Plugin Name: Troubleshooting
 * Plugin URI: https://wordpress.org/plugins/troubleshooting/
 * Description: Checks the health of your WordPress install.
 * Author: Clorith
 * Version: 1.0.0
 * Text Domain: troubleshooting
 * License: GPLv2 or later
 */

namespace SiteHealth\Troubleshooting;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'We\'re sorry, but you can not directly access this file.' );
}

define( 'SITEHEALTH_TROUBLESHOOTING_PLUGIN_FILE', __FILE__ );
define( 'SITEHEALTH_TROUBLESHOOTING_PLUGIN_DIRECTORY', __DIR__ );

require_once __DIR__ . '/Troubleshooting/class-loopback.php';
require_once __DIR__ . '/Troubleshooting/class-troubleshoot.php';

/**
 * Adds the Tools tab to the Site Health page.
 *
 * @param array<string, string> $tabs The tabs on the Site Health page.
 *
 * @return array<string, string>
 */
function add_tools_tab( array $tabs ) : array {
	return array_merge(
		$tabs,
		array(
			'troubleshooting' => \esc_html__( 'Troubleshooting', 'troubleshooting' ),
		)
	);
}

/**
 * Adds the content for the Tools tab on the Site Health page.
 *
 * @param string $tab The current tab being viewed.
 *
 * @return void
 */
function add_tools_tab_content( string $tab ) : void {
	if ( 'troubleshooting' !== $tab ) {
		return;
	}

	include_once __DIR__ . '/templates/troubleshooting.php';
}

/**
 * Register hooks and filters for the Site Health screen.
 *
 * @return void
 */
function register_actions() : void {
	if ( ! \is_admin() || ! \current_user_can( 'manage_options' ) ) {
		return;
	}

	\add_filter( 'site_health_navigation_tabs', __NAMESPACE__ . '\add_tools_tab' );
	\add_action( 'site_health_tab_content', __NAMESPACE__ . '\add_tools_tab_content' );
}

\add_action( 'load-site-health.php', __NAMESPACE__ . '\register_actions' );

// Instantiate the Troubleshooting wrapper class.
Troubleshoot::get_instance();
