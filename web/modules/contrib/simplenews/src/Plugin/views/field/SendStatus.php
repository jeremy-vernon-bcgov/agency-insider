<?php

namespace Drupal\simplenews\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to provide send status of a newsletter issue.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("simplenews_send_status")
 */
class SendStatus extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $node = $values->_entity;
    if ($node->hasField('simplenews_issue')) {
      // Get elements to render.
      $message = $this->getMessage($node);
      if (!empty($message['uri'])) {
        $output['image'] = array(
          '#theme' => 'image',
          '#uri' => $message['uri'],
          '#alt' => $message['description'],
          '#title' => $message['description'],
          '#getsize' => TRUE,
        );
      }
      $output['text'] = array(
        '#type' => 'inline_template',
        '#template' => '<span title="{{ description }}">{{ sent_count }}/{{ count }}</span>',
        '#context' => $message,
      );
      return $output;
    }
  }

  /**
   * Return a compiled message to display.
   *
   * @param $node
   *   The node object.
   *
   * @return array
   *   An array containing the elements of the message to be rendered.
   */
  protected function getMessage($node) {
    $status = $node->simplenews_issue->status;
    $message = \Drupal::service('simplenews.spool_storage')->issueSummary($node);

    $images = array(
      SIMPLENEWS_STATUS_SEND_PENDING => 'images/sn-cron.png',
      SIMPLENEWS_STATUS_SEND_READY => 'images/sn-sent.png',
    );
    if (isset($images[$status])) {
      $message['uri'] = drupal_get_path('module', 'simplenews') . '/' . $images[$status];
    }
    else {
      $message['uri'] = NULL;
    }

    return $message;
  }
}
