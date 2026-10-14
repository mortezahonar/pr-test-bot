<?php

declare(strict_types=1);

/**
 * Tries each backend in order and returns the first result.
 *
 * The last backend in the chain stands in for the ones before it, so a single
 * provider being down does not fail the whole job.
 */
final class FallbackGenerator implements TextGenerator
{
    /** @var TextGenerator[] */
    private array $generators;

    public function __construct(TextGenerator ...$generators)
    {
        $this->generators = $generators;
    }

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $lastError = null;

        foreach ($this->generators as $generator) {
            $name = (new ReflectionClass($generator))->getShortName();

            Log::write('generator: trying ' . $name);

            try {
                return $generator->generate($system, $userText, $timeout);
            } catch (Throwable $e) {
                Log::write('generator: ' . $name . ' gave up: ' . $e->getMessage());

                $lastError = $e;
            }
        }

        throw $lastError ?? new RuntimeException('No text generator configured');
    }
}
