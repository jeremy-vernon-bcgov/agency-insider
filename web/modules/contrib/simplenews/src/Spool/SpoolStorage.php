<?php

namespace Drupal\simplenews\Spool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\simplenews\recipientHandler\RecipientHandlerManager;

/**
 * Default database spool storage.
 */
class SpoolStorage implements SpoolStorageInterface {
  use MessengerTrait;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\simplenews\recipientHandler\recipientHandlerManager
   */
  protected $recipientHandlerManager;

  /**
   * Creates a SpoolStorage object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   * @param \Drupal\simplenews\recipientHandler\recipientHandlerManager
   *   The recipient handler manager.
   */
  public function __construct(Connection $connection, LockBackendInterface $lock, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, RecipientHandlerManager $recipient_handler_manager) {
    $this->connection = $connection;
    $this->lock = $lock;
    $this->config = $config_factory->get('simplenews.settings');
    $this->moduleHandler = $module_handler;
    $this->recipientHandlerManager = $recipient_handler_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getMails($limit = self::UNLIMITED, $conditions = array()) {
    $messages = array();

    // Continue to support 'nid' as a condition.
    if (!empty($conditions['nid'])) {
      $conditions['entity_type'] = 'node';
      $conditions['entity_id'] = $conditions['nid'];
      unset($conditions['nid']);
    }

    // Add default status condition if not set.
    if (!isset($conditions['status'])) {
      $conditions['status'] = array(SpoolStorageInterface::STATUS_PENDING, SpoolStorageInterface::STATUS_IN_PROGRESS);
    }

    // Special case for the status condition, the in progress actually only
    // includes spool items whose locking time has expired. So this need to build
    // an OR condition for them.
    $status_or = new Condition('OR');
    $statuses = is_array($conditions['status']) ? $conditions['status'] : array($conditions['status']);
    foreach ($statuses as $status) {
      if ($status == SpoolStorageInterface::STATUS_IN_PROGRESS) {
        $status_or->condition((new Condition('AND'))
          ->condition('status', $status)
          ->condition('s.timestamp', $this->getExpirationTime(), '<')
        );
      }
      else {
        $status_or->condition('status', $status);
      }
    }
    unset($conditions['status']);

    $query = $this->connection->select('simplenews_mail_spool', 's')
      ->fields('s')
      ->condition($status_or)
      ->orderBy('s.timestamp', 'ASC');

    // Add conditions.
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }

    /* BEGIN CRITICAL SECTION */
    // The semaphore ensures that multiple processes get different message ID's,
    // so that duplicate messages are not sent.
    if ($this->lock->acquire('simplenews_acquire_mail')) {
      // Get message id's
      // Allocate messages
      if ($limit > 0) {
        $query->range(0, $limit);
      }
      foreach ($query->execute() as $message) {
        $messages[$message->msid] = $message;
      }
      if (count($messages) > 0) {
        // Set the state and the timestamp of the messages
        $this->updateMails(
          array_keys($messages), array('status' => SpoolStorageInterface::STATUS_IN_PROGRESS)
        );
      }

      $this->lock->release('simplenews_acquire_mail');
    }

    /* END CRITICAL SECTION */

    return new SpoolList($messages);
  }

