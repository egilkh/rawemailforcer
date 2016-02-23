<?php
namespace Craft;

/**
 * Class RawEmailForcerService
 */
class RawEmailForcerService extends BaseApplicationComponent
{

  /**
   * @var
   */
  private $_settings;

  /**
   * @var int
   */
  private $_defaultEmailTimeout = 10;

  public function init() {
    parent::init();

    RawEmailForcerPlugin::log('Creating service.', LogLevel::Info);
  }

  public function handleOnBeforeSendEmail (Event $event) {
    RawEmailForcerPlugin::log('handleOnBeforeSendEmail()', LogLevel::Info);

    $emailModel = $event->params['emailModel'];
    $variables = $event->params['variables'];

    $sproutFormsEntry = isset($variables['sproutFormsEntry']) ? $variables['sproutFormsEntry'] : null;

    // Bail!
    if (!$sproutFormsEntry) {
      RawEmailForcerPlugin::log('No sproutFormsEntry found. Bailing.', LogLevel::Info);
      return;
    }

    $sproutForm = $sproutFormsEntry->getForm();

    $handle = $sproutForm->handle;

    // @todo This should be a setting.
    if ($handle !== 'prospekt') {
      RawEmailForcerPlugin::log('sproutForm handle is wrong. Bailing.', LogLevel::Info);
      return;
    }

    RawEmailForcerPlugin::log('Overriding form sending for sproutForm: ' . $sproutForm->id, LogLevel::Info);

    $event->performAction = false;

    $user = craft()->users->getUserByEmail($emailModel->toEmail);

    if (!$user)
    {
      $user = new UserModel();
      $user->email = $emailModel->toEmail;
      $user->firstName = $emailModel->toFirstName;
      $user->lastName = $emailModel->toLastName;
    }

    $this->_sendEmail($user, $emailModel, $variables);

    $event->handled = true;

    RawEmailForcerPlugin::log('/handleOnBeforeSendEmail()', LogLevel::Info);
  }



  // Private Methods
  // =========================================================================
  /**
   * @param UserModel  $user
   * @param EmailModel $emailModel
   * @param array      $variables
   *
   * @throws Exception
   * @return bool
   */
  private function _sendEmail(UserModel $user, EmailModel $emailModel, $variables = array())
  {
    RawEmailForcerPlugin::log('_sendEmail()', LogLevel::Info);

    $emailSettings = $this->getSettings();

    if (!isset($emailSettings['protocol']))
    {
      RawEmailForcerPlugin::log('Could not determine how to send the email.  Check your email settings.', LogLevel::Info);
      throw new Exception(Craft::t('Could not determine how to send the email.  Check your email settings.'));
    }

    $email = new \PHPMailer(true);

    // Enable high debug messages.
    $email->SMTPDebug = 2;
    $email->CharSet = 'UTF-8';

    if (!empty($emailModel->replyTo))
    {
      $email->addReplyTo($emailModel->replyTo);
    }

    // Set the "from" information.
    $email->setFrom($emailModel->fromEmail, $emailModel->fromName);

    // Check which protocol we need to use.
    switch ($emailSettings['protocol'])
    {
      case EmailerType::Gmail:
      case EmailerType::Smtp:
      {
        $this->_setSmtpSettings($email, $emailSettings);
        break;
      }

      case EmailerType::Pop:
      {
        $pop = new \Pop3();

        if (!isset($emailSettings['host']) || !isset($emailSettings['port']) || !isset($emailSettings['username']) || !isset($emailSettings['password']) ||
          StringHelper::isNullOrEmpty($emailSettings['host']) || StringHelper::isNullOrEmpty($emailSettings['port']) || StringHelper::isNullOrEmpty($emailSettings['username']) || StringHelper::isNullOrEmpty($emailSettings['password'])
        )
        {
          throw new Exception(Craft::t('Host, port, username and password must be configured under your email settings.'));
        }

        if (!isset($emailSettings['timeout']))
        {
          $emailSettings['timeout'] = $this->_defaultEmailTimeout;
        }

        $pop->authorize($emailSettings['host'], $emailSettings['port'], $emailSettings['timeout'], $emailSettings['username'], $emailSettings['password'], craft()->config->get('devMode') ? 1 : 0);

        $this->_setSmtpSettings($email, $emailSettings);
        break;
      }

      case EmailerType::Sendmail:
      {
        $email->isSendmail();
        break;
      }

      case EmailerType::Php:
      {
        $email->isMail();
        break;
      }

      default:
      {
        $email->isMail();
      }
    }

    if (!$this->_processTestToEmail($email, 'Address'))
    {
      $email->addAddress($user->email, $user->getFullName());
    }

    // Add any custom headers
    if (!empty($emailModel->customHeaders))
    {
      foreach ($emailModel->customHeaders as $headerName => $headerValue)
      {
        $email->addCustomHeader($headerName, $headerValue);
      }
    }

    // Add any BCC's
    if (!empty($emailModel->bcc))
    {
      if (!$this->_processTestToEmail($email, 'BCC'))
      {
        foreach ($emailModel->bcc as $bcc)
        {
          if (!empty($bcc['email']))
          {
            $bccEmail = $bcc['email'];

            $bccName = !empty($bcc['name']) ? $bcc['name'] : '';
            $email->addBCC($bccEmail, $bccName);
          }
        }
      }
    }

    // Add any CC's
    if (!empty($emailModel->cc))
    {
      if (!$this->_processTestToEmail($email, 'CC'))
      {
        foreach ($emailModel->cc as $cc)
        {
          if (!empty($cc['email']))
          {
            $ccEmail = $cc['email'];

            $ccName = !empty($cc['name']) ? $cc['name'] : '';
            $email->addCC($ccEmail, $ccName);
          }
        }
      }
    }

    $variables['user'] = $user;

    $oldLanguage = craft()->getLanguage();

    // If they have a preferredLocale, use that.
    if ($user->preferredLocale)
    {
      craft()->setLanguage($user->preferredLocale);
    }

    $email->Subject = craft()->templates->renderString($emailModel->subject, $variables);

    $email->isHTML(false);
    $email->Body = craft()->templates->renderString($emailModel->body, $variables);

    craft()->setLanguage($oldLanguage);

    RawEmailForcerPlugin::log('$email->Send()', LogLevel::Info);

    if (!$email->Send())
    {
      RawEmailForcerPlugin::log(Craft::t('Email error: {error}', array('error' => $email->ErrorInfo)), LogLevel::Info);
      throw new Exception(Craft::t('Email error: {error}', array('error' => $email->ErrorInfo)));
    }

    RawEmailForcerPlugin::log('Successfully sent email with subject: '.$email->Subject, LogLevel::Info);

    // Fire an 'onSendEmail' event
    $this->onSendEmail(new Event($this, array(
      'user' => $user,
      'emailModel' => $emailModel,
      'variables'  => $variables,
    )));

    return true;
  }

