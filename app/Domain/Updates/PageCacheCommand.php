<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use InvalidArgumentException;

/**
 * The WP-CLI command that purges the page cache of one caching plugin
 * (UZEROWPUP-55): the command name as `wp cli has-command` takes it (e.g.
 * "w3-total-cache flush") and the arguments to run it with.
 */
final readonly class PageCacheCommand
{
    /**
     * @param  list<string>  $arguments
     *
     * @throws InvalidArgumentException
     */
    public function __construct(public string $command, public array $arguments = [])
    {
        if (trim($command) === '' || str_starts_with($command, '-')) {
            throw new InvalidArgumentException('A page cache command needs a command name.');
        }
    }

    /**
     * @return list<string> the WP-CLI arguments to run
     */
    public function args(): array
    {
        return [...explode(' ', trim($this->command)), ...$this->arguments];
    }
}
