<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Support;

/**
 * Outbound compliance for marketing sends.
 *
 * Two protections, and one rule that matters more than either: this governs
 * *marketing* only. Someone chatting at 11pm still gets an instant answer —
 * throttling a conversational reply is not compliance, it is a broken product.
 *
 * 1. Quiet hours. Anything due outside the window is postponed to the next
 *    opening, never dropped and never counted as a failure.
 * 2. Per-channel daily caps, so one aggressive campaign cannot burn a
 *    WhatsApp number's quality rating or a domain's sending reputation in a
 *    single afternoon.
 *
 * The window maths is pure and static so it can be tested without WordPress.
 */
final class SendGovernor {

	/**
	 * Parse "HH:MM-HH:MM" into minutes-of-day. Overnight windows (20:00-06:00)
	 * are supported. Returns null when malformed.
	 *
	 * @return array{0:int, 1:int}|null
	 */
	public static function parseWindow( string $window ): ?array {
		if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $window, $m ) ) {
			return null;
		}

		$startHour = (int) $m[1];
		$startMin  = (int) $m[2];
		$endHour   = (int) $m[3];
		$endMin    = (int) $m[4];

		if ( $startHour > 23 || $endHour > 23 || $startMin > 59 || $endMin > 59 ) {
			return null;
		}

		return [ $startHour * 60 + $startMin, $endHour * 60 + $endMin ];
	}

	/** Is a local timestamp inside the window? A malformed window never blocks. */
	public static function isWithinWindow( int $timestamp, string $window ): bool {
		$parsed = self::parseWindow( $window );

		if ( null === $parsed ) {
			return true;
		}

		[ $start, $end ] = $parsed;
		$minute          = ( (int) gmdate( 'G', $timestamp ) ) * 60 + (int) gmdate( 'i', $timestamp );

		if ( $start === $end ) {
			return true;
		}

		return $start < $end
			? ( $minute >= $start && $minute < $end )
			: ( $minute >= $start || $minute < $end );
	}

	/** Next timestamp at or after $timestamp when the window is open. */
	public static function nextWindowStart( int $timestamp, string $window ): int {
		$parsed = self::parseWindow( $window );

		if ( null === $parsed || self::isWithinWindow( $timestamp, $window ) ) {
			return $timestamp;
		}

		[ $start ] = $parsed;
		$midnight  = (int) strtotime( gmdate( 'Y-m-d 00:00:00', $timestamp ) );
		$openToday = $midnight + $start * 60;

		return $timestamp < $openToday ? $openToday : $openToday + 86400;
	}

	/* ------------------------------------------------------- WordPress API */

	public static function windowOpen(): bool {
		if ( ! get_option( 'vmsos_send_window_enabled', false ) ) {
			return true;
		}

		return self::isWithinWindow(
			(int) current_time( 'timestamp' ),
			(string) get_option( 'vmsos_send_window', '09:00-21:00' )
		);
	}

	/** Seconds until the window reopens, floored at a minute to avoid tight loops. */
	public static function secondsUntilOpen(): int {
		$now = (int) current_time( 'timestamp' );

		return max(
			60,
			self::nextWindowStart( $now, (string) get_option( 'vmsos_send_window', '09:00-21:00' ) ) - $now
		);
	}

	/* ------------------------------------------------------------- Caps */

	private static function capOption( string $channel ): string {
		return 'vmsos_daily_cap_' . sanitize_key( $channel );
	}

	private static function counterOption( string $channel ): string {
		return 'vmsos_daily_count_' . sanitize_key( $channel );
	}

	public static function cap( string $channel ): int {
		$defaults = [ 'email' => 500, 'whatsapp' => 200 ];

		return (int) get_option( self::capOption( $channel ), $defaults[ $channel ] ?? 0 );
	}

	public static function sentToday( string $channel ): int {
		$counter = get_option( self::counterOption( $channel ), [] );
		$today   = (string) current_time( 'Y-m-d' );

		return ( is_array( $counter ) && ( $counter['date'] ?? '' ) === $today ) ? (int) $counter['count'] : 0;
	}

	/** A cap of zero means unlimited. */
	public static function canSend( string $channel ): bool {
		$cap = self::cap( $channel );

		return $cap <= 0 || self::sentToday( $channel ) < $cap;
	}

	public static function recordSend( string $channel ): void {
		$today   = (string) current_time( 'Y-m-d' );
		$counter = get_option( self::counterOption( $channel ), [] );

		if ( ! is_array( $counter ) || ( $counter['date'] ?? '' ) !== $today ) {
			$counter = [ 'date' => $today, 'count' => 0 ];
		}

		$counter['count'] = (int) $counter['count'] + 1;
		update_option( self::counterOption( $channel ), $counter, false );
	}

	public static function remainingToday( string $channel ): int {
		$cap = self::cap( $channel );

		return $cap <= 0 ? PHP_INT_MAX : max( 0, $cap - self::sentToday( $channel ) );
	}

	/**
	 * One call that answers "may I send this marketing message right now?".
	 *
	 * @return array{allowed:bool, reason:string, retry_after:int}
	 */
	public static function check( string $channel ): array {
		if ( ! self::windowOpen() ) {
			return [ 'allowed' => false, 'reason' => 'quiet_hours', 'retry_after' => self::secondsUntilOpen() ];
		}

		if ( ! self::canSend( $channel ) ) {
			// Retry after the next local midnight, when the counter rolls over.
			$now      = (int) current_time( 'timestamp' );
			$midnight = (int) strtotime( gmdate( 'Y-m-d 00:00:00', $now ) ) + 86400;

			return [ 'allowed' => false, 'reason' => 'daily_cap', 'retry_after' => max( 60, $midnight - $now ) ];
		}

		return [ 'allowed' => true, 'reason' => '', 'retry_after' => 0 ];
	}
}
