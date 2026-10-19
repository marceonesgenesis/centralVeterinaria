<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * Rows returned by a tool: a list of flat records with scalar values.
 * $freeTextFields names the columns holding clinical free text (minimized
 * before going to the provider); $knownNames lists names already shown to
 * the user, which PII minimization masks inside those free-text fields.
 * $excerptLengths maps a free-text column (it must be listed in
 * $freeTextFields) to its maximum length in characters: tools return the
 * full text and ToolPolicy cuts it only after minimizing, so a cut never
 * leaves a fragment of a phone, CPF or name that the minimizer cannot
 * recognize, and no column is cut without being minimized.
 */
final class ToolResult
{
    public const MAX_ROWS = 50;

    /** @var list<array<string, scalar|null>> */
    public readonly array $data;

    public readonly int $rowCount;

    /**
     * @param list<array<string, scalar|null>> $data
     * @param list<string> $freeTextFields
     * @param list<string> $knownNames
     * @param array<string, int> $excerptLengths
     */
    public function __construct(
        array $data,
        public readonly bool $truncated = false,
        public readonly array $freeTextFields = [],
        public readonly array $knownNames = [],
        public readonly array $excerptLengths = [],
    ) {
        foreach ($excerptLengths as $field => $max) {
            if (!is_string($field) || !is_int($max) || $max <= 0) {
                throw new \InvalidArgumentException('Excerpt lengths must map field names to positive lengths');
            }
            if (!in_array($field, $freeTextFields, true)) {
                throw new \InvalidArgumentException('Excerpt lengths must name free-text fields');
            }
        }

        if (!array_is_list($data)) {
            throw new \InvalidArgumentException('Tool result data must be a list of rows');
        }

        foreach ($data as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Each tool result row must be an array');
            }
            foreach ($row as $key => $value) {
                if (!is_string($key) || ($value !== null && !is_scalar($value))) {
                    throw new \InvalidArgumentException('Tool result rows must map string keys to scalar values');
                }
            }
        }

        $this->data = $data;
        $this->rowCount = count($data);
    }
}
