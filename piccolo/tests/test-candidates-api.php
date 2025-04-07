<?php
class Test_Candidates_REST_Endpoint extends WP_Test_REST_TestCase {
	static $json_fixtures;

	public static function wpSetUpBeforeClass() {
		self::$json_fixtures = json_decode( file_get_contents( dirname( __DIR__ ) . '/candidates.json' ) ); // TODO: Inefficient. Perhaps create a custom factory?
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/wp/v2/candidates', $routes );
	}

	public function test_editor_can_get_a_list_of_candidates() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		$post_id = $this->factory->post->create(
			array(
				'post_title' => 'Bonita Wintheiser',
				'post_type'  => 'candidate',
			)
		);
		add_post_meta( $post_id, 'json', self::$json_fixtures[0] );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/candidates' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 1, count( $data ), 'Candidate not found.' );
		$this->assertGreaterThan( 0, count( $data[0]['json']->applications ), 'Candidate does not have any applications.' );

	}
}
