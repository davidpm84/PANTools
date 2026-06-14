# PANTools

![Docker](https://img.shields.io/badge/Docker-Supported-2496ED?logo=docker&style=flat-square)
![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&style=flat-square)
![Cortex](https://img.shields.io/badge/Cortex-XDR%20%7C%20XSIAM-00C55E?style=flat-square)
![Strata](https://img.shields.io/badge/Strata-NGFW-EA212D?style=flat-square)

**PANTools** is a Dockerized, web-based toolkit for Solution Engineers and Partners working with Palo Alto Networks solutions. It provides a unified hub to access configuration parsers, API deployment tools, health check audits, and management trackers.

---

## Access Model

PANTools has two editions, each with its own login:

| Edition | Who | Login |
|---|---|---|
| **Partner Edition** | Partner SEs | Shared password |
| **SE Edition** | Palo Alto Networks SEs | GitHub PAT with access to `davidpm84/cortexcustomintegrations` |

SE Edition users also get access to the **Admin Panel** to generate access tokens and manage deployed tools.

---

## Tools

### STRATA — NGFW & SASE
- **PAN Firewall Mapper** — Upload a Tech Support File (`.tgz`) to parse active sessions, policies, NAT rules, EDLs, and decryption metrics. Recommends the optimal NGFW hardware model from the sizing database.

### CORTEX — XDR & XSIAM
- **Custom Content Importer** — Sync custom integrations, playbooks, and correlation rules from a private GitHub repository and push them directly into any Cortex XDR or XSIAM tenant via the Cortex API and Demisto SDK.
- **Cortex Health & Audit** — API-driven Best Practice Assessment (BPA). Runs automated checks for unparsed logs, stale endpoints, upgrade loops, EDR settings, and Malware/Exploit profiles. Compatible with XDR 5.1 & XSIAM 3.5.

### Management — PoV & Tracking
- **PoV Radar** — Track TRRs, PoV statuses, global timelines, and SFDC links.

### Dynamic Tools (SE Edition)
SE users can deploy additional tools from private GitHub repositories using base64 access tokens. Tools are installed with a single click and appear as cards in the hub alongside built-in tools.

---

## Admin Panel (SE Edition only)

Accessible from the navbar. Allows SE users to:
- **Generate access tokens** — create base64 tokens with a configurable expiry date to share tools with Partner users.
- **Bulk tool loader** — deploy multiple tools from private repos at once.
- **Manage active tools** — view installed tools (with creator and expiry badges) and remove them.

---

## Quick Start

**Requirements:** Docker and Docker Compose installed on the host.

```bash
git clone https://github.com/davidpm84/PANTools.git
cd PANTools
docker-compose up -d --build
```

Then open `http://localhost` in your browser.

---

## Security Notes

- Runs entirely within your local Docker environment — no external cloud dependency.
- GitHub PATs are **never stored**. Only tool metadata (name, creator, expiry) is saved locally in `.tools.json`.
- Cortex API keys used in the Importer and Audit tools are held in memory only and never written to disk.

---

## Disclaimer

PANTools is a community-driven toolkit for educational and operational assistance. It is not an official Palo Alto Networks product. Always validate configuration changes and API calls in a non-production environment first.
