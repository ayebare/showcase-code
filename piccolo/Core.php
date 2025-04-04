<?php

namespace Piccolo;

class Core {

	public $keyring_support;
	public $keyring_api;

	/**
	 * Enable plugin
	 *
	 * Adds hooks that allow interaction with WordPress code
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function enable() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin modules
	 *
	 * @action  init
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init() {
		$this->init_keyring_support();
		$this->init_keyring_api();
		$this->init_piccolo_endpoints();
		$this->init_admin_pages();
	}

	/**
	 * Initialize key ring support class
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_keyring_support() {
		$this->keyring_support = new Gmail\Keyring\APISupport();
		$this->keyring_support->init();
	}

	/**
	 * Initialize key api
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_keyring_api() {
		$this->keyring_api = new Gmail\Keyring\API( $this->keyring_support );
	}

	/**
	 * Initialize settings
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_admin_pages() {
		$keyring_api_screen = new Settings\KeyringApiScreen( $this->keyring_support );
		$keyring_api_screen->init();
	}

	/**
	 * Initialize Piccolo endpoints
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init_piccolo_endpoints() {
		new Gmail\Endpoints( $this->keyring_api );
	}
}
