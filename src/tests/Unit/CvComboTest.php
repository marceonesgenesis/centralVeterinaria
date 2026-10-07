<?php

declare(strict_types=1);

namespace {
    if (!class_exists('CvCombo', false) && is_file(dirname(__DIR__, 2) . '/app/lib/widget/CvCombo.php')) {
        require dirname(__DIR__, 2) . '/app/lib/widget/CvCombo.php';
    }
}

namespace CentralVet\Tests\Unit {

    use CentralVet\Tests\Support\Assert;

    /**
     * Fase 7A, T-26 (revisão final rodada 2, bloqueante): TCombo::reload do
     * framework aplica só htmlspecialchars e põe o rótulo num literal JS entre
     * aspas simples, sem escapar `\`; tcombo_add_option concatena o rótulo em
     * HTML e passa a `$()`. Um rótulo `\x3cimg src=x onerror=...\x3e` vira tag.
     *
     * CvCombo::reloadScript monta a recarga com os itens num literal JSON
     * (JSON_HEX_*) e cria cada opção com `new Option(texto, valor)`: o rótulo
     * nunca é interpretado como HTML. O guarda de fonte impede TCombo::reload
     * nos controllers cujos rótulos vêm de dado digitado por usuário.
     */
    final class CvComboTest
    {
        private const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

        /** Rótulos hostis: escape JS, tag crua, quebra de aspas e de linha. */
        private const LABELS = [
            7 => '\x3cimg src=x onerror=alert(1)\x3e — Confirmação',
            3 => '<img src=x onerror=alert(2)>',
            12 => "'); alert(3); ('\" \\u003csvg onload=alert(4)\\u003e",
            5 => "linha 1\nlinha 2 </script> & fim",
        ];

        /** Usos de TCombo::reload aceitos: rótulos gerados pelo código, não por usuário. */
        private const RELOAD_ALLOWED = [
            'admin/SystemProgramForm.php', // nomes de métodos por reflexão
            'admin/SystemSQLPanel.php',    // nomes de tabelas do schema
        ];

        private function script(array $items, bool $startEmpty = false, bool $fireEvents = true): string
        {
            Assert::true(class_exists('CvCombo'), 'class CvCombo must exist (app/lib/widget/CvCombo.php)');

            return \CvCombo::reloadScript('form_x', 'template_id', $items, $startEmpty, $fireEvents);
        }

        public function testHostileLabelsNeverReachHtmlOrTheVendorSink(): void
        {
            $js = $this->script(self::LABELS, true, false);

            Assert::false(str_contains($js, 'tcombo_add_option'), 'must not use the vendor HTML sink: ' . $js);
            Assert::false(str_contains($js, '<'), 'no raw < may reach the script: ' . $js);
            Assert::false(str_contains($js, '>'), 'no raw > may reach the script: ' . $js);
            Assert::false(str_contains($js, "\n"), 'no raw line break may reach the script');
            Assert::stringContains('new Option(', $js, 'options are created as DOM text, not HTML');

            foreach (self::LABELS as $label) {
                Assert::stringContains((string) json_encode($label, self::FLAGS), $js, 'label goes as a JSON literal: ' . $label);
            }
        }

        public function testItemsRoundTripInOrderAsJsonLiteral(): void
        {
            $js = $this->script(self::LABELS);

            Assert::true((bool) preg_match('/(\[\[.*\]\])/s', $js, $m), 'items must be a JSON list of pairs: ' . $js);
            $decoded = json_decode($m[1], true);
            Assert::true(is_array($decoded), 'items literal must be valid JSON');

            $expected = [];
            foreach (self::LABELS as $key => $label) {
                $expected[] = [(string) $key, $label, false];
            }
            Assert::same($expected, $decoded, 'items keep order, value and exact text');
        }

        public function testFormFieldAndFlagsAreJsonLiterals(): void
        {
            $js = \CvCombo::reloadScript("f'</script>", "c'\\x3c", [], true, false);

            Assert::false(str_contains($js, '<'), 'form and field names are encoded too: ' . $js);
            Assert::stringContains((string) json_encode("f'</script>", self::FLAGS), $js);
            Assert::stringContains((string) json_encode("c'\\x3c", self::FLAGS), $js);
            Assert::stringContains('tcombo_clear(', $js, 'clears the combo like TCombo::reload');
            Assert::stringContains(', true, false)', $js, 'startEmpty=true, fireEvents=false');

            $js = $this->script([], false, true);
            Assert::stringContains(', false, true)', $js, 'startEmpty=false, fireEvents=true');
        }

        public function testOptGroupKeysBecomeGroupsWithTextLabel(): void
        {
            $js = $this->script(['>>>g1' => '<b>Grupo</b>', 1 => 'Um']);

            Assert::true((bool) preg_match('/(\[\[.*\]\])/s', $js, $m), 'items literal: ' . $js);
            Assert::same([['>>>g1', '<b>Grupo</b>', true], ['1', 'Um', false]], json_decode($m[1], true));
            Assert::false(str_contains($js, 'tcombo_create_opt_group'), 'group label must not go through the vendor HTML sink');
        }

        public function testControllersDoNotUseTComboReloadForUserTypedLabels(): void
        {
            $control = dirname(__DIR__, 2) . '/app/control';
            $offenders = [];

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($control, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($control) + 1));
                if (in_array($relative, self::RELOAD_ALLOWED, true)) {
                    continue;
                }
                foreach ($this->reloadLines((string) file_get_contents($file->getPathname())) as $line) {
                    $offenders[] = $relative . ':' . $line;
                }
            }

            Assert::same([], $offenders, 'use CvCombo::reload instead of TCombo/TDBCombo::reload: ' . implode(', ', $offenders));
        }

        /** @return list<int> linhas com `T(DB)?Combo::reload(` fora de comentários */
        private function reloadLines(string $source): array
        {
            $tokens = token_get_all($source);
            $lines = [];
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];
                if (!is_array($t) || !in_array(strtolower(ltrim($t[1], '\\')), ['tcombo', 'tdbcombo'], true)) {
                    continue;
                }
                $j = $i + 1;
                if (($tokens[$j][0] ?? null) !== T_DOUBLE_COLON) {
                    continue;
                }
                $j++;
                if (is_array($tokens[$j] ?? null) && strtolower($tokens[$j][1]) === 'reload') {
                    $lines[] = $t[2];
                }
            }

            return $lines;
        }
    }
}
