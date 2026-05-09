<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\ReadingEntryRepository;

/**
 * Thin orchestrator for reading statistics. Delegates all SQL to
 * ReadingEntryRepository and handles only derived calculations (finish rate).
 */
class StatisticsService
{
    public function __construct(
        private readonly ReadingEntryRepository $readingEntryRepository,
    ) {
    }

    /**
     * Assembles all summary statistics for the dashboard.
     *
     * When $year is null (all-time view):
     *   - entryCount is the total number of entries (including TBR, Reading, etc.)
     * When $year is provided (year-filtered view):
     *   - entryCount is the count of entries finished in that year
     *
     * Word count stats always count only finished entries whose work has a
     * known word count; entryCount in wordCountStats may differ from finishedCount.
     *
     * @return array{
     *   entryCount: int,
     *   uniqueWorkCount: int,
     *   finishedCount: int,
     *   wordCountStats: array{totalWords: int, averageWords: float|null, entryCount: int},
     *   finishRate: float,
     *   averageRating: float|null,
     *   averageSpice: float|null,
     *   pinnedCount: int,
     *   byStatus: array<string, int>,
     *   byWorkType: array<string, int>,
     *   availableYears: int[],
     * }
     */
    public function getDashboardSummary(User $user, ?int $year): array
    {
        $finished = $this->readingEntryRepository->countFinished($user, $year);
        $started = $this->readingEntryRepository->countStarted($user, $year);

        return [
            'entryCount' => $year !== null
                ? $finished
                : $this->readingEntryRepository->countByUser($user),
            'uniqueWorkCount' => $this->readingEntryRepository->countUniqueWorks($user, $year),
            'finishedCount' => $finished,
            'wordCountStats' => $this->readingEntryRepository->getWordCountStats($user, $year),
            'finishRate' => $this->calculateFinishRate($finished, $started),
            'averageRating' => $this->readingEntryRepository->getAverageRating($user, $year),
            'averageSpice' => $this->readingEntryRepository->getAverageSpice($user, $year),
            'pinnedCount' => $this->readingEntryRepository->countPinned($user, $year),
            'byStatus' => $this->readingEntryRepository->countByStatus($user, $year),
            'byWorkType' => $this->readingEntryRepository->countByWorkType($user, $year),
            'availableYears' => $this->readingEntryRepository->findAvailableYears($user),
        ];
    }

    /**
     * Returns trend data for the chart section.
     *
     * When $year is provided, returns monthly counts (array<int, int> keyed 1–12,
     * zero-filled) via countByMonth.
     *
     * When $year is null (all-time), returns yearly counts (array<int, int> keyed
     * by year) via countByYear.
     *
     * @return array<int, int>
     */
    public function getTrendData(User $user, ?int $year): array
    {
        if ($year !== null) {
            return $this->readingEntryRepository->countByMonth($user, $year);
        }

        return $this->readingEntryRepository->countByYear($user);
    }

    /**
     * Returns word count trend data aligned to the same time buckets as getTrendData.
     *
     * Monthly (year set): array<int, int> keyed 1–12, zero-filled.
     * Yearly (all-time): zero-filled against $trendData keys so years with no
     * word data (all entries lack a word count) don't shift chart points.
     *
     * @param array<int, int> $trendData the output of getTrendData, used to align yearly keys
     * @return array<int, int>
     */
    public function getWordTrendData(User $user, ?int $year, array $trendData): array
    {
        if ($year !== null) {
            return $this->readingEntryRepository->sumWordsByMonth($user, $year);
        }

        $sums = $this->readingEntryRepository->sumWordsByYear($user);

        $aligned = [];
        foreach (array_keys($trendData) as $key) {
            $aligned[$key] = $sums[$key] ?? 0;
        }

        return $aligned;
    }

    /**
     * Returns reading entry counts bucketed by work word length (AO3-standard ranges).
     *
     * @return array{under1k: int, k1_10k: int, k10_50k: int, k50_100k: int, over100k: int}
     */
    public function getWordCountDistribution(User $user, ?int $year): array
    {
        return $this->readingEntryRepository->getWordCountDistribution($user, $year);
    }

    /**
     * Returns average reading pace (days start→finish) for completed entries.
     *
     * @return array{averageDays: float|null, entryCount: int}
     */
    public function getReadingPaceStats(User $user, ?int $year): array
    {
        return $this->readingEntryRepository->getReadingPaceStats($user, $year);
    }

