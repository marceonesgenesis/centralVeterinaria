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
 *  - registra o par nome gerado → nome original (UTF-8, texto puro) em
 *    TSession 'cv_uploads' (CvUpload::remember). CvUpload::resolve() exige o
 *    nome nesse registro: tmp/ é compartilhado e o nome de outra sessão é recusado.
 */
class CvUploaderService implements AdiantiController
{
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

        CvUpload::remember($generated, UploadedTmpFile::cleanOriginalName($original));
        self::reply(['type' => 'success', 'fileName' => $generated]);
    }

    private static function reply(array $response): void
    {
        echo json_encode($response);
    }
}
