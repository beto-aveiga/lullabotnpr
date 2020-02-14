<?php

namespace Drupal\Tests\npr_api\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Test the NPR API Settings form.
 *
 * @group npr
 */
class NprApiSettingsTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['npr_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An admin user with permission to administer the NPR API.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

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
   * Test the NPR API Settings form.
   */
  public function testNprApiSettingsForm() {

    // Login as as an admin user.
    $this->drupalLogin($this->adminUser);

    // Make sure the option exists to select a story node.
    $this->drupalGet('admin/config/services/npr/api_config');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('NPR API production URL');
  }

}
