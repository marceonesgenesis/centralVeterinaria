<?php
use CentralVet\Security\InvalidPasswordResetToken;
use CentralVet\Security\PasswordResetToken;

/**
 * SystemPasswordResetForm
 *
 * @version    8.6
 * @package    control
 * @subpackage admin
 * @author     Pablo Dall'Oglio
 * @copyright  Copyright (c) 2006 Adianti Solutions Ltd. (http://www.adianti.com.br)
 * @license    https://adiantiframework.com.br/license-template
 */
class SystemPasswordResetForm extends TPage
{
    protected $form; // form
    
    /**
     * Class constructor
     * Creates the page and the registration form
     */
    public function __construct($param)
    {
        parent::__construct();
        
        $this->style = 'clear:both';
        // creates the form
        $this->form = new BootstrapFormBuilder('form_login');
        $this->form->setFormTitle( _t('Reset password') );
        
        // create the form fields
        $jwt = new THidden('jwt');
        $password1 = new TPassword('password1');
        $password2 = new TPassword('password2');
        
        // define the sizes
        $password1->setSize('70%', 40);
        $password2->setSize('70%', 40);
        
        $locker = '<span style="float:left;margin-left:44px;height:35px;" class="login-avatar"><span class="fa fa-lock"></span></span>';
        $password1->style = 'height:35px; font-size:14px;float:left;border-bottom-left-radius: 0;border-top-left-radius: 0;';
        $password2->style = 'height:35px; font-size:14px;float:left;border-bottom-left-radius: 0;border-top-left-radius: 0;';
        
        $password1->placeholder = _t('Password');
        $password2->placeholder = _t('Password confirmation');
        
        $this->form->addFields( [$jwt] );
        $this->form->addFields( [$locker, $password1] );
        $this->form->addFields( [$locker, $password2] );
        
        $btn = $this->form->addAction(_t('Send'), new TAction(array($this, 'onReset')), '');
        $btn->class = 'btn btn-primary';
        $btn->style = 'height: 40px;width: 90%;display: block;margin: auto;font-size:17px;';
        
        $wrapper = new TElement('div');
        $wrapper->style = 'margin:auto; margin-top:100px;max-width:460px;';
        $wrapper->id    = 'login-wrapper';
        $wrapper->add($this->form);
        
        // add the form to the page
        parent::add($wrapper);
    }
    
    /**
     * Form load
     */
    public function onLoad($param)
    {
        $data = new stdClass;
        $data->jwt = $param['jwt'];
        $this->form->setData($data);
    }

    /**
     * Authenticate the User
     */
    public static function onReset($param)
    {
        $ini = AdiantiApplicationConfig::get();
        
        try
        {
            if (empty($param['password1']))
            {
                throw new Exception('Senha vazia');
            }
            
            if( $param['password1'] !== $param['password2'] )
            {
                throw new Exception(_t('The passwords do not match'));
            }
            
            if (isset($ini['general']['validate_strong_pass']) && $ini['general']['validate_strong_pass'] == '1')
            {
                (new TStrongPasswordValidator)->validate(_t('Password'), $param['password1']);
            }
            
            if (empty($ini['general']['seed']) OR $ini['general']['seed'] == 's8dkld83kf73kf094')
            {
                throw new Exception(_t('A new seed is required in the application.ini for security reasons'));
            }
            
            $tokens = new PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']);
            
            // malformed, badly signed or expired link → InvalidPasswordResetToken
            $claims = $tokens->verify((string) ($param['jwt'] ?? ''), time());
            
            TTransaction::open('permission');
            $user  = SystemUser::newFromLogin($claims['user']);
            
            // single use: the claim pwd is bound to the current password hash,
            // so the link stops working once the password changes
            if (!$user instanceof SystemUser
                || $user->active !== 'Y'
                || !$tokens->matchesPassword($claims, (string) $user->password))
            {
                throw new InvalidPasswordResetToken('Password reset token does not match the current user');
            }
            
            SystemUserOldPassword::validate($user->id, $param['password1']);
            SystemUserOldPassword::register($user->id, $param['password1']);
            $user->password = SystemUser::passwordHash($param['password1']);
            $user->store();
            
            TTransaction::close();
            
            new TMessage('info', _t('The password has been changed'));
        }
        catch (InvalidPasswordResetToken $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('This password reset link is invalid, expired or has already been used.'));
        }
        catch (Exception $e)
        {
            new TMessage('error',$e->getMessage());
            TTransaction::rollback();
        }
    }
}
