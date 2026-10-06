<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Communication\EmailProviderFactory;
use CentralVet\Communication\LogEmailProvider;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Communication\SmtpConfig;
use CentralVet\Communication\SmtpEmailProvider;
use CentralVet\Communication\WhatsAppLinkBuilder;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Tests\Support\Assert;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class CommunicationProviderTest
{
    private const RECIPIENT = 'f7a.teste@example.invalid';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    public function setUp(): void
    {
        foreach (self::envNames() as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->savedEnv = [];
    }

    public function testWhatsAppLinkNormalizesBrazilianPhoneAndEncodesText(): void
    {
        Assert::same(
            'https://wa.me/5585999990000?text=Ol%C3%A1%20%26%20at%C3%A9',
            WhatsAppLinkBuilder::build('(85) 99999-0000', 'Olá & até'),
        );
    }

    public function testWhatsAppPhoneNormalizationRules(): void
    {
        Assert::null(WhatsAppLinkBuilder::normalizePhone('123'));
        Assert::same('558533334444', WhatsAppLinkBuilder::normalizePhone('85 3333-4444'));
        Assert::same('5585999990000', WhatsAppLinkBuilder::normalizePhone('+55 (85) 99999-0000'));
        Assert::same('558533334444', WhatsAppLinkBuilder::normalizePhone('558533334444'));
        Assert::null(WhatsAppLinkBuilder::normalizePhone('448533334444'));
        Assert::null(WhatsAppLinkBuilder::normalizePhone('12345678901234'));
        Assert::null(WhatsAppLinkBuilder::normalizePhone(''));
    }

    public function testWhatsAppInvalidPhoneThrowsWithoutThePhone(): void
    {
        try {
            WhatsAppLinkBuilder::build('123', 'Oi');
        } catch (\InvalidArgumentException $e) {
            Assert::same('Invalid phone number for WhatsApp', $e->getMessage());

            return;
        }

        throw new \RuntimeException('Expected InvalidArgumentException');
    }

    public function testLogDriverIsTheDefault(): void
    {
        $provider = EmailProviderFactory::fromEnvironment(new CommunicationSpyLogger());

        Assert::instanceOf(LogEmailProvider::class, $provider);
        Assert::same('log', $provider->name());
        Assert::same('email', $provider->channel());
    }

    public function testSmtpDriverIsSelectedByEnvironment(): void
    {
        putenv('COMMUNICATION_EMAIL_DRIVER=smtp');
        putenv('SMTP_HOST=smtp.example.invalid');

        $provider = EmailProviderFactory::fromEnvironment(new CommunicationSpyLogger());

        Assert::instanceOf(SmtpEmailProvider::class, $provider);
        Assert::same('smtp', $provider->name());
    }

    public function testUnknownDriverIsRejected(): void
    {
        putenv('COMMUNICATION_EMAIL_DRIVER=carrier-pigeon');

        Assert::throws(\InvalidArgumentException::class, static fn () => EmailProviderFactory::fromEnvironment(new CommunicationSpyLogger()));
    }

    public function testLogEmailProviderNeverLogsRecipientOrBody(): void
    {
        $logger = new CommunicationSpyLogger();
        $provider = new LogEmailProvider($logger);

        $result = $provider->deliver(new OutgoingMessage(self::RECIPIENT, 'Assunto', 'Corpo secreto', 'message:42'));

        Assert::count(1, $logger->records);
        $record = $logger->records[0];
        Assert::same('communication.email.sandbox', $record['message']);
        Assert::same('message:42', $record['context']['reference']);
        Assert::same(substr(hash('sha256', self::RECIPIENT), 0, 12), $record['context']['recipient_hash']);
        Assert::same(7, $record['context']['subject_length']);
        Assert::same(13, $record['context']['body_length']);

        $serialized = json_encode($logger->records, JSON_UNESCAPED_UNICODE);
        Assert::false(str_contains((string) $serialized, self::RECIPIENT), 'Log must not contain the recipient');
        Assert::false(str_contains((string) $serialized, 'Corpo secreto'), 'Log must not contain the body');
        Assert::false($result !== null && str_contains($result, self::RECIPIENT), 'Provider id must not contain the recipient');
    }

    public function testSmtpConfigReadsEnvironmentWithSafeDefaults(): void
    {
        $config = SmtpConfig::fromEnvironment();

        Assert::same('', $config->host);
        Assert::same(587, $config->port);
        Assert::same('tls', $config->encryption);
        Assert::same('no-reply@example.invalid', $config->fromAddress);
        Assert::same('Central Vet', $config->fromName);
        Assert::same(10, $config->timeoutSeconds);
    }

    public function testSmtpConnectFailureBecomesCodedFailureWithoutAddress(): void
    {
        putenv('SMTP_HOST=smtp.example.invalid');
        putenv('SMTP_PORT=2525');
        putenv('SMTP_USERNAME=user');
        putenv('SMTP_PASSWORD=pass');
        putenv('SMTP_ENCRYPTION=ssl');

        $double = null;
        $factory = static function () use (&$double): PHPMailer {
            $double = new CommunicationPhpMailerDouble('SMTP Error: Could not connect to SMTP host. ' . self::RECIPIENT);

            return $double;
        };

        $provider = new SmtpEmailProvider(SmtpConfig::fromEnvironment(), $factory);

        try {
            $provider->deliver(new OutgoingMessage(self::RECIPIENT, 'Assunto', 'Corpo', 'message:7'));
        } catch (MessageDeliveryFailed $e) {
            Assert::same('smtp_connect', $e->errorCode());
            Assert::same('Message delivery failed: smtp_connect', $e->getMessage());
            Assert::false(str_contains($e->getMessage(), self::RECIPIENT));
            Assert::null($e->getPrevious(), 'Previous exception would leak ErrorInfo');

            Assert::same('smtp', $double->Mailer);
            Assert::same('smtp.example.invalid', $double->Host);
            Assert::same(2525, $double->Port);
            Assert::true($double->SMTPAuth);
            Assert::same(PHPMailer::ENCRYPTION_SMTPS, $double->SMTPSecure);
            Assert::same('UTF-8', $double->CharSet);
            Assert::same('text/plain', $double->ContentType);
            Assert::same(10, $double->Timeout);

            return;
        }

        throw new \RuntimeException('Expected MessageDeliveryFailed');
    }

    public function testSmtpFailureCodesAreMapped(): void
    {
        $cases = [
            'SMTP Error: Could not authenticate.' => 'smtp_auth',
            'SMTP Error: The following recipients failed: ' . self::RECIPIENT => 'smtp_recipient_rejected',
            'Something odd ' . self::RECIPIENT => 'smtp_error',
        ];

        foreach ($cases as $error => $code) {
            $provider = new SmtpEmailProvider(
                SmtpConfig::fromEnvironment(),
                static fn (): PHPMailer => new CommunicationPhpMailerDouble($error),
            );

            try {
                $provider->deliver(new OutgoingMessage(self::RECIPIENT, null, 'Corpo', 'message:8'));
                throw new \RuntimeException('Expected MessageDeliveryFailed');
            } catch (MessageDeliveryFailed $e) {
                Assert::same($code, $e->errorCode());
                Assert::false(str_contains($e->getMessage(), self::RECIPIENT));
            }
        }
    }

    public function testSmtpWithoutUsernameDisablesAuthAndNoneDisablesEncryption(): void
    {
        putenv('SMTP_ENCRYPTION=none');

        $double = new CommunicationPhpMailerDouble(null);
        $provider = new SmtpEmailProvider(SmtpConfig::fromEnvironment(), static fn (): PHPMailer => $double);

        $provider->deliver(new OutgoingMessage(self::RECIPIENT, 'Assunto', 'Corpo', 'message:9'));

        Assert::false($double->SMTPAuth);
        Assert::same('', $double->SMTPSecure);
        Assert::false($double->SMTPAutoTLS);
        Assert::same(1, $double->sendCalls);
    }

    /** @return list<string> */
    private static function envNames(): array
    {
        return [
            'COMMUNICATION_EMAIL_DRIVER', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD',
            'SMTP_ENCRYPTION', 'SMTP_FROM_ADDRESS', 'SMTP_FROM_NAME', 'SMTP_TIMEOUT_SECONDS',
        ];
    }
}

final class CommunicationSpyLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function debug(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
    }

    public function info(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function critical(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
    }
}

/**
 * PHPMailer double: records the configuration the provider applied and
 * fails (or succeeds) without ever opening an SMTP connection.
 */
final class CommunicationPhpMailerDouble extends PHPMailer
{
    public int $sendCalls = 0;

    public function __construct(private readonly ?string $failure)
    {
        parent::__construct(true);
    }

    public function send()
    {
        $this->sendCalls++;
        if ($this->failure !== null) {
            $this->ErrorInfo = $this->failure;
            throw new MailerException($this->failure);
        }

        return true;
    }
}
