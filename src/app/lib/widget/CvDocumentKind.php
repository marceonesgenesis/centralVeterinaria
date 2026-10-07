<?php
/**
 * CvDocumentKind — rótulos traduzidos dos tipos de documento (Fase 7B).
 *
 * label(): nome do tipo nos formulários (pedido e templates).
 * title(): título do documento na lista, pelo kind e na locale da sessão
 * (o título gravado em generated_document é o pt fixo do domínio e não vai
 * à tela).
 *
 * Texto puro: quem imprime escapa.
 *
 * @version    1.0
 * @package    lib
 * @subpackage widget
 */
class CvDocumentKind
{
    public static function label(string $kind): string
    {
        return match ($kind) {
            \CentralVet\Domain\DocumentKind::VACCINATION_CARD => _t('Vaccination card'),
            \CentralVet\Domain\DocumentKind::PRESCRIPTION => _t('Prescription'),
            \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE => _t('Medical certificate'),
            \CentralVet\Domain\DocumentKind::SURGERY_CONSENT => _t('Surgery consent'),
            default => $kind,
        };
    }

    public static function title(string $kind): string
    {
        return match ($kind) {
            \CentralVet\Domain\DocumentKind::VACCINATION_CARD => _t('Vaccination card'),
            \CentralVet\Domain\DocumentKind::PRESCRIPTION => _t('Prescription document'),
            \CentralVet\Domain\DocumentKind::MEDICAL_CERTIFICATE => _t('Medical certificate'),
            \CentralVet\Domain\DocumentKind::SURGERY_CONSENT => _t('Surgical consent form'),
            default => $kind,
        };
    }
}
