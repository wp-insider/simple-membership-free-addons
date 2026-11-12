<?php

/**
 * Read-only endpoints for payment/transaction records.
 *
 * GET /swpm/v1/transactions      - list and search transactions
 * GET /swpm/v1/transactions/<id> - retrieve a single transaction
 *
 * These read the 'swpm_transactions' custom post type, which is the canonical store for payment
 * records and the same one the plugin's Payments menu reads.
 *
 * Note on storage: SwpmTransactions::save_txn_record() writes each payment twice. It inserts a
 * row into the swpm_payments_tbl table (its own comment describes that as "for backwards
 * compatibility") and then creates a swpm_transactions post, linking the two with a 'db_row_id'
 * post meta. The post is the richer record: payment_button_id, is_live, custom, discount_amount,
 * txn_type and subscr_status are only ever stored there. So the post ID is what this controller
 * exposes as 'id', with the legacy table's row id reported alongside as 'db_row_id'.
 */
class SWPM_REST_API_Transactions_Controller extends SWPM_REST_API_Controller_Base {

	protected $rest_base = 'transactions';

	/**
	 * The post type holding the transaction records.
	 */
	const POST_TYPE = 'swpm_transactions';

	/**
	 * Values a client is allowed to sort the collection by.
	 *
	 * This list is deliberately short. Sorting by a post meta value in WP_Query requires the meta
	 * row to exist, and save_txn_record() runs array_filter() over the transaction data before
	 * writing it, so any field that happened to be empty at save time has no meta row at all.
	 * Sorting by one of those (status or gateway, say) would silently drop every transaction
	 * missing it from the results. Only 'txn_date', which is always written, is safe to sort on,
	 * alongside the post id itself.
	 *
	 * @var array
	 */
	protected $sortable_columns = array( 'id', 'txn_date' );

	public function get_response_schema() {
		return array(
			'id'                => 'integer',
			'db_row_id'         => '?integer',
			'member_id'         => '?integer',
			'email'             => 'string',
			'first_name'        => 'string',
			'last_name'         => 'string',
			'membership_level'  => 'string',
			'txn_date'          => 'string',
			'txn_id'            => 'string',
			'subscr_id'         => 'string',
			'subscr_status'     => 'string',
			'reference'         => 'string',
			'payment_amount'    => 'string',
			'discount_amount'   => 'string',
			'gateway'           => 'string',
			'status'            => 'string',
			'ip_address'        => 'string',
			'payment_button_id' => 'string',
			'is_live'           => 'string',
			'custom'            => 'string',
			'txn_type'          => 'string',
		);
	}

	/**
	 * The query parameters accepted by the collection endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = $this->get_common_collection_params();

		$params['search']['description'] = __( 'Limit the results to transactions matching this term in the email address, name, transaction id or subscription id.', 'swpm-rest-api' );

		$params['orderby'] = array(
			'description'       => __( 'Field to sort the results by.', 'swpm-rest-api' ),
			'type'              => 'string',
			'default'           => 'id',
			'enum'              => $this->sortable_columns,
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['member_id'] = array(
			'description'       => __( 'Limit the results to transactions belonging to this member id.', 'swpm-rest-api' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		$params['email'] = array(
			'description'       => __( 'Limit the results to transactions with this exact email address.', 'swpm-rest-api' ),
			'type'              => 'string',
			'format'            => 'email',
			'sanitize_callback' => 'sanitize_email',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['gateway'] = array(
			'description'       => __( 'Limit the results to transactions made through this payment gateway.', 'swpm-rest-api' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		$params['status'] = array(
			'description'       => __( 'Limit the results to transactions with this status.', 'swpm-rest-api' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		$params['subscr_id'] = array(
			'description'       => __( 'Limit the results to transactions belonging to this subscription id.', 'swpm-rest-api' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		$params['after'] = array(
			'description'       => __( 'Limit the results to transactions on or after this date (YYYY-MM-DD).', 'swpm-rest-api' ),
			'type'              => 'string',
			'format'            => 'date',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => array( $this, 'validate_date_param' ),
		);

		$params['before'] = array(
			'description'       => __( 'Limit the results to transactions on or before this date (YYYY-MM-DD).', 'swpm-rest-api' ),
			'type'              => 'string',
			'format'            => 'date',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => array( $this, 'validate_date_param' ),
		);

		return $params;
	}

	/**
	 * Validates that a date parameter is in the YYYY-MM-DD format and is a real calendar date.
	 *
	 * @param string $value
	 * @return true|WP_Error
	 */
	public function validate_date_param( $value ) {
		$date = DateTime::createFromFormat( 'Y-m-d', $value );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				'swpm_rest_invalid_date',
				__( 'The date must be a valid date in the YYYY-MM-DD format.', 'swpm-rest-api' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * GET /swpm/v1/transactions
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => max( 1, (int) $request['per_page'] ),
			'paged'          => max( 1, (int) $request['page'] ),
			'fields'         => 'ids',
			//The total is needed for the pagination meta, so found_rows must be calculated.
			'no_found_rows'  => false,
			//Transaction posts carry no terms and are never rendered, so skip those caches.
			'update_post_term_cache' => false,
		);

		$meta_query = $this->build_meta_query( $request );
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$args = $this->apply_ordering( $args, $request );

		$query    = new WP_Query( $args );
		$post_ids = $query->posts;

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$items[] = $this->prepare_item_for_response( (int) $post_id );
		}

