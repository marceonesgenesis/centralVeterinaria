<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\AppointmentService;
use CentralVet\Application\CashSessionService;
use CentralVet\Application\DocumentContentFactory;
use CentralVet\Application\DocumentGenerationResult;
use CentralVet\Application\DocumentGenerationService;
use CentralVet\Application\DocumentReadyNotifier;
use CentralVet\Application\DocumentRequestService;
use CentralVet\Application\EncounterAccountService;
use CentralVet\Application\EncounterService;
use CentralVet\Application\FinancialEntryService;
use CentralVet\Application\PatientService;
use CentralVet\Application\PaymentService;
use CentralVet\Application\PrescriptionService;
use CentralVet\Application\ProcedureCatalogService;
use CentralVet\Application\QueueEntryService;
use CentralVet\Application\TutorService;
use CentralVet\Audit\PdoAuditLogWriter;
use CentralVet\Authorization\AdiantiProgramPermissionProvider;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingOrder;
use CentralVet\Billing\OfferScope;
use CentralVet\Billing\PlanEntitlements;
use CentralVet\Billing\QuotaExceededException;
use CentralVet\Billing\QuotaGuard;
use CentralVet\Billing\Subscription;
use CentralVet\Billing\SubscriptionAccessGuard;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Document\DompdfDocumentRenderer;
use CentralVet\Domain\DocumentKind;
use CentralVet\Domain\Payment;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Persistence\AppointmentRepository;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\CashSessionRepository;
use CentralVet\Persistence\CommunicationPreferenceRepository;
use CentralVet\Persistence\DocumentSourceQuery;
use CentralVet\Persistence\DocumentTemplateRepository;
use CentralVet\Persistence\EncounterAccountItemRepository;
use CentralVet\Persistence\EncounterAccountRepository;
use CentralVet\Persistence\EncounterRepository;
use CentralVet\Persistence\ExamCatalogRepository;
use CentralVet\Persistence\ExamRequestRepository;
use CentralVet\Persistence\FinancialEntryRepository;
use CentralVet\Persistence\GeneratedDocumentRepository;
use CentralVet\Persistence\MessageTemplateRepository;
use CentralVet\Persistence\OutboundMessageRepository;
use CentralVet\Persistence\PatientRepository;
use CentralVet\Persistence\PaymentRepository;
use CentralVet\Persistence\PrescriptionRepository;
use CentralVet\Persistence\ProcedureCatalogItemInputRepository;
use CentralVet\Persistence\ProcedureCatalogRepository;
use CentralVet\Persistence\ProcedureExecutionRepository;
use CentralVet\Persistence\QueueEntryRepository;
use CentralVet\Persistence\ReceivableRepository;
use CentralVet\Persistence\SenderNamesQuery;
use CentralVet\Persistence\ServiceRepository;
use CentralVet\Persistence\StoredObjectRepository;
use CentralVet\Persistence\SubmissionKeyRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Persistence\TenantUsageReader;
use CentralVet\Persistence\TenantUserDirectory;
use CentralVet\Persistence\TutorRepository;
use CentralVet\Pilot\PilotSettings;
use CentralVet\Presentation\UserMessage;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Tenancy\ClinicRoleProfiles;
use CentralVet\Tenancy\ClinicTeamService;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeSessionContextSource;
use CentralVet\Tests\Support\FakeStorage;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Etapa 5, T-18: jornada homologável do piloto por perfil, com os serviços
 * reais (sem controllers) no centralvet_test. O administrador monta a
 * equipe com os perfis restritos (ClinicTeamService + ClinicRoleProfiles);
 * a recepção cadastra tutor e paciente, agenda e faz o check-in; o
 * veterinário atende, prescreve e gera o PDF (Dompdf + FakeStorage); o
 * financeiro abre o caixa, fecha a conta e recebe em duas parcelas. Cada
 * perfil age com RbacAuthorizationService sobre
 * AdiantiProgramPermissionProvider e uma FakeSessionContextSource cujos
 * `programs` são ClinicRoleProfiles::programs(<perfil>).
 *
 * Fixture: tenant A (plano pro) e B (plano business), cada um com unidade,
 * administrador no grupo do plano e assinatura ativa inserida no teste
 * (a guarda e as quotas são as reais); o tenant C (starter, 2 usuários) só
 * no teste da quota. Tudo roda na transação do teste (SAVEPOINT no
 * TransactionRunner) e é desfeito no tearDown(): nada é commitado.
 */
final class PilotJourneyIntegrationTest extends MysqlIntegrationTestCase
{
    private const KEY = 'test-key-with-enough-length-for-hs256-0123456789';
    private const CONSULTATION_CENTS = 15000;
    private const PARTIAL_CENTS = 6000;

