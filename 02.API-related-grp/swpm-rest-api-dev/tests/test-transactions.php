<?php
/**
 * Transaction endpoints, which read the swpm_transactions post type.
 */
class Test_SWPM_REST_API_Transactions extends SWPM_REST_API_Test_Case {

	/**
	 * Records a payment the way the payment gateways do, and returns the transaction post id.
	 */
	protected function record_payment( $overrides = array() ) {
		$post_id = 0;
		$capture = function ( $txn_data, $db_row_id, $saved_post_id ) use ( &$post_id ) {
			$post_id = $saved_post_id;
		};
		add_action( 'swpm_txn_record_saved', $capture, 10, 3 );

		SwpmTransactions::save_txn_record(
			array_merge(
				array(
					'payer_email'    => 'payer@example.com',
					'first_name'     => 'Pay',
					'last_name'      => 'Er',
					'txn_id'         => 'txn-' . wp_generate_password( 10, false ),
					'custom'         => '',
					'payment_amount' => '10.00',
					'gateway'        => 'stripe',
					'status'         => 'Completed',
				),
				$overrides
			)
		);

		remove_action( 'swpm_txn_record_saved', $capture, 10 );

		return (int) $post_id;
	}

	public function test_id_is_the_transaction_post_id() {
		$post_id = $this->record_payment( array( 'payment_button_id' => '412', 'is_live' => '1' ) );

		$item = $this->assert_success( $this->request( 'GET', '/transactions/' . $post_id ) );

		$this->assertSame( $post_id, $item['id'] );
		$this->assertSame( (int) get_post_meta( $post_id, 'db_row_id', true ), $item['db_row_id'] );
		$this->assertSame( '412', $item['payment_button_id'] );
		$this->assertSame( '1', $item['is_live'] );
		foreach ( array( 'custom', 'discount_amount', 'txn_type', 'subscr_status' ) as $field ) {
			$this->assertArrayHasKey( $field, $item );
		}
	}

	public function test_list_matches_the_single_record() {
		$post_id = $this->record_payment();

		$body = $this->request( 'GET', '/transactions' )->get_data();

		$this->assertSame( 1, $body['meta']['total'] );
		$this->assertSame( $post_id, $body['data'][0]['id'] );
	}

	public function test_sorting_by_date_drops_no_records() {
		$this->record_payment();
		$this->record_payment( array( 'status' => '' ) );
		$this->record_payment( array( 'gateway' => '' ) );

		$by_id   = $this->request( 'GET', '/transactions', null, array( 'orderby' => 'id' ) )->get_data();
		$by_date = $this->request( 'GET', '/transactions', null, array( 'orderby' => 'txn_date' ) )->get_data();

		$this->assertSame( 3, $by_id['meta']['total'] );
		$this->assertSame( $by_id['meta']['total'], $by_date['meta']['total'] );
	}

	public function test_sorting_by_an_optional_field_is_rejected() {
		$this->assertSame( 400, $this->request( 'GET', '/transactions', null, array( 'orderby' => 'status' ) )->get_status() );
	}

	public function test_filters() {
		$this->record_payment( array( 'payer_email' => 'one@example.com', 'gateway' => 'stripe', 'subscr_id' => 'sub_one' ) );
		$this->record_payment( array( 'payer_email' => 'two@example.com', 'gateway' => 'paypal', 'status' => 'Refunded' ) );

		$expected = array(
			array( array( 'email' => 'one@example.com' ), 1 ),
			array( array( 'gateway' => 'paypal' ), 1 ),
			array( array( 'status' => 'Completed' ), 1 ),
			array( array( 'subscr_id' => 'sub_one' ), 1 ),
			array( array( 'search' => 'two@' ), 1 ),
			array( array( 'after' => '2000-01-01' ), 2 ),
			array( array( 'before' => '2000-01-01' ), 0 ),
		);

		foreach ( $expected as $case ) {
			list( $query, $count ) = $case;
			$body = $this->request( 'GET', '/transactions', null, $query )->get_data();
			$this->assertSame( $count, $body['meta']['total'], wp_json_encode( $query ) );
		}
	}

	public function test_member_filter_and_link() {
		$member_id = $this->insert_member_row();
		$post_id   = $this->record_payment( array( 'custom' => 'swpm_id=' . $member_id ) );
		$this->record_payment();

		$body = $this->request( 'GET', '/transactions', null, array( 'member_id' => $member_id ) )->get_data();
		$this->assertSame( 1, $body['meta']['total'] );

		$single = $this->request( 'GET', '/transactions/' . $post_id )->get_data();
		$this->assertSame( $member_id, $single['data']['member_id'] );
		$this->assertStringEndsWith( '/swpm/v1/members/' . $member_id, $single['links']['member'] );
	}

	public function test_bad_date_is_rejected() {
		$this->assertSame( 400, $this->request( 'GET', '/transactions', null, array( 'after' => 'not-a-date' ) )->get_status() );
	}

	public function test_a_post_that_is_not_a_transaction_is_not_found() {
		$post_id = self::factory()->post->create();

		$this->assert_error( $this->request( 'GET', '/transactions/' . $post_id ), 404, 'swpm_rest_not_found' );
	}
}
