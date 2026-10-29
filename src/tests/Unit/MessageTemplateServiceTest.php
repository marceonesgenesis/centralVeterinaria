<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\MessageTemplateService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeMessageTemplateRepository;
use InvalidArgumentException;

/**
 * Unit tests for MessageTemplateService (T-09): tenant-wide authorization,
 * one active template per purpose and channel (save and activation),
 * closed placeholder list and denied policy without writing.
 */
final class MessageTemplateServiceTest
{
    private const ACTION = 'test::message_template';
    private const TENANT_ID = 1;
    private const USER_ID = 7;
    private const DUPLICATE = 'Another active template already exists for this purpose and channel';

    /** @return array{0: MessageTemplateService, 1: FakeMessageTemplateRepository, 2: FakeAuthorizationPolicy} */
    private function build(bool $allowed = true, MessageTemplate ...$seed): array
    {
        $templates = new FakeMessageTemplateRepository(self::TENANT_ID, ...$seed);
        $policy = new FakeAuthorizationPolicy(allowed: $allowed);
        $context = TenantContext::authenticated(self::TENANT_ID, self::USER_ID);

        return [new MessageTemplateService($templates, $policy, $context), $templates, $policy];
    }

    /** @param array<string, mixed> $overrides */
    private static function template(array $overrides = []): MessageTemplate
    {
        return MessageTemplate::reconstitute($overrides + [
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'purpose' => 'vaccine_due',
            'channel' => 'whatsapp',
            'name' => 'F7A teste vacina',
            'subject' => null,
            'body_text' => 'Olá {{tutor_name}}, a vacina {{vaccine_name}} vence em {{due_date}}.',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'created_by_system_user_id' => self::USER_ID,
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function data(array $overrides = []): array
    {
        return $overrides + [
            'purpose' => 'vaccine_due',
            'channel' => 'whatsapp',
            'name' => 'F7A teste vacina',
            'subject' => '',
            'body_text' => 'Olá {{tutor_name}}, a vacina {{vaccine_name}} vence em {{due_date}}.',
            'status' => 'active',
        ];
    }

    /** @param class-string<\Throwable> $class */
    private static function expectMessage(string $class, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Assert::instanceOf($class, $e, "Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            Assert::same($message, $e->getMessage());

            return;
        }

        Assert::true(false, "Expected exception {$class} was not thrown");
    }

    public function testSaveCreatesActiveTemplate(): void
    {
        [$service, $templates, $policy] = $this->build();

        $template = $service->save(self::data(), self::ACTION);

        Assert::notNull($template->id());
        Assert::true($template->isActive());
        Assert::null($template->subject());
        Assert::same(self::USER_ID, $template->createdBySystemUserId());
        Assert::count(1, $templates->listAll());
        Assert::same('message_template', $policy->requests[0]->entityType());
        Assert::false($policy->requests[0]->requiresUnitScope());
    }

    public function testSaveCreatesInactiveTemplate(): void
    {
        [$service, $templates] = $this->build(true, self::template());

        $template = $service->save(self::data(['status' => 'inactive', 'name' => 'Rascunho']), self::ACTION);

        Assert::false($template->isActive());
        Assert::count(2, $templates->listAll());
    }

    public function testSecondActiveTemplateForSamePurposeAndChannelIsRejected(): void
    {
        [$service, $templates] = $this->build(true, self::template());

        self::expectMessage(InvalidArgumentException::class, self::DUPLICATE, fn () => $service->save(self::data(['name' => 'Outro']), self::ACTION));
        Assert::count(1, $templates->listAll());
    }

    public function testActiveTemplateOnOtherChannelIsAccepted(): void
    {
        [$service, $templates] = $this->build(true, self::template());

        $service->save(self::data(['channel' => 'email', 'subject' => 'Vacina de {{patient_name}}']), self::ACTION);

        Assert::count(2, $templates->listAll());
    }

    public function testUpdatingTheActiveTemplateItselfIsAccepted(): void
    {
        [$service, $templates] = $this->build(true, self::template());

        $template = $service->save(self::data(['id' => 1, 'name' => 'Renomeado']), self::ACTION);

        Assert::same('Renomeado', $template->name());
        Assert::same(self::USER_ID, $template->updatedBySystemUserId());
        Assert::same('Renomeado', $templates->findById(1)->name());
    }

    public function testActivatingSecondTemplateIsRejected(): void
    {
        [$service, $templates] = $this->build(
            true,
            self::template(),
            self::template(['id' => 2, 'name' => 'Inativo', 'status' => MessageTemplate::STATUS_INACTIVE]),
        );

        self::expectMessage(InvalidArgumentException::class, self::DUPLICATE, fn () => $service->setActive(2, true, self::ACTION));
        Assert::false($templates->findById(2)->isActive());
    }

    public function testDeactivateThenActivateOther(): void
    {
        [$service, $templates] = $this->build(
            true,
            self::template(),
            self::template(['id' => 2, 'name' => 'Inativo', 'status' => MessageTemplate::STATUS_INACTIVE]),
        );

        $service->setActive(1, false, self::ACTION);
        $service->setActive(2, true, self::ACTION);

        Assert::false($templates->findById(1)->isActive());
        Assert::true($templates->findById(2)->isActive());
    }

    public function testUnknownPlaceholderIsRejected(): void
    {
        [$service, $templates] = $this->build();

        self::expectMessage(
            InvalidArgumentException::class,
            'Unknown placeholder "cpf" in template',
            fn () => $service->save(self::data(['body_text' => 'CPF {{cpf}}']), self::ACTION),
        );
        Assert::count(0, $templates->listAll());
    }

    public function testUnknownStatusIsRejected(): void
    {
        [$service, $templates] = $this->build();

        Assert::throws(InvalidArgumentException::class, fn () => $service->save(self::data(['status' => 'draft']), self::ACTION));
        Assert::count(0, $templates->listAll());
    }

    public function testDeniedSaveDoesNotWrite(): void
    {
        [$service, $templates] = $this->build(false);

        Assert::throws(AuthorizationDenied::class, fn () => $service->save(self::data(), self::ACTION));
        Assert::count(0, $templates->listAll());
    }

    public function testDeniedSetActiveDoesNotWrite(): void
    {
        [$service, $templates] = $this->build(false, self::template());

        Assert::throws(AuthorizationDenied::class, fn () => $service->setActive(1, false, self::ACTION));
        Assert::true($templates->findById(1)->isActive());
    }

    public function testFindAndListAuthorize(): void
    {
        [$service, , $policy] = $this->build(true, self::template());

        Assert::same(1, $service->find(1, self::ACTION)->id());
        Assert::count(1, $service->list(self::ACTION));
        Assert::count(2, $policy->requests);
        Assert::same(1, $policy->requests[0]->entityId());
    }

    public function testFindOfMissingOrForeignTemplateThrows(): void
    {
        [$service] = $this->build(true, self::template(['id' => 3, 'tenant_id' => 2]));

        self::expectMessage(
            CrossTenantReferenceException::class,
            'template_id 3 was not found for the authenticated tenant',
            fn () => $service->find(3, self::ACTION),
        );
    }
}
