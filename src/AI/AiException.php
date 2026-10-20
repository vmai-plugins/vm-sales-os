<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\AI;

final class AiException extends \RuntimeException {

	/** @var array<string, string> */
	private array $attempts = [];

	/** @param array<string, string> $attempts Provider id => error. */
	public function withAttempts( array $attempts ): self {
		$this->attempts = $attempts;
		return $this;
	}

	/** @return array<string, string> */
	public function attempts(): array {
		return $this->attempts;
	}
}
