<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\EmailToThemeBundle\Theme\MjmlSource;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds "Save as theme" to the actions of an email's detail page, for emails with MJML only.
 */
class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CorePermissions $security,
        private readonly MjmlSource $mjmlSource,
        private readonly RouterInterface $router,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    public function injectViewButtons(CustomButtonEvent $event): void
    {
        if (!str_contains((string) $event->getRoute(), 'mautic_email_action')
            || !$event->checkLocationContext(ButtonHelper::LOCATION_PAGE_ACTIONS)) {
            return;
        }

        $email = $event->getItem();
        if (!$email instanceof Email || null === $email->getId()) {
            return;
        }

        if (!$this->security->isGranted('core:themes:create') && !$this->security->isGranted('core:themes:edit')) {
            return;
        }

        if (null === $this->mjmlSource->forEmail($email)) {
            return;
        }

        $event->addButton(
            [
                'attr' => [
                    'href'        => $this->router->generate('mautic_email_to_theme_convert', ['emailId' => $email->getId()]),
                    'data-toggle' => 'ajax',
                ],
                'btnText'   => $this->translator->trans('mautic.email_to_theme.button'),
                'iconClass' => 'ri-palette-line',
                'priority'  => -10,
            ],
            ButtonHelper::LOCATION_PAGE_ACTIONS
        );
    }
}
