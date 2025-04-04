<?php

namespace Piccolo\Gmail\Keyring;

use Piccolo\Gmail\Retry;

class Api {

	/**
	 * The Boundary marker
	 *
	 * Used to separate requests in multipart POST requests.
	 *
	 * @since 0.0.1
	 * @var string
	 */
	const BATCH_BOUNDARY = 'batch_piccolo';

	/**
	 * API support object for this identity.
	 *
	 * @since 0.0.1
	 * @var \Piccolo\Gmail\Keyring\APISupport
	 */
	public $keyring_support;

	/**
	 * This is true when Keyring plugin is fully connected, false otherwise
	 *
	 * @since 0.0.1
	 * @var bool
	 */
	private $enabled;

	/**
	 * Constructor
	 *
	 * Use PHP dependency injection to expose the APISupport methods to this class.
	 *
	 * @since 0.0.1
	 *
	 * @param  \Piccolo\Gmail\Keyring\APISupport $keyring_support
	 */
	public function __construct( $keyring_support ) {
		$this->keyring_support = $keyring_support;
		$this->enabled         = $keyring_support->is_connected;
		$this->register_hooks();
	}

	public function register_hooks() {
		add_filter( 'http_response', array( $this, 'ensure_json_body' ), 10, 3 );
		add_filter( 'keyring_google-mail_request_token_params', array( $this, 'update_token_scope' ), 10, 100 );
	}

	/**
	 * Helper function for GET requests to keyring
	 *
	 * @param string $path The API endpoint to send GET requests to.
	 * @param array  $params query parameters to send along.
	 *
	 * @since 0.0.1
	 *
	 * @return Object|\WP_Error
	 */
	public function get( $path, $params = array() ) {
		return $this->make_request( 'GET', $path, $params );
	}

	/**
	 * Helper function for POST requests to keyring
	 *
	 * @param string $path The API endpoint to send POST requests to.
	 * @param array  $params query parameters to send along.
	 *
	 * @since 0.0.1
	 *
	 * @return object|\WP_Error
	 */
	public function post( $path, $params = array() ) {
		return $this->make_request( 'POST', $path, $params );
	}

	/**
	 * Fetches a list of messages from the Gmail account
	 *
	 * Gmail API endpoint https://gmail.googleapis.com/gmail/v1/users/{userId}/messages
	 *
	 * @see https://developers.google.com/gmail/api/reference/rest/v1/users.messages/list
	 *
	 * @since 0.0.1
	 *
	 * @return object|\WP_Error $res
	 */
	public function fetch_messages() {
		$path   = '/messages';
		$params = array(
			'includeSpamTrash' => false,
			'maxResults'       => 300,
			'q'                => 'label:INBOX !label:' . PISTACHIO_GMAIL_IMPORTED_LABEL,
		);

		try {
			$res = $this->get( $path, $params );
		} catch ( \Throwable $t ) {
			return new \WP_Error(
				'internal_error',
				sprintf(
					__( '%1$s raised a Throwable - %2$s in %3$s on line %4$d.', 'piccolo' ),
					'fetch_messages',
					$t->getMessage(),
					$t->getFile(),
					$t->getLine()
				),
				array(
					'status' => 500,
				)
			);
		}

		return $res;
	}

	/**
	 * Fetches labels
	 *
	 * Gmail API endpoint  GET https://gmail.googleapis.com/gmail/v1/users/{userId}/labels
	 *
	 * @see https://developers.google.com/gmail/api/reference/rest/v1/users.labels/list
	 *
	 * @since 0.0.1
	 *
	 * @return object|\WP_Error $res
	 */
	public function fetch_mailbox_labels() {
		$path = '/labels/';

		try {
			$res = $this->get( $path );

		} catch ( \Throwable $t ) {
			return new \WP_Error(
				'internal_error',
				sprintf(
					__( '%1$s raised a Throwable - %2$s in %3$s on line %4$d.', 'piccolo' ),
					'fetch_labels',
					$t->getMessage(),
					$t->getFile(),
					$t->getLine()
				),
				array(
					'status' => 500,
				)
			);
		}

		return $res;
	}

