<?php

namespace Guzzle\Tests\Plugin\Oauth;

use Guzzle\Http\Message\RequestFactory;
use Guzzle\Plugin\Oauth\OauthPlugin;
use Guzzle\Common\Event;

/**
 * @covers Guzzle\Plugin\Oauth\OauthPlugin
 */
class OauthPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    const TIMESTAMP = '1327274290';

    protected $config = array(
        'consumer_key'    => 'foo',
        'consumer_secret' => 'bar',
        'token'           => 'count',
        'token_secret'    => 'dracula'
    );

    protected function getRequest()
    {
        return RequestFactory::getInstance()->create('POST', 'http://www.test.com/path?a=b&c=d', null, array(
            'e' => 'f'
        ));
    }

    public function testSubscribesToEvents()
    {
        $events = OauthPlugin::getSubscribedEvents();
        $this->assertArrayHasKey('request.before_send', $events);
    }

    public function testAcceptsConfigurationData()
    {
        $p = new OauthPlugin($this->config);

        // Access the config object
        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertEquals('foo', $config['consumer_key']);
        $this->assertEquals('bar', $config['consumer_secret']);
        $this->assertEquals('count', $config['token']);
        $this->assertEquals('dracula', $config['token_secret']);
        $this->assertEquals('1.0', $config['version']);
        $this->assertEquals('HMAC-SHA1', $config['signature_method']);
    }

    public function testCreatesStringToSignFromPostRequest()
    {
        $p = new OauthPlugin($this->config);
        $request = $this->getRequest();
        $this->assertContains('&e=f', rawurldecode($p->getStringToSign($request, self::TIMESTAMP)));
        $this->assertEquals(
            // Method and URL
            'POST&http%3A%2F%2Fwww.test.com%2Fpath' .
            // Sorted parameters from query string and body
            '&a%3Db%26c%3Dd%26e%3Df%26oauth_consumer_key%3Dfoo' .
            '%26oauth_nonce%3D22c3b010c30c17043c3d2dd3a7aa3ae6c5549b32%26' .
            'oauth_signature_method%3DHMAC-SHA1' .
            '%26oauth_timestamp%3D' . self::TIMESTAMP . '%26oauth_token%3Dcount%26oauth_version%3D1.0',
            $p->getStringToSign($request, self::TIMESTAMP)
        );
    }

    public function testCreatesStringToSignIgnoringPostFields()
    {
        $config = $this->config;
        $config['disable_post_params'] = true;
        $p = new OauthPlugin($config);
        $request = $this->getRequest();
        $sts = rawurldecode($p->getStringToSign($request, self::TIMESTAMP));
        $this->assertNotContains('&e=f', $sts);
    }

    public function testCreatesStringToSignFromPostRequestWithCustomContentType()
    {
        $p = new OauthPlugin($this->config);
        $request = $this->getRequest();
        $request->setHeader('Content-Type', 'Foo');
        $this->assertEquals(
            // Method and URL
            'POST&http%3A%2F%2Fwww.test.com%2Fpath' .
            // Sorted parameters from query string and body
            '&a%3Db%26c%3Dd%26oauth_consumer_key%3Dfoo' .
            '%26oauth_nonce%3D22c3b010c30c17043c3d2dd3a7aa3ae6c5549b32%26' .
            'oauth_signature_method%3DHMAC-SHA1' .
            '%26oauth_timestamp%3D' . self::TIMESTAMP . '%26oauth_token%3Dcount%26oauth_version%3D1.0',
            $p->getStringToSign($request, self::TIMESTAMP)
        );
    }

    /**
     * @depends testCreatesStringToSignFromPostRequest
     */
    public function testConvertsBooleansToStrings()
    {
        $p = new OauthPlugin($this->config);
        $request = $this->getRequest();
        $request->getQuery()->set('a', true);
        $request->getQuery()->set('c', false);
        $this->assertContains('&a%3Dtrue%26c%3Dfalse', $p->getStringToSign($request, self::TIMESTAMP));
    }

    /**
     * @depends testCreatesStringToSignFromPostRequest
     */
    public function testSignsStrings()
    {
        $p = new OauthPlugin(array_merge($this->config, array(
            'signature_callback' => function($string, $key) {
                return "_{$string}|{$key}_";
            }
        )));
        $request = $this->getRequest();
        $sig = $p->getSignature($request, self::TIMESTAMP);
        $this->assertEquals(
            '_POST&http%3A%2F%2Fwww.test.com%2Fpath&a%3Db%26c%3Dd%26e%3Df%26oauth_consumer_key%3Dfoo' .
            '%26oauth_nonce%3D22c3b010c30c17043c3d2dd3a7aa3ae6c5549b32%26oauth_signature_method%3DHMAC-SHA1' .
            '%26oauth_timestamp%3D' . self::TIMESTAMP . '%26oauth_token%3Dcount%26oauth_version%3D1.0|' .
            'bar&dracula_',
            base64_decode($sig)
        );
    }

    public function testSignsOauthRequests()
    {
        $p = new OauthPlugin($this->config);
        $event = new Event(array(
            'request' => $this->getRequest(),
            'timestamp' => self::TIMESTAMP
        ));
        $p->onRequestBeforeSend($event);

        $this->assertTrue($event['request']->hasHeader('Authorization'));
        $this->assertEquals('OAuth oauth_consumer_key="foo", '
            . 'oauth_nonce="22c3b010c30c17043c3d2dd3a7aa3ae6c5549b32", '
            . 'oauth_signature="BqUAsVHc1cYJ3FA9%2BtLMkJnizJk%3D", '
            . 'oauth_signature_method="HMAC-SHA1", '
            . 'oauth_timestamp="' . self::TIMESTAMP . '", '
            . 'oauth_token="count", '
            . 'oauth_version="1.0"',
            (string) $event['request']->getHeader('Authorization')
        );
    }

    public function testGeneratesUniqueNonce()
    {
        $p = new OauthPlugin($this->config);
        $method = new \ReflectionMethod('Guzzle\Plugin\Oauth\OauthPlugin', 'generateNonce');
        $method->setAccessible(true);
        $request = RequestFactory::getInstance()->create('GET', 'http://www.example.com');
        $result = $method->invoke($p, $request, 1335936584);
        $this->assertEquals('29f72fa5fc2893972060b28a0df8623c41cbb5d2', $result);
    }

    public function testDoesNotAddFalseyValuesToAuthorization()
    {
        unset($this->config['token']);
        $p = new OauthPlugin($this->config);
        $event = new Event(array('request' => $this->getRequest(), 'timestamp' => self::TIMESTAMP));
        $p->onRequestBeforeSend($event);
        $this->assertTrue($event['request']->hasHeader('Authorization'));
        $this->assertNotContains('oauth_token=', (string) $event['request']->getHeader('Authorization'));
    }
}
