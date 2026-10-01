<?php

use CentralVet\Presentation\UploadedTmpFile;

/**
 * SystemDriveDocumentUploadForm
 *
 * @version    8.6
 * @package    control
 * @subpackage communication
 * @author     Pablo Dall'Oglio
 * @copyright  Copyright (c) 2006 Adianti Solutions Ltd. (http://www.adianti.com.br)
 * @license    https://adiantiframework.com.br/license-template
 */
class SystemDriveDocumentUploadForm extends TWindow
{
    protected $form;
    protected $folder_path;

    /**
     * T-20: tipos (finfo) aceitos no Drive, copiados de
     * SystemDocumentUploaderService::show ($content_type_list), que o
     * CvUploaderService substituiu (T-63) sem a checagem de MIME.
     */
    private const ALLOWED_MIMES = [
        'text/plain',
        'text/html',
        'text/csv',
        'application/pdf',
        'application/rtf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/svg+xml',
        'application/xml',
        'application/zip',
        'application/x-rar-compressed',
        'application/x-bzip',
        'application/x-bzip2',
        'application/x-tar',
    ];
    
    /**
     * Form constructor
     * @param $param Request
     */
    public function __construct()
    {
        parent::__construct();
        
        parent::setTitle( _t('Document') );
        parent::setModal(TRUE);
        parent::removePadding();
        parent::setSize(500, null);
        parent::setMinWidth(0.8, 500);

        $this->form = new BootstrapFormBuilder('SystemDriveDocumentUploadForm');
        $this->form->setProperty('class', 'card noborder');
        $this->form->enableClientValidation();
        $this->form->setFieldSizes('100%');
        
        $id    = new THidden('id');
        $folder_path = new THidden('system_folder_id');
        $title = new TEntry('title');
        $file  = new TFile('filename');
        $description = new TText('description');
        
        // T-63: nome imprevisível em tmp/, vinculado à sessão (CvUpload).
        // As extensões espelham os tipos do SystemDocumentUploaderService.
        $file->setService('CvUploaderService');
        $file->setAllowedExtensions(['txt', 'html', 'csv', 'pdf', 'rtf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'jpeg', 'jpg', 'png', 'gif', 'svg', 'xml', 'zip', 'rar', 'bz', 'bz2', 'tar']);
        
        $this->form->addFields([$id]);
        $this->form->addFields([$folder_path]);
        $this->form->addFields([new TLabel(_t('File'))]);
        $this->form->addFields([$file]);
        $this->form->addFields([new TLabel(_t('Title'))]);
        $this->form->addFields([$title]);
        $this->form->addFields([new TLabel(_t('Description'))]);
        $this->form->addFields([$description]);
        
        $file->addValidation(_t('File'), new TRequiredValidator);
        
        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-sm btn-primary';

        parent::add($this->form);
    }

    /**
     * Save form data
     * @param $param Request
     */
    public function onSave($param)
    {
        try
        {
            TTransaction::open('communication');
            
            // T-62/T-63: the uploaded name must be a regular file inside tmp/
            // (no ../, separators or symlink out) uploaded by this session
            // through CvUploaderService, checked before store()
            $source_file = null;
            $upload_name = null;
            if (!empty($param['filename']))
            {
                $source_file = CvUpload::resolve((string) $param['filename']);
                $upload_name = trim((string) $param['filename']);
                // T-20: o tipo real (finfo) precisa estar na lista do Drive;
                // recusado, o arquivo sai de tmp/ e do registro da sessão
                if (!UploadedTmpFile::mimeAllowed($source_file, self::ALLOWED_MIMES))
                {
                    @unlink($source_file);
                    CvUpload::forget($upload_name);
                    throw new InvalidArgumentException('Invalid file');
                }
                // no disco (e no caminho): o nome saneado sem prefixo; o
                // original UTF-8 vira o título quando o usuário não deu um
                $param['filename'] = CvUpload::displayName($upload_name);
                if (trim((string) ($param['title'] ?? '')) === '')
                {
                    $param['title'] = CvUpload::originalName($upload_name);
                }
            }
            
            $object = new SystemDocument;
            $object->fromArray( $param );
            $object->submission_date = date('Y-m-d H:i:s');
            $object->system_user_id = TSession::getValue('userid');
            $object->title = $object->title ? $object->title : $object->filename;
            $object->store();
            
            $target_path   = 'files/system/documents/' . $object->id;
            $target_file   =  $target_path . '/' . $object->filename;
            
            if ($source_file !== null)
            {
                if (!file_exists($target_path))
                {
                    if (!@mkdir($target_path, 0777, true))
                    {
                        throw new Exception(_t('Permission denied'). ': '. $target_path);
                    }
                }
                else
                {
                    foreach (glob("$target_path/*") as $file)
                    {
                        unlink($file);
                    }
                }
                
                // if the user uploaded a source file
                if (file_exists($target_path))
                {
                    // move to the target directory
                    rename($source_file, $target_file);
                }
                
                CvUpload::forget($upload_name);
            }
            
            TTransaction::close();

            TToast::show('success', _t('Record saved'));
            
            AdiantiCoreApplication::loadPage('SystemDriveList', 'onLoad', [
                'path' => TSession::getValue('SystemDriveListpath'),
                'filter' => 'my'
            ]);
        }
        catch (InvalidArgumentException $e)
        {
            TTransaction::rollback();
            new TMessage('error', $e->getMessage() === 'Invalid file' ? _t('Invalid file') : $e->getMessage());
        }
        catch (Exception $e)
        {
            TToast::show('error', $e->getMessage());
            TTransaction::rollback();
        }
    }
    
    /**
     * Load object to form data
     * @param $param Request
     */
    public function onNew( $param )
    {
        $data = new stdClass;
        $data->system_folder_id = $param['path'] ?? null;
        $this->form->setData($data);
    }
}
