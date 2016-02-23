<?php

namespace Craft;

/**
 * Class RawEmailForcer
 *
 * @package HIT
 */
class RawEmailForcerPlugin extends BasePlugin
{
  /**
   * @return string
   */
  public function getName()
  {
    return 'Raw Email Forcer';
  }

  /**
   * @return string
   */
  public function getVersion()
  {
    return '1.0.0';
  }

  /**
   * @return string
   */
  public function getDeveloper()
  {
    return 'Egil Hanger';
  }

  /**
   * @return string
   */
  public function getDeveloperUrl()
  {
    return 'https://www.heimdalit.no';
  }

  /**
   * @return bool
   */
  public function hasCpSection()
  {
    return false;
  }

  public function init()
  {
    parent::init();

    craft()->on('email.onBeforeSendEmail', array(rawEmailForcer(), 'handleOnBeforeSendEmail'));
  }

}

/**
 * @return RawEmailForcerService
 */
function rawEmailForcer()
{
  return Craft::app()->getComponent('rawEmailForcer');
}
