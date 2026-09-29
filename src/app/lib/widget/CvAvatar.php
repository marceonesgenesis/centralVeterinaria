<?php
/**
 * CvAvatar — avatar placeholder (sem foto no schema): ícone da espécie ou inicial do nome.
 */
class CvAvatar
{
    private const SPECIES_ICONS = [
        'fa:dog' => ['dog', 'canine', 'canino', 'cao', 'cão', 'cachorro', 'canina'],
        'fa:cat' => ['cat', 'feline', 'felino', 'felina', 'gato', 'gata'],
    ];

    public static function placeholder(string $name, ?string $species = null): TElement
    {
        $avatar = new TElement('span');
        $avatar->{'class'} = 'cv-avatar';
        $avatar->{'title'} = $name;
        $avatar->{'aria-hidden'} = 'true';

        if ($species !== null && trim($species) !== '')
        {
            $avatar->{'class'} .= ' cv-avatar--species';
            $avatar->add(new TImage(self::speciesIcon($species)));
            return $avatar;
        }

        $initial = mb_strtoupper(mb_substr(trim($name), 0, 1, 'UTF-8'), 'UTF-8');
        $avatar->add($initial !== '' ? CvFormat::e($initial) : new TImage('fa:paw'));

        return $avatar;
    }

    public static function speciesIcon(string $species): string
    {
        $normalized = mb_strtolower(trim($species), 'UTF-8');

        foreach (self::SPECIES_ICONS as $icon => $words)
        {
            foreach ($words as $word)
            {
                if (str_contains($normalized, $word))
                {
                    return $icon;
                }
            }
        }

        return 'fa:paw';
    }
}
