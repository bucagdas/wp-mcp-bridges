<?php
/**
 * 404 Monitor module abilities.
 *
 * @package RankMathMCPAbility
 */

namespace RankMathMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Monitor {

	public static function register(): void {

		wp_register_ability(
			'rank-math-mcp/list-404-logs',
			array(
				'label'               => __( 'List 404 logs', 'rank-math-mcp-ability' ),
				'description'         => __( 'Lists Rank Math 404 monitor entries: URI, hit count, last access time, referer and user agent. Searchable by URI substring. Requires the 404-monitor module (returns module_inactive otherwise).', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Substring to look for in the URI.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum rows. Default 20.',
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => 'Result page. Default 1.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "logs".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission_404' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/delete-404-log',
			array(
				'label'               => __( 'Delete a 404 log entry', 'rank-math-mcp-ability' ),
				'description'         => __( 'Deletes one Rank Math 404 log entry by id. Requires confirm: true. Destructive: the log row is removed permanently.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Log entry id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (deleted row) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( Plugin::class, 'permission_404' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/clear-404-logs',
			array(
				'label'               => __( 'Clear all 404 logs', 'rank-math-mcp-ability' ),
				'description'         => __( 'Empties the Rank Math 404 log table. Run with dry_run: true first to see how many rows would be removed; the real run requires confirm: true. Destructive and irreversible.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'dry_run' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = report the row count only, change nothing.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true for a real (non-dry_run) run.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "dry_run", "old" (row count before) and "new" (row count after).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_clear' ),
				'permission_callback' => array( Plugin::class, 'permission_404' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$table = Plugin::table_or_null( 'rank_math_404_logs' );
		if ( ! $table ) {
			return Plugin::module_inactive_error( '404-monitor' );
		}
		global $wpdb;

		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$where  = '';
		$params = array();
		if ( '' !== $search ) {
			$where    = 'WHERE uri LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $params ) : "SELECT COUNT(*) FROM {$table}" );

		$params2 = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY accessed DESC LIMIT %d OFFSET %d", $params2 ) );

		$logs = array();
		foreach ( (array) $rows as $row ) {
			$logs[] = array(
				'id'             => (int) $row->id,
				'uri'            => rawurldecode( (string) $row->uri ),
				'accessed'       => $row->accessed,
				'times_accessed' => (int) $row->times_accessed,
				'referer'        => (string) $row->referer,
				'user_agent'     => (string) $row->user_agent,
			);
		}

		return array(
			'total' => $total,
			'logs'  => $logs,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a 404 log entry.', 'rank-math-mcp-ability' ) );
		}

		$table = Plugin::table_or_null( 'rank_math_404_logs' );
		if ( ! $table ) {
			return Plugin::module_inactive_error( '404-monitor' );
		}
		global $wpdb;

		$id = (int) $input['id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new \WP_Error( 'log_not_found', __( 'No 404 log entry exists with the given id.', 'rank-math-mcp-ability' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return array(
			'id'  => $id,
			'old' => array(
				'uri'            => rawurldecode( (string) $row->uri ),
				'times_accessed' => (int) $row->times_accessed,
			),
			'new' => null,
		);
	}

	public static function cb_clear( $input ) {
		$dry_run = ! empty( $input['dry_run'] );
		if ( ! $dry_run && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true for a real run, or use dry_run: true to preview.', 'rank-math-mcp-ability' ) );
		}

		$table = Plugin::table_or_null( 'rank_math_404_logs' );
		if ( ! $table ) {
			return Plugin::module_inactive_error( '404-monitor' );
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( ! $dry_run ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return array(
			'dry_run' => $dry_run,
			'old'     => $old,
			'new'     => $new,
		);
	}
}
