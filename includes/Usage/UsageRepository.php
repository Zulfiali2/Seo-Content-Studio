<?php
/**
 * Usage log persistence.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only usage log stored in a custom table (see Core\Migrations).
 */
class UsageRepository {

	/**
	 * Fully prefixed table name.
	 */
	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_usage_log';
	}

	/**
	 * Insert one record.
	 *
	 * @param array<string, mixed> $row Column values.
	 */
	public function record( array $row ): void {
		global $wpdb;

		$data    = array(
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'user_id'       => (int) ( $row['user_id'] ?? get_current_user_id() ),
			'operation'     => substr( (string) ( $row['operation'] ?? 'text' ), 0, 32 ),
			'purpose'       => substr( (string) ( $row['purpose'] ?? '' ), 0, 64 ),
			'provider'      => substr( (string) ( $row['provider'] ?? '' ), 0, 32 ),
			'model'         => substr( (string) ( $row['model'] ?? '' ), 0, 191 ),
			'input_tokens'  => max( 0, (int) ( $row['input_tokens'] ?? 0 ) ),
			'output_tokens' => max( 0, (int) ( $row['output_tokens'] ?? 0 ) ),
			'units'         => max( 0, (int) ( $row['units'] ?? 0 ) ),
			'currency'      => substr( (string) ( $row['currency'] ?? 'USD' ), 0, 3 ),
			'cost_source'   => in_array( $row['cost_source'] ?? '', array( 'provider', 'estimate' ), true ) ? (string) $row['cost_source'] : '',
			'status'        => substr( (string) ( $row['status'] ?? 'success' ), 0, 16 ),
			'error_code'    => substr( (string) ( $row['error_code'] ?? '' ), 0, 32 ),
			'request_hash'  => substr( (string) ( $row['request_hash'] ?? '' ), 0, 64 ),
		);
		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( isset( $row['estimated_cost'] ) && is_numeric( $row['estimated_cost'] ) ) {
			$data['estimated_cost'] = (float) $row['estimated_cost'];
			$formats[]              = '%f';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$wpdb->insert( self::tableName(), $data, $formats );
	}

	/**
	 * Count attempts of an operation since a UTC timestamp. Failed attempts count
	 * too, because they may still be billed and they still hit provider limits.
	 *
	 * @param string $operation Operation type.
	 * @param int    $since     Unix timestamp.
	 */
	public function countSince( string $operation, int $since ): int {
		global $wpdb;
		$table = self::tableName();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values prepared.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE operation = %s AND created_at >= %s", $operation, gmdate( 'Y-m-d H:i:s', $since ) ) );
	}

	/**
	 * Aggregated totals for the last N days.
	 *
	 * @param int $days Window.
	 * @return array<int, array<string, mixed>>
	 */
	public function summary( int $days ): array {
		global $wpdb;
		$table = self::tableName();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT operation, provider, model, currency,
					COUNT(*) AS requests,
					SUM(status = 'success') AS succeeded,
					SUM(input_tokens) AS input_tokens,
					SUM(output_tokens) AS output_tokens,
					SUM(units) AS units,
					SUM(estimated_cost) AS estimated_cost,
					SUM(estimated_cost IS NULL AND status = 'success') AS unpriced,
					SUM(cost_source = 'provider') AS provider_reported
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY operation, provider, model, currency
				ORDER BY operation, provider, model",
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Most recent records.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = self::tableName();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, user_id, operation, purpose, provider, model, input_tokens, output_tokens, units, estimated_cost, currency, cost_source, status, error_code FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete records older than N days.
	 *
	 * @param int $days Retention.
	 */
	public function prune( int $days ): int {
		global $wpdb;
		$table = self::tableName();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values prepared.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Average recorded cost of successful, priced calls for a purpose over the
	 * last N days (basis for pre-run estimates). Null when there is no history.
	 *
	 * @param string $operation Operation type.
	 * @param string $purpose   Purpose label.
	 * @param int    $days      Window.
	 * @param string $provider  Limit to one provider ('' = all).
	 * @return array{amount: float, currency: string, samples: int}|null
	 */
	public function averageCost( string $operation, string $purpose, int $days = 30, string $provider = '' ): ?array {
		global $wpdb;
		$table = self::tableName();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values prepared.
		$row = '' === $provider
			? $wpdb->get_row( $wpdb->prepare( "SELECT AVG(estimated_cost) AS amount, MAX(currency) AS currency, COUNT(*) AS samples FROM {$table} WHERE operation = %s AND purpose = %s AND status = 'success' AND estimated_cost IS NOT NULL AND created_at >= %s", $operation, $purpose, $since ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( "SELECT AVG(estimated_cost) AS amount, MAX(currency) AS currency, COUNT(*) AS samples FROM {$table} WHERE operation = %s AND purpose = %s AND provider = %s AND status = 'success' AND estimated_cost IS NOT NULL AND created_at >= %s", $operation, $purpose, $provider, $since ), ARRAY_A );
		// phpcs:enable
		if ( ! is_array( $row ) || (int) $row['samples'] < 1 ) {
			return null;
		}
		return array(
			'amount'   => round( (float) $row['amount'], 6 ),
			'currency' => (string) $row['currency'],
			'samples'  => (int) $row['samples'],
		);
	}
}
