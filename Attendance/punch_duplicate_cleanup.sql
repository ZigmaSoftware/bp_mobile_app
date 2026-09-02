-- ---------------------------------------------------------------------------
-- Duplicate punch cleanup + prevention for zigfly_recognized / att_approval
-- ---------------------------------------------------------------------------
-- Context
--   Neither punch table stores a punch direction. IN/OUT is inferred from row
--   order within the calendar day, so one punch written twice consumes the
--   day's OUT slot with a copy of the IN punch: the day then reads as
--   "in 20:26:12, out 20:26:12, worked 00:00:00" and the employee can no
--   longer punch out.
--
--   Live data for 2026-08-01..2026-09-01 held 9 such duplicate punch events
--   across 4 employees (10 phantom rows), e.g. BPIN0093 ids 88930/88931 both
--   at '2026-08-20 20:26:12' and 89652/89653 both at '2026-08-24 10:50:14'.
--
--   The application-side guards are already deployed:
--     - bp_att_recent_duplicate_punch()  (config: BP_ATT_PUNCH_DEDUPE_SECONDS)
--     - AttendancePunchActions._punchInFlight  (Flutter)
--   This script cleans up the rows already stored and, optionally, makes the
--   duplicate impossible at the storage layer.
--
-- RUN THESE STEPS IN ORDER. Take a backup of both tables first.
-- Step 3 must not be run before step 2 has removed every existing duplicate.
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- Step 1 - PREVIEW. Read-only. Confirm the rows before deleting anything.
-- ---------------------------------------------------------------------------
SELECT
    emp_id,
    records,
    COUNT(*)                  AS rows_stored,
    MIN(id)                   AS keep_id,
    GROUP_CONCAT(id ORDER BY id) AS all_ids
FROM zigfly_recognized
WHERE records IS NOT NULL
  AND TRIM(records) <> ''
GROUP BY emp_id, records
HAVING COUNT(*) > 1
ORDER BY records;

SELECT
    emp_id,
    records,
    COUNT(*)                  AS rows_stored,
    MIN(id)                   AS keep_id,
    GROUP_CONCAT(id ORDER BY id) AS all_ids
FROM att_approval
WHERE records IS NOT NULL
  AND TRIM(records) <> ''
GROUP BY emp_id, records
HAVING COUNT(*) > 1
ORDER BY records;


-- ---------------------------------------------------------------------------
-- Step 2 - CLEANUP. Keeps the earliest id of each duplicate set, deletes the
--          later copies. One punch stays one punch; no genuine punch is lost,
--          because two real punches never share the same second.
-- ---------------------------------------------------------------------------
DELETE z
FROM zigfly_recognized z
JOIN (
    SELECT emp_id, records, MIN(id) AS keep_id
    FROM zigfly_recognized
    WHERE records IS NOT NULL
      AND TRIM(records) <> ''
    GROUP BY emp_id, records
    HAVING COUNT(*) > 1
) d
  ON  d.emp_id  = z.emp_id
  AND d.records = z.records
WHERE z.id > d.keep_id;

DELETE a
FROM att_approval a
JOIN (
    SELECT emp_id, records, MIN(id) AS keep_id
    FROM att_approval
    WHERE records IS NOT NULL
      AND TRIM(records) <> ''
    GROUP BY emp_id, records
    HAVING COUNT(*) > 1
) d
  ON  d.emp_id  = a.emp_id
  AND d.records = a.records
WHERE a.id > d.keep_id;


-- ---------------------------------------------------------------------------
-- Step 3 - PREVENTION (recommended). The storage-layer guarantee that no
--          client - the Flutter app, the web module, the face-recognition
--          service, or a future one - can write the same punch twice.
--
--          Only run after step 1 returns no rows. MySQL permits multiple NULLs
--          in a UNIQUE index, so rows with a NULL `records` are unaffected.
--
--          Note for whoever owns the face service (recognize_bp): once this
--          index exists, a duplicate INSERT raises a duplicate-key error
--          instead of silently adding a row. Handle it as success - the punch
--          is already recorded - rather than surfacing it to the employee.
-- ---------------------------------------------------------------------------
ALTER TABLE zigfly_recognized
    ADD UNIQUE KEY uk_zigfly_recognized_emp_records (emp_id, records);

ALTER TABLE att_approval
    ADD UNIQUE KEY uk_att_approval_emp_records (emp_id, records);


-- ---------------------------------------------------------------------------
-- Step 4 - VERIFY. Both queries must return zero rows.
-- ---------------------------------------------------------------------------
SELECT emp_id, records, COUNT(*) AS rows_stored
FROM zigfly_recognized
WHERE records IS NOT NULL AND TRIM(records) <> ''
GROUP BY emp_id, records
HAVING COUNT(*) > 1;

SELECT emp_id, records, COUNT(*) AS rows_stored
FROM att_approval
WHERE records IS NOT NULL AND TRIM(records) <> ''
GROUP BY emp_id, records
HAVING COUNT(*) > 1;
