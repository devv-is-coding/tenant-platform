<?php

namespace App\Concerns;

/**
 * Reads an audit event's `changes` column as lines a log page can print, the
 * same for the platform's log and an agency's own.
 */
trait SummarizesAuditChanges
{
    /**
     * Describe each recorded change as one line, e.g. "status: 2 → 4".
     *
     * Values are shown as stored: the log records what was written, so an
     * enum reads as its backing value rather than today's label for it.
     *
     * @return list<string>
     */
    public function changeSummary(): array
    {
        // Read through getAttribute(): inside the model, $this->changes is
        // Eloquent's own record of dirty attributes, not this column.
        $changes = $this->getAttribute('changes');

        if (! is_array($changes)) {
            return [];
        }

        $lines = [];

        foreach ($changes as $field => $change) {
            $lines[] = is_array($change) && array_key_exists('old', $change) && array_key_exists('new', $change)
                ? "{$field}: {$this->formatChangeValue($change['old'])} → {$this->formatChangeValue($change['new'])}"
                : "{$field}: {$this->formatChangeValue($change)}";
        }

        return $lines;
    }

    /**
     * Render one stored value for display.
     */
    private function formatChangeValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
