<?php
/**
 * Generates swpm-rest-api/docs/openapi.yaml from routes/api.php.
 *
 * Paths, parameters and request bodies come from the route definitions, and response fields come
 * from each controller's get_response_schema(). WordPress is not needed.
 *
 *   php bin/generate-openapi.php          Write the file.
 *   php bin/generate-openapi.php --check  Exit with an error if the file is out of date.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SWPM_REST_API_NAMESPACE', 'swpm/v1' );

$addon_dir  = dirname( __DIR__, 2 ) . '/swpm-rest-api';
$spec_file  = $addon_dir . '/docs/openapi.yaml';
$check_only = in_array( '--check', $argv, true );

function __( $text ) {
	return $text;
}

foreach ( array( 'route', 'router', 'controller-base', 'member-validator', 'members-controller', 'levels-controller', 'transactions-controller' ) as $class ) {
	require_once $addon_dir . '/classes/class.swpm-rest-api-' . $class . '.php';
}

$router = new SWPM_REST_API_Router( SWPM_REST_API_NAMESPACE );
require $addon_dir . '/routes/api.php';

/**
 * Stops if a controller's response fields and its get_response_schema() have drifted apart.
 */
function assert_schema_matches_controller( $controller, $file ) {
	$source = file_get_contents( $file );

	if ( ! preg_match( '/function prepare_item_for_response.*?\$data = array\((.*?)\n\t\t\);/s', $source, $block ) ) {
		exit( "Could not find the response fields in $file\n" );
	}

	preg_match_all( "/^\t\t\t'(\w+)'\s*=>/m", $block[1], $fields );

	$schema_fields = array_keys( $controller->get_response_schema() );
	$missing       = array_diff( $fields[1], $schema_fields );
	$extra         = array_diff( $schema_fields, $fields[1] );

	if ( $missing || $extra ) {
		exit( basename( $file ) . ' get_response_schema() is out of step. Missing: ' . implode( ', ', $missing ) . '. Extra: ' . implode( ', ', $extra ) . "\n" );
	}
}

function arg_to_schema( $name, $arg ) {
	$schema = array();

	foreach ( array( 'type', 'format', 'enum', 'default', 'minimum', 'maximum', 'description' ) as $key ) {
		if ( isset( $arg[ $key ] ) && '' !== $arg[ $key ] ) {
			$schema[ $key ] = $arg[ $key ];
		}
	}

	if ( 'password' === $name ) {
		$schema['writeOnly'] = true;
	}

	return $schema;
}

function resource_schema( $controller ) {
	$properties = array();

	foreach ( $controller->get_response_schema() as $field => $type ) {
		$properties[ $field ] = array( 'type' => '?' === $type[0] ? array( substr( $type, 1 ), 'null' ) : $type );
	}

	return array(
		'type'       => 'object',
		'properties' => $properties,
	);
}

function error_response( $description ) {
	return array(
		'description' => $description,
		'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/RestError' ) ) ),
	);
}

function header_ref( $name ) {
	return array( '$ref' => '#/components/headers/' . $name );
}

function response_ref( $name ) {
	return array( '$ref' => '#/components/responses/' . $name );
}

/**
 * Builds the success response, wrapped in the data/meta/links envelope the API returns.
 */
function success_response( $docs, $status, $has_throttle ) {
	$resource = array( '$ref' => '#/components/schemas/' . $docs['resource'] );
	$headers  = array();

	if ( ! empty( $docs['collection'] ) ) {
		$properties = array(
			'data'  => array(
				'type'  => 'array',
				'items' => $resource,
			),
			'meta'  => array( '$ref' => '#/components/schemas/PaginationMeta' ),
			'links' => array( '$ref' => '#/components/schemas/PaginationLinks' ),
		);
		$headers['X-WP-Total']      = header_ref( 'Total' );
		$headers['X-WP-TotalPages'] = header_ref( 'TotalPages' );
	} elseif ( ! empty( $docs['deleted'] ) ) {
		$properties = array(
			'data'  => array(
				'type'       => 'object',
				'required'   => array( 'deleted', 'previous' ),
				'properties' => array(
					'deleted'  => array( 'type' => 'boolean' ),
					'previous' => $resource,
				),
			),
			'links' => array( '$ref' => '#/components/schemas/ResourceLinks' ),
		);
	} else {
		$properties = array(
			'data'  => $resource,
			'links' => array( '$ref' => '#/components/schemas/ResourceLinks' ),
		);
	}

	if ( 201 === $status ) {
		$headers['Location'] = array(
			'description' => 'URL of the created resource.',
			'schema'      => array(
				'type'   => 'string',
				'format' => 'uri',
			),
		);
	}

	if ( $has_throttle ) {
		$headers['X-RateLimit-Limit']     = header_ref( 'RateLimitLimit' );
		$headers['X-RateLimit-Remaining'] = header_ref( 'RateLimitRemaining' );
		$headers['X-RateLimit-Reset']     = header_ref( 'RateLimitReset' );
	}

	$response = array( 'description' => 'Successful response.' );

	if ( $headers ) {
		$response['headers'] = $headers;
	}

	$response['content'] = array(
		'application/json' => array(
			'schema' => array(
				'type'       => 'object',
				'required'   => array_keys( $properties ),
				'properties' => $properties,
			),
		),
	);

	return $response;
}