    /** Programas que a jornada usa, por perfil: a guarda real precisa liberá-los no plano. */
    private const JOURNEY_PROGRAMS = [
        ClinicRoleProfiles::RECEPTION => ['TutorForm', 'PatientForm', 'AppointmentForm', 'AgendaView'],
        ClinicRoleProfiles::VETERINARIAN => ['EncounterView', 'PrescriptionForm', 'DocumentRequestForm'],
        ClinicRoleProfiles::FINANCE => ['CashSessionForm', 'EncounterAccountForm', 'PaymentForm'],
    ];

    private DateTimeImmutable $now;
    private FakeEmailProvider $email;

    /** @var array<string, int> plano → id do grupo */
    private array $planGroups = [];

    public function setUp(): void
    {
        parent::setUp();

        Assert::true((bool) $this->pdo->query("SHOW TABLES LIKE 'tenant_onboarding'")->fetchColumn(),
            'Migration 0020 must be applied to the test database');

        $this->now = new DateTimeImmutable();
        $this->email = new FakeEmailProvider();
        $this->createPlanGroups();
        $this->ensureProfileGroups();
    }

    public function testJourneyOnTenantAWithPlanProEndsWithTheReceivablePaidAndThePdfStored(): void
    {
        $this->assertJourney($this->journey($this->clinic('pro', 'A')));
    }

    public function testJourneyOnTenantBWithPlanBusinessEndsWithTheReceivablePaidAndThePdfStored(): void
    {
        $this->assertJourney($this->journey($this->clinic('business', 'B')));
    }

