<?php
return [

    'securityToken' => null,

    'retrieverMap' => [
        $_SERVER['HTTP_HOST'] => $_SERVER['DOCUMENT_ROOT']
    ],

    'cache' => [
        'cacheRoot'   => sys_get_temp_dir() . '/phast-cache-' . posix_geteuid(),
        'garbageCollection' => [
            'maxItems'    => 100,
            'probability' => 0.1,
            'maxAge' => 86400 * 365
        ]
    ],

    'servicesUrl' => '/phast.php',

    'documents' => [
        'maxBufferSizeToApply' => pow(1024, 3),

        'baseUrl' => (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
            . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],

        'filters' => [

            \Kibo\Phast\Filters\HTML\ImagesOptimizationServiceHTMLFilter::class => [
                'rewriteFormat' => \Kibo\Phast\Services\ServiceRequest::FORMAT_PATH
            ],

            \Kibo\Phast\Filters\HTML\CSSInliningHTMLFilter::class => [
                'urlRefreshTime' => 7200,
                'whitelist' => [
                    '~^https?://' . preg_quote($_SERVER['HTTP_HOST'], '~') . '/~',
                    '~^https?://fonts\.googleapis\.com/css~' => [
                        'ieCompatible' => false
                    ],
                    '~^https?://ajax\.googleapis\.com/ajax/libs/jqueryui/~'
                ]
            ],

            \Kibo\Phast\Filters\HTML\CSSImagesOptimizationServiceHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\CSSOptimizingHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\CSSDeferHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\IFrameDelayedLoadingHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\ScriptsRearrangementHTMLFilter::class => [],

            \Kibo\Phast\Filters\HTML\ScriptProxyServiceHTMLFilter::class => [
                'urlRefreshTime' => 7200,
                'match' => [
                    '~^https?://' . preg_quote($_SERVER['HTTP_HOST'], '~') . '/~',
                    '~^https?://(ssl|www)\.google-analytics\.com/~',
                    '~^https?://static\.hotjar\.com/~'
                ]
            ],

            \Kibo\Phast\Filters\HTML\DiagnosticsHTMLFilter::class => [
                'enabled' => 'diagnostics'
            ],

            \Kibo\Phast\Filters\HTML\ScriptsDeferHTMLFilter::class => [],

        ]
    ],

    'images' => [
        'enable-cache' => 'imgcache',

        'whitelist' => [
            '~^https?://' . preg_quote($_SERVER['HTTP_HOST'], '~') . '/~',
            '~^https?://ajax\.googleapis\.com/ajax/libs/jqueryui/~'
        ],

        'filters' => [
            \Kibo\Phast\Filters\Image\Resizer\Filter::class => [
                'defaultMaxWidth'  => 1920 * 2,
                'defaultMaxHeight' => 1080 * 2
            ],

            \Kibo\Phast\Filters\Image\Compression\Filter::class => [
                \Kibo\Phast\Filters\Image\Image::TYPE_PNG  =>  9,
                \Kibo\Phast\Filters\Image\Image::TYPE_JPEG => 80
            ],

            \Kibo\Phast\Filters\Image\WEBPEncoder\Filter::class => [
                'enabled'     => function_exists('imagewebp'),
                'compression' => 80
            ],

            \Kibo\Phast\Filters\Image\PNGQuantCompression\Filter::class => [
                'enabled' => @file_exists('/usr/bin/pngquant'),
                'cmdpath' => '/usr/bin/pngquant',
                'quality' => '50-85'
            ],

            \Kibo\Phast\Filters\Image\JPEGTransEnhancer\Filter::class => [
                'enabled' => @file_exists('/usr/bin/jpegtran'),
                'cmdpath' => '/usr/bin/jpegtran'
            ]
        ]
    ],

    'logging' => [
        'logWriters' => [
            [
                'class' => \Kibo\Phast\Logging\LogWriters\PHPErrorLogWriter::class,
                'levelMask' =>
                    \Kibo\Phast\Logging\LogLevel::EMERGENCY
                    | \Kibo\Phast\Logging\LogLevel::ALERT
                    | \Kibo\Phast\Logging\LogLevel::CRITICAL
                    | \Kibo\Phast\Logging\LogLevel::ERROR
                    | \Kibo\Phast\Logging\LogLevel::WARNING
            ],
            [
                'enabled' => 'diagnostics',
                'class' => \Kibo\Phast\Logging\LogWriters\JSONLFileLogWriter::class,
                'logRoot' => sys_get_temp_dir() . '/phast-logs'
            ]
        ]
    ],

    'switches' => [
        'phast' => true,
        'diagnostics' => false
    ],

    'scripts' => [
        'removeLicenseHeaders' => false
    ]
];

