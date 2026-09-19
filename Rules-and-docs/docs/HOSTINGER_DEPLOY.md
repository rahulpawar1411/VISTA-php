# Hostinger (shared hosting) â€” first deploy guide

This app is ready for Hostinger shared hosting:
- API = pure PHP (no Composer / no Node on server)
- Web = React build (`frontend/dist`)
- Photos = `backend/uploads/crm/...` (Cloudinary-style names)

---

## A. What you upload

| Part | Upload this | Do NOT upload |
|------|-------------|---------------|
| API | whole `backend/` folder | `.env` from laptop with local DB (create new on host) |
| Web | contents of `frontend/dist/` | `frontend/src`, `node_modules` |
| DB | `.sql` dump | â€” |

---

## B. Hostinger checklist (order matters)

### 1) PHP version
hPanel â†’ **Advanced** â†’ **PHP Configuration** â†’ select **PHP 8.1 / 8.2 / 8.3**  
Enable extensions if listed: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`

### 2) MySQL
1. Create database + user (All privileges)
2. Export local DB from WAMP phpMyAdmin
3. Import into Hostinger phpMyAdmin

### 3) API (`backend`)
1. Upload `backend` via File Manager or FTP  
   Example path: `domains/yourdomain.com/backend/`
2. On server, create `.env` from `.env.example` with Hostinger DB values
3. Create subdomain **`api`**
4. Set subdomain **Document Root** = `backend/public`
5. Make `backend/uploads` writable (775)
6. Test:
   - `https://api.yourdomain.com/api/health`
   - `https://api.yourdomain.com/api/health/db` â†’ must say connected

### 4) Web (React)
On your laptop:

```powershell
cd frontend
copy .env.production.example .env.production
# Edit .env.production â†’ VITE_API_BASE_URL=https://api.yourdomain.com/api
npm install
npm run build
```

Upload **everything inside** `frontend/dist/` into Hostinger `public_html/`  
(`.htaccess` is included automatically from `frontend/public/.htaccess`)

### 5) `.env` must include your web URL

```env
FRONTEND_URL=https://yourdomain.com
APP_LOGIN_URL=https://yourdomain.com
APP_ENV=production
LOGIN_LOCKOUT=true
JWT_SECRET=long-random-secret
```

### 6) Mobile (after API works)
Set production API to `https://api.yourdomain.com`  
(`EXPO_PUBLIC_API_URL` or `PRODUCTION_API_URL` in mobile)

---

## C. Important rules

1. Document root for API = **`public/`** only  
2. React = build folder only (`dist`), not source  
3. Build again if you change `VITE_API_BASE_URL`  
4. Photos save on server under `uploads/crm/...`  
5. Never put `.env` inside `public/`  
6. Use HTTPS everywhere  

---

## D. If something fails

| Problem | Fix |
|---------|-----|
| `/api/health` 404 | Wrong document root (must be `public/`) |
| `/api/health/db` fail | Wrong DB_HOST/USER/PASS/NAME in `.env` |
| Web opens but login CORS error | Set `FRONTEND_URL=https://yourdomain.com` on API `.env` |
| Blank page on refresh | Missing `dist/.htaccess` (rebuild so `public/.htaccess` copies in) |
| Photo upload fail | `uploads` not writable, or PHP upload limit (see `public/.user.ini`) |
| Images not showing | `VITE_PREFER_CLOUDINARY=false` then rebuild web |

---

## E. Recommended Hostinger layout

```
domains/yourdomain.com/
  public_html/          â† React dist files (index.html, assets, .htaccess)
  backend/
    .env
    public/             â† api.yourdomain.com document root
    src/
    uploads/crm/...
```
