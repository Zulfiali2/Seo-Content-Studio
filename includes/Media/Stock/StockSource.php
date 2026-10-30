<?php
/**
 * A free stock image library.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media\Stock;

use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Search returns normalised results:
 * id, thumb, width, height, title, author, author_url, source_url, license, license_url.
 * find() returns one result plus `download` (the file URL), looked up again on the server so a
 * browser can never choose the URL the server downloads.
 */
interface StockSource {

	/** Copyright-free: public domain or CC0 only. */
	public const COPYRIGHT_FREE = 'copyright_free';

	/** Royalty-free: free to use under the library's own licence. */
	public const ROYALTY_FREE = 'royalty_free';

	/**
	 * Id (also the credential owner).
	 */
	public function id(): string;

	/**
	 * Name shown to users.
	 */
	public function label(): string;

	/**
	 * COPYRIGHT_FREE or ROYALTY_FREE.
	 */
	public function kind(): string;

	/**
	 * Whether an API key is needed.
	 */
	public function needsKey(): bool;

	/**
	 * Ready to search.
	 */
	public function isConfigured(): bool;

	/**
	 * Page where a free API key is created ('' when none is needed).
	 */
	public function keyUrl(): string;

	/**
	 * Search.
	 *
	 * @param string $query  Search words.
	 * @param string $aspect 3:2 | 2:3 | 1:1.
	 * @return array<int, array<string, mixed>>
	 * @throws ProviderException On failure.
	 */
	public function search( string $query, string $aspect ): array;

	/**
	 * One image with its download URL, or null when it no longer exists.
	 *
	 * @param string $id Image id from search().
	 * @return array<string, mixed>|null
	 * @throws ProviderException On failure.
	 */
	public function find( string $id ): ?array;
}
