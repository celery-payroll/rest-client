<?php

/**
 * This file is part of sc/rest-client
 *
 * © Konstantin Zamyakin <dev@weblab.pro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sc\RestClient\Client;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Sc\RestClient\AuthenticationProvider\AuthenticationProviderInterface;
use Sc\RestClient\Client\Exception\RequestFailedException;
use Sc\RestClient\Client\Exception\ResourceNotFoundException;
use Sc\RestClient\RequestSigner\RequestSignerInterface;
use Sc\RestClient\ResponseParser\ResponseParserInterface;

/**
 * Class Client.
 */
class Client implements ClientInterface
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';

    const SEND_AS_JSON = 'JSON';
    const SEND_AS_FORM_VALUES = 'FORM';

    /**
     * @var string
     */
    protected $endpoint;

    /** @var RequestSignerInterface */
    protected $request_signer;

    /** @var AuthenticationProviderInterface */
    protected $auth_provider;

    /** @var ResponseParserInterface */
    protected $response_parser;

    /** @var string */
    protected $send_body_as;

    /**
     * @param $endpoint
     * @param ResponseParserInterface $responseParser
     */
    public function __construct($endpoint, ResponseParserInterface $responseParser)
    {
        $this->endpoint = rtrim($endpoint, '/').'/';
        $this->response_parser = $responseParser;
        $this->send_body_as = static::SEND_AS_FORM_VALUES;
    }

    /**
     * @param RequestSignerInterface $signer
     */
    public function useRequestSigner(RequestSignerInterface $signer)
    {
        $this->request_signer = $signer;
    }

    /**
     * @param AuthenticationProviderInterface $auth_provider
     */
    public function useAuthenticator(AuthenticationProviderInterface $auth_provider)
    {
        $this->auth_provider = $auth_provider;
    }

    /**
     * @param string $sendAsType
     */
    public function setBodyType($sendAsType)
    {
        $this->send_body_as = $sendAsType;
    }

    /**
     * @param $resource
     * @param $id
     *
     * @return array
     *
     * @throws ResourceNotFoundException
     */
    public function get($resource, $id)
    {
        try {
            $response = $this->makeRequest($resource.'/'.$id, self::METHOD_GET);
        } catch (ClientException $e) {
            throw self::createNotFoundException($resource, $id, $e);
        }

        return $this->response_parser->parseResponse($response);
    }

    /**
     * @param $resource
     *
     * @return array
     */
    public function getAll($resource, array $parameters = [])
    {
        $response = $this->makeRequest($resource.'/', self::METHOD_GET, [], $parameters);

        return $this->response_parser->parseResponse($response);
    }

    /**
     * @param $resource
     * @param array $data
     *
     * @return bool
     */
    public function create($resource, array $data)
    {
        $response = $this->makeRequest($resource.'/', self::METHOD_POST, $data);

        if (trim((string) $response->getBody())) {
            return $this->response_parser->parseResponse($response);
        }

        if ($response->hasHeader('Location')) {
            return $this->handleLocation($response->getHeaderLine('Location'));
        }
    }

    /**
     * @param $resource
     * @param $id
     * @param array      $data
     * @param bool|false $partial_update
     *
     * @return array
     *
     * @throws ResourceNotFoundException
     */
    public function update($resource, $id, array $data, $partial_update = false)
    {
        $method = $partial_update ? self::METHOD_PATCH : self::METHOD_PUT;

        try {
            $response = $this->makeRequest($resource.'/'.$id, $method, $data);

            if ($response->hasHeader('Location')) {
                return $this->handleLocation($response->getHeaderLine('Location'));
            } else {
                return $this->response_parser->parseResponse($response);
            }
        } catch (ClientException $e) {
            throw self::createNotFoundException($resource, $id, $e);
        }
    }

    /**
     * @param $resource
     * @param $id
     *
     * @return bool
     *
     * @throws ResourceNotFoundException
     */
    public function delete($resource, $id)
    {
        try {
            $response = $this->makeRequest($resource.'/'.$id, self::METHOD_DELETE);
        } catch (ClientException $e) {
            throw self::createNotFoundException($resource, $id, $e);
        }

        return ($response->getStatusCode() === 204);
    }

    /**
     * @param $path
     * @param $method
     * @param array $data
     * @param array $parameters
     *
     * @return ResponseInterface
     *
     * @throws RequestFailedException
     */
    protected function makeRequest($path, $method, array $data = [], array $parameters = [])
    {
        $uri = $this->endpoint.$path;

        if ($parameters) {
            $uri .= '?'.http_build_query($parameters);
        }

        $http_client = $this->getHttpClient();

        $request = $this->getRequest($method, $uri, $data);

        if ($this->request_signer) {
            $request = $this->request_signer->signRequest($request);
        }

        if ($this->auth_provider) {
            $request = $this->auth_provider->addAuthentificationInfo($request);
        }

        try {
            return $http_client->send($request);
        } catch (ServerException $e) {
            throw new RequestFailedException(sprintf('Request %s %s failed', $method, $path), $e->getCode(), $e);
        }
    }

    protected static function createNotFoundException($resource, $identificator, ClientException $prev)
    {
        return new ResourceNotFoundException(sprintf('Resource [%s/%s] not found', $resource, $identificator), 404, $prev);
    }

    /**
     * Handle 'Location' header with URI of created/updated resource.
     *
     * @param $location
     *
     * @return array
     */
    protected function handleLocation($location)
    {
        $uri = new Uri($location);
        $path = trim($uri->getPath(), '/');

        $response = $this->makeRequest($path, self::METHOD_GET);

        return $this->response_parser->parseResponse($response);
    }

    /**
     * @param $method
     * @param $uri
     * @param array $data
     *
     * @return Request
     */
    protected function getRequest($method, $uri, array $data = [])
    {
        switch ($this->send_body_as) {
            case static::SEND_AS_JSON:
                $headers = [
                    'Content-Type' => 'application/json',
                ];

                $strData = json_encode($data);

                break;
            case static::SEND_AS_FORM_VALUES:
            default:
                $headers = [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ];

                $strData = http_build_query($data, null, '&');

                break;
        }

        return new Request($method, $uri, $headers, $strData);
    }

    /**
     * @return HttpClient
     */
    protected function getHttpClient()
    {
        return new HttpClient();
    }
}
