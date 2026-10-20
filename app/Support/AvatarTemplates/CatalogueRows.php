<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

/**
 * What one provider fetch produced: the rows, and whether they are the whole inventory.
 *
 * `$truncated` is true when a paged fetch stopped at its safety cap while the vendor still had more, or when a
 * secondary list failed: the rows are served, but `AvatarProviderCatalogue::fetch()` never caches them as if
 * they were complete. It travels with the rows as a return value instead of a by-reference flag threaded
 * through every fetch method.
 */
final readonly class CatalogueRows
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $truncated = false,
    ) {}

    /**
     * The same completeness, other rows (the raw vendor rows mapped to catalogue entries, or sorted).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function withRows(array $rows): self
    {
        return new self($rows, $this->truncated);
    }

    /** Both row sets, complete only when both are. */
    public function merge(self $other): self
    {
        return new self(array_merge($this->rows, $other->rows), $this->truncated || $other->truncated);
    }
}
