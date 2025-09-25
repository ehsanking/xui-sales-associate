<p align="center">
  <img src="assets/xui-shield.png" alt="X-UI Sales Associate" width="120">
</p>

# X-UI Sales Associate (WooCommerce → 3x-ui via Cloudflare Worker)

Automate creating/renewing Xray accounts on a **3x-ui** panel directly from **WooCommerce**.
The WordPress plugin calls a secure **Cloudflare Worker**; the Worker logs into 3x-ui and adds/updates the client, then returns subscription details for **My Account**.

## Repo Structure
- /plugin — WordPress plugin source (v1.0.0)
- /worker/worker.js — Cloudflare Worker (v1.0.0)
- /assets — icons/screenshots
- /dist/x-ui-sales-associate-1.0.0.zip — build for releases

## Quick Start
1) Create a GitHub repo (e.g., ehsanking/xui-sales-associate).
2) Upload these folders/files (or use git push).
3) Draft a Release with tag v1.0.0 and attach /dist/x-ui-sales-associate-1.0.0.zip.

## Worker Variables (Cloudflare → Settings → Variables)
Secrets: SHARED_SECRET, PANEL_USER, PANEL_PASS
Plain: PANEL_URL, ALLOWED_ORIGINS, (optional) DEBUG=1

## Headers & Actions
- X-Alsxui-Secret: <SHARED_SECRET>
- X-Alsxui-Action: add | details

## Telegram
https://t.me/VPN_SalesAssociate
