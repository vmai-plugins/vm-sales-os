<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI;

use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\EventBus;
use VMStudio\SalesOS\Modules\Channels\VoiceClient;
use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\Modules\CRM\DealRepository;
use VMStudio\SalesOS\Modules\Knowledge\Retriever;

/**
 * Universal Tool Suite for VM Sales OS.
 * Exposes core commercial capabilities (lead capture, RAG search, calendar booking,
 * catalog lookup, quote estimation, and outbound AI telephony) to agents, webchat, and WhatsApp channels.
 */
final class UniversalTools {

	public static function register( ToolRegistry $tools, Container $container ): void {
		// 1. Lead Capture Tool
		$tools->register(
			[
				'name'        => 'capture_lead',
				'description' => 'Save or update visitor contact information (name, email, phone, company, requirements) in the Sales CRM.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'name'         => [ 'type' => 'string', 'description' => 'Full name of the contact.' ],
						'email'        => [ 'type' => 'string', 'description' => 'Email address.' ],
						'phone'        => [ 'type' => 'string', 'description' => 'Phone or WhatsApp number with country code.' ],
						'company'      => [ 'type' => 'string', 'description' => 'Company or organisation name.' ],
						'requirements' => [ 'type' => 'string', 'description' => 'Summary of what the lead wants, needs, or inquired about.' ],
					],
					'required'   => [],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				$email = sanitize_email( (string) ( $args['email'] ?? '' ) );
				$phone = (string) ( $args['phone'] ?? '' );
				$name  = sanitize_text_field( (string) ( $args['name'] ?? '' ) );

				if ( '' === $email && '' === $phone && '' === $name ) {
					return [ 'error' => 'At least a name, email, or phone number is required to save a contact.' ];
				}

				$requirements = sanitize_textarea_field( (string) ( $args['requirements'] ?? '' ) );

				/** @var ContactRepository $contacts */
				$contacts  = $container->get( ContactRepository::class );
				$contactId = $contacts->upsert(
					[
						'name'         => $name,
						'email'        => $email,
						'phone'        => $phone,
						'company'      => sanitize_text_field( (string) ( $args['company'] ?? '' ) ),
						'requirements' => $requirements,
						'source'       => (string) ( $context['channel'] ?? 'web' ),
					]
				);

				$conversationId = (int) ( $context['conversation_id'] ?? 0 );
				if ( $conversationId > 0 && $contactId > 0 ) {
					/** @var ConversationRepository $conversations */
					$conversations = $container->get( ConversationRepository::class );
					$conversations->assignContact( $conversationId, $contactId );
				}

				// Optionally create a pipeline deal if requirements exist
				$requirements = sanitize_textarea_field( (string) ( $args['requirements'] ?? '' ) );
				if ( '' !== $requirements && $container->has( DealRepository::class ) ) {
					/** @var DealRepository $deals */
					$deals     = $container->get( DealRepository::class );
					$dealTitle = '' !== $name ? sprintf( 'Deal: %s', $name ) : 'New Sales Inquiry';
					$deals->create(
						[
							'pipeline_id' => (int) get_option( 'vmsos_default_pipeline_id', 1 ),
							'contact_id'  => $contactId,
							'title'       => $dealTitle,
							'stage'       => 'new',
							'value'       => 0.0,
							'notes'       => $requirements,
						]
					);
				}

				if ( $container->has( EventBus::class ) ) {
					$container->get( EventBus::class )->dispatch(
						'lead.captured',
						[
							'contact_id'      => $contactId,
							'conversation_id' => $conversationId,
							'name'            => $name,
							'email'           => $email,
							'phone'           => $phone,
						]
					);
				}

				return [
					'ok'         => true,
					'contact_id' => $contactId,
					'message'    => 'Lead saved successfully in Sales OS CRM.',
				];
			},
			'public'
		);

