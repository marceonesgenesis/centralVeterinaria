<?php

use Adianti\Control\AdiantiController;
use Adianti\Core\AdiantiApplicationConfig;
use Adianti\Core\AdiantiCoreTranslator;
use Adianti\Registry\TSession;
use Adianti\Service\AdiantiUploaderService;
use CentralVet\Presentation\UploadedTmpFile;

/**
 * Uploader dos TFile/TMultiFile da aplicação (rodada 2, T-63), ligado por
 * `->setService('CvUploaderService')`. Repete as checagens de
 * AdiantiUploaderService::show (framework, não editável): extensões
 * bloqueadas, hash com o mesmo seed e `extensions`, e as mesmas mensagens.
 * Difere em três pontos:
 *  - só atende sessão logada (SystemPermission libera esta classe só para logado);
 *  - grava em tmp/<UploadedTmpFile::generateName()>, um nome imprevisível;
 *  - registra o nome gerado em TSession 'cv_uploads', que CvUpload::resolve()
 *    exige. tmp/ é compartilhado e o nome de outra sessão é recusado.
 */
class CvUploaderService implements AdiantiController
{
    public const SESSION_KEY = 'cv_uploads';
    public const MAX_UPLOADS = 50;

    private const BLOCK_EXTENSIONS = ['php', 'php3', 'php4', 'phtml', 'pl', 'py', 'jsp', 'asp', 'htm', 'shtml', 'sh', 'cgi', 'htaccess'];

    public function show($param)
    {
        if (!TSession::getValue('logged')) {
            self::reply(['type' => 'error', 'msg' => AdiantiCoreTranslator::translate('Permission denied')]);
            return;
        }

        $ini  = AdiantiApplicationConfig::get();
        $seed = APPLICATION_NAME . (!empty($ini['general']['seed']) ? $ini['general']['seed'] : 's8dkld83kf73kf094');
        $folder = 'tmp/';

        $file = $_FILES['fileName'] ?? null;

        if (!is_array($file) || ($file['error'] ?? null) !== 0 || ($file['size'] ?? 0) <= 0) {
            self::reply([
                'type' => 'error',
                'msg'  => AdiantiCoreTranslator::translate('Server has received no file') . '. '
                    . AdiantiCoreTranslator::translate('Check the server limits') . '. '
                    . AdiantiCoreTranslator::translate('The current limit is') . ' '
                    . AdiantiUploaderService::getMaximumFileUploadSizeFormatted(),
            ]);
            return;
        }

        $original = (string) $file['name'];

        // check blocked file extension, not using finfo because file.php.2 problem
        foreach (self::BLOCK_EXTENSIONS as $block_extension) {
            if (strpos(strtolower($original), ".{$block_extension}") !== false) {
                self::reply(['type' => 'error', 'msg' => AdiantiCoreTranslator::translate('Extension not allowed')]);
                return;
            }
        }

        if (!empty($param['extensions'])) {
            $name = $param['name'] ?? '';
            $extensions = unserialize(base64_decode((string) $param['extensions']), ['allowed_classes' => false]);
            $hash = md5("{$seed}{$name}" . base64_encode(serialize($extensions)));

            if ($hash !== ($param['hash'] ?? null)) {
                self::reply(['type' => 'error', 'msg' => AdiantiCoreTranslator::translate('Hash error')]);
                return;
            }

            $ext = pathinfo($original, PATHINFO_EXTENSION);

            if (!is_array($extensions) || !in_array(strtolower($ext), $extensions)) {
                self::reply(['type' => 'error', 'msg' => AdiantiCoreTranslator::translate('Extension not allowed')]);
                return;
            }
        }

        $generated = UploadedTmpFile::generateName($original);
        $path = $folder . $generated;

        if (!is_writable($folder)) {
            self::reply(['type' => 'error', 'msg' => AdiantiCoreTranslator::translate('Permission denied') . ": {$path}"]);
            return;
        }

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            self::reply(['type' => 'error', 'msg' => '']);
            return;
        }

        self::remember($generated);
        self::reply(['type' => 'success', 'fileName' => $generated]);
    }

    /**
     * Acrescenta o nome à lista da sessão; o mais antigo sai acima de MAX_UPLOADS.
     */
    public static function remember(string $generated): void
    {
        $uploads = TSession::getValue(self::SESSION_KEY);
        $uploads = is_array($uploads) ? array_values(array_filter($uploads, 'is_string')) : [];
        $uploads[] = $generated;

        TSession::setValue(self::SESSION_KEY, array_slice($uploads, -self::MAX_UPLOADS));
    }

    private static function reply(array $response): void
    {
        echo json_encode($response);
    }
}
