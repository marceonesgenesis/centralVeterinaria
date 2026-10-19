<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\Tools\AiTool;
use CentralVet\Ai\Tools\JsonSchemaValidator;
use CentralVet\Ai\Tools\Management\ConsultarAgendaTool;
use CentralVet\Ai\Tools\Management\VerificarHorariosTool;
use CentralVet\Ai\Tools\ToolContext;
use CentralVet\Ai\Tools\ToolEntityNotFoundException;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Ai\Tools\ToolInputException;
use CentralVet\Application\FreeSlotFinder;
use CentralVet\Domain\AiPurpose;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCopilotAgendaQuery;
use DateTimeImmutable;

/**
 * Fase 8B, T-19: agenda tools of the management copilot and the free-slot
 * rule (grid 07:00-19:00 step 30; busy = scheduled/confirmed/in progress/done
 * with the service duration; today only after now).
 */
final class AgendaToolsTest
{
    private const FORBIDDEN_KEYS = ['cpf', 'phone', 'telefone', 'email', 'address', 'endereco', 'tutor_full_name'];

    private function context(): ToolContext
    {
        return ToolContext::create(
            TenantContext::authenticated(7, 11, 3),
            'corr-t19',
            null,
            AiPurpose::COPILOT_MANAGEMENT,
            null,
        );
    }

