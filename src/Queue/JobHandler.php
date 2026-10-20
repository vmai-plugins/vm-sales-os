<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Queue;

interface JobHandler {

	/** Unique handler name stored on the job row. */
	public static function name(): string;

	/**
	 * Do the work. Throwing schedules a retry with backoff.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function handle( array $payload ): void;
}