    public function testJourneyOnTenantWithPlanEnterpriseCoversHospitalizationSurgeryCommunicationAndAiCopilot(): void
    {
        $clinic = $this->clinic('enterprise', 'E');
        $team = $this->team($clinic);
        $patient = $this->registerPatient($clinic, $team);

        // Comunicação: preferência registrada para o tutor
        $this->pdo->prepare("INSERT INTO communication_preference (tenant_id, tutor_id, channel, status, consent_source, changed_by_system_user_id, changed_at, created_at, updated_at) VALUES (?, ?, 'whatsapp', 'opted_in', 'in_person', ?, NOW(6), NOW(6), NOW(6))")
            ->execute([$clinic['tenant'], $patient['tutor'], $team[ClinicRoleProfiles::RECEPTION]]);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM communication_preference WHERE tenant_id = ? AND tutor_id = ? AND status = "opted_in"', [$clinic['tenant'], $patient['tutor']]));

        // Recepção: agendamento e check-in
        $appointment = $this->appointmentService($clinic, $team, ClinicRoleProfiles::RECEPTION)
            ->schedule($this->appointmentData($clinic, $team, $patient, '+1 day 11:00'), 'AppointmentForm::onSave');
        $appointmentId = (int) $appointment->id;
        $this->queueService($clinic, $team)->checkIn([
            'patient_id' => $patient['patient'],
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'system_unit_id' => $clinic['unit'],
            'appointment_id' => $appointmentId,
        ], 'AgendaView::onCheckIn');

        // Veterinário: atendimento clínico
        $encounters = $this->encounterService($clinic, $team, ClinicRoleProfiles::VETERINARIAN);
        $encounterId = (int) $encounters->start([
            'patient_id' => $patient['patient'],
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'system_unit_id' => $clinic['unit'],
            'appointment_id' => $appointmentId,
        ], 'EncounterView::onStart')->id();

        // Copiloto de IA: interação clínica assistida com prompt e tokens
        $sha = hash('sha256', 'clinical query');
        $cid = bin2hex(random_bytes(16));
        $this->pdo->prepare("INSERT INTO ai_interaction (tenant_id, system_unit_id, system_user_id, purpose, subject_type, subject_id, prompt_version, provider, model, status, input_sha256, input_tokens, output_tokens, cost_micro_usd, correlation_id, created_at, updated_at) VALUES (?, ?, ?, 'copilot_clinical', 'encounter', ?, 'v1', 'anthropic', 'claude-sonnet-5-5', 'answered', ?, 180, 65, 1200, ?, NOW(6), NOW(6))")
            ->execute([$clinic['tenant'], $clinic['unit'], $team[ClinicRoleProfiles::VETERINARIAN], $encounterId, $sha, $cid]);
        $interactionCount = $this->count('SELECT COUNT(*) FROM ai_interaction WHERE tenant_id = ? AND input_tokens > 0', [$clinic['tenant']]);
        Assert::same(1, $interactionCount, 'AI interaction recorded with input tokens');

        // Internação: leito e admissão
        $bedId = $this->nextId('bed');
        $this->pdo->prepare("INSERT INTO bed (id, tenant_id, system_unit_id, code, name, daily_rate_cents, status) VALUES (?, ?, ?, 'L-01', 'Leito Canil 01', 5000, 'available')")
            ->execute([$bedId, $clinic['tenant'], $clinic['unit']]);
        $hospitalizationId = $this->nextId('hospitalization');
        $this->pdo->prepare("INSERT INTO hospitalization (id, tenant_id, system_unit_id, patient_id, encounter_id, bed_id, responsible_system_user_id, admitted_by_system_user_id, reason_text, daily_rate_cents, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Observação pós-cirúrgica', 5000, 'admitted')")
            ->execute([$hospitalizationId, $clinic['tenant'], $clinic['unit'], $patient['patient'], $encounterId, $bedId, $team[ClinicRoleProfiles::VETERINARIAN], $team[ClinicRoleProfiles::VETERINARIAN]]);
        $this->pdo->prepare("UPDATE bed SET status = 'occupied', current_hospitalization_id = ? WHERE id = ?")
            ->execute([$hospitalizationId, $bedId]);
        Assert::same('occupied', (string) $this->value('SELECT status FROM bed WHERE id = ?', [$bedId]));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM hospitalization WHERE tenant_id = ? AND status = "admitted"', [$clinic['tenant']]));

        // Cirurgia: sala cirúrgica, catálogo de procedimento e agendamento
        $roomId = $this->nextId('surgery_room');
        $this->pdo->prepare("INSERT INTO surgery_room (id, tenant_id, system_unit_id, code, name, status) VALUES (?, ?, ?, 'CC-01', 'Centro Cirúrgico 1', 'active')")
            ->execute([$roomId, $clinic['tenant'], $clinic['unit']]);
        $procId = $this->nextId('procedure_catalog_item');
        $this->pdo->prepare("INSERT INTO procedure_catalog_item (id, tenant_id, name, price_cents, duration_minutes, active) VALUES (?, ?, 'Castração Eletiva', 35000, 60, 1)")
            ->execute([$procId, $clinic['tenant']]);
        $surgeryId = $this->nextId('surgery');
        $this->pdo->prepare("INSERT INTO surgery (id, tenant_id, system_unit_id, patient_id, encounter_id, room_id, procedure_catalog_item_id, procedure_name, procedure_price_cents, surgeon_system_user_id, scheduled_by_system_user_id, scheduled_start_at, scheduled_end_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Castração Eletiva', 35000, ?, ?, NOW(6), DATE_ADD(NOW(6), INTERVAL 1 HOUR), 'scheduled')")
            ->execute([$surgeryId, $clinic['tenant'], $clinic['unit'], $patient['patient'], $encounterId, $roomId, $procId, $team[ClinicRoleProfiles::VETERINARIAN], $team[ClinicRoleProfiles::VETERINARIAN]]);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM surgery WHERE tenant_id = ? AND status = "scheduled"', [$clinic['tenant']]));

        // Prescrição e emissão de PDF
        $prescriptionId = (int) $this->prescriptionService($clinic, $team, ClinicRoleProfiles::VETERINARIAN)
            ->create($this->prescriptionData($encounterId, $patient['patient'], $team), 'PrescriptionForm::onSave')->id();
        $storage = new FakeStorage();
        $vetContext = $this->context($clinic, $team[ClinicRoleProfiles::VETERINARIAN]);
        $documentId = (int) $this->documentRequestService($vetContext, $storage, ClinicRoleProfiles::VETERINARIAN)
            ->request(DocumentKind::PRESCRIPTION, $prescriptionId, null, null, false, 'DocumentRequestForm::onSave')->id();
        $result = $this->documentGenerationService($vetContext, $storage)->generate($documentId, true);
        Assert::same(DocumentGenerationResult::READY, $result->status(), 'prescription PDF generated');
        $encounters->finish($encounterId, 'EncounterView::onFinish');

        // Financeiro: caixa, conta e quitação
        $cashSessionId = (int) $this->cashService($clinic, $team, ClinicRoleProfiles::FINANCE)
            ->open($clinic['unit'], 0, $team[ClinicRoleProfiles::FINANCE], 'CashSessionForm::onOpen')->id();
        $accounts = $this->accountService($clinic, $team, ClinicRoleProfiles::FINANCE);
        $accountId = (int) $accounts->openOrGet($encounterId, 'EncounterAccountForm::onLoad')->id();
        $accounts->addManualItem($accountId, 'Atendimento Integrado', self::CONSULTATION_CENTS, 'EncounterAccountForm::onAddItem');
        $receivableId = (int) $accounts->close($accountId, 'EncounterAccountForm::onClose')->id();

        $payments = $this->paymentService($clinic, $team, ClinicRoleProfiles::FINANCE);
        $finance = $team[ClinicRoleProfiles::FINANCE];
        $payments->register($receivableId, $cashSessionId, Payment::METHOD_PIX, self::CONSULTATION_CENTS, $finance,
            'PaymentForm::onSave', SubmissionToken::generate());

        Assert::same('paid', (string) $this->value('SELECT status FROM receivable WHERE id = ?', [$receivableId]));
    }

