X-UI Sales Associate
Contributors: ehsanking
Tags: woocommerce, vpn, xray, 3x-ui, vless, vmess, trojan, wireguard, cloudflare, workers, subscription
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate VPN account creation/renewal on a 3x-ui (Xray) panel straight from WooCommerce — via a secure Cloudflare Worker proxy. Clean, full-width admin UI.

X-UI Sales Associate connects **WooCommerce** to your **3x-ui (Xray)** panel using a **Cloudflare Worker** as a secure proxy.  
After a **paid order (Completed)** the plugin sends a job to the Worker, which logs in to 3x-ui and creates/renews the user, sets traffic/expiry, and returns subscription details to store and show in **My Account**.

**Key features**
- Auto-create / renew on **paid (Completed)** orders
- Works through a **Cloudflare Worker** (Shared Secret auth + optional Allowed Origins)
- Saves & shows **Subscription URL / UUID / Expiry / Server info** in My Account
- **Plans / Defaults** per product: Traffic (GB) + Duration (days)
- **Server Settings**: Transport (WS/gRPC), TLS on/off, WS path, SNI
- **Diagnostics**: one-click **Ping**
- Tested with **3x-ui** (Xray multi-protocol: VLESS, VMESS, Trojan, ShadowSocks, WireGuard, Tunnel, Mixed, HTTP)
- References:
  - 3x-ui: https://github.com/MHSanaei/3x-ui
  - Telegram: https://t.me/VPN_SalesAssociate
  - Developer GitHub: https://github.com/ehsanking/
