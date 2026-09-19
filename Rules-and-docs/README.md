# Rules and docs

Project guidelines, IDE settings, and documentation — one place.

```
Rules-and-docs/
├── agents/          ← was `.agents` (Cursor / AI workspace guidelines)
│   └── AGENTS.md
├── vscode/          ← was `.vscode` (optional IDE launch/settings)
└── docs/            ← was `docs/` + Hostinger deploy guide
    ├── ROLES.md
    ├── BACKUP.md
    ├── HOSTINGER_DEPLOY.md
    └── …
```

| Folder | Purpose |
|--------|---------|
| `agents/` | UI/CSS and agent rules for this workspace |
| `vscode/` | VS Code / Cursor debug configs (copy to root `.vscode` if you need IDE auto-load) |
| `docs/` | Roles, backup, Hostinger deploy, slide assets |

**Note:** Cursor/VS Code only auto-load `.vscode` from the **repo root**. If you add `launch.json`, either keep a copy at `/.vscode/` or copy from here when needed.

Hostinger deploy steps: [`docs/HOSTINGER_DEPLOY.md`](docs/HOSTINGER_DEPLOY.md)
