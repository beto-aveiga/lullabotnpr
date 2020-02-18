<?php

namespace Drupal\Tests\npr_api\Functional;

use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;

/**
 * Test the NprClient.
 *
 * @group npr
 */
class NprClientTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Create a mock and queue two responses.
    // TODO: This is just dummy code for now.
    $mock = new MockHandler([
      new Response(200, ['X-Foo' => 'Bar'], 'Hello, World'),
      new Response(202, ['Content-Length' => 0]),
      new RequestException('Error Communicating with Server', new Request('GET', 'test')),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->client = new Client(['handler' => $handlerStack]);
  }

  /**
   * Test NprClient functionality.
   */
  public function testNprClient() {

    /** @var \Drupal\npr_api\NprClient $client */
    $client = \Drupal::service('npr_api.client');
    $this->assertNotNull($client);

  }

}