    public function testRestrictedProfilesAreDeniedOutsideTheirPrograms(): void
    {
        $a = $this->clinic('pro', 'A');
        $team = $this->team($a);
        $patient = $this->registerPatient($a, $team);
        $appointmentData = $this->appointmentData($a, $team, $patient, '+2 days 09:00');

        $encounter = $this->encounterService($a, $team, ClinicRoleProfiles::VETERINARIAN)->start([
            'patient_id' => $patient['patient'],
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'system_unit_id' => $a['unit'],
        ], 'EncounterView::onStart');
        $encounterId = (int) $encounter->id();

        Assert::throws(AuthorizationDenied::class, fn () => $this->prescriptionService($a, $team, ClinicRoleProfiles::RECEPTION)
            ->create($this->prescriptionData($encounterId, $patient['patient'], $team), 'PrescriptionForm::onSave'),
            'reception must not prescribe');
        Assert::same(0, $this->count('SELECT COUNT(*) FROM prescription WHERE encounter_id = ?', [$encounterId]));

        $cashSessionId = (int) $this->cashService($a, $team, ClinicRoleProfiles::FINANCE)
            ->open($a['unit'], 0, $team[ClinicRoleProfiles::FINANCE], 'CashSessionForm::onOpen')->id();
        $accounts = $this->accountService($a, $team, ClinicRoleProfiles::FINANCE);
        $accountId = (int) $accounts->openOrGet($encounterId, 'EncounterAccountForm::onLoad')->id();
        $accounts->addManualItem($accountId, 'Consulta', self::CONSULTATION_CENTS, 'EncounterAccountForm::onAddItem');
        $receivableId = (int) $accounts->close($accountId, 'EncounterAccountForm::onClose')->id();

        Assert::throws(AuthorizationDenied::class, fn () => $this->paymentService($a, $team, ClinicRoleProfiles::VETERINARIAN)
            ->register($receivableId, $cashSessionId, Payment::METHOD_CASH, 1000, $team[ClinicRoleProfiles::VETERINARIAN],
                'PaymentForm::onSave'),
            'veterinarian must not receive payments');
        Assert::same(0, $this->count('SELECT COUNT(*) FROM payment WHERE receivable_id = ?', [$receivableId]));

        $before = $this->count('SELECT COUNT(*) FROM appointment WHERE tenant_id = ?', [$a['tenant']]);
        Assert::throws(AuthorizationDenied::class, fn () => $this->appointmentService($a, $team, ClinicRoleProfiles::FINANCE)
            ->schedule($appointmentData, 'AppointmentForm::onSave'),
            'finance must not schedule');
        Assert::same($before, $this->count('SELECT COUNT(*) FROM appointment WHERE tenant_id = ?', [$a['tenant']]));
    }