  /**
   * Returns the system email settings defined in Settings → Email.
   *
   * @return array The system email settings.
   */
  public function getSettings()
  {
    if (!isset($this->_settings))
    {
      $this->_settings = craft()->systemSettings->getSettings('email');
    }

    return $this->_settings;
  }

  /**
   * Sets SMTP settings on a given email.
   *
   * @param $email
   * @param $emailSettings
   *
   * @throws Exception
   * @return null
   */
  private function _setSmtpSettings(&$email, $emailSettings)
  {
    $email->isSMTP();

    if (isset($emailSettings['smtpAuth']) && $emailSettings['smtpAuth'] == 1)
    {
      $email->SMTPAuth = true;

      if ((!isset($emailSettings['username']) && StringHelper::isNullOrEmpty($emailSettings['username'])) || (!isset($emailSettings['password']) && StringHelper::isNullOrEmpty($emailSettings['password'])))
      {
        throw new Exception(Craft::t('Username and password are required.  Check your email settings.'));
      }

      $email->Username = $emailSettings['username'];
      $email->Password = $emailSettings['password'];
    }

    if (isset($emailSettings['smtpKeepAlive']) && $emailSettings['smtpKeepAlive'] == 1)
    {
      $email->SMTPKeepAlive = true;
    }

    $email->SMTPSecure = $emailSettings['smtpSecureTransportType'] != 'none' ? $emailSettings['smtpSecureTransportType'] : null;

    if (!isset($emailSettings['host']))
    {
      throw new Exception(Craft::t('You must specify a host name in your email settings.'));
    }

    if (!isset($emailSettings['port']))
    {
      throw new Exception(Craft::t('You must specify a port in your email settings.'));
    }

    if (!isset($emailSettings['timeout']))
    {
      $emailSettings['timeout'] = $this->_defaultEmailTimeout;
    }

    $email->Host = $emailSettings['host'];
    $email->Port = $emailSettings['port'];
    $email->Timeout = $emailSettings['timeout'];
  }

    /**
   * @param $email
   * @param $method
   *
   * @return bool
   */
  private function _processTestToEmail($email, $method)
  {
    $testToEmail = craft()->config->get('testToEmailAddress');
    $method = 'add'.$method;

    // If they have the test email config var set to a non-empty string use it instead of the supplied email.
    if (is_string($testToEmail) && $testToEmail !== '')
    {
      $email->$method($testToEmail, 'Test Email');
      return true;
    }
    // If they have the test email config var set to a non-empty array use the values instead of the supplied email.
    else if (is_array($testToEmail) && count($testToEmail) > 0)
    {
      foreach ($testToEmail as $testEmail)
      {
        $email->$method($testEmail, 'Test Email');
      }

      return true;
    }

    return false;
  }

}
