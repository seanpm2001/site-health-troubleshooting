<?php
/*
	Plugin Name: Troubleshooting Mode
	Description: Conditionally disabled themes or plugins on your site for a given session, used to rule out conflicts during troubleshooting.
	Version: 1.0.0
 */

namespace SiteHealth\Troubleshooting;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'We\'re sorry, but you can not directly access this file.' );
}

// Set the MU plugin version.
define( 'TROUBLESHOOTING_MODE_PLUGIN_VERSION', '1.0.0' );

class MustUse {
	/**
	 * @var null|string
	 */
	private $disable_hash = null;

	/**
	 * @var bool
	 */
	private $override_active = true;

	/**
	 * @var array<int, string>
	 */
	private $active_plugins = array();

	/**
	 * @var array<int|string, string>
	 */
	private $allowed_plugins = array();

	/**
	 * @var string
	 */
	private $current_theme;

	/**
	 * @var null|\WP_Theme
	 */
	private $current_theme_details;

	/**
	 * @var bool
	 */
	private $self_fetching_theme = false;

	/**
	 * @var string[]
	 */
	private $available_query_args = array(
		'wp-health-check-disable-plugins',
		'health-check-disable-plugins-hash',
		'health-check-disable-troubleshooting',
		'health-check-change-active-theme',
		'health-check-troubleshoot-enable-plugin',
		'health-check-troubleshoot-disable-plugin',
		'health-check-plugin-force-enable',
		'health-check-plugin-force-disable',
		'health-check-theme-force-switch',
		'_wpnonce',
	);

	/**
	 * @var string[]
	 */
	private $default_themes = array(
		'twentytwentyfour',
		'twentytwentythree',
		'twentytwentytwo',
		'twentytwentyone',
		'twentytwenty',
		'twentynineteen',
		'twentyseventeen',
		'twentysixteen',
		'twentyfifteen',
		'twentyfourteen',
		'twentythirteen',
		'twentytwelve',
		'twentyeleven',
		'twentyten',
	);

	/**
	 * @var string
	 */
	private $latest_classic_default_theme = 'twentytwentyone';

	/**
	 * @var bool
	 */
	private $show_nonce_validator = false;

	/**
	 * @var string
	 */
	private $nonce_validator_details = '';

	/**
	 * @var array<string, bool|int|string>
	 */
	private $nonce_validator_fields = array();

	/**
	 * Health_Check_Troubleshooting_MU constructor.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Actually initiation of the plugin.
	 *
	 * @return void
	 */
	public function init() : void {
		\add_filter( 'option_active_plugins', array( $this, 'health_check_loopback_test_disable_plugins' ) );
		\add_filter( 'option_active_sitewide_plugins', array( $this, 'health_check_loopback_test_disable_plugins' ) );

		\add_filter( 'pre_option_template', array( $this, 'health_check_troubleshoot_theme_template' ) );
		\add_filter( 'pre_option_stylesheet', array( $this, 'health_check_troubleshoot_theme_stylesheet' ) );

		\add_filter( 'bulk_actions-plugins', array( $this, 'remove_plugin_bulk_actions' ) );
		\add_filter( 'handle_bulk_actions-plugins', array( $this, 'handle_plugin_bulk_actions' ), 10, 3 );

		$this->load_options();

		// If troubleshooting mode is enabled, add special filters and actions.
		if ( $this->is_troubleshooting() ) {
			// Attempt to avoid cache entries from a troubleshooting session.
			\wp_suspend_cache_addition( true );

			// Add nocache headers for browser caches.
			\add_action( 'init', 'nocache_headers' );
			\add_action( 'admin_init', 'nocache_headers' );

			\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			\add_action( 'admin_bar_menu', array( $this, 'health_check_troubleshoot_menu_bar' ), 999 );

			\add_filter( 'wp_fatal_error_handler_enabled', '__return_false' );

			\add_action( 'admin_notices', array( $this, 'prompt_install_default_theme' ) );
			\add_filter( 'user_has_cap', array( $this, 'remove_plugin_theme_install' ) );

			\add_action( 'plugin_action_links', array( $this, 'plugin_actions' ), 50, 4 );

			\add_action( 'admin_notices', array( $this, 'display_dashboard_widget' ) );
			\add_action( 'admin_footer', array( $this, 'dashboard_widget_scripts' ) );

			\add_action( 'wp_logout', array( $this, 'health_check_troubleshooter_mode_logout' ) );
			\add_action( 'init', array( $this, 'health_check_troubleshoot_get_captures' ) );

			// If needed, prompt the user to confirm their actions if they are missing a valid nonce.
			\add_action( 'admin_footer', array( $this, 'nonce_confirmation_prompt' ) );

			/*
			 * Plugin activations can be forced by other tools in things like themes, so let's
			 * attempt to work around that by forcing plugin lists back and forth.
			 *
			 * This is not an ideal scenario, but one we must accept as reality.
			 */
			\add_action( 'activated_plugin', array( $this, 'plugin_activated' ) );
		}
	}

	/**
	 * Set up the class variables based on option table entries.
	 *
	 * @return void
	 */
	public function load_options() : void {
		$this->disable_hash    = \get_option( 'health-check-disable-plugin-hash', null );
		$this->allowed_plugins = \get_option( 'health-check-allowed-plugins', array() );
		$this->active_plugins  = $this->get_unfiltered_plugin_list();
		$this->current_theme   = \get_option( 'health-check-current-theme', false );
	}

	/**
	 * Enqueue styles and scripts used by the MU plugin if applicable.
	 *
	 * @return void
	 */
	public function enqueue_assets() : void {
		if ( ! \is_admin() ) {
			return;
		}

		if ( ! \file_exists( \WP_PLUGIN_DIR . '/troubleshooting/build/troubleshooting.asset.php' ) ) {
			return;
		}

		$troubleshooter = include \WP_PLUGIN_DIR . '/troubleshooting/build/troubleshooting.asset.php';

		\wp_enqueue_script( 'troubleshooting-must-use', \plugins_url( '/troubleshooting/build/troubleshooting.js' ), array( 'site-health' ), $troubleshooter['version'], true );
		\wp_enqueue_style( 'troubleshooting-must-use', \plugins_url( '/troubleshooting/build/troubleshooting.css' ), array(), $troubleshooter['version'] );
	}

