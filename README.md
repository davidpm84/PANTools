Markdown
# 🛠️ PANTools - Solution Engineering Hub

![Docker](https://img.shields.io/badge/Docker-Supported-2496ED?logo=docker&style=flat-square)
![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&style=flat-square)
![Cortex](https://img.shields.io/badge/Cortex-XDR%20%7C%20XSIAM-00C55E?style=flat-square)
![Strata](https://img.shields.io/badge/Strata-NGFW-EA212D?style=flat-square)

**PANTools** is a unified, Dockerized web-based toolkit designed to streamline and automate daily tasks for Solution Engineers (SEs) and Security Architects working with Palo Alto Networks solutions. 

It provides a single pane of glass to access configuration parsers, API deployment tools, health check audits, and management trackers.

---

## 🌟 Key Features & Modules

### 🧱 STRATA Tools
* **PAN Firewall Mapper**: A powerful configuration parser and hardware sizing tool. Upload a Tech Support File (TSF / `.tgz`), and the mapper will analyze active sessions, policies, NAT rules, EDLs, and decryption metrics, comparing them against a hardware database to recommend the optimal NGFW model.

### 🧠 CORTEX Tools
* **Custom Content Importer**: Seamlessly synchronize custom integrations, playbooks, and correlation rules from a private GitHub repository. Select the desired content and push it directly into any Cortex XDR or XSIAM tenant using the Cortex API and Demisto SDK.
* **Cortex Health & Audit**: An API-driven Best Practice Assessment (BPA) tool. It runs automated checks to detect unparsed logs, stale endpoints, upgrade loops, and evaluates EDR settings, Malware, and Exploit profiles against security best practices.

### 🎯 MANAGEMENT Tools
* **PoV Radar**: Track Technical Review Reports (TRRs), Proof of Value (PoV) statuses, and global timelines.

---

## 🚀 Built-in OTA Updates
PANTools includes a built-in **Over-The-Air (OTA) update mechanism**. The Hub automatically checks the GitHub repository for new releases. When a new version is detected, a banner will appear allowing you to seamlessly update the tool with a single click, without needing to rebuild the Docker container manually.

---

## ⚙️ Prerequisites

* [Docker](https://docs.docker.com/get-docker/) & Docker Compose installed on your host machine.
* *(Optional)* A GitHub Personal Access Token (PAT) with `Contents` read permissions if you plan to use the **Content Importer** with a private repository.

---

## 📦 Quick Start & Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/davidpm84/PANTools.git
   cd pantools
Deploy the container:
Ensure port 80 is available on your host machine, then run:

Bash
docker-compose up -d --build
Access the Hub:
Open your web browser and navigate to:

Plaintext
http://localhost
(Or the IP address of your server).

Initial Setup (Optional):
Upon first launch, you will be prompted to enter your GitHub PAT. This enables the Cortex Content Importer to pull from private repositories. If you skip this step, the Content Importer will be disabled, but all other tools (like the Firewall Mapper and Cortex Audit) will remain fully functional.

🔒 Security & Data Privacy
No Cloud Dependency: PANTools runs entirely locally within your Docker environment.

Credential Storage: GitHub tokens and configurations are saved locally in a hidden .config.json file inside the Docker volume (config_data/). This file is excluded from version control.

API Keys: Cortex API keys used in the Importer or Audit tools are used in-memory during the execution of the PHP scripts and are never stored on the server.

⚠️ Disclaimer
This project is a community-driven toolkit created for educational and operational assistance purposes. It is not an official Palo Alto Networks product. Use it at your own risk. Always validate configuration changes and API deployments in a non-production environment first.
