<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Presentation\DateTimeInput;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;
use InvalidArgumentException;

/**
 * Rodada 2, T-53 (correção 1): o campo scheduled_at do AppointmentForm
 * entrega ao servidor o texto digitado (dd/mm/yyyy hh:ii), sem conversão do
 * widget. Com setDatabaseMask, o TDateTime convertia por createFromFormat
 * sem validar: "31/02/2026 11:00" virava 2026-03-03 e "13/13/2026 11:00"
 * virava 2027-01-13 antes do DateTimeInput::parse estrito.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global, o
 * que desligaria o stub de CvFormatUserErrorTest neste processo).
 */
final class AppointmentFormPostIntegrationTest
{
    /**
     * @return array{post: string, value: string}
     */
    private function runForm(string $posted, ?string $editDate = null): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$_POST["scheduled_at"] = ' . var_export($posted, true) . ';'
            . '$page = new AppointmentForm([]);'
            . ($editDate !== null ? '$page->onEdit(["scheduled_at" => ' . var_export($editDate, true) . ']);' : '')
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$field = $form->getField("scheduled_at");'
            . 'echo json_encode(["post" => (string) $field->getPostData(), "value" => (string) $field->getValue()]);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'AppointmentForm subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testImpossibleDatesReachTheServerUnchangedAndAreRejected(): void
    {
        foreach (['31/02/2026 11:00', '13/13/2026 11:00', '01/10/2026 25:00'] as $typed) {
            $posted = $this->runForm($typed)['post'];

            Assert::same($typed, $posted, "posted value for '{$typed}' must not be converted by the widget");
            Assert::throws(InvalidArgumentException::class, static fn () => DateTimeInput::parse($posted), "'{$typed}' must be rejected");
        }
    }

    public function testValidDisplayDateIsParsedAsDayMonth(): void
    {
        $posted = $this->runForm('01/10/2026 11:00')['post'];

        Assert::same('2026-10-01 11:00', DateTimeInput::parse($posted)->format('Y-m-d H:i'));
    }

    public function testAgendaDatePrefillIsShownInDisplayFormat(): void
    {
        Assert::same('01/10/2026', $this->runForm('', '2026-10-01')['value']);
    }
}