		// 2. Search Knowledge Base (Hybrid Vector RAG)
		$tools->register(
			[
				'name'        => 'search_knowledge',
				'description' => 'Search the verified business knowledge base, documentation, pricing policies, and FAQs.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'query' => [ 'type' => 'string', 'description' => 'Search keyword or question to look up.' ],
					],
					'required'   => [ 'query' ],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				$query = sanitize_text_field( (string) ( $args['query'] ?? '' ) );
				if ( '' === $query ) {
					return [ 'found' => 0, 'results' => [] ];
				}

				/** @var Retriever $retriever */
				$retriever = $container->get( Retriever::class );
				$hits      = $retriever->retrieve( $query, 4 );

				$snippets = [];
				foreach ( $hits as $hit ) {
					$snippets[] = [
						'content' => mb_substr( trim( (string) $hit['content'] ), 0, 500 ),
						'source'  => $hit['source'],
					];
				}

				return [
					'found'   => count( $snippets ),
					'results' => $snippets,
				];
			},
			'public'
		);

		// 3. Book Calendar Appointment Tool
		$tools->register(
			[
				'name'        => 'book_calendar',
				'description' => 'Retrieve the official booking link for scheduling a discovery call, consultation, or product demo.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'meeting_type' => [ 'type' => 'string', 'description' => 'Type of meeting (e.g. discovery, demo, consultation).' ],
					],
					'required'   => [],
				],
			],
			static function ( array $args, array $context ): array {
				$defaultUrl = home_url( '/book/' );
				$calUrl     = (string) get_option( 'vmsos_calendar_url', $defaultUrl );

				return [
					'ok'           => true,
					'calendar_url' => $calUrl,
					'instructions' => 'Invite the visitor to pick a suitable time slot via the provided calendar link.',
				];
			},
			'public'
		);

		// 4. Query Product & Service Catalog (WooCommerce Compatible)
		$tools->register(
			[
				'name'        => 'query_catalog',
				'description' => 'Search products, packages, or services in the website catalog by keyword.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'keyword'     => [ 'type' => 'string', 'description' => 'Product title or category keyword.' ],
						'max_results' => [ 'type' => 'integer', 'description' => 'Maximum items to return (default 4).' ],
					],
					'required'   => [ 'keyword' ],
				],
			],
			static function ( array $args, array $context ): array {
				$keyword = sanitize_text_field( (string) ( $args['keyword'] ?? '' ) );
				$limit   = min( 6, max( 1, (int) ( $args['max_results'] ?? 4 ) ) );

				$postTypes = post_type_exists( 'product' ) ? [ 'product' ] : [ 'post', 'page' ];

				$posts = get_posts(
					[
						'post_type'      => $postTypes,
						'post_status'    => 'publish',
						'posts_per_page' => $limit,
						's'              => $keyword,
					]
				);

				$items = [];
				foreach ( $posts as $p ) {
					$price = get_post_meta( $p->ID, '_price', true ) ?: get_post_meta( $p->ID, 'price', true );
					$items[] = [
						'id'        => $p->ID,
						'title'     => $p->post_title,
						'price'     => $price ? (string) $price : 'Contact for quote',
						'summary'   => wp_trim_words( $p->post_excerpt ?: $p->post_content, 20 ),
						'permalink' => get_permalink( $p->ID ),
					];
				}

				return [
					'found' => count( $items ),
					'items' => $items,
				];
			},
			'public'
		);

		// 5. Calculate Deal Quote / Pricing Estimator
		$tools->register(
			[
				'name'        => 'calculate_quote',
				'description' => 'Calculate price estimates, tiered group discounts, or custom package proposals.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'base_price'       => [ 'type' => 'number', 'description' => 'Standard unit or package rate.' ],
						'quantity'         => [ 'type' => 'integer', 'description' => 'Number of units, months, or persons.' ],
						'discount_percent' => [ 'type' => 'number', 'description' => 'Requested discount percentage (max 15%).' ],
					],
					'required'   => [ 'base_price' ],
				],
			],
			static function ( array $args, array $context ): array {
				$base     = max( 0.0, (float) ( $args['base_price'] ?? 0.0 ) );
				$qty      = max( 1, (int) ( $args['quantity'] ?? 1 ) );
				$discount = min( 15.0, max( 0.0, (float) ( $args['discount_percent'] ?? 0.0 ) ) );

				$subtotal = $base * $qty;
				$savings  = $subtotal * ( $discount / 100.0 );
				$total    = $subtotal - $savings;

				return [
					'base_price'       => $base,
					'quantity'         => $qty,
					'subtotal'         => round( $subtotal, 2 ),
					'discount_percent' => $discount,
					'savings'          => round( $savings, 2 ),
					'total_quote'      => round( $total, 2 ),
					'note'             => 'Quote valid for 7 days. Official invoice will be provided upon confirmation.',
				];
			},
			'public'
		);

		// 6. Outbound Telephony & AI Voice Call
		$tools->register(
			[
				'name'        => 'request_voice_call',
				'description' => 'Initiate an outbound AI sales discovery or consultation call to a prospect on their phone number.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'phone'  => [ 'type' => 'string', 'description' => 'Target customer phone number with country code in E.164 format (e.g. +14155552671).' ],
						'name'   => [ 'type' => 'string', 'description' => 'Customer or prospect name.' ],
						'reason' => [ 'type' => 'string', 'description' => 'Brief reason or topic for the call (e.g. "pricing_inquiry", "demo_followup").' ],
					],
					'required'   => [ 'phone' ],
				],
			],
			static function ( array $args, array $context ) use ( $container ): array {
				$phone  = sanitize_text_field( (string) ( $args['phone'] ?? '' ) );
				$name   = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
				$reason = sanitize_text_field( (string) ( $args['reason'] ?? 'sales_inquiry' ) );

				if ( '' === $phone ) {
					return [ 'ok' => false, 'error' => 'A valid phone number is required.' ];
				}

				// Auto-sync contact in CRM if not already created
				if ( $container->has( ContactRepository::class ) ) {
					/** @var ContactRepository $contacts */
					$contacts  = $container->get( ContactRepository::class );
					$contacts->upsert(
						[
							'name'   => $name,
							'phone'  => $phone,
							'source' => (string) ( $context['channel'] ?? 'voice_request' ),
						]
					);
				}

				if ( ! $container->has( VoiceClient::class ) ) {
					return [
						'ok'      => false,
						'error'   => 'Voice telephony module is not initialized.',
						'message' => 'The system cannot place automated phone calls at this time. Please request a callback or use WhatsApp.',
					];
				}

				/** @var VoiceClient $voiceClient */
				$voiceClient = $container->get( VoiceClient::class );
				$callRes     = $voiceClient->call( $phone, $name, $reason );

				if ( empty( $callRes['ok'] ) ) {
					return [
						'ok'      => false,
						'error'   => $callRes['error'] ?? 'Failed to place call.',
						'message' => 'Could not initiate outbound phone call. Please verify your phone number or request an agent callback.',
					];
				}

				return [
					'ok'      => true,
					'call_id' => $callRes['call_id'] ?? '',
					'message' => sprintf( 'Outbound AI call initiated to %s. The phone should ring within a few seconds.', $phone ),
				];
			},
			'public'
		);
	}
}