	/**
	 * Batch modifies messages
	 *
	 * Gmail API endpoint  POST https://gmail.googleapis.com/gmail/v1/users/{userId}/messages/batchModify
	 *
	 * @see https://developers.google.com/gmail/api/reference/rest/v1/users.messages/batchModify
	 *
	 * @since 0.0.1
	 *
	 * @param array $params
	 *
	 * @return object|\WP_Error $res
	 */
	public function batch_modify_messages( $params ) {

		$path = '/messages/batchModify';

		if ( empty( $params['postbody'] ) ) {
			return new \WP_Error( 'batch_modify', 'Postbody is empty', array( 'status' => 400 ) );
		}

		try {
			$res = $this->post(
				$path,
				array(
					'body'    => $params['postbody'],
					'headers' => array( 'Content-type' => 'application/json' ),
				)
			);

		} catch ( \Throwable $t ) {
			return new \WP_Error(
				'internal_error',
				sprintf(
					__( '%1$s raised a Throwable - %2$s in %3$s on line %4$d.', 'piccolo' ),
					'batch_modify_messages',
					$t->getMessage(),
					$t->getFile(),
					$t->getLine()
				),
				array(
					'status' => 500,
				)
			);
		}

		return $res;
	}

	/**
	 * Batch Fetches message details from the Gmail.
	 *
	 * Uses Gmail API endpoint  POST https://www.googleapis.com/batch/gmail/v1
	 *
	 * @see https://developers.google.com/gmail/api/guides/batch
	 *
	 * @since 0.0.1
	 *
	 * @param array $messages_ids  message id's to fetch.
	 *
	 * @return object|\WP_Error $res
	 */
	public function batch_fetch_messages( $messages_ids ) {
		$path = 'batch';

		// Make chunks of 100 requests per batch.
		$message_chunks = array_chunk( $messages_ids, 100 );
		$res            = array();

		foreach ( $message_chunks as $message_ids ) {
			$post_body = $this->get_messages_batch_body( $message_ids );

			try {
				$partial_res = $this->post(
					$path,
					array(
						'body'    => $post_body,
						'headers' => array( 'Content-type' => 'multipart/mixed; boundary=' . self::BATCH_BOUNDARY ),
					)
				);

				/**
				 * This response was  pre-parsed through the "http_response" WP filter
				 * that calls method \Piccolo\Gmail\Keyring\Api::ensure_json_body().
				 * Therefore we need to check for a WP_Error
				 * before attempting to merge.
				 */
				if ( is_wp_error( $partial_res ) ) {
					return $partial_res;
				}

				$res = array_merge( $res, $partial_res );

			} catch ( \Throwable $t ) {
				return new \WP_Error(
					'internal_error',
					sprintf(
						__( '%1$s raised a Throwable - %2$s in %3$s on line %4$d.', 'piccolo' ),
						'fetch_message',
						$t->getMessage(),
						$t->getFile(),
						$t->getLine()
					),
					array(
						'status' => 500,
					)
				);
			}
		}

		return $res;
	}

	/**
	 * Generates a request body for batch message fetches
	 *
	 * @see https://developers.google.com/gmail/api/guides/batch
	 *
	 * @since 0.0.1
	 *
	 * @param array $messages_ids
	 *
	 * @return string $post_body
	 */
	public function get_messages_batch_body( $messages_ids ) {

		$post_body  = "POST /batch/gmail/v1 HTTP/1.1\n";
		$post_body .= "Host: www.googleapis.com\n";
		$post_body .= 'Content-Type: multipart/mixed; boundary=' . self::BATCH_BOUNDARY . "\n";

		foreach ( $messages_ids as $message_id ) {
			$post_body .= "\n--" . self::BATCH_BOUNDARY . "\n";
			$post_body .= "Content-Type: application/http;\n\n";
			$post_body .= 'GET /gmail/v1/users/me/messages/' . $message_id . "\n\n";
		}

		$post_body .= '--' . self::BATCH_BOUNDARY . "--\n";

		return $post_body;
	}

