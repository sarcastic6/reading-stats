<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MetadataTypeRepository;
use App\Repository\StatusRepository;
use App\Repository\UserAchievementRepository;
use App\Service\AchievementService;
use App\Service\ReadingGoalService;
use App\Service\StatisticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stats')]
#[IsGranted('ROLE_USER')]
class StatsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsService $statisticsService,
        private readonly MetadataTypeRepository $metadataTypeRepository,
        private readonly StatusRepository $statusRepository,
        private readonly AchievementService $achievementService,
        private readonly ReadingGoalService $readingGoalService,
        private readonly UserAchievementRepository $userAchievementRepository,
    ) {
    }

    #[Route('', name: 'app_stats_dashboard')]
    public function dashboard(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $year = $this->parseYearParam($request);

        $summary = $this->statisticsService->getDashboardSummary($user, $year);
        $trendData = $this->statisticsService->getTrendData($user, $year);
        $wordTrendData = $this->statisticsService->getWordTrendData($user, $year, $trendData);
        $ratingDistributions = $this->statisticsService->getRatingDistributions($user, $year);
        $wordCountDistribution = $this->statisticsService->getWordCountDistribution($user, $year);
        $readingPace = $this->statisticsService->getReadingPaceStats($user, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        $metadataDistributions = $this->statisticsService->getMetadataDistributions(
            $user, ['Category', 'Rating', 'Warning'], $year,
        );

        $chartUrls = $this->buildChartUrls($summary, $trendData, $ratingDistributions, $metadataDistributions, $year);

        $topMetadata = [
            'rating'   => $this->statisticsService->getTopMetadataSpotlight($user, 'Rating', $year),
            'category' => $this->statisticsService->getTopMetadataSpotlight($user, 'Category', $year),
            'fandom'   => $this->statisticsService->getTopMetadataSpotlight($user, 'Fandom', $year),
            'pairing'  => $this->statisticsService->getTopMainPairingSpotlight($user, $year),
        ];

        $currentYear      = (int) date('Y');
        $goalsWithProgress = $this->readingGoalService->getGoalsWithProgress($user, $currentYear);
        $achievementProgress = $this->achievementService->getProgress($user);
        $recentAchievements  = $this->userAchievementRepository->findByUser($user);

        // Only show the 5 most recently unlocked on the dashboard
        $recentAchievements = array_slice($recentAchievements, 0, 5);

        // Next to unlock: locked achievements sorted by progress descending
        $nextAchievements = array_filter($achievementProgress, static fn (array $p): bool => !$p['unlocked']);
        usort($nextAchievements, static fn (array $a, array $b): int => $b['progressPct'] <=> $a['progressPct']);
        $nextAchievements = array_slice($nextAchievements, 0, 5);

        return $this->render('stats/dashboard.html.twig', [
            'summary' => $summary,
            'trendData' => $trendData,
            'wordTrendData' => $wordTrendData,
            'ratingDistributions' => $ratingDistributions,
            'wordCountDistribution' => $wordCountDistribution,
            'readingPace' => $readingPace,
            'metadataDistributions' => $metadataDistributions,
            'rankingTypes' => $rankingTypes,
            'year' => $year,
            'chartUrls' => $chartUrls,
            'topMetadata' => $topMetadata,
            'goalsWithProgress' => $goalsWithProgress,
            'currentYear' => $currentYear,
            'recentAchievements' => $recentAchievements,
            'nextAchievements' => $nextAchievements,
            'achievementProgress' => $achievementProgress,
        ]);
    }

    #[Route('/rankings/by-status', name: 'app_stats_rankings_status')]
    public function rankingsByStatus(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $year = $this->parseYearParam($request);
        $availableYears = $this->statisticsService->getDashboardSummary($user, null)['availableYears'];
        [$sortColumn, $sortDir] = $this->parseSortParams($request);

        $rankings = $this->statisticsService->getStatusRankings($user, $sortColumn, $sortDir, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        return $this->render('stats/rankings.html.twig', [
            'type' => 'Status',
            'rankings' => $rankings,
            'rankingTypes' => $rankingTypes,
            'year' => $year,
            'availableYears' => $availableYears,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'rankingRoute' => 'app_stats_rankings_status',
            'rankingRouteParams' => [],
            'showReadColumns' => false,
            'showAvgReview' => false,
            'showAbandonRate' => false,
        ]);
    }

    #[Route('/rankings/by-language', name: 'app_stats_rankings_language')]
    public function rankingsByLanguage(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $year = $this->parseYearParam($request);
        $availableYears = $this->statisticsService->getDashboardSummary($user, null)['availableYears'];
        [$sortColumn, $sortDir] = $this->parseSortParams($request);

        $rankings = $this->statisticsService->getLanguageRankings($user, $sortColumn, $sortDir, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        return $this->render('stats/rankings.html.twig', [
            'type' => 'Language',
            'rankings' => $rankings,
            'rankingTypes' => $rankingTypes,
            'year' => $year,
            'availableYears' => $availableYears,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'rankingRoute' => 'app_stats_rankings_language',
            'rankingRouteParams' => [],
            'showReadColumns' => true,
            'showAvgReview' => false,
            'showAbandonRate' => false,
        ]);
    }

    #[Route('/rankings/by-main-pairing', name: 'app_stats_rankings_main_pairing')]
    public function rankingsByMainPairing(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $year = $this->parseYearParam($request);
        $availableYears = $this->statisticsService->getDashboardSummary($user, null)['availableYears'];
        [$sortColumn, $sortDir] = $this->parseSortParams($request);

        $rankings = $this->statisticsService->getMainPairingRankings($user, $sortColumn, $sortDir, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        return $this->render('stats/rankings.html.twig', [
            'type' => 'Main Pairing',
            'rankings' => $rankings,
            'rankingTypes' => $rankingTypes,
            'year' => $year,
            'availableYears' => $availableYears,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'rankingRoute' => 'app_stats_rankings_main_pairing',
            'rankingRouteParams' => [],
            'showReadColumns' => true,
            'showAvgReview' => true,
            'showAbandonRate' => true,
        ]);
    }

    #[Route('/rankings/by-series', name: 'app_stats_rankings_series')]
    public function rankingsBySeries(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $year = $this->parseYearParam($request);
        $availableYears = $this->statisticsService->getDashboardSummary($user, null)['availableYears'];
        [$sortColumn, $sortDir] = $this->parseSeriesSortParams($request);

        $rankings = $this->statisticsService->getSeriesRankings($user, $sortColumn, $sortDir, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        return $this->render('stats/series_rankings.html.twig', [
            'type'               => 'Series',
            'rankings'           => $rankings,
            'rankingTypes'       => $rankingTypes,
            'year'               => $year,
            'availableYears'     => $availableYears,
            'sortColumn'         => $sortColumn,
            'sortDir'            => $sortDir,
            'rankingRoute'       => 'app_stats_rankings_series',
            'rankingRouteParams' => [],
        ]);
    }

    #[Route('/rankings/by-author', name: 'app_stats_rankings_author')]
    public function rankingsByAuthor(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $year = $this->parseYearParam($request);
        $availableYears = $this->statisticsService->getDashboardSummary($user, null)['availableYears'];
        [$sortColumn, $sortDir] = $this->parseAuthorSortParams($request);

        $rankings = $this->statisticsService->getAuthorRankings($user, $sortColumn, $sortDir, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        return $this->render('stats/author_rankings.html.twig', [
            'type'               => 'Author',
            'rankings'           => $rankings,
            'rankingTypes'       => $rankingTypes,
            'year'               => $year,
            'availableYears'     => $availableYears,
            'sortColumn'         => $sortColumn,
            'sortDir'            => $sortDir,
            'rankingRoute'       => 'app_stats_rankings_author',
            'rankingRouteParams' => [],
        ]);
    }

    #[Route('/rankings/{type}', name: 'app_stats_rankings')]
    public function rankings(Request $request, string $type): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Validate $type against actual metadata_types rows — no hardcoded allow-list.
        // The valid set is whatever the admin created at runtime.
        $metadataType = $this->metadataTypeRepository->findOneBy(['name' => $type]);
        if ($metadataType === null) {
            throw $this->createNotFoundException(
                sprintf('No metadata type named "%s" exists.', $type),
            );
        }

        $year = $this->parseYearParam($request);
        $availableYears = $this->statisticsService->getDashboardSummary($user, null)['availableYears'];
        [$sortColumn, $sortDir] = $this->parseSortParams($request);

        $rankings = $this->statisticsService->getRankings($user, $type, $sortColumn, $sortDir, $year);
        $rankingTypes = $this->statisticsService->getAvailableRankingTypes($user, $year);

        return $this->render('stats/rankings.html.twig', [
            'type' => $type,
            'rankings' => $rankings,
            'rankingTypes' => $rankingTypes,
            'year' => $year,
            'availableYears' => $availableYears,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'rankingRoute' => 'app_stats_rankings',
            'rankingRouteParams' => ['type' => $type],
            'showReadColumns' => true,
            'showAvgReview' => true,
            'showAbandonRate' => true,
        ]);
    }

    /**
     * Builds the per-label URL arrays used to make each chart segment clickable.
     *
     * Each array is ordered to match the chart's label/data arrays so that
     * clicking the Nth bar/slice navigates to urls[N].
     *
     * When $year is set, all list links are scoped to that year via dateFrom/dateTo.
     *
     * @param array<string, mixed>     $summary
     * @param array<int, int>          $trendData
     * @param array{review: array<int,int>, spice: array<int,int>} $ratingDistributions
     * @param array<string, array<string, int>> $metadataDistributions
     * @return array{trend: string[], status: array<string|null>, rating: string[], spice: string[], metaCategory: string[], metaRating: string[], metaWarning: string[]}
     */
    private function buildChartUrls(
        array $summary,
        array $trendData,
        array $ratingDistributions,
        array $metadataDistributions,
        ?int $year,
    ): array {
        $yearScope = $year !== null
            ? ['dateFrom' => "$year-01-01", 'dateTo' => "$year-12-31"]
            : [];

        // Trend chart: each bar is either a year (all-time) or a month (year view)
        $trendUrls = [];
        foreach (array_keys($trendData) as $key) {
            if ($year !== null) {
                $monthStr = str_pad((string) $key, 2, '0', \STR_PAD_LEFT);
                $monthStart = new \DateTimeImmutable("$year-$monthStr-01");
                $monthEnd = $monthStart->modify('last day of this month');
                $trendUrls[] = $this->generateUrl('app_reading_entry_list', [
                    'dateFrom' => $monthStart->format('Y-m-d'),
                    'dateTo' => $monthEnd->format('Y-m-d'),
                ]);
            } else {
                $trendUrls[] = $this->generateUrl('app_reading_entry_list', [
                    'dateFrom' => "$key-01-01",
                    'dateTo' => "$key-12-31",
                ]);
            }
        }

        // Status chart: map name → ID for the list's ?status= filter
        $statusIdByName = [];
        foreach ($this->statusRepository->findAll() as $status) {
            $statusIdByName[$status->getName()] = $status->getId();
        }
        $statusUrls = [];
        foreach (array_keys($summary['byStatus']) as $statusName) {
            $id = $statusIdByName[$statusName] ?? null;
            $statusUrls[] = $id !== null
                ? $this->generateUrl('app_reading_entry_list', array_merge(['status' => $id], $yearScope))
                : null;
        }

        // Review stars distribution
        $ratingUrls = [];
        foreach (array_keys($ratingDistributions['review']) as $stars) {
            $ratingUrls[] = $this->generateUrl('app_reading_entry_list', array_merge(['rating' => $stars], $yearScope));
        }

        // Spice stars distribution — uses spiceExact so the drill-down shows entries with
        // that precise spice value, not the minimum-based behaviour of the form's spice param.
        $spiceUrls = [];
        foreach (array_keys($ratingDistributions['spice']) as $spice) {
            $spiceUrls[] = $this->generateUrl('app_reading_entry_list', array_merge(['spiceExact' => $spice], $yearScope));
        }

        // Metadata donut charts: Category, Rating (AO3 content rating), Warning.
        // Each slice links to the reading list filtered by that metadata value.
        // URL format: metadata[TypeName]=Value (matches the list's metadata[] filter).
        $metaKeyMap = [
            'Category' => 'metaCategory',
            'Rating'   => 'metaRating',
            'Warning'  => 'metaWarning',
        ];
        $metadataChartUrls = [];
        foreach ($metaKeyMap as $typeName => $key) {
            $urls = [];
            foreach (array_keys($metadataDistributions[$typeName] ?? []) as $name) {
                $urls[] = $this->generateUrl(
                    'app_reading_entry_list',
                    array_merge(['metadata' => [$typeName => $name]], $yearScope),
                );
            }
            $metadataChartUrls[$key] = $urls;
        }

        // Word length distribution — one URL per bucket, boundaries match the repository buckets.
        $wordCountUrls = [
            $this->generateUrl('app_reading_entry_list', array_merge(['wordsMax' => 999], $yearScope)),
            $this->generateUrl('app_reading_entry_list', array_merge(['wordsMin' => 1000,  'wordsMax' => 9999], $yearScope)),
            $this->generateUrl('app_reading_entry_list', array_merge(['wordsMin' => 10000, 'wordsMax' => 49999], $yearScope)),
            $this->generateUrl('app_reading_entry_list', array_merge(['wordsMin' => 50000, 'wordsMax' => 99999], $yearScope)),
            $this->generateUrl('app_reading_entry_list', array_merge(['wordsMin' => 100000], $yearScope)),
        ];

        return [
            'trend'        => $trendUrls,
            'status'       => $statusUrls,
            'rating'       => $ratingUrls,
            'spice'        => $spiceUrls,
            'metaCategory' => $metadataChartUrls['metaCategory'],
            'metaRating'   => $metadataChartUrls['metaRating'],
            'metaWarning'  => $metadataChartUrls['metaWarning'],
            'wordCount'    => $wordCountUrls,
        ];
    }

    /**
     * Extracts and validates the ?sort= and ?dir= query parameters for rankings.
     *
     * Returns [sortColumn, sortDir]. Defaults to ['count', 'desc'] for invalid
     * or missing values.
     *
     * @return array{string, string}
     */
    private function parseSortParams(Request $request): array
    {
        $validColumns = ['name', 'count', 'count_pct', 'words', 'words_pct', 'read_count', 'read_pct', 'avg_review', 'abandon_rate'];
        $column = $request->query->get('sort', 'count');
        if (!in_array($column, $validColumns, true)) {
            $column = 'count';
        }

        $dir = $request->query->get('dir', 'desc');
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'desc';
        }

        return [$column, $dir];
    }

    /**
     * Extracts and validates sort params for the author rankings page.
     * Valid columns differ from the generic rankings (no pct columns; adds
     * chapters, wpc, read_in_words, avg_review; fandoms is excluded as unsortable).
     *
     * @return array{string, string}
     */
    private function parseAuthorSortParams(Request $request): array
    {
        $validColumns = ['name', 'count', 'words', 'chapters', 'wpc', 'read', 'read_in_words', 'avg_review'];
        $column = $request->query->get('sort', 'count');
        if (!in_array($column, $validColumns, true)) {
            $column = 'count';
        }

        $dir = $request->query->get('dir', 'desc');
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'desc';
        }

        return [$column, $dir];
    }

    /**
     * Extracts and validates sort params for the series rankings page.
     *
     * @return array{string, string}
     */
    private function parseSeriesSortParams(Request $request): array
    {
        $validColumns = ['name', 'count', 'works_read', 'words_read', 'coverage', 'avg_review'];
        $column = $request->query->get('sort', 'count');
        if (!in_array($column, $validColumns, true)) {
            $column = 'count';
        }

        $dir = $request->query->get('dir', 'desc');
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'desc';
        }

        return [$column, $dir];
    }

    /**
     * Extracts and validates the ?year= query parameter.
     * Returns null for the all-time view.
     */
    private function parseYearParam(Request $request): ?int
    {
        $raw = $request->query->get('year', '');
        if ($raw === '' || $raw === null) {
            return null;
        }

        $year = (int) $raw;

        // Sanity bounds: discard obviously invalid years
        if ($year < 1900 || $year > 2100) {
            return null;
        }

        return $year;
    }
}
