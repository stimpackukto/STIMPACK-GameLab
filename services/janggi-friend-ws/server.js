'use strict';
// Friend invitation events only. Existing PvP HTTP + MySQL logic remains untouched.
const http=require('http');
const crypto=require('crypto');
const {WebSocketServer}=require('ws');
const secret=process.env.JANGGI_FRIEND_WS_SECRET||'';
if(Buffer.byteLength(secret)<32){throw new Error('JANGGI_FRIEND_WS_SECRET must be at least 32 bytes');}
const sign=s=>crypto.createHmac('sha256',secret).update(s).digest('hex');
const safeEqual=(a,b)=>typeof a==='string'&&typeof b==='string'&&a.length===b.length&&
 crypto.timingSafeEqual(Buffer.from(a),Buffer.from(b));
const channels=new Map();
const wss=new WebSocketServer({noServer:true,maxPayload:1024,perMessageDeflate:false});
function attach(account,ws){
 if(!channels.has(account))channels.set(account,new Set());
 channels.get(account).add(ws);
 ws.on('close',()=>{const set=channels.get(account);if(!set)return;set.delete(ws);if(!set.size)channels.delete(account);});
 ws.on('message',()=>ws.close(1008,'read only'));
}
const server=http.createServer((req,res)=>{
 if(req.url==='/health'&&req.method==='GET'){res.writeHead(200);res.end('ok');return;}
 if(req.url==='/presence'&&req.method==='POST'){
  let body='';req.on('data',chunk=>{body+=chunk;if(body.length>1024)req.destroy();});
  req.on('end',()=>{
   if(!safeEqual(req.headers['x-janggi-signature'],sign(body))){res.writeHead(403);res.end();return;}
   let data;try{data=JSON.parse(body);}catch{res.writeHead(400);res.end();return;}
   if(!Array.isArray(data.accounts)||data.accounts.length>100||
      data.accounts.some(key=>typeof key!=='string'||!/^[a-f0-9]{64}$/.test(key))){
    res.writeHead(400);res.end();return;
   }
   const online=data.accounts.filter(key=>{
    const peers=channels.get(key);return !!peers&&Array.from(peers).some(ws=>ws.readyState===1);
   });
   res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});
   res.end(JSON.stringify({ok:true,online:online}));
  });return;
 }
 if(req.url!=='/notify'||req.method!=='POST'){res.writeHead(404);res.end();return;}
 let data='';req.on('data',chunk=>{data+=chunk;if(data.length>1024)req.destroy();});
 req.on('end',()=>{
  if(!safeEqual(req.headers['x-janggi-signature'],sign(data))){res.writeHead(403);res.end();return;}
  let payload;try{payload=JSON.parse(data);}catch{res.writeHead(400);res.end();return;}
  if(!/^[a-f0-9]{64}$/.test(payload.account)||!['invite','accept','decline','cancel'].includes(payload.event)){
   res.writeHead(400);res.end();return;
  }
  const event=JSON.stringify({type:'friend-invite-changed',event:payload.event,id:payload.id});
  for(const ws of channels.get(payload.account)||[])if(ws.readyState===1)ws.send(event);
  res.writeHead(200);res.end('ok');
 });
});
server.on('upgrade',(req,socket,head)=>{
 try{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname!=='/ws/janggi-friends'||req.headers.origin!=='https://stimpack.pe.kr')throw Error('origin');
  const ticket=url.searchParams.get('ticket')||'';
  const parts=ticket.split('.');
  if(parts.length!==4)throw Error('ticket');
  const [account,expiry,nonce,mac]=parts;
  if(!/^[a-f0-9]{64}$/.test(account)||!/^[0-9]{10}$/.test(expiry)||!/^[a-f0-9]{24}$/.test(nonce))throw Error('ticket');
  if(+expiry<Date.now()/1000||+expiry>Date.now()/1000+100)throw Error('expired');
  if(!safeEqual(mac,sign(account+'.'+expiry+'.'+nonce)))throw Error('signature');
  wss.handleUpgrade(req,socket,head,ws=>attach(account,ws));
 }catch{socket.write('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');socket.destroy();}
});
// Keep otherwise idle WSS connections healthy through Nginx without DB queries.
const heartbeat=setInterval(()=>{for(const client of wss.clients){
 if(client.readyState===1){try{client.ping();}catch{client.terminate();}}
}},30000);
heartbeat.unref();
server.listen(18771,'127.0.0.1',()=>console.log('Janggi friend notifications on 127.0.0.1:18771'));