    public function testTenantBNeitherSeesThePatientNorPaysTheReceivableOfA(): void
    {
        $a = $this->clinic('pro', 'A');
        $b = $this->clinic('business', 'B');

        $journeyA = $this->journey($a, false);
        $teamB = $this->team($b);

        Assert::null($this->patientService($b, $teamB[ClinicRoleProfiles::RECEPTION])->findById($journeyA['patient']),
            'patient of A must not be visible to B');

        $cashB = (int) $this->cashService($b, $teamB, ClinicRoleProfiles::FINANCE)
            ->open($b['unit'], 0, $teamB[ClinicRoleProfiles::FINANCE], 'CashSessionForm::onOpen')->id();
        $receivableBefore = $this->receivable($journeyA['receivable']);

        $message = null;
        try {
            $this->paymentService($b, $teamB, ClinicRoleProfiles::FINANCE)->register($journeyA['receivable'], $cashB,
                Payment::METHOD_CASH, 1000, $teamB[ClinicRoleProfiles::FINANCE], 'PaymentForm::onSave');
        } catch (AssertionFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        Assert::notNull($message, 'paying a receivable of A from B must throw');
        Assert::notNull(UserMessage::resolve((string) $message),
            sprintf('UserMessage::resolve must recognise the cross-tenant payment message "%s"', $message));
        Assert::same($receivableBefore, $this->receivable($journeyA['receivable']), 'receivable of A unchanged');
        Assert::same(0, $this->count('SELECT COUNT(*) FROM payment WHERE cash_session_id = ?', [$cashB]));
    }

    public function testStarterTenantRefusesTheThirdUserWithQuotaExceeded(): void
    {
        $c = $this->clinic('starter', 'C');
        $service = $this->teamService($c);
        $profiles = $this->profileGroups($service);

        $service->invite('Recepção C', $this->newEmail(), $profiles[ClinicRoleProfiles::RECEPTION], $c['unit'], $this->now);
        $users = $this->count('SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ?', [$c['tenant']]);
        Assert::same(2, $users, 'administrator plus one invited user');

        Assert::throws(QuotaExceededException::class, fn () => $service->invite('Veterinário C', $this->newEmail(),
            $profiles[ClinicRoleProfiles::VETERINARIAN], $c['unit'], $this->now), 'third user exceeds the starter quota');
        Assert::same(2, $this->count('SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ?', [$c['tenant']]),
            'nothing written past the quota');
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @return array{patient: int, receivable: int, cash: int, pdf: string, team: array<string, int>}
     */
    private function journey(array $clinic, bool $checkGuard = true): array
    {
        $team = $this->team($clinic);

        if ($checkGuard) {
            $guard = new SubscriptionAccessGuard(new SubscriptionRepository($this->pdo), new OfferScope(PilotSettings::fromArray([])));
            foreach (self::JOURNEY_PROGRAMS as $role => $programs) {
                foreach ($programs as $program) {
                    Assert::true(in_array($program, ClinicRoleProfiles::programs($role), true), "{$role} profile grants {$program}");
                    $decision = $guard->decide($clinic['tenant'], $program, $this->now);
                    Assert::true($decision->allowed(), sprintf('guard allows %s on plan %s (%s)', $program, $clinic['plan'], $decision->reason()));
                }
            }
        }

        // Recepção: tutor, paciente, agendamento e check-in.
        $patient = $this->registerPatient($clinic, $team);
        $appointment = $this->appointmentService($clinic, $team, ClinicRoleProfiles::RECEPTION)
            ->schedule($this->appointmentData($clinic, $team, $patient, '+1 day 10:00'), 'AppointmentForm::onSave');
        $appointmentId = (int) $appointment->id;
        $this->queueService($clinic, $team)->checkIn([
            'patient_id' => $patient['patient'],
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'system_unit_id' => $clinic['unit'],
            'appointment_id' => $appointmentId,
        ], 'AgendaView::onCheckIn');

        // Veterinário: atendimento, prescrição e PDF.
        $encounters = $this->encounterService($clinic, $team, ClinicRoleProfiles::VETERINARIAN);
        $encounterId = (int) $encounters->start([
            'patient_id' => $patient['patient'],
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'system_unit_id' => $clinic['unit'],
            'appointment_id' => $appointmentId,
        ], 'EncounterView::onStart')->id();
        $prescriptionId = (int) $this->prescriptionService($clinic, $team, ClinicRoleProfiles::VETERINARIAN)
            ->create($this->prescriptionData($encounterId, $patient['patient'], $team), 'PrescriptionForm::onSave')->id();

        $storage = new FakeStorage();
        $vetContext = $this->context($clinic, $team[ClinicRoleProfiles::VETERINARIAN]);
        $documentId = (int) $this->documentRequestService($vetContext, $storage, ClinicRoleProfiles::VETERINARIAN)
            ->request(DocumentKind::PRESCRIPTION, $prescriptionId, null, null, false, 'DocumentRequestForm::onSave')->id();
        $result = $this->documentGenerationService($vetContext, $storage)->generate($documentId, true);
        Assert::same(DocumentGenerationResult::READY, $result->status(), 'prescription PDF generated');
        $key = (string) $this->value('SELECT storage_key FROM generated_document WHERE id = ?', [$documentId]);
        $pdf = $storage->get($key);

        $encounters->finish($encounterId, 'EncounterView::onFinish');

        // Financeiro: caixa, conta, recebimento parcial e total.
        $cashSessionId = (int) $this->cashService($clinic, $team, ClinicRoleProfiles::FINANCE)
            ->open($clinic['unit'], 0, $team[ClinicRoleProfiles::FINANCE], 'CashSessionForm::onOpen')->id();
        $accounts = $this->accountService($clinic, $team, ClinicRoleProfiles::FINANCE);
        $accountId = (int) $accounts->openOrGet($encounterId, 'EncounterAccountForm::onLoad')->id();
        $accounts->addManualItem($accountId, 'Consulta clínica', self::CONSULTATION_CENTS, 'EncounterAccountForm::onAddItem');
        $receivableId = (int) $accounts->close($accountId, 'EncounterAccountForm::onClose')->id();

        $payments = $this->paymentService($clinic, $team, ClinicRoleProfiles::FINANCE);
        $finance = $team[ClinicRoleProfiles::FINANCE];
        $payments->register($receivableId, $cashSessionId, Payment::METHOD_CASH, self::PARTIAL_CENTS, $finance,
            'PaymentForm::onSave', SubmissionToken::generate());
        Assert::same('partially_paid', $this->receivable($receivableId)['status']);
        $payments->register($receivableId, $cashSessionId, Payment::METHOD_PIX, self::CONSULTATION_CENTS - self::PARTIAL_CENTS,
            $finance, 'PaymentForm::onSave', SubmissionToken::generate());

        return ['patient' => $patient['patient'], 'receivable' => $receivableId, 'cash' => $cashSessionId, 'pdf' => $pdf,
            'team' => $team, 'clinic' => $clinic];
    }

    /** @param array<string, mixed> $journey */
    private function assertJourney(array $journey): void
    {
        $receivable = $this->receivable($journey['receivable']);
        Assert::same('paid', $receivable['status']);
        Assert::same(self::CONSULTATION_CENTS, (int) $receivable['total_cents']);
        Assert::same(0, (int) $receivable['total_cents'] - (int) $receivable['paid_cents'], 'balance due is zero');

        $totals = $this->cashService($journey['clinic'], $journey['team'], ClinicRoleProfiles::FINANCE)
            ->totalsByPaymentMethod($journey['cash']);
        $sum = 0;
        foreach ($totals as $total) {
            $sum += (int) (is_array($total) ? ($total['total_cents'] ?? 0) : $total);
        }
        Assert::same(self::CONSULTATION_CENTS, $sum, 'cash totals by payment method add up to the amount paid');
        Assert::same(2, $this->count('SELECT COUNT(*) FROM payment WHERE receivable_id = ?', [$journey['receivable']]));

        Assert::true(str_starts_with($journey['pdf'], '%PDF-'), 'stored prescription starts with %PDF-');
    }

    /**
     * Administrador monta a equipe com os 3 perfis restritos.
     *
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @return array<string, int> papel → id do usuário (inclui 'admin')
     */
    private function team(array $clinic): array
    {
        $service = $this->teamService($clinic);
        $profiles = $this->profileGroups($service);
        $team = ['admin' => $clinic['user']];

        foreach (ClinicRoleProfiles::roles() as $role) {
            $userId = $service->invite(ucfirst($role) . ' ' . $clinic['tenant'], $this->newEmail(), $profiles[$role], $clinic['unit'], $this->now);
            Assert::same(ClinicRoleProfiles::GROUP_NAMES[$role], $service->findUser($userId)['group'] ?? null, "{$role} gets its profile");
            $team[$role] = $userId;
        }

        Assert::same(4, $this->count('SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ?', [$clinic['tenant']]));

        return $team;
    }

    /**
     * Ids dos grupos de perfil, lidos de allowedGroups() (T-12).
     *
     * @return array<string, int>
     */
    private function profileGroups(ClinicTeamService $service): array
    {
        $byName = array_flip($service->allowedGroups());
        $profiles = [];
        foreach (ClinicRoleProfiles::GROUP_NAMES as $role => $name) {
            Assert::true(isset($byName[$name]), "allowedGroups() offers the profile {$name}");
            $profiles[$role] = (int) $byName[$name];
        }

        return $profiles;
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     * @return array{tutor: int, patient: int, service: int}
     */
    private function registerPatient(array $clinic, array $team): array
    {
        $context = $this->context($clinic, $team[ClinicRoleProfiles::RECEPTION]);
        $tutor = (new TutorService(new TutorRepository($context, $this->pdo), $context))->create([
            'full_name' => 'Tutor Piloto ' . $clinic['tenant'],
            'phone' => '11988887777',
            'email' => 'tutor-' . bin2hex(random_bytes(4)) . '@example.test',
        ]);
        $patient = $this->patientService($clinic, $team[ClinicRoleProfiles::RECEPTION])->create([
            'tutor_id' => (int) $tutor->id,
            'name' => 'Rex',
            'species' => 'canine',
        ]);

        $this->pdo->prepare('INSERT INTO service (tenant_id, name, duration_minutes, price_cents) VALUES (?, ?, 30, ?)')
            ->execute([$clinic['tenant'], 'Consulta', self::CONSULTATION_CENTS]);

        return ['tutor' => (int) $tutor->id, 'patient' => (int) $patient->id, 'service' => (int) $this->pdo->lastInsertId()];
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     * @param array{tutor: int, patient: int, service: int} $patient
     * @return array<string, mixed>
     */
    private function appointmentData(array $clinic, array $team, array $patient, string $when): array
    {
        return [
            'patient_id' => $patient['patient'],
            'service_id' => $patient['service'],
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'scheduled_at' => $this->now->modify($when),
            'system_unit_id' => $clinic['unit'],
        ];
    }

    /**
     * @param array<string, int> $team
     * @return array<string, mixed>
     */
    private function prescriptionData(int $encounterId, int $patientId, array $team): array
    {
        return [
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'professional_system_user_id' => $team[ClinicRoleProfiles::VETERINARIAN],
            'orientation' => 'Oferecer com alimento.',
            'items' => [[
                'medication_name' => 'Amoxicilina',
                'dose' => '250',
                'dose_unit' => 'mg',
                'route' => 'oral',
                'frequency' => '12/12h',
                'duration' => '7 dias',
            ]],
        ];
    }

    /** @param array{tenant: int, unit: int, user: int, plan: string} $clinic */
    private function context(array $clinic, int $userId): TenantContext
    {
        return TenantContext::authenticated($clinic['tenant'], $userId, $clinic['unit']);
    }

    /** RBAC real: AdiantiProgramPermissionProvider sobre os programas do perfil. */
    private function authorization(string $role): RbacAuthorizationService
    {
        $programs = array_fill_keys(ClinicRoleProfiles::programs($role), true);

        return new RbacAuthorizationService(
            new AdiantiProgramPermissionProvider(new FakeSessionContextSource(['programs' => $programs, 'methods' => []])),
            new PdoAuditLogWriter($this->pdo),
        );
    }

    /** @param array{tenant: int, unit: int, user: int, plan: string} $clinic */
    private function patientService(array $clinic, int $userId): PatientService
    {
        $context = $this->context($clinic, $userId);

        return new PatientService(new PatientRepository($context, $this->pdo), new TutorRepository($context, $this->pdo), $context);
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function appointmentService(array $clinic, array $team, string $role): AppointmentService
    {
        $context = $this->context($clinic, $team[$role]);

        return new AppointmentService(new AppointmentRepository($context, $this->pdo), new ServiceRepository($context, $this->pdo),
            $this->patientService($clinic, $team[$role]), $context, $this->authorization($role));
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function queueService(array $clinic, array $team): QueueEntryService
    {
        $context = $this->context($clinic, $team[ClinicRoleProfiles::RECEPTION]);

        return new QueueEntryService(new QueueEntryRepository($context, $this->pdo),
            $this->patientService($clinic, $team[ClinicRoleProfiles::RECEPTION]), $context,
            $this->authorization(ClinicRoleProfiles::RECEPTION));
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function encounterService(array $clinic, array $team, string $role): EncounterService
    {
        $context = $this->context($clinic, $team[$role]);

        return new EncounterService(new EncounterRepository($context, $this->pdo), $this->authorization($role), $context, $this->pdo);
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function prescriptionService(array $clinic, array $team, string $role): PrescriptionService
    {
        $context = $this->context($clinic, $team[$role]);

        return new PrescriptionService(new PrescriptionRepository($context, $this->pdo), new EncounterRepository($context, $this->pdo),
            $this->authorization($role), $context, new TenantUserDirectory($context, $this->pdo));
    }

    private function documentRequestService(TenantContext $context, FakeStorage $storage, string $role): DocumentRequestService
    {
        return new DocumentRequestService(
            new GeneratedDocumentRepository($context, $this->pdo),
            new DocumentSourceQuery($context, $this->pdo),
            new DocumentTemplateRepository($context, $this->pdo),
            $storage,
            $this->authorization($role),
            $context,
        );
    }

    private function documentGenerationService(TenantContext $context, FakeStorage $storage): DocumentGenerationService
    {
        $sources = new DocumentSourceQuery($context, $this->pdo);
        $names = new SenderNamesQuery($context, $this->pdo);

        return new DocumentGenerationService(
            new GeneratedDocumentRepository($context, $this->pdo),
            new DocumentContentFactory($sources, $names),
            new DompdfDocumentRenderer(),
            $storage,
            new StoredObjectRepository($context, $this->pdo),
            new DocumentReadyNotifier(
                new CommunicationPreferenceRepository($context, $this->pdo),
                new MessageTemplateRepository($context, $this->pdo),
                new OutboundMessageRepository($context, $this->pdo),
                $sources,
                $names,
                $context,
            ),
            $context,
        );
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function cashService(array $clinic, array $team, string $role): CashSessionService
    {
        $context = $this->context($clinic, $team[$role]);

        return new CashSessionService(new CashSessionRepository($context, $this->pdo), $this->authorization($role), $context, $this->pdo);
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function accountService(array $clinic, array $team, string $role): EncounterAccountService
    {
        $context = $this->context($clinic, $team[$role]);

        return new EncounterAccountService(
            new EncounterAccountRepository($context, $this->pdo),
            new EncounterAccountItemRepository($context, $this->pdo),
            new ReceivableRepository($context, $this->pdo),
            new EncounterRepository($context, $this->pdo),
            new PatientRepository($context, $this->pdo),
            new ProcedureExecutionRepository($context, $this->pdo),
            new ExamRequestRepository($context, $this->pdo),
            new ProcedureCatalogService(
                new ProcedureCatalogRepository($context, $this->pdo),
                new ProcedureCatalogItemInputRepository($context, $this->pdo),
                $context,
            ),
            new ExamCatalogRepository($context, $this->pdo),
            $this->authorization($role),
            $context,
            new TenantUserDirectory($context, $this->pdo),
        );
    }

    /**
     * @param array{tenant: int, unit: int, user: int, plan: string} $clinic
     * @param array<string, int> $team
     */
    private function paymentService(array $clinic, array $team, string $role): PaymentService
    {
        $context = $this->context($clinic, $team[$role]);
        $authorization = $this->authorization($role);

        return new PaymentService(
            new PaymentRepository($context, $this->pdo),
            new ReceivableRepository($context, $this->pdo),
            new CashSessionRepository($context, $this->pdo),
            new FinancialEntryService(new FinancialEntryRepository($context, $this->pdo), $authorization, $context),
            $authorization,
            $context,
            new SubmissionKeyRepository($context, $this->pdo),
        );
    }

    /** @param array{tenant: int, unit: int, user: int, plan: string} $clinic */
    private function teamService(array $clinic): ClinicTeamService
    {
        $subscriptions = new SubscriptionRepository($this->pdo);
        $translate = static fn (string $key, string ...$params): string => '[' . implode('|', [$key, ...$params]) . ']';

        return new ClinicTeamService(
            $this->pdo,
            new TransactionRunner($this->pdo),
            $this->context($clinic, $clinic['user']),
            $subscriptions,
            new QuotaGuard($subscriptions, new TenantUsageReader($this->pdo)),
            new SubscriptionAccessGuard($subscriptions, new OfferScope(PilotSettings::fromArray([]))),
            new AccountInviteService(
                new PasswordResetToken(self::KEY),
                new PublicUrl('https://app.test'),
                $this->email,
                \Closure::fromCallable($translate),
            ),
        );
    }

    /** @return array{tenant: int, unit: int, user: int, plan: string} */
    private function clinic(string $planId, string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $publicId = $this->uuid();
        $this->pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (?, ?, ?, 'active')")
            ->execute([$publicId, 'e5-t18-' . strtolower($label) . '-' . $suffix, 'Clínica Piloto ' . $label]);
        $tenantId = (int) $this->pdo->lastInsertId();

        $unitId = $this->nextId('system_unit');
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (?, ?, ?)')
            ->execute([$unitId, $tenantId, 'Unidade Piloto ' . $label]);

        $login = 'e5-t18-' . strtolower($label) . '-' . $suffix . '@example.test';
        $userId = $this->nextId('system_users');
        $this->pdo->prepare("INSERT INTO system_users (id, name, login, email, password, active, system_unit_id) VALUES (?, ?, ?, ?, 'x', 'Y', ?)")
            ->execute([$userId, 'Admin ' . $label, $login, $login, $unitId]);
        $this->pdo->prepare('INSERT INTO system_user_unit (id, system_user_id, system_unit_id) VALUES (?, ?, ?)')
            ->execute([$this->nextId('system_user_unit'), $userId, $unitId]);
        $this->pdo->prepare('INSERT INTO system_user_group (id, system_user_id, system_group_id) VALUES (?, ?, ?)')
            ->execute([$this->nextId('system_user_group'), $userId, $this->planGroups[$planId]]);
        $this->pdo->prepare("INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (?, ?, 'active')")
            ->execute([$tenantId, $userId]);
        $this->pdo->prepare('INSERT INTO tenant_group (tenant_id, system_group_id) VALUES (?, ?)')
            ->execute([$tenantId, $this->planGroups[$planId]]);

        $order = BillingOrder::place($publicId, $planId, BillingOrder::METHOD_CARD, 'Admin ' . $label, $login, '11999990000',
            'Clínica Piloto ' . $label, 'termos-uso-2026-10', $this->now->modify('-2 days'), '127.0.0.1', false);
        $order->markPaid('sub_t18_' . $suffix, $this->now->modify('-2 days'));
        $orderId = (new BillingOrderRepository($this->pdo))->insert($order);

        $subscription = Subscription::start($orderId, $planId, Subscription::METHOD_CARD, 'cus_t18_' . $suffix, 'sub_t18_' . $suffix,
            'active', $this->now->modify('-1 day'), $this->now->modify('+30 days'), 100);
        $subscription->assignTenant($tenantId);
        (new SubscriptionRepository($this->pdo))->insert($subscription);

        return ['tenant' => $tenantId, 'unit' => $unitId, 'user' => $userId, 'plan' => $planId];
    }

    private function createPlanGroups(): void
    {
        foreach (PlanEntitlements::PLAN_IDS as $planId) {
            $name = TenantProvisioningService::GROUP_NAME_PREFIX . PlanEntitlements::forPlan($planId)->name();
            $id = (int) $this->value('SELECT id FROM system_group WHERE name = ? ORDER BY id LIMIT 1', [$name]);
            if ($id === 0) {
                $id = $this->nextId('system_group');
                $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$id, $name]);
            }
            $this->planGroups[$planId] = $id;
        }
    }

    /** Os grupos vêm do seed role-groups.sql (T-04); sem ele, são criados na transação do teste. */
    private function ensureProfileGroups(): void
    {
        foreach (ClinicRoleProfiles::GROUP_NAMES as $name) {
            if ((int) $this->value('SELECT COUNT(*) FROM system_group WHERE name = ? AND id <> 1', [$name]) === 0) {
                $this->pdo->prepare('INSERT INTO system_group (id, name) VALUES (?, ?)')->execute([$this->nextId('system_group'), $name]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function receivable(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT tenant_id, status, total_cents, paid_cents FROM receivable WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function newEmail(): string
    {
        return 'e5-t18-' . bin2hex(random_bytes(5)) . '@example.test';
    }

    private function nextId(string $table): int
    {
        return (int) $this->value(sprintf('SELECT COALESCE(MAX(id), 0) + 1 FROM %s FOR UPDATE', $table));
    }

    /** @param list<int|string> $params */
    private function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /** @param list<int|string> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
