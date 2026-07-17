<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Form\Type\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Block;
use Eccube\Form\Validator\TwigLint;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;

class BlockType extends AbstractType
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * BlockType constructor.
     *
     * @param $entityManager
     * @param EccubeConfig $eccubeConfig
     */
    public function __construct(EntityManagerInterface $entityManager, EccubeConfig $eccubeConfig)
    {
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_stext_len'],
                    ]),
                ],
            ])
            ->add('file_name', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length([
                        'max' => $this->eccubeConfig['eccube_stext_len'],
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[0-9a-zA-Z\/_]+$/',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^(?!.*\/\/).+$/',
                    ]),
                ],
            ])
            ->add('block_html', TextareaType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new TwigLint(),
                ],
            ])
            ->add('DeviceType', EntityType::class, [
                'class' => \Eccube\Entity\Master\DeviceType::class,
                'choice_label' => 'id',
            ])
            ->add('visible_from', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime',
            ])
            ->add('visible_to', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime',
            ])
            ->add('fallback_block', EntityType::class, [
                'class' => Block::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'admin.common.select',
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($options) {
                    $Block = $options['data'] ?? null;
                    $blockId = $Block ? $Block->getId() : null;
                    $deviceType = $Block ? $Block->getDeviceType() : null;

                    $qb = $er->createQueryBuilder('b');
                    if ($deviceType) {
                        $qb->andWhere('b.DeviceType = :deviceType')
                           ->setParameter('deviceType', $deviceType);
                    }
                    if ($blockId) {
                        $qb->andWhere('b.id <> :blockId')
                           ->setParameter('blockId', $blockId);
                    }
                    return $qb->orderBy('b.name', 'ASC');
                },
            ])
            ->add('id', HiddenType::class)
            ->addEventListener(FormEvents::POST_SUBMIT, function ($event) {
                $form = $event->getForm();
                $file_name = $form['file_name']->getData();
                $DeviceType = $form['DeviceType']->getData();
                $block_id = $form['id']->getData();

                $qb = $this->entityManager->createQueryBuilder();
                $qb->select('b')
                    ->from(Block::class, 'b')
                    ->where('b.file_name = :file_name')
                    ->setParameter('file_name', $file_name)
                    ->andWhere('b.DeviceType = :DeviceType')
                    ->setParameter('DeviceType', $DeviceType);
                if (isset($block_id)) {
                    $qb
                        ->andWhere('b.id <> :block_id')
                        ->setParameter('block_id', $block_id);
                }

                $Block = $qb
                    ->getQuery()
                    ->getResult();
                if (count($Block) > 0) {
                    $form['file_name']->addError(new FormError(trans('admin.content.block_file_name_exists')));
                }

                // Validation cho visible_from, visible_to và fallback_block
                $visible_from = $form['visible_from']->getData();
                $visible_to = $form['visible_to']->getData();
                $fallback_block = $form['fallback_block']->getData();

                if ($visible_from && $visible_to && $visible_from > $visible_to) {
                    $form['visible_to']->addError(new FormError(trans('admin.content.block_visible_to_invalid')));
                }

                $hasTimer = ($visible_from !== null || $visible_to !== null);
                $hasFallback = ($fallback_block !== null);

                if ($hasTimer && !$hasFallback) {
                    $form['fallback_block']->addError(new FormError(trans('admin.content.block_fallback_required')));
                }

                if (!$hasTimer && $hasFallback) {
                    $form['visible_from']->addError(new FormError(trans('admin.content.block_timer_required')));
                }
            });
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Block::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'block';
    }
}
