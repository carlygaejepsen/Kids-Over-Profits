-- Delete source rows from facilities_master after the migration importer succeeds.
-- Do NOT run before locations_master has been updated.

START TRANSACTION;

DELETE FROM `facilities_master` WHERE `unique_name` IN (
  'Adolescent Recovery of Cumberland Heights / DBA ARCH Academy',
  'Alternatives Residential Care Homes, LLC',
  'Behavior Training Research',
  'Boys Home of the South',
  'Boys Republic Laws Cottage STRTP',
  'Braley & Thompson ACCT House',
  'Braveheart Academy',
  'Campbell Regional Juvenile Detention Center',
  'Cheltenham Youth Facility',
  'Cherokee Home for Children',
  'ChildrenFirst Residential Care',
  'ChildrenFirst Residential Care- Gasmer',
  'Chrysalis Treatment Center',
  'Community Receiving Home, Inc. d/b/a Renaissance',
  'Costello Prep Residential Community Home',
  'D.O.V.E.S. Residential Community Home',
  'Elk River Treatment Program (CLOSED',
  'Ettie Lee Homes - Diamond L. Ranch',
  'Green Ridge Youth Center',
  'Hope for Tomorrow dba Bridge to Hope',
  'J. DeWeese Carter Center',
  'JBFCS Henry Ittleson Community Residence I',
  'Lamar House – Sunnyvale, CA',
  'Mables Home',
  'Meadow Mountain Youth Center',
  'Mount Arukah',
  'Mt. Gilead Children’s Home',
  'Murphy Bernardini Regional Juvenile Detention Center',
  'New Hope Integrated Behavioral Healthcare',
  'Open Line - The Oaks',
  'Open Line - Woodcliff',
  'Outside In Pathway to Recovery',
  'Pathways Youth & Family Services, Inc. dba Habilitative Homes',
  'Pearl of Grace Ranch',
  'Pleasant Hills Children’s Home',
  'Prairie View Therapeutic Group Homes',
  'Presbyterian Hospitality House - Granville Home',
  'RISE',
  'Restoration Crisis Center Therapeutic Home',
  'Restoration Ranch',
  'Sawmill Academy for Girls',
  'Southeastern Psychiatric Management dba Mountain View Hospital Summit Crest Lodge',
  'Stepping Stone Services',
  'The Jim H. Green Kidz Harbor, Inc. Dba Kidz Harbor Home',
  'The Wardle Home',
  'Therapy Associates Dba Star Guides',
  'Thomas J.S. Waxter Children’s Center',
  'Total Access Care Liberty',
  'Triumph Youth Services/Triumph Academy – Expeditions',
  'Victor Treatment Center - Romberger House',
  'Vinetta Green Home',
  'Vista Del Mar Child and Family Services STRTP'
);

-- Should report 52 rows affected.
-- COMMIT;
-- ROLLBACK;
