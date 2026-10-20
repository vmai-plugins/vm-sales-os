<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI\Contracts;

interface EmbeddingProvider {

	public function id(): string;

	public function isConfigured(): bool;

	/**
	 * @param array<int, string> $texts Texts to embed.
	 * @return array<int, array<int, float>> One vector per input.
	 */
	public function embed( array $texts ): array;
}
