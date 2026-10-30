<?php
/**
 * SSRF protection for user-influenced outbound URLs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Validates URLs before the server requests them.
 *
 * Rules: http/https only; no embedded credentials; host must resolve; every
 * resolved address must be public unless private networks are explicitly allowed;
 * only standard web ports (plus any port when private networks are allowed, for
 * local model servers). Callers must disable redirects and re-validate each hop.
 */
class UrlValidator {

	/**
	 * DNS resolver, injectable for tests.
	 *
	 * @var callable(string): string[]
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param callable(string): string[]|null $resolver Returns IPs for a hostname.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ?? array( self::class, 'resolve' );
	}

	/**
	 * Validate a URL. Returns null when valid or an error code string.
	 *
	 * @param string        $url           URL.
	 * @param bool          $allow_private Allow private/loopback targets (admin opt-in for local LLMs).
	 * @param string[]|null $ips           Set to the checked addresses of a hostname (empty for IP
	 *                                     literals), so the caller can connect to exactly those.
	 */
	public function validate( string $url, bool $allow_private = false, ?array &$ips = null ): ?string {
		$ips = array();
		if ( strlen( $url ) > 2048 ) {
			return 'url_too_long';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return 'url_malformed';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return 'url_scheme';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'url_credentials';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! $allow_private && ! in_array( $port, array( 80, 443, 8080, 8443 ), true ) ) {
			return 'url_port';
		}

		$host     = strtolower( trim( $parts['host'], '[]' ) );
		$resolved = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$checked = array( $host );
		} else {
			if ( ! $allow_private && ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) || ! str_contains( $host, '.' ) ) ) {
				return 'url_private';
			}
			$checked  = ( $this->resolver )( $host );
			$resolved = $checked;
		}

		if ( empty( $checked ) ) {
			return 'url_unresolvable';
		}
		if ( ! $allow_private ) {
			foreach ( $checked as $ip ) {
				if ( ! self::isPublicIp( $ip ) ) {
					return 'url_private';
				}
			}
		}
		$ips = array_values( $resolved );
		return null;
	}

	/**
	 * Whether an IP address is publicly routable.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function isPublicIp( string $ip ): bool {
		$bin = @inet_pton( trim( $ip, '[]' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $bin ) {
			return false;
		}
		if ( 16 === strlen( $bin ) ) {
			// IPv6 forms that carry an IPv4 address are judged by that address, whatever their
			// notation (::ffff:7f00:1 and ::ffff:127.0.0.1 are both 127.0.0.1):
			// IPv4-mapped ::ffff:0:0/96, IPv4-compatible ::/96 (includes :: and ::1) and 6to4 2002::/16.
			$zero = str_repeat( "\0", 10 );
			if ( str_starts_with( $bin, $zero ) && in_array( substr( $bin, 10, 2 ), array( "\xff\xff", "\0\0" ), true ) ) {
				return self::isPublicIp( (string) inet_ntop( substr( $bin, 12, 4 ) ) );
			}
			if ( str_starts_with( $bin, "\x20\x02" ) ) {
				return self::isPublicIp( (string) inet_ntop( substr( $bin, 2, 4 ) ) );
			}
		}
		$ip = (string) inet_ntop( $bin );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		// Ranges not covered by PHP's flags on all versions, plus multicast, Teredo and NAT64.
		$blocked = array( '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001::/32', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '::ffff:0:0:0/96' );
		foreach ( $blocked as $cidr ) {
			if ( self::inCidr( $ip, $cidr ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * CIDR membership for IPv4 and IPv6.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr Range.
	 */
	public static function inCidr( string $ip, string $cidr ): bool {
		[ $subnet, $bits ] = explode( '/', $cidr );
		$ip_bin            = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		$net_bin           = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( ( 0xFF << ( 8 - $rem ) ) & 0xFF );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}

	/**
	 * Resolve A and AAAA records.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		$ips     = array();
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failures handled below.
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				} elseif ( isset( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
		if ( empty( $ips ) ) {
			$v4  = gethostbynamel( $host );
			$ips = is_array( $v4 ) ? $v4 : array();
		}
		return array_values( array_unique( $ips ) );
	}
}
