<?php

declare(strict_types=1);

namespace CentralVet\Communication;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * E-mail over SMTP with PHPMailer. Failures become MessageDeliveryFailed with
 * a code only; PHPMailer's ErrorInfo/exception text (which may contain the
 * address or server replies) is inspected for classification and never
 * copied.
 */
final class SmtpEmailProvider implements MessageChannelProviderInterface
{
    /** @var \Closure(): PHPMailer */
    private readonly \Closure $mailerFactory;

    /**
     * @param (\Closure(): PHPMailer)|null $mailerFactory test seam
     */
    public function __construct(private readonly SmtpConfig $config, ?\Closure $mailerFactory = null)
    {
        $this->mailerFactory = $mailerFactory ?? static fn (): PHPMailer => new PHPMailer(true);
    }

    public function channel(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function deliver(OutgoingMessage $message): ?string
    {
        try {
            $mailer = ($this->mailerFactory)();
            $this->configure($mailer);

            $mailer->setFrom($this->config->fromAddress, $this->config->fromName);
            try {
                $mailer->addAddress($message->recipient);
            } catch (MailerException) {
                throw new MessageDeliveryFailed('smtp_recipient_rejected');
            }
            $mailer->Subject = (string) $message->subject;
            $mailer->Body = $message->body;

            $mailer->send();
        } catch (MessageDeliveryFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MessageDeliveryFailed(self::classify($e->getMessage()));
        }

        $id = $mailer->getLastMessageID();

        return $id !== '' ? $id : null;
    }

    private function configure(PHPMailer $mailer): void
    {
        $mailer->isSMTP();
        $mailer->Host = $this->config->host;
        $mailer->Port = $this->config->port;
        $mailer->SMTPAuth = $this->config->username !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $this->config->username;
            $mailer->Password = $this->config->password;
        }
        switch ($this->config->encryption) {
            case 'ssl':
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'tls':
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
            default:
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
        }
        $mailer->CharSet = 'UTF-8';
        $mailer->isHTML(false);
        $mailer->Timeout = $this->config->timeoutSeconds;
        $mailer->SMTPDebug = 0;
    }

    /**
     * Maps PHPMailer's (English, default language) error text to a code.
     */
    private static function classify(string $error): string
    {
        $error = strtolower($error);

        return match (true) {
            str_contains($error, 'could not connect') || str_contains($error, 'failed to connect') => 'smtp_connect',
            str_contains($error, 'could not authenticate') => 'smtp_auth',
            str_contains($error, 'recipients failed') || str_contains($error, 'invalid address') => 'smtp_recipient_rejected',
            default => 'smtp_error',
        };
    }
}
