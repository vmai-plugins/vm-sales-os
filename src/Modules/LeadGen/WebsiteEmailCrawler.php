<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LeadGen;

/**
 * High-speed crawler for extracting public contact emails and social media profiles
 * from business websites.
 */
final class WebsiteEmailCrawler {

	/**
	 * Domains or extensions to ignore as invalid emails or tracking artifacts.
	 *
	 * @var array<int, string>
	 */
	private const BLACKLISTED_EXTENSIONS = [
		'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'css', 'js', 'woff', 'woff2', 'ttf', 'eot',
	];

	private const BLACKLISTED_DOMAINS = [
		'example.com', 'domain.com', 'yoursite.com', 'email.com', 'mysite.com',
		'sentry.io', 'wixpress.com', 'w3.org', 'schema.org', 'google.com',
		'wordpress.org', 'cloudflare.com', 'gravatar.com', 'github.com',
	];

	/**
	 * Crawl a website URL to extract emails and social media profiles.
	 *
	 * @param string $url Website URL.
	 * @param int $timeoutSec Request timeout in seconds.
	 * @return array{emails: array<int, string>, primary_email: string, social: array<string, string>}
	 */
	public function crawl( string $url, int $timeoutSec = 4 ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return [
				'emails'        => [],
				'primary_email' => '',
				'social'        => [],
			];
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$parsedDomain = (string) wp_parse_url( $url, PHP_URL_HOST );
		$parsedDomain = preg_replace( '/^www\./i', '', strtolower( $parsedDomain ) );

		$homepageHtml = $this->fetchHtml( $url, $timeoutSec );
		$emails       = [];
		$social       = [];

		if ( '' !== $homepageHtml ) {
			$emails = $this->extractEmailsFromHtml( $homepageHtml, $parsedDomain );
			$social = $this->extractSocialLinks( $homepageHtml );

			// If no email found on homepage, try to find a Contact or About page link.
			if ( empty( $emails ) ) {
				$contactUrl = $this->findContactPageUrl( $homepageHtml, $url );
				if ( '' !== $contactUrl && $contactUrl !== $url ) {
					$contactHtml = $this->fetchHtml( $contactUrl, $timeoutSec );
					if ( '' !== $contactHtml ) {
						$contactEmails = $this->extractEmailsFromHtml( $contactHtml, $parsedDomain );
						$emails        = array_values( array_unique( array_merge( $emails, $contactEmails ) ) );
						$social        = array_merge( $social, $this->extractSocialLinks( $contactHtml ) );
					}
				}
			}
		}

		// Sort emails prioritizing those matching the business domain.
		if ( ! empty( $emails ) && '' !== $parsedDomain ) {
			usort( $emails, function( string $a, string $b ) use ( $parsedDomain ) {
				$aMatch = str_ends_with( strtolower( $a ), '@' . $parsedDomain ) || str_ends_with( strtolower( $a ), '.' . $parsedDomain );
				$bMatch = str_ends_with( strtolower( $b ), '@' . $parsedDomain ) || str_ends_with( strtolower( $b ), '.' . $parsedDomain );
				if ( $aMatch && ! $bMatch ) {
					return -1;
				}
				if ( ! $aMatch && $bMatch ) {
					return 1;
				}
				// Prioritize common contact prefixes
				$prefixes = [ 'info@', 'contact@', 'hello@', 'support@', 'admin@', 'sales@' ];
				foreach ( $prefixes as $p ) {
					if ( str_starts_with( strtolower( $a ), $p ) && ! str_starts_with( strtolower( $b ), $p ) ) {
						return -1;
					}
					if ( ! str_starts_with( strtolower( $a ), $p ) && str_starts_with( strtolower( $b ), $p ) ) {
						return 1;
					}
				}
				return 0;
			} );
		}

		return [
			'emails'        => $emails,
			'primary_email' => $emails[0] ?? '',
			'social'        => $social,
		];
	}

