<?php
/**
 * Limits for website project imports.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults suit a typical shared host. Sites can change them with the
 * `aiscs_project_import_limits` filter (each value is clamped to a safe range).
 */
final class ImportLimits {

	/** Default => [minimum, maximum]. */
	private const LIMITS = array(
		'archive_bytes'   => array( 52428800, 1048576, 524288000 ),   // 50 MB upload.
		'extracted_bytes' => array( 209715200, 1048576, 2147483647 ), // 200 MB of files.
		'file_bytes'      => array( 20971520, 65536, 104857600 ),     // 20 MB per file.
		'entries'         => array( 3000, 10, 20000 ),
		'depth'           => array( 12, 1, 40 ),
		'ratio'           => array( 200, 10, 2000 ),                  // Compression ratio for files over 1 MB.
		'html_bytes'      => array( 3145728, 65536, 20971520 ),       // 3 MB of HTML.
		'css_bytes'       => array( 4194304, 65536, 20971520 ),       // 4 MB of CSS in total.
		'js_bytes'        => array( 2097152, 16384, 10485760 ),       // 2 MB of JavaScript in total.
		'assets'          => array( 400, 1, 5000 ),                   // Assets imported per project.
		'asset_bytes'     => array( 157286400, 1048576, 2147483647 ), // 150 MB of assets.
		'preview_bytes'   => array( 26214400, 1048576, 104857600 ),   // 25 MB of inlined preview assets.
		'nodes'           => array( 20000, 100, 100000 ),             // HTML elements.
		'image_pixels'    => array( 25000000, 1000000, 100000000 ),
		'svg_bytes'       => array( 262144, 1024, 2097152 ),          // Inlined SVG.
	);

	/**
	 * All limits.
	 *
	 * @return array<string, int>
	 */
	public static function all(): array {
		$values = array();
		foreach ( self::LIMITS as $key => $limit ) {
			$values[ $key ] = $limit[0];
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'aiscs_project_import_limits', $values );
			if ( is_array( $filtered ) ) {
				foreach ( self::LIMITS as $key => $limit ) {
					$values[ $key ] = max( $limit[1], min( $limit[2], (int) ( $filtered[ $key ] ?? $limit[0] ) ) );
				}
			}
		}
		return $values;
	}
}
