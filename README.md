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

### Worker Code (v1.0.0)

```js
// Worker v1.0.0 – XUI-SA – by EHSANKiNG
export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    const allowed = (env.ALLOWED_ORIGINS || "").split(",").map(s=>s.trim()).filter(Boolean);
    const cors = {
      "Access-Control-Allow-Origin": allowed.length && allowed.includes(origin) ? origin : "*",
      "Access-Control-Allow-Headers": "Content-Type, X-Alsxui-Secret, X-Alsxui-Action",
      "Access-Control-Allow-Methods": "POST, OPTIONS"
    };
    if (request.method === "OPTIONS") return new Response(null,{status:204,headers:cors});
    if (request.method !== "POST") return new Response("Method Not Allowed", {status:405});

    // Auth
    const secret = request.headers.get("X-Alsxui-Secret");
    if (!secret || secret !== env.SHARED_SECRET) return new Response("Unauthorized", {status:401});

    const action = request.headers.get("X-Alsxui-Action") || "add";
    let payload = {}; try { payload = await request.json(); } catch { return new Response("Bad JSON",{status:400}); }

    const panel=(env.PANEL_URL||"").replace(/\/+$/,"");
    const user=env.PANEL_USER; const pass=env.PANEL_PASS;
    if(!panel||!user||!pass) return new Response("Missing PANEL_*",{status:500});

    // --- login
    const login=await fetch(panel+"/login",{
      method:"POST", headers:{"Content-Type":"application/json"},
      body:JSON.stringify({username:user,password:pass})
    });
    if(login.status!==200) return res("Login failed: "+login.status+" "+await login.text(),502,cors);
    const cookie=(login.headers.get("set-cookie")||"").split(";")[0];

    // helpers
    function token(n=16){ const a=new Uint8Array(n); crypto.getRandomValues(a);
      const abc="abcdefghijklmnopqrstuvwxyz0123456789"; let out=""; a.forEach(v=>out+=abc[v%abc.length]); return out; }

    async function getInbound(inbound_id){
      const r=await fetch(panel+"/panel/api/inbounds/get/"+Number(inbound_id||1),{headers:{"Cookie":cookie}});
      if(r.status!==200) throw new Error("get inbound failed: "+r.status);
      return r.json();
    }
    function parseClientsFromInbound(j){ try{ const s=JSON.parse(j?.obj?.settings||"{}"); return Array.isArray(s.clients)?s.clients:[] }catch{ return [] } }
    async function updateClientSubId(inbound_id, clientObj){
      const body={ id:Number(inbound_id||1), settings:JSON.stringify({clients:[clientObj]}) };
      const r=await fetch(panel+"/panel/api/inbounds/updateClient/",{
        method:"POST", headers:{"Content-Type":"application/json","Cookie":cookie},
        body:JSON.stringify(body)
      });
      if(r.status!==200) throw new Error("updateClient failed: "+r.status+" "+await r.text());
      return r.json();
    }
    async function findClient(inbound_id, key){
      const inb=await getInbound(inbound_id); const clients=parseClientsFromInbound(inb);
      return clients.find(c => c.id===key || c.uuid===key || c.email===key) || null;
    }
    async function ensureSubId(inbound_id, client){
      if(client?.subId) return client.subId;
      const subId = token(16); const merged = {...client, subId};
      await updateClientSubId(inbound_id, merged); return subId;
    }

    async function addClient({uuid,email,limit_ip=0,total_gb=50,expiry_ms=0,inbound_id=1}){
      const subId = (payload && payload.subId) ? String(payload.subId) : token(16);
      const bytes = Number(total_gb||50) * 1024 * 1024 * 1024; // GB→bytes
      const clientObj = { id: uuid, flow:"", email, limitIp:Number(limit_ip||0), totalGB:bytes, total:bytes,
                          expiryTime:Number(expiry_ms||0), enable:true, tgId:"", subId };
      const settings={clients:[clientObj]};
      const r=await fetch(panel+"/panel/api/inbounds/addClient",{
        method:"POST", headers:{"Content-Type":"application/json","Cookie":cookie},
        body:JSON.stringify({ id:Number(inbound_id||1), settings:JSON.stringify(settings) })
      });
      const t=await r.text(); if(r.status!==200) throw new Error("AddClient failed: "+r.status+" "+t);
      return { ok:true, created: parse(t), uuid, subId };
    }

    async function fetchClientDetails({uuid,inbound_id=1}){
      const c=await findClient(inbound_id, uuid); if(!c) return json({ok:false,error:"not found"}, cors, 404);
      let subId=c.subId||""; if(!subId){ try{ subId=await ensureSubId(inbound_id, c); }catch(e){} }
      return json({ ok:true, subId, client: {...c, ...(subId?{subId}:{})} }, cors);
    }

    try{
      if(action==="add")     return json(await addClient(payload), cors);
      if(action==="details") return await fetchClientDetails(payload);
      return res("Unknown action",400,cors);
    }catch(e){ return res(String(e),502,cors); }
  }
}
function json(o,c,status=200){ return new Response(JSON.stringify(o),{status,headers:{"Content-Type":"application/json",...(c||{})}}); }
function parse(t){ try{return JSON.parse(t)}catch{return {raw:t}} }
function res(t,s,c){ return new Response(t,{status:s,headers:c}); }
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
