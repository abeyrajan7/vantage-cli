<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Storage;

class CochraneFilterCrawl extends Command
{
    protected $signature = 'cochrane:filter-crawl
                        {topic : Topic name (e.g. "Allergy & intolerance")}
                        {--start=1 : Starting page number}
                        {--max= : Maximum pages to attempt (omit to crawl until 404)}
                        {--cap=1000 : Safety cap when --max is omitted}
                        {--out=cochrane_reviews.txt : Output .txt (pipes, one line per review)}';


    protected $description = 'Crawl local fake Cochrane pages, parse reviews, and append pipe-delimited lines to a text file.';

    public function handle()
    {
        //store fallback the necessary values to pass with the url
        $topic     = (string) $this->argument('topic');
        $topicSafe = $this->cleanFieldForPipes($topic);
        $start     = (int) $this->option('start');
        $max       = (int) $this->option('max');
        $outFile   = trim((string) $this->option('out'));
        $id = $this->resolveTopicIdFromLocal($topic);
        if (!$id) {
            $this->error("Could not find topic id for \"{$topic}\" in config/cochrane_topics.php");
            $this->suggestClosestTopics($topic);
            return self::FAILURE;
        }

        // Base URL for Local development
        $baseUrl = rtrim(config('app.url'), '/') . '/fake/render_portlet';

        // When running in production, uncomment the following line and comment out the one above.
        // The real URL is pulled from the COCHRANE_BASE_URL environment variable.
        // $baseUrl = config('cochrane.base_url');

        // get the whole array of fixed params
        // This array holds the non-changing parameters for the API request.
        // $fixedParams = config('cochrane.fixed_params');

        // Write to BOTH storage/app and project root
        $disk         = Storage::disk('local');                      // storage/app
        $absStorage   = storage_path('app' . DIRECTORY_SEPARATOR . $outFile);
        $absProject   = base_path($outFile);                         // project root

        // Start fresh
        $disk->put($outFile, '');
        file_put_contents($absProject, '');

        $client = new Client([
            'timeout' => config('cochrane.timeout'),
            'http_errors' => true,
            'headers' => [
                'User-Agent' => config('cochrane.user_agent'),
            ],
            // Use this for debugging with cookies if necessary.
            // 'cookies' => config('cochrane.cookies'),
        ]);
        $totalLines = 0;

        for ($cur = $start; $cur < $start + $max; $cur++) {
            $query = [
                'facetQueryTerm'   => $id,
                'searchText'       => $topic,
                'displayText'      => $topic,
                'facetDisplayName' => $topic,
                'cur'              => $cur,
            ];
            // To run the command with the full set of fixed parameters, uncomment
            // $query = array_merge($fixedParams, [
            //     'facetQueryTerm'   => $id,
            //     'searchText'       => $topic,
            //     'displayText'      => $topic,
            //     'facetDisplayName' => $topic,
            //     'cur'              => $cur,
            // ]);

            //to check which api you are currently using:
            // $fullUrl = $baseUrl . '?' . http_build_query($query);
            // dd($fullUrl);
            try {
                $res  = $client->get($baseUrl, ['query' => $query]);
                $html = (string) $res->getBody();
            } catch (ClientException $e) {
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                    $this->info("Reached 404 at cur={$cur}. Stopping.");
                    break;
                }
                throw $e;
            }

            $lines = $this->parseReviewsToLines($html, $topicSafe);
            if (!empty($lines)) {
                $block = implode("\n", $lines) . "\n";
                $disk->append($outFile, $block);                          // storage/app/<out>
                file_put_contents($absProject, $block, FILE_APPEND);       // <project>/<out>
                $this->line("Page {$cur}: wrote " . count($lines) . " line(s).");
                $totalLines += count($lines);
            } else {
                $this->line("No reviews found on page {$cur} (skipping).");
            }
        }

        $existsStorage = $disk->exists($outFile) ? 'yes' : 'no';
        $this->info("Done. Wrote {$totalLines} line(s).");
        $this->info("=> storage/app file exists: {$existsStorage} → {$absStorage}");
        $this->info("=>  project-root copy: {$absProject}");

