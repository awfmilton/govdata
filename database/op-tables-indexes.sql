-- Secondary indexes for the OpenParliament tables (SCH-11, SCH-12, 2026-11-18).
-- import-parldata.py creates op_* tables with a primary key only; every join column was unindexed, so
-- e.g. each MP parliament request full-scanned op_bills_membervote (1.5M rows) twice. Long text id
-- columns get a 64-char prefix index; short ones are indexed in full. Re-run after any import-parldata.py run.

ALTER TABLE `op_bills_bill` ADD INDEX `idx_legisinfo_id` (`legisinfo_id`), ADD INDEX `idx_session_id` (`session_id`), ADD INDEX `idx_sponsor_member_id` (`sponsor_member_id`), ADD INDEX `idx_sponsor_politician_id` (`sponsor_politician_id`);
ALTER TABLE `op_bills_billtext` ADD INDEX `idx_bill_id` (`bill_id`);
ALTER TABLE `op_bills_membervote` ADD INDEX `idx_member_id` (`member_id`), ADD INDEX `idx_politician_id` (`politician_id`), ADD INDEX `idx_votequestion_id` (`votequestion_id`);
ALTER TABLE `op_bills_partyvote` ADD INDEX `idx_party_id` (`party_id`), ADD INDEX `idx_votequestion_id` (`votequestion_id`);
ALTER TABLE `op_bills_votequestion` ADD INDEX `idx_bill_id` (`bill_id`), ADD INDEX `idx_context_statement_id` (`context_statement_id`), ADD INDEX `idx_session_id` (`session_id`);
ALTER TABLE `op_committees_committee` ADD INDEX `idx_parent_id` (`parent_id`), ADD INDEX `idx_slug` (`slug`);
ALTER TABLE `op_committees_committeeactivity` ADD INDEX `idx_committee_id` (`committee_id`);
ALTER TABLE `op_committees_committeeactivityinsession` ADD INDEX `idx_activity_id` (`activity_id`), ADD INDEX `idx_session_id` (`session_id`), ADD INDEX `idx_source_id` (`source_id`);
ALTER TABLE `op_committees_committeeinsession` ADD INDEX `idx_committee_id` (`committee_id`), ADD INDEX `idx_session_id` (`session_id`);
ALTER TABLE `op_committees_committeemeeting` ADD INDEX `idx_committee_id` (`committee_id`), ADD INDEX `idx_evidence_id` (`evidence_id`), ADD INDEX `idx_session_id` (`session_id`), ADD INDEX `idx_source_id` (`source_id`);
ALTER TABLE `op_committees_committeemeeting_activities` ADD INDEX `idx_committeeactivity_id` (`committeeactivity_id`(64)), ADD INDEX `idx_committeemeeting_id` (`committeemeeting_id`(64));
ALTER TABLE `op_committees_committeereport` ADD INDEX `idx_committee_id` (`committee_id`), ADD INDEX `idx_parent_id` (`parent_id`), ADD INDEX `idx_session_id` (`session_id`), ADD INDEX `idx_source_id` (`source_id`);
ALTER TABLE `op_core_electedmember` ADD INDEX `idx_party_id` (`party_id`), ADD INDEX `idx_politician_id` (`politician_id`), ADD INDEX `idx_riding_id` (`riding_id`);
ALTER TABLE `op_core_electedmember_sessions` ADD INDEX `idx_electedmember_id` (`electedmember_id`), ADD INDEX `idx_session_id` (`session_id`);
ALTER TABLE `op_core_party` ADD INDEX `idx_slug` (`slug`);
ALTER TABLE `op_core_partyalternatename` ADD INDEX `idx_party_id` (`party_id`);
ALTER TABLE `op_core_politician` ADD INDEX `idx_slug` (`slug`);
ALTER TABLE `op_core_politicianinfo` ADD INDEX `idx_politician_id` (`politician_id`);
ALTER TABLE `op_core_riding` ADD INDEX `idx_edid` (`edid`), ADD INDEX `idx_slug` (`slug`);
ALTER TABLE `op_elections_candidacy` ADD INDEX `idx_candidate_id` (`candidate_id`), ADD INDEX `idx_election_id` (`election_id`), ADD INDEX `idx_party_id` (`party_id`), ADD INDEX `idx_riding_id` (`riding_id`);
ALTER TABLE `op_hansards_document` ADD INDEX `idx_session_id` (`session_id`), ADD INDEX `idx_source_id` (`source_id`);
ALTER TABLE `op_hansards_oldsequencemapping` ADD INDEX `idx_document_id` (`document_id`), ADD INDEX `idx_slug` (`slug`);
ALTER TABLE `op_hansards_statement` ADD INDEX `idx_bill_debated_id` (`bill_debated_id`), ADD INDEX `idx_document_id` (`document_id`), ADD INDEX `idx_member_id` (`member_id`), ADD INDEX `idx_politician_id` (`politician_id`), ADD INDEX `idx_slug` (`slug`), ADD INDEX `idx_source_id` (`source_id`);
ALTER TABLE `op_hansards_statement_bills` ADD INDEX `idx_bill_id` (`bill_id`), ADD INDEX `idx_statement_id` (`statement_id`);
ALTER TABLE `op_hansards_statement_mentioned_politicians` ADD INDEX `idx_politician_id` (`politician_id`), ADD INDEX `idx_statement_id` (`statement_id`);