	/**
	 * Add a prompt to install a default theme.
	 *
	 * If no default theme exists, we can't reliably assert if an issue is
	 * caused by the theme. In these cases we should provide an easy step
	 * to get to, and install, one of the default themes.
	 *
	 * @return void
	 */
	public function prompt_install_default_theme() : void {
		if ( ! empty( $this->has_default_theme() ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning dismissable"><p>%s</p><p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button button-secondary">%s</a></p></div>',
			\esc_html__( 'You don\'t have any of the default themes installed. A default theme helps you determine if your current theme is causing conflicts.', 'troubleshooting' ),
			\esc_url(
				\admin_url(
					sprintf(
						'theme-install.php?theme=%s',
						$this->default_themes[0]
					)
				)
			),
			\esc_html__( 'Install the latest default theme', 'troubleshooting' ),
			\esc_url(
				\admin_url(
					sprintf(
						'theme-install.php?theme=%s',
						$this->latest_classic_default_theme
					)
				)
			),
			\esc_html__( 'Install the latest classic default theme', 'troubleshooting' )
		);
	}

	/**
	 * Remove the `Add` option for plugins and themes.
	 *
	 * When troubleshooting, adding or changing themes and plugins can
	 * lead to unexpected results. Remove these menu items to make it less
	 * likely that a user breaks their site through these.
	 *
	 * @param  array<string, bool> $caps Array containing the current users capabilities.
	 *
	 * @return array<string, bool>
	 */
	public function remove_plugin_theme_install( array $caps ) : array {
		$caps['switch_themes'] = false;

		/*
		 * This is to early for `get_current_screen()`, so we have to do it the
		 * old fashioned way with `$_SERVER`.
		 */
		$request_uri = \filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_STRING );
		if ( 'plugin-install.php' === substr( $request_uri, -18 ) ) {
			$caps['activate_plugins'] = false;
		}

		return $caps;
	}

	/**
	 * Fire on plugin activation.
	 *
	 * When in Troubleshooting Mode, plugin activations
	 * will clear out the DB entry for `active_plugins`, this is bad.
	 *
	 * We fix this by re-setting the DB entry if anything tries
	 * to modify it during troubleshooting.
	 *
	 * @return void
	 */
	public function plugin_activated() : void {
		// Force the database entry for active plugins if someone tried changing plugins while in Troubleshooting Mode.
		\update_option( 'active_plugins', $this->active_plugins );
	}

	/**
	 * Trigger when bulk actions for the plugin view are used.
	 *
	 * @param string $sendback
	 * @param string $action
	 * @param array<int, string> $plugins
	 *
	 * @return string
	 */
	public function handle_plugin_bulk_actions( string $sendback, string $action, array $plugins ) : string {
		if ( ! $this->is_troubleshooting() && 'health-check-troubleshoot' !== $action ) {
			return $sendback;
		}

		$sendback = \self_admin_url( 'plugins.php' );

		if ( 'health-check-troubleshoot' === $action ) {
			foreach ( $plugins as $single_plugin ) {
				$plugin_slug = explode( '/', $single_plugin );
				$plugin_slug = $plugin_slug[0];

				if ( in_array( $single_plugin, $this->active_plugins, true ) ) {
					$this->allowed_plugins[ $plugin_slug ] = $plugin_slug;
				}
			}

			Troubleshoot::initiate_troubleshooting_mode( $this->allowed_plugins );

			if ( ! $this->test_site_state() ) {
				$this->allowed_plugins = array();
				\update_option( 'health-check-allowed-plugins', $this->allowed_plugins );

				$this->add_dashboard_notice(
					\__( 'When enabling troubleshooting on the selected plugins, a site failure occurred. Because of this the selected plugins were kept disabled while troubleshooting mode started.', 'troubleshooting' ),
					'warning'
				);
			}
		}

		if ( 'health-check-enable' === $action ) {
			$old_allowed_plugins = $this->allowed_plugins;

			foreach ( $plugins as $single_plugin ) {
				$plugin_slug = explode( '/', $single_plugin );
				$plugin_slug = $plugin_slug[0];

				if ( in_array( $single_plugin, $this->active_plugins, true ) ) {
					$this->allowed_plugins[ $plugin_slug ] = $plugin_slug;
				}
			}

			\update_option( 'health-check-allowed-plugins', $this->allowed_plugins );

			if ( ! $this->test_site_state() ) {
				$this->allowed_plugins = $old_allowed_plugins;
				update_option( 'health-check-allowed-plugins', $old_allowed_plugins );

				$this->add_dashboard_notice(
					\__( 'When bulk-enabling plugins, a site failure occurred. Because of this the change was automatically reverted.', 'troubleshooting' ),
					'warning'
				);
			}
		}

		if ( 'health-check-disable' === $action ) {
			$old_allowed_plugins = $this->allowed_plugins;

			foreach ( $plugins as $single_plugin ) {
				$plugin_slug = explode( '/', $single_plugin );
				$plugin_slug = $plugin_slug[0];

				if ( in_array( $single_plugin, $this->active_plugins, true ) ) {
					unset( $this->allowed_plugins[ $plugin_slug ] );
				}
			}

			\update_option( 'health-check-allowed-plugins', $this->allowed_plugins );

			if ( ! $this->test_site_state() ) {
				$this->allowed_plugins = $old_allowed_plugins;
				\update_option( 'health-check-allowed-plugins', $old_allowed_plugins );

				$this->add_dashboard_notice(
					\__( 'When bulk-disabling plugins, a site failure occurred. Because of this the change was automatically reverted.', 'troubleshooting' ),
					'warning'
				);
			}
		}

		return $sendback;
	}

	/**
	 * Remove actions from underneath individual plugins.
	 *
	 * @param array<string, string> $actions
	 *
	 * @return array<string, string>
	 */
	public function remove_plugin_bulk_actions( array $actions ) : array {
		if ( ! $this->is_troubleshooting() ) {
			$actions['health-check-troubleshoot'] = __( 'Troubleshoot', 'troubleshooting' );

			return $actions;
		}

		$actions = array(
			'health-check-enable'  => __( 'Enable while troubleshooting', 'troubleshooting' ),
			'health-check-disable' => __( 'Disable while troubleshooting', 'troubleshooting' ),
		);

		return $actions;
	}

