-- Existing submissions for a specialization without an active department
-- reviewer must enter the FAMS Officer queue rather than remain stranded.
UPDATE applications a
SET a.status = 'under_review'
WHERE a.status IN ('submitted', 'resubmitted')
  AND NOT EXISTS (
      SELECT 1
      FROM application_specializations aps
      JOIN department_officer_scopes dos ON dos.specialization_id = aps.specialization_id
      JOIN users reviewer ON reviewer.id = dos.user_id AND reviewer.status = 'active'
      WHERE aps.application_id = a.id AND aps.priority_order = 1
  );
