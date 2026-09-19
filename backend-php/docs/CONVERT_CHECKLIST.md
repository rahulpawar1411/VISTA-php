# Node → PHP conversion checklist

PHP API lives in `backend-php/`. Node Express source was removed (`backend/ARCHIVED.md`).

## Phase 0 — Prep
- [x] `backend-php/` folder
- [x] `.env` / `.env.example`
- [x] This checklist
- [x] Uploads moved to `backend-php/uploads` (self-contained)

## Phase 1–7
- [x] Auth, masters, DO/customers/sub-admins, chambers, temp/inward/outward
- [x] Permissions + applyApproved* + push + activities
- [x] Dashboard (incl. deltas + month sheet), leads, notes, reports, temp-logs
- [x] Credentials email (SMTP)
- [x] Cloudinary-style upload rename under `uploads/crm/...`

## Phase 8 — Cutover / shared hosting
- [x] Route smoke — **92/92** parity
- [x] Local web + mobile → `:5080`
- [x] Node Express removed; nested `.git` / `node_modules` / `render.yaml` cleaned
- [x] Shared-hosting harden: docroot=`public/`, `.htaccess`, uploads outside web root
- [ ] Point production subdomain at `backend-php/public` + fill production `.env`
- [ ] Update Vercel `VITE_API_BASE_URL` + mobile production API URL
