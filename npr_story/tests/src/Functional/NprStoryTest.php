<?php

namespace Drupal\Tests\npr_story\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Test the NPR story functionality.
 *
 * @group npr
 */
class NprStoryTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['npr_api', 'npr_story'];

  /**
   * An admin user with permission to administer the NPR API.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'administer npr api',
    ]);

  }

  /**
   * Test npr_story functionality.
   */
  public function testNprStory() {

    // Login as as an admin user.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/services/npr/story_config');

  }

}
