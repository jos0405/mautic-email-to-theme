<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Theme;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder;

/**
 * Reads the MJML that the GrapesJS builder saved for an email.
 * Emails built in code mode or with the legacy builder have none.
 */
class MjmlSource
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function forEmail(Email $email): ?string
    {
        if (null === $email->getId()) {
            return null;
        }

        $builder = $this->em->getRepository(GrapesJsBuilder::class)->findOneBy(['email' => $email->getId()]);
        if (!$builder instanceof GrapesJsBuilder) {
            return null;
        }

        $mjml = trim((string) $builder->getCustomMjml());

        return self::isMjml($mjml) ? $mjml : null;
    }

    public static function isMjml(string $mjml): bool
    {
        return 1 === preg_match('/^\s*(<\?xml[^>]*>\s*)?<mjml[\s>]/i', $mjml)
            && false !== stripos($mjml, '</mjml>');
    }
}
