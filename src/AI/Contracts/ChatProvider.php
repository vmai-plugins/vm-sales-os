<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Contracts;

use VMStudio\SalesOS\AI\DTO\ChatRequest;
use VMStudio\SalesOS\AI\DTO\ChatResponse;

interface ChatProvider {

	/** Stable identifier, e.g. "openai", "aipuffer". */
	public function id(): string;

	public function label(): string;

	/** True when credentials/endpoint are present. */
	public function isConfigured(): bool;

	/** Whether this provider can execute tool calls. */
	public function supportsTools(): bool;

	/**
	 * Retrieve list of available model IDs for this provider.
	 *
	 * @param bool $force Bypass cache.
	 * @param string|null $keyOverride Optional API key.
	 * @param string|null $baseOverride Optional base URL.
	 * @return list<string>
	 */
	public function getModels( bool $force = false, ?string $keyOverride = null, ?string $baseOverride = null ): array;

	public function chat( ChatRequest $request ): ChatResponse;
}