    /**
     * Returns review and spice star distributions for the current user.
     *
     * @return array{review: array<int, int>, spice: array<int, int>}
     */
    public function getRatingDistributions(User $user, ?int $year): array
    {
        return [
            'review' => $this->readingEntryRepository->getRatingDistribution($user, $year),
            'spice' => $this->readingEntryRepository->getSpiceDistribution($user, $year),
        ];
    }

    /**
     * Returns entry count distributions by metadata type for use in donut charts.
     *
     * Each type name maps to an array<string, int> (metadata name → count), sorted
     * descending by count. Types with no entries return an empty array.
     *
     * @param string[] $typeNames
     * @return array<string, array<string, int>>
     */
    public function getMetadataDistributions(User $user, array $typeNames, ?int $year): array
    {
        $result = [];
        foreach ($typeNames as $typeName) {
            $rows = $this->readingEntryRepository->getTopMetadata($user, $typeName, 100, $year);
            $dist = [];
            foreach ($rows as $row) {
                $dist[$row['name']] = $row['count'];
            }
            $result[$typeName] = $dist;
        }

        return $result;
    }

    /**
     * Pass-through to repository for top-N metadata by type.
     *
     * @return array<array{name: string, count: int}>
     */
    public function getTopMetadata(User $user, string $typeName, int $limit, ?int $year): array
    {
        return $this->readingEntryRepository->getTopMetadata($user, $typeName, $limit, $year);
    }

    /**
     * Returns full ranking data for a metadata type with all derived columns computed.
     *
     * Each item contains:
     *   - name:       metadata entry name
     *   - count:      total reading entries (re-reads included, year-scoped)
     *   - countPct:   count as % of all user entries (year-scoped)
     *   - totalWords: sum of word counts from matched reading entries (year-scoped)
     *   - wordsPct:   totalWords as % of all user words (year-scoped)
     *   - readCount:  finished reading entries (year-scoped)
     *   - readPct:    readCount / count * 100 (finish rate within this item)
     *
     * Valid $sortColumn values: name, count, count_pct, words, words_pct, read_count, read_pct
     * Valid $sortDir values: asc, desc
     * Default sort: count DESC
     *
     * IMPORTANT: count_pct and words_pct sort identically to count and words
     * respectively (percentage is proportional). read_pct is a distinct sort order
     * (finish rate may differ from raw entry count).
     *
     * @return array<array{name: string, count: int, countPct: float, totalWords: int, wordsPct: float, readCount: int, readPct: float}>
     */
    public function getRankings(
        User $user,
        string $typeName,
        string $sortColumn,
        string $sortDir,
        ?int $year,
    ): array {
        $rows = $this->readingEntryRepository->getMetadataRankings($user, $typeName, $year);

        // Count % denominator: sum of all counts for this metadata type.
        // This gives each item's share of appearances within this type, not a
        // share of global reading entries. Handles multi-value types (Character,
        // Tag, etc.) correctly — one entry contributes to multiple items, so the
        // global entry count is the wrong denominator.
        $totalEntries = array_sum(array_column($rows, 'count'));

        // Words % denominator: total words read globally by this user (year-scoped).
        // This gives each item's share of actual words read — "what percentage of
        // the words you read were in works featuring this item?" Using the sum of
        // per-item word totals would inflate the denominator for multi-value types
        // (a 100k word fic with 3 characters would count 300k words total).
        $totalWords = $this->readingEntryRepository->getTotalWordsSumForUser($user, $year);

        return $this->buildRankingItems($rows, $totalEntries, $totalWords, $sortColumn, $sortDir);
    }

    /**
     * Finish rate: "of the works you started, what % did you finish?"
     * Returns 0.0 when no started entries exist (avoids division by zero).
     */
    public function getFinishRate(User $user, ?int $year): float
    {
        $finished = $this->readingEntryRepository->countFinished($user, $year);
        $started = $this->readingEntryRepository->countStarted($user, $year);

        return $this->calculateFinishRate($finished, $started);
    }

    /**
     * Returns the names of metadata types for which this user has at least one
     * reading entry (with optional year filter). Used for the rankings link
     * section on the dashboard — no link is shown for empty types.
     *
     * @return string[]
     */
    public function getAvailableRankingTypes(User $user, ?int $year): array
    {
        return $this->readingEntryRepository->findAvailableMetadataTypeNames($user, $year);
    }

