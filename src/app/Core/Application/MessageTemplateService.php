<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\MessageTemplateRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Tenant-wide catalog of message templates (T-09): list, read, create or
 * edit, activate and deactivate. Enforces "one active template per purpose
 * and channel" through `countActiveFor()`, both when saving an active
 * template and when activating one; the closed placeholder list is checked
 * by the `MessageTemplate` entity. No transaction is opened: the controller
 * owns it.
 */
final class MessageTemplateService
{
    private const ENTITY_TYPE = 'message_template';
    private const STATUSES = [MessageTemplate::STATUS_ACTIVE, MessageTemplate::STATUS_INACTIVE];

    public function __construct(
        private readonly MessageTemplateRepositoryInterface $templates,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    /** @return list<MessageTemplate> every template of the tenant, any status. */
    public function list(string $action): array
    {
        $this->authorize($action, null);

        return array_values($this->templates->listAll());
    }

    public function find(int $templateId, string $action): MessageTemplate
    {
        return $this->requireTemplate($templateId, $action);
    }

    /**
     * Creates (no `id`) or edits (`id`) a template.
     *
     * @param array<string, mixed> $data keys `id` (optional), `purpose`, `channel`, `name`, `subject`, `body_text`, `status`
     *
     * @throws InvalidArgumentException for invalid fields, unknown placeholders or a second active template.
     * @throws CrossTenantReferenceException when `id` is not a template of the tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied (nothing is written)
     */
    public function save(array $data, string $action): MessageTemplate
    {
        $status = (string) ($data['status'] ?? MessageTemplate::STATUS_ACTIVE);

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown message template status \"{$status}\"");
        }

        $purpose = (string) ($data['purpose'] ?? '');
        $channel = (string) ($data['channel'] ?? '');
        $name = (string) ($data['name'] ?? '');
        $subject = isset($data['subject']) ? (string) $data['subject'] : null;
        $bodyText = (string) ($data['body_text'] ?? '');
        $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;

        if ($id !== null) {
            $template = $this->requireTemplate($id, $action);
            $template->update($purpose, $channel, $name, $subject, $bodyText, $this->context->userId());
        } else {
            $this->authorize($action, null);
            $template = MessageTemplate::create(
                $this->context->tenantId(),
                $purpose,
                $channel,
                $name,
                $subject,
                $bodyText,
                $this->context->userId(),
            );
        }

        $this->applyStatus($template, $status === MessageTemplate::STATUS_ACTIVE);
        $this->templates->save($template);

        return $template;
    }

    public function setActive(int $templateId, bool $active, string $action): MessageTemplate
    {
        $template = $this->requireTemplate($templateId, $action);
        $this->applyStatus($template, $active);
        $this->templates->save($template);

        return $template;
    }

    private function applyStatus(MessageTemplate $template, bool $active): void
    {
        if (!$active) {
            $template->deactivate();

            return;
        }

        if ($this->templates->countActiveFor($template->purpose(), $template->channel(), $template->id()) > 0) {
            throw new InvalidArgumentException('Another active template already exists for this purpose and channel');
        }

        $template->activate();
    }

    /** @throws CrossTenantReferenceException when missing or foreign (indistinguishable). */
    private function requireTemplate(int $templateId, string $action): MessageTemplate
    {
        $template = $templateId > 0 ? $this->templates->findById($templateId) : null;

        if (!$template instanceof MessageTemplate) {
            throw new CrossTenantReferenceException("template_id {$templateId} was not found for the authenticated tenant");
        }

        $this->authorize($action, $templateId);

        return $template;
    }

    private function authorize(string $action, ?int $templateId): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: false,
            entityType: self::ENTITY_TYPE,
            entityId: $templateId,
        ))->assertAllowed();
    }
}