	/**
	 * Wrapper function for making requests through keyring plugin.
	 *
	 * @since 0.0.1
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $params
	 *
	 * @return object|\WP_Error The API response
	 */
	public function make_request( $method = 'GET', $path, $params = array() ) {
		$url = $this->build_request_url( $path, $params );

		if ( defined( 'REQUEST_DEBUG' ) && REQUEST_DEBUG ) {
			error_log( 'GET: ' . $url );
		}

		/**
		 * Filters the preemptive return value of an HTTP request using Keyring
		 *
		 * Returning a non-false value from the filter will short-circuit the HTTP request and return
		 * early with that value. A filter should return one of:
		 *
		 *  - An array containing the response 'body'
		 *  - A WP_Error instance
		 *  - boolean false to avoid short-circuiting the response
		 *
		 * Returning any other value may result in unexpected behaviour.
		 *
		 * @since 0.0.1
		 *
		 * @param false|array|WP_Error $preempt     A preemptive return value of an HTTP request. Default false.
		 * @param array                $params.
		 * @param string               $url         The request URL.
		 */
		$pre = apply_filters( 'keyring_http_request', false, $params, $url );

		if ( false !== $pre ) {
			return $pre;
		}

		if ( ! $this->enabled ) {
			return $this->get_service_error();
		}

		$params = array_merge(
			array(
				'method'  => $method,
				'timeout' => 10,
			),
			$params
		);

		$res = $this->do_request( $url, $params );

		return $this->process_response( $res );
	}

	/**
	 * Run requests using exponential backoff algorithms
	 *
	 * Uses jitter randomness to spread out retries
	 *
	 * @see https://developers.google.com/admin-sdk/email-audit/limits
	 * @see https://en.wikipedia.org/wiki/Truncated_binary_exponential_backoff
	 *
	 * @since 0.0.1
	 *
	 * @param string $url
	 * @param array  $params
	 *
	 * @return object|\WP_Error The API response
	 */
	public function do_request( $url, $params ) {
		// Set retry limit to 5.
		$retry = new Retry( 5 );

		do {
			// This backoff has no effect on the first attempt.
			$retry->backoff();
			$res = $this->keyring_support->service->request( $url, $params );

			if ( ! \Keyring_Util::is_error( $res ) ) {
				return $res;
			}
			// Takes care of incrementing retry attempts.
			$retry->update_state();

			$code = wp_remote_retrieve_response_code( $res );

			// Executed if the response code warrants a retry and the retry limit has not been reached.
			if ( $this->is_retryable_code( $code ) ) {
				$res = $this->keyring_support->service->request( $url, $params );
			} else {
				return $res;
			}
		} while ( $retry->retryable );

		$res->add_data( 'retry', $retry->get_attempt() );

		return $res;
	}

	/**
	 * Build the API URL to submit keyring authentication requests to.
	 *
	 * @since 0.0.1
	 *
	 * @param string $path path relative to the gmail endpoint base path.
	 * @param array  $params
	 *
	 * @return string $url The API URL.
	 */
	public function build_request_url( $path, $params ) {
		if ( 'batch' === $path ) {
			return $this->get_batch_api_endpoint();
		}

		$url  = untrailingslashit( $this->get_api_endpoint() );
		$url .= '/v1/users/me' . $path . '?' . http_build_query( $params );

		return $url;
	}

	/**
	 * Location where Google's Batch API receives requests.
	 *
	 * @since 0.0.1
	 *
	 * @return string $api_endpoint The API URL
	 */
	public function get_batch_api_endpoint() {
		return 'https://www.googleapis.com/batch/gmail/v1';
	}

