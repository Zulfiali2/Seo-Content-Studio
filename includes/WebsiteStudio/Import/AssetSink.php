<?php
/**
 * Where imported assets are stored.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Stores validated asset bytes and returns their public URL. The WordPress implementation is
 * MediaAssetSink; tests use an in-memory one.
 */
interface AssetSink {

	/**
	 * Store an image in the Media Library (or reuse an identical one imported before).
	 *
	 * @param string $bytes Validated image bytes.
	 * @param string $ext   Extension.
	 * @param string $name  Original file name.
	 * @param string $alt   Alternative text.
	 * @param string $key   Project key (recorded on the attachment).
	 * @param bool   $reuse Reuse an identical image imported before.
	 * @return array{id: int, url: string, reused: bool}
	 * @throws ImportException When WordPress refuses the file.
	 */
	public function image( string $bytes, string $ext, string $name, string $alt, string $key, bool $reuse ): array;

	/**
	 * Store a font file and return its URL.
	 *
	 * @param string $bytes Validated font bytes.
	 * @param string $ext   Extension.
	 * @param string $key   Project key.
	 * @return string URL.
	 * @throws ImportException When the file cannot be written.
	 */
	public function font( string $bytes, string $ext, string $key ): string;
}