$paths   = array();
$schemas = array();

assert_schema_matches_controller( $members, $addon_dir . '/classes/class.swpm-rest-api-members-controller.php' );
assert_schema_matches_controller( $levels, $addon_dir . '/classes/class.swpm-rest-api-levels-controller.php' );
assert_schema_matches_controller( $transactions, $addon_dir . '/classes/class.swpm-rest-api-transactions-controller.php' );

foreach ( $router->get_routes() as $route ) {
	$docs        = $route->get_docs();
	$args        = $route->get_args();
	$path_params = $route->get_path_params();
	$has_body    = isset( $docs['request'] );
	$handler     = $route->get_handler();
	$status      = isset( $docs['status'] ) ? $docs['status'] : 200;

	$schemas[ $docs['resource'] ] = resource_schema( $handler[0] );

	$parameters = array();
	$properties = array();
	$required   = array();

	foreach ( $args as $name => $arg ) {
		$schema      = arg_to_schema( $name, $arg );
		$description = isset( $schema['description'] ) ? $schema['description'] : '';
		$in_path     = in_array( $name, $path_params, true );

		if ( $has_body && ! $in_path ) {
			$properties[ $name ] = $schema;
			if ( ! empty( $arg['required'] ) ) {
				$required[] = $name;
			}
			continue;
		}

		unset( $schema['description'] );

		$parameter = array(
			'name' => $name,
			'in'   => $in_path ? 'path' : 'query',
		);
		if ( '' !== $description ) {
			$parameter['description'] = $description;
		}
		if ( $in_path ) {
			$parameter['required'] = true;
			$schema['minimum']     = 1;
		}
		$parameter['schema'] = $schema;
		$parameters[]        = $parameter;
	}

	if ( $has_body ) {
		$request_schema = array( 'type' => 'object' );
		if ( $required ) {
			$request_schema['required'] = $required;
		}
		$request_schema['properties'] = $properties;
		$schemas[ $docs['request'] ]  = $request_schema;
	}

	$has_throttle = (bool) array_filter(
		$route->get_middleware(),
		function ( $name ) {
			return 0 === strpos( $name, 'throttle' );
		}
	);

	$responses = array( $status => success_response( $docs, $status, $has_throttle ) );

	if ( $parameters || $has_body ) {
		$responses[400] = response_ref( 'BadRequest' );
	}
	$responses[401] = response_ref( 'Unauthorized' );
	$responses[403] = response_ref( 'Forbidden' );
	if ( $path_params ) {
		$responses[404] = response_ref( 'NotFound' );
	}
	if ( isset( $docs['errors'] ) ) {
		foreach ( $docs['errors'] as $code => $name ) {
			$responses[ $code ] = response_ref( $name );
		}
	}
	if ( $has_throttle ) {
		$responses[429] = response_ref( 'RateLimited' );
	}
	if ( 'GET' !== $route->get_methods()[0] ) {
		$responses[500] = response_ref( 'ServerError' );
	}
	ksort( $responses );

	foreach ( $route->get_methods() as $index => $method ) {
		$operation = array(
			'tags'        => array( $docs['tag'] ),
			'operationId' => $docs['operation'] . ( $index ? ucfirst( strtolower( $method ) ) : '' ),
			'summary'     => $docs['summary'],
		);

		if ( isset( $docs['description'] ) ) {
			$operation['description'] = $docs['description'];
		}
		if ( $parameters ) {
			$operation['parameters'] = $parameters;
		}
		if ( $has_body ) {
			$operation['requestBody'] = array(
				'required' => true,
				'content'  => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/' . $docs['request'] ) ) ),
			);
		}
		$operation['responses'] = $responses;

		$paths[ '/' . SWPM_REST_API_NAMESPACE . $route->get_path() ][ strtolower( $method ) ] = $operation;
	}
}

