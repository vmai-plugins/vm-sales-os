<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\Agents;

use VMStudio\SalesOS\AI\ToolRegistry;
use VMStudio\SalesOS\Core\Container;
use VMStudio\SalesOS\Core\Module;
use VMStudio\SalesOS\Modules\CRM\ContactRepository;
use VMStudio\SalesOS\REST\RestRouter;

final class AgentsModule extends Module {

	public static function slug(): string {
		return 'agents';
	}

	public static function label(): string {
		return __( 'Agents', 'vm-sales-os' );
	}

	public static function requires(): array {
		return [ 'crm' ];
	}

	public function register( Container $container ): void {
		$container->singleton( AgentRepository::class, static fn() => new AgentRepository() );
	}

	public function boot( Container $container ): void {
		$container->get( RestRouter::class )->add( AgentsController::class );

		add_action( 'vmsos_brain_tick', static fn() => $container->get( BrainTick::class )->run() );
		add_action( 'admin_init', [ $this, 'seedDefaults' ] );

		$this->registerInternalTools( $container );

		// The brain's directive rides along on the sales agent's system prompt.
		add_filter(
			'vmsos_prompt_sales_agent',
			static function ( string $template ): string {
				$brain = BrainTick::directive();
				return empty( $brain['directive'] )
					? $template
					: $template . "\n\nThis hour's priority: " . $brain['directive'];
			}
		);
	}

	public function seedDefaults(): void {
		if ( get_option( 'vmsos_agents_seeded' ) ) {
			return;
		}

		$repository = \VMStudio\SalesOS\Core\Plugin::instance()->make( AgentRepository::class );

		foreach ( $this->defaults() as $agent ) {
			$repository->upsert( $agent );
		}

		update_option( 'vmsos_agents_seeded', 1, false );
	}

	/** @return array<int, array<string, mixed>> */
	private function defaults(): array {
		return [
			[
				'slug'          => 'sales',
				'name'          => 'Sales agent',
				'role'          => 'sales',
				'goal'          => 'Turn website conversations into qualified, contactable leads.',
				'system_prompt' => 'You qualify inbound leads. Ask what they need, what their timeline is, and how to reach them. Save everything you learn with update_contact.',
				'tools'         => [ 'update_contact', 'lookup_contact', 'request_human' ],
				'capabilities'  => [ 'public', 'internal' ],
			],
			[
				'slug'          => 'research',
				'name'          => 'Research agent',
				'role'          => 'research',
				'goal'          => 'Summarise what the team knows about a lead or a topic before a call.',
				'system_prompt' => 'You prepare briefs. Pull what exists in the CRM and knowledge base, then write a short brief: who they are, what they asked for, what to say next.',
				'tools'         => [ 'lookup_contact', 'search_knowledge' ],
				'capabilities'  => [ 'internal' ],
			],
			[
				'slug'          => 'analyst',
				'name'          => 'Pipeline analyst',
				'role'          => 'analyst',
				'goal'          => 'Explain what changed in the pipeline and what to do about it.',
				'system_prompt' => 'You read pipeline numbers and report plainly. Name the one thing most worth fixing this week.',
				'tools'         => [ 'pipeline_snapshot' ],
				'capabilities'  => [ 'internal' ],
			],
		];
	}

	private function registerInternalTools( Container $container ): void {
		$tools = $container->get( ToolRegistry::class );

		$tools->register(
			[
				'name'        => 'lookup_contact',
				'description' => 'Find a contact by email or phone and return what the CRM knows about them.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'email' => [ 'type' => 'string' ],
						'phone' => [ 'type' => 'string' ],
					],
					'required'   => [],
				],
			],
			static function ( array $args ) use ( $container ): array {
				$contacts = $container->get( ContactRepository::class );

				$contact = ! empty( $args['email'] )
					? $contacts->findByEmail( (string) $args['email'] )
					: ( ! empty( $args['phone'] ) ? $contacts->findByPhone( (string) $args['phone'] ) : null );

				if ( ! $contact ) {
					return [ 'found' => false ];
				}

				return [
					'found'        => true,
					'id'           => (int) $contact['id'],
					'name'         => trim( $contact['first_name'] . ' ' . $contact['last_name'] ),
					'stage'        => $contact['stage'],
					'score'        => (int) $contact['score'],
					'requirements' => $contact['requirements'],
					'source'       => $contact['source'],
				];
			},
			'internal'
		);

		$tools->register(
			[
				'name'        => 'pipeline_snapshot',
				'description' => 'Current lead counts by stage and AI spend for the last seven days.',
				'parameters'  => [ 'type' => 'object', 'properties' => (object) [], 'required' => [] ],
			],
			static function () use ( $container ): array {
				return [
					'stages'   => $container->get( ContactRepository::class )->stageCounts(),
					'ai_usage' => $container->get( \VMStudio\SalesOS\AI\UsageMeter::class )->summary( 7 ),
				];
			},
			'internal'
		);
	}
}