    private function clock(string $now = '2026-10-15 10:10:00'): \Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable($now);
    }

    private function query(): FakeCopilotAgendaQuery
    {
        $query = new FakeCopilotAgendaQuery();
        $query->seed('professionals', [
            ['id' => 5, 'name' => 'Ana Paula Ribeiro'],
            ['id' => 6, 'name' => 'Bruno Costa'],
        ]);

        return $query;
    }

    /**
     * @param list<array{start: string, professional_id: int}> $slots
     * @return list<string>
     */
    private function startsOf(array $slots, int $professionalId): array
    {
        return array_values(array_map(
            static fn (array $slot): string => $slot['start'],
            array_filter($slots, static fn (array $slot): bool => $slot['professional_id'] === $professionalId),
        ));
    }

    public function testFreeSlotFinderSkipsBusyIntervalOfTheProfessional(): void
    {
        $day = new DateTimeImmutable('2026-10-20');
        $busy = [['professional_id' => 5, 'start' => '2026-10-20 09:00:00', 'end' => '2026-10-20 09:45:00']];

        $slots = (new FreeSlotFinder())->find($day, 30, $busy, [5, 6], new DateTimeImmutable('2026-10-15 10:00:00'));
        $five = $this->startsOf($slots, 5);
        $six = $this->startsOf($slots, 6);

        Assert::false(in_array('2026-10-20 09:00', $five, true), '09:00 overlaps 09:00-09:45');
        Assert::false(in_array('2026-10-20 09:30', $five, true), '09:30 overlaps 09:00-09:45');
        Assert::true(in_array('2026-10-20 10:00', $five, true), '10:00 is free for professional 5');
        Assert::true(in_array('2026-10-20 08:30', $five, true), '08:30-09:00 ends exactly when the busy interval starts');
        Assert::true(in_array('2026-10-20 09:00', $six, true), 'busy of 5 does not block 6');
        Assert::same('2026-10-20 07:00', $six[0]);
        Assert::same('2026-10-20 18:30', $six[count($six) - 1], '30 min slot at 18:30 ends at 19:00');
        Assert::count(24, $six);
    }

    public function testFreeSlotFinderKeepsTheSlotInsideTheGrid(): void
    {
        $day = new DateTimeImmutable('2026-10-20');

        $slots = (new FreeSlotFinder())->find($day, 60, [], [5], new DateTimeImmutable('2026-10-15 10:00:00'));
        $starts = $this->startsOf($slots, 5);

        Assert::false(in_array('2026-10-20 18:30', $starts, true), '60 min at 18:30 ends after 19:00');
        Assert::same('2026-10-20 18:00', $starts[count($starts) - 1]);
    }

    public function testFreeSlotFinderOnlyOffersSlotsAfterNowToday(): void
    {
        $day = new DateTimeImmutable('2026-10-15');

        $slots = (new FreeSlotFinder())->find($day, 30, [], [5], new DateTimeImmutable('2026-10-15 10:10:00'));

        Assert::same('2026-10-15 10:30', $this->startsOf($slots, 5)[0]);
        Assert::same([], (new FreeSlotFinder())->find(new DateTimeImmutable('2026-10-14'), 30, [], [5], new DateTimeImmutable('2026-10-15 10:10:00')));
    }

    public function testFreeSlotFinderIgnoresUnknownProfessionalBusyAndOrdersByTime(): void
    {
        $day = new DateTimeImmutable('2026-10-20');
        $busy = [['professional_id' => 9, 'start' => '2026-10-20 07:00:00', 'end' => '2026-10-20 19:00:00']];

        $slots = (new FreeSlotFinder())->find($day, 30, $busy, [6, 5], new DateTimeImmutable('2026-10-15 10:00:00'));

        Assert::same(['start' => '2026-10-20 07:00', 'professional_id' => 6], $slots[0]);
        Assert::same(['start' => '2026-10-20 07:00', 'professional_id' => 5], $slots[1]);
        Assert::same('2026-10-20 07:30', $slots[2]['start']);
    }

    public function testToolsDeclareNamePermissionFamilyAndValidSchema(): void
    {
        $query = $this->query();
        $tools = [new ConsultarAgendaTool($query, $this->clock()), new VerificarHorariosTool($query, $this->clock())];

        Assert::same(['consultar_agenda', 'verificar_horarios'], array_map(static fn (AiTool $t): string => $t->name(), $tools));
        foreach ($tools as $tool) {
            Assert::same('AgendaView::onReload', $tool->requiredPermission());
            Assert::same(ToolFamily::MANAGEMENT, $tool->family());
            Assert::true(ToolFamily::isAllowed(ToolFamily::MANAGEMENT, $tool->name()));
            Assert::true($tool->description() !== '');
            Assert::true($tool->label('pt') !== '' && $tool->label('en') !== '');
            Assert::same('object', $tool->inputSchema()['type']);
        }

        [$agenda, $horarios] = $tools;
        Assert::same([], JsonSchemaValidator::validate($agenda->inputSchema(), []));
        Assert::same([], JsonSchemaValidator::validate($agenda->inputSchema(), ['data' => '2026-10-20', 'profissional_id' => 5]));
        Assert::true(JsonSchemaValidator::validate($agenda->inputSchema(), ['data' => '20/10/2026']) !== []);

        Assert::same([], JsonSchemaValidator::validate($horarios->inputSchema(), ['data' => '2026-10-20', 'duracao_min' => 15]));
        Assert::same([], JsonSchemaValidator::validate($horarios->inputSchema(), ['data' => '2026-10-20', 'duracao_min' => 240]));
        Assert::true(JsonSchemaValidator::validate($horarios->inputSchema(), ['data' => '2026-10-20', 'duracao_min' => 14]) !== []);
        Assert::true(JsonSchemaValidator::validate($horarios->inputSchema(), ['data' => '2026-10-20', 'duracao_min' => 241]) !== []);
        Assert::true(JsonSchemaValidator::validate($horarios->inputSchema(), ['duracao_min' => 30]) !== []);
    }

    public function testConsultarAgendaRejectsRangeLongerThanSevenDays(): void
    {
        $tool = new ConsultarAgendaTool($this->query(), $this->clock());

        Assert::throws(
            ToolInputException::class,
            fn () => $tool->execute($this->context(), ['data_inicio' => '2026-10-01', 'data_fim' => '2026-10-10']),
        );
        Assert::throws(
            ToolInputException::class,
            fn () => $tool->execute($this->context(), ['data_inicio' => '2026-10-10', 'data_fim' => '2026-10-01']),
        );
        Assert::throws(
            ToolInputException::class,
            fn () => $tool->execute($this->context(), ['data_inicio' => '2026-10-01']),
        );

        $query = $this->query();
        (new ConsultarAgendaTool($query, $this->clock()))
            ->execute($this->context(), ['data_inicio' => '2026-10-01', 'data_fim' => '2026-10-07']);
        $call = array_values(array_filter($query->calls(), static fn (array $c): bool => $c['method'] === 'appointments'))[0];
        Assert::same(['2026-10-01 00:00:00', '2026-10-08 00:00:00', null, 51], $call['args'], '7 days, end exclusive, limit + 1');
    }

    public function testConsultarAgendaDefaultsToTodayAndSingleDay(): void
    {
        $query = $this->query();
        $tool = new ConsultarAgendaTool($query, $this->clock());

        $tool->execute($this->context(), []);
        $tool->execute($this->context(), ['data' => '2026-10-20', 'profissional_id' => 6]);

        $calls = array_values(array_filter($query->calls(), static fn (array $c): bool => $c['method'] === 'appointments'));
        Assert::same(['2026-10-15 00:00:00', '2026-10-16 00:00:00', null, 51], $calls[0]['args']);
        Assert::same(['2026-10-20 00:00:00', '2026-10-21 00:00:00', 6, 51], $calls[1]['args']);
    }

    public function testUnknownProfessionalIsNotFoundInScope(): void
    {
        $query = $this->query();

        foreach ([new ConsultarAgendaTool($query, $this->clock()), new VerificarHorariosTool($query, $this->clock())] as $tool) {
            Assert::throws(
                ToolEntityNotFoundException::class,
                fn () => $tool->assertInScope($this->context(), ['data' => '2026-10-20', 'duracao_min' => 30, 'profissional_id' => 99]),
            );
            $tool->assertInScope($this->context(), ['data' => '2026-10-20', 'duracao_min' => 30, 'profissional_id' => 5]);
            $tool->assertInScope($this->context(), ['data' => '2026-10-20', 'duracao_min' => 30]);
        }
    }

    /**
     * Pendências 8B, T-08: an inactive member that still has appointments
     * can be asked about in consultar_agenda; verificar_horarios keeps
     * requiring an active professional (no free slots for an inactive one).
     */
    public function testConsultarAgendaAcceptsInactiveProfessionalButVerificarHorariosDoesNot(): void
    {
        $query = $this->query();
        $query->seed('inactive_professionals', [['id' => 8, 'name' => 'Carla Inativa']]);
        $input = ['data' => '2026-10-20', 'duracao_min' => 30, 'profissional_id' => 8];

        (new ConsultarAgendaTool($query, $this->clock()))->assertInScope($this->context(), $input);

        Assert::throws(
            ToolEntityNotFoundException::class,
            fn () => (new VerificarHorariosTool($query, $this->clock()))->assertInScope($this->context(), $input),
        );
        Assert::throws(
            ToolEntityNotFoundException::class,
            fn () => (new ConsultarAgendaTool($query, $this->clock()))->assertInScope($this->context(), ['profissional_id' => 99]),
        );
    }

    public function testConsultarAgendaOutputsFirstNamesOnlyAndTruncatesAtFifty(): void
    {
        $row = [
            'scheduled_at' => '2026-10-20 09:00:00',
            'patient_name' => 'Rex',
            'service_name' => 'Consulta',
            'duration_minutes' => 30,
            'status' => 'agendado',
            'professional_id' => 5,
            'professional_name' => 'Ana Paula Ribeiro',
            'cpf' => '12345678900',
            'phone' => '11999990000',
            'email' => 'ana@example.test',
            'address' => 'Rua A, 1',
            'tutor_full_name' => 'Maria Souza Lima',
        ];
        $query = $this->query();
        $query->seed('appointments', [$row]);

        $result = (new ConsultarAgendaTool($query, $this->clock()))->execute($this->context(), ['data' => '2026-10-20']);

        Assert::same(1, $result->rowCount);
        Assert::false($result->truncated);
        Assert::same([
            'horario' => '2026-10-20 09:00',
            'paciente' => 'Rex',
            'tipo' => 'Consulta',
            'status' => 'agendado',
            'profissional' => 'Ana',
        ], $result->data[0]);
        foreach (self::FORBIDDEN_KEYS as $key) {
            Assert::false(array_key_exists($key, $result->data[0]), "{$key} must not leave the tool");
        }
        Assert::false(str_contains((string) json_encode($result->data), 'Maria'), 'tutor name never leaves the tool');

        $many = $this->query();
        $many->seed('appointments', array_fill(0, 51, $row));
        $truncated = (new ConsultarAgendaTool($many, $this->clock()))->execute($this->context(), ['data' => '2026-10-20']);
        Assert::same(50, $truncated->rowCount);
        Assert::true($truncated->truncated);
    }

    public function testVerificarHorariosUsesBusyOfTheProfessionalAndFirstName(): void
    {
        $query = $this->query();
        $query->seed('busy', [
            ['professional_id' => 5, 'start' => '2026-10-20 07:00:00', 'end' => '2026-10-20 08:15:00'],
            ['professional_id' => 6, 'start' => '2026-10-20 07:00:00', 'end' => '2026-10-20 19:00:00'],
        ]);

        $result = (new VerificarHorariosTool($query, $this->clock()))
            ->execute($this->context(), ['data' => '2026-10-20', 'duracao_min' => 30, 'profissional_id' => 5]);

        Assert::same(['horario' => '2026-10-20 08:30', 'profissional_id' => 5, 'profissional' => 'Ana'], $result->data[0]);
        Assert::same([5], array_values(array_unique(array_column($result->data, 'profissional_id'))));
        Assert::same(21, $result->rowCount, '08:30..18:30');
        Assert::false($result->truncated);

        $busyCalls = array_values(array_filter($query->calls(), static fn (array $c): bool => $c['method'] === 'busy'));
        Assert::same(['2026-10-20', 5], $busyCalls[0]['args']);
    }

    public function testVerificarHorariosForEveryProfessionalKeepsAllWhenUnderTheLimit(): void
    {
        $result = (new VerificarHorariosTool($this->query(), $this->clock()))
            ->execute($this->context(), ['data' => '2026-10-20', 'duracao_min' => 30]);

        Assert::same(48, $result->rowCount, '2 professionals x 24 slots');
        Assert::false($result->truncated);
    }

    /**
     * T-27: without profissional_id only the schedulable professionals of the
     * unit are offered (never the administrative user), and the 50-row limit
     * is shared among them with slots spread over the whole day.
     */
    public function testVerificarHorariosSpreadsTheLimitAmongSchedulableProfessionals(): void
    {
        $query = new FakeCopilotAgendaQuery();
        $query->seed('professionals', [
            ['id' => 5, 'name' => 'Ana Paula Ribeiro'],
            ['id' => 6, 'name' => 'Bruno Costa'],
            ['id' => 7, 'name' => 'Carla Dias'],
            ['id' => 9, 'name' => 'Rita Recepcao'],
        ]);
        $query->seed('schedulableProfessionals', [
            ['id' => 5, 'name' => 'Ana Paula Ribeiro'],
            ['id' => 6, 'name' => 'Bruno Costa'],
            ['id' => 7, 'name' => 'Carla Dias'],
        ]);

        $result = (new VerificarHorariosTool($query, $this->clock()))
            ->execute($this->context(), ['data' => '2026-10-20', 'duracao_min' => 30]);

        Assert::true($result->rowCount <= 50, 'at most 50 rows');
        Assert::true($result->truncated, '3 x 24 free slots do not fit in 50 rows');
        Assert::false(in_array(9, array_column($result->data, 'profissional_id'), true), 'administrative user is not offered');
        Assert::same(['horario' => '2026-10-20 07:00', 'profissional_id' => 5, 'profissional' => 'Ana'], $result->data[0]);
        Assert::same(['horario' => '2026-10-20 07:00', 'profissional_id' => 6, 'profissional' => 'Bruno'], $result->data[1]);

        foreach ([5, 6, 7] as $professionalId) {
            $times = array_values(array_map(
                static fn (array $row): string => substr($row['horario'], 11),
                array_filter($result->data, static fn (array $row): bool => $row['profissional_id'] === $professionalId),
            ));
            Assert::true(count($times) >= 16, "professional {$professionalId} has at least 16 slots");
            Assert::true(min($times) < '09:00', "professional {$professionalId} has a morning slot");
            Assert::true(max($times) > '17:00', "professional {$professionalId} has a late afternoon slot");
        }
    }

    public function testFreeSlotFinderSpreadSharesTheLimitAndSpacesTheSlots(): void
    {
        $finder = new FreeSlotFinder();
        $slots = $finder->find(new DateTimeImmutable('2026-10-20'), 30, [], [5, 6, 7], new DateTimeImmutable('2026-10-15 10:10:00'));
        Assert::count(72, $slots);

        $spread = $finder->spread($slots, 50);
        Assert::count(48, $spread, 'quota intdiv(50, 3) = 16 per professional');
        Assert::same(
            ['07:00', '08:00', '08:30', '09:30', '10:00', '11:00', '11:30', '12:30', '13:00', '14:00', '14:30', '15:30', '16:00', '17:00', '17:30', '18:30'],
            array_map(static fn (string $start): string => substr($start, 11), $this->startsOf($spread, 6)),
            'indices round(i * 23 / 15)',
        );
        Assert::same(['start' => '2026-10-20 07:00', 'professional_id' => 5], $spread[0]);
        Assert::same(['start' => '2026-10-20 07:00', 'professional_id' => 7], $spread[2]);
        Assert::same(['start' => '2026-10-20 08:00', 'professional_id' => 5], $spread[3]);

        Assert::same($slots, $finder->spread($slots, 100), 'everything fits: unchanged');
        Assert::same([], $finder->spread([], 50));
        Assert::count(2, $finder->spread($slots, 2), 'quota is at least 1, output never above the limit');
    }
}