    /**
     * Returns the most-read metadata entry for a given type name, plus any
     * other entries that tie for first place.
     *
     * IMPORTANT — counts by reading entries, not distinct works: re-reads of the
     * same work count separately. This reflects where the user's reading time
     * actually went rather than breadth of exposure.
     *
     * Returns null when the metadata type does not exist or has no entries.
     *
     * The "ties" key contains entries tied for first place beyond the first one
     * returned, so ties|length is the "+N" overflow count shown in the UI.
     *
     * @return array{name: string, count: int, ties: array<array{name: string, count: int}>}|null
     */
    public function getTopMetadataSpotlight(User $user, string $typeName, ?int $year): ?array
    {
        // Fetch enough rows to detect ties; >50-way ties at #1 are implausible.
        $rows = $this->readingEntryRepository->getTopMetadata($user, $typeName, 50, $year);

        if ($rows === []) {
            return null;
        }

        $topCount = $rows[0]['count'];
        $ties = array_values(array_filter(
            array_slice($rows, 1),
            static fn (array $row): bool => $row['count'] === $topCount,
        ));

        return [
            'name' => $rows[0]['name'],
            'count' => $topCount,
            'ties' => $ties,
        ];
    }

    /**
     * Returns the most-used main pairing across a user's reading entries, plus
     * any ties for first place. Uses the reading entry's mainPairing field, not
     * work metadata — so it reflects the user's personal focus for each read.
     *
     * Returns null when no reading entries have a main pairing set.
     *
     * @return array{name: string, count: int, ties: array<array{name: string, count: int}>}|null
     */
    public function getTopMainPairingSpotlight(User $user, ?int $year): ?array
    {
        $rows = $this->readingEntryRepository->getTopMainPairing($user, 50, $year);

        if ($rows === []) {
            return null;
        }

        $topCount = $rows[0]['count'];
        $ties = array_values(array_filter(
            array_slice($rows, 1),
            static fn (array $row): bool => $row['count'] === $topCount,
        ));

        return [
            'name' => $rows[0]['name'],
            'count' => $topCount,
            'ties' => $ties,
        ];
    }

    /**
     * Returns ranking data grouped by reading entry status, with all derived
     * columns computed and sorted. Follows the same column semantics as getRankings.
     *
     * Note: Read Count and Read % are only meaningful for statuses where
     * countsAsRead = true (typically only 'Completed'). All other statuses
     * will show 0 for both columns — this is expected and correct.
     *
     * @return array<array{name: string, count: int, countPct: float, totalWords: int, wordsPct: float, readCount: int, readPct: float}>
     */
    public function getStatusRankings(
        User $user,
        string $sortColumn,
        string $sortDir,
        ?int $year,
    ): array {
        $rows = $this->readingEntryRepository->getStatusRankingsData($user, $year);
        $totalEntries = array_sum(array_column($rows, 'count'));
        $totalWords = $this->readingEntryRepository->getTotalWordsSumForUser($user, $year);

        return $this->buildRankingItems($rows, $totalEntries, $totalWords, $sortColumn, $sortDir);
    }

    /**
     * Returns ranking data grouped by work language, with all derived columns
     * computed and sorted. Follows the same column semantics as getRankings.
     *
     * Works with no language set are excluded (INNER JOIN in the repository).
     *
     * @return array<array{name: string, count: int, countPct: float, totalWords: int, wordsPct: float, readCount: int, readPct: float}>
     */
    public function getLanguageRankings(
        User $user,
        string $sortColumn,
        string $sortDir,
        ?int $year,
    ): array {
        $rows = $this->readingEntryRepository->getLanguageRankingsData($user, $year);
        $totalEntries = array_sum(array_column($rows, 'count'));
        $totalWords = $this->readingEntryRepository->getTotalWordsSumForUser($user, $year);

        return $this->buildRankingItems($rows, $totalEntries, $totalWords, $sortColumn, $sortDir);
    }

    /**
     * Rankings grouped by the reading entry's mainPairing field.
     *
     * Entries with no main pairing set are excluded (INNER JOIN in the repository).
     * Count % denominator = sum of all main-pairing counts (type-scoped).
     * Words % denominator = user's global total words read (year-scoped).
     *
     * @return array<array{name: string, count: int, countPct: float, totalWords: int, wordsPct: float, readCount: int, readPct: float}>
     */
    public function getMainPairingRankings(
        User $user,
        string $sortColumn,
        string $sortDir,
        ?int $year,
    ): array {
        $rows = $this->readingEntryRepository->getMainPairingRankingsData($user, $year);
        $totalEntries = array_sum(array_column($rows, 'count'));
        $totalWords = $this->readingEntryRepository->getTotalWordsSumForUser($user, $year);

        return $this->buildRankingItems($rows, $totalEntries, $totalWords, $sortColumn, $sortDir);
    }

