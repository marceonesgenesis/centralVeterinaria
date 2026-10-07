<?php
/**
 * CvCatalog — critérios compartilhados dos combos de catálogo do kit Cv*.
 */
class CvCatalog
{
    /**
     * Critério do combo de catálogo: itens ativos do tenant e, quando há item
     * atual, também ele (mesmo inativo), para o combo não perder o vínculo.
     */
    public static function activeOrCurrentCriteria(int $tenantId, ?int $currentId): TCriteria
    {
        $criteria = new TCriteria;
        $criteria->add(new TFilter('tenant_id', '=', $tenantId));

        if ($currentId === null)
        {
            $criteria->add(new TFilter('active', '=', 1));
            return $criteria;
        }

        $visible = new TCriteria;
        $visible->add(new TFilter('active', '=', 1));
        $visible->add(new TFilter('id', '=', $currentId), TExpression::OR_OPERATOR);
        $criteria->add($visible);

        return $criteria;
    }
}
