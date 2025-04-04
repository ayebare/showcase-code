<?php

/**
 * Piccolo Configurations.
 */

define( 'PISTACHIO_PLUGIN_DIR', __DIR__ );

/**
 * Identifier for Keyring service to be used.
 *
 * @since 0.0.1
 * @var string
 */
define( 'PISTACHIO_GMAIL_KEYRING_SERVICE', 'Keyring_Service_GoogleMail' );

/**
 * Keyring service name.
 *
 * @since 0.0.1
 * @var string
 */
define( 'PISTACHIO_GMAIL_KEYRING_SLUG', 'google-mail' );

/**
 * Gmail label for imported messages.
 *
 * @since 0.0.1
 * @var string
 */
define( 'PISTACHIO_GMAIL_IMPORTED_LABEL', 'imported' );

/**
 * Option name for storing Gmail specific options in the Large Options (as post meta) as well as wp-options
 *
 * @since 0.0.1
 * @var string
 */
define( 'PISTACHIO_GMAIL_OPTIONS', 'piccolo-gmail-options' );
