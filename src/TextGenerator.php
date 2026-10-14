<?php

declare(strict_types=1);

/**
 * A text generation backend.
 *
 * Every backend takes a system instruction and a user prompt and returns the
 * generated text. Backends are interchangeable so one can stand in for
 * another when it is unavailable.
 */
interface TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string;
}
