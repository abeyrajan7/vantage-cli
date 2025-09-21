<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;

class CochraneCrawler extends Command
{
    private const TOPICS_JSON = '[{"id":"z1506030924307755598196034641807","title":"Allergy \\u0026 intolerance"},{"id":"z1209270503555436197730382260671","title":"Blood disorders"},{"id":"z1209270504325056240433870825568","title":"Cancer"},{"id":"z1209270506397401105880747733814","title":"Child health"},{"id":"z1209270517013654280662502828396","title":"Complementary \\u0026 alternative medicine"},{"id":"z1209270520546298433878661077335","title":"Consumer \\u0026 communication strategies"},{"id":"z1209270521308609304428063247054","title":"Dentistry \\u0026 oral health"},{"id":"z1209270522125408101510869314290","title":"Developmental, psychosocial \\u0026 learning problems"},{"id":"z1306131057021224666154337161576","title":"Diagnosis"},{"id":"z1209270522463875219228418874346","title":"Ear, nose \\u0026 throat"},{"id":"z1209270523266078530882748723362","title":"Effective practice \\u0026 health systems"},{"id":"z1209270524042325670929098616351","title":"Endocrine \\u0026 metabolic"},{"id":"z1209270524406271065891754655665","title":"Eyes \\u0026 vision"},{"id":"z1209270525153683793351219115280","title":"Gastroenterology \\u0026 hepatology"},{"id":"z1209270527346428439245515123767","title":"Genetic disorders"},{"id":"z1209270528090177085579134391608","title":"Gynaecology"},{"id":"z1305131036229293292321438818383","title":"Health \\u0026 safety at work"},{"id":"z1702221407285233729667009826083","title":"Health professional education"},{"id":"z1209270530155810435593455227522","title":"Heart \\u0026 circulation"},{"id":"z1209270532564255913630615179455","title":"Infectious disease"},{"id":"z1812202128257763601894499966737","title":"Insurance medicine"},{"id":"z1209270536031876257941944572357","title":"Kidney disease"},{"id":"z1209270536574680511905632880005","title":"Lungs \\u0026 airways"},{"id":"z1209270540006671523997579560781","title":"Mental health"},{"id":"z1209270542227710303774307877038","title":"Methodology"},{"id":"z1209270542317057954205935715994","title":"Neonatal care"},{"id":"z1209270544087566967307064976193","title":"Neurology"},{"id":"z1209270547455450673659035855752","title":"Orthopaedics \\u0026 trauma"},{"id":"z1209270502385005468487254367079","title":"Pain \\u0026 anaesthesia"},{"id":"z1209270550029507560491602193368","title":"Pregnancy \\u0026 childbirth"},{"id":"z1209270552392347897765250778146","title":"Public health"},{"id":"z2007290642474121761163844119430","title":"Reproductive \\u0026 sexual health"},{"id":"z1209270552574982854088789647107","title":"Rheumatology"},{"id":"z1209270554134846850298301128418","title":"Skin disorders"},{"id":"z1209270555015401529060096109128","title":"Tobacco, drugs \\u0026 alcohol"},{"id":"z1209270555433404138993999813829","title":"Urology"},{"id":"z1209270556296734575921098026769","title":"Wounds"}]';

    protected $signature = 'cochrane:crawl
                            {topicUrl : Cochrane topic listing URL (e.g. http://www.cochranelibrary.com/home/topic-and-review-group-list.html?page=topic)}
                            {--topicName= : Human-readable topic name (e.g. "Allergy & intolerance")}
                            {--out=cochrane_reviews.txt : Output file name (stored in storage/)}';

    protected $description = 'Collect URL|Topic|Title|Author|Date for Cochrane reviews. Uses Guzzle + native DOM. Detects challenges and falls back to Crossref (no bypass).';
    private function normTopic(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
        $s = strtolower($s);
        $s = preg_replace('/\s*&\s*/', ' & ', $s);   // " & " normalized
        $s = preg_replace('/\s+/', ' ', trim($s));   // collapse whitespace
        return $s;
    }

    private function topicIdForName(string $topicName): ?string
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            $arr = json_decode(self::TOPICS_JSON, true) ?: [];
            foreach ($arr as $row) {
                if (!empty($row['title']) && !empty($row['id'])) {
                    $index[$this->normTopic($row['title'])] = $row['id'];
                }
            }
        }

        $key = $this->normTopic($topicName);
        if (isset($index[$key])) return $index[$key];

        // Fuzzy: pick the title with highest similarity if > 0.8
        $bestId = null; $bestScore = 0.0;
        foreach ($index as $titleKey => $id) {
            similar_text($key, $titleKey, $pct);
            if ($pct > $bestScore) { $bestScore = $pct; $bestId = $id; }
        }
        return ($bestScore >= 80.0) ? $bestId : null;
    }


