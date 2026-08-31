# Database backup

## Quick backup
From `backend/` folder:

```bash
npm run db:backup
```

Output: `backend/backups/reeferon-YYYY-MM-DD_HH-mm.sql`  
If `mysqldump` is not on PATH (common on Windows without WAMP in PATH), a JSON snapshot is written instead (`.json`).

## Restore (SQL)

**Local WAMP:**
```bash
mysql -u root -p reeferon_crm_db < backups/reeferon-YYYY-MM-DD_HH-mm.sql
```

**FreeSQL / remote (uses `backend/.env` credentials):**
```bash
npm run db:restore -- "C:\Users\Lenovo\Desktop\Backup data\crm\24_08_2026_sql12835558 (2).sql"
```

Or:
```bash
node scripts/restore-db.js "path\to\your-backup.sql"
```

Notes:
- Use a **`.sql` dump** (phpMyAdmin export). You cannot copy a WAMP `data/` folder into FreeSQL — cloud MySQL only accepts SQL import.
- Old database name in the file (e.g. `sql12835558`) is OK; the script imports into whatever `DB_NAME` is in `.env`.

## Schedule (Windows Task Scheduler)
Run daily:
```
node C:\Users\Lenovo\Desktop\CRM\backend\scripts\backup-db.js
```

## Log archive
```bash
npm run logs:archive
```
Moves legacy `error.log` / `errors-*.log` text files to `logs/archive/`.
