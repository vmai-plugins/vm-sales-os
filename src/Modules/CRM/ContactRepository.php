<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

use VMStudio\SalesOS\Database\Repository;

final class ContactRepository extends Repository {

	protected function table(): string {
		return 'vmsos_contacts';
	}

	public static function hash( string $value ): string {
		$value = strtolower( trim( $value ) );
		return '' === $value ? '' : md5( $value );
	}

	/** Normalise a phone number to digits, keeping a leading +. */
	public static function normalisePhone( string $phone ): string {
		$phone = preg_replace( '/[^\d+]/', '', $phone ) ?? '';
		return mb_substr( $phone, 0, 20 );
	}

	/** @return array<string, mixed>|null */
	public function findByEmail( string $email ): ?array {
		$hash = self::hash( $email );
		if ( '' === $hash ) {
			return null;
		}
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare( 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND email_hash = %s', $this->brand(), $hash ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** @return array<string, mixed>|null */
	public function findByPhone( string $phone ): ?array {
		$hash = self::hash( self::normalisePhone( $phone ) );
		if ( '' === $hash ) {
			return null;
		}
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB
			$this->db->prepare( 'SELECT * FROM `' . $this->name() . '` WHERE brand_id = %d AND phone_hash = %s', $this->brand(), $hash ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Insert or merge on email, then phone. Returns the contact id.
	 *
	 * @param array<string, mixed> $input Raw contact fields.
	 */
	public function upsert( array $input ): int {
		if ( empty( $input['first_name'] ) && ! empty( $input['name'] ) ) {
			$nameParts = preg_split( '/\s+/', trim( (string) $input['name'] ), 2 ) ?: [ (string) $input['name'], '' ];
			$input['first_name'] = $nameParts[0];
			$input['last_name']  = $nameParts[1] ?? '';
		}

		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$phone = self::normalisePhone( (string) ( $input['phone'] ?? '' ) );

		$existing = ( '' !== $email ? $this->findByEmail( $email ) : null )
			?? ( '' !== $phone ? $this->findByPhone( $phone ) : null );

		$stage = ! empty( $input['stage'] ) ? sanitize_key( (string) $input['stage'] ) : ( $existing['stage'] ?? 'new' );

		$fields = [
			'first_name'   => sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ),
			'email'        => $email,
			'email_hash'   => '' !== $email ? self::hash( $email ) : ( '' !== $phone ? md5( 'no_email_' . $phone ) : md5( 'no_email_' . uniqid( 'c_', true ) ) ),
			'phone'        => $phone,
			'phone_hash'   => '' !== $phone ? self::hash( $phone ) : md5( 'no_phone_' . uniqid( 'c_', true ) ),
			'source'       => sanitize_text_field( (string) ( $input['source'] ?? 'web' ) ),
			'requirements' => mb_substr( wp_strip_all_tags( (string) ( $input['requirements'] ?? '' ) ), 0, 2000 ),
			'consent'      => ! empty( $input['consent'] ) ? 1 : 0,
			'stage'        => $stage,
			'updated_at'   => $this->now(),
		];

		if ( isset( $input['score'] ) ) {
			$fields['score'] = max( 0, min( 100, (int) $input['score'] ) );
		}
		if ( isset( $input['score_reason'] ) ) {
			$fields['score_reason'] = mb_substr( sanitize_text_field( (string) $input['score_reason'] ), 0, 255 );
		}
		if ( isset( $input['company_id'] ) ) {
			$fields['company_id'] = (int) $input['company_id'];
		}
		if ( isset( $input['owner_id'] ) ) {
			$fields['owner_id'] = (int) $input['owner_id'];
		}
		if ( isset( $input['tags'] ) ) {
			$fields['tags'] = is_array( $input['tags'] ) ? implode( ',', array_map( 'sanitize_text_field', $input['tags'] ) ) : sanitize_text_field( (string) $input['tags'] );
		}
		if ( isset( $input['meta'] ) ) {
			$fields['meta'] = is_array( $input['meta'] ) ? wp_json_encode( $input['meta'] ) : (string) $input['meta'];
		}

		if ( $existing ) {
			// Never blank out a known value with an empty one.
			foreach ( $fields as $key => $value ) {
				if ( '' === $value || 0 === $value ) {
					unset( $fields[ $key ] );
				}
			}
			$fields['updated_at'] = $this->now();
			$updateFormats = [];
			foreach ( $fields as $k => $v ) {
				$updateFormats[] = in_array( $k, [ 'score', 'company_id', 'consent', 'owner_id' ], true ) ? '%d' : '%s';
			}
			$this->update( (int) $existing['id'], $fields, $updateFormats );
			return (int) $existing['id'];
		}

		$fields['created_at'] = $this->now();
		$insertFormats        = [];
		foreach ( $fields as $k => $v ) {
			$insertFormats[] = in_array( $k, [ 'score', 'company_id', 'consent', 'owner_id' ], true ) ? '%d' : '%s';
		}

		return $this->insert( $fields, $insertFormats );
	}

	public function setScore( int $id, int $score, string $stage, string $reason ): void {
		$this->update(
			$id,
			[
				'score'        => max( 0, min( 100, $score ) ),
				'stage'        => sanitize_key( $stage ),
				'score_reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ),
				'updated_at'   => $this->now(),
			],
			[ '%d', '%s', '%s', '%s' ]
		);
	}

	public function touch( int $id ): void {
		$this->update( $id, [ 'last_seen_at' => $this->now() ], [ '%s' ] );
	}

	/**
	 * @param array<string, mixed> $filters stage, search, min_score.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters = [], int $perPage = 25, int $page = 1 ): array {
		$where = [ 'brand_id = %d' ];
		$args  = [ $this->brand() ];

		if ( ! empty( $filters['stage'] ) ) {
			$where[] = 'stage = %s';
			$args[]  = sanitize_key( (string) $filters['stage'] );
		}
		if ( isset( $filters['min_score'] ) ) {
			$where[] = 'score >= %d';
			$args[]  = (int) $filters['min_score'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $this->db->esc_like( sanitize_text_field( (string) $filters['search'] ) ) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		$args[] = max( 1, min( 100, $perPage ) );
		$args[] = max( 0, ( max( 1, $page ) - 1 ) * $perPage );

		$sql = 'SELECT * FROM `' . $this->name() . '` WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY score DESC, updated_at DESC LIMIT %d OFFSET %d';

		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB
	}

	/** @return array<string, int> */
	public function stageCounts(): array {
		$rows = $this->db->get_results( // phpcs:ignore WordPress.DB
			$this->db->prepare( 'SELECT stage, COUNT(*) AS total FROM `' . $this->name() . '` WHERE brand_id = %d GROUP BY stage', $this->brand() ),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['stage'] ] = (int) $row['total'];
		}
		return $out;
	}

	/** subscribed | unsubscribed | bounced — empty means never asked. */
	public function setMarketingStatus( int $id, string $status ): bool {
		return $this->update(
			$id,
			[ 'marketing_status' => sanitize_key( $status ), 'updated_at' => $this->now() ],
			[ '%s', '%s' ]
		);
	}

	/**
	 * Can we legitimately send this contact marketing email?
	 *
	 * Explicit opt-out always wins. Beyond that the site decides whether an
	 * unanswered consent box blocks sending.
	 */
	public function mayEmail( array $contact ): bool {
		if ( empty( $contact['email'] ) || ! is_email( (string) $contact['email'] ) ) {
			return false;
		}

		$status = (string) ( $contact['marketing_status'] ?? '' );

		if ( in_array( $status, [ 'unsubscribed', 'bounced' ], true ) ) {
			return false;
		}

		if ( 'subscribed' === $status ) {
			return true;
		}

		if ( ! get_option( 'vmsos_require_consent', true ) ) {
			return true;
		}

		return ! empty( $contact['consent'] );
	}

	/**
	 * Direct field edit from the admin. Unlike upsert() this trusts the
	 * caller: an operator correcting a name should be able to blank a field
	 * that the assistant guessed wrong.
	 *
	 * @param array<string, mixed> $input Editable fields.
	 */
	public function edit( int $id, array $input ): bool {
		$data    = [ 'updated_at' => $this->now() ];
		$formats = [ '%s' ];

		$text = [ 'first_name', 'last_name', 'phone', 'source', 'stage', 'requirements' ];

		foreach ( $text as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$data[ $field ] = 'requirements' === $field
				? mb_substr( sanitize_textarea_field( (string) $input[ $field ] ), 0, 2000 )
				: sanitize_text_field( (string) $input[ $field ] );

			$formats[] = '%s';
		}

		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( (string) $input['email'] );

			// The unique index is on the hash, so the two must move together.
			$data['email']      = $email;
			$data['email_hash'] = '' !== $email ? self::hash( $email ) : '';
			$formats[]          = '%s';
			$formats[]          = '%s';
		}

		if ( array_key_exists( 'consent', $input ) ) {
			$data['consent'] = empty( $input['consent'] ) ? 0 : 1;
			$formats[]       = '%d';
		}

		if ( array_key_exists( 'owner_id', $input ) ) {
			$data['owner_id'] = (int) $input['owner_id'];
			$formats[]        = '%d';
		}

		return $this->update( $id, $data, $formats );
	}
}
