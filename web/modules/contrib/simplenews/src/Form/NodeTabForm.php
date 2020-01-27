<?php

namespace Drupal\simplenews\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\simplenews\Spool\SpoolStorageInterface;
use Drupal\simplenews\Mail\MailerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure simplenews subscriptions of a user.
 */
class NodeTabForm extends FormBase {

  /**
   * The spool storage.
   *
   * @var \Drupal\simplenews\Spool\SpoolStorageInterface
   */
  protected $spoolStorage;

  /**
   * The currently authenticated user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The simplenews mailer.
   *
   * @var \Drupal\simplenews\Mail\MailerInterface
   */
  protected $mailer;

  /**
   * Constructs a new NodeTabForm.
   *
   * @param \Drupal\simplenews\Spool\SpoolStorageInterface $spool_storage
   *   The spool storage.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The currently authenticated user.
   * @param \Drupal\simplenews\Mail\MailerInterface $simplenews_mailer
   *   The simplenews mailer service.
   */
  public function __construct(SpoolStorageInterface $spool_storage, AccountInterface $current_user, MailerInterface $simplenews_mailer) {
    $this->spoolStorage = $spool_storage;
    $this->currentUser = $current_user;
    $this->mailer = $simplenews_mailer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simplenews.spool_storage'),
      $container->get('current_user'),
      $container->get('simplenews.mailer')
    );
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simplenews_node_tab';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    $config = $this->config('simplenews.settings');
    $status = $node->simplenews_issue->status;
    $summary = $this->spoolStorage->issueSummary($node);
    $form['#title'] = $this->t('<em>Newsletter issue</em> @title', array('@title' => $node->getTitle()));

    // We will need the node.
    $form_state->set('node', $node);

    // Show newsletter sending options if newsletter has not been send yet.
    // If send a notification is shown.
    if ($status == SIMPLENEWS_STATUS_SEND_NOT) {

      $form['test'] = array(
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => t('Test'),
      );
      $form['test']['test_address'] = array(
        '#type' => 'textfield',
        '#title' => t('Test email addresses'),
        '#description' => t('A comma-separated list of email addresses to be used as test addresses.'),
        '#default_value' => $this->currentUser->getEmail(),
        '#size' => 60,
        '#maxlength' => 128,
      );

      $form['test']['submit'] = array(
        '#type' => 'submit',
        '#value' => t('Send test newsletter issue'),
        '#name' => 'send_test',
        '#submit' => array('::submitTestMail'),
        '#validate' => array('::validateTestAddress'),
      );
      $form['send'] = array(
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => t('Send'),
      );

      // Add some text to describe the send situation.
      $form['send']['count'] = array(
        '#type' => 'item',
        '#markup' => t('Send newsletter issue to @count subscribers.', array('@count' => $summary['count'])),
      );

      if (!$node->isPublished()) {
        $send_text = t('Mails will be sent when the issue is published.');
        $button_text = t('Send on publish');
      }
      elseif (!$config->get('mail.use_cron')) {
        $send_text = t('Mails will be sent immediately.');
      }
      else {
        $send_text = t('Mails will be sent when cron runs.');
      }

      $form['send']['method'] = array(
        '#type' => 'item',
        '#markup' => $send_text,
      );

      $form['send']['send'] = array(
        '#type' => 'submit',
        '#button_type' => 'primary',
        '#value' => $button_text ?? t('Send now'),
      );
    }
    else {
      $form['status'] = array(
        '#type' => 'item',
        '#title' => $summary['description'],
      );
      if ($status != SIMPLENEWS_STATUS_SEND_READY) {
        $form['actions'] = array(
          '#type' => 'actions',
        );
        $form['actions']['stop'] = array(
          '#type' => 'submit',
          '#submit' => array('::submitStop'),
          '#value' => t('Stop sending'),
        );
      }
    }
    return $form;
  }

  /**
   * Validates the test address.
   */
  public function validateTestAddress(array $form, FormStateInterface $form_state) {
    $test_address = $form_state->getValue('test_address');
    $test_address = trim($test_address);
    if (!empty($test_address)) {
      $mails = explode(',', $test_address);
      foreach ($mails as $mail) {
        $mail = trim($mail);
        if (!valid_email_address($mail)) {
          $form_state->setErrorByName('test_address', t('Invalid email address "%mail".', array('%mail' => $mail)));
        }
      }
      $form_state->set('test_addresses', $mails);
    }
    else {
      $form_state->setErrorByName('test_address', t('Missing test email address.'));
    }
  }

  /**
   * Submit handler for sending test mails.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   */
  public function submitTestMail(array &$form, FormStateInterface $form_state) {
    $this->mailer->sendTest($form_state->get('node'), $form_state->get('test_addresses'));
  }

  /**
   * Submit handler for sending published newsletter issue.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->spoolStorage->addIssue($form_state->get('node'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitStop(array &$form, FormStateInterface $form_state) {
    $this->spoolStorage->deleteIssue($form_state->get('node'));
  }

  /**
   * Checks access for the simplenews node tab.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node where the tab should be added.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   An access result object.
   */
  public function checkAccess(NodeInterface $node) {
    $account = $this->currentUser();

    if ($node->hasField('simplenews_issue') && $node->simplenews_issue->target_id != NULL) {
      return AccessResult::allowedIfHasPermission($account, 'administer newsletters')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'send newsletter'));
    }
    return AccessResult::neutral();
  }

}