	/**
	 * Location where Google's API receives requests.
	 *
	 * @since 0.0.1
	 *
	 * @return string $api_endpoint The API URL.
	 */
	public function get_api_endpoint() {
		return 'https://gmail.googleapis.com/gmail';
	}

	/**
	 * Return an error if keyring is not active or fully connected.
	 *
	 * @since 0.0.1
	 *
	 * @return \WP_Error
	 */
	public function get_service_error() {
		if ( ! $this->keyring_support->keyring_is_active() ) {
			return new \WP_Error( 'keyring_service', __( 'It looks like the Keyring plugin is not installed.', 'piccolo' ), array( 'status' => 503 ) );
		}

		$service = \Keyring::get_service_by_name( PISTACHIO_GMAIL_KEYRING_SLUG );

		if ( ! $service ) {
			return new \WP_Error( 'keyring_service', __( 'It looks like you don\'t have the Google Mail service for Keyring installed.', 'piccolo' ), array( 'status' => 503 ) );

		} elseif ( ! $service->is_configured() ) {
			return new \WP_Error( 'keyring_service', __( 'Before you can use this importer, you need to configure the Google Mail service within Keyring.', 'piccolo' ), array( 'status' => 503 ) );
		}

		return new \WP_Error( 'keyring_inactive', __( 'Make sure Keyring plugin is active and it\'s Google Mail service configured.', 'piccolo' ), array( 'status' => 503 ) );
	}

	/**
	 * Process API response from requests made through Keyring plugin.
	 *
	 * @since 0.0.1
	 *
	 * @param object $response
	 *
	 * @return object|\WP_Error
	 */
	public function process_response( $response ) {
		if ( null === $response ) {
			return new \WP_Error( 'null_response', __( 'Failed to download your emails from Gmail. Please wait a few minutes and try again.: - %s', 'piccolo' ), array( 'status' => 400 ) );
		}

		if ( \Keyring_Util::is_error( $response ) ) {
			return $this->rewrite_generic_keyring_error( $response );
		}

		// Grab the cursor for the next URL to request.
		if ( ! empty( $response->nextPageToken ) ) {
			$this->keyring_support->set_option( 'nextPageToken', $response->nextPageToken );
		}

		return $response;
	}

	/**
	 * Rewrite Keyring error message to more meaningful ones.
	 *
	 * @since 0.0.1
	 *
	 * @param \WP_ERROR $response
	 *
	 * @return \WP_Error
	 */
	public function rewrite_generic_keyring_error( $response ) {

		// The entire HTTP object is passed back if it's an error.
		$http        = $response->get_error_message();
		$status_code = wp_remote_retrieve_response_code( $http );
		$body        = wp_remote_retrieve_body( $http );

		if ( 200 === $status_code ) {
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $response;
			} else {
				return new \WP_Error( 'keyring_parse_fail', sprintf( __( 'Failed to parse JSON: - %s', 'piccolo' ), $body ), array( 'status' => 400 ) );
			}
		} elseif ( 400 === $status_code ) {
			return new \WP_Error( 'keyring_json_fail', sprintf( __( 'Failed to get JSON: - %s', 'piccolo' ), $body ), array( 'status' => $status_code ) );
		} elseif ( in_array( $status_code, array( 502, 503 ), true ) ) {
			return new \WP_Error( 'keyring_gmail_fail', sprintf( __( 'Gmail is currently experiencing problems. Please wait for a while then try again: - %s', 'piccolo' ), $body ), array( 'status' => $status_code ) );
		}

