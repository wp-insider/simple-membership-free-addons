<?php
/**
 * Every route of the swpm/v1 REST API, in one place.
 *
 * $router is the SWPM_REST_API_Router this file is included from. The docs() details are not used
 * at runtime: bin/generate-openapi.php in the swpm-rest-api-dev folder reads them, together with
 * each route's args, to produce docs/openapi.yaml. Re-run it after changing this file.
 */

//Direct access to this file is not permitted
if ( ! defined( 'ABSPATH' ) ) {
	exit; //Exit if accessed directly
}

$read_access  = array( 'auth:read', 'throttle:read' );
$write_access = array( 'auth:write', 'throttle:write' );

$update_methods = array( 'PATCH', 'PUT', 'POST' );

$members      = new SWPM_REST_API_Members_Controller();
$levels       = new SWPM_REST_API_Levels_Controller();
$transactions = new SWPM_REST_API_Transactions_Controller();

$member_id_arg = array( 'id' => array( 'description' => __( 'Unique identifier of the member profile.', 'swpm-rest-api' ) ) );
$level_id_arg  = array( 'id' => array( 'description' => __( 'Unique identifier of the membership level. Id 1 is reserved and returns not found.', 'swpm-rest-api' ) ) );

/*
 * Members
 */
$router->get( '/members', array( $members, 'get_items' ) )
	->args( $members->get_collection_params() )
	->middleware( $read_access )
	->docs(
		array(
			'operation'  => 'listMembers',
			'tag'        => 'Members',
			'summary'    => 'List and search members',
			'resource'   => 'Member',
			'collection' => true,
		)
	);

$router->post( '/members', array( $members, 'create_item' ) )
	->args( SWPM_REST_API_Member_Validator::get_route_args( true ) )
	->middleware( $write_access )
	->docs(
		array(
			'operation'   => 'createMember',
			'tag'         => 'Members',
			'summary'     => 'Create a member and its WordPress user',
			'description' => 'Without account_state, the status follows the level: email activation gives activation_required, then the level default, then the global setting. Requires write access.',
			'resource'    => 'Member',
			'request'     => 'MemberCreate',
			'status'      => 201,
		)
	);

$router->get( '/members/{id}', array( $members, 'get_item' ) )
	->args( $member_id_arg )
	->middleware( $read_access )
	->docs(
		array(
			'operation' => 'getMember',
			'tag'       => 'Members',
			'summary'   => 'Get a member',
			'resource'  => 'Member',
		)
	);

$router->match( $update_methods, '/members/{id}', array( $members, 'update_item' ) )
	->args( array_merge( $member_id_arg, SWPM_REST_API_Member_Validator::get_route_args() ) )
	->middleware( $write_access )
	->docs(
		array(
			'operation'   => 'updateMember',
			'tag'         => 'Members',
			'summary'     => 'Update a member',
			'description' => 'Updates only the fields sent, on every method. user_name cannot be changed. Requires write access.',
			'resource'    => 'Member',
			'request'     => 'MemberUpdate',
		)
	);

$router->delete( '/members/{id}', array( $members, 'delete_item' ) )
	->args( $member_id_arg )
	->middleware( $write_access )
	->docs(
		array(
			'operation'   => 'deleteMember',
			'tag'         => 'Members',
			'summary'     => 'Delete a member and its WordPress user',
			'description' => 'A linked WordPress user with an administrator role is preserved. Requires write access.',
			'resource'    => 'Member',
			'deleted'     => true,
		)
	);

/*
 * Membership levels
 */
$router->get( '/levels', array( $levels, 'get_items' ) )
	->args( $levels->get_collection_params() )
	->middleware( $read_access )
	->docs(
		array(
			'operation'  => 'listMembershipLevels',
			'tag'        => 'Membership levels',
			'summary'    => 'List membership levels',
			'resource'   => 'MembershipLevel',
			'collection' => true,
		)
	);

$router->post( '/levels', array( $levels, 'create_item' ) )
	->args( $levels->get_write_params( true ) )
	->middleware( $write_access )
	->docs(
		array(
			'operation'   => 'createMembershipLevel',
			'tag'         => 'Membership levels',
			'summary'     => 'Create a membership level',
			'description' => 'subscription_period is required unless subscription_duration_type is 0. Requires write access.',
			'resource'    => 'MembershipLevel',
			'request'     => 'MembershipLevelCreate',
			'status'      => 201,
		)
	);

$router->get( '/levels/{id}', array( $levels, 'get_item' ) )
	->args( $level_id_arg )
	->middleware( $read_access )
	->docs(
		array(
			'operation' => 'getMembershipLevel',
			'tag'       => 'Membership levels',
			'summary'   => 'Get a membership level',
			'resource'  => 'MembershipLevel',
		)
	);

$router->match( $update_methods, '/levels/{id}', array( $levels, 'update_item' ) )
	->args( array_merge( $level_id_arg, $levels->get_write_params() ) )
	->middleware( $write_access )
	->docs(
		array(
			'operation'   => 'updateMembershipLevel',
			'tag'         => 'Membership levels',
			'summary'     => 'Update a membership level',
			'description' => 'Updates only the fields sent, on every method. Changing the duration type also requires a matching subscription_period, unless the new type is 0. Requires write access.',
			'resource'    => 'MembershipLevel',
			'request'     => 'MembershipLevelUpdate',
		)
	);

$router->delete( '/levels/{id}', array( $levels, 'delete_item' ) )
	->args( array_merge( $level_id_arg, $levels->get_delete_params() ) )
	->middleware( $write_access )
	->docs(
		array(
			'operation'   => 'deleteMembershipLevel',
			'tag'         => 'Membership levels',
			'summary'     => 'Delete a membership level',
			'description' => 'Fails with 409 while members are assigned to the level, unless force is true. Requires write access.',
			'resource'    => 'MembershipLevel',
			'deleted'     => true,
			'errors'      => array( 409 => 'Conflict' ),
		)
	);

/*
 * Transactions
 */
$router->get( '/transactions', array( $transactions, 'get_items' ) )
	->args( $transactions->get_collection_params() )
	->middleware( $read_access )
	->docs(
		array(
			'operation'  => 'listTransactions',
			'tag'        => 'Transactions',
			'summary'    => 'List and search transactions',
			'resource'   => 'Transaction',
			'collection' => true,
		)
	);

$router->get( '/transactions/{id}', array( $transactions, 'get_item' ) )
	->args( array( 'id' => array( 'description' => __( 'Post id of the transaction record.', 'swpm-rest-api' ) ) ) )
	->middleware( $read_access )
	->docs(
		array(
			'operation' => 'getTransaction',
			'tag'       => 'Transactions',
			'summary'   => 'Get a transaction',
			'resource'  => 'Transaction',
		)
	);
