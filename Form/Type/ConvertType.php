<?php

declare(strict_types=1);

namespace MauticPlugin\EmailToThemeBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use MauticPlugin\EmailToThemeBundle\Theme\Model\ConversionRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Save as theme": a new theme, or an update of an existing one.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class ConvertType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $modes = [];
        if ($options['allow_new']) {
            $modes['mautic.email_to_theme.form.mode.new'] = ConversionRequest::MODE_NEW;
        }
        if ([] !== $options['themes']) {
            $modes['mautic.email_to_theme.form.mode.update'] = ConversionRequest::MODE_UPDATE;
        }

        $builder->add('mode', ChoiceType::class, [
            'label'       => 'mautic.email_to_theme.form.mode',
            'label_attr'  => ['class' => 'control-label'],
            'choices'     => $modes,
            'expanded'    => true,
            'multiple'    => false,
            'placeholder' => false,
            'attr'        => ['class' => 'email-to-theme-mode'],
        ]);

        $builder->add('name', TextType::class, [
            'label'       => 'mautic.email_to_theme.form.name',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'attr'        => ['class' => 'form-control', 'maxlength' => 100, 'tooltip' => 'mautic.email_to_theme.form.name.tooltip'],
            'constraints' => [new Length(['max' => 100])],
        ]);

        $builder->add('target', ChoiceType::class, [
            'label'       => 'mautic.email_to_theme.form.target',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'choices'     => array_flip($options['themes']),
            'placeholder' => 'mautic.email_to_theme.form.target.placeholder',
            'attr'        => ['class' => 'form-control', 'tooltip' => 'mautic.email_to_theme.form.target.tooltip'],
        ]);

        $builder->add('thumbnail', FileType::class, [
            'label'       => 'mautic.email_to_theme.form.thumbnail',
            'label_attr'  => ['class' => 'control-label'],
            'required'    => false,
            'attr'        => ['class' => 'form-control', 'accept' => 'image/png,image/jpeg,image/gif,image/webp', 'tooltip' => 'mautic.email_to_theme.form.thumbnail.tooltip'],
            'constraints' => [new File([
                'maxSize'          => '5M',
                'mimeTypes'        => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
                'mimeTypesMessage' => 'mautic.email_to_theme.error.thumbnail',
            ])],
        ]);

        $builder->add('buttons', FormButtonsType::class, [
            'apply_text' => false,
            'save_text'  => 'mautic.email_to_theme.form.save',
            'save_icon'  => 'ri-palette-line',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'themes'      => [],
            'allow_new'   => true,
            'constraints' => [new Callback([$this, 'validate'])],
        ]);
        $resolver->setAllowedTypes('themes', 'array');
        $resolver->setAllowedTypes('allow_new', 'bool');
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function validate(?array $data, ExecutionContextInterface $context): void
    {
        $mode = $data['mode'] ?? null;
        if (ConversionRequest::MODE_NEW === $mode && '' === trim((string) ($data['name'] ?? ''))) {
            $context->buildViolation('mautic.email_to_theme.error.name_required')->atPath('[name]')->addViolation();
        }
        if (ConversionRequest::MODE_UPDATE === $mode && empty($data['target'])) {
            $context->buildViolation('mautic.email_to_theme.error.target_required')->atPath('[target]')->addViolation();
        }
    }

    public function getBlockPrefix(): string
    {
        return 'email_to_theme';
    }
}
