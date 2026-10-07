<?php
/**
 * CvNav — grupos de abas de módulo (financeiro, estoque, serviços, internação, cirurgia, prescrição, comunicação).
 * Abas sem tela real ficam desabilitadas ("Em breve").
 */
class CvNav
{
    public static function tabs(string $group, string $active): TElement
    {
        return CvPage::tabs(self::group($group), $active);
    }

    /**
     * @return array<string, array{label: string, href: ?string}>
     */
    public static function group(string $group): array
    {
        $groups = [
            'finance' => [
                'overview'    => ['Overview',    'index.php?class=FinancialOverview'],
                'revenues'    => ['Revenues',    'index.php?class=FinancialEntryList&entry_type=income'],
                'expenses'    => ['Expenses',    'index.php?class=FinancialEntryList&entry_type=expense'],
                'payables'    => ['Payables',    'index.php?class=PayableList'],
                'receivables' => ['Receivables', 'index.php?class=PendingReceivableList'],
                'cashflow'    => ['Cash flow',   'index.php?class=CashSessionList'],
                'bank_accounts' => ['Bank accounts', 'index.php?class=BankAccountList'],
            ],
            'stock' => [
                'products'   => ['Products',   'index.php?class=ProductList'],
                'sales'      => ['Sales',      'index.php?class=SaleForm'],
                'movements'  => ['Movements',  null],
                'categories' => ['Categories', null],
                'suppliers'  => ['Suppliers',  null],
                'reports'    => ['Reports',    null],
            ],
            'services' => [
                'services'   => ['Services',   'index.php?class=ServiceList'],
                'categories' => ['Categories', null],
                'packages'   => ['Packages',   null],
                'pricing'    => ['Pricing',    null],
            ],
            'hospitalization' => [
                'board' => ['Board', 'index.php?class=HospitalizationBoard'],
                'beds'  => ['Beds',  'index.php?class=BedList'],
            ],
            'surgery' => [
                'list'  => ['Surgeries', 'index.php?class=SurgeryList'],
                'rooms' => ['Rooms',     'index.php?class=SurgeryRoomList'],
            ],
            'communication' => [
                'messages'  => ['Messages',          'index.php?class=CommunicationMessageList'],
                'templates' => ['Message templates', 'index.php?class=MessageTemplateList'],
            ],
            'prescription' => [
                'new'       => ['New prescription',     self::prescriptionHref(false)],
                'history'   => ['Prescription history', self::prescriptionHref(true)],
                'templates' => ['Templates',            null],
            ],
        ];

        if (!isset($groups[$group]))
        {
            throw new InvalidArgumentException('Unknown CvNav group: ' . $group);
        }

        $tabs = [];
        foreach ($groups[$group] as $key => [$label, $href])
        {
            $tabs[$key] = ['label' => _t($label), 'href' => $href];
        }

        return $tabs;
    }

    /**
     * PrescriptionForm é contextual: as abas repassam encounter_id/patient_id da requisição atual.
     */
    private static function prescriptionHref(bool $history): string
    {
        $params = ['class' => 'PrescriptionForm'];
        foreach (['encounter_id', 'patient_id'] as $key)
        {
            if (isset($_REQUEST[$key]) && (int) $_REQUEST[$key] > 0)
            {
                $params[$key] = (int) $_REQUEST[$key];
            }
        }
        if ($history)
        {
            $params['tab'] = 'history';
        }

        return 'index.php?' . http_build_query($params);
    }
}
