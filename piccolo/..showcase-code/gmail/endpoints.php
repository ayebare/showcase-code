<?php

namespace Piccolo\Gmail;

/**
 * Piccolo Gmail Endpoints
 *
 * Gmail
 *
 * - wp-json/piccolo-api/v1/gmail/messages
 * - wp-json/piccolo-api/v1/gmail/messages/import
 */
class Endpoints {

	/**
	 * Messages Store key
	 *
	 * Used as a storage key for  messages temporarily stored in wp big options
	 *
	 * @since 0.0.1
	 * @var string
	 */
	const MESSAGES_LARGE_OPTIONS_KEY = 'fetch-messages';

	/**
	 * Query lock transient key
	 *
	 * Transient key used to prevent multiple simultaneous queries for messages.
	 *
	 * @since 0.0.1
	 * @var string
	 */
	const  QUERY_LOCK = 'piccolo_query_lock';

	public function __construct( $keyring_api ) {
		$this->namespace   = 'piccolo-api/v1';
		$this->rest_base   = 'gmail';
		$this->keyring_api = $keyring_api;
		$this->rest_api_init();
	}

	/**
	 * Register endpoint hooks
	 *
	 * Runs method to register Gmail rest routes in WordPress
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function rest_api_init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Check if the current user has manage_option WP privileges
	 *
	 * @since 0.0.1
	 * @return mixed true|WP_Error true if the user has permissions, \WP_Error if they don't
	 */
	public function manage_options_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_permissions_error_msg = esc_html__(
			'You do not have the correct user permissions to perform this action.
			Please contact your site admin if you think this is a mistake.'
		);

