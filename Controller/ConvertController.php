<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\EmailBundle\Controller\EmailController;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\EmailToThemeBundle\Form\Type\ConvertType;
use MauticPlugin\EmailToThemeBundle\Theme\AssetCollector;
use MauticPlugin\EmailToThemeBundle\Theme\MjmlSource;
use MauticPlugin\EmailToThemeBundle\Theme\Model\ConversionRequest;
use MauticPlugin\EmailToThemeBundle\Theme\Model\ConversionResult;
use MauticPlugin\EmailToThemeBundle\Theme\ThemeCatalog;
use MauticPlugin\EmailToThemeBundle\Theme\ThemeWriteException;
use MauticPlugin\EmailToThemeBundle\Theme\ThemeWriter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Save as theme" page for one email.
 */
class ConvertController extends FormController
{
    private const MAX_LISTED_SKIPS = 5;

    public function convertAction(
        Request $request,
        EmailModel $emailModel,
        MjmlSource $mjmlSource,
        AssetCollector $collector,
        ThemeWriter $writer,
        ThemeCatalog $catalog,
        int $emailId,
    ): Response {
        $email = $emailModel->getEntity($emailId);
        if (!$email instanceof Email || null === $email->getId()) {
            return $this->redirectToEmail(null, [[
                'type'    => 'error',
                'msg'     => 'mautic.email.error.notfound',
                'msgVars' => ['%id%' => $emailId],
            ]]);
        }

        if (!$this->security->hasEntityAccess('email:emails:viewown', 'email:emails:viewother', $email->getCreatedBy())) {
            $this->throwAccessDenied();
        }
        $canCreate = $this->security->isGranted('core:themes:create');
        $canUpdate = $this->security->isGranted('core:themes:edit');
        if (!$canCreate && !$canUpdate) {
            $this->throwAccessDenied();
        }

        $mjml = $mjmlSource->forEmail($email);
        if (null === $mjml) {
            return $this->redirectToEmail($email, [['type' => 'error', 'msg' => 'mautic.email_to_theme.error.no_mjml']]);
        }

        $themes = $canUpdate ? $catalog->updatableThemes() : [];
        if (!$canCreate && [] === $themes) {
            return $this->redirectToEmail($email, [['type' => 'error', 'msg' => 'mautic.email_to_theme.error.nothing_to_update']]);
        }

        $action = $this->generateUrl('mautic_email_to_theme_convert', ['emailId' => $email->getId()]);
        $form   = $this->formFactory->create(ConvertType::class, [
            'mode' => $canCreate ? ConversionRequest::MODE_NEW : ConversionRequest::MODE_UPDATE,
            'name' => mb_substr((string) $email->getName(), 0, 100),
        ], [
            'action'    => $action,
            'themes'    => $themes,
            'allow_new' => $canCreate,
        ]);

        if ($request->isMethod('POST')) {
            if ($this->isFormCancelled($form)) {
                return $this->redirectToEmail($email);
            }

            if ($this->isFormValid($form)) {
                $data      = $form->getData();
                $thumbnail = $data['thumbnail'] ?? null;
                $conversion = new ConversionRequest(
                    (string) $data['mode'],
                    trim((string) ($data['name'] ?? '')),
                    ConversionRequest::MODE_UPDATE === $data['mode'] ? (string) $data['target'] : null,
                    $thumbnail instanceof UploadedFile ? $thumbnail->getPathname() : null,
                    (int) $email->getId(),
                    (string) $email->getName(),
                );

                try {
                    $result = $writer->write($conversion, $mjml, $collector->collect($mjml));

                    return $this->redirectToEmail($email, $this->resultFlashes($result));
                } catch (ThemeWriteException $e) {
                    $form->addError(new FormError($this->translator->trans($e->getMessage(), $e->getParameters(), 'flashes')));
                }
            }
        }

        return $this->delegateView([
            'viewParameters'  => [
                'form'   => $form->createView(),
                'email'  => $email,
                'themes' => $themes,
            ],
            'contentTemplate' => '@EmailToTheme/Convert/form.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_email_index',
                'route'         => $action,
                'mauticContent' => 'emailToTheme',
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resultFlashes(ConversionResult $result): array
    {
        $flashes = [[
            'type'    => 'notice',
            'msg'     => $result->created ? 'mautic.email_to_theme.notice.created' : 'mautic.email_to_theme.notice.updated',
            'msgVars' => [
                '%name%'   => htmlspecialchars($result->name, ENT_QUOTES),
                '%count%'  => $result->copiedImages,
                '%url%'    => $this->generateUrl('mautic_themes_index'),
                '%backup%' => htmlspecialchars(basename((string) $result->backupPath), ENT_QUOTES),
            ],
        ]];

        $listed = 0;
        foreach ($result->skipped as $url => $reason) {
            if (++$listed > self::MAX_LISTED_SKIPS) {
                $flashes[] = [
                    'type'    => 'warning',
                    'msg'     => 'mautic.email_to_theme.warning.more_skipped',
                    'msgVars' => ['%count%' => count($result->skipped) - self::MAX_LISTED_SKIPS],
                ];
                break;
            }
            $flashes[] = [
                'type'    => 'warning',
                'msg'     => 'mautic.email_to_theme.warning.skipped',
                'msgVars' => [
                    '%url%'    => htmlspecialchars(mb_strimwidth($url, 0, 120, '…'), ENT_QUOTES),
                    '%reason%' => $this->translator->trans($reason, [], 'flashes'),
                ],
            ];
        }

        return $flashes;
    }

    /**
     * @param list<array<string, mixed>> $flashes
     */
    private function redirectToEmail(?Email $email, array $flashes = []): Response
    {
        if (null === $email) {
            return $this->postActionRedirect([
                'returnUrl'       => $this->generateUrl('mautic_email_index'),
                'contentTemplate' => EmailController::class.'::indexAction',
                'passthroughVars' => ['activeLink' => '#mautic_email_index', 'mauticContent' => 'email'],
                'flashes'         => $flashes,
            ]);
        }

        return $this->postActionRedirect([
            'returnUrl'       => $this->generateUrl('mautic_email_action', ['objectAction' => 'view', 'objectId' => $email->getId()]),
            'contentTemplate' => EmailController::class.'::viewAction',
            'viewParameters'  => ['objectId' => $email->getId()],
            'passthroughVars' => ['activeLink' => '#mautic_email_index', 'mauticContent' => 'email'],
            'flashes'         => $flashes,
        ]);
    }
}
