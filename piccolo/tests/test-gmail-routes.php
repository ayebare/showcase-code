<?php

class Test_Gmail_REST_Endpoints extends WP_Test_REST_TestCase {

	/**
	 * Store for sample responses.
	 *
	 * We populate this array with sample responses from response.php.
	 *
	 * @since 0.0.1
	 * @var array.
	 */
	static $sample_manifest;

	public static function wpSetUpBeforeClass() {

		$sample_responses = __DIR__ . DIRECTORY_SEPARATOR . 'response.php';

		self::$sample_manifest = include $sample_responses;

		do_action( 'rest_api_init' );
	}

	public function test_messages_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertarrayHasKey( '/piccolo-api/v1/gmail/messages', $routes );
	}

	public function test_import_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertarrayHasKey( '/piccolo-api/v1/gmail/messages/import', $routes );
	}

	/**
	 * Test to ensure that there is a permissions restriction to Gmail API access.
	 *
	 * @since 0.0.1
	 *
	 * return void
	 */
	public function test_user_permission_fetch_messages_check() {
		$request  = new \WP_REST_Request( 'GET', '/piccolo-api/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( rest_authorization_required_code(), $response->get_status() );
	}

	/**
	 * Test to ensure admins have permission to fetch messages from Gmail.
	 *
	 * @since 0.0.1
	 *
	 * return void
	 */
	public function test_admin_can_fetch_messages() {
		$this->mock_batch_fetch_messages_response();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		$request  = new \WP_REST_Request( 'GET', '/piccolo-api/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertResponseStatus( 200, $response );
	}

	/**
	 * Test to ensure the structure of messages returned is as expected.
	 *
	 * @since 0.0.1
	 *
	 * return void
	 */
	public function test_served_message_structure() {
		$this->mock_batch_fetch_messages_response();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		$request       = new \WP_REST_Request( 'GET', '/piccolo-api/v1/gmail/messages' );
		$response      = rest_get_server()->dispatch( $request );
		$response_body = $response->get_data();
		$this->assertMessageResponseData( $response_body );
	}

	public function test_gmail_fetch_stores_messages() {
		$this->markTestIncomplete();
	}


	public function test_gmail_import_empties_storage() {
		$this->markTestIncomplete();
	}

	/**
	 * Helper function to mock batch fetch messages response.
	 *
	 * @since 0.0.1
	 *
	 * return void
	 */
	public function mock_batch_fetch_messages_response() {
		$this->mock_fetch_message_response();

		$url      = 'https://www.googleapis.com/batch/gmail/v1';
		$response = self::$sample_manifest['fetch_batch_messages'];

		$this->mock_keyring_response( $response, $url );
	}

	/**
	 * Helper function to mock fetch messages response. ( Message ids )
	 *
	 * @since 0.0.1
	 *
	 * return void
	 */
	public function mock_fetch_message_response() {
		$url      = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';
		$response = self::$sample_manifest['fetch_messages'];

		$this->mock_keyring_response( $response, $url, 0.10 );
	}

	/**
	 * Helper function to mock responses returned by keyring given a route.
	 *
	 * @param mixed $mocked_response The response for to return for the route.
	 * @param string $mock_url The request path to check against.
	 */
	public function mock_keyring_response( $mocked_response, $mock_url ) {
		add_filter( 'keyring_http_request', function ( $response, $params, $url ) use ( $mocked_response, $mock_url ) {
			if ( strpos( $url, $mock_url ) !== 0 ) {
				return $response;
			}

			return $mocked_response;
		}, 10, 3 );
	}

	/**
	 * Check response code
	 *
	 * @param string $status Status code.
	 * @param object $response REST API response object.
	 */
	protected function assertResponseStatus( $status, $response ) {
		$this->assertEquals( $status, $response->get_status() );
	}

	/**
	 * Ensure messages response includes the expected data
	 *
	 */
	protected function assertMessageResponseData( $response ) {
		$expected = array(
			array(
				'data'    => array(
					'id'        => '1818f71c2f5cbf0c',
					'thread_id' => '1818f71c2f5cbf0c',
					'label_ids' => array( '0' => 'INBOX' ),

					'snippet'     => 'Sample Snippet',
					'header_meta' => array(
						'date'    => 'Thu, 23 Jun 2022 07:23:25 GMT',
						'subject' => 'Tests',
						'from'    => 'Test test@accounts.tests.com',
					),
					'body'        => array(
						'plain' => array(),
						'html'  => array(
							'0' => 'text plain data',
							'1' => 'text html data'
						)
					)
				),
				'headers' => array(),
				'status'  => 200
			)
		);

		$this->assertEquals( wp_json_encode( $expected ), $response );
	}
}
