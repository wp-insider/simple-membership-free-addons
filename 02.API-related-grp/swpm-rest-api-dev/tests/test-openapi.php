<?php
/**
 * The OpenAPI file must match the routes.
 */
class Test_SWPM_REST_API_OpenAPI extends WP_UnitTestCase {

	public function test_openapi_file_is_up_to_date() {
		$generator = dirname( __DIR__ ) . '/bin/generate-openapi.php';

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $generator ) . ' --check 2>&1', $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
	}
}