$schemas['PaginationMeta'] = array(
	'type'       => 'object',
	'properties' => array(
		'total'        => array( 'type' => 'integer' ),
		'per_page'     => array( 'type' => 'integer' ),
		'current_page' => array( 'type' => 'integer' ),
		'total_pages'  => array( 'type' => 'integer' ),
		'has_more'     => array( 'type' => 'boolean' ),
	),
);

$nullable_uri                = array(
	'type'   => array( 'string', 'null' ),
	'format' => 'uri',
);
$schemas['PaginationLinks'] = array(
	'type'       => 'object',
	'properties' => array(
		'self'  => array(
			'type'   => 'string',
			'format' => 'uri',
		),
		'first' => array(
			'type'   => 'string',
			'format' => 'uri',
		),
		'prev'  => $nullable_uri,
		'next'  => $nullable_uri,
		'last'  => array(
			'type'   => 'string',
			'format' => 'uri',
		),
	),
);
$schemas['ResourceLinks']   = array(
	'type'                 => 'object',
	'description'          => 'Links to this resource and related resources, keyed by relation.',
	'additionalProperties' => array(
		'type'   => 'string',
		'format' => 'uri',
	),
);
$schemas['RestError']       = array(
	'type'       => 'object',
	'required'   => array( 'code', 'message', 'data' ),
	'properties' => array(
		'code'    => array(
			'type'        => 'string',
			'description' => 'WordPress REST error code, such as swpm_rest_not_found.',
		),
		'message' => array( 'type' => 'string' ),
		'data'    => array(
			'type'                 => 'object',
			'properties'           => array(
				'status'       => array( 'type' => 'integer' ),
				'errors'       => array(
					'type'                 => 'object',
					'description'          => 'Field name to problem, for validation errors.',
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'member_count' => array( 'type' => 'integer' ),
				'retry_after'  => array( 'type' => 'integer' ),
			),
			'additionalProperties' => true,
		),
	),
);

$rate_limited            = error_response( 'Too many requests.' );
$rate_limited['headers'] = array(
	'Retry-After' => array(
		'description' => 'Seconds to wait before trying again.',
		'schema'      => array( 'type' => 'integer' ),
	),
);

preg_match( '/^\s*Version:\s*(\S+)/m', file_get_contents( $addon_dir . '/swpm-rest-api.php' ), $version );

$spec = array(
	'openapi'    => '3.1.0',
	'info'       => array(
		'title'       => 'Simple Membership REST API',
		'version'     => $version[1],
		'description' => "Machine-readable specification for the Simple Membership REST API add-on.\n\nEnable the API in WP Membership > Settings > Addon Settings. Write operations also require Allow write access, which is off by default. Every route requires authentication.\n\nSend the API key using X-SWPM-API-Key or Authorization: Bearer <api-key>, never in a query parameter. Alternatively, authenticate as a WordPress user with membership management permission; WordPress Application Passwords use HTTP Basic authentication.\n\nRequests are limited per minute for each client. The limits are configurable in the add-on settings.\n",
		'license'     => array( 'name' => 'GPL-2.0-or-later' ),
	),
	'servers'    => array(
		array(
			'url'         => 'https://your-site.com/wp-json',
			'description' => 'Replace with your WordPress REST API root.',
		),
	),
	'tags'       => array(
		array(
			'name'        => 'Members',
			'description' => 'Member profiles and their linked WordPress users.',
		),
		array(
			'name'        => 'Membership levels',
			'description' => 'Membership level configuration.',
		),
		array(
			'name'        => 'Transactions',
			'description' => 'Read-only payment transaction records.',
		),
	),
	'security'   => array(
		array( 'swpmApiKey' => array() ),
		array( 'bearerApiKey' => array() ),
		array( 'wordpressApplicationPassword' => array() ),
	),
	'paths'      => $paths,
	'components' => array(
		'securitySchemes' => array(
			'swpmApiKey'                   => array(
				'type'        => 'apiKey',
				'in'          => 'header',
				'name'        => 'X-SWPM-API-Key',
				'description' => 'API key copied from the add-on settings.',
			),
			'bearerApiKey'                 => array(
				'type'         => 'http',
				'scheme'       => 'bearer',
				'bearerFormat' => 'SWPM API key',
				'description'  => 'The API key as a Bearer token.',
			),
			'wordpressApplicationPassword' => array(
				'type'        => 'http',
				'scheme'      => 'basic',
				'description' => 'WordPress username and Application Password for a user with membership management permission.',
			),
		),
		'headers'         => array(
			'Total'              => array(
				'description' => 'Total matching records, ignoring pagination.',
				'schema'      => array( 'type' => 'integer' ),
			),
			'TotalPages'         => array(
				'description' => 'Number of pages for the requested per_page.',
				'schema'      => array( 'type' => 'integer' ),
			),
			'RateLimitLimit'     => array(
				'description' => 'Requests allowed per minute.',
				'schema'      => array( 'type' => 'integer' ),
			),
			'RateLimitRemaining' => array(
				'description' => 'Requests left in the current minute.',
				'schema'      => array( 'type' => 'integer' ),
			),
			'RateLimitReset'     => array(
				'description' => 'Unix timestamp when the current minute ends.',
				'schema'      => array( 'type' => 'integer' ),
			),
		),
		'responses'       => array(
			'BadRequest'   => error_response( 'Invalid request data or parameters.' ),
			'Unauthorized' => error_response( 'Credentials are missing or invalid, or the WordPress user lacks membership management permission.' ),
			'Forbidden'    => error_response( 'The API or write access is disabled, or a filter rejected the request.' ),
			'NotFound'     => error_response( 'The requested record does not exist.' ),
			'Conflict'     => error_response( 'The level still has assigned members and force was not set.' ),
			'RateLimited'  => $rate_limited,
			'ServerError'  => error_response( 'A database or linked WordPress user operation failed.' ),
		),
		'schemas'         => $schemas,
	),
);

function yaml_key( $key ) {
	if ( is_int( $key ) ) {
		return "'$key'";
	}

	return preg_match( '~^[A-Za-z_$/][\w$/{}.-]*$~', $key ) ? $key : json_encode( $key );
}

function yaml_scalar( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( is_int( $value ) ) {
		return (string) $value;
	}
	if ( preg_match( '~^[A-Za-z_][A-Za-z0-9 _./()-]*[A-Za-z0-9_./()]$~', $value ) && ! preg_match( '/^(true|false|null|yes|no|on|off|y|n)$/i', $value ) ) {
		return $value;
	}

	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * The spec only needs a small part of YAML, so no library is required to write it.
 */
function to_yaml( $data, $depth = 0 ) {
	$pad     = str_repeat( '  ', $depth );
	$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
	$output  = '';

	foreach ( $data as $key => $value ) {
		$prefix = $is_list ? $pad . '- ' : $pad . yaml_key( $key ) . ':';

		if ( is_array( $value ) && ! $value ) {
			$output .= ( $is_list ? $prefix : $prefix . ' ' ) . "[]\n";
		} elseif ( is_array( $value ) && $is_list ) {
			$output .= $prefix . ltrim( to_yaml( $value, $depth + 1 ) );
		} elseif ( is_array( $value ) ) {
			$output .= $prefix . "\n" . to_yaml( $value, $depth + 1 );
		} elseif ( is_string( $value ) && false !== strpos( $value, "\n" ) ) {
			$output .= $prefix . " |\n";
			foreach ( explode( "\n", rtrim( $value ) ) as $line ) {
				$output .= ( '' === $line ? '' : $pad . '  ' . $line ) . "\n";
			}
		} else {
			$output .= ( $is_list ? $prefix : $prefix . ' ' ) . yaml_scalar( $value ) . "\n";
		}
	}

	return $output;
}

$yaml = "# Generated by swpm-rest-api-dev/bin/generate-openapi.php. Do not edit by hand.\n" . to_yaml( $spec );

if ( $check_only ) {
	$current = is_file( $spec_file ) ? str_replace( "\r\n", "\n", file_get_contents( $spec_file ) ) : '';

	if ( $current !== $yaml ) {
		fwrite( STDERR, "docs/openapi.yaml is out of date. Run bin/generate-openapi.php.\n" );
		exit( 1 );
	}

	echo "docs/openapi.yaml is up to date.\n";
	exit( 0 );
}

file_put_contents( $spec_file, $yaml );
echo 'Wrote ' . $spec_file . ' (' . count( $paths ) . " paths).\n";
