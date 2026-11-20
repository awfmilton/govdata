-- Merge 20 duplicate mp_persons rows created by a 2025 import (ids 1327-1346) into the original person.
--
-- The duplicates have no OpenParliament link, and each has one "45th Parliament" mandate starting 2025-04-28
-- with no riding. Several of those mandates are wrong (Neil Ellis left in 2021; Findlay, Kitchen, Serre,
-- McLeod, Van Bynen, Martinez Ferrada and Kurek did not sit in the 45th Parliament per op_core_electedmember).
-- The original rows carry the OpenParliament-sourced mandates, so the duplicate mandates are dropped.
--
-- Mapping checked by hand against op_core_politician / op_core_electedmember (2026-11-20). Rachael Harder is
-- the earlier surname of Rachael Thomas (op politician 9400); "Robert J. Morrissey" is Bobby Morrissey.
--
-- Audit: every merged person row and its dropped mandates are kept as JSON in mp_persons_merges, with the
-- reference counts repointed. Re-runnable: rows already merged are skipped.

CREATE TABLE IF NOT EXISTS mp_persons_merges (
  dup_id         int unsigned NOT NULL PRIMARY KEY,
  kept_id        int unsigned NOT NULL,
  reason         varchar(300) NOT NULL,
  dup_row        json NOT NULL,
  dup_mandates   json NOT NULL,
  repointed      json NOT NULL,
  merged_at      datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TEMPORARY TABLE IF EXISTS t_merge;
CREATE TEMPORARY TABLE t_merge (dup_id int unsigned PRIMARY KEY, kept_id int unsigned NOT NULL);
INSERT INTO t_merge VALUES
 (1327,1088),(1328,901),(1329,155),(1330,800),(1331,157),(1332,952),(1333,1140),(1334,699),(1335,1101),(1336,993),
 (1337,1087),(1338,1020),(1339,1124),(1340,945),(1341,975),(1342,287),(1343,1038),(1344,999),(1345,784),(1346,305);
DELETE t FROM t_merge t JOIN mp_persons_merges m ON m.dup_id = t.dup_id;

START TRANSACTION;

INSERT INTO mp_persons_merges
SELECT t.dup_id, t.kept_id, 'duplicate from 2025 import; kept the OpenParliament-linked record',
       JSON_OBJECT('id', d.id, 'first_name', d.first_name, 'last_name', d.last_name, 'ourcommons_id', d.ourcommons_id,
                   'email', d.email, 'postal_code', d.postal_code, 'source_url', d.source_url),
       IFNULL((SELECT JSON_ARRAYAGG(JSON_OBJECT('id', m.id, 'parliament', m.parliament, 'start_date', m.start_date,
                                                'end_date', m.end_date, 'party', m.party, 'riding_id', m.riding_id))
                 FROM mp_mandates m WHERE m.mp_id = t.dup_id), JSON_ARRAY()),
       JSON_OBJECT('lob_dpoh_mp_match', (SELECT COUNT(*) FROM lob_dpoh_mp_match l WHERE l.mp_person_id = t.dup_id),
                   'mps', (SELECT COUNT(*) FROM mps x WHERE x.mp_persons_id = t.dup_id),
                   'hoc_exp_summary', (SELECT COUNT(*) FROM hoc_exp_summary s WHERE s.mp_person_id = t.dup_id),
                   'audit_flags', (SELECT COUNT(*) FROM audit_flags f WHERE f.mp_person_id = t.dup_id)),
       UTC_TIMESTAMP()
  FROM t_merge t JOIN mp_persons d ON d.id = t.dup_id;

-- Repoint references. (hoc_exp_* and audit_flags are rebuilt afterwards; repointing keeps them consistent now.)
UPDATE lob_dpoh_mp_match l JOIN t_merge t ON t.dup_id = l.mp_person_id SET l.mp_person_id = t.kept_id,
       l.note = CONCAT_WS('; ', l.note, CONCAT('repointed from duplicate mp_persons ', t.dup_id));
UPDATE mps x JOIN t_merge t ON t.dup_id = x.mp_persons_id SET x.mp_persons_id = t.kept_id;
UPDATE hoc_exp_summary s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;
UPDATE hoc_exp_members s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;
UPDATE hoc_exp_contracts s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;
UPDATE hoc_exp_hospitality s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;
UPDATE hoc_exp_travel_claims s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;
UPDATE hoc_exp_travel_legs s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;
UPDATE audit_flags s JOIN t_merge t ON t.dup_id = s.mp_person_id SET s.mp_person_id = t.kept_id;

DELETE m FROM mp_mandates m JOIN t_merge t ON t.dup_id = m.mp_id;
DELETE d FROM mp_persons d JOIN t_merge t ON t.dup_id = d.id;

COMMIT;

SELECT COUNT(*) merged_total FROM mp_persons_merges;
SELECT COUNT(*) remaining_unlinked_persons_with_p45 FROM mp_persons p
 WHERE p.op_politician_id IS NULL AND EXISTS (SELECT 1 FROM mp_mandates m WHERE m.mp_id = p.id AND m.parliament = 45);
