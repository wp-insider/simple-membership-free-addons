<?php

/**
 * Shared behaviour for all swpm/v1 REST controllers: the collection parameters that every list
 * endpoint accepts, the response envelope and ORDER BY sanitization. Routes are defined in
 * routes/api.php.
 */
abstract class SWPM_REST_API_Controller_Base {

	/**
	 * The REST namespace. Same for every controller in this addon.
	 *
	 * @var string
	 */
	protected $namespace = SWPM_REST_API_NAMESPACE;

	/**
	 * The collection path for this controller, e.g. 'members'. Used to build response links.
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Describes the fields of this controller's resource, for the generated OpenAPI file.
	 *
	 * @return array Map of field name => type, where a leading '?' marks a nullable field.
	 */
	abstract public function get_response_schema();

	/**
	 * The query parameters that every collection (list) endpoint accepts.
	 *
	 * @return array
	 */
	protected function get_common_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'swpm-rest-api' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to return per page.', 'swpm-rest-api' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'   => array(
				'description'       => __( 'Limit the results to those matching a search term.', 'swpm-rest-api' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'    => array(
				'description'       => __( 'Sort direction of the results.', 'swpm-rest-api' ),
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The SQL OFFSET for the requested page.
	 *
	 * @param WP_REST_Request $request
	 * @return int
	 */
	protected function get_pagination_offset( $request ) {
		$page     = max( 1, (int) $request['page'] );
		$per_page = max( 1, (int) $request['per_page'] );
		return ( $page - 1 ) * $per_page;
	}

	/**
	 * Builds the response for a collection endpoint.
	 *
	 * Responses are wrapped in a data/meta/links envelope. The rows live under 'data' so that
	 * pagination counts and navigation links can travel in the body: a lot of HTTP clients,
	 * no-code tools and AI agent frameworks never surface response headers to the caller, which
	 * would otherwise leave them unable to page through results at all.
	 *
	 * The X-WP-Total and X-WP-TotalPages headers are still sent as well, so tooling that follows
	 * the WordPress REST convention keeps working.
	 *
	 * @param array           $items
	 * @param int             $total Total number of matching rows (ignoring pagination).
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	protected function prepare_collection_response( $items, $total, $request ) {
		$total     = (int) $total;
		$per_page  = max( 1, (int) $request['per_page'] );
		$page      = max( 1, (int) $request['page'] );
		$max_pages = (int) ceil( $total / $per_page );

		$payload = array(
			'data'  => $items,
			'meta'  => array(
				'total'        => $total,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => $max_pages,
				'has_more'     => $page < $max_pages,
			),
			'links' => $this->get_pagination_links( $request, $page, $max_pages ),
		);

		$response = rest_ensure_response( $payload );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $max_pages );

		return $response;
	}

	/**
	 * Builds the response for a single item endpoint.
	 *
	 * Single items get the same 'data' envelope as collections so a client never has to care
	 * which kind of route it called. There is no 'meta' block here because pagination counts are
	 * meaningless for one record.
	 *
	 * @param array $item
	 * @param array $links Related resource links to merge in alongside 'self'.
	 * @return WP_REST_Response
	 */
	protected function prepare_item_response( $item, $links = array() ) {
		$payload = array(
			'data'  => $item,
			//An empty PHP array would encode as a JSON list, but links is always an object.
			'links' => empty( $links ) ? new stdClass() : $links,
		);

		return rest_ensure_response( $payload );
	}

	/**
	 * Builds the self/first/prev/next/last links for a collection response.
	 *
	 * Every query parameter the caller sent is preserved, so following 'next' keeps the same
	 * filters and sort order.
	 *
	 * @param WP_REST_Request $request
	 * @param int             $page
	 * @param int             $max_pages
	 * @return array
	 */
	protected function get_pagination_links( $request, $page, $max_pages ) {
		$base_url = rest_url( $this->namespace . '/' . $this->rest_base );
		$query    = $request->get_query_params();

		//An empty result set still has a first and last page, conceptually page one.
		$last_page = max( 1, $max_pages );

		$links = array(
			'self'  => $this->build_page_link( $base_url, $query, $page ),
			'first' => $this->build_page_link( $base_url, $query, 1 ),
			'prev'  => $page > 1 ? $this->build_page_link( $base_url, $query, $page - 1 ) : null,
			'next'  => $page < $max_pages ? $this->build_page_link( $base_url, $query, $page + 1 ) : null,
			'last'  => $this->build_page_link( $base_url, $query, $last_page ),
		);

		return $links;
	}

	/**
	 * Builds a single paginated URL, carrying the caller's other query parameters across.
	 *
	 * @param string $base_url
	 * @param array  $query
	 * @param int    $page
	 * @return string
	 */
	protected function build_page_link( $base_url, $query, $page ) {
		$query['page'] = (int) $page;

		return add_query_arg( $query, $base_url );
	}

	/**
	 * Builds the canonical URL of one item in this controller's collection.
	 *
	 * @param int|string $id
	 * @return string
	 */
	protected function get_item_url( $id ) {
		return rest_url( $this->namespace . '/' . $this->rest_base . '/' . $id );
	}

	/**
	 * Resolves a requested 'orderby' value against an allowlist.
	 *
	 * The result gets interpolated straight into an ORDER BY clause (column names cannot be bound
	 * as prepared statement parameters), so it must only ever come from this allowlist.
	 *
	 * @param string $orderby
	 * @param array  $allowed
	 * @param string $fallback
	 * @return string
	 */
	protected function sanitize_orderby( $orderby, $allowed, $fallback ) {
		return in_array( $orderby, $allowed, true ) ? $orderby : $fallback;
	}

	/**
	 * Normalizes the sort direction to a literal 'ASC' or 'DESC'.
	 *
	 * @param string $order
	 * @return string
	 */
	protected function sanitize_order( $order ) {
		return strtoupper( (string) $order ) === 'ASC' ? 'ASC' : 'DESC';
	}

	/**
	 * The standard 404 for a single item route.
	 *
	 * @param string $message
	 * @return WP_Error
	 */
	protected function not_found_error( $message ) {
		return new WP_Error( 'swpm_rest_not_found', $message, array( 'status' => 404 ) );
	}
}
