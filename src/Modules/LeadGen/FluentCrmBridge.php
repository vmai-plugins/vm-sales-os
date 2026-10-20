<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\LeadGen;

use VMStudio\SalesOS\Support\Logger;

/**
 * Native bridge to FluentCRM for B2B lead synchronization,
 * list segmentation, dynamic tag assignment, and automated marketing funnels.
 */
final class FluentCrmBridge {

	public function __construct(
		private ?Logger $logger = null
	) {}

	/**
	 * Check if FluentCRM is installed and active on this WordPress site.
	 */
	public function isActive(): bool {
		return function_exists( 'FluentCrmApi' ) || defined( 'FLUENTCRM' );
	}

	/**
	 * Get all existing FluentCRM lists.
	 *
	 * @return array<int, array{id: int, title: string, slug: string}>
	 */
	public function getLists(): array {
		if ( ! $this->isActive() ) {
			return [];
		}

		try {
			$raw = FluentCrmApi( 'lists' )->all();
			$lists = [];
			foreach ( $raw as $item ) {
				$lists[] = [
					'id'    => (int) $item->id,
					'title' => (string) ( $item->title ?? $item->slug ),
					'slug'  => (string) $item->slug,
				];
			}
			return $lists;
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( 'FluentCrmBridge getLists error: ' . $e->getMessage() );
			}
			return [];
		}
	}

	/**
	 * Create a new list in FluentCRM.
	 */
	public function createList( string $title, string $slug = '' ): int|false {
		if ( ! $this->isActive() ) {
			return false;
		}

		$title = trim( $title );
		if ( '' === $title ) {
			return false;
		}

		$slug = '' !== $slug ? sanitize_title( $slug ) : sanitize_title( $title );

		try {
			if ( class_exists( '\FluentCrm\App\Models\Lists' ) ) {
				$created = \FluentCrm\App\Models\Lists::create( [
					'title' => $title,
					'slug'  => $slug,
				] );
				return isset( $created->id ) ? (int) $created->id : false;
			}

			$listApi = FluentCrmApi( 'lists' );
			$created = $listApi->create( [
				'title' => $title,
				'slug'  => $slug,
			] );

			return isset( $created->id ) ? (int) $created->id : false;
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( 'FluentCrmBridge createList error: ' . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Get all existing FluentCRM tags.
	 *
	 * @return array<int, array{id: int, title: string, slug: string}>
	 */
	public function getTags(): array {
		if ( ! $this->isActive() ) {
			return [];
		}

		try {
			$raw = FluentCrmApi( 'tags' )->all();
			$tags = [];
			foreach ( $raw as $item ) {
				$tags[] = [
					'id'    => (int) $item->id,
					'title' => (string) ( $item->title ?? $item->slug ),
					'slug'  => (string) $item->slug,
				];
			}
			return $tags;
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( 'FluentCrmBridge getTags error: ' . $e->getMessage() );
			}
			return [];
		}
	}

	/**
	 * Create a new tag in FluentCRM.
	 */
	public function createTag( string $title, string $slug = '' ): int|false {
		if ( ! $this->isActive() ) {
			return false;
		}

		$title = trim( $title );
		if ( '' === $title ) {
			return false;
		}

		$slug = '' !== $slug ? sanitize_title( $slug ) : sanitize_title( $title );

		try {
			if ( class_exists( '\FluentCrm\App\Models\Tag' ) ) {
				$created = \FluentCrm\App\Models\Tag::create( [
					'title' => $title,
					'slug'  => $slug,
				] );
				return isset( $created->id ) ? (int) $created->id : false;
			}

			$tagApi = FluentCrmApi( 'tags' );
			$created = $tagApi->create( [
				'title' => $title,
				'slug'  => $slug,
			] );

			return isset( $created->id ) ? (int) $created->id : false;
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( 'FluentCrmBridge createTag error: ' . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Synchronize a business lead into FluentCRM.
	 *
	 * @param array<string, mixed> $leadData Lead information (name, email, phone, company, etc.)
	 * @param array<int, int> $listIds List IDs to subscribe to
	 * @param array<int, string> $tags Tag slugs or titles
	 * @param string $status 'subscribed', 'pending', etc.
	 * @return int|false FluentCRM contact ID or false on failure
	 */
	public function syncLead( array $leadData, array $listIds = [], array $tags = [], string $status = 'subscribed' ): int|false {
		if ( ! $this->isActive() ) {
			return false;
		}

		$email = sanitize_email( (string) ( $leadData['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $leadData['phone'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $leadData['name'] ?? '' ) );

		// FluentCRM requires a valid email for a subscriber record.
		// If email is missing, we can create a placeholder if configured, or return false to keep phone-only leads in VM Sales OS local CRM.
		if ( '' === $email || ! is_email( $email ) ) {
			// If phone exists, generate a deduplicated local shadow email if user wants all phone leads synced
			$autoShadow = (bool) get_option( 'vmsos_leadgen_shadow_email_sync', false );
			if ( $autoShadow && '' !== $phone ) {
				$digits = preg_replace( '/\D/', '', $phone );
				$email  = 'lead+' . $digits . '@' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'local' );
			} else {
				return false;
			}
		}

		$nameParts = preg_split( '/\s+/', trim( $name ), 2 ) ?: [ '' ];
		$firstName = $nameParts[0] ?? '';
		$lastName  = $nameParts[1] ?? '';

		$cleanTags = array_values( array_unique( array_filter( array_map( 'trim', $tags ) ) ) );

		try {
			$contactApi = FluentCrmApi( 'contacts' );

			$contactPayload = [
				'email'      => $email,
				'first_name' => $firstName,
				'last_name'  => $lastName,
				'company'    => $name,
				'tags'       => $cleanTags,
			];

			if ( '' !== $phone ) {
				$contactPayload['phone'] = $phone;
			}

			if ( ! empty( $leadData['address'] ) ) {
				$contactPayload['address_line_1'] = sanitize_text_field( (string) $leadData['address'] );
			}

			if ( ! empty( $leadData['city'] ) ) {
				$contactPayload['city'] = sanitize_text_field( (string) $leadData['city'] );
			}

			// Maintain existing subscriber status if already subscribed
			$existing = $contactApi->getContact( $email );
			if ( ! $existing ) {
				$contactPayload['status'] = sanitize_key( $status );
			}

			if ( ! empty( $listIds ) ) {
				$contactPayload['lists'] = array_map( 'intval', $listIds );
			}

			$contact = $contactApi->createOrUpdate( $contactPayload );

			if ( $contact && isset( $contact->id ) ) {
				$contactId = (int) $contact->id;

				// Attach lists explicitly to trigger any automations / sequences configured on list entry
				if ( ! empty( $listIds ) && method_exists( $contact, 'attachLists' ) ) {
					$contact->attachLists( $listIds );
				}

				// Deep sync custom metadata
				if ( method_exists( $contact, 'updateOrCreateMeta' ) ) {
					$contact->updateOrCreateMeta( 'source', 'google_maps' );
					if ( ! empty( $leadData['place_id'] ) ) {
						$contact->updateOrCreateMeta( 'google_place_id', sanitize_text_field( (string) $leadData['place_id'] ) );
					}
					if ( ! empty( $leadData['website'] ) ) {
						$contact->updateOrCreateMeta( 'website', esc_url_raw( (string) $leadData['website'] ) );
					}
					if ( isset( $leadData['rating'] ) ) {
						$contact->updateOrCreateMeta( 'google_rating', (string) $leadData['rating'] );
					}
					if ( ! empty( $leadData['category'] ) ) {
						$contact->updateOrCreateMeta( 'business_category', sanitize_text_field( (string) $leadData['category'] ) );
					}
				}

				return $contactId;
			}
		} catch ( \Throwable $e ) {
			if ( $this->logger ) {
				$this->logger->error( 'FluentCrmBridge syncLead failed: ' . $e->getMessage() );
			}
		}

		return false;
	}
}