  /**
   * {@inheritdoc}
   */
  public function updateMails($msids, array $data) {
    $this->connection->update('simplenews_mail_spool')
      ->condition('msid', (array) $msids, 'IN')
      ->fields(array(
        'status' => $data['status'],
        'error' => isset($data['error']) ? (int) $data['error'] : 0,
        'timestamp' => REQUEST_TIME,
      ))
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function countMails(array $conditions = array()) {

    // Continue to support 'nid' as a condition.
    if (!empty($conditions['nid'])) {
      $conditions['entity_type'] = 'node';
      $conditions['entity_id'] = $conditions['nid'];
      unset($conditions['nid']);
    }

    // Add default status condition if not set.
    if (!isset($conditions['status'])) {
      $conditions['status'] = array(SpoolStorageInterface::STATUS_PENDING, SpoolStorageInterface::STATUS_IN_PROGRESS);
    }

    $query = $this->connection->select('simplenews_mail_spool');
    // Add conditions.
    foreach ($conditions as $field => $value) {
      if ($field == 'status') {
        if (!is_array($value)) {
          $value = array($value);
        }
        $status_or = new Condition('OR');
        foreach ($value as $status) {
          // Do not count pending entries unless they are expired.
          if ($status == SpoolStorageInterface::STATUS_IN_PROGRESS) {
            $status_or->condition((new Condition('AND'))
              ->condition('status', $status)
              ->condition('timestamp', $this->getExpirationTime(), '<')
            );
          }
          else {
            $status_or->condition('status', $status);
          }
        }
        $query->condition($status_or);
      }
      else {
        $query->condition($field, $value);
      }
    }

    $query->addExpression('COUNT(*)', 'count');

    return (int)$query
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {

    $expiration_time = REQUEST_TIME - $this->config->get('mail.spool_expire') * 86400;
    return $this->connection->delete('simplenews_mail_spool')
      ->condition('status', [SpoolStorageInterface::STATUS_DONE, SpoolStorageInterface::STATUS_SKIPPED], 'IN')
      ->condition('timestamp', $expiration_time, '<=')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMails(array $conditions) {
    $query = $this->connection->delete('simplenews_mail_spool');

    foreach ($conditions as $condition => $value) {
      $query->condition($condition, $value);
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function addIssue($issue) {
    if (!in_array($issue->simplenews_issue->status, [SIMPLENEWS_STATUS_SEND_NOT, SIMPLENEWS_STATUS_SEND_PUBLISH])) {
      return;
    }

    if (!$issue->isPublished()) {
      $issue->simplenews_issue->status = SIMPLENEWS_STATUS_SEND_PUBLISH;
      $issue->save();
      $this->messenger()->addMessage(t('Newsletter issue %title will be sent when published.', array('%title' => $issue->getTitle())));
      return;
    }

    $recipient_handler = $this->getRecipientHandler($issue);
    $issue->simplenews_issue->subscribers = $recipient_handler->addToSpool();
    $issue->simplenews_issue->sent_count = 0;
    $issue->simplenews_issue->status = SIMPLENEWS_STATUS_SEND_PENDING;

    // Save except if already saving.
    if (!isset($issue->original)) {
      $issue->save();
    }

    // Notify other modules that a newsletter was just spooled.
    $this->moduleHandler->invokeAll('simplenews_spooled', [$issue]);

    // Attempt to send immediately, if configured to do so.
    if (\Drupal::service('simplenews.mailer')->attemptImmediateSend(['entity_type' => $issue->getEntityTypeId(), 'entity_id' => $issue->id()])) {
      $this->messenger()->addMessage(t('Newsletter issue %title sent.', ['%title' => $issue->getTitle()]));
    }
    else {
      $this->messenger()->addMessage(t('Newsletter issue %title pending.', ['%title' => $issue->getTitle()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteIssue(ContentEntityInterface $issue) {
    if ($issue->simplenews_issue->status != SIMPLENEWS_STATUS_SEND_PENDING) {
      return;
    }

    $count = $this->deleteMails(['entity_type' => $issue->getEntityTypeId(), 'entity_id' => $issue->id()]);
    $issue->simplenews_issue->status = SIMPLENEWS_STATUS_SEND_NOT;
    $issue->save();

    $this->messenger()->addMessage(t('Sending of %title was stopped. @count pending email(s) were deleted.', [
      '%title' => $issue->getTitle(),
      '@count' => $count,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function addMail(array $spool) {
    if (!isset($spool['status'])) {
      $spool['status'] = SpoolStorageInterface::STATUS_PENDING;
    }
    if (!isset($spool['timestamp'])) {
      $spool['timestamp'] = REQUEST_TIME;
    }
    if (isset($spool['data'])) {
      $spool['data'] = serialize($spool['data']);
    }

    $this->connection->insert('simplenews_mail_spool')
      ->fields($spool)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getRecipientHandler(ContentEntityInterface $issue, array $edited_values = NULL, $return_options = FALSE) {
    $field = $issue->get('simplenews_issue');
    $newsletter_ids = $field->isEmpty() ? [] : array_map(function ($i) { return $i['target_id']; }, $field->getValue());
    $newsletter_id = $edited_values['target_id'] ?? $newsletter_ids[0] ?? NULL;
    $handler = ($edited_values['handler'] ?? $field->handler) ?: 'simplenews_all';

    // Ensure the requested handler is a valid option.
    $options = $this->recipientHandlerManager->getOptions($newsletter_id);
    if (!isset($options[$handler])) {
      reset($options);
      $handler = key($options);
    }

    $handler_settings = $edited_values['handler_settings'] ?? $field->handler_settings;
    $handler_settings['_issue'] = $issue;
    $handler_settings['_connection'] = $this->connection;
    $handler_settings['_newsletter_ids'] = $newsletter_ids;
    $recipient_handler = $this->recipientHandlerManager->createInstance($handler, $handler_settings);

    return $return_options ? [$recipient_handler, $options] : $recipient_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function issueSummary(ContentEntityInterface $issue) {
    $status = $issue->simplenews_issue->status;
    $summary['sent_count'] = (int) $issue->simplenews_issue->sent_count;
    $summary['count'] = (int) $issue->simplenews_issue->subscribers;

    if ($status == SIMPLENEWS_STATUS_SEND_READY) {
      $summary['description'] = t('Newsletter issue sent to @count subscribers.', ['@count' => $summary['count']]);
    }
    elseif ($status == SIMPLENEWS_STATUS_SEND_PENDING) {
      $summary['description'] = t('Newsletter issue is pending, @sent mails sent out of @count.', [
        '@sent' => $summary['sent_count'],
        '@count' => $summary['count'],
      ]);
    }
    else {
      $summary['count'] = $this->issueCountRecipients($issue);
      if ($status == SIMPLENEWS_STATUS_SEND_NOT) {
        $summary['description'] = t('Newsletter issue will be sent to @count subscribers.', ['@count' => $summary['count']]);
      }
      else {
        $summary['description'] = t('Newsletter issue will be sent to @count subscribers on publish.', ['@count' => $summary['count']]);
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function issueCountRecipients(ContentEntityInterface $issue) {
    return $this->getRecipientHandler($issue)->count();
  }

  /**
   * Returns the expiration time for IN_PROGRESS status.
   *
   * @return int
   *   A unix timestamp. Any IN_PROGRESS messages with a timestamp older than
   *   this will be re-allocated and re-sent.
   */
  protected function getExpirationTime() {
    $timeout = $this->config->get('mail.spool_progress_expiration');
    $expiration_time = REQUEST_TIME - $timeout;
    return $expiration_time;
  }

}
