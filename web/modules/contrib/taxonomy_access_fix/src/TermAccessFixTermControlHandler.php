<?php

namespace Drupal\taxonomy_access_fix;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the taxonomy term entity type.
 *
 * @see \Drupal\taxonomy\Entity\Term
 */
class TermAccessFixTermControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        if ($account->hasPermission('administer taxonomy')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        $access_result = AccessResult::allowedIfHasPermission($account, "view terms in {$entity->bundle()}")
          ->andIf(AccessResult::allowedIf($entity->isPublished()))
          ->cachePerPermissions()
          ->addCacheableDependency($entity);
        if (!$access_result->isAllowed()) {
          $access_result->setReason("The 'view terms in {$entity->bundle()}' OR 'administer taxonomy' permission is required and the taxonomy term must be published.");
        }
        return $access_result;

      case 'update':
        return AccessResult::allowedIfHasPermissions($account, [
          "edit terms in {$entity->bundle()}",
          'administer taxonomy',
        ], 'OR');

      case 'delete':
        return AccessResult::allowedIfHasPermissions($account, [
          "delete terms in {$entity->bundle()}",
          'administer taxonomy',
        ], 'OR');

      default:
        // No opinion.
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, "add terms in $entity_bundle");
  }

}
