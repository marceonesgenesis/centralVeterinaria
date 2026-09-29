<?php
/**
 * ExamRequest
 *
 * Adianti TRecord binding for the `exam_request` table (T-03), used only as
 * the session-key namespace for PendingExamResultList's filter form
 * (setActiveRecord()/AdiantiStandardCollectionTrait::onSearch()), mirroring
 * CentralVet\Domain\ExamRequest's own field set. All business rules and
 * persistence go through CentralVet\Application\ExamService (T-04, already
 * implemented) — this class never calls store()/delete() on its own, and
 * PendingExamResultList never queries the `exam_request` table through it
 * either (every row comes from ExamService::listPending()).
 *
 * The `exam_request` table was created by migration
 * src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql,
 * already applied (Fase 3).
 *
 * @version    1.0
 * @package    model
 * @subpackage clinic
 */
class ExamRequest extends TRecord
{
    const TABLENAME = 'exam_request';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'serial'; // {max, serial}

    /**
     * Constructor method
     */
    public function __construct($id = NULL, $callObjectLoad = TRUE)
    {
        parent::__construct($id, $callObjectLoad);
        parent::addAttribute('tenant_id');
        parent::addAttribute('encounter_id');
        parent::addAttribute('patient_id');
        parent::addAttribute('exam_catalog_item_id');
        parent::addAttribute('professional_system_user_id');
        parent::addAttribute('status');
        parent::addAttribute('requested_at');
        parent::addAttribute('updated_at');
    }
}
