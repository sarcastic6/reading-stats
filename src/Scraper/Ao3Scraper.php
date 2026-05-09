<?php

declare(strict_types=1);

namespace App\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Scrapes metadata from Archive of Our Own (AO3) work pages.
 *
 * CRITICAL GUARDRAIL — METADATA ONLY:
 * This scraper operates solely on the work's landing page metadata block.
 * It must NEVER navigate to chapter pages or extract any story/prose content.
 * Only the following metadata is extracted: title, authors, summary, tags,
 * word count, chapter count, dates, series info, language, and source URL.
 *
 */
class Ao3Scraper implements ScraperInterface
{
    private const AO3_HOST = 'archiveofourown.org';

    /** Retry-After values above this cap are treated as absent (null returned). */
    private const RETRY_AFTER_CAP_SECONDS = 120;

    /**
     * Timestamp (microtime float) of the last outbound HTTP request made by this instance.
     * Null until the first request. Persists across calls within the same process lifetime,
     * so a Messenger worker correctly throttles across consecutive messages.
     */
    private ?float $lastRequestAt = null;

    /**
     * The AO3 session cookie string (e.g. "_otwarchive_session=VALUE").
     * Set after a successful login or when a persisted session is loaded from file.
     * Null when auth is disabled or login has not yet succeeded.
     * Also used as the "is authenticated" sentinel — the other auth cookies are
     * only sent when this is non-null.
     */
    private ?string $sessionCookie = null;

    /**
     * The remember-me token cookie string (e.g. "remember_user_token=VALUE").
     * Set alongside $sessionCookie on login. Allows AO3 (Rails) to re-establish
     * a session when _otwarchive_session has rotated or expired. Without this,
     * the session will go stale once the session cookie is no longer current.
     */
    private ?string $rememberUserToken = null;

    /**
     * The user credentials flag cookie string (e.g. "user_credentials=1").
     * Set by AO3 on successful login. Signals to AO3 that the browser has an
     * active remember-me session; may be required alongside remember_user_token.
     */
    private ?string $userCredentials = null;

    /**
     * True once a login attempt has been made (regardless of outcome),
     * so we only attempt login once per process lifetime.
     * Reset to false by invalidateSession() when a stale session is detected,
     * allowing one re-authentication attempt per fetchUrl() call.
     */
    private bool $loginAttempted = false;

