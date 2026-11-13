<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_frontend_editing\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests access to the describe and token endpoints over HTTP.
 */
#[Group('headless_frontend_editing')]
#[RunTestsInSeparateProcesses]
final class ProtocolTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'headless_frontend_editing'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the route requirements.
   */
  public function testRouteRequirements(): void {
    $provider = $this->container->get('router.route_provider');
    $describe = $provider->getRouteByName('headless_frontend_editing.describe');
    $this->assertSame('TRUE', $describe->getRequirement('_csrf_request_header_token'));
    $this->assertSame(['POST'], $describe->getMethods());
    $this->assertSame('use headless frontend editing', $describe->getRequirement('_permission'));
  }

  /**
   * Tests that anyone can load the client script, revalidated by ETag.
   */
  public function testClientScript(): void {
    $this->drupalGet('headless-frontend-editing/client.js');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'text/javascript; charset=utf-8');
    $this->assertSession()->responseHeaderEquals('Cache-Control', 'max-age=300, public');
    $this->assertSession()->responseContains("'hfe:ready'");
    $etag = $this->getSession()->getResponseHeader('ETag');
    $this->assertNotEmpty($etag);

    $this->getSession()->setRequestHeader('If-None-Match', $etag);
    $this->drupalGet('headless-frontend-editing/client.js');
    $this->assertSession()->statusCodeEquals(304);
    $this->assertSame('', $this->getSession()->getPage()->getContent());

    $this->getSession()->setRequestHeader('If-None-Match', '"stale"');
    $this->drupalGet('headless-frontend-editing/client.js');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that anonymous users get no token.
   */
  public function testTokenAnonymous(): void {
    $this->drupalGet('headless-frontend-editing/token');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the token and describe endpoints as an editor.
   */
  public function testEditor(): void {
    $this->drupalLogin($this->drupalCreateUser(['use headless frontend editing']));
    $this->drupalGet('headless-frontend-editing/token');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertNotEmpty(json_decode($this->getSession()->getPage()->getContent(), TRUE)['token']);

    $url = $this->buildUrl('headless-frontend-editing/describe');
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof BrowserKitDriver);
    $client = $driver->getClient();
    $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{"targets":[]}');
    $this->assertSame(403, $client->getInternalResponse()->getStatusCode());

    $csrf = $this->drupalGet('session/token');
    $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], '{"targets":[]}');
    $this->assertSame(200, $client->getInternalResponse()->getStatusCode());
    $this->assertSame(['targets' => []], json_decode($client->getInternalResponse()->getContent(), TRUE));
  }

}
