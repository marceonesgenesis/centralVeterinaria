<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\PendingCenterService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\PendingItem;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakePendingItemQuery;
use DateTimeImmutable;

/**
 * Unit tests for PendingCenterService (T-13): ordering by priority rank,
 * then dueAt, then type and sourceId; type filter; "only mine" filter;
 * counts per type over the unfiltered list; authorization before the query.
 */
final class PendingCenterServiceTest
{
    private const ACTION = 'test::pending_center';
    private const TENANT_ID = 1;
    private const USER_ID = 7;
    private const OTHER_USER_ID = 8;
    private const UNIT_ID = 5;
    private const NOW = '2026-10-06 12:00:00';

    /** @param list<PendingItem> $items
     *  @return array{0: PendingCenterService, 1: FakePendingItemQuery, 2: FakeAuthorizationPolicy} */
    private function build(array $items, bool $allowed = true): array
    {
        $query = new FakePendingItemQuery($items);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW);

        return [new PendingCenterService($query, $policy, $context, $clock), $query, $policy];
    }

    private static function item(string $type, int $sourceId, string $dueAt, ?int $responsible = null): PendingItem
    {
        [$class, $params] = match ($type) {
            PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION => ['HospitalizationView', ['id' => $sourceId, 'tab' => 'administrations']],
            PendingItem::TYPE_EXAM_RESULT, PendingItem::TYPE_EXAM_REVIEW => ['ExamResultForm', ['exam_request_id' => $sourceId]],
            PendingItem::TYPE_RECEIVABLE_OPEN => ['PaymentForm', ['receivable_id' => $sourceId]],
            PendingItem::TYPE_VACCINE_DUE => ['VaccinationCardView', ['patient_id' => $sourceId]],
            PendingItem::TYPE_RETURN_APPOINTMENT => ['AgendaView', ['date' => '2026-10-06']],
            default => ['CommunicationMessageView', ['id' => $sourceId]],
        };

        return new PendingItem($type, $sourceId, 'F7A teste', 'assunto', new DateTimeImmutable($dueAt), $responsible, $class, $params);
    }

    /** @param list<PendingItem> $items
     *  @return list<string> */
    private static function keys(array $items): array
    {
        return array_map(static fn (PendingItem $i): string => $i->type() . '#' . $i->sourceId(), $items);
    }

    public function testOrdersByPriorityThenDueAtThenTypeAndSource(): void
    {
        [$service, $query, $policy] = $this->build([
            self::item(PendingItem::TYPE_RECEIVABLE_OPEN, 30, '2026-10-20 12:00:00'),            // low
            self::item(PendingItem::TYPE_EXAM_RESULT, 20, '2026-10-05 12:00:00'),                // high (overdue)
            self::item(PendingItem::TYPE_EXAM_RESULT, 21, '2026-10-06 18:00:00'),                // normal
            self::item(PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION, 10, '2026-10-06 11:00:00'), // urgent
            self::item(PendingItem::TYPE_EXAM_REVIEW, 5, '2026-10-05 12:00:00'),                 // high, same dueAt
            self::item(PendingItem::TYPE_EXAM_RESULT, 2, '2026-10-05 12:00:00'),                 // high, same dueAt+type
        ]);

        $list = $service->list(null, false, self::ACTION);

        Assert::same([
            'hospitalization_administration#10',
            'exam_result#2',
            'exam_result#20',
            'exam_review#5',
            'exam_result#21',
            'receivable_open#30',
        ], self::keys($list));
        Assert::count(1, $query->calls());
        Assert::same(self::UNIT_ID, $query->calls()[0][1]);
        Assert::same(self::NOW, $query->calls()[0][2]->format('Y-m-d H:i:s'));
        Assert::same(200, $query->calls()[0][3]);

        $request = $policy->requests[0];
        Assert::same(self::ACTION, $request->action());
        Assert::true($request->requiresUnitScope());
        Assert::same(self::UNIT_ID, $request->resourceUnitId());
        Assert::same('pending_center', $request->entityType());
    }

    public function testFiltersByTypeAndIgnoresUnknownType(): void
    {
        [$service] = $this->build([
            self::item(PendingItem::TYPE_EXAM_RESULT, 1, '2026-10-05 12:00:00'),
            self::item(PendingItem::TYPE_RECEIVABLE_OPEN, 2, '2026-10-05 12:00:00'),
        ]);

        Assert::same(['receivable_open#2'], self::keys($service->list(PendingItem::TYPE_RECEIVABLE_OPEN, false, self::ACTION)));
        Assert::count(2, $service->list('bogus', false, self::ACTION));
        Assert::count(2, $service->list('', false, self::ACTION));
    }

    public function testOnlyMineKeepsItemsOfContextUser(): void
    {
        [$service] = $this->build([
            self::item(PendingItem::TYPE_EXAM_RESULT, 1, '2026-10-05 12:00:00', self::USER_ID),
            self::item(PendingItem::TYPE_EXAM_REVIEW, 2, '2026-10-05 12:00:00', self::OTHER_USER_ID),
            self::item(PendingItem::TYPE_RECEIVABLE_OPEN, 3, '2026-10-05 12:00:00', null),
            self::item(PendingItem::TYPE_MESSAGE_FAILED, 4, '2026-10-04 12:00:00', self::USER_ID),
        ]);

        Assert::same(['message_failed#4', 'exam_result#1'], self::keys($service->list(null, true, self::ACTION)));
        Assert::same(['exam_result#1'], self::keys($service->list(PendingItem::TYPE_EXAM_RESULT, true, self::ACTION)));
    }

    public function testCountsByTypeHasAllEightTypesIncludingZeros(): void
    {
        [$service] = $this->build([
            self::item(PendingItem::TYPE_EXAM_RESULT, 1, '2026-10-05 12:00:00'),
            self::item(PendingItem::TYPE_EXAM_RESULT, 2, '2026-10-05 12:00:00'),
            self::item(PendingItem::TYPE_RECEIVABLE_OPEN, 3, '2026-10-05 12:00:00'),
        ]);

        $counts = $service->countsByType(self::ACTION);

        Assert::same(PendingItem::TYPES, array_keys($counts));
        Assert::same(2, $counts[PendingItem::TYPE_EXAM_RESULT]);
        Assert::same(1, $counts[PendingItem::TYPE_RECEIVABLE_OPEN]);
        Assert::same(0, $counts[PendingItem::TYPE_VACCINE_DUE]);
        Assert::same(0, $counts[PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION]);
    }

    public function testDeniedPolicyThrowsBeforeQuery(): void
    {
        [$service, $query] = $this->build([self::item(PendingItem::TYPE_EXAM_RESULT, 1, '2026-10-05 12:00:00')], false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->list(null, false, self::ACTION));
        Assert::throws(AuthorizationDenied::class, fn () => $service->countsByType(self::ACTION));
        Assert::same([], $query->calls());
    }

    public function testNowUsesInjectedClock(): void
    {
        [$service] = $this->build([]);

        Assert::same(self::NOW, $service->now()->format('Y-m-d H:i:s'));
    }
}
