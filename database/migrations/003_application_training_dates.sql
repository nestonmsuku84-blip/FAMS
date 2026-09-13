-- Apply after the base FAMS schema and 002_profiles_and_draft_workflow.sql.
ALTER TABLE applications
    ADD COLUMN reporting_date DATE NULL AFTER expected_learning_objectives,
    ADD COLUMN training_end_date DATE NULL AFTER reporting_date;