		return new \WP_Error( 'invalid_user_permissions', $user_permissions_error_msg, array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Register piccolo Gmail routes
	 *
	 * @action rest_api_init
	 *
	 * @since 0.0.1
	 *
	 * @return  void
	 */
	public function register_routes() {
		// GET /site/wp-json/piccolo-api/v1/gmail/messages/ - Returns messages without an imported label.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/messages/',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_gmail_messages' ),
					'permission_callback' => array( $this, 'manage_options_check' ),
				),
				// Register our schema callback.
				'schema' => array( $this, 'get_message_schema' ),
			)
		);

		/**
		 *POST /site/wp-json/piccolo-api/v1/gmail/messages/import - Ads an imported label to imported messages
		 *
		 * Message body takes a json array of message ids e.g
		 *
		 * { "messages": [ "1815907c27f19d62","18128d84ec35cc0c" ] }
		 */
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/messages/import/',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'label_imported_messages' ),
					'permission_callback' => array( $this, 'manage_options_check' ),
					'args'                => array(
						'messages' => array(
							'$schema'           => 'http://json-schema.org/draft-04/schema#',
							'required'          => true,
							'description'       => 'Message id\'s to update',
							'type'              => 'array',
							'items'             => array(
								'type' => 'string',
							),
							'validate_callback' => array( $this, 'validate_message_list' ),
							'sanitize_callback' => array( $this, 'sanitize_message_id_list' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Gets Gmail messages
	 *
	 * Callback for  GET /site/wp-json/piccolo-api/v1/gmail/messages/ endpoint
	 *
	 * @uses \Piccolo\Gmail\Endpoints::fetch_messages()
	 * @uses \Piccolo\Gmail\Endpoints::batch_fetch_messages()
	 *
	 * @since 0.0.1
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return mixed WP_REST_Response|WP_Error
	 */
	public function get_gmail_messages( $request ) {
		// Retrieve messages stored using wp large options that works as makeshift cache.
		$res = $this->get_stored_messages();

		if ( false === $res ) {
				// Prevent multiple queries at once - only a single process should trigger a query.
				// safe guard against stampedes
			if ( false !== get_transient( self::QUERY_LOCK ) ) {

				// Retry algorithms at this point should be implemented on the client side.
				return rest_ensure_response( new \WP_Error( 'server-busy', 'Server Busy try again', array( 'status' => 529 ) ) );
			}

			// Set a short lock to prevent multiple instances of the query.
			set_transient( self::QUERY_LOCK, 1, 7 );

			try {
				$msg_ids = $this->fetch_messages();

				if ( is_wp_error( $msg_ids ) ) {
					return rest_ensure_response( $msg_ids );
				}

				$ids      = wp_list_pluck( $msg_ids->messages, 'id' );
				$messages = $this->batch_fetch_messages( $ids );

				if ( is_wp_error( $messages ) ) {
					return rest_ensure_response( $messages );
				}

				foreach ( $messages as $message ) {
					$res[] = $this->rest_prepare_message( $message, $request );
				}

				if ( ! empty( $res ) ) {
					$this->store_messages( $res );
				}
			} catch ( \Throwable $t ) {
				/**
				 * Note that timeouts and memory exhaustion do not invoke this block.
				 *
				 * @todo catch them on_shutdown().
				 */

				$catch_return = new \WP_Error(
					'internal_error',
					sprintf(
						__( 'Callback for `%1$s` raised a Throwable - %2$s in %3$s on line %4$d.', 'piccolo' ),
						$request->get_route(),
						$t->getMessage(),
						$t->getFile(),
						$t->getLine()
					),
					array(
						'status' => 500,
						'route'  => $request->get_route(),
						'params' => $request->get_params(),
						'body'   => $request->get_body(),
					)
				);
			}
		}

		// Callback triggered a Throwable, indicating it failed.
		if ( isset( $catch_return ) ) {
			return rest_ensure_response( $catch_return );
		}

		return rest_ensure_response( wp_json_encode( $res ) );
	}

	/**
	 * Add a label to imported messages
	 *
	 * Callback for  //POST /site/wp-json/piccolo-api/v1/gmail/messages/import
	 *
	 * The function does the following
	 *
	 *  - Delete the cache for imported messages. This allows the next message fetch to be fresh.
	 *  - Adds a label to messages to mark them as imported so they are not pulled in on the next fetch.
	 *
	 * @uses \Piccolo\Gmail\Keyring\batch_modify_messages
	 *
	 * @since 0.0.1
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return mixed WP_REST_Response|WP_Error
	 */
	public function label_imported_messages( $request ) {
		$args         = $request->get_json_params();
		$message_list = $args['messages'];

		$label = $this->keyring_api->get_imported_messages_label_id();

		if ( empty( $label ) ) {
			return rest_ensure_response( new \WP_Error( 'invalid_param_value', sprintf( 'No label "%s" was found in Gmail', PICCOLO_GMAIL_IMPORTED_LABEL ) ) );
		}

		$params = array(
			'postbody' => wp_json_encode(
				array(
					'ids'         => $message_list,
					'addLabelIds' => array( $label ),
				)
			),
		);

		$res = $this->keyring_api->batch_modify_messages( $params );

		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( $res );
		}

		// Delete stored messages to allow a fresh fetch.
		$this->store_messages( null );

		return wp_send_json( $res );
	}

	/**
	 * Helper function to fetch messages from Gmail.
	 *
	 * @since 0.0.1
	 *
	 * @return  object|\WP_Error $messages Object of message Ids or WP_Error.
	 */
	public function fetch_messages() {
		$messages = $this->keyring_api->fetch_messages();
		return $messages;
	}

	/**
	 * Helper function to batch fetch messages from Gmail.
	 *
	 * @since 0.0.1
	 *
	 * @param array $messages message ids to fetch data for.
	 *
	 * @return object|\WP_Error Object of message Ids
	 */
	public function batch_fetch_messages( $messages ) {
		return $this->keyring_api->batch_fetch_messages( $messages );
	}


	/**
	 * Matches the message data to the schema we want.
	 *
	 * The message is processed to return:
	 * - thread_id
	 * - label_ids
	 * - snippet
	 * - sender_name
	 * - date
	 * - email
	 * - subject
	 * - body
	 *
	 * @since 0.0.1
	 *
	 * @param object           $message the message data.
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response $data the message data.
	 */
	public function rest_prepare_message( $message, $request ) {
		$message_data = array();

		$schema = $this->get_message_schema();

		// We are also renaming the fields to more understandable names.
		if ( isset( $schema['properties']['id'] ) ) {
			$message_data['id'] = (string) $message->id;
		}

		if ( isset( $schema['properties']['thread_id'] ) ) {
			$message_data['thread_id'] = (string) $message->threadId;
		}

		if ( isset( $schema['properties']['label_ids'] ) ) {
			$message_data['label_ids'] = (array) $message->labelIds;
		}

		if ( isset( $schema['properties']['snippet'] ) ) {
			$message_data['snippet'] = (string) $message->snippet;
		}
		if ( isset( $schema['properties']['header_meta'] ) ) {
			$message_data['header_meta'] = $this->get_message_meta( $message->payload->headers );
		}
		if ( isset( $schema['properties']['body'] ) ) {
			$message_data['body'] = $this->get_message_body( $message->payload );
		}

		return rest_ensure_response( $message_data );
	}


	/**
	 * Get the message's metadata i.e name, date, from, subject
	 *
	 * @since 0.0.1
	 *
	 * @param array $headers message headers.
	 *
	 * @return array $meta Array of the message's meta data.
	 */
	public function get_message_meta( $headers ) {
		$meta = array();

		foreach ( $headers as $item ) {
			if ( in_array( $item->name, array( 'Reply-To', 'Date', 'From', 'Subject' ), true ) ) {
				$meta[ strtolower( str_replace( '-', '_', $item->name ) ) ] = $item->value;
			}
		}

		return $meta;
	}

	/**
	 * Get the message's body.
	 *
	 * @since 0.0.1
	 *
	 * @param object $payload Message payload.
	 *
	 * @return array $body Message body consisting of the html and text parts.
	 */
	public function get_message_body( $payload ) {
		$body = array(
			'plain' => array(),
			'html'  => array(),
		);

		if ( ! empty( $payload->body->data ) ) {
			array_push( $body['plain'], $payload->body->data );
		}

		if ( ! empty( $payload->parts ) ) {
			foreach ( $payload->parts as $value ) {
				if ( isset( $value->body->data ) ) {
					array_push( $body['html'], $value->body->data );
				}
			}
		}

		return $body;
	}


	/**
	 * Get the message schema.
	 *
	 * @since 0.0.1
	 *
	 * @return array $schema
	 */
	public function get_message_schema() {
		$schema = array(
			// This tells the spec of JSON Schema we are using which is draft 4.
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'Gmail Message',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => 'Unique identifier for the message.',
					'type'        => 'string',
				),
				'thread_id'   => array(
					'description' => 'The immutable ID of the message.',
					'type'        => 'string',
				),
				'label_ids'   => array(
					'description' => 'List of IDs of labels applied to this message.',
					'type'        => 'array',
				),
				'snippet'     => array(
					'description' => 'A short part of the message text..',
					'type'        => 'string',
				),
				'header_meta' => array(
					'description' => 'Message Meta data extracted from message headers',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'string',
						'properties' => array(
							'subject'  => array(
								'description' => 'The message subject.',
								'type'        => 'string',
							),
							'date'     => array(
								'description' => 'The date the email was received.',
								'type'        => 'string',
							),
							'from'     => array(
								'description' => 'A name that appears in the "From:" header for mail sent using this alias.',
								'type'        => 'string',
							),
							'reply_to' => array(
								'description' => 'A name and email that appears in the "Reply-To:" header for mail sent using this alias.',
								'type'        => 'string',
							),
						),
					),
				),
				'body'        => array(
					'description' => 'The message body.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'string',
						'properties' => array(
							'text' => array(
								'description' => 'The text part of the message body which may be empty',
								'type'        => 'string',
							),
							'html' => array(
								'description' => 'The html parts of the message body, which may be empty',
								'type'        => 'array',
								'items'       => array(
									'type' => 'string',
								),
							),
						),
					),
				),
			),
		);

		return $schema;
	}

	/**
	 * Validates that the parameter belongs to a list of admitted values.
	 *
	 * @since 0.0.1
	 *
	 * @param string           $value Value to check.
	 * @param \WP_REST_Request $request The request sent to the WP REST API.
	 * @param string           $param Name of the parameter passed to endpoint holding $value.
	 *
	 * @return bool|\WP_Error
	 */
	public function validate_message_list( $value = '', $request, $param ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'invalid_param_value', sprintf( '%s must be an array', $param ) );
		}

		if ( empty( $value ) ) {
			return new \WP_Error( 'invalid_param_value', sprintf( '%s No message Ids specified', $param ) );
		}

		foreach ( $value as $message_id ) {
			if ( ! is_string( $message_id ) ) {
				return new \WP_Error( 'invalid_param_value', sprintf( 'The API expects %s to be an array of  strings', $param ) );
			}
		}

		return true;
	}

	/**
	 * Sanitizes an array of message ids.
	 *
	 * @since 0.0.1
	 *
	 * @param string           $value Value to check.
	 * @param \WP_REST_Request $request The request sent to the WP REST API.
	 * @param string           $param Name of the parameter passed to endpoint holding $value.
	 *
	 * @return array $sanitized_value
	 */
	public function sanitize_message_id_list( $value = '', $request, $param ) {
		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Store fetched messages using WP Large Options plugin.
	 *
	 * @since 0.0.1
	 *
	 * @return \WP_REST_Response $messages
	 */
	public function get_stored_messages() {
		return $this->keyring_api->keyring_support->
		get_large_option( self::MESSAGES_LARGE_OPTIONS_KEY );
	}

	/**
	 * Store fetched messages using WP Large Options plugin.
	 *
	 * When messages value is set to null, the messages are deleted.
	 *
	 * @since 0.0.1
	 *
	 * @param \WP_REST_Response $messages
	 *
	 * @return object|\WP_Error $res
	 */
	public function store_messages( $messages ) {
		return $this->keyring_api->keyring_support->
		set_large_option( self::MESSAGES_LARGE_OPTIONS_KEY, $messages );
	}
}
