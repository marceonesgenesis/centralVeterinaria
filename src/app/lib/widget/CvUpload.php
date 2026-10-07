<?php

use Adianti\Registry\TSession;
use CentralVet\Presentation\UploadedTmpFile;

/**
 * Ponte entre os handlers de upload e o registro da sessão (rodada 2, T-63).
 * TSession 'cv_uploads' guarda nome em disco → nome original (UTF-8, texto
 * puro), com no máximo 50 pares. O caminho de um upload em tmp/ só sai
 * daqui: resolve() exige que o nome tenha sido gerado pelo CvUploaderService
 * nesta sessão, e forget() tira o par do registro depois do consumo.
 * Recusa → 'Invalid file'.
 */
class CvUpload
{
    public const SESSION_KEY = 'cv_uploads';

    public static function resolve(string $name): string
    {
        return UploadedTmpFile::resolveForSession($name, self::uploads());
    }

    public static function remember(string $diskName, string $originalName): void
    {
        TSession::setValue(self::SESSION_KEY, UploadedTmpFile::rememberUpload(self::registry(), $diskName, $originalName));
    }

    public static function forget(string $name): void
    {
        $map = self::registry();
        unset($map[trim($name)]);

        TSession::setValue(self::SESSION_KEY, $map);
    }

    /**
     * Nome original (UTF-8) para mostrar, gravar como original_name e usar
     * no Content-Disposition. Texto: escape na exibição, nunca em caminho.
     */
    public static function originalName(string $name): string
    {
        return UploadedTmpFile::originalName(trim($name), self::registry());
    }

    /**
     * Nome saneado sem o prefixo hex, seguro para caminho.
     */
    public static function displayName(string $name): string
    {
        return UploadedTmpFile::displayName(trim($name));
    }

    /**
     * Nomes em disco desta sessão, para resolveForSession()/newUploadItems().
     *
     * @return list<string>
     */
    public static function uploads(): array
    {
        return array_keys(self::registry());
    }

    /** @return array<string, string> */
    private static function registry(): array
    {
        $uploads = TSession::getValue(self::SESSION_KEY);

        return is_array($uploads) ? UploadedTmpFile::sessionMap($uploads) : [];
    }
}
