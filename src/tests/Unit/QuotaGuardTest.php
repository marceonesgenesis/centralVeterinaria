<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\QuotaExceededException;
use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\Subscription;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSubscriptionRepository;
use CentralVet\Tests\Support\FakeTenantUsageReader;
use DateTimeImmutable;

/**
 * Etapa 3, T-15: quota de usuários e de unidades pelo plano da assinatura.
 * Lança quando o uso atual já alcançou o limite; sem limite (Enterprise) ou
 * sem assinatura (legacy), não lança.
 */
final class QuotaGuardTest
{
    private const TENANT = 7;

    public function testStarterWithTwoUsersCannotAddAnother(): void
    {
        $usage = new FakeTenantUsageReader();
        $usage->users[self::TENANT] = 2;
        $guard = new QuotaGuard($this->subscriptions('starter'), $usage);

        $message = $this->messageOf(static fn () => $guard->assertCanAddUser(self::TENANT));

        Assert::same('Your plan allows up to 2 users', $message);
    }

    public function testStarterWithOneUserCanAddAnother(): void
    {
        $usage = new FakeTenantUsageReader();
        $usage->users[self::TENANT] = 1;
        $guard = new QuotaGuard($this->subscriptions('starter'), $usage);

        $guard->assertCanAddUser(self::TENANT);
        Assert::true(true);
    }

    public function testEnterpriseWithFiveHundredUsersDoesNotThrow(): void
    {
        $usage = new FakeTenantUsageReader();
        $usage->users[self::TENANT] = 500;
        $usage->units[self::TENANT] = 500;
        $guard = new QuotaGuard($this->subscriptions('enterprise'), $usage);

        $guard->assertCanAddUser(self::TENANT);
        $guard->assertCanAddUnit(self::TENANT);
        Assert::true(true);
    }

    public function testUnitQuotaFollowsThePlan(): void
    {
        $usage = new FakeTenantUsageReader();
        $usage->units[self::TENANT] = 1;

        $pro = new QuotaGuard($this->subscriptions('pro'), $usage);
        Assert::same(
            'Your plan allows up to 1 units',
            $this->messageOf(static fn () => $pro->assertCanAddUnit(self::TENANT)),
        );

        $business = new QuotaGuard($this->subscriptions('business'), $usage);
        $business->assertCanAddUnit(self::TENANT);

        $usage->units[self::TENANT] = 5;
        Assert::same(
            'Your plan allows up to 5 units',
            $this->messageOf(static fn () => $business->assertCanAddUnit(self::TENANT)),
        );
    }

    public function testTenantWithoutSubscriptionHasNoQuota(): void
    {
        $usage = new FakeTenantUsageReader();
        $usage->users[999] = 1000;
        $usage->units[999] = 1000;
        $guard = new QuotaGuard($this->subscriptions('starter'), $usage);

        $guard->assertCanAddUser(999);
        $guard->assertCanAddUnit(999);
        Assert::true(true);
    }

    private function subscriptions(string $planId): FakeSubscriptionRepository
    {
        $subscription = Subscription::start(
            10, $planId, 'card', 'cus_1', 'sub_1', 'active',
            new DateTimeImmutable('2026-10-01 00:00:00'), new DateTimeImmutable('2026-11-01 00:00:00'), 100,
        );
        $subscription->assignTenant(self::TENANT);

        $repository = new FakeSubscriptionRepository();
        $repository->insert($subscription);

        return $repository;
    }

    private function messageOf(callable $callback): string
    {
        try {
            $callback();
        } catch (QuotaExceededException $e) {
            return $e->getMessage();
        }

        throw new \RuntimeException('Expected QuotaExceededException');
    }
}
