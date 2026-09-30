<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Rodada 2, T-28: catálogo de mensagens de domínio (inglês, cru) → chave _t
 * com parâmetros ^1/^2, usado por CvFormat::userError/userMessage.
 */
final class UserMessageTest
{
    public function testStaticMessageResolvesToItselfWithoutParams(): void
    {
        $resolved = UserMessage::resolve('Patient sex must be one of M, F, U');

        Assert::same(['key' => 'Patient sex must be one of M, F, U', 'params' => []], $resolved);
    }

    public function testPatternCapturesTheEncounterId(): void
    {
        $resolved = UserMessage::resolve('Encounter 3408 is finished and cannot be paused');

        Assert::same(['key' => 'Encounter ^1 is finished and cannot be paused', 'params' => ['3408']], $resolved);
    }

    public function testPatternKeepsTheRawNameForLaterEscaping(): void
    {
        $resolved = UserMessage::resolve('A bank account named "<b>x</b>" already exists for this unit');

        Assert::same(['key' => 'A bank account named "^1" already exists for this unit', 'params' => ['<b>x</b>']], $resolved);
    }

    public function testPatternWithoutCaptureDropsTheId(): void
    {
        Assert::same(['key' => 'Bank account not found', 'params' => []], UserMessage::resolve('Bank account 12 not found for this tenant'));
        // A regex do contrato captura o id, mas a chave não usa ^1: o id não aparece no texto.
        Assert::same(['key' => 'This appointment is already in the queue', 'params' => ['7']], UserMessage::resolve('Appointment 7 is already in the queue'));
    }

    public function testPatternWithTwoCaptures(): void
    {
        $resolved = UserMessage::resolve('Appointment 5 cannot be rescheduled from status finished');

        Assert::same(['key' => 'Appointment ^1 cannot be rescheduled from status ^2', 'params' => ['5', 'finished']], $resolved);
    }

    public function testSchedulingConflictDropsSlotAndProfessional(): void
    {
        $resolved = UserMessage::resolve('Requested slot 2026-09-30 14:00-14:30 conflicts with an existing appointment for professional_system_user_id 1');

        Assert::same(['key' => 'Requested slot conflicts with an existing appointment', 'params' => []], $resolved);
    }

    public function testInvalidDateAndTimeIsCatalogued(): void
    {
        Assert::same(['key' => 'Invalid date and time', 'params' => []], UserMessage::resolve('Invalid date and time'));
    }

    public function testUnknownMessageResolvesToNull(): void
    {
        Assert::null(UserMessage::resolve('qualquer outra'));
        Assert::null(UserMessage::resolve('Encounter 3408 is finished and cannot be paused!'));
    }

    public function testCatalogHasExactlyTheContractEntries(): void
    {
        Assert::count(14, UserMessage::STATIC);
        Assert::count(13, UserMessage::PATTERNS);

        foreach (UserMessage::STATIC as $message => $key) {
            Assert::same($message, $key);
        }
    }
}
