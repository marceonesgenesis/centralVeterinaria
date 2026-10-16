<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * Rows returned by a tool: a list of flat records with scalar values.
 * $freeTextFields names the columns holding clinical free text (minimized
 * before going to the provider); $knownNames lists names already shown to
 * the user that PII minimization may keep.
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
     */
    public function __construct(
        array $data,
        public readonly bool $truncated = false,
        public readonly array $freeTextFields = [],
        public readonly array $knownNames = [],
    ) {
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
