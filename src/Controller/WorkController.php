<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\WorkFormDto;
use App\Entity\MetadataType;
use App\Form\WorkFormType;
use App\Repository\MetadataRepository;
use App\Repository\MetadataTypeRepository;
use App\Repository\WorkRepository;
use App\Scraper\Ao3Scraper;
use App\Scraper\AuthRequiredException;
use App\Scraper\RateLimitException;
use App\Scraper\ScrapedWorkDto;
use App\Scraper\ScraperRegistry;
use App\Scraper\ScrapingException;
use App\Service\ImportService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use App\Service\WorkService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/work')]
#[IsGranted('ROLE_USER')]
class WorkController extends AbstractController
{
    public function __construct(
        private readonly WorkService $workService,
        private readonly WorkRepository $workRepository,
        private readonly ImportService $importService,
        private readonly MetadataTypeRepository $metadataTypeRepository,
        private readonly MetadataRepository $metadataRepository,
        private readonly ScraperRegistry $scraperRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Step 1a: Create a new Work, then redirect to creating a ReadingEntry for it.
     */
    #[Route('/new', name: 'app_work_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dto = $this->consumeImportSession($request);
        $form = $this->createForm(WorkFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $work = $this->workService->createWork($dto);
                $this->addFlash('success', 'work.created');

                return $this->redirectToRoute('app_reading_entry_new', ['workId' => $work->getId()]);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('work/new.html.twig', $this->buildWorkFormViewData($form, $dto));
    }

    /**
     * Edit an existing Work's metadata.
     */
    #[Route('/{id}/edit', name: 'app_work_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $work = $this->workRepository->findWithAllRelations($id);
        if ($work === null) {
            throw $this->createNotFoundException();
        }

        $dto = $this->workService->workToFormDto($work);
        $form = $this->createForm(WorkFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->workService->updateWork($work, $dto);
                $this->addFlash('success', 'work.updated');

                return $this->redirectToRoute('app_work_show', ['id' => $id]);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('work/edit.html.twig', array_merge(
            $this->buildWorkFormViewData($form, $dto),
            ['work' => $work],
        ));
    }

    /**
     * Builds the template variables shared by the new and edit work form views.
     * Handles metadata type sorting, checkbox pre-population, and chip index building.
     *
     * @return array<string, mixed>
     */
    private function buildWorkFormViewData(FormInterface $form, WorkFormDto $dto): array
    {
        $authorType = $this->workService->findOrCreateAuthorType();

        $metadataTypes = $this->metadataTypeRepository->createQueryBuilder('mt')
            ->where('mt.name != :author')
            ->setParameter('author', 'Author')
            ->getQuery()
            ->getResult();

        usort($metadataTypes, static function (MetadataType $a, MetadataType $b): int {
            $order = MetadataType::DISPLAY_ORDER;
            $posA = array_search($a->getName(), $order, true);
            $posB = array_search($b->getName(), $order, true);
            $posA = $posA === false ? PHP_INT_MAX : $posA;
            $posB = $posB === false ? PHP_INT_MAX : $posB;

            return $posA !== $posB ? $posA <=> $posB : strcmp($a->getName(), $b->getName());
        });

        $checkboxTypes = array_values(array_filter(
            $metadataTypes,
            static fn (MetadataType $t) => $t->isShowAsCheckboxes(),
        ));

        $checkboxOptions = $this->metadataRepository->findCheckboxOptionsByTypes($checkboxTypes);

        $knownCheckboxNames = [];
        foreach ($checkboxOptions as $typeId => $options) {
            $knownCheckboxNames[$typeId] = array_map(static fn ($o) => $o['name'], $options);
        }

        $preselectedCheckboxNames = $this->workService->resolveCheckboxPreselections(
            $dto->metadata,
            $checkboxTypes,
        );

        $checkboxTypeIds = array_map(static fn (MetadataType $t) => $t->getId(), $checkboxTypes);
        $dto->metadata = array_values(array_filter(
            $dto->metadata,
            static fn (array $entry) => !in_array(
                $entry['metadataType']->getId(),
                $checkboxTypeIds,
                true,
            ),
        ));

        $metadataByType = [];
        $metaIndex = 0;
        foreach ($dto->metadata as $entry) {
            $typeId = $entry['metadataType']->getId();
            if ($typeId === null) {
                continue;
            }
            $metadataByType[$typeId][] = [
                'index' => $metaIndex,
                'name'  => $entry['name'],
                'link'  => $entry['link'] ?? '',
            ];
            $metaIndex++;
        }

        $authorChips = [];
        foreach ($dto->authors as $i => $author) {
            $authorChips[] = [
                'index' => $i,
                'name'  => $author['name'],
                'link'  => $author['link'] ?? '',
            ];
        }

        return [
            'form'                     => $form,
            'dto'                      => $dto,
            'metadataTypes'            => $metadataTypes,
            'checkboxOptions'          => $checkboxOptions,
            'knownCheckboxNames'       => $knownCheckboxNames,
            'preselectedCheckboxNames' => $preselectedCheckboxNames,
            'metadataByType'           => $metadataByType,
            'totalMetadataIndex'       => $metaIndex,
            'authorTypeId'             => $authorType->getId(),
            'authorChips'              => $authorChips,
        ];
    }

    /**
     * Reads a ScrapedWorkDto from the session (if present), maps it to a WorkFormDto,
     * flashes any mapping warnings, and clears the session key.
     * Returns a blank WorkFormDto if no import data is in the session.
     */
    private function consumeImportSession(Request $request): WorkFormDto
    {
        $session = $request->getSession();
        $scraped = $session->get('import_scraped_work');

        if (!($scraped instanceof ScrapedWorkDto)) {
            return new WorkFormDto();
        }

        $session->remove('import_scraped_work');
        $session->remove('import_duplicate_work_id');

        $result = $this->importService->mapToWorkFormDto($scraped);

        foreach ($result->warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $result->dto;
    }

    /**
     * Re-scrapes a Work's source URL and updates its metadata in place.
     * Only works that have a source URL pointing to a supported scraper can be refreshed.
     */
    #[Route('/{id}/refresh', name: 'app_work_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refresh(int $id, Request $request): Response
    {
        $work = $this->workRepository->find($id);
        if ($work === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('refresh_work_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $link = $work->getLink();
        if ($link === null) {
            $this->addFlash('error', 'work.refresh.error.no_link');

            return $this->redirectToRoute('app_work_show', ['id' => $id]);
        }

        $scraper = $this->scraperRegistry->getScraperForUrl($link);
        if ($scraper === null) {
            $this->addFlash('error', 'work.refresh.error.unsupported_url');

            return $this->redirectToRoute('app_work_show', ['id' => $id]);
        }

        try {
            $scraped = $scraper->scrape($link);
        } catch (RateLimitException $e) {
            $this->logger->warning('Work refresh rate limited', [
                'work_id'       => $id,
                'url'           => $link,
                'retry_after'   => $e->getRetryAfterSeconds(),
            ]);
            $this->addFlash('error', 'work.refresh.error.rate_limited');

            return $this->redirectToRoute('app_work_show', ['id' => $id]);
        } catch (AuthRequiredException $e) {
            $this->logger->warning('Work refresh requires AO3 authentication', [
                'work_id' => $id,
                'url'     => $link,
                'error'   => $e->getMessage(),
            ]);
            $this->addFlash('error', 'work.refresh.error.auth_required');

            return $this->redirectToRoute('app_work_show', ['id' => $id]);
        } catch (ScrapingException $e) {
            $this->logger->error('Work refresh scrape failed', [
                'work_id'     => $id,
                'url'         => $link,
                'http_status' => $e->getHttpStatus(),
                'error'       => $e->getMessage(),
            ]);
            $this->addFlash('error', 'work.refresh.error.scrape_failed');

            return $this->redirectToRoute('app_work_show', ['id' => $id]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Work refresh connection failed', [
                'work_id' => $id,
                'url'     => $link,
                'error'   => $e->getMessage(),
            ]);
            $this->addFlash('error', 'work.refresh.error.connection_failed');

            return $this->redirectToRoute('app_work_show', ['id' => $id]);
        }

        $result = $this->importService->mapToWorkFormDto($scraped);
        foreach ($result->warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        try {
            $this->workService->refreshWork($work, $result->dto);
            $this->addFlash('success', 'work.refresh.success');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_work_show', ['id' => $id]);
    }

    /**
     * Read-only detail page for a single Work.
     */
    #[Route('/{id}', name: 'app_work_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $work = $this->workRepository->findWithAllRelations($id);

        if ($work === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('work/show.html.twig', [
            'work' => $work,
        ]);
    }

    /**
     * HTML fragment for the work preview offcanvas on the select page.
     * Returns a partial template (no base layout) consumed via fetch().
     */
    #[Route('/{id}/preview', name: 'app_work_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(int $id): Response
    {
        $work = $this->workRepository->findWithAllRelations($id);

        if ($work === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('work/_preview.html.twig', [
            'work' => $work,
        ]);
    }

    /**
     * Step 1b: Select an existing Work to create a ReadingEntry for (e.g., re-reads).
     * Also handles POST to import a Work from an external URL (merged from ImportController).
     */
    #[Route('/select', name: 'app_work_select', methods: ['GET', 'POST'])]
    public function select(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import_url', $request->request->getString('_import_token'))) {
                throw $this->createAccessDeniedException();
            }

            $url = trim($request->request->getString('import_url'));
            $importMode = $request->request->getString('import_mode', 'url');

            if ($url === '') {
                $this->addFlash('error', 'import.url.not_blank');

                return $this->redirectToRoute('app_work_select');
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->addFlash('error', 'import.url.invalid');

                return $this->redirectToRoute('app_work_select');
            }

            $scraper = $this->scraperRegistry->getScraperForUrl($url);
            if ($scraper === null) {
                $this->addFlash('error', 'import.error.unsupported_url');

                return $this->redirectToRoute('app_work_select');
            }

            // Duplicate detection before scraping: canonicalize the URL (no HTTP request)
            // and check if a work already exists. This avoids an unnecessary AO3 request.
            $canonicalUrl = $scraper->canonicalizeUrl($url);
            $existing = $this->workRepository->findByLink($canonicalUrl);
            if ($existing !== null) {
                $this->addFlash('info', 'import.info.existing_work_used');

                return $this->redirectToRoute('app_reading_entry_new', ['workId' => $existing->getId()]);
            }

            if ($importMode === 'paste') {
                if (!$scraper instanceof Ao3Scraper) {
                    $this->addFlash('error', 'import.error.unsupported_url');

                    return $this->redirectToRoute('app_work_select');
                }

                return $this->redirectToRoute('app_work_import_paste', ['url' => $canonicalUrl]);
            }

            try {
                $scraped = $scraper->scrape($url);
            } catch (RateLimitException $e) {
                $this->logger->warning('Import scrape rate limited', [
                    'url'         => $url,
                    'retry_after' => $e->getRetryAfterSeconds(),
                ]);
                $this->addFlash('error', 'import.error.rate_limited');

                return $this->redirectToRoute('app_work_select');
            } catch (AuthRequiredException $e) {
                $this->logger->warning('Import requires AO3 authentication', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                // Pre-populate the manual form with the URL and source type so the user
                // can enter the remaining details by hand without retyping the URL.
                $partial = new ScrapedWorkDto();
                $partial->sourceUrl  = $url;
                $partial->sourceType = 'AO3';
                $request->getSession()->set('import_scraped_work', $partial);
                $this->addFlash('error', 'import.error.auth_required');

                return $this->redirectToRoute('app_work_new');
            } catch (ScrapingException $e) {
                $this->logger->error('Import scrape failed', [
                    'url'         => $url,
                    'http_status' => $e->getHttpStatus(),
                    'error'       => $e->getMessage(),
                ]);
                $this->addFlash('error', 'import.error.scrape_failed');

                return $this->redirectToRoute('app_work_select');
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Import scrape connection failed', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'import.error.connection_failed');

                return $this->redirectToRoute('app_work_select');
            }

            // Store DTO in session — WorkController::new() reads it on the next GET and applies mapping
            $request->getSession()->set('import_scraped_work', $scraped);

            return $this->redirectToRoute('app_work_new');
        }

        $query = $request->query->getString('q', '');
        $works = [];

        if ($query !== '') {
            $works = $this->workRepository->createQueryBuilder('w')
                ->where('LOWER(w.title) LIKE LOWER(:q)')
                ->setParameter('q', '%' . $query . '%')
                ->orderBy('w.title', 'ASC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }

        return $this->render('work/select.html.twig', [
            'works' => $works,
            'query' => $query,
        ]);
    }

    #[Route('/import/paste', name: 'app_work_import_paste', methods: ['GET', 'POST'])]
    public function importPaste(Request $request): Response
    {
        $url = $request->isMethod('POST')
            ? trim($request->request->getString('import_url'))
            : trim($request->query->getString('url'));

        if ($url === '') {
            $this->addFlash('error', 'import.url.not_blank');

            return $this->redirectToRoute('app_work_select');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addFlash('error', 'import.url.invalid');

            return $this->redirectToRoute('app_work_select');
        }

        $scraper = $this->scraperRegistry->getScraperForUrl($url);
        if (!$scraper instanceof Ao3Scraper) {
            $this->addFlash('error', 'import.error.unsupported_url');

            return $this->redirectToRoute('app_work_select');
        }

        $canonicalUrl = $scraper->canonicalizeUrl($url);
        $existing = $this->workRepository->findByLink($canonicalUrl);
        if ($existing !== null) {
            $this->addFlash('info', 'import.info.existing_work_used');

            return $this->redirectToRoute('app_reading_entry_new', ['workId' => $existing->getId()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import_paste', $request->request->getString('_paste_token'))) {
                throw $this->createAccessDeniedException();
            }

            $pastedHtml = trim($request->request->getString('ao3_paste_html'));
            $pastedText = trim($request->request->getString('ao3_paste_text'));
            $content = $pastedHtml !== '' ? $pastedHtml : $pastedText;

            if ($content === '') {
                $this->addFlash('error', 'import.paste.not_blank');

                return $this->redirectToRoute('app_work_import_paste', ['url' => $canonicalUrl]);
            }

            try {
                $scraped = $scraper->parsePastedWorkHtml($content, $canonicalUrl);
            } catch (ScrapingException $e) {
                $this->logger->error('Import paste parse failed', [
                    'url'   => $canonicalUrl,
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'import.error.paste_failed');

                return $this->redirectToRoute('app_work_import_paste', ['url' => $canonicalUrl]);
            }

            $request->getSession()->set('import_scraped_work', $scraped);

            return $this->redirectToRoute('app_work_new');
        }

        return $this->render('work/import_paste.html.twig', [
            'url' => $canonicalUrl,
        ]);
    }
}
