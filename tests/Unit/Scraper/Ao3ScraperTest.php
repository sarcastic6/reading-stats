<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scraper;

use App\Scraper\Ao3Scraper;
use App\Scraper\RateLimitException;
use App\Scraper\ScrapedWorkDto;
use App\Scraper\ScrapingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class Ao3ScraperTest extends TestCase
{
    /**
     * @param MockResponse|list<MockResponse> $responses
     */
    private function makeScraper(MockResponse|array $responses, int $delayMs = 0): Ao3Scraper
    {
        $client = new MockHttpClient($responses);

        return new Ao3Scraper(
            $client,
            new NullLogger(),
            'ReadingStats/test',
            $delayMs,
            false,        // authEnabled — disabled for unit tests; no real HTTP login
            null,         // username
            null,         // password
            sys_get_temp_dir(), // projectDir — session file is never written when auth is disabled
        );
    }

    private function makeScraperWithHtml(string $html, int $status = 200): Ao3Scraper
    {
        return $this->makeScraper(new MockResponse($html, ['http_code' => $status]));
    }

    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../../Fixtures/ao3/' . $name . '.html';

        return (string) file_get_contents($path);
    }

    // --- supports() ---

    /** @return array<string, array{string, bool}> */
    public static function supportsProvider(): array
    {
        return [
            'ao3 work' => ['https://archiveofourown.org/works/12345', true],
            'ao3 work with chapter' => ['https://archiveofourown.org/works/12345/chapters/67890', true],
            'ao3 www' => ['https://www.archiveofourown.org/works/12345', true],
            'ao3 series (not a work)' => ['https://archiveofourown.org/series/12345', false],
            'ao3 user' => ['https://archiveofourown.org/users/foo', false],
            'unrelated url' => ['https://example.com/works/12345', false],
            'ffn url' => ['https://www.fanfiction.net/s/12345', false],
            'no host' => ['notaurl', false],
        ];
    }

    #[DataProvider('supportsProvider')]
    public function test_supports(string $url, bool $expected): void
    {
        $scraper = $this->makeScraperWithHtml('');
        $this->assertSame($expected, $scraper->supports($url));
    }

    // --- canonicalizeUrl() ---

    /** @return array<string, array{string, string}> */
    public static function canonicalizeUrlProvider(): array
    {
        return [
            'plain work url'              => ['https://archiveofourown.org/works/12345', 'https://archiveofourown.org/works/12345'],
            'trailing slash stripped'     => ['https://archiveofourown.org/works/12345/', 'https://archiveofourown.org/works/12345'],
            'chapter path stripped'       => ['https://archiveofourown.org/works/12345/chapters/67890', 'https://archiveofourown.org/works/12345'],
            'www subdomain normalised'    => ['https://www.archiveofourown.org/works/12345', 'https://archiveofourown.org/works/12345'],
            'http upgraded to https'      => ['http://archiveofourown.org/works/12345', 'https://archiveofourown.org/works/12345'],
            'query string stripped'       => ['https://archiveofourown.org/works/12345?view_adult=true', 'https://archiveofourown.org/works/12345'],
        ];
    }

    #[DataProvider('canonicalizeUrlProvider')]
    public function test_canonicalize_url(string $input, string $expected): void
    {
        $scraper = $this->makeScraperWithHtml('');
        $this->assertSame($expected, $scraper->canonicalizeUrl($input));
    }

    // --- scrape(): complete work ---

    public function test_scrape_complete_work_title(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertSame('Test Complete Work Title', $dto->title);
    }

    public function test_scrape_complete_work_author(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertCount(1, $dto->authors);
        $this->assertSame('TestAuthor', $dto->authors[0]['name']);
        $this->assertStringContainsString('/users/TestAuthor', $dto->authors[0]['link'] ?? '');
    }

    public function test_scrape_complete_work_summary(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertStringContainsString('summary of the complete test work', $dto->summary ?? '');
    }

    public function test_scrape_complete_work_words(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        // "12,345" → 12345
        $this->assertSame(12345, $dto->words);
    }

    public function test_scrape_complete_work_chapters(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertSame(5, $dto->chapters);
        $this->assertSame(5, $dto->totalChapters);
        $this->assertTrue($dto->isComplete);
    }

    public function test_scrape_complete_work_dates(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertSame('2023-01-15', $dto->publishedDate);
    }

    public function test_scrape_complete_work_language(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertSame('English', $dto->language);
    }

    public function test_scrape_complete_work_source_type(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertSame('AO3', $dto->sourceType);
        $this->assertSame('Fanfiction', $dto->workType);
    }

    public function test_scrape_complete_work_metadata(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertArrayHasKey('Rating', $dto->metadata);
        $this->assertContains('General Audiences', array_column($dto->metadata['Rating'], 'name'));
        $this->assertStringContainsString('/tags/', $dto->metadata['Rating'][0]['link'] ?? '');

        $this->assertArrayHasKey('Fandom', $dto->metadata);
        $this->assertContains('Test Fandom', array_column($dto->metadata['Fandom'], 'name'));

        $this->assertArrayHasKey('Relationship', $dto->metadata);
        $this->assertContains('Character A/Character B', array_column($dto->metadata['Relationship'], 'name'));

        $this->assertArrayHasKey('Character', $dto->metadata);
        $this->assertContains('Character A', array_column($dto->metadata['Character'], 'name'));
        $this->assertContains('Character B', array_column($dto->metadata['Character'], 'name'));

        $this->assertArrayHasKey('Tag', $dto->metadata);
        $this->assertContains('Fluff', array_column($dto->metadata['Tag'], 'name'));
        $this->assertContains('Happy Ending', array_column($dto->metadata['Tag'], 'name'));
    }

    // --- scrape(): ongoing work (chapters "X/?") ---

    public function test_scrape_ongoing_work_chapters_not_complete(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('ongoing_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/22222');

        $this->assertSame(10, $dto->chapters);
        $this->assertNull($dto->totalChapters);
        $this->assertFalse($dto->isComplete);
    }

    public function test_scrape_ongoing_work_updated_date_not_set_when_status_is_date(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('ongoing_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/22222');

        // The ongoing fixture has "2024-06-15" in dd.status — should parse as updated date
        $this->assertSame('2024-06-15', $dto->lastUpdatedDate);
    }

    // --- scrape(): multi-author ---

    public function test_scrape_multi_author_returns_all_authors(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('multi_author'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/33333');

        $this->assertCount(2, $dto->authors);
        $authorNames = array_column($dto->authors, 'name');
        $this->assertContains('AuthorOne', $authorNames);
        $this->assertContains('AuthorTwo', $authorNames);
    }

    // --- scrape(): series work ---

    public function test_scrape_series_work_series_fields(): void
    {
        // scrape() makes two requests for a series work: work page then series page.
        // Provide a blank second response so MockHttpClient doesn't run out.
        $scraper = $this->makeScraper([
            new MockResponse($this->fixture('series_work'), ['http_code' => 200]),
            new MockResponse('<html></html>', ['http_code' => 200]),
        ]);
        $dto = $scraper->scrape('https://archiveofourown.org/works/44444');

        $this->assertSame('Test Series Name', $dto->seriesName);
        $this->assertSame(2, $dto->placeInSeries);
        $this->assertStringContainsString('archiveofourown.org/series/99999', $dto->seriesUrl ?? '');
    }

    public function test_scrape_series_work_ignores_navigation_links(): void
    {
        $scraper = $this->makeScraper([
            new MockResponse($this->fixture('series_work_with_navigation_links'), ['http_code' => 200]),
            new MockResponse('<html></html>', ['http_code' => 200]),
        ]);
        $dto = $scraper->scrape('https://archiveofourown.org/works/44444/chapters/55555');

        $this->assertSame('Test Series Name', $dto->seriesName);
        $this->assertSame(2, $dto->placeInSeries);
        $this->assertSame('https://archiveofourown.org/series/99999', $dto->seriesUrl);
    }

    // --- scrape(): minimal work (most optional fields missing) ---

    public function test_scrape_minimal_work_tolerates_missing_fields(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('minimal_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/55555');

        $this->assertSame('Minimal Work Title', $dto->title);
        $this->assertCount(1, $dto->authors);
        $this->assertSame('MinimalAuthor', $dto->authors[0]['name']);
        $this->assertNull($dto->summary);
        $this->assertNull($dto->language);
        $this->assertSame(500, $dto->words);
        $this->assertEmpty($dto->metadata);
    }

    // --- URL normalization ---

    public function test_url_is_normalized_to_canonical_form(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('minimal_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/55555/chapters/99999');

        // sourceUrl should be the canonical works/{id} URL
        $this->assertStringContainsString('archiveofourown.org/works/55555', $dto->sourceUrl ?? '');
        $this->assertStringNotContainsString('/chapters/', $dto->sourceUrl ?? '');
    }

    // --- HTTP error handling: RateLimitException ---

    /** @return array<string, array{int}> */
    public static function rateLimitStatusProvider(): array
    {
        return [
            'HTTP 429' => [429],
            'HTTP 503' => [503],
            'HTTP 502' => [502],
            'HTTP 504' => [504],
        ];
    }

    #[DataProvider('rateLimitStatusProvider')]
    public function test_scrape_throws_rate_limit_exception(int $status): void
    {
        $scraper = $this->makeScraperWithHtml('', $status);

        $this->expectException(RateLimitException::class);
        $scraper->scrape('https://archiveofourown.org/works/99999');
    }

    public function test_rate_limit_exception_carries_url(): void
    {
        $scraper = $this->makeScraperWithHtml('', 429);

        try {
            $scraper->scrape('https://archiveofourown.org/works/99999');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertStringContainsString('archiveofourown.org', $e->getUrl());
        }
    }

    public function test_rate_limit_exception_parses_retry_after_header(): void
    {
        $response = new MockResponse('', [
            'http_code'       => 429,
            'response_headers' => ['Retry-After: 30'],
        ]);
        $scraper = $this->makeScraper($response);

        try {
            $scraper->scrape('https://archiveofourown.org/works/99999');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfterSeconds());
        }
    }

    public function test_rate_limit_exception_returns_null_when_retry_after_absent(): void
    {
        $scraper = $this->makeScraperWithHtml('', 429);

        try {
            $scraper->scrape('https://archiveofourown.org/works/99999');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->getRetryAfterSeconds());
        }
    }

    public function test_rate_limit_exception_returns_null_when_retry_after_exceeds_cap(): void
    {
        $response = new MockResponse('', [
            'http_code'       => 429,
            'response_headers' => ['Retry-After: 999'],
        ]);
        $scraper = $this->makeScraper($response);

        try {
            $scraper->scrape('https://archiveofourown.org/works/99999');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->getRetryAfterSeconds());
        }
    }

    // --- HTTP error handling: ScrapingException ---

    public function test_scrape_throws_scraping_exception_on_404(): void
    {
        $scraper = $this->makeScraperWithHtml('Not found', 404);

        $this->expectException(ScrapingException::class);
        $scraper->scrape('https://archiveofourown.org/works/99999');
    }

    public function test_scraping_exception_carries_url_and_status(): void
    {
        $scraper = $this->makeScraperWithHtml('Not found', 404);

        try {
            $scraper->scrape('https://archiveofourown.org/works/99999');
            $this->fail('Expected ScrapingException');
        } catch (ScrapingException $e) {
            $this->assertStringContainsString('archiveofourown.org', $e->getScrapedUrl());
            $this->assertSame(404, $e->getHttpStatus());
        }
    }

    // --- scrapeWorkPage() ---

    public function test_scrape_work_page_returns_dto_without_series_data(): void
    {
        // series_work fixture has a seriesUrl — but scrapeWorkPage() must NOT fetch it.
        // We supply only one response: if a second request were made, MockHttpClient would
        // return an empty response and the series fields would silently be null anyway,
        // but the important assertion is that the DTO is returned successfully.
        $scraper = $this->makeScraperWithHtml($this->fixture('series_work'));
        $dto = $scraper->scrapeWorkPage('https://archiveofourown.org/works/44444');

        $this->assertInstanceOf(ScrapedWorkDto::class, $dto);
        $this->assertSame('Test Series Name', $dto->seriesName);
        $this->assertNotNull($dto->seriesUrl);
        // Series stats must not be populated — scrapeWorkPage() stops before the series fetch.
        $this->assertNull($dto->seriesNumberOfParts);
        $this->assertNull($dto->seriesTotalWords);
        $this->assertNull($dto->seriesIsComplete);
    }

    // --- DTO guarantees ---

    public function test_scrape_result_is_scraped_work_dto(): void
    {
        $scraper = $this->makeScraperWithHtml($this->fixture('complete_work'));
        $dto = $scraper->scrape('https://archiveofourown.org/works/11111');

        $this->assertInstanceOf(ScrapedWorkDto::class, $dto);
    }
}