		return $this->prepare_collection_response( $items, (int) $query->found_posts, $request );
	}

	/**
	 * GET /swpm/v1/transactions/<id>
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( empty( $post ) || self::POST_TYPE !== $post->post_type ) {
			return $this->not_found_error( __( 'No transaction was found for the given id.', 'swpm-rest-api' ) );
		}

		$item = $this->prepare_item_for_response( $post_id );

		return $this->prepare_item_response( $item, $this->get_transaction_links( $item ) );
	}

	/**
	 * Translates the request filters into a WP_Query meta_query.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	protected function build_meta_query( $request ) {
		$meta_query = array( 'relation' => 'AND' );

		$search = $request['search'];
		if ( ! empty( $search ) ) {
			//Same set of fields the plugin's own Payments menu searches across.
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'email',
					'value'   => $search,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'first_name',
					'value'   => $search,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'last_name',
					'value'   => $search,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'txn_id',
					'value'   => $search,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'subscr_id',
					'value'   => $search,
					'compare' => 'LIKE',
				),
			);
		}

		$exact_match_filters = array(
			'member_id' => 'member_id',
			'email'     => 'email',
			'gateway'   => 'gateway',
			'status'    => 'status',
			'subscr_id' => 'subscr_id',
		);

		foreach ( $exact_match_filters as $param => $meta_key ) {
			if ( empty( $request[ $param ] ) ) {
				continue;
			}

			$meta_query[] = array(
				'key'     => $meta_key,
				'value'   => $request[ $param ],
				'compare' => '=',
			);
		}

		if ( ! empty( $request['after'] ) ) {
			$meta_query[] = array(
				'key'     => 'txn_date',
				'value'   => $request['after'],
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $request['before'] ) ) {
			$meta_query[] = array(
				'key'     => 'txn_date',
				'value'   => $request['before'],
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		//Only the 'relation' key means nothing was actually filtered.
		if ( count( $meta_query ) < 2 ) {
			return array();
		}

		return $meta_query;
	}

	/**
	 * Applies the requested sort order to the query arguments.
	 *
	 * @param array           $args
	 * @param WP_REST_Request $request
	 * @return array
	 */
	protected function apply_ordering( $args, $request ) {
		$orderby = $this->sanitize_orderby( $request['orderby'], $this->sortable_columns, 'id' );
		$order   = $this->sanitize_order( $request['order'] );

		if ( 'txn_date' === $orderby ) {
			$args['meta_key']  = 'txn_date';
			$args['meta_type'] = 'DATE';
			//The post id is the tie breaker, since txn_date has no time component.
			$args['orderby'] = array(
				'meta_value' => $order,
				'ID'         => $order,
			);

			return $args;
		}

		$args['orderby'] = array( 'ID' => $order );

		return $args;
	}

	/**
	 * Shapes a transaction post into the API response.
	 *
	 * @param int $post_id
	 * @return array
	 */
	protected function prepare_item_for_response( $post_id ) {
		$meta = SwpmTransactions::get_txn_post_meta_data_in_array_format( $post_id );

		$member_id = isset( $meta['member_id'] ) ? $meta['member_id'] : '';

		$data = array(
			'id'                => (int) $post_id,
			'db_row_id'         => empty( $meta['db_row_id'] ) ? null : (int) $meta['db_row_id'],
			'member_id'         => '' === $member_id ? null : (int) $member_id,
			'email'             => $meta['email'],
			'first_name'        => $meta['first_name'],
			'last_name'         => $meta['last_name'],
			'membership_level'  => $meta['membership_level'],
			'txn_date'          => $meta['txn_date'],
			'txn_id'            => $meta['txn_id'],
			'subscr_id'         => $meta['subscr_id'],
			'subscr_status'     => $meta['subscr_status'],
			'reference'         => $meta['reference'],
			'payment_amount'    => $meta['payment_amount'],
			'discount_amount'   => $meta['discount_amount'],
			'gateway'           => $meta['gateway'],
			'status'            => $meta['status'],
			'ip_address'        => $meta['ip_address'],
			'payment_button_id' => $meta['payment_button_id'],
			'is_live'           => $meta['is_live'],
			'custom'            => $meta['custom'],
			//Not part of get_txn_post_meta_data_in_array_format(), but save_txn_record() stores it.
			'txn_type'          => get_post_meta( $post_id, 'txn_type', true ),
		);

		/**
		 * Lets other addons add fields to (or remove fields from) a transaction API response.
		 */
		return apply_filters( 'swpm_rest_api_prepare_transaction', $data, $post_id );
	}

	/**
	 * Related resource links for one transaction.
	 *
	 * @param array $item A prepared transaction item.
	 * @return array
	 */
	protected function get_transaction_links( $item ) {
		$links = array(
			'self' => $this->get_item_url( $item['id'] ),
		);

		if ( ! empty( $item['member_id'] ) ) {
			$links['member'] = rest_url( $this->namespace . '/members/' . $item['member_id'] );
		}

		return $links;
	}
}
