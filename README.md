<p align="center">
  <img src="assets/xui-shield.png" alt="X-UI Sales Associate" width="120">
</p>

# X-UI Sales Associate (WooCommerce → 3x-ui via Cloudflare Worker)

Automate creating/renewing Xray accounts on a **3x-ui** panel straight from **WooCommerce**.  
The plugin calls a secure **Cloudflare Worker**; the Worker logs into 3x-ui, adds/updates the client, and returns subscription details to show in **My Account**.

- Works with **3x-ui (Xray)** multi-protocol panel (VLESS, VMESS, Trojan, ShadowSocks, WireGuard, Tunnel, Mixed, HTTP).
- Per‑product **GB** & **Days**, **Server Settings**, **Diagnostics** (Ping), full‑width admin UI.
- Secure proxy with **Shared Secret** + optional **Allowed Origins**.

---

## Table of Contents
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Step-by-Step — Cloudflare Worker](#step-by-step--cloudflare-worker)
  - [Environment Variables](#environment-variables)
  - [Worker Code (v1.0.0)](#worker-code-v100)
  - [Test with curl (examples)](#test-with-curl-examples)
- [Step-by-Step — WordPress Plugin](#step-by-step--wordpress-plugin)
- [Data Model / Units](#data-model--units)
- [Troubleshooting](#troubleshooting)
- [Security Notes](#security-notes)
- [Persian Guide (راهنمای فارسی)](#persian-guide-راهنمای-فارسی)

---

## How it works
1) Customer pays an order → WooCommerce marks it **Completed**.  
2) Plugin sends a POST to your **Cloudflare Worker** with headers `X-Alsxui-Secret` and `X-Alsxui-Action`.  
3) Worker logs in to **3x-ui**, adds/updates the client in the targeted inbound, ensures a `subId`, and returns identifiers for the plugin to store and display in **My Account**.

---

## Requirements
- WordPress **6.6+**, PHP **7.4+**
- WooCommerce (active)
- A reachable **3x-ui** panel (admin credentials)
- A **Cloudflare** account (to deploy a Worker)
- HTTPS strongly recommended

---

## Quick Start
1. **Create a Worker** in Cloudflare → copy its URL (e.g., `https://x-ui-brand.you.workers.dev`).  
2. **Set Worker variables** (see below) — especially `SHARED_SECRET`, `PANEL_URL`, `PANEL_USER`, `PANEL_PASS`.  
3. **Paste Worker code** and **Deploy**.  
4. In WordPress → **X-UI SA → Settings → Worker & API**:  
   - Worker Proxy URL = your Worker URL  
   - Shared Secret = `SHARED_SECRET` (must match Worker)  
   - (Optional) Allowed Origins = your site/admin origins  
5. In product edit → **X-UI SA** tab → set **GB** and **Days**.  
6. Place a test order, mark **Completed**, verify in **My Account**.

---

## Step-by-Step — Cloudflare Worker

### Environment Variables
Cloudflare → your Worker → **Settings → Variables**.

**Secrets (Encrypted)**
- `SHARED_SECRET` — long random token. Must equal the plugin’s “Shared Secret”.
- `PANEL_USER` — 3x-ui admin (or service) username.
- `PANEL_PASS` — password for that user.

**Plain text**
- `PANEL_URL` — base URL of 3x-ui (e.g., `https://panel.example.com`).
- `ALLOWED_ORIGINS` — comma-separated origins that may call the Worker (e.g., `https://yoursite.com,https://admin.yoursite.com`).
- `DEBUG` — optional (`1` to output extra info on errors).

**Request headers (plugin → Worker)**
- `Content-Type: application/json`
- `X-Alsxui-Secret: <SHARED_SECRET>`
- `X-Alsxui-Action: add` | `details`

### Worker Code (v1.0.1)

```js
// Worker v1.0.1 — 3x-ui Official API Compatible (addClient + update/:id), with CSRF & verify
export default {
  async fetch(request, env, ctx) {
    const origin = request.headers.get("Origin") || "";
    const allowed = (env.ALLOWED_ORIGINS || "")
      .split(",")
      .map(s => s.trim())
      .filter(Boolean);
    const CORS = {
      "Access-Control-Allow-Origin":
        allowed.length && allowed.includes(origin) ? origin : "*",
      "Access-Control-Allow-Headers":
        "Content-Type, X-Alsxui-Secret, X-Alsxui-Action",
      "Access-Control-Allow-Methods": "POST, OPTIONS",
      "Access-Control-Expose-Headers": "Content-Type",
    };

    if (request.method === "OPTIONS")
      return new Response(null, { status: 204, headers: CORS });
    if (request.method !== "POST")
      return j({ ok: false, error: "method_not_allowed" }, CORS, 405);

    try {
      // --- auth to worker
      const secret = request.headers.get("X-Alsxui-Secret");
      if (!secret || secret !== (env.SHARED_SECRET || "")) {
        return j({ ok: false, error: "unauthorized" }, CORS, 401);
      }

      // --- parse action & payload
      let payload = {};
      try {
        payload = await request.json();
      } catch {
        return j({ ok: false, error: "bad_json" }, CORS, 400);
      }
      const action = String(
        payload.action || request.headers.get("X-Alsxui-Action") || "add"
      ).toLowerCase();

      // quick probes
      if (action === "ping") return j({ ok: true, worker: "1.2.0" }, CORS);
      if (action === "whoami")
        return j(
          {
            ok: true,
            panel_url: (env.PANEL_URL || "").replace(/\/+$/, ""),
            has_user: !!env.PANEL_USER,
            has_pass: !!env.PANEL_PASS,
          },
          CORS
        );

      // --- env sanity
      const PANEL = (env.PANEL_URL || "").replace(/\/+$/, "");
      const USER = env.PANEL_USER;
      const PASS = env.PANEL_PASS;
      if (!PANEL || !USER || !PASS)
        return j(
          {
            ok: false,
            error: "missing_env",
            missing: { panel: !PANEL, user: !USER, pass: !PASS },
          },
          CORS,
          500
        );

      // --- login
      const login = await fetch(PANEL + "/login", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ username: USER, password: PASS }),
        redirect: "manual",
      });

      const cookiesMerged = mergeCookies(login.headers);
      if (login.status !== 200 && login.status !== 302) {
        return j(
          { ok: false, error: "login_failed", status: login.status },
          CORS,
          502
        );
      }
      if (!cookiesMerged) {
        return j({ ok: false, error: "no_session_cookie" }, CORS, 502);
      }

      // warm /panel/ (and extract CSRF if present)
      let cookieHeader = cookiesMerged;
      const panelResp = await fetch(PANEL + "/panel/", {
        headers: { Cookie: cookieHeader, Accept: "text/html" },
      });
      const html = await panelResp.text();
      const more = mergeCookies(panelResp.headers);
      if (more) cookieHeader = cookieHeader + "; " + more;

      const csrfToken =
        // meta tag
        (html.match(
          /<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i
        ) || [])[1] ||
        // hidden input
        (html.match(
          /<input[^>]+name=["']_csrf["'][^>]+value=["']([^"']+)["']/i
        ) || [])[1] ||
        // cookie names sometimes used
        (cookieHeader.match(/XSRF-TOKEN=([^;]+)/) || [])[1] ||
        (cookieHeader.match(/csrfToken=([^;]+)/) || [])[1] ||
        "";

      const H = {
        Cookie: cookieHeader,
        Accept: "application/json, text/plain, */*",
        "X-Requested-With": "XMLHttpRequest",
        Referer: PANEL + "/panel/",
      };
      if (csrfToken) H["X-CSRF-Token"] = csrfToken;

      // --- helpers
      function token(n = 16) {
        const arr = new Uint8Array(n);
        crypto.getRandomValues(arr);
        const abc = "abcdefghijklmnopqrstuvwxyz0123456789";
        let o = "";
        for (let i = 0; i < n; i++) o += abc[arr[i] % abc.length];
        return o;
      }
      function toBytes(gb) {
        const n = Number(gb || 0);
        if (!isFinite(n) || n <= 0) return 0;
        return Math.round(n * 1024 * 1024 * 1024);
      }
      function norm(s) {
        return String(s || "").trim().toLowerCase();
      }

      async function api(path, options = {}) {
        return fetch(PANEL + path, {
          ...options,
          headers: { ...(options.headers || {}), ...H },
        });
      }

      // Official 3x-ui endpoints
      const EP = {
        LIST: "/panel/api/inbounds/list",
        GET: (id) => `/panel/api/inbounds/get/${Number(id)}`,
        ADD: "/panel/api/inbounds/addClient",
        UPDATE: (id) => `/panel/api/inbounds/update/${Number(id)}`, // ID in PATH
      };

      async function listInbounds() {
        const r = await api(EP.LIST);
        const t = await r.text();
        if (r.status !== 200) return [];
        try {
          const j = JSON.parse(t);
          return Array.isArray(j?.obj) ? j.obj : [];
        } catch {
          return [];
        }
      }

      async function getInboundObj(id) {
        const r = await api(EP.GET(id));
        const t = await r.text();
        if (r.status !== 200) return null;
        try {
          const j = JSON.parse(t);
          return j?.obj || null;
        } catch {
          return null;
        }
      }

      function parseClients(inbObj) {
        try {
          const s = JSON.parse(inbObj?.settings || "{}");
          return Array.isArray(s.clients) ? s.clients : [];
        } catch {
          return [];
        }
      }

      function writeClients(inbObj, clients) {
        let s = {};
        try {
          s = JSON.parse(inbObj?.settings || "{}");
        } catch {
          s = {};
        }
        s.clients = clients;
        inbObj.settings = JSON.stringify(s);
        return inbObj;
      }

      async function updateInbound(inbObj, inboundId) {
        // Official: /panel/api/inbounds/update/:id  with JSON body = full obj
        // Try JSON first
        let r = await api(EP.UPDATE(inboundId), {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(inbObj),
        });
        if (r.status === 200) return { ok: true, via: "json" };

        // Fallback: form-encoded (some forks accept)
        const form = new URLSearchParams();
        for (const [k, v] of Object.entries(inbObj)) {
          form.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
        }
        if (csrfToken) form.append("_csrf", csrfToken);
        r = await api(EP.UPDATE(inboundId), {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: form.toString(),
        });
        if (r.status === 200) return { ok: true, via: "form" };

        return { ok: false, status: r.status, text: await r.text() };
      }

      async function addClientOfficial({ inbound_id, client }) {
        // Official: POST /panel/api/inbounds/addClient
        // Body: { id: <inbound_id>, settings: "<stringified JSON with clients:[client]>" }
        const settingsStr = JSON.stringify({ clients: [client] });
        const body = JSON.stringify({ id: Number(inbound_id), settings: settingsStr });

        // Try JSON
        let r = await api(EP.ADD, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body,
        });
        if (r.status === 200) return { ok: true, via: "json" };

        // Fallback: form-encoded
        const form = new URLSearchParams();
        form.append("id", String(Number(inbound_id)));
        form.append("settings", settingsStr);
        if (csrfToken) form.append("_csrf", csrfToken);
        r = await api(EP.ADD, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: form.toString(),
        });
        if (r.status === 200) return { ok: true, via: "form" };

        return { ok: false, status: r.status, text: await r.text() };
      }

      async function findClientAnywhere(key) {
        const nkey = norm(key);
        const all = await listInbounds();
        for (const inb of all) {
          const obj = await getInboundObj(inb.id);
          const clients = parseClients(obj);
          const hit = clients.find((c) =>
            [c.id, c.uuid, c.email].filter(Boolean).map(norm).includes(nkey)
          );
          if (hit) {
            return {
              inbound_id: inb.id,
              inbound_remark: inb.remark || null,
              client: hit,
            };
          }
        }
        return null;
      }

      // ---- UPSERT flow
      if (["add", "upsert", "renew"].includes(action)) {
        const inbound_id = payload.inbound_id ?? 1;
        const email = String(payload.email || "").trim();
        const uuid = String(payload.uuid || "").trim();
        const key = email || uuid;
        if (!key) return j({ ok: false, error: "missing email/uuid" }, CORS, 400);

        const addBytes = toBytes(payload.total_gb ?? payload.add_gb ?? 0);
        const extendDays =
          payload.extend_days != null ? Number(payload.extend_days) : 0;
        const extendMs =
          payload.extend_ms != null
            ? Number(payload.extend_ms)
            : extendDays * 24 * 60 * 60 * 1000;
        const absoluteExpiry =
          payload.expiry_ms != null && payload.expiry_is_duration !== true
            ? Number(payload.expiry_ms)
            : 0;

        // 1) Get inbound
        const inbObj = await getInboundObj(inbound_id);
        if (!inbObj)
          return j(
            { ok: false, error: "inbound_not_found", inbound_id },
            CORS,
            404
          );

        const clients = parseClients(inbObj);
        const now = Date.now();
        const nkey = norm(key);
        let idx = clients.findIndex((c) =>
          [c.id, c.uuid, c.email].filter(Boolean).map(norm).includes(nkey)
        );

        if (idx >= 0) {
          // 2) RENEW (update full inbound)
          const existing = clients[idx];
          const baseExpiry = Math.max(Number(existing.expiryTime || 0) || 0, now);
          const newExpiry =
            absoluteExpiry > 0
              ? absoluteExpiry
              : extendMs > 0
              ? baseExpiry + extendMs
              : Number(existing.expiryTime || 0) || 0;

          const merged = {
            ...existing,
            total: Math.max(0, Number(existing.total || 0)) + addBytes,
            totalGB:
              Math.max(0, Number(existing.totalGB || 0) || Number(existing.total || 0)) +
              addBytes,
            expiryTime: newExpiry > 0 ? newExpiry : Number(existing.expiryTime || 0) || 0,
            enable: true,
          };
          if (!merged.subId) merged.subId = token(16);
          clients[idx] = merged;

          const updatedObj = writeClients(inbObj, clients);
          const u = await updateInbound(updatedObj, inbound_id);
          if (!u.ok) {
            return j(
              {
                ok: false,
                error: "update_failed",
                via: u.via || null,
                status: u.status || null,
                text: u.text || null,
                csrf: !!csrfToken,
              },
              CORS,
              502
            );
          }

          const v = await findClientAnywhere(key);
          if (!v) {
            return j(
              {
                ok: false,
                error: "not_persisted_after_update",
                via: u.via,
                csrf: !!csrfToken,
              },
              CORS,
              502
            );
          }
          return j(
            {
              ok: true,
              renewed: true,
              created: false,
              uuid: merged.uuid,
              email: merged.email,
              subId: merged.subId,
              verify: v,
            },
            CORS
          );
        } else {
          // 3) CREATE (official addClient)
          const client = {
            id: uuid || token(8),
            uuid: uuid || crypto.randomUUID(),
            flow: "",
            email,
            limitIp: Number(payload.limit_ip || 0),
            total: addBytes,
            totalGB: addBytes,
            expiryTime: Number(payload.expiry_ms || 0),
            enable: true,
            tgId: "",
            subId: payload.subId || token(16),
          };

          const a = await addClientOfficial({ inbound_id, client });
          if (!a.ok) {
            // fall back: inject into obj and update/:id
            const pushed = writeClients(inbObj, [...clients, client]);
            const u = await updateInbound(pushed, inbound_id);
            if (!u.ok) {
              return j(
                {
                  ok: false,
                  error: "add_failed",
                  add_via: a.via || null,
                  add_status: a.status || null,
                  add_text: a.text || null,
                  update_via: u.via || null,
                  update_status: u.status || null,
                  update_text: u.text || null,
                  csrf: !!csrfToken,
                },
                CORS,
                502
              );
            }
          }

          const v = await findClientAnywhere(key);
          if (!v) {
            return j(
              {
                ok: false,
                error: "not_persisted",
                note: "panel accepted but client not found",
                csrf: !!csrfToken,
              },
              CORS,
              502
            );
          }
          return j(
            {
              ok: true,
              created: true,
              renewed: false,
              uuid: client.uuid,
              email: client.email,
              subId: client.subId,
              verify: v,
            },
            CORS
          );
        }
      }

      // Utility: details / verify
      if (action === "details" || action === "verify") {
        const key = String(payload.email || payload.uuid || "").trim();
        if (!key) return j({ ok: false, error: "missing key" }, CORS, 400);
        const v = await findClientAnywhere(key);
        if (!v) return j({ ok: false, error: "not_found" }, CORS, 404);
        if (!v.client.subId) v.client.subId = token(16);
        return j(
          {
            ok: true,
            subId: v.client.subId,
            where: { inbound_id: v.inbound_id, inbound_remark: v.inbound_remark },
          },
          CORS
        );
      }

      return j({ ok: false, error: "unknown_action" }, CORS, 400);
    } catch (e) {
      return new Response(
        JSON.stringify({
          ok: false,
          error: "exception",
          message: String(e && e.message ? e.message : e),
        }),
        { status: 502, headers: { "Content-Type": "application/json" } }
      );
    }
  },
};

function j(o, h, s = 200) {
  return new Response(JSON.stringify(o), {
    status: s,
    headers: { "Content-Type": "application/json", ...(h || {}) },
  });
}

function mergeCookies(headers) {
  // Merge all Set-Cookie cookies into a single Cookie header value (name=value; name2=value2)
  const raw =
    headers.get("set-cookie") || headers.get("Set-Cookie") || "";
  if (!raw) return "";
  // split on commas that separate cookies: , followed by key=
  const parts = raw.split(/,(?=[^;]+?=)/);
  const pairs = parts
    .map((s) => String(s).split(";")[0])
    .filter(Boolean);
  return pairs.join("; ");
}

```

### Test with curl (examples)
**Add client**
```bash
curl -s -X POST "https://YOUR.worker.dev" \
  -H "Content-Type: application/json" \
  -H "X-Alsxui-Secret: YOUR_SHARED_SECRET" \
  -H "X-Alsxui-Action: add" \
  --data '{
    "uuid": "user-uuid-or-id-123",
    "email": "buyer@example.com",
    "limit_ip": 0,
    "total_gb": 50,
    "expiry_ms": 1735689600000,
    "inbound_id": 1
  }'
```

**Fetch details (and force subId if missing)**
```bash
curl -s -X POST "https://YOUR.worker.dev" \
  -H "Content-Type: application/json" \
  -H "X-Alsxui-Secret: YOUR_SHARED_SECRET" \
  -H "X-Alsxui-Action: details" \
  --data '{"uuid":"user-uuid-or-id-123", "inbound_id":1}'
```

---

## Step-by-Step — WordPress Plugin
1) Upload & Activate the plugin zip.  
2) **X-UI SA → Settings → Worker & API**:
   - **Worker Proxy URL** — your Worker URL  
   - **Shared Secret** — must equal `SHARED_SECRET` in the Worker  
   - **(Optional) Allowed Origins** — origins allowed to call the Worker  
3) **Server Settings** — Transport (WS/gRPC), TLS on/off, WS path, SNI.  
4) In each product (Simple / Subscription) → **X-UI SA** meta:
   - **GB** (traffic cap)
   - **Days** (expiry)
5) Make a real/test order → when **Completed**, the Worker is called and results are stored (subscription/uuid/expiry/server) and shown in **My Account**.

---

## Data Model / Units
- **Traffic**: `total_gb` (GB) → Worker converts to bytes `GB * 1024^3`.  
- **Expiry**: `expiry_ms` — epoch milliseconds (e.g., *now + days* × `86400000`).  
- **limit_ip**: integer (0 = unlimited).  
- **Identifiers**: `uuid` (id/email/uuid used to find the client), `subId` ensured/returned.

---

## Troubleshooting
- **401 Unauthorized** → Wrong/missing `X-Alsxui-Secret` or mismatch with `SHARED_SECRET`.  
- **Login failed** → Wrong `PANEL_URL/PANEL_USER/PANEL_PASS` or panel unreachable.  
- **AddClient failed** → Inbound ID wrong / 3x-ui endpoint changed / body not matching your build.  
- **CORS issues** → Check `ALLOWED_ORIGINS` and request `Origin`.  
- **Details returns not found** → Wrong `uuid`/`inbound_id`.

---

## Security Notes
- Keep credentials in **Secrets**; never hardcode them.  
- Lock **ALLOWED_ORIGINS** to your site(s).  
- Run your site & panel on **HTTPS**.  
- Consider Cloudflare **Firewall Rules** / **Rate Limiting** for the Worker.

---

## Persian Guide (راهنمای فارسی)

### نحوهٔ کار
1) با کامل شدن پرداخت، ووکامرس وضعیت سفارش را **Completed** می‌کند.  
2) افزونه به **Cloudflare Worker** شما POST می‌زند (هدرها: `X-Alsxui-Secret`, `X-Alsxui-Action`).  
3) Worker داخل **3x-ui** لاگین کرده، کاربر را در inbound هدف ایجاد/به‌روز می‌کند، اگر **`subId`** نباشد می‌سازد و خروجی را به افزونه برمی‌گرداند تا در **حساب کاربری من** نمایش داده شود.

