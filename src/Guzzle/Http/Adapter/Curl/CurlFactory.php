<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * Creates curl resources from a request and response object
 */
class CurlFactory
{
    /** @var self */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    public function createHandle(RequestInterface $request, ResponseInterface $response)
    {
        $options = $this->getDefaultOptions($request, $response);
        $this->applyMethod($request, $options);
        $this->applyTransferOptions($request, $options);
        $this->applyHeaders($request, $options);
        $handle = curl_init();
        unset($options['_headers']);
        curl_setopt_array($handle, $options);

        return $handle;
    }

    protected function getDefaultOptions(RequestInterface $request, ResponseInterface $response)
    {
        $transfer = $request->getTransferOptions();
        $mediator = new RequestMediator($request, $response);
        $options = array(
            CURLOPT_URL            => $request->getUrl(),
            CURLOPT_CONNECTTIMEOUT => $transfer['connect_timeout'] ?: 150,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_PORT           => $request->getPort(),
            CURLOPT_WRITEFUNCTION  => array($mediator, 'writeResponseBody'),
            CURLOPT_HEADERFUNCTION => array($mediator, 'receiveResponseHeader'),
            CURLOPT_READFUNCTION   => array($mediator, 'readRequestBody'),
            CURLOPT_HTTP_VERSION   => $request->getProtocolVersion() === '1.0'
                ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
            '_headers'             => clone $request->getHeaders()
        );

        if (defined('CURLOPT_PROTOCOLS')) {
            // Allow only HTTP and HTTPS protocols
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        // Add CURLOPT_ENCODING if Accept-Encoding header is provided
        if ($acceptEncodingHeader = $request->getHeader('Accept-Encoding')) {
            $options[CURLOPT_ENCODING] = (string) $acceptEncodingHeader;
            // Let cURL set the Accept-Encoding header, prevents duplicate values
            $request->removeHeader('Accept-Encoding');
        }

        return $options;
    }

    protected function applyMethod(RequestInterface $request, array &$options)
    {
        $method = $request->getMethod();
        if ($method == 'GET') {
            $options[CURLOPT_HTTPGET] = true;
            unset($options[CURLOPT_READFUNCTION]);
        } elseif ($method == 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset($options[CURLOPT_WRITEFUNCTION]);
            unset($options[CURLOPT_READFUNCTION]);
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($request->getBody()) {
                $this->applyBody($request, $options);
                // If the Expect header is not present, prevent curl from adding it
                if (!$request->hasHeader('Expect')) {
                    $options[CURLOPT_HTTPHEADER][] = 'Expect:';
                }
            }
        }
    }

    protected function applyBody(RequestInterface $request, array &$options)
    {
        // You can send the body as a string using curl's CURLOPT_POSTFIELDS
        if (isset($request->getTransferOptions()['adapter']['body_as_string'])) {
            $options[CURLOPT_POSTFIELDS] = (string) $request->getBody();
            // Don't duplicate the Content-Length header
            unset($options['_headers']['Content-Length']);
            unset($options['_headers']['Transfer-Encoding']);
        } else {
            $options[CURLOPT_UPLOAD] = true;
            // Let cURL handle setting the Content-Length header
            if ($len = $request->getHeader('Content-Length')) {
                $options[CURLOPT_INFILESIZE] = (int) (string) $len;
                unset($options['_headers']['Content-Length']);
            }
            $request->getBody()->seek(0);
        }
    }

    protected function applyHeaders(RequestInterface $request, array &$options)
    {
        foreach ($options['_headers'] as $key => $value) {
            $options[CURLOPT_HTTPHEADER][] = $key . ': ' . $value;
        }
    }

    protected function applyTransferOptions(RequestInterface $request, array &$options)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        foreach ($request->getTransferOptions()->toArray() as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $options, $value);
            }
        }
    }

    protected function visit_proxy(RequestInterface $request, &$options, $value)
    {
        $options[CURLOPT_PROXY] = $value;
    }

    protected function visit_timeout(RequestInterface $request, &$options, $value)
    {
        $options[CURLOPT_TIMEOUT_MS] = $value * 1000;
    }

    protected function visit_connect_timeout(RequestInterface $request, &$options, $value)
    {
        $options[CURLOPT_CONNECTTIMEOUT_MS] = $value * 1000;
    }

    protected function visit_verify(RequestInterface $request, &$options, $value)
    {
        if ($value === true || is_string($value)) {
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            if ($value !== true) {
                $options[CURLOPT_CAINFO] = $value;
            }
        } elseif ($value === false) {
            unset($options[CURLOPT_CAINFO]);
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            $options[CURLOPT_SSL_VERIFYPEER] = false;
        }
    }

    protected function visit_cert(RequestInterface $request, &$options, $value)
    {
        if (is_array($value)) {
            $options[CURLOPT_SSLCERT] = $value[0];
            $options[CURLOPT_SSLCERTPASSWD] = $value[1];
        } else {
            $options[CURLOPT_SSLCERT] = $value;
        }
    }

    protected function visit_ssl_key(RequestInterface $request, &$options, $value)
    {
        if (is_array($value)) {
            $options[CURLOPT_SSLKEY] = $value[0];
            $options[CURLOPT_SSLKEYPASSWD] = $value[1];
        } else {
            $options[CURLOPT_SSLKEY] = $value;
        }
    }

    protected function visit_auth(RequestInterface $request, &$options, $value)
    {
        static $authMap = array(
            'basic'  => CURLAUTH_BASIC,
            'digest' => CURLAUTH_DIGEST,
            'ntlm'   => CURLAUTH_NTLM,
            'any'    => CURLAUTH_ANY
        );

        $scheme = isset($value[2]) ? strtolower($value[2]) : 'basic';
        if (!isset($authMap[$scheme])) {
            throw new \InvalidArgumentException('Invalud authentication scheme: ' . $scheme);
        }

        $scheme = $authMap[$scheme];
        $options[CURLOPT_HTTPAUTH] = $scheme;
        $options[CURLOPT_USERPWD] = $value[0] . ':' . $value[1];
    }
}