	/**
	 * Fetch HTML content safely with timeout.
	 */
	private function fetchHtml( string $url, int $timeoutSec ): string {
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => $timeoutSec,
				'redirection' => 3,
				'sslverify'   => false,
				'headers'     => [
					'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 VMStudioSalesOS/1.2',
					'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );
		// Truncate to 500KB to prevent memory exhaustion on giant pages.
		return substr( $body, 0, 500000 );
	}

	/**
	 * Extract valid, sanitized emails from HTML markup.
	 *
	 * @return array<int, string>
	 */
	public function extractEmailsFromHtml( string $html, string $expectedDomain = '' ): array {
		$emails = [];

		// 1. Look for mailto: links first (highest confidence)
		if ( preg_match_all( '/href=[\'"]mailto:\s*([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})[\?\'"]/i', $html, $matches ) ) {
			foreach ( $matches[1] as $m ) {
				$cleaned = strtolower( trim( $m ) );
				if ( $this->isValidEmail( $cleaned ) ) {
					$emails[] = $cleaned;
				}
			}
		}

		// 2. Scan text for email regex
		if ( preg_match_all( '/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/i', $html, $matches ) ) {
			foreach ( $matches[1] as $m ) {
				$cleaned = strtolower( trim( $m ) );
				if ( $this->isValidEmail( $cleaned ) ) {
					$emails[] = $cleaned;
				}
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Validate email address and filter false positives.
	 */
	private function isValidEmail( string $email ): bool {
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		$parts = explode( '@', $email );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$domain = strtolower( $parts[1] );
		$ext    = pathinfo( $domain, PATHINFO_EXTENSION );

		if ( in_array( $ext, self::BLACKLISTED_EXTENSIONS, true ) ) {
			return false;
		}

		foreach ( self::BLACKLISTED_DOMAINS as $bDomain ) {
			if ( $domain === $bDomain || str_ends_with( $domain, '.' . $bDomain ) ) {
				return false;
			}
		}

		// Skip dummy placeholders
		if ( preg_match( '/^(user|admin|test|sample|name|you|info)@(example|domain|yoursite)\./i', $email ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract social media profile links from HTML.
	 *
	 * @return array<string, string>
	 */
	public function extractSocialLinks( string $html ): array {
		$social = [];
		$platforms = [
			'facebook'  => '/href=[\'"](https?:\/\/(?:www\.)?facebook\.com\/[^\'"\s>]+)[\'"]/i',
			'instagram' => '/href=[\'"](https?:\/\/(?:www\.)?instagram\.com\/[^\'"\s>]+)[\'"]/i',
			'linkedin'  => '/href=[\'"](https?:\/\/(?:www\.)?linkedin\.com\/(?:company|in)\/[^\'"\s>]+)[\'"]/i',
			'twitter'   => '/href=[\'"](https?:\/\/(?:www\.)?(?:twitter\.com|x\.com)\/[^\'"\s>]+)[\'"]/i',
			'youtube'   => '/href=[\'"](https?:\/\/(?:www\.)?youtube\.com\/(?:c\/|channel\/|user\/|@)[^\'"\s>]+)[\'"]/i',
		];

		foreach ( $platforms as $key => $pattern ) {
			if ( preg_match( $pattern, $html, $matches ) ) {
				$link = trim( $matches[1] );
				// Ignore share dialog links
				if ( ! str_contains( $link, 'sharer' ) && ! str_contains( $link, 'intent/tweet' ) && ! str_contains( $link, 'shareArticle' ) ) {
					$social[ $key ] = esc_url_raw( $link );
				}
			}
		}

		return $social;
	}

	/**
	 * Detect contact page URL from homepage links.
	 */
	private function findContactPageUrl( string $html, string $baseUrl ): string {
		if ( preg_match_all( '/<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$href = trim( $m[1] );
				$text = strtolower( trim( strip_tags( $m[2] ) ) );

				if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' ) || str_starts_with( $href, 'tel:' ) || str_starts_with( $href, 'mailto:' ) ) {
					continue;
				}

				if (
					str_contains( $text, 'contact' ) ||
					str_contains( $text, 'reach us' ) ||
					str_contains( $text, 'get in touch' ) ||
					str_contains( strtolower( $href ), 'contact' )
				) {
					// Resolve relative URL
					if ( str_starts_with( $href, 'http://' ) || str_starts_with( $href, 'https://' ) ) {
						return $href;
					}

					$parsedBase = wp_parse_url( $baseUrl );
					$scheme     = $parsedBase['scheme'] ?? 'https';
					$host       = $parsedBase['host'] ?? '';
					$origin     = $scheme . '://' . $host;

					if ( str_starts_with( $href, '/' ) ) {
						return $origin . $href;
					}

					return rtrim( $baseUrl, '/' ) . '/' . ltrim( $href, '/' );
				}
			}
		}

		return '';
	}
}
