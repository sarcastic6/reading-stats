<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ReadingEntryFormDto;
use App\Entity\Metadata;
use App\Entity\MetadataType;
use App\Entity\Status;
use App\Entity\Work;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReadingEntryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Work|null $work */
        $work = $options['work'];

        $builder
            ->add('status', EntityType::class, [
                'label' => 'reading.field.status',
                'class' => Status::class,
                'choice_label' => 'name',
                'placeholder' => '',
            ])
            ->add('dateStarted', DateType::class, [
                'label' => 'reading.field.date_started',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('dateFinished', DateType::class, [
                'label' => 'reading.field.date_finished',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('lastReadChapter', IntegerType::class, [
                'label' => 'reading.field.last_chapter',
                'required' => false,
            ])
            ->add('reviewStars', ChoiceType::class, [
                'label' => 'reading.field.review_stars',
                'choices' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
                'choice_label' => static fn (int $v) => str_repeat('⭐', $v),
                'placeholder' => '',
                'required' => false,
            ])
            ->add('spiceStars', ChoiceType::class, [
                'label' => 'reading.field.spice_stars',
                'choices' => [0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
                'choice_label' => static fn (int $v) => $v === 0 ? '🚫' : str_repeat('🔥', $v),
                'placeholder' => '',
                'required' => false,
            ])
            ->add('mainPairing', EntityType::class, [
                'label' => 'reading.field.main_pairing',
                'class' => Metadata::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '',
                'query_builder' => static function (EntityRepository $er) use ($work) {
                    $qb = $er->createQueryBuilder('m')
                        ->innerJoin('m.metadataType', 'mt')
                        ->where('mt.name = :typeName')
                        ->setParameter('typeName', 'Relationships')
                        ->orderBy('m.name', 'ASC');

                    // Narrow to pairings associated with the selected work, if available
                    if ($work !== null) {
                        $qb->innerJoin(
                            'App\Entity\Work',
                            'w',
                            'ON',
                            'w.id = :workId AND m MEMBER OF w.metadata',
                        )
                        ->setParameter('workId', $work->getId());
                    }

                    return $qb;
                },
            ])
            ->add('comments', TextareaType::class, [
                'label' => 'reading.field.comments',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('pinned', CheckboxType::class, [
                'label' => 'reading.field.pinned',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReadingEntryFormDto::class,
            'work' => null,
            'translation_domain' => 'messages',
        ]);

        $resolver->setAllowedTypes('work', ['null', Work::class]);
    }
}
