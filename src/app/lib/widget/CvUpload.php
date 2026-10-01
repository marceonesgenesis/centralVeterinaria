<?php

use Adianti\Registry\TSession;
use CentralVet\Presentation\UploadedTmpFile;

/**
 * Ponte entre os handlers de upload e a lista de nomes da sessão (rodada 2,
 * T-63). O caminho de um upload em tmp/ só sai daqui: resolve() exige que o
 * nome tenha sido gerado pelo CvUploaderService nesta sessão, e forget()
 * tira o nome da lista depois do consumo. Recusa → 'Invalid file'.
 */
class CvUpload
{
    public static function resolve(string $name): string
    {
        return UploadedTmpFile::resolveForSession($name, self::uploads());
    }

    public static function forget(string $name): void
    {
        $name = trim($name);
        $uploads = array_values(array_filter(self::uploads(), static fn (string $item): bool => $item !== $name));

        TSession::setValue(CvUploaderService::SESSION_KEY, $uploads);
    }

    /**
     * Nome para mostrar e gravar como original (sem o prefixo hex).
     */
    public static function displayName(string $name): string
    {
        return UploadedTmpFile::displayName(trim($name));
    }

    /**
     * Lista de nomes desta sessão, para UploadedTmpFile::newUploadItems().
     *
     * @return list<string>
     */
    public static function uploads(): array
    {
        $uploads = TSession::getValue(CvUploaderService::SESSION_KEY);

        return is_array($uploads) ? array_values(array_filter($uploads, 'is_string')) : [];
    }
}
