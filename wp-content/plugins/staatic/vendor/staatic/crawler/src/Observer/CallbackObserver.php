<?php

namespace Staatic\Crawler\Observer;

use Closure;
use Staatic\Vendor\Psr\Http\Message\UriInterface;
use Staatic\Vendor\Psr\Http\Message\ResponseInterface;
use Staatic\Vendor\GuzzleHttp\Exception\TransferException;
final class CallbackObserver extends AbstractObserver
{
    /**
     * @var \Closure
     */
    private $crawlFulfilled;
    /**
     * @var \Closure
     */
    private $crawlRejected;
    /**
     * @var \Closure|null
     */
    private $startsCrawling;
    /**
     * @var \Closure|null
     */
    private $finishedCrawling;
    public function __construct(callable $crawlFulfilled, callable $crawlRejected, ?callable $startsCrawling = null, ?callable $finishedCrawling = null)
    {
        $this->crawlFulfilled = Closure::fromCallable($crawlFulfilled);
        $this->crawlRejected = Closure::fromCallable($crawlRejected);
        $this->startsCrawling = $startsCrawling ? Closure::fromCallable($startsCrawling) : null;
        $this->finishedCrawling = $finishedCrawling ? Closure::fromCallable($finishedCrawling) : null;
    }
    public function startsCrawling() : void
    {
        if (!$this->startsCrawling) {
            return;
        }
        ($this->startsCrawling)();
    }
    /**
     * @param UriInterface $url
     * @param UriInterface $transformedUrl
     * @param ResponseInterface $response
     * @param UriInterface|null $foundOnUrl
     * @param mixed[] $tags
     */
    public function crawlFulfilled($url, $transformedUrl, $response, $foundOnUrl, $tags) : void
    {
        ($this->crawlFulfilled)($url, $transformedUrl, $response, $foundOnUrl, $tags);
    }
    /**
     * @param UriInterface $url
     * @param UriInterface $transformedUrl
     * @param TransferException $transferException
     * @param UriInterface|null $foundOnUrl
     * @param mixed[] $tags
     */
    public function crawlRejected($url, $transformedUrl, $transferException, $foundOnUrl, $tags) : void
    {
        ($this->crawlRejected)($url, $transformedUrl, $transferException, $foundOnUrl, $tags);
    }
    public function finishedCrawling() : void
    {
        if (!$this->finishedCrawling) {
            return;
        }
        ($this->finishedCrawling)();
    }
}
