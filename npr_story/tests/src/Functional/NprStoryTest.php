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
  public static $modules = ['npr_api', 'npr_story', 'media', 'node'];

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

    // Use article as a node to test that nodes can be configured.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    $this->adminUser = $this->drupalCreateUser([
      'administer npr api',
    ]);
  }

  /**
   * Test npr_story functionality.
   */
  public function testNprStory() {

    $story_config_path = 'admin/config/services/npr/story_config';

    // Login as as an admin user.
    $this->drupalLogin($this->adminUser);

    // Check for the message that appears when no story node type is selected.
    $this->drupalGet($story_config_path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Select and save Drupal story node type to choose field mappings');

    // Check for dropdown messages when a content type is selected.
    $edit = [
      'story_node_type' => 'article',
    ];
    $this->drupalPostForm($story_config_path, $edit, 'Save configuration');
    $this->assertSession()->pageTextContains('id');
    $this->assertSession()->pageTextContains('body');
    $this->assertSession()->pageTextContains('byline');

  }

}
