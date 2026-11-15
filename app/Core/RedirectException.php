<?php

declare(strict_types=1);

namespace App\Core;

/** Stops the current action and sends the user elsewhere with an error message. */
final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $path, public readonly string $errorKey)
    {
        parent::__construct($errorKey);
    }
}