    private function applyTopicIdToUrl(string $url, ?string $topicId): string
    {
        if (!$topicId) return $url;

        if (strpos($url, '{topicId}') !== false) {
            return str_replace('{topicId}', rawurlencode($topicId), $url);
        }

        $parts = parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        // Don’t overwrite if caller already provided topicId
        if (empty($query['topicId'])) {
            $query['topicId'] = $topicId;
        }

        // Rebuild URL
        $parts['query'] = http_build_query($query);
        $built  = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) $built .= ':' . $parts['port'];
        $built .= $parts['path'] ?? '';
        if (!empty($parts['query'])) $built .= '?' . $parts['query'];
        if (!empty($parts['fragment'])) $built .= '#' . $parts['fragment'];
        return $built;
    }



    public function handle()
    {
        $topicUrl  = $this->argument('topicUrl');
        $topicName = $this->option('topicName') ?: 'Unknown Topic';
        $outFile   = storage_path($this->option('out'));
        $topicId = $this->topicIdForName($topicName);
        if ($topicId) {
            $resolved = $this->applyTopicIdToUrl($topicUrl, $topicId);
            if ($resolved !== $topicUrl) {
                $this->info("Resolved topic ID: {$topicId}");
                $this->info("Resolved listing URL: {$resolved}");
                $topicUrl = $resolved;
            } else {
                $this->info("Resolved topic ID: {$topicId} (URL unchanged; no {topicId} placeholder)");
            }
        } else {
            $this->warn("Could not resolve topic ID for '{$topicName}'. Proceeding with the provided URL.");
        }

        

        $this->info("Starting crawl for topic: {$topicName}");
        $this->info("Listing URL: {$topicUrl}");
        $this->info("Output file: {$outFile}");

        $headers = [
            'User-Agent' => 'vantage-labs-cochrane-scraper/1.0 (mailto:you@example.com)',
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];

        $client = new Client([
            'headers' => $headers,
            'cookies' => new CookieJar(),
            'timeout' => 20,
        ]);

        $fh = fopen($outFile, 'w');
        if ($fh === false) {
            $this->error("Cannot open output file: {$outFile}");
            return 1;
        }

        $queue = [$topicUrl];
        $seen  = [];

        while (!empty($queue)) {
            $pageUrl = array_shift($queue);
            if (isset($seen[$pageUrl])) {
                continue;
            }
            $seen[$pageUrl] = true;

            $this->line("Fetching listing page: {$pageUrl}");

            try {
                $resp = $client->get($pageUrl);
                $html = (string) $resp->getBody();

                if ($this->isChallengePage($html)) {
                    $this->warn("Challenge detected at {$pageUrl}. Falling back to Crossref for topic: {$topicName}");

                    $this->writeCrossrefFallback($fh, $topicName, 400); // fetch+write up to 200
                    fwrite($fh, "# CHALLENGE_DETECTED|{$topicName}|{$pageUrl}|\n");

                    continue;
                }

                // Parse listing page with native DOM
                $dom = $this->loadHtml($html);
                $xp  = new \DOMXPath($dom);

                // 1) Extract candidate review links (DOI/Wiley links)
                $linkNodes = $xp->query('//a[contains(@href,"/doi/") or contains(@href,"onlinelibrary.wiley.com/doi")]');
                if ($linkNodes !== false) {
                    foreach ($linkNodes as $a) {
                        /** @var \DOMElement $a */
                        $href = $a->getAttribute('href');
                        if (!$href) continue;
                        $absUrl = $this->normalizeUrl($href);

                        $meta = $this->fetchReviewMetadata($client, $absUrl);
                        if ($meta === null) {
                            fwrite($fh, "{$absUrl}|{$topicName}| | | \n");
                        } else {
                            $title   = $this->sanitizeField($meta['title'] ?? '');
                            $authors = $this->sanitizeField($meta['authors'] ?? '');
                            $date    = $this->sanitizeField($meta['date'] ?? '');
                            fwrite($fh, "{$absUrl}|{$topicName}|{$title}|{$authors}|{$date}\n");
                        }
                    }
                }

                // 2) Naive pagination discovery
                $pagNodes = $xp->query('//a[contains(@href, "page=")]');
                if ($pagNodes !== false) {
                    foreach ($pagNodes as $a) {
                        $href = $a->getAttribute('href');
                        if ($href) {
                            $queue[] = $this->normalizeUrl($href);
                        }
                    }
                }

                // Politeness
                usleep(500000); // 0.5s

            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;

                if ($statusCode === 403) {
                    $this->warn("403 Forbidden at {$pageUrl}. Falling back to Crossref for topic: {$topicName}");
                    $this->writeCrossrefFallback($fh, $topicName, 400);
                }

                fwrite($fh, "# ERROR_FETCHING|{$pageUrl}|".$e->getMessage()."\n");
            } catch (\Exception $e) {
                $this->error("Exception: ".$e->getMessage());
                fwrite($fh, "# ERROR_FETCHING|{$pageUrl}|".$e->getMessage()."\n");
            }
        }

        fclose($fh);
        $this->info("Done. Output: {$outFile}");
        return 0;
    }

    /* ===================== Helpers (no crawler libs) ===================== */

    /** Return DOMDocument from (possibly messy) HTML without warnings. */
    protected function loadHtml(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Add meta charset if missing to help DOM parse unicode
        if (stripos($html, '<meta charset=') === false) {
            $html = "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">".$html;
        }
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    /** Detect common anti-bot/challenge pages (we do not bypass). */
    protected function isChallengePage(string $html): bool
    {
        $lower = strtolower($html);
        foreach ([
            'verifying you are human',
            'please enable javascript',
            'cloudflare',
            'checking your browser',
            'access denied',
            'captcha',
            'just a moment...'
        ] as $s) {
            if (strpos($lower, $s) !== false) return true;
        }
        return false;
    }

    /** Normalize possibly relative URLs to absolute under cochranelibrary.com. */
    protected function normalizeUrl(string $href): string
    {
        if (strpos($href, '//') === 0) return 'https:'.$href;
        if (parse_url($href, PHP_URL_SCHEME) === null) {
            return rtrim('https://www.cochranelibrary.com', '/').'/'.ltrim($href, '/');
        }
        return $href;
    }

    /** Fetch a review page and extract title/authors/date with native DOM. */
    protected function fetchReviewMetadata(Client $client, string $url): ?array
    {
        try {
            $resp = $client->get($url);
            $html = (string) $resp->getBody();
            if ($this->isChallengePage($html)) return null;

            $dom = $this->loadHtml($html);
            $xp  = new \DOMXPath($dom);

            // Title: prefer meta[name="dc.title"], else first <h1>
            $title = '';
            $n = $xp->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.title"]/@content');
            if ($n && $n->length) {
                $title = trim($n->item(0)->nodeValue);
            } else {
                $h1 = $xp->query('//h1');
                if ($h1 && $h1->length) $title = trim($h1->item(0)->textContent);
            }

            // Authors: collect all meta[name="dc.creator"], else any class containing "author"
            $authors = [];
            $n = $xp->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.creator"]/@content');
            if ($n && $n->length) {
                foreach ($n as $it) $authors[] = trim($it->nodeValue);
            } else {
                $nodes = $xp->query('//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"author")]');
                if ($nodes) {
                    foreach ($nodes as $it) {
                        $txt = trim(preg_replace('/\s+/', ' ', $it->textContent ?? ''));
                        if ($txt) $authors[] = $txt;
                    }
                }
            }
            $authorsStr = implode(', ', array_unique(array_filter($authors)));

            // Date: meta[name="dc.date"] or <time datetime="...">
            $date = '';
            $n = $xp->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dc.date"]/@content');
            if ($n && $n->length) {
                $date = $this->normalizeDate($n->item(0)->nodeValue);
            } else {
                $t = $xp->query('//time[@datetime]/@datetime');
                if ($t && $t->length) $date = $this->normalizeDate($t->item(0)->nodeValue);
            }

            return [
                'title'   => $title,
                'authors' => $authorsStr,
                'date'    => $date,
            ];
        } catch (\Exception $e) {
            return null; // fail soft for a single review
        }
    }

    /** Normalize date to YYYY-MM-DD where possible. */
    protected function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        try {
            $dt = new \DateTime($raw);
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            // Fallback for "YYYY-MM" or "YYYY"
            if (preg_match('/^\d{4}-\d{2}$/', $raw)) return $raw.'-01';
            if (preg_match('/^\d{4}$/', $raw))    return $raw.'-01-01';
            return $raw;
        }
    }

    /** Clean fields for pipe output. */
    protected function sanitizeField($val): string
    {
        if (is_array($val)) $val = implode(', ', $val);
        $val = str_replace(["\r", "\n", '|'], ' ', trim($val));
        return preg_replace('/\s+/', ' ', $val);
    }

    /** Write Crossref fallback lines for the given topic. */
    protected function writeCrossrefFallback($fh, string $topicName, int $max = 200): void
    {
        $rows = $this->searchCrossrefForTopic($topicName, $max); // cursor-paged
        foreach ($rows as $r) {
            $line = sprintf(
                "%s|%s|%s|%s|%s\n",
                $r['url'],
                $topicName,
                $this->sanitizeField($r['title']),
                $this->sanitizeField($r['authors']),
                $this->sanitizeField($r['date'])
            );
            fwrite($fh, $line);
        }
    }

    /**
     * Crossref fallback using only Guzzle + JSON (no crawler libs).
     * Returns array of ['url','title','authors','date'].
     */
    protected function searchCrossrefForTopic(string $topic, int $max = 400, bool $filterTitles = true): array
    {
        $client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.crossref.org/',
            'timeout'  => 25,
            'headers'  => [
                'User-Agent' => 'vantage-labs-cochrane-scraper/1.0 (mailto:you@example.com)',
                'Accept'     => 'application/json',
            ],
        ]);

        // Build dynamic patterns from the topic (no hardcoded synonyms).
        $tokens = $this->tokenizeTopic($topic);
        $includePats = $this->buildIncludePatterns($tokens);

        // Query terms: try the full topic string, then individual tokens to broaden recall.
        $terms = [];
        if (!empty($topic)) $terms[] = $topic;
        $terms = array_merge($terms, $tokens);
        $terms = array_values(array_unique($terms));

        // Deduplicate: keep newest (higher .pubN, then newer date) per Cochrane core ID (CDnnnnnn).
        $bestByCore = []; // core => ['doi','title','authors','date','url','pub','y','m','d']

        foreach ($terms as $term) {
            if (count($bestByCore) >= $max) break;

            $cursor   = '*';
            $pageSize = 200;

            while (count($bestByCore) < $max && $cursor) {
                $params = [
                    'query'                 => $term,
                    'query.container-title' => 'Cochrane Database of Systematic Reviews',
                    'filter'                => 'prefix:10.1002,type:journal-article',
                    'select'                => 'DOI,title,author,URL,issued,link,container-title,subject',
                    'sort'                  => 'issued',
                    'order'                 => 'desc',
                    'rows'                  => $pageSize,
                    'cursor'                => $cursor,
                ];

                try {
                    $resp  = $client->get('works', ['query' => $params]);
                    $data  = json_decode((string)$resp->getBody(), true);
                    $msg   = $data['message'] ?? [];
                    $items = $msg['items'] ?? [];
                    if (!$items) break;

                    foreach ($items as $it) {
                        $doi = $it['DOI'] ?? null;
                        if (!$doi) continue;

                        // 1) Cochrane-only gates
                        $container = '';
                        if (!empty($it['container-title'])) {
                            $container = is_array($it['container-title']) ? ($it['container-title'][0] ?? '') : $it['container-title'];
                        }
                        if (stripos($container, 'Cochrane Database of Systematic Reviews') === false) {
                            continue;
                        }
                        if (!preg_match('/^10\.1002\/14651858\.(CD\d{6})(?:\.pub(\d+))?/i', $doi, $m)) {
                            continue; // drop non-Cochrane or non-review DOIs
                        }
                        $core = strtoupper($m[1]);                 // CDnnnnnn
                        $pub  = isset($m[2]) ? (int)$m[2] : 1;     // .pubN (default 1)

                        // 2) Dynamic topical filter (title OR subject)
                        $title = is_array($it['title'] ?? null) ? ($it['title'][0] ?? '') : ($it['title'] ?? '');
                        $subjects = !empty($it['subject']) && is_array($it['subject']) ? $it['subject'] : [];
                        if ($filterTitles && !$this->titleOrSubjectsMatch(mb_strtolower($title), $subjects, $includePats)) {
                            continue;
                        }

                        // 3) URL preference: Wiley link with Cochrane DOI if available; else DOI URL
                        $url = 'https://doi.org/' . $doi;
                        if (!empty($it['link'])) {
                            foreach ($it['link'] as $lnk) {
                                if (!empty($lnk['URL']) && stripos($lnk['URL'], '10.1002/14651858.') !== false) {
                                    $url = $lnk['URL'];
                                    break;
                                }
                            }
                        } elseif (!empty($it['URL']) && stripos($it['URL'], '10.1002/14651858.') !== false) {
                            $url = $it['URL'];
                        }

                        // 4) Authors
                        $authors = '';
                        if (!empty($it['author'])) {
                            $names = [];
                            foreach ($it['author'] as $a) {
                                $names[] = trim(($a['given'] ?? '').' '.($a['family'] ?? ''));
                            }
                            $authors = implode(', ', array_filter($names));
                        }

                        // 5) Date parts → comparable
                        $y=$mth=$d=0;
                        if (!empty($it['issued']['date-parts'][0])) {
                            $p = $it['issued']['date-parts'][0];
                            $y   = (int)($p[0] ?? 0);
                            $mth = (int)($p[1] ?? 1);
                            $d   = (int)($p[2] ?? 1);
                        }
                        $dateStr = $y ? sprintf('%04d-%02d-%02d', $y, $mth, $d) : '';

                        // 6) Keep the "best" version per core (latest .pubN; if tie, newer date)
                        $cur = $bestByCore[$core] ?? null;
                        $isBetter = false;
                        if (!$cur) {
                            $isBetter = true;
                        } elseif ($pub > ($cur['pub'] ?? 0)) {
                            $isBetter = true;
                        } elseif ($pub === ($cur['pub'] ?? 0)) {
                            $isBetter = ($y > ($cur['y'] ?? 0)) || ($y === ($cur['y'] ?? 0) && ($mth > ($cur['m'] ?? 0) || ($mth === ($cur['m'] ?? 0) && $d > ($cur['d'] ?? 0))));
                        }

                        if ($isBetter) {
                            $bestByCore[$core] = [
                                'doi'     => $doi,
                                'title'   => $title,
                                'authors' => $authors,
                                'date'    => $dateStr,
                                'url'     => $url,
                                'pub'     => $pub,
                                'y'       => $y, 'm' => $mth, 'd' => $d,
                            ];
                        }

                        if (count($bestByCore) >= $max) break;
                    }

                    $cursor = $msg['next-cursor'] ?? null;
                    if (!$cursor) break;

                } catch (\Exception $e) {
                    $this->warn("Crossref query '{$term}' failed: ".$e->getMessage());
                    break; // try next term
                }
            }
        }

        // Sort newest first; tie-breaker higher pub
        usort($bestByCore, function ($a, $b) {
            $ad = sprintf('%04d-%02d-%02d', $a['y'] ?? 0, $a['m'] ?? 0, $a['d'] ?? 0);
            $bd = sprintf('%04d-%02d-%02d', $b['y'] ?? 0, $b['m'] ?? 0, $b['d'] ?? 0);
            if ($ad === $bd) return ($b['pub'] ?? 0) <=> ($a['pub'] ?? 0);
            return strcmp($bd, $ad);
        });

        // Map to output shape (trim to $max)
        $out = [];
        foreach ($bestByCore as $r) {
            $out[] = [
                'url'     => $r['url'],
                'title'   => $r['title'],
                'authors' => $r['authors'],
                'date'    => $r['date'],
            ];
            if (count($out) >= $max) break;
        }
        return $out;
    }

        // Tokenize topic into words (drop stopwords), no hardcoding.
    protected function tokenizeTopic(string $topic): array
    {
        $topic = mb_strtolower($topic);
        preg_match_all('/[a-z][a-z\-]{2,}/u', $topic, $m);
        $words = $m[0] ?? [];

        $stop = ['and','or','of','the','a','an','for','to','from','by','on','in','with','group','topic'];
        $words = array_values(array_diff(array_map(fn($w)=>trim($w,'-'), $words), $stop));

        return array_values(array_unique($words));
    }

    // Build loose regex patterns from tokens (simple stemming by prefix).
    protected function buildIncludePatterns(array $tokens): array
    {
        $pats = [];
        foreach ($tokens as $t) {
            // For longer tokens, use a stem prefix to match variants (e.g., allerg*).
            $stem = mb_strlen($t) >= 6 ? mb_substr($t, 0, 5) : $t; // e.g., "allerg", "intol", "cardi", etc.
            $pats[] = '/\b' . preg_quote($stem, '/') . '\w{0,12}\b/i';
        }
        return $pats;
    }

    // Dynamic check: title OR subjects match at least one include pattern.
    protected function titleOrSubjectsMatch(string $title, array $subjects, array $includePats): bool
    {
        foreach ($includePats as $pat) {
            if ($title !== '' && preg_match($pat, $title)) return true;
        }
        foreach ($subjects as $s) {
            foreach ($includePats as $pat) {
                if (preg_match($pat, $s)) return true;
            }
        }
        return false;
    }


}
