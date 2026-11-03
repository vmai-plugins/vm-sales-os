<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Modules\Chat\ConversationRepository;
use VMStudio\SalesOS\Support\BrandContext;
use VMStudio\SalesOS\Support\IntegrationLog;

final class FluentFormSync {

	public function __construct(
		private ContactRepository $contacts,
		private ConversationRepository $conversations,
		private ActivityRepository $activities,
		private ?IntegrationLog $log = null
	) {}

	public function boot(): void {
		// Real-time hook when any Fluent Form is submitted on the website
		add_action( 'fluentform/submission_inserted', [ $this, 'onSubmission' ], 10, 3 );
		add_action( 'fluentform_submission_inserted', [ $this, 'onSubmission' ], 10, 3 );
	}

	/**
	 * Real-time listener for Fluent Form submission.
	 *
	 * @param int                  $insertId Form submission ID in wp_fluentform_submissions.
	 * @param array<string, mixed> $formData Raw or parsed form input.
	 * @param object|array         $form     Form object.
	 */
	public function onSubmission( int $insertId, mixed $formData, mixed $form ): void {
		if ( ! is_array( $formData ) ) {
			return;
		}

		$formId    = is_object( $form ) ? (int) ( $form->id ?? 0 ) : (int) ( $form['id'] ?? 0 );
		$formTitle = is_object( $form ) ? (string) ( $form->title ?? 'Website Form' ) : (string) ( $form['title'] ?? 'Website Form' );

		$this->processEntry( $insertId, $formId, $formTitle, $formData, current_time( 'mysql' ) );
	}

	/**
	 * Process a single entry (both live and historical sync).
	 *
	 * @param array<string, mixed> $data
	 */
	public function processEntry( int $submissionId, int $formId, string $formTitle, array $data, string $createdAt ): int {
		$firstName = '';
		$lastName  = '';
		$email     = '';
		$phone     = '';
		$company   = '';
		$message   = '';

		// 1. Extract Name
		if ( isset( $data['names'] ) && is_array( $data['names'] ) ) {
			$firstName = trim( (string) ( $data['names']['first_name'] ?? '' ) );
			$lastName  = trim( (string) ( $data['names']['last_name'] ?? '' ) );
		} elseif ( ! empty( $data['name'] ) ) {
			$parts     = preg_split( '/\s+/', trim( (string) $data['name'] ), 2 ) ?: [ '' ];
			$firstName = $parts[0];
			$lastName  = $parts[1] ?? '';
		} else {
			$firstName = trim( (string) ( $data['first_name'] ?? '' ) );
			$lastName  = trim( (string) ( $data['last_name'] ?? '' ) );
		}

		// 2. Extract Email
		foreach ( [ 'email', 'user_email', 'your_email' ] as $k ) {
			if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$email = sanitize_email( $data[ $k ] );
				if ( is_email( $email ) ) {
					break;
				}
			}
		}