	/**
	 * Modify plugin actions.
	 *
	 * While in Troubleshooting Mode, weird things will happen if you start
	 * modifying your plugin list. Prevent this, but also add in the ability
	 * to enable or disable a plugin during troubleshooting from this screen.
	 *
	 * @param array<string, string> $actions
	 * @param string $plugin_file
	 * @param array<string, bool|string|string[]> $plugin_data
	 * @param string $context
	 *
	 * @return array<string, string>
	 */
	public function plugin_actions( array $actions, string $plugin_file, array $plugin_data, string $context ) : array {
		if ( 'mustuse' === $context ) {
			return $actions;
		}

		/*
		 * Disable all plugin actions when in Troubleshooting Mode.
		 *
		 * We intentionally remove all plugin actions to avoid accidental clicking, activating or deactivating plugins
		 * while our plugin is altering plugin data may lead to unexpected behaviors, so to keep things sane we do
		 * not allow users to perform any actions during this time.
		 */
		$actions = array();

		// This isn't an active plugin, so does not apply to our troubleshooting scenarios.
		if ( ! in_array( $plugin_file, $this->active_plugins, true ) ) {
			return $actions;
		}

		// Set a slug if the plugin lives in the plugins directory root.
		if ( ! stristr( $plugin_file, '/' ) ) {
			$plugin_slug = $plugin_file;
		} else { // Set the slug for plugin inside a folder.
			$plugin_slug = explode( '/', $plugin_file );
			$plugin_slug = $plugin_slug[0];
		}

		if ( in_array( $plugin_slug, $this->allowed_plugins, true ) ) {
			$actions['troubleshoot-disable'] = sprintf(
				'<a href="%s" id="disable-troubleshooting-%s">%s</a>',
				\esc_url(
					\add_query_arg(
						array(
							'health-check-troubleshoot-disable-plugin' => $plugin_slug,
							'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-disable-plugin', array( $plugin_slug ) ),
						),
						\admin_url( 'plugins.php' )
					)
				),
				\esc_attr( $plugin_slug ),
				\esc_html__( 'Disable while troubleshooting', 'troubleshooting' )
			);
		} else {
			$actions['troubleshoot-disable'] = sprintf(
				'<a href="%s" id="enable-troubleshooting-%s">%s</a>',
				\esc_url(
					\add_query_arg(
						array(
							'health-check-troubleshoot-enable-plugin' => $plugin_slug,
							'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-enable-plugin', array( $plugin_slug ) ),
						),
						\admin_url( 'plugins.php' )
					)
				),
				\esc_attr( $plugin_slug ),
				\esc_html__( 'Enable while troubleshooting', 'troubleshooting' )
			);
		}

		return $actions;
	}

	/**
	 * Get the actual list of active plugins.
	 *
	 * When in Troubleshooting Mode we override the list of plugins,
	 * this function lets us grab the active plugins list without
	 * any interference.
	 *
	 * @return array<int, string> Array of active plugins.
	 */
	public function get_unfiltered_plugin_list() : array {
		$this->override_active = false;
		$all_plugins           = \get_option( 'active_plugins' );
		$this->override_active = true;

		if ( ! $all_plugins ) {
			$all_plugins = array();
		}

		return $all_plugins;
	}

	/**
	 * Check if the user is currently in Troubleshooting Mode or not.
	 *
	 * @return bool
	 */
	public function is_troubleshooting() {
		// Check if a session cookie to disable plugins has been set.
		if ( isset( $_COOKIE['wp-health-check-disable-plugins'] ) ) {
			$client_ip = \filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- We are intentionally not sanitizing the cookie, as we are using it to populate a global variable which is sanitized correctly later, and this would lead to potential double-sanitizing.
			$_GET['health-check-disable-plugin-hash'] = $_COOKIE['wp-health-check-disable-plugins'] . md5( $client_ip );
		}

		// If the disable hash isn't set, no need to interact with things.
		if ( ! isset( $_GET['health-check-disable-plugin-hash'] ) ) {
			return false;
		}

		if ( empty( $this->disable_hash ) ) {
			return false;
		}

		// If the plugin hash is not valid, we also break out
		if ( \sanitize_text_field( \wp_unslash( $_GET['health-check-disable-plugin-hash'] ) ) !== $this->disable_hash ) {
			return false;
		}

		return true;
	}

	/**
	 * Filter the plugins that are activated in WordPress.
	 *
	 * @param array<int, string> $plugins An array of plugins marked as active.
	 *
	 * @return array<int, string>
	 */
	function health_check_loopback_test_disable_plugins( array $plugins ) : array {
		if ( ! $this->is_troubleshooting() || ! $this->override_active ) {
			return $plugins;
		}

		// If we've received a comma-separated list of allowed plugins, we'll add them to the array of allowed plugins.
		if ( isset( $_GET['health-check-allowed-plugins'] ) ) {
			$this->allowed_plugins = explode( ',', \sanitize_text_field( \wp_unslash( $_GET['health-check-allowed-plugins'] ) ) );
		}

		foreach ( $plugins as $plugin_no => $plugin_path ) {
			// Split up the plugin path, [0] is the slug and [1] holds the primary plugin file.
			$plugin_parts = explode( '/', $plugin_path );

			// We may want to allow individual, or groups of plugins, so introduce a skip-mechanic for those scenarios.
			if ( in_array( $plugin_parts[0], $this->allowed_plugins, true ) ) {
				continue;
			}

			// Remove the reference to this plugin.
			unset( $plugins[ $plugin_no ] );
		}

		// Return a possibly modified list of activated plugins.
		return $plugins;
	}

	/**
	 * Check if a default theme exists.
	 *
	 * If a default theme exists, return the most recent one, if not return `false`.
	 *
	 * @return string
	 */
	function has_default_theme() {
		foreach ( $this->default_themes as $default_theme ) {
			if ( $this->theme_exists( $default_theme ) ) {
				return $default_theme;
			}
		}

		return '';
	}

	/**
	 * Check if a theme exists by looking for the slug.
	 *
	 * @param string $theme_slug
	 *
	 * @return bool
	 */
	function theme_exists( $theme_slug ) {
		return is_dir( WP_CONTENT_DIR . '/themes/' . $theme_slug );
	}

	/**
	 * Check if theme overrides are active.
	 *
	 * @return bool
	 */
	function override_theme() {
		if ( ! $this->is_troubleshooting() ) {
			return false;
		}

		return true;
	}

	/**
	 * Override the default theme.
	 *
	 * Attempt to set one of the default themes, or a theme of the users choosing, as the active one
	 * during Troubleshooting Mode.
	 *
	 * @param string $default
	 *
	 * @return string
	 */
	function health_check_troubleshoot_theme_stylesheet( string $default ) : string {
		if ( $this->self_fetching_theme ) {
			return $default;
		}

		if ( ! $this->override_theme() ) {
			return $default;
		}

		if ( empty( $this->current_theme_details ) ) {
			$this->self_fetching_theme   = true;
			$this->current_theme_details = \wp_get_theme( $this->current_theme );
			$this->self_fetching_theme   = false;
		}

		// If no theme has been chosen, start off by troubleshooting as a default theme if one exists.
		$default_theme = $this->has_default_theme();
		if ( empty( $this->current_theme ) ) {
			if ( ! empty( $default_theme ) ) {
				return $default_theme;
			}
		}

		return $this->current_theme;
	}

	/**
	 * Override the default parent theme.
	 *
	 * If this is a child theme, override the parent and provide our users chosen themes parent instead.
	 *
	 * @param bool|string $default
	 *
	 * @return bool|string
	 */
	function health_check_troubleshoot_theme_template( $default ) {
		if ( $this->self_fetching_theme ) {
			return $default;
		}

		if ( ! $this->override_theme() ) {
			return $default;
		}

		if ( empty( $this->current_theme_details ) ) {
			$this->self_fetching_theme   = true;
			$this->current_theme_details = \wp_get_theme( $this->current_theme );
			$this->self_fetching_theme   = false;
		}

		// If no theme has been chosen, start off by troubleshooting as a default theme if one exists.
		$default_theme = $this->has_default_theme();
		if ( empty( $this->current_theme ) ) {
			if ( ! empty( $default_theme ) ) {
				return $default_theme;
			}
		}

		if ( $this->current_theme_details->parent() ) {
			return $this->current_theme_details->get_template();
		}

		return $this->current_theme;
	}

	/**
	 * Disable Troubleshooting Mode on logout.
	 *
	 * If logged in, disable the Troubleshooting Mode when the logout
	 * event is fired, this ensures we start with a clean slate on
	 * the next login.
	 *
	 * @return void
	 */
	function health_check_troubleshooter_mode_logout() {
		if ( isset( $_COOKIE['wp-health-check-disable-plugins'] ) ) {
			$this->disable_troubleshooting_mode();
		}
	}

	function disable_troubleshooting_mode() : void {
		unset( $_COOKIE['wp-health-check-disable-plugins'] );
		setcookie( 'wp-health-check-disable-plugins', '', 0, COOKIEPATH, COOKIE_DOMAIN );
		\delete_option( 'health-check-allowed-plugins' );
		\delete_option( 'health-check-default-theme' );
		\delete_option( 'health-check-current-theme' );
		\delete_option( 'health-check-dashboard-notices' );

		\delete_option( 'health-check-backup-plugin-list' );
	}

	/**
	 * Takes a URL, or uses the current URL, and removes the query args we use to control the Troubleshooting Mode.
	 *
	 * @param string $url Optional. Defaults to the current URL. The URL to strip query arguments from.
	 *
	 * @return string
	 */
	private function get_clean_url( $url = null ) {
		if ( ! $url ) {
			$http_host   = \filter_input( INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING );
			$request_uri = \filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_STRING );

			// The full URL for the current request.
			$raw_url = ( \is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;

			// We prepare the `REQUEST_URI` entry our selves, to account for WP installs in subdirectories or similar.
			$request_uri = str_ireplace( \site_url( '/' ), '', $raw_url );

			$url = \site_url( $request_uri );
		}

		return \remove_query_arg( $this->available_query_args, $url );
	}

	/**
	 * A helper function to validate if nonces exist, and is valid.
	 *
	 * This helps us add nonce-verification to internal links within the WordPress admin, while
	 * at the same time allowing for the flexibility of support volunteers giving direct links to users who
	 * may otherwise spend needless time looking for the correct links.
	 *
	 * @param string $action The action being performed and confirmed.
	 * @param array<int, string> $assets And array of the plugins, or themes, the action is applied to.
	 *
	 * @return boolean
	 */
	private function validate_action_nonce( string $action, array $assets ) : bool {
		$nonce_action = sprintf(
			'%s-%s',
			$action,
			md5( implode( ',', $assets ) )
		);

		$nonce = ( isset( $_GET['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_GET['_wpnonce'] ) ) : false );

		// Validate nonce.
		if ( false === $nonce || ! \wp_verify_nonce( $nonce, $nonce_action ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Generate a predetermined formatted nonce value for the actions we wish to perform.
	 *
	 * @param string $action The action being performed and confirmed.
	 * @param array<int, string> $assets An array of the plugins, or themes, the action is applied to.
	 *
	 * @return string
	 */
	private function prepare_action_nonce( string $action, array $assets ) : string {
		$nonce_action = sprintf(
			'%s-%s',
			$action,
			md5( implode( ',', $assets ) )
		);

		return \wp_create_nonce( $nonce_action );
	}

	/**
	 * Catch query arguments.
	 *
	 * When in Troubleshooting Mode, look for various GET variables that trigger
	 * various plugin actions.
	 *
	 * @return void
	 */
	function health_check_troubleshoot_get_captures() {
		// Disable Troubleshooting Mode.
		if ( isset( $_GET['health-check-disable-troubleshooting'] ) ) {
			// Validate the cache or return early.
			if ( ! $this->validate_action_nonce( 'health-check-disable-troubleshooting', array() ) ) {
				$this->show_nonce_validator   = true;
				$this->nonce_validator_fields = array(
					'_wpnonce'                             => $this->prepare_action_nonce(
						'health-check-disable-troubleshooting',
						array()
					),
					'health-check-disable-troubleshooting' => 1,
				);

				$this->nonce_validator_details = sprintf(
					'<p>%s</p>',
					\__( 'You were attempting to <strong>disable Troubleshooting Mode</strong>.', 'troubleshooting' )
				);

				return;
			}

			$this->disable_troubleshooting_mode();

			\wp_redirect( \remove_query_arg( $this->available_query_args ) );
			die();
		}

		// Dismiss notices.
		if ( isset( $_GET['health-check-dismiss-notices'] ) && $this->is_troubleshooting() && \is_admin() ) {
			// Validate the cache or return early.
			if ( ! $this->validate_action_nonce( 'health-check-dismiss-notices', array() ) ) {
				$this->show_nonce_validator   = true;
				$this->nonce_validator_fields = array(
					'_wpnonce'                     => $this->prepare_action_nonce(
						'health-check-dismiss-notices',
						array()
					),
					'health-check-dismiss-notices' => 1,
				);

				$this->nonce_validator_details = sprintf(
					'<p>%s</p>',
					\__( 'You were attempting to <strong>dismiss all notices</strong>.', 'troubleshooting' )
				);

				return;
			}

			\update_option( 'health-check-dashboard-notices', array() );

			\wp_redirect( \admin_url() );
			die();
		}

		// Enable an individual plugin.
		if ( isset( $_GET['health-check-troubleshoot-enable-plugin'] ) ) {
			// Validate the cache or return early.
			if ( ! $this->validate_action_nonce( 'health-check-troubleshoot-enable-plugin', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) ) ) ) {
				$this->show_nonce_validator   = true;
				$this->nonce_validator_fields = array(
					'_wpnonce' => $this->prepare_action_nonce(
						'health-check-troubleshoot-enable-plugin',
						array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) )
					),
					'health-check-troubleshoot-enable-plugin' => implode( ',', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) ) ),
				);

				$this->nonce_validator_details = sprintf(
					'<p>%s</p>',
					sprintf(
						// translators: The plugin being affected.
						\__( 'You were attempting to <strong>enable</strong> the %s plugin while troubleshooting.', 'troubleshooting' ),
						sprintf(
							'<strong>%s</strong>',
							\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) )
						)
					)
				);

				return;
			}

			$old_allowed_plugins = $this->allowed_plugins;

			$new_allowed_plugin_slug = (string) \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) );

			$this->allowed_plugins[ $new_allowed_plugin_slug ] = $new_allowed_plugin_slug;

			\update_option( 'health-check-allowed-plugins', $this->allowed_plugins );

			if ( isset( $_GET['health-check-plugin-force-enable'] ) ) {
				$this->add_dashboard_notice(
					sprintf(
						// translators: %s: The plugin slug.
						'The %s plugin was forcefully enabled.',
						esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) )
					),
					'info'
				);
			}

			if ( ! $this->test_site_state() && ! isset( $_GET['health-check-plugin-force-enable'] ) ) {
				$this->allowed_plugins = $old_allowed_plugins;
				\update_option( 'health-check-allowed-plugins', $old_allowed_plugins );

				$notice = sprintf(
					// Translators: %1$s: The link-button markup to force enable the plugin. %2$s: The force-enable link markup.
					\__( 'When enabling the plugin, %1$s, a site failure occurred. Because of this the change was automatically reverted. %2$s', 'troubleshooting' ),
					\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) ),
					sprintf(
						'<a href="%s" aria-label="%s">%s</a>',
						\esc_url(
							\add_query_arg(
								array(
									'health-check-troubleshoot-enable-plugin' => \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ),
									'health-check-plugin-force-enable' => 'true',
									'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-enable-plugin', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) ) ),
								),
								$this->get_clean_url()
							)
						),
						\esc_attr(
							sprintf(
								// translators: %s: Plugin name.
								\__( 'Force-enable the plugin, %s, even though the loopback checks failed.', 'troubleshooting' ),
								\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-enable-plugin'] ) ) )
							)
						),
						\__( 'Enable anyway', 'troubleshooting' )
					)
				);

				$this->add_dashboard_notice(
					$notice,
					'warning'
				);
			}

			\wp_redirect( \remove_query_arg( $this->available_query_args ) );
			die();
		}

		// Disable an individual plugin.
		if ( isset( $_GET['health-check-troubleshoot-disable-plugin'] ) ) {
			// Validate the cache or return early.
			if ( ! $this->validate_action_nonce( 'health-check-troubleshoot-disable-plugin', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) ) ) ) {
				$this->show_nonce_validator   = true;
				$this->nonce_validator_fields = array(
					'_wpnonce' => $this->prepare_action_nonce(
						'health-check-troubleshoot-disable-plugin',
						array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) )
					),
					'health-check-troubleshoot-disable-plugin' => implode( ',', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) ) ),
				);

				$this->nonce_validator_details = sprintf(
					'<p>%s</p>',
					sprintf(
						// translators: The plugin being affected.
						\__( 'You were attempting to <strong>disable</strong> the %s plugin while troubleshooting.', 'troubleshooting' ),
						sprintf(
							'<strong>%s</strong>',
							\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) )
						)
					)
				);

				return;
			}
			$old_allowed_plugins = $this->allowed_plugins;

			unset( $this->allowed_plugins[ \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) ] );

			\update_option( 'health-check-allowed-plugins', $this->allowed_plugins );

			if ( isset( $_GET['health-check-plugin-force-disable'] ) ) {
				$this->add_dashboard_notice(
					sprintf(
						// translators: %s: The plugin slug.
						'The %s plugin was forcefully disabled.',
						\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) )
					),
					'info'
				);
			}

			if ( ! $this->test_site_state() && ! isset( $_GET['health-check-plugin-force-disable'] ) ) {
				$this->allowed_plugins = $old_allowed_plugins;
				\update_option( 'health-check-allowed-plugins', $old_allowed_plugins );

				$notice = sprintf(
					// Translators: %1$s: The plugin slug that was disabled. %2$s: The force-disable link markup.
					\__( 'When disabling the plugin, %1$s, a site failure occurred. Because of this the change was automatically reverted. %2$s', 'troubleshooting' ),
					\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) ),
					sprintf(
						'<a href="%1$s" aria-label="%2$s">%3$s</a>',
						\esc_url(
							\add_query_arg(
								array(
									'health-check-troubleshoot-disable-plugin' => \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ),
									'health-check-plugin-force-disable' => 'true',
									'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-disable-plugin', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) ) ),
								),
								$this->get_clean_url()
							)
						),
						\esc_attr(
							sprintf(
								// translators: %s: Plugin name.
								\__( 'Force-disable the plugin, %s, even though the loopback checks failed.', 'troubleshooting' ),
								\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-troubleshoot-disable-plugin'] ) ) )
							)
						),
						\__( 'Disable anyway', 'troubleshooting' )
					)
				);

				$this->add_dashboard_notice(
					$notice,
					'warning'
				);
			}

			\wp_redirect( \remove_query_arg( $this->available_query_args ) );
			die();
		}

		// Change the active theme for this session.
		if ( isset( $_GET['health-check-change-active-theme'] ) ) {
			// Validate the cache or return early.
			if ( ! $this->validate_action_nonce( 'health-check-change-active-theme', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) ) ) ) {
				$this->show_nonce_validator   = true;
				$this->nonce_validator_fields = array(
					'_wpnonce'                         => $this->prepare_action_nonce(
						'health-check-change-active-theme',
						array( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) )
					),
					'health-check-change-active-theme' => implode( ',', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) ) ),
				);

				$this->nonce_validator_details = sprintf(
					'<p>%s</p>',
					sprintf(
						// translators: The theme being activated.
						\__( 'You were attempting to <strong>change the active theme</strong> to %s while troubleshooting.', 'troubleshooting' ),
						sprintf(
							'<strong>%s</strong>',
							\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) )
						)
					)
				);

				return;
			}

			$old_theme = \get_option( 'health-check-current-theme' );

			\update_option( 'health-check-current-theme', \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) );

			if ( isset( $_GET['health-check-theme-force-switch'] ) ) {
				$this->add_dashboard_notice(
					sprintf(
						// translators: %s: The theme slug.
						'The theme was forcefully switched to %s.',
						\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) )
					),
					'info'
				);
			}

			if ( ! $this->test_site_state() && ! isset( $_GET['health-check-theme-force-switch'] ) ) {
				\update_option( 'health-check-current-theme', $old_theme );

				$notice = sprintf(
					// Translators: %1$s: The theme slug that was switched to.. %2$s: The force-enable link markup.
					\__( 'When switching the active theme to %1$s, a site failure occurred. Because of this we reverted the theme to the one you used previously. %2$s', 'troubleshooting' ),
					\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) ),
					sprintf(
						'<a href="%s" aria-label="%s">%s</a>',
						\esc_url(
							\add_query_arg(
								array(
									'health-check-change-active-theme' => \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ),
									'health-check-theme-force-switch' => 'true',
									'_wpnonce' => $this->prepare_action_nonce( 'health-check-change-active-theme', array( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) ) ),
								),
								$this->get_clean_url()
							)
						),
						\esc_attr(
							sprintf(
								// translators: %s: Plugin name.
								\__( 'Force-switch to the %s theme, even though the loopback checks failed.', 'troubleshooting' ),
								\esc_html( \sanitize_text_field( \wp_unslash( $_GET['health-check-change-active-theme'] ) ) )
							)
						),
						\__( 'Switch anyway', 'troubleshooting' )
					)
				);

				$this->add_dashboard_notice(
					$notice,
					'warning'
				);
			}

			\wp_redirect( \remove_query_arg( $this->available_query_args ) );
			die();
		}
	}

	private function add_dashboard_notice( string $message, string $severity = 'notice' ) : void {
		$notices = \get_option( 'health-check-dashboard-notices', array() );

		$notices[] = array(
			'severity' => $severity,
			'message'  => $message,
			'time'     => gmdate( 'Y-m-d H:i' ),
		);

		\update_option( 'health-check-dashboard-notices', $notices );
	}

	/**
	 * Extend the admin bar.
	 *
	 * When in Troubleshooting Mode, introduce a new element to the admin bar to show
	 * enabled and disabled plugins (if conditions are met), switch between themes
	 * and disable Troubleshooting Mode altogether.
	 *
	 * @param \WP_Admin_Bar $wp_menu
	 *
	 * @return void
	 */
	function health_check_troubleshoot_menu_bar( $wp_menu ) {
		// We need some admin functions to make this a better user experience, so include that file.
		if ( ! \is_admin() ) {
			require_once \trailingslashit( ABSPATH ) . 'wp-admin/includes/plugin.php';
		}

		// Make sure the updater tools are available since WordPress 5.5.0 auto-updates were introduced.
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			require_once \trailingslashit( ABSPATH ) . 'wp-admin/includes/update.php';
		}

		// Ensure the theme functions are available to us on every page.
		include_once \trailingslashit( ABSPATH ) . 'wp-admin/includes/theme.php';

		// Add top-level menu item.
		$wp_menu->add_menu(
			array(
				'id'    => 'health-check',
				'title' => \esc_html__( 'Troubleshooting Mode', 'troubleshooting' ),
				'href'  => \admin_url( '/' ),
			)
		);

		// Add a link to manage plugins if there are more than 20 set to be active.
		if ( count( $this->active_plugins ) > 20 ) {
			$wp_menu->add_node(
				array(
					'id'     => 'health-check-plugins',
					'title'  => \esc_html__( 'Manage active plugins', 'troubleshooting' ),
					'parent' => 'health-check',
					'href'   => \admin_url( 'plugins.php' ),
				)
			);
		} else {
			$wp_menu->add_node(
				array(
					'id'     => 'health-check-plugins',
					'title'  => \esc_html__( 'Plugins', 'troubleshooting' ),
					'parent' => 'health-check',
					'href'   => \admin_url( 'plugins.php' ),
				)
			);

			$wp_menu->add_group(
				array(
					'id'     => 'health-check-plugins-enabled',
					'parent' => 'health-check-plugins',
				)
			);
			$wp_menu->add_group(
				array(
					'id'     => 'health-check-plugins-disabled',
					'parent' => 'health-check-plugins',
				)
			);

			foreach ( $this->active_plugins as $single_plugin ) {
				$plugin_slug = explode( '/', $single_plugin );
				$plugin_slug = $plugin_slug[0];

				$plugin_data = \get_plugin_data( \trailingslashit( WP_PLUGIN_DIR ) . $single_plugin );

				$enabled = true;

				if ( in_array( $plugin_slug, $this->allowed_plugins, true ) ) {
					$label = sprintf(
					// Translators: %s: Plugin slug.
						\esc_html__( 'Disable %s', 'troubleshooting' ),
						sprintf(
							'<strong>%s</strong>',
							$plugin_data['Name']
						)
					);
					$url = \add_query_arg(
						array(
							'health-check-troubleshoot-disable-plugin' => $plugin_slug,
							'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-disable-plugin', array( $plugin_slug ) ),
						),
						$this->get_clean_url()
					);
				} else {
					$enabled = false;
					$label   = sprintf(
						// Translators: %s: Plugin slug.
						\esc_html__( 'Enable %s', 'troubleshooting' ),
						sprintf(
							'<strong>%s</strong>',
							$plugin_data['Name']
						)
					);
					$url = \add_query_arg(
						array(
							'health-check-troubleshoot-enable-plugin' => $plugin_slug,
							'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-enable-plugin', array( $plugin_slug ) ),
						),
						$this->get_clean_url()
					);
				}

				$wp_menu->add_node(
					array(
						'id'     => sprintf(
							'health-check-plugin-%s',
							$plugin_slug
						),
						'title'  => $label,
						'parent' => ( $enabled ? 'health-check-plugins-enabled' : 'health-check-plugins-disabled' ),
						'href'   => $url,
					)
				);
			}
		}

		$wp_menu->add_node(
			array(
				'id'     => 'health-check-theme',
				'title'  => \esc_html__( 'Themes', 'troubleshooting' ),
				'parent' => 'health-check',
				'href'   => \admin_url( 'themes.php' ),
			)
		);

		$themes = \wp_prepare_themes_for_js();

		foreach ( $themes as $theme ) {
			$node = array(
				'id'     => sprintf(
					'health-check-theme-%s',
					\sanitize_title( $theme['id'] )
				),
				'title'  => sprintf(
					'%s %s',
					( $theme['active'] ? \esc_html_x( 'Active:', 'Prefix for the active theme in troubleshooting mode', 'troubleshooting' ) : \esc_html_x( 'Switch to', 'Prefix for inactive themes in troubleshooting mode', 'troubleshooting' ) ),
					$theme['name']
				),
				'parent' => 'health-check-theme',
			);

			if ( ! $theme['active'] ) {
				$node['href'] = \add_query_arg(
					array(
						'health-check-change-active-theme' => $theme['id'],
						'_wpnonce'                         => $this->prepare_action_nonce( 'health-check-change-active-theme', array( $theme['id'] ) ),
					),
					$this->get_clean_url()
				);
			}

			$wp_menu->add_node( $node );
		}

		// Add a link to disable Troubleshooting Mode.
		$wp_menu->add_node(
			array(
				'id'     => 'health-check-disable',
				'title'  => \esc_html__( 'Disable Troubleshooting Mode', 'troubleshooting' ),
				'parent' => 'health-check',
				'href'   => \add_query_arg(
					array(
						'health-check-disable-troubleshooting' => true,
						'_wpnonce' => $this->prepare_action_nonce( 'health-check-disable-troubleshooting', array() ),
					),
					$this->get_clean_url()
				),
			)
		);
	}

	public function test_site_state() : bool {

		// Make sure the Health_Check_Loopback class is available to us, in case the primary plugin is disabled.
		if ( ! method_exists( 'SiteHealth\Troubleshooting\Loopback', 'can_perform_loopback' ) ) {
			$plugin_file = \trailingslashit( WP_PLUGIN_DIR ) . 'troubleshooting/Troubleshooting/class-loopback.php';

			// Make sure the file exists, in case someone deleted the plugin manually, we don't want any errors.
			if ( ! file_exists( $plugin_file ) ) {

				// If the plugin files are inaccessible, we can't guarantee for the state of the site, so the default is a bad response.
				return false;
			}

			require_once $plugin_file;
		}

		$loopback_state = Loopback::can_perform_loopback();

		if ( 'good' !== $loopback_state['status'] ) {
			return false;
		}

		return true;
	}

	public function dashboard_widget_scripts() : void {
		// Check that it's the dashboard page, we don't want to disturb any other pages.
		$screen = \get_current_screen();
		if ( 'dashboard' !== $screen->id && 'plugins' !== $screen->id ) {
			return;
		}
	}

	public function display_dashboard_widget() : void {
		// Check that it's the dashboard page, we don't want to disturb any other pages.
		$screen = \get_current_screen();
		if ( 'dashboard' !== $screen->id && 'plugins' !== $screen->id ) {
			return;
		}

		$notices = \get_option( 'health-check-dashboard-notices', array() );

		$active_plugins   = array();
		$inactive_plugins = array();

		$themes = \wp_prepare_themes_for_js();
		?>
		<div class="wrap">
			<div id="health-check-dashboard-widget">
				<div class="welcome-panel-content health-check-column">
					<h2>
						<?php
						printf(
							// translators: %s: The running status of Troubleshooting Mode.
							\esc_html__( 'Troubleshooting Mode - %s', 'troubleshooting' ),
							sprintf(
								'<span class="green">%s</span>',
								\esc_html__( 'enabled', 'troubleshooting' )
							)
						);
						?>
					</h2>

					<?php
					printf(
						'<a href="%s" class="button button-primary">%s</a>',
						\esc_url(
							\add_query_arg(
								array(
									'health-check-disable-troubleshooting' => true,
									'_wpnonce' => $this->prepare_action_nonce( 'health-check-disable-troubleshooting', array() ),
								),
								$this->get_clean_url()
							)
						),
						\esc_html__( 'Disable Troubleshooting Mode', 'troubleshooting' )
					);
					?>

					<div class="about-description">
						<p>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- The string contains expected markup, and does not allow user input as part of its string.
							\_e( 'Your site is currently in Troubleshooting Mode. This has <strong>no effect on your site visitors</strong>, they will continue to view your site as usual, but for you it will look as if you had just installed WordPress for the first time.', 'troubleshooting' );
							?>
						</p>

						<p>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- The string contains expected markup, and does not allow user input as part of its string.
							\_e( 'Here you can enable individual plugins or themes, helping you to find out what might be causing strange behaviors on your site. Do note that <strong>any changes you make to settings will be kept</strong> when you disable Troubleshooting Mode.', 'troubleshooting' );
							?>
						</p>

						<p>
							<?php \esc_html_e( 'The Health Check plugin will attempt to disable cache solutions on your site, but if you are using a custom caching solution, you may need to disable it manually when troubleshooting.', 'troubleshooting' ); ?>
						</p>

						<p>
							<?php
							printf(
								// translators: %s: The User Switcher plugin link.
								\esc_html__(
									'If you wish to troubleshoot as another user, or as an anonymous site visitor, the %s allows for all of this while also being compatible with Troubleshooting Mode.',
									'troubleshooting'
								),
								sprintf(
									// translators: 1: The User Switcher plugin link. 2: The User Switcher plugin name.
									'<a href="%s">%s</a>',
									\esc_url( \__( 'https://wordpress.org/plugins/user-switching/', 'troubleshooting' ) ),
									\esc_html__( 'User Switcher plugin', 'troubleshooting' )
								)
							);
							?>
						</p>
					</div>
				</div>

				<div class="health-check-column">
					<dl role="presentation" class="health-check-accordion">
						<dt role="heading" aria-level="3">
							<button aria-expanded="false" class="health-check-accordion-trigger" aria-controls="health-check-accordion-block-plugins" id="health-check-accordion-heading-plugins" type="button">
								<span class="title">
									<?php
									printf(
										// translators: %d: The amount of available plugins.
										\esc_html__( 'Available plugins (%d)', 'troubleshooting' ),
										count( $this->active_plugins )
									);
									?>
								</span>
								<span class="icon"></span>
							</button>
						</dt>
						<dd id="health-check-accordion-block-plugins" role="region" aria-labelledby="health-check-accordion-heading-plugins" class="health-check-accordion-panel" hidden="hidden">
							<ul id="health-check-plugins" role="list">
								<?php
								$has_toggle = false;

								foreach ( $this->active_plugins as $count => $single_plugin ) {
									$plugin_slug = explode( '/', $single_plugin );
									$plugin_slug = $plugin_slug[0];

									$plugin_is_visible = true;
									if ( $count >= 5 ) {
										$plugin_is_visible = false;
									}

									$plugin_data = \get_plugin_data( \trailingslashit( WP_PLUGIN_DIR ) . $single_plugin );

									$actions = array();

									if ( in_array( $plugin_slug, $this->allowed_plugins, true ) ) {
										$actions[] = sprintf(
											'<a href="%s" aria-label="%s">%s</a>',
											\esc_url(
												\add_query_arg(
													array(
														'health-check-troubleshoot-disable-plugin' => $plugin_slug,
														'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-disable-plugin', array( $plugin_slug ) ),
													),
													$this->get_clean_url()
												)
											),
											esc_attr(
												sprintf(
													// translators: %s: Plugin name.
													\__( 'Disable the plugin, %s, while troubleshooting.', 'troubleshooting' ),
													\esc_html( $plugin_data['Name'] )
												)
											),
											\esc_html__( 'Disable', 'troubleshooting' )
										);
									} else {
										$actions[] = sprintf(
											'<a href="%s" aria-label="%s">%s</a>',
											\esc_url(
												\add_query_arg(
													array(
														'health-check-troubleshoot-enable-plugin' => $plugin_slug,
														'_wpnonce' => $this->prepare_action_nonce( 'health-check-troubleshoot-enable-plugin', array( $plugin_slug ) ),
													),
													$this->get_clean_url()
												)
											),
											\esc_attr(
												sprintf(
													// translators: %s: Plugin name.
													\__( 'Enable the plugin, %s, while troubleshooting.', 'troubleshooting' ),
													\esc_html( $plugin_data['Name'] )
												)
											),
											\esc_html__( 'Enable', 'troubleshooting' )
										);
									}

									if ( ! $plugin_is_visible && ! $has_toggle ) {
										$has_toggle = true;

										printf(
											'<li><button type="button" class="show-remaining button button-link">%s</button></li>',
											sprintf(
												\esc_html(
													// translators: %d: Amount of hidden plugins.
													\_n(
														'Show %d remaining plugin',
														'Show %d remaining plugins',
														( is_countable( $this->active_plugins ) ? ( count( $this->active_plugins ) - 5 ) : 0 ),
														'troubleshooting'
													)
												),
												\esc_html( ( is_countable( $this->active_plugins ) ? ( count( $this->active_plugins ) - 5 ) : 0 ) )
											)
										);
									}

									printf(
										'<li class="%s">%s - %s</li>',
										( ! $plugin_is_visible ? 'toggle-visibility hidden' : '' ),
										\esc_html( $plugin_data['Name'] ),
										implode( ' | ', $actions ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output contains markup, and is escaped during generation.
									);
								}
								?>
							</ul>
						</dd>

						<dt role="heading" aria-level="3">
							<button aria-expanded="false" class="health-check-accordion-trigger" aria-controls="health-check-accordion-block-themes" id="health-check-accordion-heading-themes" type="button">
								<span class="title">
									<?php
									printf(
										// translators: %d: The amount of available themes.
										\esc_html__( 'Available themes (%d)', 'troubleshooting' ),
										count( $themes )
									);
									?>
								</span>
								<span class="icon"></span>
							</button>
						</dt>
						<dd id="health-check-accordion-block-themes" role="region" aria-labelledby="health-check-accordion-heading-themes" class="health-check-accordion-panel" hidden="hidden">
							<ul id="health-check-themes" role="list">
								<?php
								$has_toggle = false;

								foreach ( $themes as $count => $theme ) {
									$theme_is_visible = true;
									if ( $count >= 5 ) {
										$theme_is_visible = false;
									}

									$actions = sprintf(
										'<a href="%s" aria-label="%s">%s</a>',
										\esc_url(
											\add_query_arg(
												array(
													'health-check-change-active-theme' => $theme['id'],
													'_wpnonce'                         => $this->prepare_action_nonce( 'health-check-change-active-theme', array( $theme['id'] ) ),
												),
												$this->get_clean_url()
											)
										),
										\esc_attr(
											sprintf(
												// translators: %s: Theme name.
												\__( 'Switch the active theme to %s', 'troubleshooting' ),
												\esc_html( $theme['name'] )
											)
										),
										\esc_html__( 'Switch to this theme', 'troubleshooting' )
									);

									$theme_label = sprintf(
										'%s %s',
										// translators: Prefix for the active theme in a listing.
										( $theme['active'] ? \esc_html__( 'Active:', 'troubleshooting' ) : '' ),
										\esc_html( $theme['name'] )
									);

									if ( ! $theme['active'] ) {
										$theme_label .= ' - ' . $actions;
									}

									if ( ! $theme_is_visible && ! $has_toggle ) {
										$has_toggle = true;

										printf(
											'<li><button type="button" class="show-remaining button button-link">%s</button></li>',
											sprintf(
												esc_html(
													// translators: %d: Amount of hidden themes.
													_n(
														'Show %d remaining theme',
														'Show %d remaining themes',
														( is_countable( $themes ) ? ( count( $themes ) - 5 ) : 0 ),
														'troubleshooting'
													)
												),
												\esc_html( ( is_countable( $themes ) ? ( count( $themes ) - 5 ) : 0 ) )
											)
										);
									}

									printf(
										'<li class="%s">%s</li>',
										( ! $theme_is_visible ? 'toggle-visibility hidden' : '' ),
										$theme_label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output contains markup, and is escaped during generation.
									);
								}
								?>
							</ul>
						</dd>

						<dt role="heading" aria-level="3">
							<button aria-expanded="true" class="health-check-accordion-trigger" aria-controls="health-check-accordion-block-notices" id="health-check-accordion-heading-notices" type="button">
								<span class="title">
									<?php
									printf(
										// translators: %d: The amount of notices that are visible.
										\esc_html__( 'Notices (%d)', 'troubleshooting' ),
										( is_countable( $notices ) ? count( $notices ) : 0 )
									);
									?>
								</span>
								<span class="icon"></span>
							</button>
						</dt>
						<dd id="health-check-accordion-block-notices" role="region" aria-labelledby="health-check-accordion-heading-notices" class="health-check-accordion-panel">
							<?php if ( empty( $notices ) && 'plugins' !== $screen->id ) : ?>
								<div class="no-notices">
									<p>
										<?php \esc_html_e( 'There are no notices to show.', 'troubleshooting' ); ?>
									</p>
								</div>
							<?php endif; ?>

							<?php if ( 'plugins' === $screen->id ) : ?>
								<div class="notice notice-warning inline">
									<p>
										<?php \esc_html_e( 'Plugin actions, such as activating and deactivating, are not available while in Troubleshooting Mode.', 'troubleshooting' ); ?>
									</p>
								</div>
							<?php endif; ?>

							<?php
							foreach ( $notices as $notice ) {
								printf(
									'<div class="notice notice-%s inline"><p>%s</p></div>',
									\esc_attr( $notice['severity'] ),
									\wp_kses(
										$notice['message'],
										array(
											'a' => array(
												'class' => true,
												'href'  => true,
												'aria-label' => true,
											),
										)
									)
								);
							}
							?>

							<?php
							if ( ! empty( $notices ) ) {
								printf(
									'<div class="dismiss-notices"><a href="%s" class="">%s</a></div>',
									\esc_url(
										\add_query_arg(
											array(
												'health-check-dismiss-notices' => true,
												'_wpnonce' => $this->prepare_action_nonce( 'health-check-dismiss-notices', array() ),
											),
											$this->get_clean_url()
										)
									),
									\esc_html__( 'Dismiss notices', 'troubleshooting' )
								);
							}
							?>
						</dd>
					</dl>
				</div>
			</div>
		</div>
		<?php
	}

	public function nonce_confirmation_prompt() : void {
		// If the nonce-validator is disabled, do not show anything.
		if ( ! $this->show_nonce_validator ) {
			return;
		}

		$kses_allowed_markup = array(
			'p'      => array( 'class' ),
			'ul'     => array( 'class' ),
			'li'     => array( 'class' ),
			'strong' => array(),
			'em'     => array(),
		);

		$form_fields = array();

		foreach ( $this->nonce_validator_fields as $field => $value ) {
			$form_fields[] = sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				\esc_attr( (string) $field ),
				\esc_attr( (string) $value )
			);
		}

		echo '
<div id="health-check-nonce-validator" class="health-check-troubleshooting">
	<div class="health-check-nonce-validator-wrapper">
		<form action="" method="get" class="health-check-nonce-validator-inner">
			' . implode( "\n", $form_fields ) /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output contains form fields, and is escaped during generation. */ . '

			<h2>
				' . \esc_html__( 'Troubleshooting Mode - Security check', 'troubleshooting' ) . '
			</h2>

			<p>
				' . \esc_html__( 'You were attempting to perform an action that requires a security token, which was either not present in your request, or was considered invalid. Please verify that the following action is intentional, or feel free to cancel the action and nothing will change.', 'troubleshooting' ) . '
			</p>

			' . \wp_kses( $this->nonce_validator_details, $kses_allowed_markup ) . '

			<hr />

			<button type="submit" class="button button-primary">' . \esc_html__( 'Confirm', 'troubleshooting' ) . '</button>
			<button type="button" class="button button-secondary" onclick="document.getElementById(\'health-check-nonce-validator\').remove()">' . \esc_html__( 'Cancel', 'troubleshooting' ) . '</button>
		</form>
	</div>
</div>
';
	}
}

new MustUse();
