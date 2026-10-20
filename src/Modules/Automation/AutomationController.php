<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Automation;

use VMStudio\SalesOS\REST\AbstractController;
use WP_REST_Request;

final class AutomationController extends AbstractController {

	public const DEFAULT_EVENTS = [
		[ 'slug' => 'contact.captured', 'label' => 'New Contact Captured', 'fields' => [ 'email', 'phone', 'first_name', 'last_name', 'source' ] ],
		[ 'slug' => 'deal.stage_changed', 'label' => 'Deal Stage Changed', 'fields' => [ 'deal_id', 'stage', 'value', 'contact_id' ] ],
		[ 'slug' => 'conversation.started', 'label' => 'Conversation Started', 'fields' => [ 'channel', 'visitor_hash', 'contact_id' ] ],
		[ 'slug' => 'conversation.awaiting_human', 'label' => 'Awaiting Human Operator', 'fields' => [ 'conversation_id', 'contact_id', 'channel' ] ],
		[ 'slug' => 'lead.scored', 'label' => 'Lead Scored / Stage Advanced', 'fields' => [ 'contact_id', 'score', 'stage' ] ],
	];

	public const DEFAULT_ACTIONS = [
		[ 'slug' => 'sequence.enroll', 'label' => 'Enroll in Sequence', 'args' => [ 'sequence_id' ] ],
		[ 'slug' => 'agent.run', 'label' => 'Run Autonomous Agent', 'args' => [ 'agent_slug' ] ],
		[ 'slug' => 'notification.send', 'label' => 'Send Email Notification', 'args' => [ 'recipient', 'subject' ] ],
		[ 'slug' => 'deal.create', 'label' => 'Create CRM Deal', 'args' => [ 'pipeline_id', 'stage', 'value' ] ],
	];

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/automation/rules',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'index' ],
					'permission_callback' => fn() => $this->canOperate(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save' ],
					'permission_callback' => fn() => $this->canManage(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/automation/rules/(?P<id>[a-zA-Z0-9_\-]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => fn() => $this->canManage(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/automation/meta',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'meta' ],
				'permission_callback' => fn() => $this->canOperate(),
			]
		);
	}

	public function index( WP_REST_Request $request ) {
		$rules = (array) get_option( AutomationModule::OPTION, [] );
		return $this->ok( [ 'rules' => array_values( $rules ) ] );
	}

	public function meta( WP_REST_Request $request ) {
		return $this->ok(
			[
				'events'  => self::DEFAULT_EVENTS,
				'actions' => self::DEFAULT_ACTIONS,
			]
		);
	}

	public function save( WP_REST_Request $request ) {
		$rule = (array) ( $request->get_json_params() ?: $request->get_params() );

		$id = sanitize_key( (string) ( $rule['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = 'rule_' . wp_generate_password( 8, false );
		}

		$on     = sanitize_text_field( (string) ( $rule['on'] ?? '' ) );
		$action = sanitize_text_field( (string) ( $rule['action'] ?? '' ) );

		if ( '' === $on || '' === $action ) {
			return $this->fail( 'vmsos_invalid_rule', __( 'Event trigger and action are required.', 'vm-sales-os' ) );
		}

		$ruleData = [
			'id'          => $id,
			'label'       => sanitize_text_field( (string) ( $rule['label'] ?? ( 'Rule: ' . $on . ' -> ' . $action ) ) ),
			'on'          => $on,
			'conditions'  => (array) ( $rule['conditions'] ?? [] ),
			'action'      => $action,
			'args'        => (array) ( $rule['args'] ?? [] ),
			'delay'       => max( 0, (int) ( $rule['delay'] ?? 0 ) ),
			'enabled'     => ! empty( $rule['enabled'] ),
			'updated_at'  => current_time( 'mysql' ),
		];

		$rules = (array) get_option( AutomationModule::OPTION, [] );
		$existingIndex = null;
		foreach ( $rules as $idx => $r ) {
			if ( ( $r['id'] ?? '' ) === $id ) {
				$existingIndex = $idx;
				break;
			}
		}

		if ( null !== $existingIndex ) {
			$rules[ $existingIndex ] = $ruleData;
		} else {
			$rules[] = $ruleData;
		}

		update_option( AutomationModule::OPTION, array_values( $rules ), false );

		return $this->ok( [ 'rule' => $ruleData ] );
	}

	public function delete( WP_REST_Request $request ) {
		$id    = sanitize_key( (string) $request->get_param( 'id' ) );
		$rules = (array) get_option( AutomationModule::OPTION, [] );

		$filtered = array_values(
			array_filter(
				$rules,
				fn( array $r ) => ( $r['id'] ?? '' ) !== $id
			)
		);

		update_option( AutomationModule::OPTION, $filtered, false );

		return $this->ok( [ 'deleted' => true, 'id' => $id ] );
	}
}
