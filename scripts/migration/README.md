# One-Time MySQL To Supabase Import

This isolated tool is for a controlled data migration only; it is not part of the deployed browser application. It requires read access to the source MySQL/MariaDB database and a private Supabase service-role key. It intentionally refuses to write to any nonempty target table and never transfers password hashes.

Before running it:

1. Create a verified `mysqldump` using a database account with read-only access. Keep the dump and user map outside source control, calculate a checksum, and retain both with the rollback materials.
2. Apply all Supabase migrations to a fresh non-production project.
3. Set the private variables from the root `.env.example`, including the verified Supabase Auth redirect URL.
4. Create `private/legacy-user-map.json` with one entry per legacy user: `{ "legacy_user_id": 12, "email": "verified-address@example.com", "verified": true }`. The `verified` field is an operator attestation; verify account ownership out of band first.
5. Run `npm install` in this directory, then `npm run migrate` for a read-only dry run. Review counts, user mappings, branch/date assumptions, and the source dump before using `npm run migrate -- --apply` against an empty Supabase target. The `package-lock.json` file is allowed to be committed to track the exact dependency versions for this isolated tool.

The importer preserves integer business IDs, maps legacy user IDs to invited Auth UUIDs, omits password hashes, imports tables in foreign-key order, converts MySQL timestamp strings as UTC after setting the MySQL session timezone to UTC, converts scheduled SMS local times using the stored UTC offset, resets identity sequences, and checks target row counts plus cent-accurate totals by transaction type, invoice total, and invoice-item total. Missing SMS offsets are interpreted as UTC. Database constraints can reject inconsistent legacy relationships. On any partial write, stop and reconcile or restore the target; the script will not overwrite data on a rerun.

No source rows or mappings have been imported in this workspace. The configured MySQL connection currently fails, so the utility has not been run against real data.