    /**
     * Computes derived columns (percentages, read rate) and sorts the items.
     * Shared by all ranking types (metadata, status, language).
     *
     * Count % uses $totalEntries as its denominator (type-scoped total).
     * Words % uses $globalTotalWords as its denominator (user's global words read).
     *
     * @param array<array{name: string, count: int, totalWords: int, readCount: int}> $rows
     * @return array<array{name: string, count: int, countPct: float, totalWords: int, wordsPct: float, readCount: int, readPct: float}>
     */
    /**
     * Returns ranking data grouped by Author metadata, with wordsPerChapter
     * computed in PHP and all rows sorted by the requested column.
     *
     * Null values for wordsPerChapter and avgReview sort to the bottom
     * regardless of sort direction (they represent missing data, not a low value).
     *
     * Valid $sortColumn values: name, count, words, chapters, wpc, read, read_in_words, avg_review
     * Valid $sortDir values: asc, desc
     * Default sort: count DESC
     *
     * @return array<array{
     *   mid: int,
     *   name: string,
     *   ao3Link: string|null,
     *   count: int,
     *   totalWords: int,
     *   totalChapters: int,
     *   wordsPerChapter: int|null,
     *   read: int,
     *   readInWords: int,
     *   avgReview: float|null,
     *   fandoms: string[],
     * }>
     */
    public function getAuthorRankings(
        User $user,
        string $sortColumn,
        string $sortDir,
        ?int $year,
    ): array {
        $rows = $this->readingEntryRepository->getAuthorRankingsData($user, $year);

        $items = array_map(static function (array $row): array {
            // Words per chapter is undefined when chapter count is zero or missing.
            // Stored as int (rounded) since fractional chapters are not meaningful.
            $wpc = ($row['totalChapters'] > 0)
                ? (int) round($row['totalWords'] / $row['totalChapters'])
                : null;

            return array_merge($row, ['wordsPerChapter' => $wpc]);
        }, $rows);

        usort($items, static function (array $a, array $b) use ($sortColumn, $sortDir): int {
            $valA = match ($sortColumn) {
                'name'          => $a['name'],
                'count'         => $a['count'],
                'words'         => $a['totalWords'],
                'chapters'      => $a['totalChapters'],
                'wpc'           => $a['wordsPerChapter'],
                'read'          => $a['read'],
                'read_in_words' => $a['readInWords'],
                'avg_review'    => $a['avgReview'],
                default         => throw new \UnexpectedValueException('Unknown sort column: ' . $sortColumn),
            };
            $valB = match ($sortColumn) {
                'name'          => $b['name'],
                'count'         => $b['count'],
                'words'         => $b['totalWords'],
                'chapters'      => $b['totalChapters'],
                'wpc'           => $b['wordsPerChapter'],
                'read'          => $b['read'],
                'read_in_words' => $b['readInWords'],
                'avg_review'    => $b['avgReview'],
                default         => throw new \UnexpectedValueException('Unknown sort column: ' . $sortColumn),
            };

            // Null values (missing data) always sort to the bottom regardless of direction
            if ($valA === null && $valB === null) {
                return 0;
            }
            if ($valA === null) {
                return 1;
            }
            if ($valB === null) {
                return -1;
            }

            $cmp = is_string($valA) ? strcasecmp($valA, $valB) : ($valA <=> $valB);

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        return $items;
    }

    /**
     * Returns ranking data grouped by series, sorted by the requested column.
     *
     * Null values for wordsRead, totalWorks, totalWords, and avgReview sort to the
     * bottom regardless of sort direction (missing data, not a low value).
     *
     * Valid $sortColumn values: name, count, works_read, words_read, avg_review
     * Default sort: count DESC
     *
     * @return array<array{
     *   sid: int,
     *   name: string,
     *   ao3Link: string|null,
     *   count: int,
     *   worksRead: int,
     *   totalWorks: int|null,
     *   wordsRead: int,
     *   totalWords: int|null,
     *   avgReview: float|null,
     *   isComplete: bool|null,
     * }>
     */
    public function getSeriesRankings(
        User $user,
        string $sortColumn,
        string $sortDir,
        ?int $year,
    ): array {
        $rows = $this->readingEntryRepository->getSeriesRankingsData($user, $year);

        usort($rows, static function (array $a, array $b) use ($sortColumn, $sortDir): int {
            $valA = match ($sortColumn) {
                'name'       => $a['name'],
                'count'      => $a['count'],
                'works_read' => $a['worksRead'],
                'words_read' => $a['wordsRead'],
                'coverage'   => $a['coverageWords'],
                'avg_review' => $a['avgReview'],
                default      => throw new \UnexpectedValueException('Unknown sort column: ' . $sortColumn),
            };
            $valB = match ($sortColumn) {
                'name'       => $b['name'],
                'count'      => $b['count'],
                'works_read' => $b['worksRead'],
                'words_read' => $b['wordsRead'],
                'coverage'   => $b['coverageWords'],
                'avg_review' => $b['avgReview'],
                default      => throw new \UnexpectedValueException('Unknown sort column: ' . $sortColumn),
            };

            // Null values always sort to the bottom regardless of direction
            if ($valA === null && $valB === null) {
                return 0;
            }
            if ($valA === null) {
                return 1;
            }
            if ($valB === null) {
                return -1;
            }

            $cmp = is_string($valA) ? strcasecmp($valA, $valB) : ($valA <=> $valB);

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        return $rows;
    }

    private function buildRankingItems(
        array $rows,
        int $totalEntries,
        int $globalTotalWords,
        string $sortColumn,
        string $sortDir,
    ): array {
        $items = array_map(
            static function (array $row) use ($totalEntries, $globalTotalWords): array {
                return [
                    'name' => $row['name'],
                    'count' => $row['count'],
                    'countPct' => $totalEntries > 0
                        ? round($row['count'] / $totalEntries * 100, 1)
                        : 0.0,
                    'totalWords' => $row['totalWords'],
                    'wordsPct' => $globalTotalWords > 0
                        ? round($row['totalWords'] / $globalTotalWords * 100, 1)
                        : 0.0,
                    'readCount' => $row['readCount'],
                    'readPct' => $row['count'] > 0
                        ? round($row['readCount'] / $row['count'] * 100, 1)
                        : 0.0,
                    // avgReview is null when the repository doesn't provide it (Status,
                    // Language) or when no reviews exist for this item.
                    'avgReview' => $row['avgReview'] ?? null,
                    // abandonRate: of entries where the user started this item, what % did
                    // they not complete? Null when the repository doesn't provide startedCount
                    // (Status, Language) or when startedCount is zero (all TBR entries).
                    'abandonRate' => isset($row['startedCount']) && $row['startedCount'] > 0
                        ? round(($row['startedCount'] - $row['readCount']) / $row['startedCount'] * 100, 1)
                        : null,
                ];
            },
            $rows,
        );

        usort($items, static function (array $a, array $b) use ($sortColumn, $sortDir): int {
            $valA = match ($sortColumn) {
                'name' => $a['name'],
                'count', 'count_pct' => $a['count'],
                'words', 'words_pct' => $a['totalWords'],
                'read_count' => $a['readCount'],
                'read_pct' => $a['readPct'],
                'avg_review' => $a['avgReview'],
                'abandon_rate' => $a['abandonRate'],
                default => throw new \UnexpectedValueException('Unknown sort column: ' . $sortColumn),
            };
            $valB = match ($sortColumn) {
                'name' => $b['name'],
                'count', 'count_pct' => $b['count'],
                'words', 'words_pct' => $b['totalWords'],
                'read_count' => $b['readCount'],
                'read_pct' => $b['readPct'],
                'avg_review' => $b['avgReview'],
                'abandon_rate' => $b['abandonRate'],
                default => throw new \UnexpectedValueException('Unknown sort column: ' . $sortColumn),
            };

            // Null values (no reviews) always sort to the bottom regardless of direction
            if ($valA === null && $valB === null) {
                return 0;
            }
            if ($valA === null) {
                return 1;
            }
            if ($valB === null) {
                return -1;
            }

            $cmp = is_string($valA)
                ? strcasecmp($valA, $valB)
                : ($valA <=> $valB);

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        return $items;
    }

    private function calculateFinishRate(int $finished, int $started): float
    {
        if ($started === 0) {
            return 0.0;
        }

        return round($finished / $started * 100, 1);
    }
}
