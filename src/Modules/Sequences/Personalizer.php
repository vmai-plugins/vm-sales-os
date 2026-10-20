<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Sequences;

use VMStudio\SalesOS\AI\AiException;
use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\Support\Logger;

/**
 * Merge tags first, AI second.
 *
 * The AI pass is opt-in per step and never load-bearing: if it fails or
 * returns something suspicious, the merged template goes out unchanged. An
 * outreach email that silently becomes an error message is worse than a
 * generic one.
 */
final class Personalizer {

	public function __construct( private ProviderManager $ai, private Logger $logger ) {}

	/**
	 * @param array<string, mixed> $contact Contact row.
	 */
	public function merge( string $text, array $contact ): string {
		$first = trim( (string) ( $contact['first_name'] ?? '' ) );
		$last  = trim( (string) ( $contact['last_name'] ?? '' ) );

		$tags = [
			'{{first_name}}'   => '' !== $first ? $first : __( 'there', 'vm-sales-os' ),
			'{{last_name}}'    => $last,
			'{{full_name}}'    => trim( $first . ' ' . $last ),
			'{{email}}'        => (string) ( $contact['email'] ?? '' ),
			'{{phone}}'        => (string) ( $contact['phone'] ?? '' ),
			'{{requirements}}' => (string) ( $contact['requirements'] ?? '' ),
			'{{stage}}'        => (string) ( $contact['stage'] ?? '' ),
			'{{business}}'     => (string) get_bloginfo( 'name' ),
			'{{site_url}}'     => home_url(),
			'{{sender}}'       => (string) get_option( 'vmsos_sender_name', get_bloginfo( 'name' ) ),
		];

		/**
		 * Add your own merge tags.
		 *
		 * @param array<string, string> $tags    Tag map.
		 * @param array<string, mixed>  $contact Contact row.
		 */
		$tags = (array) apply_filters( 'vmsos_merge_tags', $tags, $contact );

		return strtr( $text, $tags );
	}

	/**
	 * Rewrite the opening so it reads like it was written for this person.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 */
	public function personalise( string $body, array $contact ): string {
		$requirements = trim( (string) ( $contact['requirements'] ?? '' ) );

		if ( '' === $requirements ) {
			return $body;
		}

		$system = 'You adapt a sales email to one recipient. Keep the structure, offer and length. '
			. 'Rewrite only what needs to change so it speaks to their stated need. '
			. 'Do not invent facts, prices, dates or names. Do not add a subject line, greeting block or signature. '
			. 'Return the email body only, nothing else.';

		$prompt = "Recipient need:\n" . mb_substr( $requirements, 0, 800 ) . "\n\nEmail body:\n" . $body;

		try {
			$response = $this->ai->chat(
				new ChatRequest(
					[ Message::system( $system ), Message::user( $prompt ) ],
					temperature: 0.5,
					maxTokens: 700,
					operation: 'sequence_personalise'
				)
			);
		} catch ( AiException $e ) {
			$this->logger->error( 'sequences', 'Personalisation failed, sending template', [ 'error' => $e->getMessage() ] );
			return $body;
		}

		$rewritten = trim( $response->content );

		// Guard against a truncated or runaway rewrite.
		if ( '' === $rewritten || mb_strlen( $rewritten ) < mb_strlen( $body ) / 3 || mb_strlen( $rewritten ) > mb_strlen( $body ) * 3 ) {
			return $body;
		}

		return $rewritten;
	}
}
