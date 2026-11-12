<?php

/**
 * One route definition: the HTTP methods, path, controller method, arguments and middleware.
 *
 * Instances are created by SWPM_REST_API_Router and configured with chained calls in
 * routes/api.php.
 */
class SWPM_REST_API_Route {

	protected $methods;
	protected $path;
	protected $handler;
	protected $args       = array();
	protected $middleware = array();
	protected $docs       = array();

	/**
	 * @param array    $methods HTTP methods, e.g. array( 'GET' ).
	 * @param string   $path    Path relative to the namespace, e.g. '/members/{id}'.
	 * @param callable $handler The controller method that produces the response.
	 */
	public function __construct( $methods, $path, $handler ) {
		$this->methods = $methods;
		$this->path    = $path;
		$this->handler = $handler;
	}

	/**
	 * Sets the request arguments (the same schema register_rest_route() takes).
	 *
	 * @param array $args
	 * @return $this
	 */
	public function args( $args ) {
		$this->args = $args;
		return $this;
	}

	/**
	 * Adds middleware to run before the controller, in order.
	 *
	 * @param string|array $names Names like 'auth:read'.
	 * @return $this
	 */
	public function middleware( $names ) {
		$this->middleware = array_merge( $this->middleware, (array) $names );
		return $this;
	}

	/**
	 * Sets the details used to generate docs/openapi.yaml. They have no effect at runtime.
	 *
	 * @param array $docs
	 * @return $this
	 */
	public function docs( $docs ) {
		$this->docs = $docs;
		return $this;
	}

	public function get_methods() {
		return $this->methods;
	}

	public function get_path() {
		return $this->path;
	}

	public function get_handler() {
		return $this->handler;
	}

	public function get_middleware() {
		return $this->middleware;
	}

	public function get_docs() {
		return $this->docs;
	}

	/**
	 * The route in the regex form WordPress expects. Every {placeholder} is a numeric id.
	 *
	 * @return string
	 */
	public function get_wp_path() {
		return preg_replace( '/\{(\w+)\}/', '(?P<$1>\d+)', $this->path );
	}

	/**
	 * The {placeholder} names in the path.
	 *
	 * @return array
	 */
	public function get_path_params() {
		preg_match_all( '/\{(\w+)\}/', $this->path, $matches );
		return $matches[1];
	}

	/**
	 * The request arguments, with every path placeholder included as an integer.
	 *
	 * @return array
	 */
	public function get_args() {
		$args = $this->args;

		foreach ( $this->get_path_params() as $param ) {
			$defined        = isset( $args[ $param ] ) ? $args[ $param ] : array();
			$args[ $param ] = array_merge(
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				$defined
			);
		}

		return $args;
	}
}
