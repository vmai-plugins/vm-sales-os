<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI;

/**
 * Versioned prompt templates. Prompts are data, not string literals scattered
 * through the codebase, so they can be edited, diffed and A/B tested.
 */
final class PromptRegistry {

	private const OPTION = 'vmsos_prompts';

	/** @var array<string, array{version:int, template:string}> */
	private array $defaults = [
		'sales_agent' => [
			'version'  => 1,
			'template' => "You are {agent_name}, the sales assistant for {business_name}.\n\nWhat you do: understand what the visitor needs, answer from the knowledge below, and move the conversation towards a booked call or a shared contact detail.\n\nRules:\n- Answer only from the knowledge and conversation. If you do not know, say so and offer to have a human follow up.\n- Never invent prices, delivery times, or policies.\n- Ask one question at a time.\n- Capture name, email or phone, and what they need, then call update_contact.\n- Reply in the visitor's language. Keep replies under 90 words.\n\nBusiness context:\n{business_context}\n\nKnowledge:\n{knowledge}",
		],
		'lead_scoring' => [
			'version'  => 1,
			'template' => "Score this lead from 0 to 100 on how likely they are to buy soon.\n\nLead:\n{lead}\n\nRecent conversation:\n{conversation}\n\nReturn only JSON: {\"score\": int, \"stage\": \"new|qualified|hot|unqualified\", \"reason\": \"one short sentence\", \"next_action\": \"one short sentence\"}",
		],
		'conversation_summary' => [
			'version'  => 1,
			'template' => "Summarise this conversation for a sales rep who is about to take over.\n\n{conversation}\n\nReturn only JSON: {\"summary\": \"2 sentences\", \"intent\": \"one phrase\", \"sentiment\": \"positive|neutral|negative\", \"requirements\": \"what they asked for\", \"blockers\": \"what is stopping the sale\"}",
		],
	];

	/** @param array<string, string> $vars Replacement variables. */
	public function render( string $key, array $vars = [] ): string {
		$template = $this->template( $key );
		foreach ( $vars as $name => $value ) {
			$template = str_replace( '{' . $name . '}', (string) $value, $template );
		}
		// Drop any placeholder the caller did not supply.
		return trim( (string) preg_replace( '/\{[a-z_]+\}/', '', $template ) );
	}

	public function template( string $key ): string {
		$stored = get_option( self::OPTION, [] );
		if ( isset( $stored[ $key ]['template'] ) ) {
			return (string) $stored[ $key ]['template'];
		}
		return (string) apply_filters( 'vmsos_prompt_' . $key, $this->defaults[ $key ]['template'] ?? '' );
	}

	public function save( string $key, string $template ): void {
		$stored         = get_option( self::OPTION, [] );
		$version        = (int) ( $stored[ $key ]['version'] ?? $this->defaults[ $key ]['version'] ?? 1 ) + 1;
		$stored[ $key ] = [ 'version' => $version, 'template' => $template, 'updated_at' => time() ];
		update_option( self::OPTION, $stored, false );
	}

	public function reset( string $key ): void {
		$stored = get_option( self::OPTION, [] );
		unset( $stored[ $key ] );
		update_option( self::OPTION, $stored, false );
	}

	/** @return array<int, string> */
	public function keys(): array {
		return array_keys( $this->defaults );
	}
}