        return self::SUCCESS;
    }

    // function to get id of the topic from cochrane_topics.php
    protected function resolveTopicIdFromLocal(string $topic): ?string
    {
        $map = config('cochrane_topics'); // array: "Title" => "id"
        if (!is_array($map)) return null;

        // Normalize keys once for case-insensitive lookups
        $needle = $this->normalizeTitle($topic);

        foreach ($map as $title => $id) {
            if ($this->normalizeTitle($title) === $needle) {
                return $id;
            }
        }
        return null;
    }

    protected function suggestClosestTopics(string $topic): void
    {
        $map = config('cochrane_topics') ?? [];
        $needle = $this->normalizeTitle($topic);
        $candidates = [];

        foreach (array_keys($map) as $title) {
            $norm = $this->normalizeTitle($title);
            $dist = levenshtein($needle, $norm);
            $candidates[$title] = $dist;
        }

        asort($candidates);
        $suggest = array_slice(array_keys($candidates), 0, 5);
        if ($suggest) {
            $this->line('Did you mean: ' . implode(' | ', $suggest));
        }
    }

    protected function normalizeTitle(string $s): string
    {
        // make matching resilient to whitespace, ampersands, etc.
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s));
        $s = str_ireplace([' and ', ' & '], ' & ', $s); // normalize "and" vs "&"
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * Parse the HTML of a results page and return pipe-delimited lines:
     * <link>|<topic>|<title>|<authors>|<YYYY-MM-DD>
     */
    protected function parseReviewsToLines(string $html, string $topicSafe): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOCDATA);
        $xp  = new \DOMXPath($dom);

        // 1) Pick a stable container selector for each result “card”
        //    Add OR-clauses if your markup varies.
        $items = $xp->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' search-result ')] |
             //*[contains(concat(' ', normalize-space(@class), ' '), ' search-results-item ')] |
             //*[contains(concat(' ', normalize-space(@class), ' '), ' result-item ')] |
             //article[contains(@class,'result')] |
             //li[contains(@class,'result')]"
        );

        $lines = [];

        /** @var \DOMElement $item */
        foreach ($items as $item) {
            // 2) Title + link (required)
            $aNode = $xp->query(".//h3[contains(concat(' ', normalize-space(@class), ' '), ' result-title ')]/a", $item)->item(0)
                  ?: $xp->query(".//a[contains(@class,'result-title') or contains(@class,'search-result__title') or name()='a'][1]", $item)->item(0);

            if (!$aNode instanceof \DOMElement) {
                // No title/link → skip this card
                continue;
            }

            $title = $this->cleanFieldForPipes($aNode->textContent ?? '');
            $href  = trim($aNode->getAttribute('href') ?? '');
            $link  = $this->normalizeWileyHref($href);

            // 3) Authors (nice-to-have)
            $authorsNode =
                $xp->query(".//*[contains(@class,'search-result-authors')]//div[1]", $item)->item(0)
             ?: $xp->query(".//*[contains(@class,'result-authors')][1]", $item)->item(0)
             ?: $xp->query(".//*[contains(@class,'authors')][1]", $item)->item(0);

            $authors = $this->cleanFieldForPipes($this->cleanText($authorsNode?->textContent ?? ''));

            // 4) Date (look for <time> first, then fallback to date div)
            $timeAttr = $xp->query(".//time/@datetime", $item)->item(0);
            $timeEl   = $xp->query(".//time", $item)->item(0);
            $dateDiv  = $xp->query(".//*[contains(@class,'search-result-date')]//div[1] | .//*[contains(@class,'result-date')][1]", $item)->item(0);

            $rawDateText =
                  ($timeAttr?->value ?? '')
               ?: ($timeEl?->textContent ?? '')
               ?: ($dateDiv?->textContent ?? '');

            $dateIso = $this->toIsoDate($this->cleanText($rawDateText));

            // 5) Compose pipe line
            $lines[] = "{$link}|{$topicSafe}|{$title}|{$authors}|{$dateIso}";
        }

        return $lines;
    }

    /** Replace relative Cochrane path with absolute Wiley DOI link. */
    protected function normalizeWileyHref(string $href): string
    {
        $href = trim($href);

        // Already absolute?
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $href = preg_replace('#^/cdsr/doi/#i', '/doi/', $href);

        return 'http://onlinelibrary.wiley.com' . $href;
    }

//     Collapse whitespace and trim.
    protected function cleanText(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s ?? '');
        return trim($s);
    }

    /** Clean text and make it safe for pipe-delimited output. */
    protected function cleanFieldForPipes(string $s): string
    {
        $s = $this->cleanText($s);
        return str_replace('|', ' - ', $s);
    }

//Convert many human date shapes to YYYY-MM-DD; fallback to original if parsing fails.
    protected function toIsoDate(string $human): string
    {
        $human = trim(preg_replace('/\s+/u', ' ', $human));
        if ($human === '') return '';

        foreach (['j F Y', 'd F Y', 'j M Y', 'd M Y', 'Y-m-d', 'Y/m/d'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $human);
            if ($dt instanceof \DateTime) return $dt->format('Y-m-d');
        }

        // Regex like "28 February 2013"
        if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $human, $m)) {
            $dt = \DateTime::createFromFormat('j F Y', "{$m[1]} {$m[2]} {$m[3]}");
            if ($dt) return $dt->format('Y-m-d');
        }

        // e.g., inside datetime="2013-02-28"
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $human, $m)) {
            return $m[0];
        }

        return $human; // fallback
    }

//     Run an XPath query scoped to a given element (use the element as the context node).
    protected function queryOneWithin(\DOMElement $context, string $relXpath, \DOMXPath $xp): ?\DOMNode
    {
        $rel = trim($relXpath);
        if ($rel === '') return null;
        if (str_starts_with($rel, './')) $rel = substr($rel, 2);
        if (!str_starts_with($rel, '.') && !str_starts_with($rel, '/')) $rel = './/' . $rel;
        $nodes = @$xp->query($rel, $context);
        return ($nodes && $nodes->length) ? $nodes->item(0) : null;
    }
}
