# ReeferON CRM — PHP API (shared hosting ready)

Pure PHP 8.1+ / MySQL / Apache. No Composer. Works on **Hostinger shared hosting**.

Full first-time steps: see [`Rules-and-docs/docs/HOSTINGER_DEPLOY.md`](../Rules-and-docs/docs/HOSTINGER_DEPLOY.md)

## Local (WAMP)

```powershell
cd backend-php
.\start.ps1
```

API: `http://127.0.0.1:5080`

## Pack for Hostinger

```powershell
cd backend-php\scripts
.\pack-for-hostinger.ps1
```

Creates `reeferon-api-hostinger.zip` (without your local `.env`).

## On Hostinger (short)

1. Unzip `backend-php` on server  
2. Create `.env` from `.env.example` (Hostinger MySQL + `FRONTEND_URL`)  
3. Subdomain `api` → document root = **`public/`**  
4. `uploads` writable (775)  
5. Test `/api/health` and `/api/health/db`

Photos: `uploads/crm/<folder>/<cloudinary-style-name>.jpg`
