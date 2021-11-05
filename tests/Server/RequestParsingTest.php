<?php

declare(strict_types=1);

namespace GraphQL\Tests\Server;

use GraphQL\Error\InvariantViolation;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use InvalidArgumentException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;

use function http_build_query;
use function json_encode;

class RequestParsingTest extends TestCase
{
    public function testParsesGraphqlRequest(): void
    {
        $query  = '{my query}';
        $parsed = [
            'raw' => $this->parseRawRequest('application/graphql', $query),
            'psr' => $this->parsePsrRequest('application/graphql', $query),
        ];

        foreach ($parsed as $source => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, null, null, null, $source);
            self::assertFalse($parsedBody->isReadOnly(), $source);
        }
    }

    /**
     * @param string $contentType
     * @param string $content
     *
     * @return OperationParams|OperationParams[]
     */
    private function parseRawRequest($contentType, $content, string $method = 'POST')
    {
        $_SERVER['CONTENT_TYPE']   = $contentType;
        $_SERVER['REQUEST_METHOD'] = $method;

        $helper = new Helper();

        return $helper->parseHttpRequest(static function () use ($content): string {
            return $content;
        });
    }

    /**
     * @param string $contentType
     * @param string $content
     *
     * @return OperationParams|OperationParams[]
     */
    private function parsePsrRequest($contentType, $content, string $method = 'POST')
    {
        $psrRequest = new Request(
            $method,
            '',
            ['Content-Type' => $contentType],
            Stream::create($content)
        );

        $helper = new Helper();

        return $helper->parsePsrRequest($psrRequest);
    }

    /**
     * @param OperationParams $params
     * @param string          $query
     * @param string          $queryId
     * @param mixed|null      $variables
     * @param string          $operation
     */
    private static function assertValidOperationParams(
        $params,
        $query,
        $queryId = null,
        $variables = null,
        $operation = null,
        $extensions = null,
        $message = ''
    ): void {
        self::assertInstanceOf(OperationParams::class, $params, $message);

        self::assertSame($query, $params->query, $message);
        self::assertSame($queryId, $params->queryId, $message);
        self::assertSame($variables, $params->variables, $message);
        self::assertSame($operation, $params->operation, $message);
        self::assertSame($extensions, $params->extensions, $message);
    }

    public function testParsesUrlencodedRequest(): void
    {
        $query     = '{my query}';
        $variables = ['test' => '1', 'test2' => '2'];
        $operation = 'op';

        $post   = [
            'query'         => $query,
            'variables'     => $variables,
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawFormUrlencodedRequest($post),
            'psr' => $this->parsePsrFormUrlEncodedRequest($post),
            'serverRequest' => $this->parsePsrFormUrlEncodedServerRequest($post),
        ];

        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, $variables, $operation, null, $method);
            self::assertFalse($parsedBody->isReadOnly(), $method);
        }
    }

    /**
     * @param mixed[] $postValue
     *
     * @return OperationParams|OperationParams[]
     */
    private function parseRawFormUrlencodedRequest($postValue)
    {
        $_SERVER['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = $postValue;

        $helper = new Helper();

        return $helper->parseHttpRequest(static function (): void {
            throw new InvariantViolation("Shouldn't read from php://input for urlencoded request");
        });
    }

    /**
     * @param mixed[] $postValue
     *
     * @return OperationParams[]|OperationParams
     */
    private function parsePsrFormUrlEncodedRequest($postValue)
    {
        $helper = new Helper();

        return $helper->parsePsrRequest(
            new Request(
                'POST',
                '',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query($postValue)
            )
        );
    }

    private function parsePsrFormUrlEncodedServerRequest($postValue)
    {
        $helper = new Helper();

        return $helper->parsePsrRequest(
            (new ServerRequest(
                'POST',
                '',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
            ))->withParsedBody($postValue)
        );
    }

    public function testParsesGetRequest(): void
    {
        $query     = '{my query}';
        $variables = ['test' => '1', 'test2' => '2'];
        $operation = 'op';

        $get    = [
            'query'         => $query,
            'variables'     => $variables,
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawGetRequest($get),
            'psr' => $this->parsePsrGetRequest($get),
        ];

        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, $variables, $operation, null, $method);
            self::assertTrue($parsedBody->isReadOnly(), $method);
        }
    }

    /**
     * @param mixed[] $getValue
     */
    private function parseRawGetRequest($getValue): OperationParams
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET                      = $getValue;

        $helper = new Helper();

        return $helper->parseHttpRequest(static function (): void {
            throw new InvariantViolation("Shouldn't read from php://input for urlencoded request");
        });
    }

    /**
     * @param mixed[] $getValue
     *
     * @return OperationParams[]|OperationParams
     */
    private function parsePsrGetRequest(array $getValue)
    {
        $helper = new Helper();

        return $helper->parsePsrRequest(
            new Request('GET', (new Uri())->withQuery(http_build_query($getValue)))
        );
    }

    public function testParsesMultipartFormdataRequest(): void
    {
        $query     = '{my query}';
        $variables = ['test' => '1', 'test2' => '2'];
        $operation = 'op';

        $post   = [
            'query'         => $query,
            'variables'     => $variables,
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawMultipartFormDataRequest($post),
            'psr' => $this->parsePsrMultipartFormDataRequest($post),
        ];

        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, $variables, $operation, null, $method);
            self::assertFalse($parsedBody->isReadOnly(), $method);
        }
    }

    /**
     * @param mixed[] $postValue
     *
     * @return OperationParams|OperationParams[]
     */
    private function parseRawMultipartFormDataRequest($postValue)
    {
        $_SERVER['CONTENT_TYPE']   = 'multipart/form-data; boundary=----FormBoundary';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = $postValue;

        $helper = new Helper();

        return $helper->parseHttpRequest(static function (): void {
            throw new InvariantViolation("Shouldn't read from php://input for multipart/form-data request");
        });
    }

    /**
     * @param mixed[] $postValue
     *
     * @return OperationParams|OperationParams[]
     */
    private function parsePsrMultipartFormDataRequest($postValue)
    {
        $helper = new Helper();

        return $helper->parsePsrRequest(
            new Request(
                'POST',
                '',
                ['Content-Type' => 'multipart/form-data; boundary=----FormBoundary'],
                http_build_query($postValue)
            )
        );
    }

    public function testParsesJSONRequest(): void
    {
        $query     = '{my query}';
        $variables = ['test' => 1, 'test2' => 2];
        $operation = 'op';

        $body   = [
            'query'         => $query,
            'variables'     => $variables,
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawRequest('application/json', json_encode($body)),
            'psr' => $this->parsePsrRequest('application/json', json_encode($body)),
        ];
        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, $variables, $operation, null, $method);
            self::assertFalse($parsedBody->isReadOnly(), $method);
        }
    }

    public function testParsesParamsAsJSON(): void
    {
        $query      = '{my query}';
        $variables  = ['test1' => 1, 'test2' => 2];
        $extensions = ['test3' => 3, 'test4' => 4];
        $operation  = 'op';

        $body   = [
            'query'         => $query,
            'extensions'    => json_encode($extensions),
            'variables'     => json_encode($variables),
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawRequest('application/json', json_encode($body)),
            'psr' => $this->parsePsrRequest('application/json', json_encode($body)),
        ];
        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, $variables, $operation, $extensions, $method);
            self::assertFalse($parsedBody->isReadOnly(), $method);
        }
    }

    public function testIgnoresInvalidVariablesJson(): void
    {
        $query     = '{my query}';
        $variables = '"some invalid json';
        $operation = 'op';

        $body   = [
            'query'         => $query,
            'variables'     => $variables,
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawRequest('application/json', json_encode($body)),
            'psr' => $this->parsePsrRequest('application/json', json_encode($body)),
        ];
        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, $query, null, $variables, $operation, null, $method);
            self::assertFalse($parsedBody->isReadOnly(), $method);
        }
    }

    public function testParsesApolloPersistedQueryJSONRequest(): void
    {
        $queryId    = 'my-query-id';
        $extensions = ['persistedQuery' => ['sha256Hash' => $queryId]];
        $variables  = ['test' => 1, 'test2' => 2];
        $operation  = 'op';

        $body   = [
            'extensions'    => $extensions,
            'variables'     => $variables,
            'operationName' => $operation,
        ];
        $parsed = [
            'raw' => $this->parseRawRequest('application/json', json_encode($body)),
            'psr' => $this->parsePsrRequest('application/json', json_encode($body)),
        ];
        foreach ($parsed as $method => $parsedBody) {
            self::assertValidOperationParams($parsedBody, null, $queryId, $variables, $operation, $extensions, $method);
            self::assertFalse($parsedBody->isReadOnly(), $method);
        }
    }

    public function testParsesBatchJSONRequest(): void
    {
        $body   = [
            [
                'query'         => '{my query}',
                'variables'     => ['test' => 1, 'test2' => 2],
                'operationName' => 'op',
            ],
            [
                'queryId'       => 'my-query-id',
                'variables'     => ['test' => 1, 'test2' => 2],
                'operationName' => 'op2',
            ],
        ];
        $parsed = [
            'raw' => $this->parseRawRequest('application/json', json_encode($body)),
            'psr' => $this->parsePsrRequest('application/json', json_encode($body)),
        ];
        foreach ($parsed as $method => $parsedBody) {
            self::assertIsArray($parsedBody, $method);
            self::assertCount(2, $parsedBody, $method);
            self::assertValidOperationParams(
                $parsedBody[0],
                $body[0]['query'],
                null,
                $body[0]['variables'],
                $body[0]['operationName'],
                null,
                $method
            );
            self::assertValidOperationParams(
                $parsedBody[1],
                null,
                $body[1]['queryId'],
                $body[1]['variables'],
                $body[1]['operationName'],
                null,
                $method
            );
        }
    }

    public function testFailsParsingInvalidRawJsonRequestRaw(): void
    {
        $body = 'not really{} a json';

        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Expected JSON object or array for "application/json" request, but failed to parse because: Syntax error');
        $this->parseRawRequest('application/json', $body);
    }

    public function testFailsParsingInvalidRawJsonRequestPsr(): void
    {
        $body = 'not really{} a json';

        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Expected JSON object or array for "application/json" request, but failed to parse because: Syntax error');
        $this->parsePsrRequest('application/json', $body);
    }

    public function testFailsParsingNonPreParsedPsrRequest(): void
    {
        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Expected JSON object or array for "application/json" request, got: null');
        $this->parsePsrRequest('application/json', json_encode(null));
    }

    public function testFailsParsingInvalidEmptyJsonRequestPsr(): void
    {
        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Expected JSON object or array for "application/json" request, but failed to parse because: Syntax error');
        $this->parsePsrRequest('application/json', '');
    }

    public function testFailsParsingNonArrayOrObjectJsonRequestRaw(): void
    {
        $body = '"str"';

        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Expected JSON object or array for "application/json" request, got: "str"');
        $this->parseRawRequest('application/json', $body);
    }

    public function testFailsParsingNonArrayOrObjectJsonRequestPsr(): void
    {
        $body = '"str"';

        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Expected JSON object or array for "application/json" request, got: "str"');
        $this->parsePsrRequest('application/json', $body);
    }

    public function testFailsParsingInvalidContentTypeRaw(): void
    {
        $contentType = 'not-supported-content-type';
        $body        = 'test';

        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Unexpected content type: "not-supported-content-type"');
        $this->parseRawRequest($contentType, $body);
    }

    public function testFailsParsingInvalidContentTypePsr(): void
    {
        $contentType = 'not-supported-content-type';
        $body        = 'test';

        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Unexpected content type: "not-supported-content-type"');
        $this->parseRawRequest($contentType, $body);
    }

    public function testFailsWithMissingContentTypeRaw(): void
    {
        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('Missing "Content-Type" header');
        $this->parseRawRequest(null, 'test');
    }

    public function testFailsWithMissingContentTypePsr(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parsePsrRequest(null, 'test');
    }

    public function testFailsOnMethodsOtherThanPostOrGetRaw(): void
    {
        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('HTTP Method "PUT" is not supported');
        $this->parseRawRequest('application/json', json_encode([]), 'PUT');
    }

    public function testFailsOnMethodsOtherThanPostOrGetPsr(): void
    {
        $this->expectException(RequestError::class);
        $this->expectExceptionMessage('HTTP Method "PUT" is not supported');
        $this->parsePsrRequest('application/json', json_encode([]), 'PUT');
    }
}
