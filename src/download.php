<?php

declare(strict_types=1);

chdir(__DIR__);
require_once __DIR__ . '/init.php';
new TSession(\CentralVet\Session\SessionHandlerFactory::createFromEnvironment());

if (isset($_GET['file']) && TSession::getValue('logged'))
{
    $requestedFile = (string) $_GET['file'];
    if ($requestedFile === '' || str_contains($requestedFile, "\0"))
    {
        return;
    }

    $filesRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'files');
    $file = realpath($requestedFile);

    if ($filesRoot === false || $file === false || !is_file($file))
    {
        return;
    }

    $filesPrefix = $filesRoot . DIRECTORY_SEPARATOR;
    if (!str_starts_with($file, $filesPrefix))
    {
        return;
    }

    $systemRoot = realpath($filesRoot . DIRECTORY_SEPARATOR . 'system');
    if ($systemRoot !== false && ($file === $systemRoot || str_starts_with($file, $systemRoot . DIRECTORY_SEPARATOR)))
    {
        return;
    }

    $info      = pathinfo($file);
    $extension = $info['extension'];
    
    $content_type_list = array();
    $content_type_list['txt']  = 'text/plain';
    $content_type_list['html'] = 'text/html';
    $content_type_list['csv']  = 'text/csv';
    $content_type_list['pdf']  = 'application/pdf';
    $content_type_list['rtf']  = 'application/rtf';
    $content_type_list['doc']  = 'application/msword';
    $content_type_list['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $content_type_list['xls']  = 'application/vnd.ms-excel';
    $content_type_list['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $content_type_list['ppt']  = 'application/vnd.ms-powerpoint';
    $content_type_list['pptx'] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    $content_type_list['odt']  = 'application/vnd.oasis.opendocument.text';
    $content_type_list['ods']  = 'application/vnd.oasis.opendocument.spreadsheet';
    $content_type_list['jpeg'] = 'image/jpeg';
    $content_type_list['jpg']  = 'image/jpeg';
    $content_type_list['png']  = 'image/png';
    $content_type_list['gif']  = 'image/gif';
    $content_type_list['svg']  = 'image/svg+xml';
    $content_type_list['xml']  = 'application/xml';
    $content_type_list['zip']  = 'application/zip';
    $content_type_list['rar']  = 'application/x-rar-compressed';
    $content_type_list['bz']   = 'application/x-bzip';
    $content_type_list['bz2']  = 'application/x-bzip2';
    $content_type_list['tar']  = 'application/x-tar';
    
    if (in_array(strtolower($extension), array_keys($content_type_list), true))
    {
        $basename = !empty($_GET['basename']) ? $_GET['basename'] : basename($file);
        $basename = str_replace(["\r", "\n", '"'], '', basename((string) $basename));
        $filesize = filesize($file); // get the filesize
        
        header("Pragma: public");
        header("Expires: 0"); // set expiration time
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/octet-stream');
        header("Content-Length: {$filesize}");
        header("Content-Disposition: attachment; filename=\"{$basename}\"");
        header("Content-Transfer-Encoding: binary");
        
        // a readfile da problemas no internet explorer
        // melhor jogar direto o conteudo do arquivo na tela
        readfile($file);
    }
}
