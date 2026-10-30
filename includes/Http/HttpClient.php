<?php
/**
 * JSON HTTP client on top of the WordPress HTTP API.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Http;

use AiSeoContentStudio\Logging\Logger;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Security\UrlValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Sends JSON requests with timeouts, bounded retries, response size limits and
 * normalised errors. Redirects are never followed.
 */
class HttpClient {

	/** Statuses worth retrying. */
	private const RETRYABLE_STATUSES = array( 408, 409, 429, 500, 502, 503, 504, 529 );

	/**
	 * Statuses after which a POST may already have been processed (and billed), so the
	 * client does not repeat it on its own. The job runner may still retry the step once,
	 * visibly. 429, 503 and 529 mean the request was refused, so those are retried here.
	 */
	private const AMBIGUOUS_STATUSES = array( 408, 409, 500, 502, 504 );

	/**
	 * Transport (wp_remote_request signature), injectable for tests.
	 *
	 * @var callable(string, array<string, mixed>): (array<string, mixed>|\WP_Error)
	 */
	private $transport;

	/**
	 * Sleep function, injectable for tests.
	 *
	 * @var callable(int): void
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Logger        $logger           Logger.
	 * @param UrlValidator  $validator        SSRF validator for user-supplied URLs.
	 * @param callable|null $transport        Defaults to wp_remote_request.
	 * @param callable|null $sleeper          Defaults to sleep().
	 * @param int           $max_retries      Retries after the first attempt (bounded 0–5).
	 * @param int           $timeout          Per-attempt timeout in seconds.
	 * @param int           $max_response     Maximum response size in bytes.
	 * @param int           $max_total_wait   Maximum seconds spent waiting between retries.
	 */
	public function __construct(
		private readonly Logger $logger,
		private readonly UrlValidator $validator,
		?callable $transport = null,
		?callable $sleeper = null,
		private readonly int $max_retries = 2,
		private readonly int $timeout = 90,
		private readonly int $max_response = 8388608,
		private readonly int $max_total_wait = 20,
	) {
		$this->transport = $transport ?? 'wp_remote_request';
		$this->sleeper   = $sleeper ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * Send a request and decode a JSON response.
	 *
	 * Options:
	 * - provider (string): label used in error messages.
	 * - user_url (bool): URL came from user configuration; run SSRF validation.
	 * - allow_private (bool): with user_url, allow private-network targets.
	 * - retries (int): override retry count (bounded by the constructor value).
	 * - timeout (int): override per-attempt timeout.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     URL.
	 * @param array<string, string>     $headers Headers.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param array<string, mixed>      $options Options.
	 * @return array<string, mixed> Decoded JSON object.
	 * @throws ProviderException On any failure.
	 */
	public function requestJson( string $method, string $url, array $headers = array(), ?array $body = null, array $options = array() ): array {
		$provider      = (string) ( $options['provider'] ?? 'The provider' );
		$allow_private = ! empty( $options['allow_private'] );
		$pinned        = array();

		if ( ! empty( $options['user_url'] ) ) {
			$error = $this->validator->validate( $url, $allow_private, $pinned );
			if ( null !== $error ) {
				throw new ProviderException(
					/* translators: %s: validation error code. */
					sprintf( __( 'The endpoint URL is not allowed (%s). Private or local addresses require the "allow private endpoints" setting.', 'ai-seo-content-studio' ), $error ),
					ProviderException::UNSAFE_URL
				);
			}
			// API keys travel in headers, so plain http is accepted only for a server on the
			// site's own network (allowed with "allow private endpoints"), never across the internet.
			if ( 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && ! self::onlyPrivate( $url, $pinned ) ) {
				throw new ProviderException(
					__( 'The endpoint must use https://. Plain http:// is allowed only for an AI server on your own network (turn on "allow private endpoints").', 'ai-seo-content-studio' ),
					ProviderException::UNSAFE_URL
				);
			}
		}

		$args = array(
			'method'              => strtoupper( $method ),
			'headers'             => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => self::userAgent(),
				),
				null === $body ? array() : array( 'Content-Type' => 'application/json' ),
				$headers
			),
			'timeout'             => (int) ( $options['timeout'] ?? $this->timeout ),
			'redirection'         => 0,
			'limit_response_size' => (int) ( $options['max_bytes'] ?? $this->max_response ),
			'reject_unsafe_urls'  => ! $allow_private,
		);
		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				throw new ProviderException( __( 'The request could not be encoded as JSON.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
			}
			$args['body'] = $encoded;
		}

