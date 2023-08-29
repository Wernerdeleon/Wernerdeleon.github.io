<?php

namespace Staatic\Vendor\GuzzleHttp\Handler;

use InvalidArgumentException;
use RuntimeException;
use Exception;
use Staatic\Vendor\GuzzleHttp\Exception\ConnectException;
use Staatic\Vendor\GuzzleHttp\Exception\RequestException;
use Staatic\Vendor\GuzzleHttp\Promise as P;
use Staatic\Vendor\GuzzleHttp\Promise\FulfilledPromise;
use Staatic\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Staatic\Vendor\GuzzleHttp\Psr7\LazyOpenStream;
use Staatic\Vendor\GuzzleHttp\TransferStats;
use Staatic\Vendor\GuzzleHttp\Utils;
use Staatic\Vendor\Psr\Http\Message\RequestInterface;
class CurlFactory implements CurlFactoryInterface
{
    public const CURL_VERSION_STR = 'curl_version';
    public const LOW_CURL_VERSION_NUMBER = '7.21.2';
    private $handles = [];
    private $maxHandles;
    public function __construct(int $maxHandles)
    {
        $this->maxHandles = $maxHandles;
    }
    /**
     * @param RequestInterface $request
     * @param mixed[] $options
     */
    public function create($request, $options) : EasyHandle
    {
        if (isset($options['curl']['body_as_string'])) {
            $options['_body_as_string'] = $options['curl']['body_as_string'];
            unset($options['curl']['body_as_string']);
        }
        $easy = new EasyHandle();
        $easy->request = $request;
        $easy->options = $options;
        $conf = $this->getDefaultConf($easy);
        $this->applyMethod($easy, $conf);
        $this->applyHandlerOptions($easy, $conf);
        $this->applyHeaders($easy, $conf);
        unset($conf['_headers']);
        if (isset($options['curl'])) {
            $conf = \array_replace($conf, $options['curl']);
        }
        $conf[\CURLOPT_HEADERFUNCTION] = $this->createHeaderFn($easy);
        $easy->handle = $this->handles ? \array_pop($this->handles) : \curl_init();
        \curl_setopt_array($easy->handle, $conf);
        return $easy;
    }
    /**
     * @param EasyHandle $easy
     */
    public function release($easy) : void
    {
        $resource = $easy->handle;
        unset($easy->handle);
        if (\count($this->handles) >= $this->maxHandles) {
            \curl_close($resource);
        } else {
            \curl_setopt($resource, \CURLOPT_HEADERFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_READFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_WRITEFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_PROGRESSFUNCTION, null);
            \curl_reset($resource);
            $this->handles[] = $resource;
        }
    }
    /**
     * @param callable $handler
     * @param EasyHandle $easy
     * @param CurlFactoryInterface $factory
     */
    public static function finish($handler, $easy, $factory) : PromiseInterface
    {
        if (isset($easy->options['on_stats'])) {
            self::invokeStats($easy);
        }
        if (!$easy->response || $easy->errno) {
            return self::finishError($handler, $easy, $factory);
        }
        $factory->release($easy);
        $body = $easy->response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return new FulfilledPromise($easy->response);
    }
    private static function invokeStats(EasyHandle $easy) : void
    {
        $curlStats = \curl_getinfo($easy->handle);
        $curlStats['appconnect_time'] = \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME);
        $stats = new TransferStats($easy->request, $easy->response, $curlStats['total_time'], $easy->errno, $curlStats);
        $easy->options['on_stats']($stats);
    }
    private static function finishError(callable $handler, EasyHandle $easy, CurlFactoryInterface $factory) : PromiseInterface
    {
        $ctx = ['errno' => $easy->errno, 'error' => \curl_error($easy->handle), 'appconnect_time' => \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME)] + \curl_getinfo($easy->handle);
        $ctx[self::CURL_VERSION_STR] = \curl_version()['version'];
        $factory->release($easy);
        if (empty($easy->options['_err_message']) && (!$easy->errno || $easy->errno == 65)) {
            return self::retryFailedRewind($handler, $easy, $ctx);
        }
        return self::createRejection($easy, $ctx);
    }
    private static function createRejection(EasyHandle $easy, array $ctx) : PromiseInterface
    {
        static $connectionErrors = [\CURLE_OPERATION_TIMEOUTED => \true, \CURLE_COULDNT_RESOLVE_HOST => \true, \CURLE_COULDNT_CONNECT => \true, \CURLE_SSL_CONNECT_ERROR => \true, \CURLE_GOT_NOTHING => \true];
        if ($easy->createResponseException) {
            return P\Create::rejectionFor(new RequestException('An error was encountered while creating the response', $easy->request, $easy->response, $easy->createResponseException, $ctx));
        }
        if ($easy->onHeadersException) {
            return P\Create::rejectionFor(new RequestException('An error was encountered during the on_headers event', $easy->request, $easy->response, $easy->onHeadersException, $ctx));
        }
        $message = \sprintf('cURL error %s: %s (%s)', $ctx['errno'], $ctx['error'], 'see https://curl.haxx.se/libcurl/c/libcurl-errors.html');
        $uriString = (string) $easy->request->getUri();
        if ($uriString !== '' && \false === \strpos($ctx['error'], $uriString)) {
            $message .= \sprintf(' for %s', $uriString);
        }
        $error = isset($connectionErrors[$easy->errno]) ? new ConnectException($message, $easy->request, null, $ctx) : new RequestException($message, $easy->request, $easy->response, null, $ctx);
        return P\Create::rejectionFor($error);
    }
    private function getDefaultConf(EasyHandle $easy) : array
    {
        $conf = ['_headers' => $easy->request->getHeaders(), \CURLOPT_CUSTOMREQUEST => $easy->request->getMethod(), \CURLOPT_URL => (string) $easy->request->getUri()->withFragment(''), \CURLOPT_RETURNTRANSFER => \false, \CURLOPT_HEADER => \false, \CURLOPT_CONNECTTIMEOUT => 300];
        if (\defined('CURLOPT_PROTOCOLS')) {
            $conf[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
        }
        $version = $easy->request->getProtocolVersion();
        if ($version == 1.1) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_1;
        } elseif ($version == 2.0) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_2_0;
        } else {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_0;
        }
        return $conf;
    }
    private function applyMethod(EasyHandle $easy, array &$conf) : void
    {
        $body = $easy->request->getBody();
        $size = $body->getSize();
        if ($size === null || $size > 0) {
            $this->applyBody($easy->request, $easy->options, $conf);
            return;
        }
        $method = $easy->request->getMethod();
        if ($method === 'PUT' || $method === 'POST') {
            if (!$easy->request->hasHeader('Content-Length')) {
                $conf[\CURLOPT_HTTPHEADER][] = 'Content-Length: 0';
            }
        } elseif ($method === 'HEAD') {
            $conf[\CURLOPT_NOBODY] = \true;
            unset($conf[\CURLOPT_WRITEFUNCTION], $conf[\CURLOPT_READFUNCTION], $conf[\CURLOPT_FILE], $conf[\CURLOPT_INFILE]);
        }
    }
    private function applyBody(RequestInterface $request, array $options, array &$conf) : void
    {
        $size = $request->hasHeader('Content-Length') ? (int) $request->getHeaderLine('Content-Length') : null;
        if ($size !== null && $size < 1000000 || !empty($options['_body_as_string'])) {
            $conf[\CURLOPT_POSTFIELDS] = (string) $request->getBody();
            $this->removeHeader('Content-Length', $conf);
            $this->removeHeader('Transfer-Encoding', $conf);
        } else {
            $conf[\CURLOPT_UPLOAD] = \true;
            if ($size !== null) {
                $conf[\CURLOPT_INFILESIZE] = $size;
                $this->removeHeader('Content-Length', $conf);
            }
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $conf[\CURLOPT_READFUNCTION] = static function ($ch, $fd, $length) use($body) {
                return $body->read($length);
            };
        }
        if (!$request->hasHeader('Expect')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Expect:';
        }
        if (!$request->hasHeader('Content-Type')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Content-Type:';
        }
    }
    private function applyHeaders(EasyHandle $easy, array &$conf) : void
    {
        foreach ($conf['_headers'] as $name => $values) {
            foreach ($values as $value) {
                $value = (string) $value;
                if ($value === '') {
                    $conf[\CURLOPT_HTTPHEADER][] = "{$name};";
                } else {
                    $conf[\CURLOPT_HTTPHEADER][] = "{$name}: {$value}";
                }
            }
        }
        if (!$easy->request->hasHeader('Accept')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Accept:';
        }
    }
    private function removeHeader(string $name, array &$options) : void
    {
        foreach (\array_keys($options['_headers']) as $key) {
            if (!\strcasecmp($key, $name)) {
                unset($options['_headers'][$key]);
                return;
            }
        }
    }
    private function applyHandlerOptions(EasyHandle $easy, array &$conf) : void
    {
        $options = $easy->options;
        if (isset($options['verify'])) {
            if ($options['verify'] === \false) {
                unset($conf[\CURLOPT_CAINFO]);
                $conf[\CURLOPT_SSL_VERIFYHOST] = 0;
                $conf[\CURLOPT_SSL_VERIFYPEER] = \false;
            } else {
                $conf[\CURLOPT_SSL_VERIFYHOST] = 2;
                $conf[\CURLOPT_SSL_VERIFYPEER] = \true;
                if (\is_string($options['verify'])) {
                    if (!\file_exists($options['verify'])) {
                        throw new InvalidArgumentException("SSL CA bundle not found: {$options['verify']}");
                    }
                    if (\is_dir($options['verify']) || \is_link($options['verify']) === \true && ($verifyLink = \readlink($options['verify'])) !== \false && \is_dir($verifyLink)) {
                        $conf[\CURLOPT_CAPATH] = $options['verify'];
                    } else {
                        $conf[\CURLOPT_CAINFO] = $options['verify'];
                    }
                }
            }
        }
        if (!isset($options['curl'][\CURLOPT_ENCODING]) && !empty($options['decode_content'])) {
            $accept = $easy->request->getHeaderLine('Accept-Encoding');
            if ($accept) {
                $conf[\CURLOPT_ENCODING] = $accept;
            } else {
                $conf[\CURLOPT_ENCODING] = '';
                $conf[\CURLOPT_HTTPHEADER][] = 'Accept-Encoding:';
            }
        }
        if (!isset($options['sink'])) {
            $options['sink'] = \Staatic\Vendor\GuzzleHttp\Psr7\Utils::tryFopen('php://temp', 'w+');
        }
        $sink = $options['sink'];
        if (!\is_string($sink)) {
            $sink = \Staatic\Vendor\GuzzleHttp\Psr7\Utils::streamFor($sink);
        } elseif (!\is_dir(\dirname($sink))) {
            throw new RuntimeException(\sprintf('Directory %s does not exist for sink value of %s', \dirname($sink), $sink));
        } else {
            $sink = new LazyOpenStream($sink, 'w+');
        }
        $easy->sink = $sink;
        $conf[\CURLOPT_WRITEFUNCTION] = static function ($ch, $write) use($sink) : int {
            return $sink->write($write);
        };
        $timeoutRequiresNoSignal = \false;
        if (isset($options['timeout'])) {
            $timeoutRequiresNoSignal |= $options['timeout'] < 1;
            $conf[\CURLOPT_TIMEOUT_MS] = $options['timeout'] * 1000;
        }
        if (isset($options['force_ip_resolve'])) {
            if ('v4' === $options['force_ip_resolve']) {
                $conf[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
            } elseif ('v6' === $options['force_ip_resolve']) {
                $conf[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V6;
            }
        }
        if (isset($options['connect_timeout'])) {
            $timeoutRequiresNoSignal |= $options['connect_timeout'] < 1;
            $conf[\CURLOPT_CONNECTTIMEOUT_MS] = $options['connect_timeout'] * 1000;
        }
        if ($timeoutRequiresNoSignal && \strtoupper(\substr(\PHP_OS, 0, 3)) !== 'WIN') {
            $conf[\CURLOPT_NOSIGNAL] = \true;
        }
        if (isset($options['proxy'])) {
            if (!\is_array($options['proxy'])) {
                $conf[\CURLOPT_PROXY] = $options['proxy'];
            } else {
                $scheme = $easy->request->getUri()->getScheme();
                if (isset($options['proxy'][$scheme])) {
                    $host = $easy->request->getUri()->getHost();
                    if (isset($options['proxy']['no']) && Utils::isHostInNoProxy($host, $options['proxy']['no'])) {
                        unset($conf[\CURLOPT_PROXY]);
                    } else {
                        $conf[\CURLOPT_PROXY] = $options['proxy'][$scheme];
                    }
                }
            }
        }
        if (isset($options['crypto_method'])) {
            if (\STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT === $options['crypto_method']) {
                if (!\defined('CURL_SSLVERSION_TLSv1_0')) {
                    throw new InvalidArgumentException('Invalid crypto_method request option: TLS 1.0 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_0;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT === $options['crypto_method']) {
                if (!\defined('CURL_SSLVERSION_TLSv1_1')) {
                    throw new InvalidArgumentException('Invalid crypto_method request option: TLS 1.1 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_1;
            } elseif (\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $options['crypto_method']) {
                if (!\defined('CURL_SSLVERSION_TLSv1_2')) {
                    throw new InvalidArgumentException('Invalid crypto_method request option: TLS 1.2 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
            } elseif (\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT === $options['crypto_method']) {
                if (!\defined('CURL_SSLVERSION_TLSv1_3')) {
                    throw new InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_3;
            } else {
                throw new InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
            }
        }
        if (isset($options['cert'])) {
            $cert = $options['cert'];
            if (\is_array($cert)) {
                $conf[\CURLOPT_SSLCERTPASSWD] = $cert[1];
                $cert = $cert[0];
            }
            if (!\file_exists($cert)) {
                throw new InvalidArgumentException("SSL certificate not found: {$cert}");
            }
            $ext = \pathinfo($cert, \PATHINFO_EXTENSION);
            if (\preg_match('#^(der|p12)$#i', $ext)) {
                $conf[\CURLOPT_SSLCERTTYPE] = \strtoupper($ext);
            }
            $conf[\CURLOPT_SSLCERT] = $cert;
        }
        if (isset($options['ssl_key'])) {
            if (\is_array($options['ssl_key'])) {
                if (\count($options['ssl_key']) === 2) {
                    [$sslKey, $conf[\CURLOPT_SSLKEYPASSWD]] = $options['ssl_key'];
                } else {
                    [$sslKey] = $options['ssl_key'];
                }
            }
            $sslKey = $sslKey ?? $options['ssl_key'];
            if (!\file_exists($sslKey)) {
                throw new InvalidArgumentException("SSL private key not found: {$sslKey}");
            }
            $conf[\CURLOPT_SSLKEY] = $sslKey;
        }
        if (isset($options['progress'])) {
            $progress = $options['progress'];
            if (!\is_callable($progress)) {
                throw new InvalidArgumentException('progress client option must be callable');
            }
            $conf[\CURLOPT_NOPROGRESS] = \false;
            $conf[\CURLOPT_PROGRESSFUNCTION] = static function ($resource, int $downloadSize, int $downloaded, int $uploadSize, int $uploaded) use($progress) {
                $progress($downloadSize, $downloaded, $uploadSize, $uploaded);
            };
        }
        if (!empty($options['debug'])) {
            $conf[\CURLOPT_STDERR] = Utils::debugResource($options['debug']);
            $conf[\CURLOPT_VERBOSE] = \true;
        }
    }
    private static function retryFailedRewind(callable $handler, EasyHandle $easy, array $ctx) : PromiseInterface
    {
        try {
            $body = $easy->request->getBody();
            if ($body->tell() > 0) {
                $body->rewind();
            }
        } catch (RuntimeException $e) {
            $ctx['error'] = 'The connection unexpectedly failed without ' . 'providing an error. The request would have been retried, ' . 'but attempting to rewind the request body failed. ' . 'Exception: ' . $e;
            return self::createRejection($easy, $ctx);
        }
        if (!isset($easy->options['_curl_retries'])) {
            $easy->options['_curl_retries'] = 1;
        } elseif ($easy->options['_curl_retries'] == 2) {
            $ctx['error'] = 'The cURL request was retried 3 times ' . 'and did not succeed. The most likely reason for the failure ' . 'is that cURL was unable to rewind the body of the request ' . 'and subsequent retries resulted in the same error. Turn on ' . 'the debug option to see what went wrong. See ' . 'https://bugs.php.net/bug.php?id=47204 for more information.';
            return self::createRejection($easy, $ctx);
        } else {
            ++$easy->options['_curl_retries'];
        }
        return $handler($easy->request, $easy->options);
    }
    private function createHeaderFn(EasyHandle $easy) : callable
    {
        if (isset($easy->options['on_headers'])) {
            $onHeaders = $easy->options['on_headers'];
            if (!\is_callable($onHeaders)) {
                throw new InvalidArgumentException('on_headers must be callable');
            }
        } else {
            $onHeaders = null;
        }
        return static function ($ch, $h) use($onHeaders, $easy, &$startingResponse) {
            $value = \trim($h);
            if ($value === '') {
                $startingResponse = \true;
                try {
                    $easy->createResponse();
                } catch (Exception $e) {
                    $easy->createResponseException = $e;
                    return -1;
                }
                if ($onHeaders !== null) {
                    try {
                        $onHeaders($easy->response);
                    } catch (Exception $e) {
                        $easy->onHeadersException = $e;
                        return -1;
                    }
                }
            } elseif ($startingResponse) {
                $startingResponse = \false;
                $easy->headers = [$value];
            } else {
                $easy->headers[] = $value;
            }
            return \strlen($h);
        };
    }
}
