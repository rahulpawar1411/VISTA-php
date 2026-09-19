# Rules and docs

Project guidelines, IDE settings, and documentation â€” one place.

```
Rules-and-docs/
â”œâ”€â”€ agents/          â† was `.agents` (Cursor / AI workspace guidelines)
â”‚   â””â”€â”€ AGENTS.md
â”œâ”€â”€ vscode/          â† was `.vscode` (optional IDE launch/settings)
â””â”€â”€ docs/            â† was `docs/` + Hostinger + Node archive note
    â”œâ”€â”€ ROLES.md
    â”œâ”€â”€ BACKUP.md
    â”œâ”€â”€ HOSTINGER_DEPLOY.md
    â”œâ”€â”€ NODE_BACKEND_ARCHIVED.md
    â””â”€â”€ â€¦
```

| Folder | Purpose |
|--------|---------|
| `agents/` | UI/CSS and agent rules for this workspace |
| `vscode/` | VS Code / Cursor debug configs (copy to root `.vscode` if you need IDE auto-load) |
| `docs/` | Roles, backup, Hostinger deploy, Node archive note, slide assets |

**Note:** Cursor/VS Code only auto-load `.vscode` from the **repo root**. If you add `launch.json`, either keep a copy at `/.vscode/` or copy from here when needed.

| Doc | Topic |
|-----|--------|
| [`docs/ROLES.md`](docs/ROLES.md) | Who can do what |
| [`docs/BACKUP.md`](docs/BACKUP.md) | DB / uploads backup |
| [`docs/HOSTINGER_DEPLOY.md`](docs/HOSTINGER_DEPLOY.md) | Shared hosting deploy |
| [`docs/NODE_BACKEND_ARCHIVED.md`](docs/NODE_BACKEND_ARCHIVED.md) | Old Express backend removed (use `backend`) |

Hostinger deploy steps: [`docs/HOSTINGER_DEPLOY.md`](docs/HOSTINGER_DEPLOY.md)