		return new \WP_Error( 'keyring_gmail_fail', sprintf( __( 'We got an unknown error back from Gmail. This is what they said: - %s', 'piccolo' ), $body ), array( 'status' => $status_code ) );
	}

	/**
	 * Ensure responses from Gmail's API's are proper json.
	 *
	 * Gmail's batch response body is decorated with headers and boundary text to seperate every
	 * request. https://developers.google.com/gmail/api/guides/batch These need to be cleaned
	 * out else Keyring's request method will return an empty string if the response is not valid json.
	 *
	 * @uses \Piccolo\Gmail\Keyring\Api::convert_batch_to_json()
	 * @filter http_response
	 * @since 0.0.1
	 *
	 * @param array  $response
	 * @param array  $parsed_args
	 * @param string $url
	 *
	 * @return array|\WP_Error $response
	 */
	public function ensure_json_body( $response, $parsed_args, $url ) {

		if ( $url !== $this->get_batch_api_endpoint() ) {
			return $response;
		}

		if ( ! empty( $response['body'] ) ) {
			$response['body'] = $this->convert_batch_to_json( $response['body'] );
		}

		return $response;
	}

	/**
	 * Add gmail.modify and gmail.labels permission scope to Keyring's Gmail authenticator.
	 *
	 * @since 0.0.1
	 *
	 * @param array $params
	 * @return array $params
	 */
	public function update_token_scope( $params ) {
		$params['scope'] = 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/gmail.labels';

		return $params;
	}

	/**
	 * Converts a Gmail API Batch response to proper json.
	 *
	 * @since 0.0.1
	 *
	 * @param string $batch_response
	 *
	 * @return string|\WP_Error $res
	 */
	public function convert_batch_to_json( $batch_response ) {
		if ( empty( $batch_response ) ) {
			return new \WP_Error( 'batch_fail', __( 'No response returned' ), array( 'status' => 400 ) );
		}

		// This delimiter represents the text between each request response.
		$delimiter = substr( $batch_response, 0, strpos( $batch_response, '{' ) );

		if ( empty( $delimiter ) ) {
			return new \WP_Error( 'batch_fail', sprintf( __( 'Failed to convert to JSON: - %s', 'piccolo' ), $delimiter ), array( 'status' => 400 ) );
		}

		// Get string up before the end response "boundary" delimiter.
		$batch_response = substr( $batch_response, 0, strrpos( $batch_response, '--batch_' ) );
		$batch_emails   = explode( $delimiter, $batch_response );
		$batch_emails   = array_filter( $batch_emails );
		$json           = '[' . implode( ',', $batch_emails ) . ']';

		return $json;
	}

	/**
	 * Get the Id of label we use to mark imported messages
	 *
	 * @since 0.0.1
	 *
	 * @return string $label_id
	 */
	public function get_imported_messages_label_id() {
		$label_id = $this->keyring_support->get_option( 'imported-label' );

		if ( false === $label_id ) {

			$mail_labels = $this->fetch_mailbox_labels();

			if ( ! empty( $mail_labels->labels ) ) {
				$imported_label = current( wp_list_filter( $mail_labels->labels, array( 'name' => PISTACHIO_GMAIL_IMPORTED_LABEL ) ) );

				if ( ! empty( $imported_label->id ) ) {
					$label_id = $imported_label->id;
					$this->keyring_support->set_option( 'imported-label', $label_id );
				}
			}
		}

		return $label_id;
	}

	/**
	 * Checks if Gmail response code warrants a re-try.
	 *
	 * @since 0.0.1
	 *
	 * @param string $code respose code
	 *
	 * @return bool
	 */
	public function is_retryable_code( $code ) {

		// array $retryMap Map of errors with retry counts.
		$retry_map = array(
			'500'                   => 1,
			'503'                   => 1,
			'rateLimitExceeded'     => 1,
			'userRateLimitExceeded' => 1,
			6                       => 1,  // COULDNT_RESOLVE_HOST
			7                       => 1,  // COULDNT_CONNECT
			28                      => 1,  // OPERATION_TIMEOUTED
			35                      => 1,  // SSL_CONNECT_ERROR
			52                      => 1,  // GOT_NOTHING
		);

		return isset( $retry_map[ $code ] );
	}
}
