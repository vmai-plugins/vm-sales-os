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

	public function chat( ChatRequest $request ): ChatResponse;
}
