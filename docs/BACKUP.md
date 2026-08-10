# Database backup

## Quick backup
From `backend/` folder:

```bash
npm run db:backup
```

Output: `backend/backups/reeferon-YYYY-MM-DD_HH-mm.sql`  
If `mysqldump` is not on PATH (common on Windows without WAMP in PATH), a JSON snapshot is written instead (`.json`).

## Restore (SQL)
```bash
mysql -u root -p reeferon_crm_db < backups/reeferon-YYYY-MM-DD_HH-mm.sql
```

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
