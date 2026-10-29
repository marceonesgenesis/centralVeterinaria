<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-18: preferências de comunicação do tutor
 * (TutorCommunicationForm) e mensagem manual (CommunicationComposeForm).
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de SurgeryClinicalFormsIntegrationTest. Sem sessão, as telas
 * abrem com os campos e sem dados do banco; os carregadores protegidos são
 * trocados por subclasses quando o teste precisa de um tutor.
 */
final class CommunicationTutorScreensIntegrationTest
{
    private const MARKER = '@@T18SCREENS@@';

    /** @return array<string, mixed> */
    private function runAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'ob_start();'
            . 'try {' . $body . '}'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "\n' . self::MARKER . '", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'subprocess output: ' . $out);

        return $decoded;
    }

    /** @return list<string> */
    private function fieldsOf(string $class, array $param): array
    {
        $result = $this->runAdianti(
            '$_GET = ' . var_export($param, true) . ';'
            . 'if (!class_exists(' . var_export($class, true) . ')) { $out["missing"] = true; } else {'
            . '$page = new ' . $class . '(' . var_export($param, true) . ');'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["fields"] = $form === null ? [] : array_values(array_map("strval", array_keys($form->getFields())));'
            . '}'
        );

        Assert::true(!isset($result['missing']), "{$class} must exist");
        Assert::true(!isset($result['error']), "{$class} threw: " . (string) ($result['error'] ?? ''));

        return $result['fields'];
    }

    public function testPreferencesFormHasStatusAndSourcePerChannel(): void
    {
        $fields = $this->fieldsOf('TutorCommunicationForm', ['tutor_id' => '1']);

        foreach (['tutor_id', 'email_status', 'email_source', 'whatsapp_status', 'whatsapp_source'] as $name) {
            Assert::true(in_array($name, $fields, true), "TutorCommunicationForm must have field '{$name}' (got: " . implode(', ', $fields) . ')');
        }
    }

    /** Mostra só se há e-mail e se o telefone serve para WhatsApp, nunca o contato. */
    public function testPreferencesFormNeverPrintsTheTutorContact(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("TutorCommunicationForm")) { $out["missing"] = true; } else {'
            . '$_GET = ["class" => "TutorCommunicationForm", "tutor_id" => "1"];'
            . 'eval(\'class TutorCommunicationFormT18 extends TutorCommunicationForm {'
            . ' protected static function loadTutor(int $tutorId): ?array {'
            . ' return ["name" => "F7A teste <b>Ana</b>", "email" => "f7a.teste@example.invalid", "phone" => "(85) 99999-8888"]; }'
            . ' protected static function loadPreferences(int $tutorId): ?array {'
            . ' return ["email" => "opted_in", "whatsapp" => "not_recorded"]; } }\');'
            . '$page = new TutorCommunicationFormT18($_GET); $page->show();'
            . '$out["yes"] = _t("Yes");'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'TutorCommunicationForm must exist');
        Assert::true(!isset($result['error']), 'TutorCommunicationForm threw: ' . (string) ($result['error'] ?? ''));

        $html = (string) $result['html'];
        Assert::true(!str_contains($html, 'f7a.teste@example.invalid'), 'the tutor e-mail must not be printed');
        Assert::true(!str_contains($html, '99999-8888') && !str_contains($html, '85999998888'), 'the tutor phone must not be printed');
        Assert::true(str_contains($html, 'F7A teste &lt;b&gt;Ana&lt;/b&gt;'), 'the tutor name must be rendered escaped');
        Assert::true(!str_contains($html, '<b>Ana</b>'), 'the tutor name must not be rendered as raw HTML');
        Assert::true(str_contains($html, (string) $result['yes']), 'the screen must say the tutor has an e-mail');
    }

    public function testComposeFormHasChannelPurposeTemplateSubjectAndBody(): void
    {
        $fields = $this->fieldsOf('CommunicationComposeForm', ['tutor_id' => '1']);

        foreach (['tutor_id', 'patient_id', 'channel', 'purpose', 'template_id', 'subject', 'body_text'] as $name) {
            Assert::true(in_array($name, $fields, true), "CommunicationComposeForm must have field '{$name}' (got: " . implode(', ', $fields) . ')');
        }
    }

    /** Corpo da mensagem só pelo corpo do POST: o que vier na query string é ignorado. */
    public function testComposeIgnoresBodyFromTheQueryString(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("CommunicationComposeForm")) { $out["missing"] = true; } else {'
            . '$_POST = [];'
            . '$_GET = ["class" => "CommunicationComposeForm", "method" => "onSave", "tutor_id" => "1",'
            . ' "channel" => "email", "purpose" => "custom", "subject" => "ASSUNTOURL", "body_text" => "CORPOVIAURL"];'
            . '$page = new CommunicationComposeForm($_GET);'
            . '$page->onSave($_GET);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["kept"] = (string) $form->getField("body_text")->getValue();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'CommunicationComposeForm must exist');
        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(!str_contains((string) $result['html'], 'CORPOVIAURL'), 'body from the query string must not be used');
        Assert::true(!str_contains((string) $result['html'], 'body_text is required'), 'error must not expose the technical field name');
        Assert::same('', $result['kept'], 'body from the query string must be ignored');
    }

    public function testScreensWithoutTutorIdRenderEmptyState(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("TutorCommunicationForm") || !class_exists("CommunicationComposeForm")) { $out["missing"] = true; } else {'
            . '$_GET = [];'
            . '$a = new TutorCommunicationForm([]); $a->show();'
            . '$b = new CommunicationComposeForm([]); $b->show();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'both screens must exist');
        Assert::true(!isset($result['error']), 'screens without tutor_id threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(2, substr_count((string) $result['html'], 'cv-state--empty'));
    }

    public function testActionsMatchTheAuthorizationPattern(): void
    {
        $result = $this->runAdianti(
            'foreach (["TutorCommunicationForm", "CommunicationComposeForm"] as $c) {'
            . ' $out["actions"][$c] = null;'
            . ' if (!class_exists($c)) { continue; }'
            . ' $out["actions"][$c] = [];'
            . ' foreach ((new ReflectionClass($c))->getConstants() as $k => $v) {'
            . '  if (str_starts_with($k, "ACTION_")) { $out["actions"][$c][$k] = $v; } } }'
            . '$out["onChangeTemplate"] = class_exists("CommunicationComposeForm") && method_exists("CommunicationComposeForm", "onChangeTemplate")'
            . ' && (new ReflectionMethod("CommunicationComposeForm", "onChangeTemplate"))->isStatic();'
        );

        foreach ($result['actions'] as $class => $actions) {
            Assert::true(is_array($actions), "{$class} must exist");
            Assert::true($actions !== [], "{$class} must declare ACTION_* constants");

            foreach ($actions as $name => $action) {
                Assert::same(
                    1,
                    preg_match(AuthorizationRequest::ACTION_PATTERN, (string) $action),
                    "{$class}::{$name} = '{$action}' must match AuthorizationRequest::ACTION_PATTERN"
                );
                Assert::true(str_starts_with((string) $action, $class . '::'), "{$class}::{$name} must be '{$class}::<method>'");
            }

            Assert::true(in_array($class . '::onSave', $actions, true), "{$class} must declare '{$class}::onSave'");
        }

        Assert::true($result['onChangeTemplate'] === true, 'CommunicationComposeForm::onChangeTemplate must be a static action');
    }
}
