-- Additional junk rows that survived the earlier address-only cleanup.
-- These are raw-address names or CSV-header labels found while building
-- the locations_master migration.

START TRANSACTION;

DELETE FROM `facilities_master` WHERE `unique_name` IN (
  'Active / Operating Facilities',
  'Confirmed Closed Facilities',
  '1710 Mount Silliman Way, Antioch, CA 94530',
  '6222 Wilshire Blvd Suite 313, Los Angeles, CA 90048;',
  '2136 Cutler St, Simi Valley, CA 93065;',
  '1400 Lawrence Rd. Danville, CA 94506'
);

-- Should report 6 rows affected.
-- COMMIT;
-- ROLLBACK;
