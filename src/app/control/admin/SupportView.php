<?php
/**
 * SupportView
 *
 * Tela "Ajuda" do piloto (Etapa 5, T-17): canais do suporte vindos de
 * CentralVet\Pilot\PilotSettings (SUPPORT_EMAIL obrigatório, SUPPORT_WHATSAPP
 * opcional, SUPPORT_HOURS), links para os Termos de Uso, a Política de
 * Privacidade e a política de cancelamento (âncora #cancelamento nos
 * termos). Sem e-mail configurado mostra "Canal de suporte não
 * configurado". Só leitura: nenhuma ação muda dado.
 *
 * @version    8.6
 * @package    control
 * @subpackage admin
 * @license    https://adiantiframework.com.br/license-template
 */
class SupportView extends TPage
{
    public function __construct($param = null)
    {
        parent::__construct();

        $settings = CentralVet\Pilot\PilotSettings::fromEnvironment();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Support'), null, [], false));
        $container->add(self::channelsCard($settings));
        $container->add(self::legalCard());

        parent::add($container);
    }

    private static function channelsCard(CentralVet\Pilot\PilotSettings $settings): TElement
    {
        $card = self::card(_t('Support'));
        $body = $card->get(1);

        $email = $settings->supportEmail();
        if ($email === null)
        {
            $alert = new TElement('div');
            $alert->{'class'} = 'alert alert-warning';
            $alert->{'role'} = 'status';
            $alert->add(CvFormat::e(_t('Support channel not configured')));
            $body->add($alert);
        }

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table cv-support-channels';
        $table->style = 'width:100%';
        $tbody = new TElement('tbody');

        if ($email !== null)
        {
            $tbody->add(self::row(_t('Support e-mail'), self::link('mailto:' . $email, 'fa:envelope', $email)));
        }

        $whatsapp = $settings->supportWhatsapp();
        if ($whatsapp !== null)
        {
            $tbody->add(self::row('WhatsApp', self::link('https://wa.me/' . $whatsapp, 'fab:whatsapp', '+' . $whatsapp, true)));
        }

        $tbody->add(self::row(_t('Service hours'), CvFormat::e($settings->supportHours())));

        $table->add($tbody);
        $body->add($table);

        return $card;
    }

    private static function legalCard(): TElement
    {
        $card = self::card(_t('Terms of use'));
        $body = $card->get(1);

        $list = new TElement('ul');
        $list->{'class'} = 'list-unstyled cv-support-legal';
        foreach ([
            [CentralVet\Legal\LegalDocuments::TERMS_URL, _t('Terms of use')],
            [CentralVet\Legal\LegalDocuments::PRIVACY_URL, _t('Privacy policy')],
            [CentralVet\Legal\LegalDocuments::TERMS_URL . '#cancelamento', _t('Cancellation policy')],
        ] as [$href, $label])
        {
            $item = new TElement('li');
            $item->add(self::link($href, 'fa:file-alt', $label, true));
            $list->add($item);
        }
        $body->add($list);

        return $card;
    }

    /** Linha rótulo (à esquerda) + valor. */
    private static function row(string $label, TElement|string $value): TElement
    {
        $tr = new TElement('tr');
        $tr->add(TElement::tag('th', CvFormat::e($label), ['scope' => 'row', 'style' => 'text-align:left;width:220px']));
        $cell = new TElement('td');
        $cell->add($value);
        $tr->add($cell);

        return $tr;
    }

    private static function link(string $href, string $icon, string $label, bool $newTab = false): TElement
    {
        $link = new TElement('a');
        $link->{'class'} = 'btn btn-link cv-touch-target';
        $link->{'href'} = CvFormat::e($href);
        if ($newTab)
        {
            $link->{'target'} = '_blank';
            $link->{'rel'} = 'noopener';
        }
        $link->add(new TImage($icon));
        $link->add(TElement::tag('span', CvFormat::e($label)));

        return $link;
    }

    /** Card com cabeçalho (filho 0) e corpo (filho 1). */
    private static function card(string $title): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';

        $header = new TElement('div');
        $header->{'class'} = 'cv-card__header';
        $header->add(TElement::tag('h2', CvFormat::e($title), ['class' => 'cv-card__title']));
        $card->add($header);

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $card->add($body);

        return $card;
    }
}
