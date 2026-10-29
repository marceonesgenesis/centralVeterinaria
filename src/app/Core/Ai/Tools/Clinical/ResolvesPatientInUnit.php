<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools\Clinical;

use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolInputException;

/**
 * Resolves the patient of a clinical tool: `patient_id` (integer > 0) or the
 * conversation anchor, and only when the patient belongs to the active unit.
 * The using class must expose `$this->query` (CopilotClinicalQueryInterface).
 */
trait ResolvesPatientInUnit
{
    /** @param array<string, mixed> $input */
    private function patientId(ToolContext $context, array $input): int
    {
        $patientId = $input['patient_id'] ?? $context->anchorPatientId;
        if (!is_int($patientId) || $patientId <= 0) {
            throw new ToolInputException('patient_id: required (no patient in the conversation)');
        }
        if (!$this->query->patientInUnit($patientId)) {
            throw new ToolEntityNotFoundException('Patient not found');
        }

        return $patientId;
    }
}
