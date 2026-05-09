<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReadingEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReadingEntry>
 */
class ReadingEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReadingEntry::class);
    }

    /**
     * Fetches a paginated list of reading entries for a user, with JOIN FETCH
     * to avoid N+1 queries on Work and Status.
     *
     * The SoftDeleteFilter is temporarily disabled so that reading entries
     * that reference soft-deleted works still appear (with a visual indicator).
     *
     * @return ReadingEntry[]
     */
    public function findByUser(User $user, int $page = 1, int $limit = 25): array
    {
        $offset = ($page - 1) * $limit;
        $em = $this->getEntityManager();
        $filters = $em->getFilters();

        // Temporarily disable the soft-delete filter so deleted works are still visible
        // on reading entries that reference them (per design: preserve history).
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            return $this->createQueryBuilder('re')
                ->innerJoin('re.work', 'w')
                ->addSelect('w')
                ->innerJoin('re.status', 's')
                ->addSelect('s')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->orderBy('re.createdAt', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Fetches a paginated, filtered list of reading entries for a user.
     *
     * Supported filters (all optional):
     *   - status (int): status ID exact match
     *   - q (string): case-insensitive LIKE on work title
     *   - author (string): case-insensitive LIKE on author metadata name
     *   - pinned (bool): entry pinned flag
     *   - rating (int): exact reviewStars match
     *   - dateFrom (string: Y-m-d): dateFinished >= this date
     *   - dateTo (string: Y-m-d): dateFinished <= this date
     *
     * The SoftDeleteFilter is temporarily disabled so entries referencing
     * soft-deleted works still appear.
     *
     * @param array<string, mixed> $filterParams
     * @return ReadingEntry[]
     */
    public function findByUserFiltered(User $user, array $filterParams, int $page = 1, int $limit = 25, string $sort = 'dateFinished', string $dir = 'desc'): array
    {
        $offset = ($page - 1) * $limit;
        $em = $this->getEntityManager();
        $emFilters = $em->getFilters();

        $softDeleteEnabled = $emFilters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $emFilters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->innerJoin('re.work', 'w')
                ->addSelect('w')
                ->innerJoin('re.status', 's')
                ->addSelect('s')
                // NOTE: Do NOT join w.metadata here — collection joins multiply SQL rows and
                // cause setMaxResults() to limit rows rather than entities, producing fewer
                // results than the requested page size. Metadata is lazy-loaded per work
                // instead, which is acceptable for this app's data volume.
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $dirUpper = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

            if ($sort === 'title') {
                $qb->orderBy('LOWER(w.title)', $dirUpper);
            } elseif ($sort === 'status') {
                $qb->orderBy('s.name', $dirUpper);
            } elseif ($sort === 'author') {
                // Correlated subquery to get the first author name (alphabetically) for each
                // work without joining w.metadata — a direct join multiplies rows and breaks
                // setMaxResults() pagination (see note above).
                $qb->addSelect(
                    '(SELECT MIN(LOWER(sortA.name))
                      FROM App\Entity\Work sortW
                      JOIN sortW.metadata sortA
                      JOIN sortA.metadataType sortAType
                      WHERE sortW = w AND sortAType.name = :sortAuthorType)
                     AS HIDDEN authorSort'
                )
                ->setParameter('sortAuthorType', 'Author')
                ->orderBy('authorSort', $dirUpper);
            } else {
                // dateFinished sort — active entries (is_active = true, e.g. Reading) always
                // float to the top so they are easy to find and update. Within the non-active
                // group, entries sort by dateFinished with NULLs (e.g. DNF, On Hold) sinking
                // to the bottom since they are not actionable.
                $qb->addSelect('CASE WHEN s.isActive = true THEN 0 ELSE 1 END AS HIDDEN activeSort')
                    ->addSelect('CASE WHEN re.dateFinished IS NULL THEN 1 ELSE 0 END AS HIDDEN dateNullOrder')
                    ->orderBy('activeSort', 'ASC')
                    ->addOrderBy('dateNullOrder', 'ASC')
                    ->addOrderBy('re.dateFinished', $dirUpper);
            }

            $this->applyFilters($qb, $filterParams);

            return $qb->getQuery()->getResult();
        } finally {
            if ($softDeleteEnabled) {
                $emFilters->enable('soft_delete');
            }
        }
    }

    /**
     * Counts filtered entries for a user. Used for pagination alongside findByUserFiltered().
     *
     * @param array<string, mixed> $filterParams
     */
    public function countByUserFiltered(User $user, array $filterParams): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(DISTINCT re.id)')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->setParameter('user', $user);

        $this->applyFilters($qb, $filterParams);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Fetches a single reading entry by ID, scoped to the given user.
     * Returns null if the entry doesn't exist or belongs to a different user.
     *
     * The SoftDeleteFilter is disabled so entries referencing soft-deleted works
     * still load correctly (same reasoning as findByUser).
     */
    public function findByIdForUser(int $id, User $user): ?ReadingEntry
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();

        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            return $this->createQueryBuilder('re')
                ->innerJoin('re.work', 'w')
                ->addSelect('w')
                ->innerJoin('re.status', 's')
                ->addSelect('s')
                ->leftJoin('re.mainPairing', 'mp')
                ->addSelect('mp')
                ->where('re.id = :id')
                ->andWhere('re.user = :user')
                ->setParameter('id', $id)
                ->setParameter('user', $user)
                ->getQuery()
                ->getOneOrNullResult();
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Fetches all reading entries for the user with all associations eager-loaded
     * (work, series, series source links, language, metadata + type, status,
     * main pairing). Used for full-data exports.
     *
     * The soft-delete filter is temporarily disabled so entries referencing
     * soft-deleted works are still included — export must reflect the complete
     * reading history, including works deleted after the entry was recorded.
     *
     * Ordered by dateFinished DESC, then createdAt DESC.
     *
     * Joining w.metadata (a collection) without setMaxResults() is safe here —
     * Doctrine deduplicates rows via the identity map and no pagination is applied.
     *
     * @return ReadingEntry[]
     */
    public function findAllForUserExport(User $user): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();

        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            return $this->createQueryBuilder('re')
                ->innerJoin('re.work', 'w')
                ->addSelect('w')
                ->leftJoin('w.series', 'ser')
                ->addSelect('ser')
                ->leftJoin('ser.sourceLinks', 'sersl')
                ->addSelect('sersl')
                ->leftJoin('w.language', 'lang')
                ->addSelect('lang')
                ->leftJoin('w.metadata', 'm')
                ->addSelect('m')
                ->leftJoin('m.metadataType', 'mt')
                ->addSelect('mt')
                ->innerJoin('re.status', 's')
                ->addSelect('s')
                ->leftJoin('re.mainPairing', 'mp')
                ->addSelect('mp')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->orderBy('re.dateFinished', 'DESC')
                ->addOrderBy('re.createdAt', 'DESC')
                ->getQuery()
                ->getResult();
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Total reading entry count for the given user.
     * When $year is provided, only counts entries with dateFinished in that year.
     */
    public function countByUser(User $user, ?int $year = null): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(re.id)')
            ->where('re.user = :user')
            ->setParameter('user', $user);

        $this->applyYearFilter($qb, $year);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Sum of work word counts across reading entries for the given user where
     * the user has actually read the work (status.hasBeenStarted = true).
     * TBR entries are excluded; DNF entries are included (user read some of it).
     *
     * Works without a word count (NULL) are treated as zero.
     * When $year is provided, only sums entries with dateFinished in that year.
     *
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
     */
    public function getTotalWordsSumForUser(User $user, ?int $year = null): int
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('SUM(COALESCE(w.words, 0))')
                ->innerJoin('re.work', 'w')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true);

            $this->applyYearFilter($qb, $year);

            return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Applies optional filter conditions to a QueryBuilder.
     * The 'w' alias for Work must already be joined before calling this.
     *
     * @param array<string, mixed> $filterParams
     */
    // -------------------------------------------------------------------------
    // Aggregate / statistics query methods
    // -------------------------------------------------------------------------

    /**
     * Returns distinct years in which the user recorded a dateFinished, sorted
     * descending (most recent first). Used to populate the year-filter dropdown.
     *
     * PHP grouping is used for DB portability (avoids YEAR() / EXTRACT()).
     *
     * @return int[]
     */
    public function findAvailableYears(User $user): array
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished')
            ->where('re.user = :user')
            ->andWhere('re.dateFinished IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $years = [];
        foreach ($rows as $row) {
            $date = $row['dateFinished'];
            if ($date instanceof \DateTimeInterface) {
                $years[(int) $date->format('Y')] = true;
            }
        }

        $years = array_keys($years);
        rsort($years);

        return $years;
    }

    /**
     * Entry count grouped by status name for the given user.
     * When $year is provided, only counts entries with dateFinished in that year.
     *
     * @return array<string, int>
     */
    public function countByStatus(User $user, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('re')
            ->select('s.name as statusName, COUNT(re.id) as cnt')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->setParameter('user', $user)
            ->groupBy('s.id, s.name');

        $this->applyYearFilter($qb, $year);

        $rows = $qb->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['statusName']] = (int) $row['cnt'];
        }

        arsort($result);

        return $result;
    }

    /**
     * Entry count grouped by work type (Book/Fanfiction) for the given user.
     * When $year is provided, only counts entries with dateFinished in that year.
     *
     * The SoftDeleteFilter is disabled so entries referencing soft-deleted works
     * still contribute to the type count.
     *
     * @return array<string, int>
     */
    public function countByWorkType(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('w.type as workType, COUNT(re.id) as cnt')
                ->innerJoin('re.work', 'w')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('w.type');

            $this->applyYearFilter($qb, $year);

            $rows = $qb->getQuery()->getArrayResult();
            $result = [];
            foreach ($rows as $row) {
                $workType = $row['workType'];
                $typeName = $workType instanceof \App\Enum\WorkType
                    ? $workType->value
                    : (string) $workType;
                $result[$typeName] = (int) $row['cnt'];
            }

            arsort($result);

            return $result;
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Aggregate word count stats for entries where the user has actually read
     * the work (status.hasBeenStarted = true or status.countsAsRead = true —
     * i.e., any status other than TBR).
     * Only entries whose work has a non-NULL word count are counted.
     *
     * Returns:
     *   - totalWords:   sum of word counts across matched entries
     *   - averageWords: average word count (null if no entries match)
     *   - entryCount:   count of matched entries (denominator for averageWords)
     *
     * The SoftDeleteFilter is disabled so soft-deleted works still count.
     * The entryCount may be less than the total finished count when some works
     * have no word count; the template should display a contextual subtitle.
     *
     * @return array{totalWords: int, averageWords: float|null, entryCount: int}
     */
    public function getWordCountStats(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $base = $this->createQueryBuilder('re')
                ->innerJoin('re.work', 'w')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->andWhere('w.words IS NOT NULL')
                ->setParameter('user', $user)
                ->setParameter('started', true);

            $this->applyYearFilter($base, $year);

            // Three separate scalar queries to avoid hydration-mode complexity.
            // COALESCE is defensive; the IS NOT NULL filter above already excludes NULLs.
            $totalWords = (int) ((clone $base)
                ->select('SUM(COALESCE(w.words, 0))')
                ->getQuery()
                ->getSingleScalarResult() ?? 0);

            $avgRaw = (clone $base)
                ->select('AVG(w.words)')
                ->getQuery()
                ->getSingleScalarResult();

            $entryCount = (int) (clone $base)
                ->select('COUNT(re.id)')
                ->getQuery()
                ->getSingleScalarResult();

            return [
                'totalWords' => $totalWords,
                'averageWords' => $avgRaw !== null ? round((float) $avgRaw) : null,
                'entryCount' => $entryCount,
            ];
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns reading entry counts bucketed into AO3-standard word length ranges.
     *
     * Buckets: <1K, 1K–10K, 10K–50K, 50K–100K, >100K words.
     * Only entries where the user has actually started the work (hasBeenStarted)
     * and the work has a known word count are included — consistent with word
     * count stats elsewhere.
     *
     * Bucketing is done in PHP rather than SQL to avoid database-specific CASE
     * syntax and maintain cross-DB portability.
     *
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
     *
     * @return array{under1k: int, k1_10k: int, k10_50k: int, k50_100k: int, over100k: int}
     */
    public function getWordCountDistribution(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('w.words')
                ->innerJoin('re.work', 'w')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->andWhere('w.words IS NOT NULL')
                ->setParameter('user', $user)
                ->setParameter('started', true);

            $this->applyYearFilter($qb, $year);

            $rows = $qb->getQuery()->getArrayResult();

            $buckets = [
                'under1k'   => 0,
                'k1_10k'    => 0,
                'k10_50k'   => 0,
                'k50_100k'  => 0,
                'over100k'  => 0,
            ];

            foreach ($rows as $row) {
                $words = (int) $row['words'];
                if ($words < 1_000) {
                    $buckets['under1k']++;
                } elseif ($words < 10_000) {
                    $buckets['k1_10k']++;
                } elseif ($words < 50_000) {
                    $buckets['k10_50k']++;
                } elseif ($words < 100_000) {
                    $buckets['k50_100k']++;
                } else {
                    $buckets['over100k']++;
                }
            }

            return $buckets;
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Counts completed entries (status.countsAsRead = true) per month for the
     * given year. Returns array<int, int> keyed 1–12, zero-filled.
     *
     * PHP grouping is used instead of MONTH() for DB portability.
     *
     * @return array<int, int>
     */
    public function countByMonth(User $user, int $year): array
    {
        $yearStart = new \DateTimeImmutable("$year-01-01");
        $yearEnd = new \DateTimeImmutable("$year-12-31");

        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('re.dateFinished >= :yearStart')
            ->andWhere('re.dateFinished <= :yearEnd')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->setParameter('yearStart', $yearStart, Types::DATE_IMMUTABLE)
            ->setParameter('yearEnd', $yearEnd, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(range(1, 12), 0);
        foreach ($rows as $row) {
            $date = $row['dateFinished'];
            if ($date instanceof \DateTimeInterface) {
                $counts[(int) $date->format('n')]++;
            }
        }

        return $counts;
    }

    /**
     * Counts completed entries (status.countsAsRead = true) per calendar year.
     * Returns array<int, int> keyed by year, sorted ascending.
     * Used for the all-time trend chart.
     *
     * PHP grouping is used instead of YEAR() for DB portability.
     *
     * @return array<int, int>
     */
    public function countByYear(User $user): array
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('re.dateFinished IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $date = $row['dateFinished'];
            if ($date instanceof \DateTimeInterface) {
                $year = (int) $date->format('Y');
                $counts[$year] = ($counts[$year] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Sums work word counts per month for completed entries in the given year.
     * Returns array<int, int> keyed 1–12, zero-filled.
     *
     * PHP grouping is used instead of MONTH() for DB portability.
     * Entries with no word count on the work are excluded from the sum.
     *
     * @return array<int, int>
     */
    public function sumWordsByMonth(User $user, int $year): array
    {
        $yearStart = new \DateTimeImmutable("$year-01-01");
        $yearEnd   = new \DateTimeImmutable("$year-12-31");

        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished', 'w.words')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('re.dateFinished >= :yearStart')
            ->andWhere('re.dateFinished <= :yearEnd')
            ->andWhere('w.words IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->setParameter('yearStart', $yearStart, Types::DATE_IMMUTABLE)
            ->setParameter('yearEnd', $yearEnd, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        $sums = array_fill_keys(range(1, 12), 0);
        foreach ($rows as $row) {
            $date = $row['dateFinished'];
            if ($date instanceof \DateTimeInterface) {
                $sums[(int) $date->format('n')] += (int) $row['words'];
            }
        }

        return $sums;
    }

    /**
     * Sums work word counts per calendar year for completed entries.
     * Returns array<int, int> keyed by year, sorted ascending.
     *
     * PHP grouping is used instead of YEAR() for DB portability.
     * Entries with no word count on the work are excluded from the sum.
     *
     * @return array<int, int>
     */
    public function sumWordsByYear(User $user): array
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished', 'w.words')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('re.dateFinished IS NOT NULL')
            ->andWhere('w.words IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->getQuery()
            ->getArrayResult();

        $sums = [];
        foreach ($rows as $row) {
            $date = $row['dateFinished'];
            if ($date instanceof \DateTimeInterface) {
                $y = (int) $date->format('Y');
                $sums[$y] = ($sums[$y] ?? 0) + (int) $row['words'];
            }
        }

        ksort($sums);

        return $sums;
    }

    /**
     * Histogram of reviewStars (1–5) for the given user, zero-filled for all
     * values 1–5 so the chart always shows the full scale.
     *
     * @return array<int, int>
     */
    public function getRatingDistribution(User $user, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('re')
            ->select('re.reviewStars as stars, COUNT(re.id) as cnt')
            ->where('re.user = :user')
            ->andWhere('re.reviewStars IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('re.reviewStars')
            ->orderBy('re.reviewStars', 'ASC');

        $this->applyYearFilter($qb, $year);

        $rows = $qb->getQuery()->getArrayResult();
        $result = array_fill_keys(range(1, 5), 0);
        foreach ($rows as $row) {
            $result[(int) $row['stars']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Histogram of spiceStars (0–5) for the given user, zero-filled for all
     * values 0–5 so the chart always shows the full scale.
     *
     * @return array<int, int>
     */
    public function getSpiceDistribution(User $user, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('re')
            ->select('re.spiceStars as stars, COUNT(re.id) as cnt')
            ->where('re.user = :user')
            ->andWhere('re.spiceStars IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('re.spiceStars')
            ->orderBy('re.spiceStars', 'ASC');

        $this->applyYearFilter($qb, $year);

        $rows = $qb->getQuery()->getArrayResult();
        $result = array_fill_keys(range(0, 5), 0);
        foreach ($rows as $row) {
            $result[(int) $row['stars']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Top $limit metadata entries of the given type, ranked by how many of the
     * user's reading entries reference a work with that metadata.
     *
     * CRITICAL: The query is anchored on reading_entries WHERE user = :user.
     * Without this anchor, counts would include other users' entries referencing
     * the same (global) works, producing inflated results.
     *
     * The SoftDeleteFilter is disabled so entries referencing soft-deleted works
     * still contribute to metadata counts.
     *
     * @return array<array{name: string, count: int}>
     */
    public function getTopMetadata(
        User $user,
        string $typeName,
        int $limit,
        ?int $year = null,
    ): array {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('m.name as name, COUNT(re.id) as cnt')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->setParameter('user', $user)
                ->setParameter('typeName', $typeName)
                ->groupBy('m.id, m.name')
                ->orderBy('COUNT(re.id)', 'DESC')
                ->setMaxResults($limit);

            $this->applyYearFilter($qb, $year);

            $rows = $qb->getQuery()->getArrayResult();

            return array_map(
                static fn (array $row) => ['name' => (string) $row['name'], 'count' => (int) $row['cnt']],
                $rows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns the top-N most-used main pairings across a user's reading entries.
     *
     * Counts by reading entry's mainPairing field — not by work metadata — so
     * this reflects the user's personal focus for each read, not all pairings
     * tagged on the work.
     *
     * Entries with no main pairing set are excluded (INNER JOIN).
     *
     * @return array<array{name: string, count: int}>
     */
    public function getTopMainPairing(User $user, int $limit, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('mp.name as name, COUNT(re.id) as cnt')
                ->innerJoin('re.mainPairing', 'mp')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('mp.id, mp.name')
                ->orderBy('COUNT(re.id)', 'DESC')
                ->setMaxResults($limit);

            $this->applyYearFilter($qb, $year);

            $rows = $qb->getQuery()->getArrayResult();

            return array_map(
                static fn (array $row) => ['name' => (string) $row['name'], 'count' => (int) $row['cnt']],
                $rows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns all pinned entries for a user, applying the same sort logic as findByUserFiltered()
     * but with no other filters. Used to populate the pinned section on the list page.
     *
     * The SoftDeleteFilter is temporarily disabled so pinned entries referencing
     * soft-deleted works still appear.
     *
     * @return ReadingEntry[]
     */
    public function findPinnedByUser(User $user, string $sort = 'dateFinished', string $dir = 'desc'): array
    {
        $em = $this->getEntityManager();
        $emFilters = $em->getFilters();
        $softDeleteEnabled = $emFilters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $emFilters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->innerJoin('re.work', 'w')
                ->addSelect('w')
                ->innerJoin('re.status', 's')
                ->addSelect('s')
                ->where('re.user = :user')
                ->andWhere('re.pinned = :pinned')
                ->setParameter('user', $user)
                ->setParameter('pinned', true);

            $dirUpper = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

            if ($sort === 'title') {
                $qb->orderBy('LOWER(w.title)', $dirUpper);
            } elseif ($sort === 'status') {
                $qb->orderBy('s.name', $dirUpper);
            } elseif ($sort === 'author') {
                $qb->addSelect(
                    '(SELECT MIN(LOWER(sortA.name))
                      FROM App\Entity\Work sortW
                      JOIN sortW.metadata sortA
                      JOIN sortA.metadataType sortAType
                      WHERE sortW = w AND sortAType.name = :sortAuthorType)
                     AS HIDDEN authorSort'
                )
                ->setParameter('sortAuthorType', 'Author')
                ->orderBy('authorSort', $dirUpper);
            } else {
                // dateFinished sort — same logic as findByUserFiltered(): active entries float
                // to top, NULLs in the non-active group sink to the bottom.
                $qb->addSelect('CASE WHEN s.isActive = true THEN 0 ELSE 1 END AS HIDDEN activeSort')
                    ->addSelect('CASE WHEN re.dateFinished IS NULL THEN 1 ELSE 0 END AS HIDDEN dateNullOrder')
                    ->orderBy('activeSort', 'ASC')
                    ->addOrderBy('dateNullOrder', 'ASC')
                    ->addOrderBy('re.dateFinished', $dirUpper);
            }

            return $qb->getQuery()->getResult();
        } finally {
            if ($softDeleteEnabled) {
                $emFilters->enable('soft_delete');
            }
        }
    }

    /**
     * Count of pinned reading entries for the given user.
     */
    public function countPinned(User $user, ?int $year = null): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(re.id)')
            ->where('re.user = :user')
            ->andWhere('re.pinned = :pinned')
            ->setParameter('user', $user)
            ->setParameter('pinned', true);

        $this->applyYearFilter($qb, $year);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Average reviewStars for entries that have a rating, for the given user.
     * Returns null when no rated entries exist.
     */
    public function getAverageRating(User $user, ?int $year = null): ?float
    {
        $qb = $this->createQueryBuilder('re')
            ->select('AVG(re.reviewStars)')
            ->where('re.user = :user')
            ->andWhere('re.reviewStars IS NOT NULL')
            ->setParameter('user', $user);

        $this->applyYearFilter($qb, $year);

        $result = $qb->getQuery()->getSingleScalarResult();

        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Count of entries where status.countsAsRead = true for the given user.
     * When $year is provided, also filters on dateFinished within that year.
     */
    public function countFinished(User $user, ?int $year = null): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(re.id)')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true);

        $this->applyYearFilter($qb, $year);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Filter-scoped stat methods (used by the reading-list stat strip)
    // These mirror their unfiltered counterparts but call applyFilters() instead
    // of applyYearFilter(), so they respect the active filter set.
    // -------------------------------------------------------------------------

    /**
     * Total word count for entries with hasBeenStarted = true, scoped to the
     * active filter set. Used by the stat strip when filters are applied.
     *
     * @param array<string, mixed> $filterParams
     */
    public function getTotalWordsSumFiltered(User $user, array $filterParams): int
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('SUM(COALESCE(w.words, 0))')
                ->innerJoin('re.work', 'w')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true);

            $this->applyFilters($qb, $filterParams);

            return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Average review stars for entries that have a rating, scoped to the active
     * filter set. Returns null when no rated entries match the filters.
     *
     * @param array<string, mixed> $filterParams
     */
    public function getAverageRatingFiltered(User $user, array $filterParams): ?float
    {
        $qb = $this->createQueryBuilder('re')
            ->select('AVG(re.reviewStars)')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('re.reviewStars IS NOT NULL')
            ->setParameter('user', $user);

        $this->applyFilters($qb, $filterParams);

        $result = $qb->getQuery()->getSingleScalarResult();

        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Count of entries where status.countsAsRead = true, scoped to the active
     * filter set. Used by the stat strip's 4th box when filters are active.
     *
     * DISTINCT re.id guards against duplicate rows that can arise when
     * applyFilters() adds multiple JOIN paths (e.g. author + fandom both joined).
     *
     * @param array<string, mixed> $filterParams
     */
    public function countFinishedFiltered(User $user, array $filterParams): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(DISTINCT re.id)')
            ->innerJoin('re.work', 'w')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true);

        $this->applyFilters($qb, $filterParams);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------

    /**
     * Count of "started" entries: entries whose status has hasBeenStarted = true
     * (Reading, On Hold, Completed, DNF — anything except TBR).
     * Used as the denominator for the finish rate.
     *
     * When $year is provided, also filters on dateFinished within that year.
     */
    public function countStarted(User $user, ?int $year = null): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(re.id)')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.hasBeenStarted = :started')
            ->setParameter('user', $user)
            ->setParameter('started', true);

        $this->applyYearFilter($qb, $year);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count of distinct works across all entries for the given user.
     * When $year is provided, counts only works from entries finished that year.
     */
    public function countUniqueWorks(User $user, ?int $year = null): int
    {
        $qb = $this->createQueryBuilder('re')
            ->select('COUNT(DISTINCT re.work)')
            ->where('re.user = :user')
            ->setParameter('user', $user);

        $this->applyYearFilter($qb, $year);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Average spiceStars for entries that have a spice rating (including 0 = no
     * spice), for the given user. Returns null when no rated entries exist.
     */
    public function getAverageSpice(User $user, ?int $year = null): ?float
    {
        $qb = $this->createQueryBuilder('re')
            ->select('AVG(re.spiceStars)')
            ->where('re.user = :user')
            ->andWhere('re.spiceStars IS NOT NULL')
            ->setParameter('user', $user);

        $this->applyYearFilter($qb, $year);

        $result = $qb->getQuery()->getSingleScalarResult();

        return $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Returns full ranking data for all metadata entries of the given type.
     * No limit is applied — returns all results; caller is responsible for sorting.
     *
     * Each row contains:
     *   - name:       metadata entry name
     *   - count:      total reading entries referencing this metadata (re-reads included)
     *   - totalWords: sum of work word counts across all matched reading entries
     *                 (NULL word counts treated as zero; re-reads multiply the word count)
     *   - readCount:  count of reading entries where status.countsAsRead = true
     *
     * Two separate queries are used (one for count+words, one for readCount) to
     * avoid relying on CASE WHEN inside aggregate functions, which is not reliably
     * portable across Doctrine DQL versions.
     *
     * IMPORTANT: The query is anchored on reading_entries WHERE user = :user to
     * prevent cross-user inflation from shared global works.
     *
     * The SoftDeleteFilter is disabled so entries referencing soft-deleted works
     * still contribute to metadata counts.
     *
     * @return array<array{name: string, count: int, totalWords: int, readCount: int}>
     */
    public function getMetadataRankings(User $user, string $typeName, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            // Query 1: entry count only (all statuses — Count # reflects all reading activity)
            $qb = $this->createQueryBuilder('re')
                ->select('m.id as mid, m.name as name, COUNT(re.id) as cnt')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->setParameter('user', $user)
                ->setParameter('typeName', $typeName)
                ->groupBy('m.id, m.name');

            $this->applyYearFilter($qb, $year);

            $mainRows = $qb->getQuery()->getArrayResult();

            // Query 2: total words — only entries where the user actually read the work
            // (hasBeenStarted = true). TBR contributes zero words; DNF counts (user read some).
            $qb2 = $this->createQueryBuilder('re')
                ->select('m.id as mid, SUM(COALESCE(w.words, 0)) as totalWords')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('typeName', $typeName)
                ->setParameter('started', true)
                ->groupBy('m.id');

            $this->applyYearFilter($qb2, $year);

            $wordsRows = $qb2->getQuery()->getArrayResult();

            // Query 3: read count (countsAsRead entries only — excludes DNF, TBR, Reading, On Hold)
            $qb3 = $this->createQueryBuilder('re')
                ->select('m.id as mid, COUNT(re.id) as readCnt')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->andWhere('s.countsAsRead = :countsAsRead')
                ->setParameter('user', $user)
                ->setParameter('typeName', $typeName)
                ->setParameter('countsAsRead', true)
                ->groupBy('m.id');

            $this->applyYearFilter($qb3, $year);

            $readRows = $qb3->getQuery()->getArrayResult();

            // Query 4: avg review stars per metadata item (NULL reviews excluded by SQL AVG)
            $qb4 = $this->createQueryBuilder('re')
                ->select('m.id as mid, AVG(re.reviewStars) as avgReview')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->setParameter('user', $user)
                ->setParameter('typeName', $typeName)
                ->groupBy('m.id');

            $this->applyYearFilter($qb4, $year);

            $reviewRows = $qb4->getQuery()->getArrayResult();

            // Query 5: started count per metadata item (hasBeenStarted = true).
            // Used as the denominator for abandon rate: of works you actually began,
            // how many did you not finish? Excludes TBR from the denominator so that
            // unstarted entries don't deflate the abandonment signal.
            $qb5 = $this->createQueryBuilder('re')
                ->select('m.id as mid, COUNT(re.id) as startedCnt')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('typeName', $typeName)
                ->setParameter('started', true)
                ->groupBy('m.id');

            $this->applyYearFilter($qb5, $year);

            $startedRows = $qb5->getQuery()->getArrayResult();

            // Index words, read counts, avg reviews, and started counts by metadata ID for O(1) lookup
            $wordTotals = [];
            foreach ($wordsRows as $row) {
                $wordTotals[(int) $row['mid']] = (int) $row['totalWords'];
            }

            $readCounts = [];
            foreach ($readRows as $row) {
                $readCounts[(int) $row['mid']] = (int) $row['readCnt'];
            }

            $avgReviews = [];
            foreach ($reviewRows as $row) {
                $avgReviews[(int) $row['mid']] = $row['avgReview'] !== null
                    ? round((float) $row['avgReview'], 2)
                    : null;
            }

            $startedCounts = [];
            foreach ($startedRows as $row) {
                $startedCounts[(int) $row['mid']] = (int) $row['startedCnt'];
            }

            return array_map(
                static fn (array $row): array => [
                    'name' => (string) $row['name'],
                    'count' => (int) $row['cnt'],
                    'totalWords' => $wordTotals[(int) $row['mid']] ?? 0,
                    'readCount' => $readCounts[(int) $row['mid']] ?? 0,
                    'avgReview' => $avgReviews[(int) $row['mid']] ?? null,
                    'startedCount' => $startedCounts[(int) $row['mid']] ?? 0,
                ],
                $mainRows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns ranking data grouped by reading entry status for the given user.
     *
     * Each row contains name, count, totalWords, and readCount.
     * readCount is derived in PHP from the status's countsAsRead flag — no
     * additional query needed because the flag is the same for all entries in
     * the same status group.
     *
     * Works without a word count (NULL) are treated as zero.
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
     *
     * @return array<array{name: string, count: int, totalWords: int, readCount: int}>
     */
    public function getStatusRankingsData(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            // Query 1: entry count per status (all entries)
            $qb = $this->createQueryBuilder('re')
                ->select('s.id as sid, s.name as name, s.countsAsRead as countsAsRead, COUNT(re.id) as cnt')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('s.id, s.name, s.countsAsRead');

            $this->applyYearFilter($qb, $year);

            $mainRows = $qb->getQuery()->getArrayResult();

            // Query 2: total words per status (hasBeenStarted = true only)
            $qb2 = $this->createQueryBuilder('re')
                ->select('s.id as sid, SUM(COALESCE(w.words, 0)) as totalWords')
                ->innerJoin('re.work', 'w')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true)
                ->groupBy('s.id');

            $this->applyYearFilter($qb2, $year);

            $wordsRows = $qb2->getQuery()->getArrayResult();

            $wordTotals = [];
            foreach ($wordsRows as $row) {
                $wordTotals[(int) $row['sid']] = (int) $row['totalWords'];
            }

            return array_map(
                static fn (array $row): array => [
                    'name' => (string) $row['name'],
                    'count' => (int) $row['cnt'],
                    'totalWords' => $wordTotals[(int) $row['sid']] ?? 0,
                    // readCount is derived: for a given status, every entry either
                    // counts as read (countsAsRead = true) or none do.
                    'readCount' => $row['countsAsRead'] ? (int) $row['cnt'] : 0,
                ],
                $mainRows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns ranking data grouped by work language for the given user.
     *
     * Works with no language set (NULL language_id) are excluded via INNER JOIN.
     * Works without a word count (NULL) are treated as zero.
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
     *
     * @return array<array{name: string, count: int, totalWords: int, readCount: int}>
     */
    public function getLanguageRankingsData(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            // Query 1: entry count per language (all entries)
            $qb = $this->createQueryBuilder('re')
                ->select('l.id as lid, l.name as name, COUNT(re.id) as cnt')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.language', 'l')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('l.id, l.name');

            $this->applyYearFilter($qb, $year);

            $mainRows = $qb->getQuery()->getArrayResult();

            // Query 2: total words per language (hasBeenStarted = true only)
            $qb2 = $this->createQueryBuilder('re')
                ->select('l.id as lid, SUM(COALESCE(w.words, 0)) as totalWords')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.language', 'l')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true)
                ->groupBy('l.id');

            $this->applyYearFilter($qb2, $year);

            $wordsRows = $qb2->getQuery()->getArrayResult();

            // Query 3: read count per language (countsAsRead = true)
            $qb3 = $this->createQueryBuilder('re')
                ->select('l.id as lid, COUNT(re.id) as readCnt')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.language', 'l')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.countsAsRead = :countsAsRead')
                ->setParameter('user', $user)
                ->setParameter('countsAsRead', true)
                ->groupBy('l.id');

            $this->applyYearFilter($qb3, $year);

            $readRows = $qb3->getQuery()->getArrayResult();

            $wordTotals = [];
            foreach ($wordsRows as $row) {
                $wordTotals[(int) $row['lid']] = (int) $row['totalWords'];
            }

            $readCounts = [];
            foreach ($readRows as $row) {
                $readCounts[(int) $row['lid']] = (int) $row['readCnt'];
            }

            return array_map(
                static fn (array $row): array => [
                    'name' => (string) $row['name'],
                    'count' => (int) $row['cnt'],
                    'totalWords' => $wordTotals[(int) $row['lid']] ?? 0,
                    'readCount' => $readCounts[(int) $row['lid']] ?? 0,
                ],
                $mainRows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns ranking row data grouped by the reading entry's mainPairing field.
     *
     * Uses INNER JOIN on re.mainPairing so entries without a main pairing are excluded.
     * Three-query pattern: count (all statuses), totalWords (hasBeenStarted), readCount (countsAsRead).
     *
     * @return array<array{name: string, count: int, totalWords: int, readCount: int}>
     */
    public function getMainPairingRankingsData(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            // Query 1: entry count per main pairing (all entries that have one set)
            $qb = $this->createQueryBuilder('re')
                ->select('mp.id as mpid, mp.name as name, COUNT(re.id) as cnt')
                ->innerJoin('re.mainPairing', 'mp')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('mp.id, mp.name');

            $this->applyYearFilter($qb, $year);

            $mainRows = $qb->getQuery()->getArrayResult();

            // Query 2: total words per main pairing (hasBeenStarted = true only)
            $qb2 = $this->createQueryBuilder('re')
                ->select('mp.id as mpid, SUM(COALESCE(w.words, 0)) as totalWords')
                ->innerJoin('re.mainPairing', 'mp')
                ->innerJoin('re.work', 'w')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true)
                ->groupBy('mp.id');

            $this->applyYearFilter($qb2, $year);

            $wordsRows = $qb2->getQuery()->getArrayResult();

            // Query 3: read count per main pairing (countsAsRead = true)
            $qb3 = $this->createQueryBuilder('re')
                ->select('mp.id as mpid, COUNT(re.id) as readCnt')
                ->innerJoin('re.mainPairing', 'mp')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.countsAsRead = :countsAsRead')
                ->setParameter('user', $user)
                ->setParameter('countsAsRead', true)
                ->groupBy('mp.id');

            $this->applyYearFilter($qb3, $year);

            $readRows = $qb3->getQuery()->getArrayResult();

            // Query 4: avg review stars per main pairing (NULL reviews excluded by SQL AVG)
            $qb4 = $this->createQueryBuilder('re')
                ->select('mp.id as mpid, AVG(re.reviewStars) as avgReview')
                ->innerJoin('re.mainPairing', 'mp')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('mp.id');

            $this->applyYearFilter($qb4, $year);

            $reviewRows = $qb4->getQuery()->getArrayResult();

            // Query 5: started count per main pairing (hasBeenStarted = true).
            // Denominator for abandon rate — excludes TBR entries.
            $qb5 = $this->createQueryBuilder('re')
                ->select('mp.id as mpid, COUNT(re.id) as startedCnt')
                ->innerJoin('re.mainPairing', 'mp')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true)
                ->groupBy('mp.id');

            $this->applyYearFilter($qb5, $year);

            $startedRows = $qb5->getQuery()->getArrayResult();

            $wordTotals = [];
            foreach ($wordsRows as $row) {
                $wordTotals[(int) $row['mpid']] = (int) $row['totalWords'];
            }

            $readCounts = [];
            foreach ($readRows as $row) {
                $readCounts[(int) $row['mpid']] = (int) $row['readCnt'];
            }

            $avgReviews = [];
            foreach ($reviewRows as $row) {
                $avgReviews[(int) $row['mpid']] = $row['avgReview'] !== null
                    ? round((float) $row['avgReview'], 2)
                    : null;
            }

            $startedCounts = [];
            foreach ($startedRows as $row) {
                $startedCounts[(int) $row['mpid']] = (int) $row['startedCnt'];
            }

            return array_map(
                static fn (array $row): array => [
                    'name' => (string) $row['name'],
                    'count' => (int) $row['cnt'],
                    'totalWords' => $wordTotals[(int) $row['mpid']] ?? 0,
                    'readCount' => $readCounts[(int) $row['mpid']] ?? 0,
                    'avgReview' => $avgReviews[(int) $row['mpid']] ?? null,
                    'startedCount' => $startedCounts[(int) $row['mpid']] ?? 0,
                ],
                $mainRows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns the names of metadata types for which the user has at least one
     * reading entry (through the work → works_metadata → metadata chain).
     * Used to populate the rankings link section on the dashboard.
     *
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
     *
     * @return string[]
     */
    public function findAvailableMetadataTypeNames(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            $qb = $this->createQueryBuilder('re')
                ->select('mt.name as typeName')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('mt.id, mt.name')
                ->orderBy('mt.name', 'ASC');

            $this->applyYearFilter($qb, $year);

            $rows = $qb->getQuery()->getArrayResult();

            return array_column($rows, 'typeName');
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns ranking data grouped by Author metadata for the given user.
     *
     * Uses four queries merged by author ID in PHP:
     *   Q1: entry count + avg review stars per author (all statuses)
     *   Q2: total words + total chapters per author (hasBeenStarted = true)
     *   Q3: read count + read-in-words per author (countsAsRead = true)
     *   Q4: distinct fandom names per author (DBAL — requires two joins to works_metadata)
     * Plus one DBAL lookup for AO3 profile links (metadata_source_links, year-independent).
     *
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
     * DBAL queries bypass the ORM filter; soft-deleted works are included by default.
     *
     * @return array<array{
     *   mid: int,
     *   name: string,
     *   ao3Link: string|null,
     *   count: int,
     *   totalWords: int,
     *   totalChapters: int,
     *   read: int,
     *   readInWords: int,
     *   avgReview: float|null,
     *   fandoms: string[],
     * }>
     */
    public function getAuthorRankingsData(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            // Q1: entry count + avg review per author (all statuses)
            $qb1 = $this->createQueryBuilder('re')
                ->select('m.id as mid, m.name as name, COUNT(re.id) as cnt, AVG(re.reviewStars) as avgReview')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->setParameter('user', $user)
                ->setParameter('typeName', 'Author')
                ->groupBy('m.id, m.name');

            $this->applyYearFilter($qb1, $year);

            $mainRows = $qb1->getQuery()->getArrayResult();

            // Q2: total words + chapters per author (hasBeenStarted = true only)
            $qb2 = $this->createQueryBuilder('re')
                ->select('m.id as mid, SUM(COALESCE(w.words, 0)) as totalWords, SUM(COALESCE(w.chapters, 0)) as totalChapters')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->andWhere('s.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('typeName', 'Author')
                ->setParameter('started', true)
                ->groupBy('m.id');

            $this->applyYearFilter($qb2, $year);

            $wordsRows = $qb2->getQuery()->getArrayResult();

            // Q3: read count + read-in-words per author (countsAsRead = true only)
            $qb3 = $this->createQueryBuilder('re')
                ->select('m.id as mid, COUNT(re.id) as readCnt, SUM(COALESCE(w.words, 0)) as readInWords')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.metadata', 'm')
                ->innerJoin('m.metadataType', 'mt')
                ->innerJoin('re.status', 's')
                ->where('re.user = :user')
                ->andWhere('mt.name = :typeName')
                ->andWhere('s.countsAsRead = :countsAsRead')
                ->setParameter('user', $user)
                ->setParameter('typeName', 'Author')
                ->setParameter('countsAsRead', true)
                ->groupBy('m.id');

            $this->applyYearFilter($qb3, $year);

            $readRows = $qb3->getQuery()->getArrayResult();

            // Q4 (DBAL): AO3 profile links — year-independent (a profile link is not
            // time-scoped; we look up all Author metadata_source_links in one query).
            $conn = $em->getConnection();
            $ao3Rows = $conn->executeQuery(
                'SELECT msl.metadata_id, msl.link
                   FROM metadata_source_links msl
                   INNER JOIN metadata m ON msl.metadata_id = m.id
                   INNER JOIN metadata_types mt ON m.metadata_type_id = mt.id
                  WHERE mt.name = :authorType AND msl.source_type = :sourceType',
                ['authorType' => 'Author', 'sourceType' => \App\Enum\SourceType::AO3->value],
            )->fetchAllAssociative();

            // Q5 (DBAL): distinct fandoms per author, year-scoped.
            // Requires two joins to works_metadata (once for Author, once for Fandom),
            // which cannot be expressed cleanly in a single DQL query.
            $fandomSql = '
                SELECT m_author.id AS authorId, m_fandom.name AS fandomName
                  FROM reading_entries re
                  INNER JOIN works w ON re.work_id = w.id
                  INNER JOIN works_metadata wm_a ON wm_a.work_id = w.id
                  INNER JOIN metadata m_author ON wm_a.metadata_id = m_author.id
                  INNER JOIN metadata_types mt_author ON m_author.metadata_type_id = mt_author.id
                  INNER JOIN works_metadata wm_f ON wm_f.work_id = w.id
                  INNER JOIN metadata m_fandom ON wm_f.metadata_id = m_fandom.id
                  INNER JOIN metadata_types mt_fandom ON m_fandom.metadata_type_id = mt_fandom.id
                 WHERE re.user_id = :userId
                   AND mt_author.name = :authorType
                   AND mt_fandom.name = :fandomType';

            $fandomParams = [
                'userId'     => $user->getId(),
                'authorType' => 'Author',
                'fandomType' => 'Fandom',
            ];

            if ($year !== null) {
                $fandomSql .= ' AND re.date_finished >= :yearStart AND re.date_finished <= :yearEnd';
                $fandomParams['yearStart'] = "$year-01-01";
                $fandomParams['yearEnd']   = "$year-12-31";
            }

            $fandomSql .= ' GROUP BY m_author.id, m_fandom.name ORDER BY m_author.id ASC, m_fandom.name ASC';

            $fandomRows = $conn->executeQuery($fandomSql, $fandomParams)->fetchAllAssociative();

            // Index all secondary results by author ID for O(1) lookup
            $wordTotals    = [];
            $chapterTotals = [];
            foreach ($wordsRows as $row) {
                $wordTotals[(int) $row['mid']]    = (int) $row['totalWords'];
                $chapterTotals[(int) $row['mid']] = (int) $row['totalChapters'];
            }

            $readCounts  = [];
            $readInWords = [];
            foreach ($readRows as $row) {
                $readCounts[(int) $row['mid']]  = (int) $row['readCnt'];
                $readInWords[(int) $row['mid']] = (int) $row['readInWords'];
            }

            $ao3Links = [];
            foreach ($ao3Rows as $row) {
                $ao3Links[(int) $row['metadata_id']] = (string) $row['link'];
            }

            $fandoms = [];
            foreach ($fandomRows as $row) {
                $fandoms[(int) $row['authorId']][] = (string) $row['fandomName'];
            }

            return array_map(
                static fn (array $row): array => [
                    'mid'           => (int) $row['mid'],
                    'name'          => (string) $row['name'],
                    'ao3Link'       => $ao3Links[(int) $row['mid']] ?? null,
                    'count'         => (int) $row['cnt'],
                    'totalWords'    => $wordTotals[(int) $row['mid']] ?? 0,
                    'totalChapters' => $chapterTotals[(int) $row['mid']] ?? 0,
                    'read'          => $readCounts[(int) $row['mid']] ?? 0,
                    'readInWords'   => $readInWords[(int) $row['mid']] ?? 0,
                    'avgReview'     => $row['avgReview'] !== null ? round((float) $row['avgReview'], 2) : null,
                    'fandoms'       => $fandoms[(int) $row['mid']] ?? [],
                ],
                $mainRows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    /**
     * Returns ranking data grouped by series for the given user.
     *
     * Uses two ORM queries merged by series ID in PHP, plus one DBAL lookup
     * for series metadata (numberOfParts, totalWords, isComplete, AO3 link):
     *   Q1: entry count + avg review per series (all statuses, year-filtered)
     *   Q2: works read (COUNT DISTINCT) + words read per series (countsAsRead=true, year-filtered)
     *   Q3 (DBAL): series.numberOfParts, series.totalWords, series.isComplete, AO3 link
     *
     * The SoftDeleteFilter is disabled so soft-deleted works still contribute.
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
    public function getSeriesRankingsData(User $user, ?int $year = null): array
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $softDeleteEnabled = $filters->isEnabled('soft_delete');
        if ($softDeleteEnabled) {
            $filters->disable('soft_delete');
        }

        try {
            // Q1: entry count + avg review per series (all statuses)
            $qb1 = $this->createQueryBuilder('re')
                ->select('s.id as sid, s.name as name, COUNT(re.id) as cnt, AVG(re.reviewStars) as avgReview')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.series', 's')
                ->where('re.user = :user')
                ->setParameter('user', $user)
                ->groupBy('s.id, s.name');

            $this->applyYearFilter($qb1, $year);

            $mainRows = $qb1->getQuery()->getArrayResult();

            if ($mainRows === []) {
                return [];
            }

            // Q2: works read (distinct works completed) + words read (countsAsRead=true)
            $qb2 = $this->createQueryBuilder('re')
                ->select('s.id as sid, COUNT(DISTINCT w.id) as worksRead, SUM(COALESCE(w.words, 0)) as wordsRead')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.series', 's')
                ->innerJoin('re.status', 'st')
                ->where('re.user = :user')
                ->andWhere('st.countsAsRead = :countsAsRead')
                ->setParameter('user', $user)
                ->setParameter('countsAsRead', true)
                ->groupBy('s.id');

            $this->applyYearFilter($qb2, $year);

            $readRows = $qb2->getQuery()->getArrayResult();

            // Q4: coverage words — sum of words for distinct works the user has ever started in each series,
            // year-independent (all-time coverage, regardless of the active year filter)
            $qb4 = $this->createQueryBuilder('re')
                ->select('s.id as sid, w.id as wid, w.words as wwords')
                ->innerJoin('re.work', 'w')
                ->innerJoin('w.series', 's')
                ->innerJoin('re.status', 'st')
                ->where('re.user = :user')
                ->andWhere('st.hasBeenStarted = :started')
                ->setParameter('user', $user)
                ->setParameter('started', true)
                ->groupBy('s.id, w.id');

            $coverageRows = $qb4->getQuery()->getArrayResult();

            // Sum per-work words by series in PHP to get total words covered
            $coverageMap = [];
            foreach ($coverageRows as $row) {
                $sid = (int) $row['sid'];
                $coverageMap[$sid] = ($coverageMap[$sid] ?? 0) + (int) ($row['wwords'] ?? 0);
            }

            // Q3 (DBAL): series metadata + AO3 link — year-independent (static data)
            $seriesIds = array_map(static fn (array $r): int => (int) $r['sid'], $mainRows);
            $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
            $conn = $em->getConnection();
            $metaRows = $conn->executeQuery(
                "SELECT s.id, s.number_of_parts, s.total_words, s.is_complete, ssl.link as ao3Link
                   FROM series s
                   LEFT JOIN series_source_links ssl ON ssl.series_id = s.id AND ssl.source_type = ?
                  WHERE s.id IN ($placeholders)",
                array_merge([\App\Enum\SourceType::AO3->value], $seriesIds),
            )->fetchAllAssociative();

            // Index secondary results by series ID for O(1) lookup
            $readWorksMap = [];
            $readWordsMap = [];
            foreach ($readRows as $row) {
                $readWorksMap[(int) $row['sid']] = (int) $row['worksRead'];
                $readWordsMap[(int) $row['sid']] = (int) $row['wordsRead'];
            }

            $metaMap = [];
            foreach ($metaRows as $row) {
                $metaMap[(int) $row['id']] = [
                    'totalWorks' => $row['number_of_parts'] !== null ? (int) $row['number_of_parts'] : null,
                    'totalWords' => $row['total_words'] !== null ? (int) $row['total_words'] : null,
                    'isComplete' => $row['is_complete'] !== null ? (bool) $row['is_complete'] : null,
                    'ao3Link'    => $row['ao3Link'] !== null ? (string) $row['ao3Link'] : null,
                ];
            }

            return array_map(
                static function (array $row) use ($readWorksMap, $readWordsMap, $coverageMap, $metaMap): array {
                    $sid  = (int) $row['sid'];
                    $meta = $metaMap[$sid] ?? ['totalWorks' => null, 'totalWords' => null, 'isComplete' => null, 'ao3Link' => null];

                    return [
                        'sid'           => $sid,
                        'name'          => (string) $row['name'],
                        'ao3Link'       => $meta['ao3Link'],
                        'count'         => (int) $row['cnt'],
                        'worksRead'     => $readWorksMap[$sid] ?? 0,
                        'totalWorks'    => $meta['totalWorks'],
                        'wordsRead'     => $readWordsMap[$sid] ?? 0,
                        'coverageWords' => $coverageMap[$sid] ?? 0,
                        'totalWords'    => $meta['totalWords'],
                        'avgReview'     => $row['avgReview'] !== null ? round((float) $row['avgReview'], 2) : null,
                        'isComplete'    => $meta['isComplete'],
                    ];
                },
                $mainRows,
            );
        } finally {
            if ($softDeleteEnabled) {
                $filters->enable('soft_delete');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the average reading pace (days from dateStarted to dateFinished)
     * for completed entries (countsAsRead = true) where both dates are set.
     *
     * Date diff is computed in PHP for cross-database portability (SQLite,
     * MySQL, and PostgreSQL each use different date-diff functions).
     * Negative diffs (data entry errors where start > finish) are silently
     * skipped. Same-day reads count as 0 days.
     *
     * @return array{averageDays: float|null, entryCount: int}
     */
    public function getReadingPaceStats(User $user, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('re')
            ->select('re.dateStarted, re.dateFinished')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('re.dateStarted IS NOT NULL')
            ->andWhere('re.dateFinished IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true);

        $this->applyYearFilter($qb, $year);

        $rows = $qb->getQuery()->getArrayResult();

        $totalDays = 0;
        $count = 0;
        foreach ($rows as $row) {
            /** @var \DateTimeInterface $start */
            $start = $row['dateStarted'];
            /** @var \DateTimeInterface $finish */
            $finish = $row['dateFinished'];
            $diff = (int) $finish->diff($start)->days;
            // diff()->days is always non-negative; check the sign via invert flag
            if ($start->diff($finish)->invert === 1) {
                // start is after finish — data entry error, skip
                continue;
            }
            $totalDays += $diff;
            $count++;
        }

        return [
            'averageDays' => $count > 0 ? round($totalDays / $count, 1) : null,
            'entryCount'  => $count,
        ];
    }

    /**
     * Applies a year filter on dateFinished to a QueryBuilder.
     * When $year is null this is a no-op (all-time view).
     */
    private function applyYearFilter(QueryBuilder $qb, ?int $year): void
    {
        if ($year === null) {
            return;
        }

        // Types::DATE_IMMUTABLE ensures Doctrine serializes the boundary as 'Y-m-d'
        // (not 'Y-m-d H:i:s'), so SQLite string comparison correctly includes
        // entries on Jan 1 and Dec 31 of the selected year.
        $qb->andWhere('re.dateFinished >= :yearStart')
            ->andWhere('re.dateFinished <= :yearEnd')
            ->setParameter('yearStart', new \DateTimeImmutable("$year-01-01"), Types::DATE_IMMUTABLE)
            ->setParameter('yearEnd', new \DateTimeImmutable("$year-12-31"), Types::DATE_IMMUTABLE);
    }

    private function applyFilters(QueryBuilder $qb, array $filterParams): void
    {
        if (!empty($filterParams['status'])) {
            $qb->andWhere('re.status = :filter_status')
                ->setParameter('filter_status', (int) $filterParams['status']);
        }

        if (!empty($filterParams['q'])) {
            $qb->andWhere('w.title LIKE :filter_q')
                ->setParameter('filter_q', '%' . $filterParams['q'] . '%');
        }

        if (!empty($filterParams['author'])) {
            // Join through the works_metadata junction to filter by Author metadata name.
            // DISTINCT is used on the caller side to avoid duplicate rows when a work
            // has multiple metadata entries matching the author pattern.
            $qb->innerJoin('w.metadata', 'm_author')
                ->innerJoin('m_author.metadataType', 'mt_author')
                ->andWhere('mt_author.name = :author_type')
                ->andWhere('m_author.name LIKE :filter_author')
                ->setParameter('author_type', 'Author')
                ->setParameter('filter_author', '%' . $filterParams['author'] . '%');
        }

        if (isset($filterParams['pinned']) && $filterParams['pinned'] !== '') {
            $qb->andWhere('re.pinned = :filter_pinned')
                ->setParameter('filter_pinned', (bool) $filterParams['pinned']);
        }

        if (!empty($filterParams['rating'])) {
            $qb->andWhere('re.reviewStars = :filter_rating')
                ->setParameter('filter_rating', (int) $filterParams['rating']);
        }

        if (!empty($filterParams['dateFrom'])) {
            // Types::DATE_IMMUTABLE ensures Doctrine serializes as 'Y-m-d' (not 'Y-m-d H:i:s'),
            // so SQLite string comparison correctly includes entries on the boundary date.
            // Invalid date strings (e.g. hand-edited URLs) are silently ignored — no filter applied.
            try {
                $qb->andWhere('re.dateFinished >= :filter_date_from')
                    ->setParameter('filter_date_from', new \DateTimeImmutable($filterParams['dateFrom']), Types::DATE_IMMUTABLE);
            } catch (\Exception) {
                // Silently skip — returning unfiltered results is the safe default for bad input.
            }
        }

        if (!empty($filterParams['dateTo'])) {
            // Same reasoning as dateFrom above.
            try {
                $qb->andWhere('re.dateFinished <= :filter_date_to')
                    ->setParameter('filter_date_to', new \DateTimeImmutable($filterParams['dateTo']), Types::DATE_IMMUTABLE);
            } catch (\Exception) {
                // Silently skip — returning unfiltered results is the safe default for bad input.
            }
        }

        // Exact spice match used by chart drill-down links (always exact regardless of value).
        // In practice only one of spiceExact/spice will be set at a time since spiceExact
        // is never written by the filter form.
        if (isset($filterParams['spiceExact']) && $filterParams['spiceExact'] !== '') {
            $qb->andWhere('re.spiceStars = :filter_spice_exact')
                ->setParameter('filter_spice_exact', (int) $filterParams['spiceExact']);
        }

        // Spice stars: 0 is a valid value so check !== '' rather than !empty.
        // spice=0 is an exact match (no-spice entries only).
        // spice=1–5 is a minimum (entries at or above that level), matching how
        // the review filter works. The two cases are intentionally different:
        // 0 represents a categorical absence of spice, not a point on the scale.
        if (isset($filterParams['spice']) && $filterParams['spice'] !== '') {
            $spice = (int) $filterParams['spice'];
            if ($spice === 0) {
                $qb->andWhere('re.spiceStars = :filter_spice')
                    ->setParameter('filter_spice', 0);
            } else {
                $qb->andWhere('re.spiceStars >= :filter_spice')
                    ->setParameter('filter_spice', $spice);
            }
        }

        if (!empty($filterParams['type'])) {
            // Validate against the WorkType enum to silently ignore invalid values.
            // The 'w' alias for Work is always joined before applyFilters is called.
            $workType = \App\Enum\WorkType::tryFrom($filterParams['type']);
            if ($workType !== null) {
                $qb->andWhere('w.type = :filter_type')
                    ->setParameter('filter_type', $workType);
            }
        }

        if (!empty($filterParams['language'])) {
            // Filter by work language name. Drill-down from the Language rankings page.
            $qb->innerJoin('w.language', 'lang_filter')
                ->andWhere('lang_filter.name = :filter_language')
                ->setParameter('filter_language', $filterParams['language']);
        }

        if (!empty($filterParams['series'])) {
            // Filter by series ID. Used by the Series rankings drill-down links.
            $qb->andWhere('w.series = :filter_series')
                ->setParameter('filter_series', (int) $filterParams['series']);
        }

        if (!empty($filterParams['mainPairing'])) {
            // Filter by the reading entry's mainPairing name. Used by both drill-down links
            // from the Main Pairing rankings page and the filter form's text input.
            // LIKE match so partial names (typed in the form) work alongside exact drill-down values.
            $qb->innerJoin('re.mainPairing', 'mp_filter')
                ->andWhere('mp_filter.name LIKE :filter_main_pairing')
                ->setParameter('filter_main_pairing', '%' . $filterParams['mainPairing'] . '%');
        }

        // wordsMin / wordsMax: filter by work word count range (used by chart drill-downs).
        // NULL word counts are naturally excluded by the comparison (NULL >= N is falsy in SQL).
        if (isset($filterParams['wordsMin']) && $filterParams['wordsMin'] !== '') {
            $qb->andWhere('w.words >= :filter_words_min')
                ->setParameter('filter_words_min', (int) $filterParams['wordsMin']);
        }

        if (isset($filterParams['wordsMax']) && $filterParams['wordsMax'] !== '') {
            $qb->andWhere('w.words <= :filter_words_max')
                ->setParameter('filter_words_max', (int) $filterParams['wordsMax']);
        }

        // metadata[] array: each key is a metadata type name, each value is the filter string.
        // One JOIN per active type filter using indexed aliases to avoid alias conflicts.
        // e.g. metadata[Fandom]=Harry+Potter&metadata[Warning]=Violence → two inner joins.
        if (!empty($filterParams['metadata']) && is_array($filterParams['metadata'])) {
            $i = 0;
            foreach ($filterParams['metadata'] as $typeName => $value) {
                if ($value === '') {
                    continue;
                }
                $metaAlias   = 'm_meta_' . $i;
                $mtAlias     = 'mt_meta_' . $i;
                $qb->innerJoin('w.metadata', $metaAlias)
                    ->innerJoin("$metaAlias.metadataType", $mtAlias)
                    ->andWhere("$mtAlias.name = :filter_meta_type_$i")
                    ->andWhere("$metaAlias.name LIKE :filter_meta_name_$i")
                    ->setParameter("filter_meta_type_$i", $typeName)
                    ->setParameter("filter_meta_name_$i", '%' . $value . '%');
                ++$i;
            }
        }
    }

    // =========================================================================
    // Achievement query methods
    // All methods below are used exclusively by AchievementService.
    // "Finished" means status.countsAsRead = true unless otherwise noted.
    // The SoftDeleteFilter is not disabled here — achievement calculations
    // intentionally exclude soft-deleted works (they no longer represent
    // active content the user completed).
    // =========================================================================

    /**
     * COUNT(DISTINCT metadata) of the given type across all finished entries.
     * Used for unique-fandoms and unique-authors achievement conditions.
     */
    public function countDistinctMetadataForFinished(User $user, string $typeName): int
    {
        $result = $this->createQueryBuilder('re')
            ->select('COUNT(DISTINCT m.id)')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->innerJoin('w.metadata', 'm')
            ->innerJoin('m.metadataType', 'mt')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('mt.name = :typeName')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->setParameter('typeName', $typeName)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * COUNT(DISTINCT work.language) across all finished entries.
     * Used for the unique-languages achievement condition.
     */
    public function countDistinctLanguagesForFinished(User $user): int
    {
        $result = $this->createQueryBuilder('re')
            ->select('COUNT(DISTINCT w.language)')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('w.language IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Returns true if the user has at least one finished entry whose work has
     * >= $minWords words. Used for long-work achievement conditions.
     */
    public function hasFinishedWorkWithMinWords(User $user, int $minWords): bool
    {
        $result = $this->createQueryBuilder('re')
            ->select('COUNT(re.id)')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('w.words >= :minWords')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->setParameter('minWords', $minWords)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result > 0;
    }

    /**
     * COUNT of entries where reviewStars IS NOT NULL.
     * Used for rated-count achievement conditions.
     */
    public function countRated(User $user): int
    {
        return (int) $this->createQueryBuilder('re')
            ->select('COUNT(re.id)')
            ->where('re.user = :user')
            ->andWhere('re.reviewStars IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns the dateFinished (or createdAt fallback) of the Nth finished entry,
     * ordered by dateFinished ASC. Used to derive historical unlock dates for
     * finished-count achievements.
     *
     * Returns null if fewer than N finished entries exist.
     */
    public function getNthFinishedEntryDate(User $user, int $n): ?\DateTimeImmutable
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->setFirstResult($n - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        // dateFinished may be null if user didn't record a finish date; fall back to createdAt
        $dateStr = $row['dateFinished'] ?? $row['createdAt'];

        if ($dateStr === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $dateStr);
    }

    /**
     * Returns all finished entries with their work's word count, ordered by
     * dateFinished ASC (then createdAt for stability). Used by AchievementService
     * to compute running word-sum milestones in PHP.
     *
     * @return array<int, array{dateFinished: string|null, createdAt: string, words: int|null}>
     */
    public function getFinishedEntriesWithWordsOrderedByDate(User $user): array
    {
        /** @var array<int, array{dateFinished: string|null, createdAt: string, words: int|null}> */
        return $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt', 'w.words')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.hasBeenStarted = :started')
            ->setParameter('user', $user)
            ->setParameter('started', true)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Returns finished entries with metadata of the given type, ordered by
     * dateFinished ASC. Each row has dateFinished, createdAt, and metadataId.
     * Used by AchievementService to compute unique-metadata milestones in PHP.
     *
     * @return array<int, array{dateFinished: string|null, createdAt: string, metadataId: int}>
     */
    public function getFinishedEntriesWithMetadataOrderedByDate(User $user, string $typeName): array
    {
        /** @var array<int, array{dateFinished: string|null, createdAt: string, metadataId: int}> */
        return $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt', 'm.id AS metadataId')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->innerJoin('w.metadata', 'm')
            ->innerJoin('m.metadataType', 'mt')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('mt.name = :typeName')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->setParameter('typeName', $typeName)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Returns finished entries with their work's language, ordered by dateFinished ASC.
     * Each row has dateFinished, createdAt, and languageId.
     * Used by AchievementService to compute unique-language milestones in PHP.
     *
     * @return array<int, array{dateFinished: string|null, createdAt: string, languageId: int|null}>
     */
    public function getFinishedEntriesWithLanguageOrderedByDate(User $user): array
    {
        /** @var array<int, array{dateFinished: string|null, createdAt: string, languageId: int|null}> */
        return $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt', 'IDENTITY(w.language) AS languageId')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('w.language IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Returns the dateFinished of the first "second finished read" of any work
     * for this user — i.e. the earliest re-read completion date.
     *
     * Strategy: for each work with >= 2 finished entries, rank the entries by
     * dateFinished ASC. The re-read date is the dateFinished of rank 2 (the
     * second time they finished that work). We return the minimum across all works.
     *
     * Computed in PHP for cross-DB portability (avoids window functions).
     */
    public function getDateOfFirstReread(User $user): ?\DateTimeImmutable
    {
        // Fetch (work_id, dateFinished, createdAt) for all finished entries,
        // ordered consistently. Group in PHP.
        $rows = $this->createQueryBuilder('re')
            ->select('IDENTITY(re.work) AS workId', 're.dateFinished', 're.createdAt')
            ->innerJoin('re.status', 's')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->getQuery()
            ->getScalarResult();

        // Group by workId, collect ordered dateFinished values
        $byWork = [];
        foreach ($rows as $row) {
            $byWork[(int) $row['workId']][] = $row['dateFinished'] ?? $row['createdAt'];
        }

        $firstRereadDate = null;
        foreach ($byWork as $dates) {
            if (count($dates) < 2) {
                continue;
            }
            // Index 1 = second finished read (0-based)
            $secondDate = $dates[1];
            if ($secondDate === null) {
                continue;
            }
            $dt = new \DateTimeImmutable((string) $secondDate);
            if ($firstRereadDate === null || $dt < $firstRereadDate) {
                $firstRereadDate = $dt;
            }
        }

        return $firstRereadDate;
    }

    /**
     * Returns the dateFinished of the first finished entry whose work has
     * >= $minWords words. Returns null if no such entry exists.
     */
    public function getDateOfFirstLongWorkFinished(User $user, int $minWords): ?\DateTimeImmutable
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt')
            ->innerJoin('re.status', 's')
            ->innerJoin('re.work', 'w')
            ->where('re.user = :user')
            ->andWhere('s.countsAsRead = :countsAsRead')
            ->andWhere('w.words >= :minWords')
            ->setParameter('user', $user)
            ->setParameter('countsAsRead', true)
            ->setParameter('minWords', $minWords)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();

        if (empty($rows)) {
            return null;
        }

        $dateStr = $rows[0]['dateFinished'] ?? $rows[0]['createdAt'];

        return $dateStr !== null ? new \DateTimeImmutable((string) $dateStr) : null;
    }

    /**
     * Returns the dateFinished (or createdAt fallback) of the Nth entry with
     * a non-null reviewStars, ordered by dateFinished ASC.
     * Used to derive historical unlock dates for rated-count achievements.
     */
    public function getNthRatedEntryDate(User $user, int $n): ?\DateTimeImmutable
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt')
            ->where('re.user = :user')
            ->andWhere('re.reviewStars IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->setFirstResult($n - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();

        if (empty($rows)) {
            return null;
        }

        $dateStr = $rows[0]['dateFinished'] ?? $rows[0]['createdAt'];

        return $dateStr !== null ? new \DateTimeImmutable((string) $dateStr) : null;
    }

    /**
     * Returns the dateFinished (or createdAt fallback) of the Nth pinned entry,
     * ordered by dateFinished ASC.
     * Used to derive historical unlock dates for pinned-count achievements.
     */
    public function getNthPinnedEntryDate(User $user, int $n): ?\DateTimeImmutable
    {
        $rows = $this->createQueryBuilder('re')
            ->select('re.dateFinished', 're.createdAt')
            ->where('re.user = :user')
            ->andWhere('re.pinned = :pinned')
            ->setParameter('user', $user)
            ->setParameter('pinned', true)
            ->orderBy('re.dateFinished', 'ASC')
            ->addOrderBy('re.createdAt', 'ASC')
            ->setFirstResult($n - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();

        if (empty($rows)) {
            return null;
        }

        $dateStr = $rows[0]['dateFinished'] ?? $rows[0]['createdAt'];

        return $dateStr !== null ? new \DateTimeImmutable((string) $dateStr) : null;
    }
}
