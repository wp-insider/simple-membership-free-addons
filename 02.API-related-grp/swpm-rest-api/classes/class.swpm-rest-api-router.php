<?php

/**
 * Collects the route definitions from routes/api.php and registers them with WordPress.
 *
 * Middleware runs inside the permission_callback, which is the hook WordPress provides for
 * deciding whether a request may reach its callback. A middleware is a callable that receives the
 * request and the part of its name after the colon ('read' for 'auth:read'), and returns true to
 * continue or a WP_Error to stop.
 */
class SWPM_REST_API_Router {

	protected $namespace;
	protected $routes     = array();
	protected $middleware = array();

	/**
	 * @param string $namespace
	 */
	public function __construct( $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Gives a middleware callable the name that routes refer to it by.
	 *
	 * @param string   $name
	 * @param callable $callback
	 */
	public function register_middleware( $name, $callback ) {
		$this->middleware[ $name ] = $callback;
	}

	public function get( $path, $handler ) {
		return $this->add_route( array( 'GET' ), $path, $handler );
	}

	public function post( $path, $handler ) {
		return $this->add_route( array( 'POST' ), $path, $handler );
	}

	public function delete( $path, $handler ) {
		return $this->add_route( array( 'DELETE' ), $path, $handler );
	}

	public function match( $methods, $path, $handler ) {
		return $this->add_route( $methods, $path, $handler );
	}

	protected function add_route( $methods, $path, $handler ) {
		$route          = new SWPM_REST_API_Route( $methods, $path, $handler );
		$this->routes[] = $route;
		return $route;
	}

	/**
	 * @return SWPM_REST_API_Route[]
	 */
	public function get_routes() {
		return $this->routes;
	}

	/**
	 * Registers every route with WordPress, one register_rest_route() call per path.
	 */
	public function register() {
		$endpoints = array();

		foreach ( $this->routes as $route ) {
			$endpoints[ $route->get_wp_path() ][] = array(
				'methods'             => implode( ', ', $route->get_methods() ),
				'callback'            => $route->get_handler(),
				'args'                => $route->get_args(),
				'permission_callback' => function ( $request ) use ( $route ) {
					return $this->run_middleware( $route, $request );
				},
			);
		}

		foreach ( $endpoints as $wp_path => $path_endpoints ) {
			register_rest_route( $this->namespace, $wp_path, $path_endpoints );
		}
	}

	/**
	 * Runs a route's middleware in order, stopping at the first one that does not return true.
	 *
	 * A route with no middleware, or naming one that does not exist, is refused rather than left
	 * open.
	 *
	 * @param SWPM_REST_API_Route $route
	 * @param WP_REST_Request     $request
	 * @return true|WP_Error
	 */
	public function run_middleware( $route, $request ) {
		$names = $route->get_middleware();

		if ( empty( $names ) ) {
			return $this->misconfigured_route_error();
		}

		foreach ( $names as $name ) {
			$parts     = explode( ':', $name, 2 );
			$parameter = isset( $parts[1] ) ? $parts[1] : '';

			if ( ! isset( $this->middleware[ $parts[0] ] ) ) {
				return $this->misconfigured_route_error();
			}

			$result = call_user_func( $this->middleware[ $parts[0] ], $request, $parameter );

			if ( true !== $result ) {
				return $result;
			}
		}

		return true;
	}

	protected function misconfigured_route_error() {
		return new WP_Error(
			'swpm_rest_route_misconfigured',
			__( 'This route has no valid access rules, so the request was refused.', 'swpm-rest-api' ),
			array( 'status' => 500 )
		);
	}
}
