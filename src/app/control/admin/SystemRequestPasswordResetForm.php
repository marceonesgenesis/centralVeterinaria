<?php
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PasswordResetRequest;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;
use CentralVet\Security\TooManyPasswordResetAttempts;

/**
 * SystemRequestPasswordResetForm
 *
 * @version    8.6
 * @package    control
 * @subpackage admin
 * @author     Pablo Dall'Oglio
 * @copyright  Copyright (c) 2006 Adianti Solutions Ltd. (http://www.adianti.com.br)
 * @license    https://adiantiframework.com.br/license-template
 */
class SystemRequestPasswordResetForm extends TPage
{
    protected $form; // form
    
    /**
     * Class constructor
     * Creates the page and the registration form
     */
    function __construct($param)
    {
        parent::__construct();
        
        $this->style = 'clear:both';
        
        // creates the form
        $this->form = new TModalForm('form_login');
        $this->form->setFormTitle( _t('Reset password') );
        
        // create the form fields
        $login = new TEntry('login');
        
        // define the sizes
        $login->setSize('100%');
        
        $login->placeholder = _t('User');
        $this->form->addRowField(_t('Login'), $login, true);
        
        $this->form->addAction(_t('Send'), new TAction(array($this, 'onRequest')), '');
        
        parent::add($this->form);
    }
    
    /**
     *
     */
    public function onLoad($param)
    {
    }
    
    /**
     * Requests a password reset e-mail.
     *
     * The answer is the same for unknown, inactive and active logins, and also
     * when APP_PUBLIC_URL or the mail delivery fail (logged with error_log), so
     * it does not reveal whether the login exists. Only the throttle answers
     * differently, and it does not depend on the login existing.
     */
    public static function onRequest($param)
    {
        $ini = AdiantiApplicationConfig::get();
        
        try
        {
            if ($ini['permission']['reset_password'] !== '1')
            {
                throw new Exception( _t('The password reset is disabled') );
            }
            
            if (empty($ini['general']['seed']) OR $ini['general']['seed'] == 's8dkld83kf73kf094')
            {
                throw new Exception(_t('A new seed is required in the application.ini for security reasons'));
            }
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
            return;
        }
        
        try
        {
            $request = new PasswordResetRequest(
                LoginRateLimiter::fromEnvironment(PasswordResetRequest::PREFIX),
                new PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']),
                PublicUrl::fromEnvironment()
            );
            
            $request->handle(
                (string) ($param['login'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                static function (string $login): ?array {
                    TTransaction::open('permission');
                    try
                    {
                        $user = SystemUser::newFromLogin($login);
                        TTransaction::close();
                    }
                    catch (Throwable $e)
                    {
                        TTransaction::rollback();
                        throw $e;
                    }
                    
                    if (!$user instanceof SystemUser)
                    {
                        return null;
                    }
                    
                    return [
                        'login'    => (string) $user->login,
                        'name'     => (string) $user->name,
                        'email'    => (string) $user->email,
                        'active'   => (string) $user->active,
                        'password' => (string) $user->password,
                    ];
                },
                static function (string $email, string $name, string $link): void {
                    $html = new THtmlRenderer('app/resources/system/admin/reset_password.html');
                    $html->enableSection('main', ['name' => $name, 'link' => $link]);
                    
                    MailService::send( $email, _t('Password reset'), $html->getContents(), 'html' );
                },
                time()
            );
        }
        catch (TooManyPasswordResetAttempts $e)
        {
            new TMessage('error', _t('Too many password reset attempts. Try again in %s seconds.', $e->secondsUntilAvailable));
            return;
        }
        catch (Throwable $e)
        {
            error_log('SystemRequestPasswordResetForm::onRequest: ' . get_class($e) . ': ' . $e->getMessage());
        }
        
        new TMessage('info', _t('If the login is registered and active, you will receive an e-mail with the instructions to reset the password.'));
    }
}
