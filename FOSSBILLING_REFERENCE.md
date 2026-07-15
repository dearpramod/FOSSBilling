# FOSSBilling Reference

> Open-source billing and client management platform for hosting providers and digital service businesses.
> Latest version: **0.8.3** | Docs: https://docs.fossbilling.org

---

## Table of Contents

- [Overview](#overview)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [File Structure](#file-structure)
- [Admin Features](#admin-features)
- [Extension & Development System](#extension--development-system)
- [Event Hooks](#event-hooks)
- [Security](#security)
- [Community & Support](#community--support)

---

## Overview

FOSSBilling is a self-hosted, fully auditable billing platform. It lets you manage clients, orders, invoices, and provisioning for hosting and digital services. The codebase is fully open-source, designed to be extended through modules, themes, and integrations.

---

## System Requirements

### Web Server
| Server | Notes |
|---|---|
| Apache | Requires `mod_rewrite`; `.htaccess` included |
| NGINX | Requires manual config (templates provided) |
| LiteSpeed / OpenLiteSpeed | Supported out of the box |

### PHP
- **Supported versions:** 8.3, 8.4, 8.5
- **Required extensions:** `curl`, `dom`, `iconv`, `intl`, `json`, `openssl`, `pdo_mysql`, `xml`, `zlib`
- **Recommended extensions:** `mbstring`, `opcache`, `imagick` or `gd`, `simplexml` (Plesk only)
- **php.ini settings:**
  - `memory_limit` ≥ 64M
  - `max_execution_time` = 30s (45–60s on shared hosting)
  - `allow_url_fopen` = On (required for remote images in PDFs/emails)

### Database
- MySQL 8.4+ or MariaDB 11.4+
- Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`
- Required privileges: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`, `INDEX`, `ALTER`

---

## Installation

### Standard Installation

1. Download the latest release from GitHub
2. Create a MySQL/MariaDB database and a dedicated user
3. Configure SSL certificates
4. Clear the document root of default/sample files
5. Upload and extract FOSSBilling to the document root
6. Visit your domain over HTTPS to launch the setup wizard:
   - Accept license terms
   - Enter database credentials
   - Create the administrator account
   - Confirm the public URL
   - Configure reverse proxy headers (if applicable)
   - Select default currency and price format
7. Configure the cron job (see below)

### Cron Job

FOSSBilling depends on cron for renewals, expired orders, email delivery, and background tasks.

- **Required interval:** every 5 minutes
- **Warning trigger:** system alerts if cron hasn't run within 15 minutes
- Find your exact cron command at: `Admin Panel → Settings → Scheduled Tasks`

### Other Installation Methods

| Method | Use Case |
|---|---|
| Docker | Containerized/isolated deployments |
| Build from Source | Development, contributions, custom builds |

### NGINX Configuration Notes

Replace these placeholders in the provided NGINX template:
- `%%DOMAIN%%` — your domain name
- `%%SOURCE_PATH%%` — absolute path to the `src/` directory
- SSL certificate paths
- PHP-FPM socket path

---

## File Structure

```
/
├── src/
│   ├── data/              # Runtime data: cache, logs, user uploads (must be writable)
│   ├── public/            # Web-accessible: shared assets, branding, gateway icons
│   ├── install/           # Setup wizard (auto-removed after install)
│   ├── library/
│   │   ├── Api/           # API endpoint classes
│   │   ├── Box/           # Legacy compatibility layer
│   │   ├── FOSSBilling/   # Modern core classes (Doctrine, HTTP, Security, Twig, Validation, Sanitizer)
│   │   ├── Model/         # Database models
│   │   ├── Payment/       # Payment gateway adapters
│   │   ├── Registrar/     # Domain registrar adapters
│   │   └── Server/        # Hosting control panel adapters
│   ├── locale/            # Translation files (Git submodule)
│   ├── modules/           # Feature modules — each contains:
│   │   └── <ModuleName>/
│   │       ├── Api/
│   │       ├── Controller/
│   │       ├── templates/
│   │       └── Service.php
│   └── themes/            # Installed themes (assets, config, templates, customizations)
└── frontend/              # Frontend source — compiles to src/public/assets
```

---

## Admin Features

### Product Types
- **Hosting** — provision web hosting accounts via server manager integrations
- **Domains** — register/manage domains via registrar integrations
- **API Keys** — issue and manage API credentials
- **Downloadable** — sell file downloads
- **Licenses** — sell software licenses

### Server Manager Integrations
- WHM/cPanel
- HestiaCP
- CWP (Control Web Panel)
- Additional third-party managers supported

### Configuration Areas
| Area | Description |
|---|---|
| Company Information | Business details for billing documents and client-facing materials |
| Email Templates | Customize messages sent to clients and staff |
| Anti-Spam | Protect against abusive signups, tickets, and login attempts |
| Localization | Multi-language and regional formatting support |
| Invoice PDFs | Customize invoice layout and content |
| Payment Gateways | Manual and third-party gateway configuration |

---

## Extension & Development System

### Extension Types

| Type | Description |
|---|---|
| **Modules** | New feature sets with their own API, controllers, and templates |
| **Payment Gateways** | Billing workflow payment integrations |
| **Registrar Integrations** | Domain registration service connectors |
| **Server Managers** | Hosting control panel / provisioning backend connectors |
| **Themes** | Client and admin interface customizations |

### Developer Tools

- **REST API** — programmatic access to all platform features
- **JavaScript Wrapper** — API-driven frontend integrations without direct backend work
- **Event Hooks** — react to platform events in module code
- **Twig Filters & Functions** — extend the templating engine for custom themes/modules
- **File Structure Docs** — maps all extension points

### Module Structure

Each module lives under `src/modules/<ModuleName>/` and can contain:

```
<ModuleName>/
├── Api/           # API classes (Admin.php, Client.php, Guest.php)
├── Controller/    # Route controllers
├── templates/     # Twig templates
└── Service.php    # Business logic + event hook handlers
```

---

## Event Hooks

Hooks are static methods on a module's `Service.php`. FOSSBilling auto-discovers them when cron runs.

> **Note:** After adding a new hook handler, cron must run at least once before it is registered.

### Naming Patterns

| Pattern | Trigger |
|---|---|
| `onBefore{Action}` | Fires before an action occurs |
| `onAfter{Action}` | Fires after an action completes |
| `onEvent{Description}` | Fires when a specific event happens |
| `onEveryEvent` | Universal listener — fires on all events |

### Hook Categories

**Admin hooks:**
- Extensions (activate, deactivate, install, update)
- Clients (create, delete, password change, update)
- Orders (activate, cancel, renew, suspend)
- Invoices (approve, refund, reminder, payment receipt)
- Subscriptions, Transactions, Tickets, Staff, System, Themes, Authentication

**Client hooks:**
- Account operations (signup, login, profile update, password change)
- Cart and order management
- Tickets, Domains

**Guest hooks:** Password reset requests

**Product hooks:** Service license management

### Example Hook

```php
// src/modules/MyModule/Service.php

class Service
{
    public static function onAfterAdminOrderCreate(\Box_Event $event): void
    {
        $order = $event->getSubject();
        // Custom logic here
    }
}
```

---

## Security

### Core Principle

> "Security depends on the full stack: FOSSBilling, PHP, your database, web server, control panel, extensions, and operational practices."

### Checklist

- [ ] Apply core hardening guidance before going live
- [ ] Review security docs after updates
- [ ] Re-audit when changing hosting providers
- [ ] Audit all new extensions before activating
- [ ] Keep the installation up to date
- [ ] Use error reporting for diagnostics

### Vulnerability Reporting

Responsible disclosure is accepted through the project's vulnerability reporting process (details in the Security section of the docs).

---

## Community & Support

| Channel | Purpose |
|---|---|
| **GitHub** | Source code, issues, pull requests |
| **Discord** | Community help and discussion |
| **Docs** | https://docs.fossbilling.org |

### Contribution Areas

- PHP backend development
- Frontend (HTML/CSS/JS)
- UI/UX design
- Infrastructure and DevOps
