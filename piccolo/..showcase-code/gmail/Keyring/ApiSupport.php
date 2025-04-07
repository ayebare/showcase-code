<?php

namespace Piccolo\Gmail\Keyring;

class APISupport {

	/**
	 * Minimum version of Keyring required.
	 *
	 * @since 0.0.1
	 * @var string
	 */
	const MIN_KEYRING_VERSION = '1.4';

	/**
	 * Gmail authentication service from Keyring
	 *
	 * @var string
	 */
	public $service = false;

	/**
	 * API endpoint used to request a token.
	 *
	 * @var string
	 */
	private $token = false;

	/**
	 * Shows if Gmail's keyring service is fully setup.
	 *
	 * Ensure its true before running any Keyring requests.
	 *
	 * @var string True when a connection is present false otherwise.
	 */
	public $is_connected = false;

	/**
	 * Options for the plugin
	 *
	 * @var string
	 */

	private $options = array();

	/**
	 * Large Options for Gmail
	 *
	 * @var string
	 */

	private $large_options = array();

	/**
	 * Entry point for the Keyring API Support class.
	 *
	 * Check if keyring plugin is installed before initializing Keyring related function.
	 * Show notice in the admin if the Piccolo plugin is active but Keyring plugin is not.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init() {
		// If plugin active and keyring is not installed, show notice and bail.
		if ( ! $this->keyring_is_active() ) {
			if ( current_user_can( 'install_plugins' ) ) {
				add_action( 'admin_notices', array( $this, 'require_keyring_notice' ) );
			}

			return;
		}
		$this->init_keyring();
		add_action( 'keyring_connection_deleted', array( $this, 'delete_piccolo_token_id' ), 10, 2 );
	}

	/**
	 * Check if keyring plugin is active
	 *
	 * The installed Keyring plugin version should me greater than the minimum allowed version.
	 *
	 * @since 0.0.1
	 *
	 * @return bool true is the required version is active false otherwise.
	 */
	public function keyring_is_active() {
		return defined( 'KEYRING__VERSION' ) && version_compare( KEYRING__VERSION, static::MIN_KEYRING_VERSION );
	}

	/**
	 * Check if WordPress Large Options functions are available.
	 *
	 * WordPress Large Options is currently added through composer autoload.
	 *
	 * @since 0.0.1
	 *
	 * @return bool true if available, false otherwise.
	 */
	public function wp_large_options_enabled() {
		return defined( 'WLO_POST_TYPE' ) && defined( 'WLO_META_KEY' );
	}

	/**
	 * Initialize the Keyring Gmail service if a token is present.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_keyring() {
		$this->options = get_option( PICCOLO_GMAIL_OPTIONS, array() );
		$this->token   = $this->get_token();
		if ( $this->token ) {
			$this->setup_keyring_service();
		}
	}

	/**
	 * Get the token to Authenticate connections to Gmail using Keyring.
	 *
	 * @since 0.0.1
	 *
	 * @return string $token
	 */
	public function get_token() {
		// If we have a token set already, then load some details for it.
		if ( $this->get_option( 'token_id' ) ) {
			$token = \Keyring::get_token_store()->get_token(
				array(
					'service' => PICCOLO_GMAIL_KEYRING_SLUG,
					'id'      => $this->get_option( 'token_id' ),
				)
			);

			return $token;
		}

		return false;
	}

	/**
	 * Get one of the options specific to this plugin from the array in which we retain them.
	 *
	 * @param string $name The name of the option you'd like to get.
	 * @param mixed  $default What to return if the option requested isn't available, defaults to false.
	 *
	 * @since 0.0.1
	 *
	 * @return mixed
	 */
	public function get_option( $name, $default = false ) {
		if ( isset( $this->options[ $name ] ) ) {
			return $this->options[ $name ];
		}

		return $default;
	}

	/**
	 * Set an option within the array maintained for this plugin. Optionally set multiple options
	 * by passing an array of named pairs in. Passing null as the name will reset all options.
	 * If you want to store something big, then use core's update_option() or similar so that it's
	 * outside of this array.
	 *
	 * @param mixed $name String for a name/value pair, array for a collection of options, or null to reset everything.
	 * @param mixed $val The value to set this option to.
	 *
	 * @return bool true if the option as set successfully, false otherwise.
	 */
	public function set_option( $name, $val = null ) {
		if ( is_array( $name ) ) {
			$this->options = array_merge( (array) $this->options, $name );
		} elseif ( is_null( $name ) && is_null( $val ) ) { // $name = null to reset all options
			$this->options = array();
		} elseif ( is_null( $val ) && isset( $this->options[ $name ] ) ) {
			unset( $this->options[ $name ] );
		} else {
			$this->options[ $name ] = $val;
		}

		return update_option( PICCOLO_GMAIL_OPTIONS, $this->options );
	}

