<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * Input valid by schema but unusable by the tool (e.g. inverted date
 * range). The message must never carry PII.
 */
final class ToolInputException extends \RuntimeException
{
}