		$retries = max( 0, min( $this->max_retries, (int) ( $options['retries'] ?? $this->max_retries ), 5 ) );
		$waited  = 0;
		$attempt = 0;

		while ( true ) {
			++$attempt;
			try {
				return $this->attempt( $url, $args, $provider, $pinned );
			} catch ( ProviderException $e ) {
				if ( ! $e->retryable || $attempt > $retries ) {
					throw $e;
				}
				if ( 'GET' !== $args['method'] && in_array( $e->httpStatus, self::AMBIGUOUS_STATUSES, true ) ) {
					throw $e;
				}
				$delay = $e->retryAfter ?? ( 2 ** ( $attempt - 1 ) );
				if ( $delay < 0 || $waited + $delay > $this->max_total_wait ) {
					throw $e;
				}
				$this->logger->warning(
					'Retrying provider request',
					array(
						'provider' => $provider,
						'attempt'  => $attempt,
						'category' => $e->category,
						'delay'    => $delay,
					)
				);
				( $this->sleeper )( $delay );
				$waited += $delay;
			}
		}
	}

	/**
	 * Fetch a web page (HTML or plain text) from an untrusted URL.
	 *
	 * Every URL, including each redirect target, is SSRF-validated. Redirects are
	 * followed manually (at most $max_redirects) so each hop is re-checked. No
	 * retries: a failed page is simply skipped by callers.
	 *
	 * @param string $url           Absolute URL.
	 * @param int    $timeout       Seconds.
	 * @param int    $max_bytes     Response size limit.
	 * @param int    $max_redirects Maximum redirects to follow.
	 * @param string $accept        Accept header (images use image/*).
	 * @return array{status: int, body: string, content_type: string, final_url: string}
	 * @throws ProviderException On unsafe URLs, transport errors or too many redirects.
	 */
	public function fetchPage( string $url, int $timeout = 15, int $max_bytes = 2097152, int $max_redirects = 3, string $accept = 'text/html,application/xhtml+xml,text/plain;q=0.8' ): array {
		for ( $hop = 0; $hop <= $max_redirects; $hop++ ) {
			$pinned = array();
			$error  = $this->validator->validate( $url, false, $pinned );
			if ( null !== $error ) {
				/* translators: %s: validation error code. */
				throw new ProviderException( sprintf( __( 'The page address is not allowed (%s).', 'ai-seo-content-studio' ), $error ), ProviderException::UNSAFE_URL );
			}

			$response = $this->send(
				$url,
				$pinned,
				array(
					'method'              => 'GET',
					'headers'             => array(
						'Accept'     => $accept,
						'User-Agent' => self::userAgent(),
					),
					'timeout'             => $timeout,
					'redirection'         => 0,
					'limit_response_size' => $max_bytes,
					'reject_unsafe_urls'  => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new ProviderException( __( 'The page could not be downloaded.', 'ai-seo-content-studio' ), ProviderException::NETWORK );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 300 && $status < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
				if ( '' === $location ) {
					break;
				}
				$url = self::resolveUrl( $url, $location );
				continue;
			}

			$type = wp_remote_retrieve_header( $response, 'content-type' );
			return array(
				'status'       => $status,
				'body'         => (string) wp_remote_retrieve_body( $response ),
				'content_type' => strtolower( is_array( $type ) ? (string) reset( $type ) : (string) $type ),
				'final_url'    => $url,
			);
		}
		throw new ProviderException( __( 'The page redirected too many times.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
	}

	/**
	 * Call the transport. When the host's addresses were validated, the connection is pinned to
	 * them (cURL), so a DNS answer that changes between the check and the request (DNS
	 * rebinding) cannot send it to a private address. Other transports fall back to WordPress's
	 * own reject_unsafe_urls check.
	 *
	 * @param string               $url    URL.
	 * @param string[]             $pinned Validated addresses of the URL's host (empty = no pinning).
	 * @param array<string, mixed> $args   wp_remote_request args.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function send( string $url, array $pinned, array $args ): array|\WP_Error {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		if ( array() === $pinned || '' === $host || ! defined( 'CURLOPT_RESOLVE' ) || ! function_exists( 'add_action' ) ) {
			return ( $this->transport )( $url, $args );
		}
		$port  = (int) ( $parts['port'] ?? ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80 ) );
		$ip    = (string) reset( $pinned );
		$entry = $host . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip );
		$pin   = static function ( $handle, $request = array(), $request_url = '' ) use ( $entry, $url ): void {
			if ( $request_url === $url ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the validated address.
			}
		};
		add_action( 'http_api_curl', $pin, 10, 3 );
		try {
			return ( $this->transport )( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}
	}

	/**
	 * Whether every address of the URL's host is private or local (an IP literal is its own address).
	 *
	 * @param string   $url URL.
	 * @param string[] $ips Validated addresses of the host (empty for an IP literal).
	 */
	private static function onlyPrivate( string $url, array $ips ): bool {
		$host = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );
		if ( array() === $ips ) {
			$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : UrlValidator::resolve( $host );
		}
		if ( array() === $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( UrlValidator::isPublicIp( $ip ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * User-Agent sent with every request.
	 */
	public static function userAgent(): string {
		return 'AI-SEO-Content-Studio/' . ( defined( 'AISCS_VERSION' ) ? AISCS_VERSION : 'dev' ) . '; WordPress';
	}

	/**
	 * Resolve a (possibly relative) redirect target against the current URL.
	 *
	 * @param string $base     Current URL.
	 * @param string $location Location header.
	 */
	public static function resolveUrl( string $base, string $location ): string {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $location ) ) {
			return $location;
		}
		$parts  = wp_parse_url( $base );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $location, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
		}
		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}
		$path = $parts['path'] ?? '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $origin . ( '' === $dir ? '/' : $dir ) . $location;
	}

	/**
	 * One attempt.
	 *
	 * @param string               $url      URL.
	 * @param array<string, mixed> $args     wp_remote_request args.
	 * @param string               $provider Provider label.
	 * @param string[]             $pinned   Validated host addresses (empty = no pinning).
	 * @return array<string, mixed>
	 * @throws ProviderException On failure.
	 */
	private function attempt( string $url, array $args, string $provider, array $pinned = array() ): array {
		$response = $this->send( $url, $pinned, $args );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$this->logger->warning(
				'Provider transport error',
				array(
					'provider' => $provider,
					'error'    => $message,
				)
			);
			// Rejected by WordPress before sending: retrying cannot help.
			if ( false !== stripos( $message, 'valid URL' ) ) {
				throw new ProviderException(
					/* translators: %s: provider name. */
					sprintf( __( 'WordPress refused to connect to %s because the address is not allowed (it may resolve to a private network address or not resolve at all).', 'ai-seo-content-studio' ), $provider ),
					ProviderException::UNSAFE_URL
				);
			}
			if ( false !== stripos( $message, 'blocked' ) && defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
				throw new ProviderException(
					/* translators: %s: provider name. */
					sprintf( __( 'Outbound requests to %s are blocked by WP_HTTP_BLOCK_EXTERNAL. Add the host to WP_ACCESSIBLE_HOSTS.', 'ai-seo-content-studio' ), $provider ),
					ProviderException::NETWORK
				);
			}

			$timeout = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' );
			throw new ProviderException(
				$timeout
					/* translators: %s: provider name. */
					? sprintf( __( '%s did not respond in time. Try again, or increase the request timeout in settings.', 'ai-seo-content-studio' ), $provider )
					/* translators: %s: provider name. */
					: sprintf( __( 'Could not connect to %s. Check the server\'s outbound network access.', 'ai-seo-content-studio' ), $provider ),
				$timeout ? ProviderException::TIMEOUT : ProviderException::NETWORK,
				// A timed-out POST may still have been processed (and billed) by the provider,
				// so only reads are retried after a timeout.
				! $timeout || 'GET' === ( $args['method'] ?? 'GET' )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = '' === $raw ? null : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $json ) ) {
				throw new ProviderException(
					/* translators: %s: provider name. */
					sprintf( __( '%s returned a response that is not valid JSON.', 'ai-seo-content-studio' ), $provider ),
					ProviderException::INVALID_RESPONSE,
					false,
					$status
				);
			}
			return $json;
		}

		$this->logger->warning(
			'Provider HTTP error',
			array(
				'provider' => $provider,
				'status'   => $status,
			)
		);
		throw $this->mapError( $status, is_array( $json ) ? $json : array(), $provider, $this->retryAfter( $response ) );
	}

	/**
	 * Map an HTTP error to a ProviderException.
	 *
	 * @param int                  $status      HTTP status.
	 * @param array<string, mixed> $json        Decoded error body.
	 * @param string               $provider    Provider label.
	 * @param int|null             $retry_after Retry-After seconds.
	 */
	private function mapError( int $status, array $json, string $provider, ?int $retry_after ): ProviderException {
		$upstream = self::upstreamMessage( $json );
		$code     = strtolower( (string) ( $json['error']['code'] ?? $json['error']['type'] ?? $json['error']['status'] ?? '' ) );

		$category = match ( true ) {
			401 === $status => ProviderException::AUTH,
			403 === $status => ProviderException::PERMISSION,
			404 === $status => ProviderException::NOT_FOUND,
			402 === $status, 'insufficient_quota' === $code, 'billing_error' === $code => ProviderException::QUOTA,
			// A 429 that reports a quota "limit: 0" means the plan has no allowance for this model at all.
			429 === $status && (bool) preg_match( '/\blimit:\s*0\b/', (string) wp_json_encode( $json ) ) => ProviderException::QUOTA,
			429 === $status => ProviderException::RATE_LIMIT,
			408 === $status => ProviderException::TIMEOUT,
			$status >= 500 => ProviderException::SERVER,
			default => ProviderException::INVALID_REQUEST,
		};

		$retryable = ProviderException::QUOTA !== $category && in_array( $status, self::RETRYABLE_STATUSES, true );

		$summary = match ( $category ) {
			ProviderException::AUTH => __( 'The API key was rejected. Check that it is correct and active.', 'ai-seo-content-studio' ),
			ProviderException::PERMISSION => __( 'The API key does not have permission for this request or model.', 'ai-seo-content-studio' ),
			ProviderException::NOT_FOUND => __( 'The endpoint or model was not found. Check the model name.', 'ai-seo-content-studio' ),
			ProviderException::QUOTA => __( 'Your plan has no remaining quota for this model (on free plans some models, such as image models, have no allowance). Enable billing with the provider or choose another model.', 'ai-seo-content-studio' ),
			ProviderException::RATE_LIMIT => __( 'The provider rate limit was reached. Wait and try again.', 'ai-seo-content-studio' ),
			ProviderException::TIMEOUT => __( 'The provider timed out.', 'ai-seo-content-studio' ),
			ProviderException::SERVER => __( 'The provider had a temporary server error.', 'ai-seo-content-studio' ),
			default => __( 'The provider rejected the request.', 'ai-seo-content-studio' ),
		};

		/* translators: 1: provider name, 2: HTTP status, 3: summary. */
		$message = sprintf( __( '%1$s (HTTP %2$d): %3$s', 'ai-seo-content-studio' ), $provider, $status, $summary );
		if ( '' !== $upstream ) {
			$message .= ' ' . $upstream;
		}

		return new ProviderException( $message, $category, $retryable, $status, $retry_after );
	}

	/**
	 * Extract a short, sanitised upstream error message.
	 *
	 * @param array<string, mixed> $json Error body.
	 */
	public static function upstreamMessage( array $json ): string {
		$message = $json['error']['message'] ?? $json['message'] ?? ( is_string( $json['error'] ?? null ) ? $json['error'] : '' );
		if ( ! is_string( $message ) || '' === $message ) {
			return '';
		}
		$message = wp_strip_all_tags( $message );
		// Never echo anything that looks like a key back to the browser.
		$message = (string) preg_replace( array( '/\bsk-[A-Za-z0-9_\-]{6,}/', '/\bAIza[0-9A-Za-z_\-]{10,}/' ), '[redacted]', $message );
		return mb_substr( $message, 0, 300 );
	}

	/**
	 * Parse Retry-After (seconds form only; HTTP-date is ignored).
	 *
	 * @param array<string, mixed> $response Response.
	 */
	private function retryAfter( array $response ): ?int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		return is_numeric( $header ) ? max( 0, (int) ceil( (float) $header ) ) : null;
	}
}