	/**
	 * Get one of the options specific to this plugin from the large options array.
	 *
	 * @param string $name The name of the option you'd like to get.
	 * @param mixed  $default What to return if the option requested isn't available, defaults to false.
	 *
	 * @since 0.0.1
	 *
	 * @return mixed
	 */
	public function get_large_option( $name, $default = false ) {
		if ( ! $this->wp_large_options_enabled() ) {
			return $default;
		}
		// The Large options variable is initialized on first demand.
		if ( empty( $this->large_options ) ) {
			$options             = wlo_get_option( PICCOLO_GMAIL_OPTIONS, $default );
			$this->large_options = $options;
		}

		if ( isset( $this->large_options[ $name ] ) ) {
			return $this->large_options[ $name ];
		}

		return $default;
	}

	/**
	 * Set an option within the array maintained for this plugin. Optionally set multiple options
	 * by passing an array of named pairs in. Passing null as the name will reset all options.
	 * If you want to store something big, then use core's update_option() or similar so that it's
	 * outside of this array.
	 *
	 * @param mixed $name String for a name/value pair, array for a collection of options, or null to reset everything.
	 * @param mixed $val The value to set this option to.
	 *
	 * @return bool
	 */
	public function set_large_option( $name, $val = null ) {
		if ( ! $this->wp_large_options_enabled() ) {
			return false;
		}

		// The Large options variable is initialized on first demand.
		if ( empty( $this->large_options ) ) {
			$options             = wlo_get_option( $name, array() );
			$this->large_options = $options;
		}

		if ( is_array( $name ) ) {
			$this->large_options = array_merge( (array) $this->large_options, $name );
		} elseif ( is_null( $name ) && is_null( $val ) ) { // $name = null to reset all large options.
			$this->large_options = array();
		} elseif ( is_null( $val ) && isset( $this->large_options[ $name ] ) ) {
			unset( $this->large_options[ $name ] );
		} else {
			$this->large_options[ $name ] = $val;
		}

		return wlo_update_option( PICCOLO_GMAIL_OPTIONS, $this->large_options );
	}

	public function setup_keyring_service() {
		$this->service = call_user_func( array( PICCOLO_GMAIL_KEYRING_SERVICE, 'init' ) );
		$this->service->set_token( $this->token );
		$this->is_connected = $this->is_connected();
	}

	/**
	 * Checks if keyring Keyring service is available and had a token connected.
	 *
	 * @since 0.0.1
	 *
	 * @return bool true if connected false otherwise.
	 */
	public function is_connected() {
		return $this->service && $this->service->get_token();
	}

	/**
	 * Display a notice in the admin when the keyring plugin is not installed or active.
	 *
	 * @action  admin_notices
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function require_keyring_notice() {
		global $keyring_required; // So that we only send the message once.

		if ( ( ! empty( $_SERVER['REQUEST_URI'] ) && 'update.php' === basename( $_SERVER['REQUEST_URI'] ) ) || $keyring_required ) {
			return;
		}

		echo '<div class="updated">';
		echo '<p>';
		printf(
			esc_html__( '%1$s plugin requires the %2$s plugin version > %3$s to handle authentication. Please install it by clicking the button below, or activate it if you have already installed it, then you will be able to use the importers.', 'piccolo' ),
			'<strong>Piccolo</strong>',
			'<a href="http://wordpress.org/extend/plugins/keyring/" target="_blank">Keyring</a>',
			esc_html( static::MIN_KEYRING_VERSION )
		);
		echo '</p>';
		echo '<p><a href="plugin-install.php?tab=plugin-information&plugin=keyring&from=import&TB_iframe=true&width=640&height=666" class="button-primary thickbox">' . esc_html__( 'Install Keyring', 'piccolo' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Delete the token used by Piccolo when its deleted in the Keyring plugin.
	 *
	 * @action  keyring_connection_deleted
	 *
	 * @since 0.0.1
	 *
	 * @param string $service
	 * @param array  $request
	 */
	public function delete_piccolo_token_id( $service, $request ) {
		if ( PICCOLO_GMAIL_KEYRING_SLUG !== $service ) {
			return;
		}

		if ( empty( $request['token_id'] ) ) {
			return;
		}

		$piccolo_token_id = $this->get_option( 'token_id' );
		if ( absint( $piccolo_token_id ) === absint( $request['token_id'] ) ) {
			$this->reset();
		}
	}

	/**
	 * Reset options for piccolo Gmail connections
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function reset() {
		$this->set_option( null );
	}
}
