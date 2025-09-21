<?php
// config/cochrane.php

return [
    // Real render endpoint (used if you *don’t* pass --fake)
    'base_url' => env('COCHRANE_BASE_URL', 'https://www.cochranelibrary.com/en/c/portal/render_portlet'),

    // All the “fixed” params that don’t change per topic/page
    'fixed_params' => [
        'p_l_id' => '20759',
        'p_p_id' => 'scolarissearchresultsportlet_WAR_scolarissearchresults',
        'p_p_lifecycle' => '0',
        'p_t_lifecycle' => '0',
        'p_p_state' => 'normal',
        'p_p_mode' => 'view',
        'p_p_col_id' => 'column-1',
        'p_p_col_pos' => '0',
        'p_p_col_count' => '1',
        'p_p_isolated' => '1',
        'currentURL' => '/search',
        'min_year' => '',
        'max_year' => '',
        'custom_min_year' => '',
        'custom_max_year' => '',
        'searchBy' => '13',
        'selectedType' => 'review',
        'isWordVariations' => '',
        'resultPerPage' => '25',
        'searchType' => 'basic',
        'orderBy' => 'displayDate-true',
        'publishDateTo' => '',
        'publishDateFrom' => '',
        'publishYearTo' => '',
        'publishYearFrom' => '',
        'forceTypeSelection' => 'true',
        'facetQueryField' => 'topic_id',
        'facetCategory' => 'Topics',
        'pathname' => '/search',
    ],

    // HTTP client defaults
    'timeout'     => 30,
    'user_agent'  => env('COCHRANE_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0'),
    // If you ever need to hit the *real* site with cookies:
    'cookies'     => env('COCHRANE_COOKIES', ''), // e.g. "GUEST_LANGUAGE_ID=en_US; COOKIE_SUPPORT=true; ..."
];