    /**
     * Absolute path to the JSON file used to persist the AO3 session cookie
     * across process lifetimes. e.g. /path/to/project/var/ao3_session.json
     */
    private readonly string $sessionFilePath;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'SCRAPER_USER_AGENT')]
        private readonly string $userAgent,
        #[Autowire(env: 'int:SCRAPER_REQUEST_DELAY_MS')]
        private readonly int $requestDelayMs,
        #[Autowire(env: 'bool:AO3_AUTH_ENABLED')]
        private readonly bool $authEnabled,
        // Nullable so the container compiles when auth is disabled and the vars are absent.
        // Values are only used when $authEnabled is true.
        #[Autowire(env: 'default::AO3_USERNAME')]
        private readonly ?string $username,
        #[Autowire(env: 'default::AO3_PASSWORD')]
        private readonly ?string $password,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->sessionFilePath = $projectDir . '/var/ao3_session.json';
    }

    public function supports(string $url): bool
    {
        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);
        if ($host !== self::AO3_HOST && $host !== 'www.' . self::AO3_HOST) {
            return false;
        }

        $path = $parsed['path'] ?? '';

        return (bool) preg_match('#^/works/\d+#', $path);
    }

    /**
     * Fetches and parses the work page only, without fetching series data.
     *
     * The returned DTO will have seriesUrl populated if the work belongs to a series,
     * but seriesNumberOfParts, seriesTotalWords, and seriesIsComplete will be null.
     *
     * This method is intentionally not on ScraperInterface — it is an AO3-specific
     * implementation detail that may not generalise to other scrapers. The future batch
     * orchestrator will depend on the concrete Ao3Scraper directly.
     *
     * @throws \InvalidArgumentException if the URL is not a supported AO3 work URL
     * @throws RateLimitException if AO3 returns 429, 503, 502, or 504
     * @throws ScrapingException if AO3 returns any other non-200 status
     * @throws TransportExceptionInterface if the HTTP request cannot be completed
     */
    public function scrapeWorkPage(string $url): ScrapedWorkDto
    {
        // Explicitly guard against non-AO3 URLs. This makes the security boundary
        // intentional and visible — do not rely on normalizeUrl() to enforce it,
        // as that would be accidental protection that could silently disappear on refactor.
        if (!$this->supports($url)) {
            throw new \InvalidArgumentException(
                sprintf('Ao3Scraper does not support URL: %s', $url),
            );
        }

        $normalizedUrl = $this->normalizeUrl($url);

        $this->logger->debug('AO3 scraper: fetching work page', ['url' => $normalizedUrl]);

        $html = $this->fetchUrl($normalizedUrl);

        $this->logger->debug('AO3 scraper: fetched work page HTML', [
            'url' => $normalizedUrl,
            'bytes' => strlen($html),
        ]);

        return $this->parse($html, $normalizedUrl);
    }

    /**
     * Fetches and parses a work page, then fetches series data if the work belongs to a series.
     *
     * This is the interface-contract method. Its sync behavior is identical to before:
     * one call, one DTO returned, series fields populated when available.
     *
     * Known limitation: if the work page fetch succeeds but the series fetch throws
     * RateLimitException, the already-scraped work data is discarded and the caller must
     * retry the entire operation. This is acceptable for the sync path — rate limits
     * mid-scrape are rare, the doubled request is a single extra HTTP call, and preserving
     * partial state would add unjustified complexity for a one-at-a-time user-facing flow.
     * The async path avoids this entirely by calling scrapeWorkPage() directly.
     *
     * @throws \InvalidArgumentException if the URL is not a supported AO3 work URL
     * @throws RateLimitException if AO3 returns 429, 503, 502, or 504
     * @throws ScrapingException if AO3 returns any other non-200 status
     * @throws TransportExceptionInterface if an HTTP request cannot be completed
     */
    public function scrape(string $url): ScrapedWorkDto
    {
        $dto = $this->scrapeWorkPage($url);

        if ($dto->seriesUrl !== null) {
            $seriesData = $this->fetchSeriesData($dto->seriesUrl);
            $dto->seriesNumberOfParts = $seriesData['numberOfParts'];
            $dto->seriesTotalWords    = $seriesData['totalWords'];
            $dto->seriesIsComplete    = $seriesData['isComplete'];
        }

        return $dto;
    }

    /**
     * Performs a single outbound HTTP GET with proactive rate-limit throttling.
     *
     * Throttle: sleeps only the remaining portion of $requestDelayMs that hasn't elapsed
     * since the last request. If more time has already passed (or this is the first call),
     * sleeps zero. Updates $lastRequestAt after every successful response.
     *
     * Response handling:
     * - 429 / 503: throws RateLimitException with Retry-After seconds (capped at 120 s; null if absent or over cap)
     * - 502 / 504: throws RateLimitException with null (transient infra errors, same retry strategy)
     * - any other non-200: throws ScrapingException
     * - TransportExceptionInterface: propagates uncaught (no response received — caller decides)
     *
     * @throws RateLimitException
     * @throws ScrapingException
     * @throws TransportExceptionInterface
     */
    private function fetchUrl(string $url, bool $isRetry = false): string
    {
        $this->ensureLoggedIn();
        $this->throttle();

        // Always send view_adult to bypass the adult-content interstitial.
        // AO3 redirects canonical work URLs to /chapters/{id} and drops query params,
        // so this must be a cookie (not a query param) to survive the redirect chain.
        $cookies = ['view_adult=true'];
        if ($this->sessionCookie !== null) {
            $cookies[] = $this->sessionCookie;
            // Send all auth cookies together — remember_user_token allows AO3 to
            // re-establish the session if _otwarchive_session has rotated or expired.
            if ($this->rememberUserToken !== null) {
                $cookies[] = $this->rememberUserToken;
            }
            if ($this->userCredentials !== null) {
                $cookies[] = $this->userCredentials;
            }
        }

        $cookieHeader = implode('; ', $cookies);
        $this->logger->debug('AO3 scraper: sending request', [
            'url' => $url,
            'is_retry' => $isRetry,
            'session_cookie_present' => $this->sessionCookie !== null,
        ]);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Cookie' => $cookieHeader,
            ],
        ]);

        // getStatusCode() may throw TransportExceptionInterface for async clients — let it propagate.
        $statusCode = $response->getStatusCode();
        $this->lastRequestAt = microtime(true);

        $finalUrl = $response->getInfo('url') ?? $url;
        $redirectCount = $response->getInfo('redirect_count') ?? 0;
        $this->logger->debug('AO3 scraper: received response', [
            'requested_url' => $url,
            'final_url' => $finalUrl,
            'http_status' => $statusCode,
            'redirected' => $finalUrl !== $url,
            'redirect_count' => $redirectCount,
        ]);

        if ($statusCode === 200) {
            // AO3 redirects requests for restricted works to the login page (still a 200).
            if ($this->isLoginPage($finalUrl)) {
                $this->logger->warning('AO3 scraper: redirected to login page — session invalid or expired', [
                    'requested_url' => $url,
                    'final_url' => $finalUrl,
                    'redirect_count' => $redirectCount,
                    'session_cookie_present' => $this->sessionCookie !== null,
                    'is_retry' => $isRetry,
                ]);

                if (!$this->authEnabled) {
                    throw new AuthRequiredException(
                        $url,
                        sprintf('AO3 requires authentication for URL: %s (auth is disabled)', $url),
                    );
                }

                if (!$isRetry) {
                    $this->invalidateSession();

                    return $this->fetchUrl($url, true);
                }

                // Re-authentication did not help — credentials are likely wrong or the
                // account does not have access to this work.
                throw new AuthRequiredException(
                    $url,
                    sprintf('AO3 requires authentication for URL: %s (login failed or access denied)', $url),
                );
            }

            // AO3's /lost_cookie page means the server expected a cookie it couldn't find.
            // Treat this as a session problem — log everything we know and trigger re-auth.
            if ($this->isLostCookiePage($finalUrl)) {
                $this->logger->warning('AO3 scraper: redirected to /lost_cookie — session cookie not recognised by server', [
                    'requested_url' => $url,
                    'final_url' => $finalUrl,
                    'redirect_count' => $redirectCount,
                    'session_cookie_present' => $this->sessionCookie !== null,
                    'is_retry' => $isRetry,
                ]);

                if (!$this->authEnabled) {
                    throw new AuthRequiredException(
                        $url,
                        sprintf('AO3 returned /lost_cookie for URL: %s (auth is disabled)', $url),
                    );
                }

                if (!$isRetry) {
                    $this->invalidateSession();

                    return $this->fetchUrl($url, true);
                }

                throw new AuthRequiredException(
                    $url,
                    sprintf('AO3 returned /lost_cookie for URL: %s (re-authentication did not help)', $url),
                );
            }

            $content = $response->getContent();

            // Log any Set-Cookie headers on the final successful response.
            // AO3 rotates _otwarchive_session on redirects; if it appears here
            // we update our in-memory session and persist it so future processes
            // use the current value rather than a stale one.
            $rotatedSession = $this->extractNamedCookie($response->getHeaders(false), '_otwarchive_session');
            if ($rotatedSession !== null && $rotatedSession !== $this->sessionCookie) {
                $this->logger->debug('AO3 scraper: session cookie rotated in final response, updating persisted session', [
                    'url' => $url,
                ]);
                $this->sessionCookie = $rotatedSession;
                $this->saveSession();
            }

            return $content;
        }

        if ($statusCode === 429 || $statusCode === 503) {
            $retryAfter = $this->parseRetryAfter($response->getHeaders(false));
            throw new RateLimitException($url, $retryAfter);
        }

        if ($statusCode === 502 || $statusCode === 504) {
            // Transient Cloudflare/infrastructure errors — indistinguishable from a rate limit
            // in practice; apply the same backoff-with-jitter strategy.
            throw new RateLimitException($url, null);
        }

        throw new ScrapingException(
            $url,
            sprintf('AO3 returned HTTP %d for URL: %s', $statusCode, $url),
            $statusCode,
        );
    }

    /**
     * Sleeps for the remaining portion of $requestDelayMs that hasn't elapsed
     * since the last outbound request. No-op on the first call or if enough time
     * has already passed.
     */
    private function throttle(): void
    {
        if ($this->requestDelayMs <= 0 || $this->lastRequestAt === null) {
            return;
        }

        $elapsedMs = (int) ((microtime(true) - $this->lastRequestAt) * 1000);
        $remainingMs = $this->requestDelayMs - $elapsedMs;

        if ($remainingMs > 0) {
            usleep($remainingMs * 1000);
        }
    }

    /**
     * Parses the Retry-After response header.
     * Returns null if absent or if the value exceeds RETRY_AFTER_CAP_SECONDS.
     * Values over the cap are logged so the operator knows AO3's instruction was received.
     *
     * @param array<string, list<string>> $headers
     */
    private function parseRetryAfter(array $headers): ?int
    {
        // Header names are lowercased by Symfony's HTTP client.
        $values = $headers['retry-after'] ?? [];
        if ($values === []) {
            return null;
        }

        $seconds = (int) $values[0];
        if ($seconds > self::RETRY_AFTER_CAP_SECONDS) {
            $this->logger->warning('AO3 scraper: Retry-After exceeds cap; treating as null', [
                'retry_after' => $seconds,
                'cap' => self::RETRY_AFTER_CAP_SECONDS,
            ]);

            return null;
        }

        return $seconds > 0 ? $seconds : null;
    }

    public function canonicalizeUrl(string $url): string
    {
        return $this->normalizeUrl($url);
    }

    private function normalizeUrl(string $url): string
    {
        // Ensure https scheme
        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        // Extract work ID from path and build canonical URL
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';

        // Strip anything after /works/{id} (e.g. /chapters/...) to stay on the works page
        if (preg_match('#(/works/\d+)#', $path, $matches)) {
            $path = $matches[1];
        }

        return 'https://' . self::AO3_HOST . $path;
    }

    private function parse(string $html, string $sourceUrl): ScrapedWorkDto
    {
        $crawler = new Crawler($html);
        $dto = new ScrapedWorkDto();
        $dto->sourceUrl = $sourceUrl;
        $dto->sourceType = 'AO3';
        $dto->workType = 'Fanfiction';

        $dto->title = $this->parseTitle($crawler);
        $dto->authors = $this->parseAuthors($crawler);
        $dto->summary = $this->parseSummary($crawler);
        $dto->language = $this->parseLanguage($crawler);
        $dto->words = $this->parseWords($crawler);

        [$chapters, $totalChapters] = $this->parseChapters($crawler);
        $dto->chapters = $chapters;
        $dto->totalChapters = $totalChapters;
        $dto->isComplete = $this->parseIsComplete($crawler, $totalChapters);

        $dto->publishedDate = $this->parseDate($crawler, 'dd.published');
        $dto->lastUpdatedDate = $this->parseUpdatedDate($crawler);

        [$seriesName, $seriesUrl, $placeInSeries] = $this->parseSeries($crawler);
        $dto->seriesName = $seriesName;
        $dto->seriesUrl = $seriesUrl;
        $dto->placeInSeries = $placeInSeries;

        $dto->metadata = $this->parseMetadata($crawler);

        return $dto;
    }

    private function parseTitle(Crawler $crawler): ?string
    {
        try {
            $titleNode = $crawler->filter('h2.title.heading');
            if ($titleNode->count() === 0) {
                return null;
            }

            return trim($titleNode->text());
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse title', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return list<array{name: string, link: string|null}> */
    private function parseAuthors(Crawler $crawler): array
    {
        try {
            $authors = [];
            $seen = [];
            // Scope to h3.byline to avoid picking up rel="author" links in the
            // associations block (e.g. translation credits), which caused duplicate
            // author entries when the original author co-authored a translation.
            $crawler->filter('h3.byline a[rel="author"]')->each(function (Crawler $node) use (&$authors, &$seen): void {
                $name = trim($node->text());
                if ($name !== '' && isset($seen[$name])) {
                    $this->logger->warning('AO3 scraper: duplicate author skipped', ['name' => $name]);
                }
                if ($name !== '' && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $href = $node->attr('href');
                    $authors[] = [
                        'name' => $name,
                        'link' => $href !== null ? 'https://' . self::AO3_HOST . $href : null,
                    ];
                }
            });

            return $authors;
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse authors', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function parseSummary(Crawler $crawler): ?string
    {
        try {
            $node = $crawler->filter('.summary .userstuff');
            if ($node->count() === 0) {
                return null;
            }

            return trim($node->text());
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse summary', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function parseLanguage(Crawler $crawler): ?string
    {
        try {
            $node = $crawler->filter('dd.language');
            if ($node->count() === 0) {
                return null;
            }

            return trim($node->text());
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse language', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function parseWords(Crawler $crawler): ?int
    {
        try {
            $node = $crawler->filter('dd.words');
            if ($node->count() === 0) {
                return null;
            }

            // AO3 formats word counts with commas, e.g. "123,456"
            $text = str_replace(',', '', trim($node->text()));

            return (int) $text ?: null;
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse words', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Returns [chapters, totalChapters] where totalChapters is null for ongoing works ("X/?").
     *
     * @return array{int|null, int|null}
     */
    private function parseChapters(Crawler $crawler): array
    {
        try {
            $node = $crawler->filter('dd.chapters');
            if ($node->count() === 0) {
                return [null, null];
            }

            // Format is "X/Y" for complete or "X/?" for ongoing
            $text = trim($node->text());
            if (!str_contains($text, '/')) {
                $val = (int) $text;

                return [$val ?: null, $val ?: null];
            }

            [$published, $total] = explode('/', $text, 2);
            $publishedInt = (int) trim($published) ?: null;
            $totalInt = trim($total) === '?' ? null : ((int) trim($total) ?: null);

            return [$publishedInt, $totalInt];
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse chapters', ['error' => $e->getMessage()]);

            return [null, null];
        }
    }

    private function parseIsComplete(Crawler $crawler, ?int $totalChapters): bool
    {
        try {
            // If totalChapters is known (not "?"), check dd.status for explicit "Completed"
            $node = $crawler->filter('dd.status');
            if ($node->count() > 0) {
                return strtolower(trim($node->text())) === 'completed';
            }

            // Single-chapter works (no status dd) are complete when chapters == 1/1
            return $totalChapters !== null && $totalChapters === 1;
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse completion status', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function parseDate(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);
            if ($node->count() === 0) {
                return null;
            }

            return trim($node->text());
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('AO3 scraper: failed to parse date from selector "%s"', $selector),
                ['error' => $e->getMessage()],
            );

            return null;
        }
    }

    private function parseUpdatedDate(Crawler $crawler): ?string
    {
        // dd.status contains the last-updated date for multi-chapter works.
        // For single-chapter or complete works it may say "Completed" instead.
        // Fall back to published date if not a date string.
        try {
            $node = $crawler->filter('dd.status');
            if ($node->count() === 0) {
                return null;
            }

            $text = trim($node->text());
            // If the text looks like a date (YYYY-MM-DD), use it; otherwise return null
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
                return $text;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse updated date', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Returns [seriesName, seriesUrl, placeInSeries].
     *
     * @return array{string|null, string|null, int|null}
     */
    private function parseSeries(Crawler $crawler): array
    {
        try {
            $node = $crawler->filter('dd.series');
            if ($node->count() === 0) {
                return [null, null, null];
            }

            $seriesName = null;
            $seriesUrl = null;
            $placeInSeries = null;

            $seriesLinkSelector = implode(', ', [
                'a[href^="/series/"]',
                'a[href^="https://archiveofourown.org/series/"]',
                'a[href^="http://archiveofourown.org/series/"]',
                'a[href^="https://www.archiveofourown.org/series/"]',
                'a[href^="http://www.archiveofourown.org/series/"]',
            ]);

            $linkNode = $node->filter($seriesLinkSelector);
            if ($linkNode->count() > 0) {
                $seriesName = trim($linkNode->first()->text());
                $href = $linkNode->first()->attr('href');
                if ($href !== null) {
                    $seriesUrl = str_starts_with($href, 'http')
                        ? $href
                        : 'https://' . self::AO3_HOST . $href;
                }
            }

            // The position text typically appears as "Part X of <series>"
            $fullText = trim($node->text());
            if (preg_match('/Part\s+(\d+)/i', $fullText, $matches)) {
                $placeInSeries = (int) $matches[1];
            }

            return [$seriesName, $seriesUrl, $placeInSeries];
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse series', ['error' => $e->getMessage()]);

            return [null, null, null];
        }
    }

    /**
     * Fetches the AO3 series page and extracts series-level metadata.
     * Returns null for any field that cannot be determined.
     *
     * Unlike the work page fetch, transport failures and rate limits are allowed to propagate —
     * the caller (scrape()) handles them uniformly.
     *
     * @return array{numberOfParts: int|null, totalWords: int|null, isComplete: bool|null}
     *
     * @throws RateLimitException
     * @throws ScrapingException
     * @throws TransportExceptionInterface
     */
    private function fetchSeriesData(string $seriesUrl): array
    {
        // Guard against SSRF: the series URL is extracted from AO3's HTML and could
        // theoretically point to an arbitrary host if AO3 serves an absolute URL.
        // Validate the host explicitly rather than relying on parseSeries() to always
        // produce an AO3 URL — defence in depth, consistent with scrapeWorkPage().
        $host = parse_url($seriesUrl, PHP_URL_HOST);
        if ($host !== self::AO3_HOST && $host !== 'www.' . self::AO3_HOST) {
            throw new ScrapingException(sprintf(
                'Refusing to fetch series URL with unexpected host: %s',
                $seriesUrl,
            ));
        }

        $this->logger->debug('AO3 scraper: fetching series page', ['url' => $seriesUrl]);

        $html = $this->fetchUrl($seriesUrl);

        return $this->parseSeriesPage($html);
    }

    /**
     * Parses series metadata from the AO3 series page HTML.
     *
     * All three fields are scoped to dl.series.meta.group dl.stats to avoid collisions
     * with the per-work dl.stats blocks that appear in the series work listing below.
     * The "Complete:" dt has no class, so it is located via XPath.
     *
     * @return array{numberOfParts: int|null, totalWords: int|null, isComplete: bool|null}
     */
    private function parseSeriesPage(string $html): array
    {
        $result = ['numberOfParts' => null, 'totalWords' => null, 'isComplete' => null];

        try {
            $crawler = new Crawler($html);

            // Scope to the series metadata stats block. The series work listing below
            // also contains dl.stats elements (one per work), so without this scoping
            // the first filter match would be an individual work's stats, not the series total.
            $statsBlock = $crawler->filter('dl.series.meta.group dl.stats');
            if ($statsBlock->count() === 0) {
                $this->logger->warning('AO3 scraper: series metadata stats block not found');

                return $result;
            }

            $worksNode = $statsBlock->filter('dd.works');
            if ($worksNode->count() > 0) {
                $text = str_replace(',', '', trim($worksNode->text()));
                $result['numberOfParts'] = (int) $text ?: null;
            }

            $wordsNode = $statsBlock->filter('dd.words');
            if ($wordsNode->count() > 0) {
                $text = str_replace(',', '', trim($wordsNode->text()));
                $result['totalWords'] = (int) $text ?: null;
            }

            // "Complete:" dt has no class; XPath targets it by text content.
            $completeNode = $crawler->filterXPath(
                '//dl[contains(@class,"series") and contains(@class,"meta") and contains(@class,"group")]'
                . '//dl[contains(@class,"stats")]'
                . '//dt[normalize-space(text())="Complete:"]/following-sibling::dd[1]',
            );
            if ($completeNode->count() > 0) {
                $result['isComplete'] = strtolower(trim($completeNode->text())) === 'yes';
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to parse series page', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Logs in to AO3 if auth is enabled and a login has not yet been attempted.
     *
     * Called once per process lifetime (guarded by $loginAttempted).
     * On success, $sessionCookie is populated and included in all subsequent requests.
     * On any non-rate-limit failure, logs a warning and continues without auth —
     * scraping still works, just without access to registered-user-only works.
     *
     * Login flow:
     *   1. GET /users/login → extract authenticity_token + pre-login session cookie
     *   2. POST /users/login (no redirect follow) → on 302, extract _otwarchive_session
     *
     * @throws RateLimitException if AO3 rate-limits the login page request
     * @throws TransportExceptionInterface if an HTTP request cannot be completed
     */
    private function ensureLoggedIn(): void
    {
        $this->logger->debug('AO3 scraper: ensureLoggedIn() called', [
            'auth_enabled' => $this->authEnabled,
            'login_attempted' => $this->loginAttempted,
            'session_cookie_present' => $this->sessionCookie !== null,
        ]);

        if (!$this->authEnabled || $this->loginAttempted) {
            return;
        }

        if (empty($this->username) || empty($this->password)) {
            $this->logger->warning('AO3 auth is enabled but AO3_USERNAME or AO3_PASSWORD is not set; skipping login');
            $this->loginAttempted = true;

            return;
        }

        // Try to reuse a persisted session from a previous process before doing a full login.
        $storedCookie = $this->loadSession();
        if ($storedCookie !== null) {
            $this->sessionCookie = $storedCookie;
            $this->loginAttempted = true;
            $this->logger->debug('AO3 scraper: loaded persisted session from file');

            return;
        }

        $this->loginAttempted = true;

        $this->logger->debug('AO3 scraper: attempting login', ['username' => $this->username]);

        // Step 1: GET login page — extract CSRF token and pre-login session cookie.
        $loginUrl = 'https://' . self::AO3_HOST . '/users/login';

        $this->throttle();
        $pageResponse = $this->httpClient->request('GET', $loginUrl, [
            'headers' => ['User-Agent' => $this->userAgent],
        ]);
        $pageStatus = $pageResponse->getStatusCode();
        $this->lastRequestAt = microtime(true);

        if ($pageStatus === 429 || $pageStatus === 503) {
            throw new RateLimitException($loginUrl, $this->parseRetryAfter($pageResponse->getHeaders(false)));
        }

        if ($pageStatus !== 200) {
            $this->logger->warning('AO3 login: could not load login page', ['status' => $pageStatus]);

            return;
        }

        $token = $this->parseAuthenticityToken($pageResponse->getContent());
        if ($token === null) {
            $this->logger->warning('AO3 login: authenticity_token not found on login page');

            return;
        }

        $this->logger->debug('AO3 login: extracted authenticity_token', ['token' => $token]);

        $preCookies = $this->extractAllCookies($pageResponse->getHeaders(false));

        // Step 2: POST credentials. Use max_redirects=0 so we receive the 302 directly
        // and can read its Set-Cookie header before the client follows the redirect.
        $cookieHeader = implode('; ', array_filter([$preCookies, 'view_adult=true']));

        $this->throttle();
        $postResponse = $this->httpClient->request('POST', $loginUrl, [
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Cookie' => $cookieHeader,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'user[login]' => $this->username,
                'user[password]' => $this->password,
                'authenticity_token' => $token,
                'user[remember_me]' => '1',
                'commit' => 'Log in',
            ]),
        ]);
        $postStatus = $postResponse->getStatusCode();
        $this->lastRequestAt = microtime(true);

        $this->logger->debug('AO3 login: POST response received', [
            'status' => $postStatus,
            'location_header' => $postResponse->getHeaders(false)['location'] ?? [],
        ]);

        if ($postStatus === 429 || $postStatus === 503) {
            throw new RateLimitException($loginUrl, $this->parseRetryAfter($postResponse->getHeaders(false)));
        }

        // A successful AO3 login redirects (302) to the user's profile page.
        if ($postStatus !== 302) {
            $this->logger->warning('AO3 login: unexpected POST response (wrong credentials?)', [
                'status' => $postStatus,
            ]);

            return;
        }

        $postHeaders = $postResponse->getHeaders(false);
        $sessionCookie     = $this->extractNamedCookie($postHeaders, '_otwarchive_session');
        $rememberUserToken = $this->extractNamedCookie($postHeaders, 'remember_user_token');
        $userCredentials   = $this->extractNamedCookie($postHeaders, 'user_credentials');

        $this->logger->debug('AO3 login: extracted cookies from POST 302', [
            'session_cookie_present'      => $sessionCookie !== null,
            'remember_user_token_present' => $rememberUserToken !== null,
            'user_credentials_present'    => $userCredentials !== null,
        ]);

        if ($sessionCookie === null) {
            $this->logger->warning('AO3 login: session cookie not found in login redirect');

            return;
        }

        $this->sessionCookie     = $sessionCookie;
        $this->rememberUserToken = $rememberUserToken;
        $this->userCredentials   = $userCredentials;
        $this->saveSession();
        $this->logger->info('AO3 scraper: login successful', ['username' => $this->username]);
    }

    /**
     * Returns true if the given URL is the AO3 login page.
     * Used to detect when a request was silently redirected due to an expired session.
     */
    private function isLoginPage(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return str_contains($path, '/users/login');
    }

    /**
     * Returns true if the given URL is the AO3 /lost_cookie page.
     * AO3 redirects here when it expected a cookie in the request that it couldn't find.
     * Treated the same as a login-page redirect: the session is invalid, re-auth is needed.
     */
    private function isLostCookiePage(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return str_contains($path, '/lost_cookie');
    }

    /**
     * Loads the persisted session from the JSON file and populates all auth cookie
     * properties as side effects. Returns the session cookie string on success,
     * or null if the file does not exist, is unreadable, or has no session cookie.
     *
     * The remember_user_token and user_credentials keys are optional — session files
     * written by older versions of this class will not have them, and that is handled
     * gracefully (those properties remain null).
     */
    private function loadSession(): ?string
    {
        if (!file_exists($this->sessionFilePath)) {
            $this->logger->debug('AO3 scraper: no session file found', ['path' => $this->sessionFilePath]);

            return null;
        }

        try {
            $contents = file_get_contents($this->sessionFilePath);
            if ($contents === false) {
                $this->logger->warning('AO3 scraper: session file exists but could not be read', ['path' => $this->sessionFilePath]);

                return null;
            }

            /** @var array{cookie?: string, remember_user_token?: string, user_credentials?: string, saved_at?: string}|null $data */
            $data = json_decode($contents, true);
            if (!is_array($data) || empty($data['cookie'])) {
                $this->logger->warning('AO3 scraper: session file is malformed or empty', [
                    'path' => $this->sessionFilePath,
                    'raw_contents' => $contents,
                ]);

                return null;
            }

            $savedAt = $data['saved_at'] ?? 'unknown';
            $ageSeconds = null;
            if ($savedAt !== 'unknown') {
                try {
                    $savedTime = new \DateTimeImmutable($savedAt);
                    $ageSeconds = (new \DateTimeImmutable())->getTimestamp() - $savedTime->getTimestamp();
                } catch (\Throwable) {
                    // non-fatal, age stays null
                }
            }

            $this->rememberUserToken = $data['remember_user_token'] ?? null;
            $this->userCredentials   = $data['user_credentials'] ?? null;

            $this->logger->debug('AO3 scraper: loaded session from file', [
                'path' => $this->sessionFilePath,
                'saved_at' => $savedAt,
                'age_seconds' => $ageSeconds,
                'remember_user_token_present' => $this->rememberUserToken !== null,
                'user_credentials_present' => $this->userCredentials !== null,
            ]);

            return $data['cookie'];
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to read session file', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Persists the current session and all auth cookies to the JSON file so they
     * can be reused by future processes without requiring a fresh login.
     */
    private function saveSession(): void
    {
        if ($this->sessionCookie === null) {
            return;
        }

        try {
            $data = json_encode([
                'cookie'              => $this->sessionCookie,
                'remember_user_token' => $this->rememberUserToken,
                'user_credentials'    => $this->userCredentials,
                'saved_at'            => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            file_put_contents($this->sessionFilePath, $data);

            $this->logger->debug('AO3 scraper: session saved to file', [
                'path' => $this->sessionFilePath,
                'remember_user_token_present' => $this->rememberUserToken !== null,
                'user_credentials_present' => $this->userCredentials !== null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 scraper: failed to save session file', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clears the in-memory session state and deletes the session file.
     * Called when a stale session is detected so ensureLoggedIn() will perform
     * a fresh login on the next fetchUrl() call.
     */
    private function invalidateSession(): void
    {
        $this->sessionCookie     = null;
        $this->rememberUserToken = null;
        $this->userCredentials   = null;
        $this->loginAttempted    = false;

        if (file_exists($this->sessionFilePath) && !unlink($this->sessionFilePath)) {
            $this->logger->warning('AO3 scraper: failed to delete session file', [
                'path' => $this->sessionFilePath,
            ]);
        }

        $this->logger->info('AO3 scraper: session invalidated, will re-authenticate');
    }

    /**
     * Parses the Rails authenticity_token from the login page HTML.
     * Returns null if the field is not found or parsing fails.
     */
    private function parseAuthenticityToken(string $html): ?string
    {
        try {
            $crawler = new Crawler($html);
            $node = $crawler->filter('input[name="authenticity_token"]');
            if ($node->count() === 0) {
                return null;
            }

            return $node->first()->attr('value');
        } catch (\Throwable $e) {
            $this->logger->warning('AO3 login: failed to parse authenticity_token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extracts all name=value pairs from Set-Cookie response headers as a single
     * cookie string suitable for use in a Cookie request header.
     * Only the name=value portion is kept; attributes (Path, HttpOnly, etc.) are stripped.
     *
     * @param array<string, list<string>> $headers
     */
    private function extractAllCookies(array $headers): string
    {
        $cookies = [];
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $nameValue = trim(explode(';', $cookie, 2)[0]);
            if ($nameValue !== '') {
                $cookies[] = $nameValue;
            }
        }

        return implode('; ', $cookies);
    }

    /**
     * Finds a named cookie in Set-Cookie response headers and returns its
     * name=value string (attributes stripped), or null if not present.
     *
     * @param array<string, list<string>> $headers
     */
    private function extractNamedCookie(array $headers, string $name): ?string
    {
        $prefix = $name . '=';
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            if (str_starts_with($cookie, $prefix)) {
                return trim(explode(';', $cookie, 2)[0]);
            }
        }

        return null;
    }

    /**
     * Returns metadata grouped by AO3 category name.
     * Each entry is {name: string, link: string|null}.
     *
     * @return array<string, list<array{name: string, link: string|null}>>
     */
    private function parseMetadata(Crawler $crawler): array
    {
        $metadata = [];

        $categorySelectors = [
            'Rating' => 'dd.rating.tags a.tag',
            'Warning' => 'dd.warning.tags a.tag',
            'Category' => 'dd.category.tags a.tag',
            'Fandom' => 'dd.fandom.tags a.tag',
            'Relationship' => 'dd.relationship.tags a.tag',
            'Character' => 'dd.character.tags a.tag',
            'Tag' => 'dd.freeform.tags a.tag',
        ];

        foreach ($categorySelectors as $category => $selector) {
            try {
                $tags = [];
                $seen = [];
                $crawler->filter($selector)->each(function (Crawler $node) use (&$tags, &$seen): void {
                    $name = trim($node->text());
                    // Deduplicate by name — AO3 can return the same tag twice (e.g. when
                    // a canonical tag and its unwrangled alias both appear in the HTML).
                    if ($name !== '' && isset($seen[$name])) {
                        $this->logger->warning('AO3 scraper: duplicate tag skipped', [
                            'category' => $category,
                            'name'     => $name,
                        ]);
                    }
                    if ($name !== '' && !isset($seen[$name])) {
                        $seen[$name] = true;
                        $href = $node->attr('href');
                        $tags[] = [
                            'name' => $name,
                            'link' => $href !== null ? 'https://' . self::AO3_HOST . $href : null,
                        ];
                    }
                });

                if ($tags !== []) {
                    $metadata[$category] = $tags;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('AO3 scraper: failed to parse metadata category "%s"', $category),
                    ['error' => $e->getMessage()],
                );
            }
        }

        return $metadata;
    }
}
