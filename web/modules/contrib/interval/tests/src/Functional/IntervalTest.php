<?php

namespace Drupal\Tests\interval\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Tests\BrowserTestBase;

/**
 * Ensures that the interval field works correctly.
 *
 * @group interval
 */
class IntervalTest extends BrowserTestBase {

  /**
   * Profile to use.
   */
  protected $profile = 'testing';

  /**
   * Theme to use.
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array(
    'field_ui',
    'interval',
    'entity_test',
  );

  /**
   * Permissions to grant admin user.
   *
   * @var array
   */
  protected $permissions = array(
    'access administration pages',
    'view test entity',
    'administer entity_test fields',
    'administer entity_test content',
  );

  /**
   * Sets the test up.
   */
  protected function setUp() {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser($this->permissions);
  }

  /**
   * Tests adding and editing values using interval.
   */
  public function testInterval() {
    $this->drupalLogin($this->adminUser);
    // Add a new interval field.
    $this->drupalGet('entity_test/structure/entity_test/fields/add-field');
    $edit = array(
      'label' => 'Foobar',
      'field_name' => 'foobar',
      'new_storage_type' => 'interval',
    );
    $this->drupalPostForm(NULL, $edit, t('Save and continue'));
    $this->drupalPostForm(NULL, array(
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ), t('Save field settings'));

    $this->drupalPostForm(NULL, array(), t('Save settings'));
    $this->assertRaw(t('Saved %name configuration', array('%name' => 'Foobar')));

    // Setup widget and formatters.
    EntityFormDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', array(
        'type' => 'interval_default',
        'weight' => 20,
      ))
      ->save();

    EntityViewDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', array(
        'label' => 'hidden',
        'type' => 'interval_default',
        'weight' => 20,
      ))
      ->save();

    // Test the fields values/widget.
    $this->drupalGet('entity_test/add');
    $this->assertField('field_foobar[0][interval]', 'Found foobar field interval');
    $this->assertField('field_foobar[0][period]', 'Found foobar field period');

    // Add some extra fields.
    $button = $this->getSession()->getPage()->findButton('Add another item');
    $button->click();
    $button->click();

    $edit = array(
      'field_foobar[0][period]' => 'week',
      'field_foobar[0][interval]' => 1,
      'field_foobar[1][period]' => 'day',
      'field_foobar[1][interval]' => 3,
      'field_foobar[2][period]' => 'quarter',
      'field_foobar[2][interval]' => 1,
      'name[0][value]' => 'Barfoo',
      'user_id[0][target_id]' => 'foo (' . $this->adminUser->id() . ')',
    );

    $this->drupalPostForm(NULL, $edit, t('Save'));
    $this->resetAll();
    $entities = \Drupal::entityTypeManager()->getStorage('entity_test')->loadByProperties(array(
      'name' => 'Barfoo',
    ));
    $this->assertEqual(1, count($entities), 'Entity was saved');
    $entity = reset($entities);
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertText('Barfoo');
    $this->assertText('1 Week');
    $this->assertText('3 Days');
    $this->assertText('1 Quarter');

    // Change the formatter to raw.
    EntityViewDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', array(
        'label' => 'hidden',
        'type' => 'interval_raw',
        'weight' => 20,
      ))
      ->save();
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertText('1 Week');
    $this->assertText('3 Days');
    $this->assertText('1 Quarter');

    // Change the formatter to php.
    EntityViewDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', array(
        'label' => 'hidden',
        'type' => 'interval_php',
        'weight' => 20,
      ))
      ->save();
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertText('7 days');
    $this->assertText('3 days');
    $this->assertText('3 months');

    $this->drupalGet('entity_test/manage/' . $entity->id() . '/edit');
    $edit = array(
      'name[0][value]' => 'Bazbar',
      // Remove one child.
      'field_foobar[2][interval]' => '',
    );
    $this->drupalPostForm(NULL, $edit, t('Save'));
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertText('Bazbar');
    // Reload entity.
    \Drupal::entityTypeManager()->getStorage('entity_test')->resetCache(array($entity->id()));
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->load($entity->id());
    $this->assertEqual(count($entity->field_foobar), 2, 'Two values in field');
  }

}
