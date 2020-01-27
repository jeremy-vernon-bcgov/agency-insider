<?php

namespace Drupal\simplenews\Spool;

use Drupal\simplenews\Entity\Subscriber;
use Drupal\simplenews\Mail\MailEntity;

/**
 * List of mail spool entries.
 */
class SpoolList implements SpoolListInterface {

  /**
   * Array with mail spool rows being processed.
   *
   * @var array
   */
  protected $mails;

  /**
   * Array of the processed mail spool rows.
   */
  protected $processed = array();

  /**
   * Creates a spool list.
   *
   * @param array $mails
   *   List of mail spool rows.
   */
  public function __construct(array $mails) {
    $this->mails = $mails;
  }

  /**
   * {@inheritdoc}
   */
  public function count() {
    return count($this->mails);
  }

  /**
   * {@inheritdoc}
   */
  public function nextMail() {
    // Get the current mail spool row and update the internal pointer to the
    // next row.
    $spool_data = current($this->mails);
    next($this->mails);
    // If we're done, return false.
    if (!$spool_data) {
      return FALSE;
    }

    // Store this spool row as processed.
    $this->processed[$spool_data->msid] = $spool_data;

    $issue = \Drupal::entityTypeManager()
      ->getStorage($spool_data->entity_type)
      ->load($spool_data->entity_id);
    if (!$issue) {
      // If the entity load failed, set the processed status done and proceed with
      // the next mail.
      $this->processed[$spool_data->msid]->result = array(
        'status' => SpoolStorageInterface::STATUS_DONE,
        'error' => TRUE
      );
      return $this->nextMail();
    }

    if (!empty($spool_data->data)) {
      $subscriber = Subscriber::create(unserialize($spool_data->data));
    }
    else {
      $subscriber = Subscriber::load($spool_data->snid);
    }

    if (!$subscriber || !$subscriber->getMail()) {
      // If loading the subscriber failed or no email is available, set the
      // processed status done and proceed with the next mail.
      $this->processed[$spool_data->msid]->result = array(
        'status' => SpoolStorageInterface::STATUS_DONE,
        'error' => TRUE
      );
      return $this->nextMail();
    }

    $mail = new MailEntity($issue, $subscriber, \Drupal::service('simplenews.mail_cache'));

    // Set the langcode.
    $this->processed[$spool_data->msid]->langcode = $mail->getLanguage();
    return $mail;
  }

  /**
   * {@inheritdoc}
   */
  function getProcessed() {
    $processed = $this->processed;
    $this->processed = array();
    return $processed;
  }

}
