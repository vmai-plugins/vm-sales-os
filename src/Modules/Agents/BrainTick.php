<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Agents;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\Message;
use VMStudio\SalesOS\AI\ProviderManager;
use VMStudio\SalesOS\AI\UsageMeter;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Support\Logger;

/**
 * The hourly strategic pass. It reads what actually happened — lead flow,
 * conversation volume, AI spend — and writes back one directive that the sales
 * agent's prompt picks up. This is the difference between a chatbot and
 * something that adapts.
 */
final class BrainTick {

	private const OPTION = 'vmsos_brain_directive';

	public function __construct(
		private ProviderManager $ai,
		private ContactRepository $contacts,
		private UsageMeter $meter,
		private Logger $logger
	) {}

	public function run(): void {
		$snapshot = $this->snapshot();

		try {
			$response = $this->ai->chat(
				new ChatRequest(
					[
						Message::system( 'You advise a small sales team. Reply with JSON only.' ),
						Message::user(
							"This week's numbers:\n" . wp_json_encode( $snapshot ) .
							"\n\nReturn JSON: {\"insight\": \"one sentence on what the numbers show\", \"directive\": \"one instruction for the sales assistant to follow this hour\", \"confidence\": \"low|medium|high\"}"
						),
					],
					temperature: 0.2,
					maxTokens: 300,
					jsonMode: true,
					operation: 'brain'
				)
			);

			$json = $response->json();
			if ( ! is_array( $json ) || empty( $json['directive'] ) ) {
				return;
			}

			update_option(
				self::OPTION,
				[
					'insight'    => sanitize_text_field( (string) ( $json['insight'] ?? '' ) ),
					'directive'  => sanitize_text_field( (string) $json['directive'] ),
					'confidence' => sanitize_key( (string) ( $json['confidence'] ?? 'low' ) ),
					'snapshot'   => $snapshot,
					'updated_at' => time(),
				],
				false
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'brain', 'Strategic tick skipped', [ 'error' => $e->getMessage() ] );
		}
	}

	/** @return array<string, mixed> */
	private function snapshot(): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		return [
			'contacts_by_stage' => $this->contacts->stageCounts(),
			'new_contacts_7d'   => (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'vmsos_contacts` WHERE created_at >= %s', $since )
			),
			'conversations_7d'  => (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'vmsos_conversations` WHERE created_at >= %s', $since )
			),
			'ai_usage_7d'       => $this->meter->summary( 7 ),
		];
	}

	/** @return array<string, mixed> */
	public static function directive(): array {
		return (array) get_option( self::OPTION, [] );
	}
}
