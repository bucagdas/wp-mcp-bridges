<?php
/**
 * Redirections module abilities.
 *
 * Talks to the rank_math_redirections table directly (with existence
 * checks) so the verbs work independent of module class autoloading;
 * the cache table is cleared on every write to avoid stale redirects.
 *
 * @package RankMathMCPAbility
 */

namespace RankMathMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redirections {

	const HEADER_CODES = array( 301, 302, 307, 410, 451 );
	const COMPARISONS  = array( 'exact', 'contains', 'start', 'end', 'regex' );

	public static function register(): void {

		wp_register_ability(
			'rank-math-mcp/list-redirections',
			array(
				'label'               => __( 'List redirections', 'rank-math-mcp-ability' ),
				'description'         => __( 'Lists Rank Math redirections with sources, target URL, header code, status and hit count. Filter by status or search in the target URL. Requires the redirections module (returns module_inactive otherwise).', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive', 'trashed', 'any' ),
							'default'     => 'any',
							'description' => 'Filter by status. Default "any".',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Substring to look for in the target URL.',
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
					'description' => 'Object with "total" and "redirections".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/get-redirection',
			array(
				'label'               => __( 'Get a redirection', 'rank-math-mcp-ability' ),
				'description'         => __( 'Returns one Rank Math redirection by id, including all source patterns.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Redirection id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The redirection row.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/create-redirection',
			array(
				'label'               => __( 'Create a redirection', 'rank-math-mcp-ability' ),
				'description'         => __( 'Creates a Rank Math redirection from one source pattern to a target URL. comparison: exact, contains, start, end or regex (default exact). header_code: 301, 302, 307, 410 or 451 (default 301; 410/451 need no target URL). Returns the created row.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source'      => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => 'Source pattern, e.g. "/eski-sayfa" (without the domain).',
						),
						'comparison'  => array(
							'type'        => 'string',
							'enum'        => self::COMPARISONS,
							'default'     => 'exact',
							'description' => 'How the source is matched. Default "exact".',
						),
						'url_to'      => array(
							'type'        => 'string',
							'description' => 'Target URL. Required unless header_code is 410 or 451.',
						),
						'header_code' => array(
							'type'        => 'integer',
							'enum'        => self::HEADER_CODES,
							'default'     => 301,
							'description' => 'HTTP status code. Default 301.',
						),
						'status'      => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive' ),
							'default'     => 'active',
							'description' => 'Initial status. Default "active".',
						),
					),
					'required'             => array( 'source' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created redirection row.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/update-redirection',
			array(
				'label'               => __( 'Update a redirection', 'rank-math-mcp-ability' ),
				'description'         => __( 'Updates one Rank Math redirection. Provide only the fields to change: source, comparison, url_to, header_code and/or status (active, inactive or trashed). Returns {old,new} rows read back after the write.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Redirection id.',
						),
						'source'      => array( 'type' => 'string', 'minLength' => 1 ),
						'comparison'  => array(
							'type' => 'string',
							'enum' => self::COMPARISONS,
						),
						'url_to'      => array( 'type' => 'string' ),
						'header_code' => array(
							'type' => 'integer',
							'enum' => self::HEADER_CODES,
						),
						'status'      => array(
							'type' => 'string',
							'enum' => array( 'active', 'inactive', 'trashed' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "old" and "new" redirection rows.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/bulk-update-redirection-status',
			array(
				'label'               => __( 'Bulk update redirection status', 'rank-math-mcp-ability' ),
				'description'         => __( 'Sets the status (active, inactive or trashed) of many redirections at once, filtered by their current status. ALWAYS run with dry_run: true first to see how many rows match. The real run requires confirm: true.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'from_status' => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive', 'trashed' ),
							'description' => 'Only redirections currently in this status are affected.',
						),
						'to_status'   => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive', 'trashed' ),
							'description' => 'New status to set.',
						),
						'limit'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'default'     => 50,
							'description' => 'Maximum rows to affect. Default 50.',
						),
						'dry_run'     => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = report matches only, change nothing.',
						),
						'confirm'     => array(
							'type'        => 'boolean',
							'description' => 'Must be true for a real (non-dry_run) run.',
						),
					),
					'required'             => array( 'from_status', 'to_status' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "dry_run", "matched", "changed" and "ids".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_bulk_update_status' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/get-redirection-stats',
			array(
				'label'               => __( 'Get redirection statistics', 'rank-math-mcp-ability' ),
				'description'         => __( 'Returns redirection counts by status and the top redirections by hit count. Useful for spotting the most-used redirects or how many are inactive/trashed.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "by_status" (counts) and "top_hits" (array of {id, url_to, hits}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get_stats' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'rank-math-mcp/delete-redirection',
			array(
				'label'               => __( 'Delete a redirection', 'rank-math-mcp-ability' ),
				'description'         => __( 'Deletes one Rank Math redirection. Requires confirm: true. By default the row is moved to "trashed" status; set force: true to remove it permanently.', 'rank-math-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Redirection id.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed.',
						),
						'force'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = delete permanently, false (default) = move to trash.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (previous status) and "new" ("trashed" or "deleted").',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( Plugin::class, 'permission_redirections' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function table() {
		return Plugin::table_or_null( 'rank_math_redirections' );
	}

	private static function format_row( $row ): array {
		$sources = maybe_unserialize( $row->sources );
		return array(
			'id'            => (int) $row->id,
			'sources'       => is_array( $sources ) ? array_values( $sources ) : array(),
			'url_to'        => $row->url_to,
			'header_code'   => (int) $row->header_code,
			'status'        => $row->status,
			'hits'          => (int) $row->hits,
			'created'       => $row->created,
			'updated'       => $row->updated,
			'last_accessed' => $row->last_accessed ?? null,
		);
	}

	private static function fetch_row( string $table, int $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Rank Math caches source→redirection lookups; drop entries for a
	 * redirection so edits take effect immediately.
	 */
	private static function bust_cache( int $redirection_id ): void {
		global $wpdb;
		$cache = Plugin::table_or_null( 'rank_math_redirections_cache' );
		if ( $cache ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$cache} WHERE redirection_id = %d", $redirection_id ) );
		}
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		global $wpdb;

		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'any';
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$where  = array();
		$params = array();
		if ( 'any' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$where[]  = 'url_to LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $params ) : "SELECT COUNT(*) FROM {$table}" );

		$offset   = ( $page - 1 ) * $per_page;
		$params2  = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $params2 ) );

		return array(
			'total'        => $total,
			'redirections' => array_map( array( __CLASS__, 'format_row' ), (array) $rows ),
		);
	}

	public static function cb_get( $input ) {
		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		$row = self::fetch_row( $table, (int) $input['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'redirection_not_found', __( 'No redirection exists with the given id.', 'rank-math-mcp-ability' ) );
		}
		return self::format_row( $row );
	}

	public static function cb_create( $input ) {
		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		global $wpdb;

		$header_code = isset( $input['header_code'] ) ? (int) $input['header_code'] : 301;
		$url_to      = isset( $input['url_to'] ) ? esc_url_raw( (string) $input['url_to'] ) : '';
		if ( '' === $url_to && ! in_array( $header_code, array( 410, 451 ), true ) ) {
			return new \WP_Error( 'missing_url_to', __( 'url_to is required unless header_code is 410 or 451.', 'rank-math-mcp-ability' ) );
		}

		$sources = array(
			array(
				'pattern'    => sanitize_text_field( (string) $input['source'] ),
				'comparison' => isset( $input['comparison'] ) ? (string) $input['comparison'] : 'exact',
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'sources'     => maybe_serialize( $sources ),
				'url_to'      => in_array( $header_code, array( 410, 451 ), true ) ? '' : $url_to,
				'header_code' => $header_code,
				'hits'        => 0,
				'status'      => isset( $input['status'] ) ? (string) $input['status'] : 'active',
				'created'     => current_time( 'mysql' ),
				'updated'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'insert_failed', __( 'The redirection could not be created.', 'rank-math-mcp-ability' ) );
		}

		$row = self::fetch_row( $table, (int) $wpdb->insert_id );
		return self::format_row( $row );
	}

	public static function cb_update( $input ) {
		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		global $wpdb;

		$id  = (int) $input['id'];
		$row = self::fetch_row( $table, $id );
		if ( ! $row ) {
			return new \WP_Error( 'redirection_not_found', __( 'No redirection exists with the given id.', 'rank-math-mcp-ability' ) );
		}
		$old = self::format_row( $row );

		$data = array( 'updated' => current_time( 'mysql' ) );
		if ( isset( $input['source'] ) || isset( $input['comparison'] ) ) {
			$sources         = array(
				array(
					'pattern'    => isset( $input['source'] ) ? sanitize_text_field( (string) $input['source'] ) : ( $old['sources'][0]['pattern'] ?? '' ),
					'comparison' => isset( $input['comparison'] ) ? (string) $input['comparison'] : ( $old['sources'][0]['comparison'] ?? 'exact' ),
				),
			);
			$data['sources'] = maybe_serialize( $sources );
		}
		if ( isset( $input['url_to'] ) ) {
			$data['url_to'] = esc_url_raw( (string) $input['url_to'] );
		}
		if ( isset( $input['header_code'] ) ) {
			$data['header_code'] = (int) $input['header_code'];
		}
		if ( isset( $input['status'] ) ) {
			$data['status'] = (string) $input['status'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, $data, array( 'id' => $id ) );
		self::bust_cache( $id );

		return array(
			'old' => $old,
			'new' => self::format_row( self::fetch_row( $table, $id ) ),
		);
	}

	public static function cb_bulk_update_status( $input ) {
		$dry_run = ! empty( $input['dry_run'] );
		if ( ! $dry_run && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true for a real run, or use dry_run: true to preview.', 'rank-math-mcp-ability' ) );
		}

		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		global $wpdb;

		$from  = (string) $input['from_status'];
		$to    = (string) $input['to_status'];
		$limit = isset( $input['limit'] ) ? min( 200, max( 1, (int) $input['limit'] ) ) : 50;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", $from, $limit ) );
		$ids = array_map( 'intval', $ids );

		if ( ! $dry_run && ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated = %s WHERE id IN ({$placeholders})", array_merge( array( $to, current_time( 'mysql' ) ), $ids ) ) );
			foreach ( $ids as $id ) {
				self::bust_cache( $id );
			}
		}

		return array(
			'dry_run' => $dry_run,
			'matched' => count( $ids ),
			'changed' => $dry_run ? 0 : count( $ids ),
			'ids'     => $ids,
		);
	}

	public static function cb_get_stats() {
		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) as c FROM {$table} GROUP BY status" );
		$by_status = array(
			'active'   => 0,
			'inactive' => 0,
			'trashed'  => 0,
		);
		foreach ( (array) $counts as $row ) {
			$by_status[ $row->status ] = (int) $row->c;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top = $wpdb->get_results( "SELECT id, url_to, hits FROM {$table} WHERE status != 'trashed' ORDER BY hits DESC LIMIT 10" );

		return array(
			'by_status' => $by_status,
			'top_hits'  => array_map(
				static function ( $row ) {
					return array(
						'id'     => (int) $row->id,
						'url_to' => $row->url_to,
						'hits'   => (int) $row->hits,
					);
				},
				(array) $top
			),
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a redirection.', 'rank-math-mcp-ability' ) );
		}

		$table = self::table();
		if ( ! $table ) {
			return Plugin::module_inactive_error( 'redirections' );
		}
		global $wpdb;

		$id  = (int) $input['id'];
		$row = self::fetch_row( $table, $id );
		if ( ! $row ) {
			return new \WP_Error( 'redirection_not_found', __( 'No redirection exists with the given id.', 'rank-math-mcp-ability' ) );
		}

		$force = ! empty( $input['force'] );
		if ( $force ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'status' => 'trashed', 'updated' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		}
		self::bust_cache( $id );

		return array(
			'id'  => $id,
			'old' => $row->status,
			'new' => $force ? 'deleted' : 'trashed',
		);
	}
}