### پیش‌نیازها
- وردپرس **6.6+**، PHP **7.4+**، ووکامرس  
- پنل **3x-ui** در دسترس (یوزر/پسورد ادمین)  
- اکانت Cloudflare برای ساخت Worker  
- HTTPS توصیه می‌شود

### متغیرهای Worker
**Secrets (رمزگذاری شده)**: `SHARED_SECRET`, `PANEL_USER`, `PANEL_PASS`  
**Plain**: `PANEL_URL`, `ALLOWED_ORIGINS`, `DEBUG`  
هدرها: `X-Alsxui-Secret` (احراز هویت)، `X-Alsxui-Action` (`add`، `details`).

### کد Worker و تست
کد کامل بالا را در Worker بگذارید و Deploy کنید.  
**add**: فیلدها `uuid, email, limit_ip, total_gb, expiry_ms, inbound_id`  
**details**: فیلدها `uuid, inbound_id` — در صورت نبود **`subId`**، ساخته می‌شود.

### تنظیم افزونه در وردپرس
- **X-UI SA → Settings → Worker & API**: Worker URL، Shared Secret، Allowed Origins  
- **Server Settings**: Transport، TLS، WS Path، SNI  
- در محصول: **GB** و **Days**  
- با رسیدن سفارش به **Completed**، ایجاد/تمدید انجام می‌شود و اطلاعات اشتراک در **حساب کاربری من** می‌آید.

### واحدها
- ترافیک: **GB** → بایت (GB × 1024^3)  
- انقضا: **expiry_ms** بر حسب میلی‌ثانیه (اکنون + روز × 86400000)  
- **limit_ip**: عدد صحیح (۰ = بدون محدودیت)  
- **uuid / subId**: شناسه کاربر در 3x-ui، **subId** تضمین می‌شود.

### رفع اشکال
- **401**: Secret غلط/نداشتن هدر.  
- **Login failed**: `PANEL_*` غلط یا دسترس‌ناپذیر.  
- **AddClient failed**: inbound/مسیر API اشتباه.  
- **CORS**: `ALLOWED_ORIGINS` را بررسی کنید.  
- **not found**: `uuid/inbound_id` را چک کنید.

---

## Links
- 3x-ui (MHSanaei): https://github.com/MHSanaei/3x-ui  
- Telegram: https://t.me/VPN_SalesAssociate  
- Developer GitHub: https://github.com/ehsanking/
