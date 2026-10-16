<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * An id requested by the model does not exist inside the tenant/active
 * unit. Callers answer "not found" without telling apart "other tenant"
 * from "does not exist". The message must never carry PII.
 */
final class ToolEntityNotFoundException extends \RuntimeException
{
}
