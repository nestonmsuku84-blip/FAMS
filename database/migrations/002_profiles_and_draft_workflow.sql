-- Apply after the base FAMS schema.  Profile images are shared by every role.
ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER phone_number;

