<?php
/**
 * Resolves the URLs a project's HTML and CSS use.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * One policy for every reference in a project:
 * - files in the ZIP become tokens (`aiscs-asset:<encoded path>`) that are swapped for data
 *   URIs in previews and for Media Library or uploads URLs when the draft is built;
 * - absolute http(s) URLs stay as written (the visitor's browser loads them; the server never
 *   requests them);
 * - `data:` URLs are kept only for images and fonts;
 * - anything else (javascript:, vbscript:, other schemes, missing files) is dropped and reported.
 */
final class AssetRefs {

	public const TOKEN = 'aiscs-asset:';

	/** Hosts whose stylesheets may be loaded on the page (web fonts and public CDNs). */
	public const STYLESHEET_HOSTS = array( 'fonts.googleapis.com', 'fonts.bunny.net', 'use.typekit.net', 'p.typekit.net', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'use.fontawesome.com', 'kit.fontawesome.com', 'ka-f.fontawesome.com' );

	/**
	 * Assets used: path => kind.
	 *
	 * @var array<string, string>
	 */
	private array $used = array();

	/** @var array<string, string> reference => referring file */
	private array $missing = array();

	/** @var string[] */
	private array $external = array();

	/** @var string[] Allowed external stylesheets (in order). */
	private array $stylesheets = array();

	/** @var string[] */
	private array $refused = array();

	/** @var array<string, string> project page path => first referring file */
	private array $pages = array();

	/**
	 * Constructor.
	 *
	 * @param ProjectArchive $archive Archive.
	 * @param string         $entry   Entry HTML path (links to it become in-page links).
	 */
	public function __construct( private readonly ProjectArchive $archive, private readonly string $entry ) {}

	/**
	 * Map a reference found in a file.
	 *
	 * @param string $from    Referring file.
	 * @param string $ref     Reference as written.
	 * @param string $context "asset" (images, fonts, media), "link" (a/area href) or "stylesheet".
	 * @return string|null New value, or null to remove it.
	 */
	public function map( string $from, string $ref, string $context = 'asset' ): ?string {
		$ref = trim( html_entity_decode( $ref, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $ref || preg_match( '/[\x00-\x08\x0E-\x1F\x7F]/', $ref ) ) {
			return null;
		}
		if ( str_starts_with( $ref, '#' ) ) {
			return 'link' === $context ? $ref : null;
		}
		if ( str_starts_with( $ref, self::TOKEN ) ) {
			return null; // Never trust a token written in the source.
		}
		$scheme = preg_match( '/^([a-z][a-z0-9+.-]*):/i', $ref, $m ) ? strtolower( $m[1] ) : '';
		if ( str_starts_with( $ref, '//' ) ) {
			$ref    = 'https:' . $ref;
			$scheme = 'https';
		}
		if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
			if ( ! filter_var( $ref, FILTER_VALIDATE_URL ) ) {
				$this->refused[] = $ref;
				return null;
			}
			if ( 'stylesheet' === $context ) {
				$host = strtolower( (string) parse_url( $ref, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parse, no WordPress dependency in tests.
				if ( in_array( $host, self::STYLESHEET_HOSTS, true ) && 'https' === $scheme ) {
					$this->stylesheets[] = $ref;
					return $ref;
				}
				$this->refused[] = $ref;
				return null;
			}
			$this->external[] = $ref;
			return $ref;
		}
		if ( in_array( $scheme, array( 'mailto', 'tel', 'sms' ), true ) ) {
			return 'link' === $context ? $ref : null;
		}
		if ( 'data' === $scheme ) {
			if ( 'link' !== $context && preg_match( '#^data:(image/(png|jpe?g|gif|webp|avif|svg\+xml)|font/[a-z0-9]+|application/(font-woff2?|x-font-[a-z]+|vnd\.ms-fontobject))[;,]#i', $ref ) ) {
				return $ref;
			}
			$this->refused[] = mb_substr( $ref, 0, 40 ) . '…';
			return null;
		}
		if ( '' !== $scheme ) {
			$this->refused[] = mb_substr( $ref, 0, 80 );
			return null; // javascript:, vbscript:, file:, …
		}

		// Relative or root-relative: a file in the project.
		$fragment = '';
		$path_ref = $ref;
		if ( false !== ( $p = strcspn( $ref, '?#' ) ) && $p < strlen( $ref ) ) {
			$fragment = str_contains( $ref, '#' ) ? substr( $ref, (int) strpos( $ref, '#' ) ) : '';
			$path_ref = substr( $ref, 0, $p );
		}
		$path = $this->archive->find( ProjectArchive::resolve( $from, $path_ref ) );
		if ( null === $path ) {
			if ( 'link' === $context && '' === $path_ref && '' !== $fragment ) {
				return $fragment;
			}
			$this->missing[ $ref ] = $from;
			return null;
		}
		$kind = $this->archive->files()[ $path ]['kind'];
		if ( 'link' === $context ) {
			if ( 'html' === $kind ) {
				if ( $path === $this->entry ) {
					return '' !== $fragment ? $fragment : '#';
				}
				$this->pages[ $path ] ??= $from;
				return '#';
			}
			if ( in_array( $kind, array( 'image', 'svg' ), true ) ) {
				$this->used[ $path ] = $kind;
				return self::token( $path );
			}
			return '#';
		}
		if ( 'stylesheet' === $context ) {
			return 'css' === $kind ? $path : null;
		}
		if ( ! in_array( $kind, array( 'image', 'svg', 'font' ), true ) ) {
			$this->refused[] = $path;
			return null;
		}
		$this->used[ $path ] = $kind;
		return self::token( $path );
	}

	/**
	 * Token for a project file.
	 *
	 * @param string $path Normalised path.
	 */
	public static function token( string $path ): string {
		return self::TOKEN . rawurlencode( $path );
	}

	/**
	 * Replace every token in a text.
	 *
	 * @param string                    $text Text.
	 * @param callable(string): string $map  Receives the path; returns the URL ("" removes it).
	 */
	public static function replaceTokens( string $text, callable $map ): string {
		if ( ! str_contains( $text, self::TOKEN ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'/' . preg_quote( self::TOKEN, '/' ) . '([A-Za-z0-9%._~-]+)/',
			static fn ( array $m ): string => $map( rawurldecode( $m[1] ) ),
			$text
		);
	}

	/**
	 * Assets in use: path => kind.
	 *
	 * @return array<string, string>
	 */
	public function used(): array {
		return $this->used;
	}

	/**
	 * Report: missing files, external URLs, refused references, other project pages, stylesheets.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$missing = array();
		foreach ( $this->missing as $ref => $from ) {
			$missing[] = array(
				'ref'  => mb_substr( $ref, 0, 200 ),
				'from' => $from,
			);
		}
		return array(
			'missing'     => $missing,
			'external'    => array_values( array_unique( array_map( static fn ( string $u ): string => mb_substr( $u, 0, 300 ), $this->external ) ) ),
			'refused'     => array_values( array_unique( $this->refused ) ),
			'pages'       => array_keys( $this->pages ),
			'stylesheets' => array_values( array_unique( $this->stylesheets ) ),
		);
	}

	/**
	 * Allowed external stylesheets.
	 *
	 * @return string[]
	 */
	public function stylesheets(): array {
		return array_values( array_unique( $this->stylesheets ) );
	}
}
