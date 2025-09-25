// Worker v1.0.0 – XUI-SA – by EHSANKiNG
export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    const allowed = (env.ALLOWED_ORIGINS || "").split(",").map(s=>s.trim()).filter(Boolean);
    const cors = {
      "Access-Control-Allow-Origin": allowed.length && allowed.includes(origin) ? origin : "*",
      "Access-Control-Allow-Headers":"Content-Type, X-Alsxui-Secret, X-Alsxui-Action",
      "Access-Control-Allow-Methods":"POST, OPTIONS"
    };
    if (request.method === "OPTIONS") return new Response(null,{status:204,headers:cors});
    if (request.method !== "POST") return new Response("Method Not Allowed Please Check: https://t.me/VPN_SalesAssociate",{status:405});

    // Auth
    const secret=request.headers.get("X-Alsxui-Secret");
    if(!secret||secret!==env.SHARED_SECRET) return new Response("Unauthorized",{status:401});

    const action=request.headers.get("X-Alsxui-Action")||"add";
    let payload={}; try{ payload=await request.json(); }catch{ return new Response("Bad JSON",{status:400}); }

    const panel=(env.PANEL_URL||"").replace(/\/+$/,"");
    const user=env.PANEL_USER; const pass=env.PANEL_PASS;
    if(!panel||!user||!pass) return new Response("Missing PANEL_*",{status:500});

    // --- login
    const login=await fetch(panel+"/login",{
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body:JSON.stringify({username:user,password:pass})
    });
    if(login.status!==200){ return res("Login failed: "+login.status+" "+await login.text(),502,cors); }
    const cookie=(login.headers.get("set-cookie")||"").split(";")[0];

    // --- helpers
    function token(n=16){
      const arr = new Uint8Array(n);
      crypto.getRandomValues(arr);
      const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789";
      let out=""; for (let i=0;i<arr.length;i++) out += alphabet[arr[i]%alphabet.length];
      return out;
    }

    async function getInbound(inbound_id){
      const r = await fetch(panel + "/panel/api/inbounds/get/" + Number(inbound_id||1), { headers:{ "Cookie": cookie } });
      if (r.status !== 200) throw new Error("get inbound failed: "+r.status);
      return r.json(); // { obj: { settings: "..." } }
    }

    function parseClientsFromInbound(inbJson){
      try{
        const s = JSON.parse(inbJson?.obj?.settings || "{}");
        return Array.isArray(s.clients) ? s.clients : [];
      }catch{ return []; }
    }

    async function updateClientSubId(inbound_id, clientObjWithNewSubId){
      const body = {
        id: Number(inbound_id||1),
        settings: JSON.stringify({ clients: [ clientObjWithNewSubId ] })
      };
      const r = await fetch(panel + "/panel/api/inbounds/updateClient/", {
        method:"POST",
        headers:{ "Content-Type":"application/json", "Cookie": cookie },
        body: JSON.stringify(body)
      });
      if (r.status !== 200) throw new Error("updateClient failed: "+r.status+" "+await r.text());
      return r.json();
    }

    async function findClient(inbound_id, uuidOrEmail){
      const inb = await getInbound(inbound_id);
      const clients = parseClientsFromInbound(inb);
      return clients.find(c => c.id===uuidOrEmail || c.uuid===uuidOrEmail || c.email===uuidOrEmail) || null;
    }

    async function ensureSubId(inbound_id, client){
      if (client?.subId) return client.subId;
      const newSub = token(16);
      const merged = Object.assign({}, client, { subId: newSub });
      await updateClientSubId(inbound_id, merged);
      return newSub;
    }

    async function addClient({uuid,email,limit_ip=0,total_gb=50,expiry_ms=0,inbound_id=1}){
      // Create sub
      const subId = (payload && payload.subId) ? String(payload.subId) : token(16);

      const clientObj = {
        id: uuid,
        flow: "",
        email,
        limitIp: Number(limit_ip||0),
        totalGB: Number(total_gb||50)*1024*1024*1024,
        total:  Number(total_gb||50)*1024*1024*1024,
        expiryTime: Number(expiry_ms||0),
        enable: true,
        tgId: "",
        subId
      };

      const settings={ clients:[ clientObj ] };
      const r=await fetch(panel+"/panel/api/inbounds/addClient",{
        method:"POST",
        headers:{"Content-Type":"application/json","Cookie":cookie},
        body:JSON.stringify({ id:Number(inbound_id||1), settings:JSON.stringify(settings) })
      });
      const t=await r.text();
      if(r.status!==200) throw new Error("AddClient failed: "+r.status+" "+t);

      // return subId
      return { ok:true, created: parse(t), uuid, subId };
    }

    async function fetchClientDetails({uuid,inbound_id=1}){
      // Client
      const c = await findClient(inbound_id, uuid);
      if (!c) return json({ ok:false, error:"not found" }, cors, 404);

      // subId 
      let subId = c.subId || "";
      if (!subId){
        try{
          subId = await ensureSubId(inbound_id, c);
        }catch(e){
          //SubId
        }
      }
      // SubId
      return json({ ok:true, subId, client: Object.assign({}, c, subId ? { subId } : {}) }, cors);
    }

    // --- router
    try{
      if(action==="add")        return json(await addClient(payload),cors);
      if(action==="details")    return await fetchClientDetails(payload);
      return res("Unknown action",400,cors);
    }catch(e){ return res(String(e),502,cors); }
  }
}

function json(o,c,status=200){ return new Response(JSON.stringify(o),{status,headers:Object.assign({"Content-Type":"application/json"},c||{})}); }
function parse(t){ try{return JSON.parse(t)}catch{return {raw:t}} }
function res(t,s,c){ return new Response(t,{status:s,headers:c}); }
