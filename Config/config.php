<?php

declare(strict_types=1);

use MauticPlugin\EmailToThemeBundle\Controller\ConvertController;

return [
    'name'        => 'Email to Theme',
    'description' => 'Turn an email built with the drag-and-drop (MJML) builder into an email theme, or update an existing theme from it.',
    'version'     => '1.0.0',
    'author'      => 'Friendly',
    'routes'      => [
        'main' => [
            'mautic_email_to_theme_convert' => [
                'path'         => '/email-to-theme/{emailId}',
                'controller'   => ConvertController::class.'::convertAction',
                'method'       => 'GET|POST',
                'requirements' => ['emailId' => '\d+'],
            ],
        ],
    ],
    'parameters' => [
        // Largest image (in bytes) copied into a theme's assets folder.
        'email_to_theme_max_image_bytes' => 5 * 1024 * 1024,
        // Download images that are not stored on this Mautic (public addresses only).
        'email_to_theme_fetch_remote'    => true,
    ],
];
