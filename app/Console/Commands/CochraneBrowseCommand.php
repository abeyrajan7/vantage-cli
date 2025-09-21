<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class CochraneBrowseCommand extends Command
{
    protected $signature = 'cochrane:browse
        {--topicName= : Human-readable topic name (e.g. "Neurology")}
        {--topicId= : Required topic_id facet (e.g. z1209270544087566967307064976193)}
        {--out=cochrane_reviews.txt : Output file (stored in storage/)}
        {--pageStart=1}
        {--pageSize=25}
        {--maxPages=50}
        {--cookie= : Raw Cookie header from your browser (must include cf_clearance)}
        {--ua= : Exact User-Agent from the same browser session}
    ';

    protected $description = 'Browse Cochrane topic results (search portlet) and collect reviews. Uses Guzzle 7 + DOM only. Pass your browser cookies + UA to satisfy Cloudflare.';

    private ?string $manualCookieHeader = null;
    private array $baseHeaders = [];

    public function handle(): int
    {
        $topicName = trim((string)$this->option('topicName'));
        $topicId   = trim((string)$this->option('topicId'));
        $outPath   = storage_path($this->option('out'));
        $pageStart = max(1, (int)$this->option('pageStart'));
        $pageSize  = max(1, min(100, (int)$this->option('pageSize')));
        $maxPages  = max(1, (int)$this->option('maxPages'));

        // Cookie + UA
        $this->manualCookieHeader = $this->option('cookie') ? trim((string)$this->option('cookie')) : null;
        $ua = trim((string)($this->option('ua') ?: ''));
        if ($this->manualCookieHeader) {
            $parts = $this->parseCookiePairs($this->manualCookieHeader);
            if (!empty($parts)) {
                $this->line('Using cookies: '.implode(', ', array_keys($parts)));
            }
        } else {
            $this->warn('No --cookie provided. You will likely hit a challenge (403/robot check).');
        }
        if ($ua === '') {
            $this->warn('No --ua provided. Pass your exact browser UA (cf_clearance is often UA-bound).');
            $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
        }

        if ($topicId === '') {
            $this->error('Missing --topicId. Provide a known topic_id facet (z...).');
            return 1;
        }
        if ($topicName === '') {
            $topicName = 'Topic';
        }

        $this->baseHeaders = $this->buildHeadersForUA($ua);

        $client = new Client([
            'base_uri'        => 'https://www.cochranelibrary.com',
            'headers'         => $this->baseHeaders,
            'cookies'         => new CookieJar(), // we’ll send raw Cookie header per-request
            'timeout'         => 25,
            'http_errors'     => false,
            'decode_content'  => true,
            'allow_redirects' => ['track_redirects' => true],
            'curl'            => [
                CURLOPT_ENCODING     => 'gzip,deflate',      // IMPORTANT: no br/zstd
                // CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // uncomment if you still see content-encoding: br
            ],
        ]);

        $this->info("Topic: {$topicName}");
        $this->info("TopicId: {$topicId}");
        $this->info("Output: {$outPath}");

        // Optional warm-up (don’t abort on failure)
        $this->line('Warm-up: GET /search …');
        $warm = $this->simpleGet($client, '/search', false, null);
        if ($warm === null || $this->isChallengePage($warm)) {
            $this->warn('Warm-up blocked or challenged. Continuing…');
        }

        // Prepare output
        $fh = @fopen($outPath, 'w');
        if (!$fh) {
            $this->error("Cannot open output file: {$outPath}");
            return 1;
        }

        $total = 0;
        $seen  = [];

        for ($page = $pageStart; $page < $pageStart + $maxPages; $page++) {
            $this->line("Page {$page}: building URL candidates…");
            $candidates = $this->buildCandidateUrls($topicName, $topicId, $page, $pageSize);

            $this->line("Page {$page}: trying ".count($candidates)." candidate URL(s) …");
            $html = null;
            $listingUrl = null;

            foreach ($candidates as $url) {
                $this->line("  → {$url}");
                $htmlTry = $this->simpleGet($client, $url, true, 'https://www.cochranelibrary.com/cdsr/reviews/topics');
                if ($htmlTry !== null && !$this->isChallengePage($htmlTry)) {
                    $html = $htmlTry;
                    $listingUrl = $url;
                    break;
                }
                usleep(150000);
            }

            if ($html === null || $this->isChallengePage($html)) {
                $this->warn("Page {$page}: failed to fetch listing after ".count($candidates)." candidates. Stopping.");
                break;
            }

            $links = $this->parseListingForReviews($html);
            $this->line("Page {$page}: found ".count($links)." review link(s).");

            if (empty($links)) {
                $this->line("No more results; stopping.");
                break;
            }

            foreach ($links as $u) {
                if (isset($seen[$u])) continue;
                $seen[$u] = true;

                // Fetch review meta with the listing URL as referer
                $meta = $this->fetchReviewMetaWithReferer($client, $u, $listingUrl ?? '/search');

                // Prefer Wiley URL in output (build from DOI if possible)
                $doi = '';
                if (preg_match('~/(10\.\d{4,9}/[^/]+)/full~', $u, $m)) {
                    $doi = $m[1];
                }
                $wileyUrl = $doi ? "https://onlinelibrary.wiley.com/doi/{$doi}/full" : $u;

                $title   = $this->sanitize($meta['title'] ?? '');
                $authors = $this->sanitize($meta['authors'] ?? '');
                $date    = $this->sanitize($meta['date'] ?? '');

                fwrite($fh, "{$wileyUrl}|{$topicName}|{$title}|{$authors}|{$date}\n");
                $total++;

                usleep(250000); // polite
            }

            // polite inter-page delay
            usleep(400000);
        }

        fclose($fh);
        $this->info("Done. Wrote {$total} review(s) to: {$outPath}");
        if ($total === 0) {
            $this->warn('If blocked: recopy a fresh Cookie header and pass --ua with your exact browser UA.');
        }
        return 0;
    }

    /* ------------------------------- Helpers ------------------------------- */

    private function buildHeadersForUA(string $ua): array
    {
        $isEdge   = stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false;
        $isChrome = stripos($ua, 'Chrome/') !== false && !$isEdge;

        $secUa = $isEdge
            ? '"Chromium";v="140", "Microsoft Edge";v="140", "Not=A?Brand";v="24"'
            : ($isChrome
                ? '"Chromium";v="140", "Google Chrome";v="140", "Not=A?Brand";v="24"'
                : '"Not.A/Brand";v="99", "Chromium";v="140"');

        return [
            'User-Agent'                => $ua,
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language'           => 'en-US,en;q=0.9',
            'Accept-Encoding'           => 'gzip, deflate', // IMPORTANT: no br/zstd
            'Connection'                => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'DNT'                       => '1',
            'Sec-Fetch-Site'            => 'same-origin',
            'Sec-Fetch-Mode'            => 'navigate',
            'Sec-Fetch-Dest'            => 'document',
            'Sec-CH-UA'                 => $secUa,
            'Sec-CH-UA-Mobile'          => '?0',
            'Sec-CH-UA-Platform'        => '"Windows"',
            'Referer'                   => 'https://www.cochranelibrary.com/cdsr/reviews/topics',
        ];
    }

    private function buildCandidateUrls(string $topicName, string $topicId, int $page, int $pageSize): array
    {
        $encName = $topicName;

        // EXACT style you captured in DevTools (most reliable)
        $exact = [
            'p_p_id'        => 'scolarissearchresultsportlet_WAR_scolarissearchresults',
            'p_p_lifecycle' => '0',
            'p_p_state'     => 'normal',
            'p_p_mode'      => 'view',
            'p_p_col_id'    => 'column-1',
            'p_p_col_count' => '1',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_displayText'     => $encName,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_searchText'      => $encName,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_searchType'      => 'basic',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetQueryField' => 'topic_id',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_searchBy'        => '13',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_orderBy'         => 'displayDate-true',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetDisplayName'=> $encName,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetQueryTerm'  => $topicId,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetCategory'   => 'Topics',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_delta'           => (string)$pageSize,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_cur'             => (string)$page,
        ];

        // Alternate forms (some skins accept unprefixed or "isolated" params)
        $isoUnpref = [
            'p_p_id'        => 'scolarissearchresultsportlet_WAR_scolarissearchresults',
            'p_p_lifecycle' => '0',
            'p_p_state'     => 'normal',
            'p_p_mode'      => 'view',
            'p_p_isolated'  => '1',
            'displayText'   => $encName,
            'searchText'    => $encName,
            'searchType'    => 'basic',
            'searchBy'      => '13',
            'orderBy'       => 'displayDate-true',
            'cur'           => (string)$page,
            'resultPerPage' => (string)$pageSize,
            'facetQueryField'=> 'topic_id',
            'facetQueryTerm' => $topicId,
            'facetDisplayName'=> $encName,
            'facetCategory'   => 'Topics',
            'selectedType'    => 'review',
            'forceTypeSelection' => 'true',
        ];
        $isoPref = [
            'p_p_id'        => 'scolarissearchresultsportlet_WAR_scolarissearchresults',
            'p_p_lifecycle' => '0',
            'p_p_state'     => 'normal',
            'p_p_mode'      => 'view',
            'p_p_isolated'  => '1',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_displayText'     => $encName,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_searchText'      => $encName,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_searchType'      => 'basic',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_searchBy'        => '13',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_orderBy'         => 'displayDate-true',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_cur'             => (string)$page,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_delta'           => (string)$pageSize,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetQueryField' => 'topic_id',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetQueryTerm'  => $topicId,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetDisplayName'=> $encName,
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_facetCategory'   => 'Topics',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_selectedType'    => 'review',
            '_scolarissearchresultsportlet_WAR_scolarissearchresults_forceTypeSelection' => 'true',
        ];
        $legacy = $isoPref;
        unset($legacy['p_p_isolated']);
        $legacy['p_p_col_id']    = 'column-1';
        $legacy['p_p_col_count'] = '1';

        // Also provide an offset style variant
        $isoPrefOffset = $isoPref;
        $isoPrefOffset['_scolarissearchresultsportlet_WAR_scolarissearchresults_start'] = (string)(($page - 1) * $pageSize);

        $qs = fn(array $a) => http_build_query($a, '', '&', PHP_QUERY_RFC1738);

        // Try the exact URL FIRST
        $urls = [
            '/search?' . $qs($exact),
        ];

        // Then fallbacks
        $urls[] = '/search?' . $qs($isoPref);
        $urls[] = '/search?' . $qs($isoPrefOffset);
        $urls[] = '/search?' . $qs($isoUnpref);
        $urls[] = '/web/cochrane/search?' . $qs($isoPref);
        $urls[] = '/search?' . $qs($legacy);
        $urls[] = '/web/cochrane/search?' . $qs($legacy);

        return $urls;
    }

    private function simpleGet(Client $client, string $url, bool $withReferer = false, ?string $refererUrl = null): ?string
    {
        try {
            $opts = ['headers' => $this->baseHeaders];
            if ($this->manualCookieHeader) {
                $opts['headers']['Cookie'] = $this->manualCookieHeader;
            }
            if ($withReferer && $refererUrl) {
                $opts['headers']['Referer'] = $refererUrl;
            }
            $resp = $client->get($url, $opts);
            $code = $resp->getStatusCode();
            $ce   = $resp->getHeaderLine('Content-Encoding');
            $this->line("  ↳ HTTP {$code}, content-encoding=" . ($ce ?: 'none'));
            if ($code !== 200) {
                return null;
            }
            $body = (string)$resp->getBody();
            if ($this->isChallengePage($body)) {
                $this->line("  ↳ challenge/robot page detected");
                return null;
            }
            return $body;
        } catch (\Throwable $e) {
            $this->line("  ↳ exception: ".$e->getMessage());
            return null;
        }
    }

    private function isChallengePage(string $html): bool
    {
        $l = strtolower($html);
        foreach ([
            'verifying you are human',
            'please enable javascript',
            'cloudflare',
            'checking your browser',
            'captcha',
            'just a moment',
            'access denied'
        ] as $s) {
            if (strpos($l, $s) !== false) return true;
        }
        return false;
    }

    private function loadDom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        if (stripos($html, '<meta charset=') === false) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'.$html;
        }
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return new \DOMXPath($dom);
    }

    private function parseListingForReviews(string $html): array
    {
        $xp = $this->loadDom($html);
        $urls = [];

        // Primary selector (card/row layout)
        $nodes = $xp->query('//div[contains(@class,"search-results-item-body")]//h3//a[@href]');
        if ($nodes && $nodes->length > 0) {
            foreach ($nodes as $a) {
                /** @var \DOMElement $a */
                $href = $a->getAttribute('href');
                if ($href) {
                    $urls[] = $this->absUrl($href);
                }
            }
        }

        // Fallback selector directly for DOI links (defensive)
        if (empty($urls)) {
            $nodes = $xp->query('//a[contains(@href,"/cdsr/doi/10.1002/") and contains(@href,"/full")]');
            if ($nodes) {
                foreach ($nodes as $a) {
                    $href = $a->getAttribute('href');
                    if ($href) $urls[] = $this->absUrl($href);
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function fetchReviewMetaWithReferer(Client $client, string $url, string $referer): array
    {
        $html = $this->simpleGet($client, $url, true, $referer);
        if ($html === null) return ['title'=>'','authors'=>'','date'=>''];

        $xp = $this->loadDom($html);

        // Title: prefer DC or citation meta, else H1
        $title = $this->firstAttr($xp, [
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.title"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="citation_title"]/@content',
        ]);
        if ($title === '') {
            $t = $xp->query('//h1');
            if ($t && $t->length) $title = trim($t->item(0)->textContent);
        }

        // Authors: DC.creator or citation_authors (may be repeated meta tags)
        $authors = [];
        foreach ([
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.creator"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="citation_authors"]/@content',
        ] as $q) {
            $nodes = $xp->query($q);
            if ($nodes && $nodes->length) {
                foreach ($nodes as $node) {
                    $v = trim($node->nodeValue ?? '');
                    if ($v !== '') {
                        // citation_authors may be a semicolon- or comma-separated string
                        if (strpos($q, 'citation_authors') !== false && (str_contains($v, ';') || str_contains($v, ','))) {
                            foreach (preg_split('/[;,]/', $v) as $piece) {
                                $piece = trim($piece);
                                if ($piece !== '') $authors[] = $piece;
                            }
                        } else {
                            $authors[] = $v;
                        }
                    }
                }
            }
        }
        $authors = implode(', ', array_values(array_unique(array_filter($authors))));

        // Date: dc.date or citation_date or <time datetime=…>
        $date = $this->firstAttr($xp, [
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.date"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="citation_date"]/@content',
            '//time[@datetime]/@datetime',
        ]);
        $date = $this->normalizeDate($date);

        return ['title'=>$title, 'authors'=>$authors, 'date'=>$date];
    }

    private function firstAttr(\DOMXPath $xp, array $queries): string
    {
        foreach ($queries as $q) {
            $n = $xp->query($q);
            if ($n && $n->length) {
                return trim($n->item(0)->nodeValue ?? '');
            }
        }
        return '';
    }

    private function absUrl(string $href): string
    {
        if (strpos($href, '//') === 0) return 'https:'.$href;
        if (parse_url($href, PHP_URL_SCHEME)) return $href;
        return 'https://www.cochranelibrary.com'.(strpos($href,'/')===0?$href:'/'.ltrim($href,'/'));
    }

    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        try {
            $d = new \DateTime($raw);
            return $d->format('Y-m-d');
        } catch (\Exception $e) {
            if (preg_match('/^\d{4}-\d{2}$/', $raw)) return $raw.'-01';
            if (preg_match('/^\d{4}$/', $raw))    return $raw.'-01-01';
            return $raw;
        }
    }

    private function sanitize(string $s): string
    {
        $s = str_replace(["\r","\n",'|'], ' ', $s);
        return preg_replace('/\s+/', ' ', trim($s));
    }

    private function parseCookiePairs(string $raw): array
    {
        $map = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $k = trim($parts[0]); $v = trim($parts[1]);
                if ($k !== '' && $v !== '') $map[$k] = $v;
            }
        }
        return $map;
    }
}
