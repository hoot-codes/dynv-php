<?php

declare(strict_types=1);

namespace Dynv;

final class ChangeEvent
{
    /**
     * @param list<string> $changed Sorted key names that were added, changed or removed.
     */
    public function __construct(
        public readonly int $version,
        public readonly array $changed,
        public readonly bool $restart,
    ) {
    }
}
