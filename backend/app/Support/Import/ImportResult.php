<?php

namespace App\Support\Import;

class ImportResult
{
    public int $read = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $notes = [];

    public function __construct(public string $target) {}

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'read' => $this->read,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'notes' => $this->notes,
        ];
    }
}
