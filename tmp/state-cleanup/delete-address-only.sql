-- Delete 20 raw-address-named CA projects from facilities_master.
-- These projects had real CA addresses but every facility inside used the
-- project name as its identification.name -- no real facility names.
-- CALIFORNIA mega already has 633 properly-named entries; no data loss here.

START TRANSACTION;

DELETE FROM `facilities_master` WHERE `unique_name` IN (
  '8983 Coan Ln, Orangevale, CA 95662;',
  '1632 E Dimondale Dr, Carson, CA 90746;',
  '30 Country Wood Dr, Phillips Ranch, CA 91766;',
  '795 Eden Plains Rd, Brentwood, CA 94513;',
  '3718 Dimaggio Way, Antioch, CA 94509;',
  '7250 Wiest Rd, Calipatria, CA 92233;',
  '7760 Joyce Dr, Sebastopol, CA 95472;',
  '1564 W 36th Pl, Los Angeles, CA 90018;',
  '33335 Mulholland Hwy, Malibu, CA 90265;',
  '3817 Edith Ln, Bakersfield, CA 93304;',
  'Trabuco Canyon, CA;',
  'Davis, CA;',
  'Palm Desert, CA;',
  'San Francisco, CA;',
  '2390 Portola St, San Bernardino, CA 92407',
  '215 W La Verne Ave, Pomona, CA 91767;',
  '238 S Flower St, Orange, CA 92868;',
  '2464 Slew of Gold Ct, Perris, CA 92571;',
  '25123 Middlebrook Way, Moreno Valley, CA 92551;',
  '18646 W Oxnard St, Tarzana, CA 91356;'
);

-- Should report 20 rows affected. To commit:
-- COMMIT;
-- ROLLBACK;