		// 3. Extract Phone
		foreach ( [ 'phone', 'mobile', 'telephone', 'phone_number', 'contact_number' ] as $k ) {
			if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$phone = ContactRepository::normalisePhone( (string) $data[ $k ] );
				if ( '' !== $phone ) {
					break;
				}
			}
		}

		// 4. Extract Company / Organization
		foreach ( [ 'company', 'organization', 'business_name', 'company_name' ] as $k ) {
			if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$company = sanitize_text_field( (string) $data[ $k ] );
				break;
			}
		}

		// 5. Extract Message / Requirements
		foreach ( [ 'message', 'description', 'requirements', 'inquiry', 'comment', 'notes' ] as $k ) {
			if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$message = sanitize_textarea_field( (string) $data[ $k ] );
				break;
			}
		}

		// If no email, name, or phone was found, cannot create contact
		if ( empty( $email ) && empty( $phone ) && empty( $firstName ) ) {
			return 0;
		}

		// Upsert into Sales OS Contacts
		$contactId = $this->contacts->upsert( [
			'first_name'   => $firstName,
			'last_name'    => $lastName,
			'email'        => $email,
			'phone'        => $phone,
			'source'       => 'fluentform',
			'requirements' => $message,
			'consent'      => 1,
			'meta'         => [
				'fluentform_id'         => $formId,
				'fluentform_title'      => $formTitle,
				'fluent_submission_id'  => $submissionId,
				'company'               => $company,
				'initial_source_url'    => (string) ( $data['_wp_http_referer'] ?? '' ),
			],
		] );

		if ( $contactId <= 0 ) {
			return 0;
		}

		global $wpdb;
		$brandId = BrandContext::current();

		// Attach Company if provided
		if ( ! empty( $company ) ) {
			$companyId = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}vmsos_companies WHERE brand_id = %d AND name = %s LIMIT 1",
					$brandId,
					$company
				)
			);
			if ( $companyId <= 0 ) {
				$wpdb->insert(
					$wpdb->prefix . 'vmsos_companies',
					[
						'brand_id'   => $brandId,
						'name'       => $company,
						'created_at' => $createdAt,
						'updated_at' => $createdAt,
					]
				);
				$companyId = (int) $wpdb->insert_id;
			}
			if ( $companyId > 0 ) {
				$wpdb->update(
					$wpdb->prefix . 'vmsos_contacts',
					[ 'company_id' => $companyId ],
					[ 'id' => $contactId ],
					[ '%d' ],
					[ '%d' ]
				);
			}
		}

		// Set initial lead score to 50 if requirements exist
		if ( ! empty( $message ) ) {
			$wpdb->update(
				$wpdb->prefix . 'vmsos_contacts',
				[
					'score'        => 50,
					'score_reason' => sprintf( 'Inquiry captured via %s: "%s"', $formTitle, mb_substr( $message, 0, 100 ) ),
					'updated_at'   => $createdAt,
				],
				[ 'id' => $contactId ],
				[ '%d', '%s', '%s' ],
				[ '%d' ]
			);
		}

		// Sync pipeline deal into vmsos_deals if no deal exists for this contact
		$dealTable = $wpdb->prefix . 'vmsos_deals';
		$existingDealId = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$dealTable}` WHERE brand_id = %d AND contact_id = %d LIMIT 1",
				$brandId,
				$contactId
			)
		);
		if ( $existingDealId <= 0 ) {
			$dealValue = 0.0;
			foreach ( [ 'budget', 'estimated_budget', 'project_budget', 'price', 'amount', 'deal_value' ] as $bKey ) {
				if ( ! empty( $data[ $bKey ] ) ) {
					$numStr = preg_replace( '/[^0-9.]/', '', (string) $data[ $bKey ] );
					if ( is_numeric( $numStr ) ) {
						$dealValue = (float) $numStr;
						break;
					}
				}
			}
			$dealTitle = ! empty( $company )
				? ( 'Inquiry: ' . $company )
				: ( 'Inquiry: ' . trim( $firstName . ' ' . $lastName ) );
			if ( empty( trim( $dealTitle, ': ' ) ) ) {
				$dealTitle = 'Inquiry from ' . $formTitle;
			}
			$wpdb->insert(
				$dealTable,
				[
					'brand_id'    => $brandId,
					'contact_id'  => $contactId,
					'company_id'  => $companyId ?? 0,
					'title'       => $dealTitle,
					'stage'       => 'new',
					'value'       => $dealValue,
					'currency'    => (string) get_option( 'vmsos_currency', 'INR' ),
					'created_at'  => $createdAt,
					'updated_at'  => $createdAt,
				],
				[ '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s' ]
			);
		}

		// Create/link Conversation in Sales OS Omnichannel Inbox
		$existingConv = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}vmsos_conversations WHERE brand_id = %d AND contact_id = %d LIMIT 1",
				$brandId,
				$contactId
			),
			ARRAY_A
		);

		$convId = 0;
		if ( $existingConv ) {
			$convId = (int) $existingConv['id'];
		} else {
			$convData = $this->conversations->start( 'web_form', 'fluentform-' . $submissionId );
			$convId   = (int) $convData['id'];
			$this->conversations->attachContact( $convId, $contactId );

			// Update timestamp to match submission time
			$wpdb->update(
				$wpdb->prefix . 'vmsos_conversations',
				[
					'created_at'      => $createdAt,
					'last_message_at' => $createdAt,
					'status'          => 'open',
				],
				[ 'id' => $convId ]
			);
		}

		// Append Message if message body exists
		if ( $convId > 0 && ! empty( $message ) ) {
			$existingMsg = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}vmsos_messages WHERE brand_id = %d AND conversation_id = %d AND role = 'user' AND content = %s LIMIT 1",
					$brandId,
					$convId,
					$message
				)
			);

			if ( ! $existingMsg ) {
				$wpdb->insert(
					$wpdb->prefix . 'vmsos_messages',
					[
						'brand_id'        => $brandId,
						'conversation_id' => $convId,
						'role'            => 'user',
						'content'         => $message,
						'provider'        => 'fluentform',
						'model'           => 'form_entry',
						'created_at'      => $createdAt,
					]
				);
			}
		}

		// Log CRM Activity Timeline
		$this->activities->log(
			'contact',
			$contactId,
			'fluentform_entry',
			sprintf( 'Inquiry submitted on "%s": %s', $formTitle, mb_substr( $message, 0, 120 ) ),
			'fluentform'
		);

		return $contactId;
	}

	/**
	 * Run historical migration of all submissions in wp_fluentform_submissions.
	 *
	 * @return array{processed: int, synced: int, errors: int}
	 */
	public function syncHistorical( int $limit = 500 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submissions';
		$formsTable = $wpdb->prefix . 'fluentform_forms';

		// Verify table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [ 'processed' => 0, 'synced' => 0, 'errors' => 1 ];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.form_id, s.response, s.created_at, COALESCE(f.title, 'Website Form') as form_title
				 FROM {$table} s
				 LEFT JOIN {$formsTable} f ON f.id = s.form_id
				 ORDER BY s.id ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$processed = 0;
		$synced    = 0;
		$errors    = 0;

		foreach ( (array) $rows as $row ) {
			$processed++;
			$resp = json_decode( (string) $row['response'], true );
			if ( ! is_array( $resp ) ) {
				$errors++;
				continue;
			}

			$cid = $this->processEntry(
				(int) $row['id'],
				(int) $row['form_id'],
				(string) $row['form_title'],
				$resp,
				(string) $row['created_at']
			);

			if ( $cid > 0 ) {
				$synced++;
			} else {
				$errors++;
			}
		}

		return [
			'processed' => $processed,
			'synced'    => $synced,
			'errors'    => $errors,
		];
	}
}
