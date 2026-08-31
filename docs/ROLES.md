# ReeferON CRM — Roles & Access

**Keep this straight:** catalog masters (`warehouse_master` / `client_master`) are lists of names+codes. Daily DO work uses **chamber ↔ client assignments** for that warehouse. Customers never edit either.

**Yaad rakho:** Master Data = catalog. DO Master Setup = assignments. Customer = sirf allowed scope read.

## super_admin (Web — Super Admin Secure Window)

| Area | Access |
|------|--------|
| Login | Web only (`frontend`) |
| DO operators | Create / edit / delete, chamber limit |
| Customers | Create, scope (`allowed_clients`, `allowed_warehouses`) |
| Sub-admins | Create / manage app sub-admins |
| Logs | Daily chamber, inward, outward — full history + export |
| Inventory | Reconciliation, daily box tracker |
| Permissions | Approve / deny DO edit & role requests |
| System | Activity logs, security logs, config |

---

## sub_admin (Mobile — SubAdminScreen)

| Tab | Access |
|-----|--------|
| Home | Overview, DO task progress |
| Logs | Chambers, inward, outward — all warehouses |
| Reports | Inventory reconciliation |
| More | Profile, notifications (permission requests approve/deny) |

Same data scope as super_admin on mobile (not limited by customer CSV).

---

## customer (Mobile — CustomerScreen)

| Area | Access |
|------|--------|
| Scope | Only `allowed_clients` + `allowed_warehouses` from Super Admin |
| Logs | Chambers, inward, outward, inventory (scoped) |
| Reports | Inventory reconciliation (scoped) |
| Submit | Customer reports (if enabled) |

Cannot see other clients’ warehouses or DO master setup.

---

## do_operator (Mobile — DashboardScreen)

| Area | Access |
|------|--------|
| Chambers | Assigned chamber limit only |
| Master setup | Client ↔ chamber assignments (pending Super Admin approval for some actions) |
| Daily logs | Morning / evening temp inspections + photo |
| Offline | SQLite queue → sync when online |
| Reports | Own logs with filters |
| Permissions | Request edit / add chamber via notifications |

Cannot access web Super Admin or other DO’s chambers.

---

## API route summary

| Prefix | Roles |
|--------|--------|
| `/api/auth` | Public login |
| `/api/chambers` | super_admin, customer, do_operator, sub_admin |
| `/api/dashboard` | super_admin, customer, sub_admin |
| `/api/inward-logs`, `/api/outward-logs`, `/api/chamber-temp` | All mobile + super_admin |
| `/api/do-operators`, `/api/customers`, `/api/sub-admins` | super_admin only |
| `/api/permission-requests` | Role-specific (see routes) |

---

## Health check

`GET /api/health` — no auth. Returns database status + uptime.
