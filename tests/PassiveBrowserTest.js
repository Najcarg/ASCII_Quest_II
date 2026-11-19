"use strict";
// Real template + authoritative PHP service projections. No DB/network app calls.
const {spawn,execFileSync}=require("node:child_process"),fs=require("node:fs"),os=require("node:os"),path=require("node:path"),assert=require("node:assert/strict");
const root=path.resolve(__dirname,".."),output=path.resolve(process.argv[2]||path.join(os.tmpdir(),"ascii-quest-task35-browser"));fs.mkdirSync(output,{recursive:true});
const sample=JSON.parse(execFileSync("php",[path.join(__dirname,"fixtures/task35_states.php")],{encoding:"utf8",maxBuffer:8*1024*1024}));
const profile=fs.mkdtempSync(path.join(os.tmpdir(),"task35-chromium-"));
const browser=spawn(process.env.CHROMIUM||"chromium",["--headless","--no-sandbox","--disable-gpu","--allow-file-access-from-files","--remote-debugging-port=0",`--user-data-dir=${profile}`,"about:blank"],{stdio:["ignore","ignore","pipe"]});
let socket,next=0;const pending=new Map(),results=[],network=[];
function command(method,params={}){const id=++next;return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{pending.delete(id);reject(Error("CDP timeout "+method));},15000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});}
async function evaluate(expression){const result=await command("Runtime.evaluate",{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw Error(JSON.stringify(result.exceptionDetails));return result.result.value;}
async function ready(){for(let i=0;i<100;i++){if(await evaluate("!!window.ASCIIQuestPassivesController && !ASCIIQuestPassivesController.pending()"))return;await new Promise(r=>setTimeout(r,30));}throw Error("Passive controller did not settle");}
async function screenshot(name){const m=await command("Page.getLayoutMetrics"),r=await command("Page.captureScreenshot",{captureBeyondViewport:true,clip:{x:0,y:0,width:m.cssContentSize.width,height:m.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(output,name),Buffer.from(r.data,"base64"));}
async function measure(){return evaluate(`(()=>{const rect=s=>{const r=document.querySelector(s).getBoundingClientRect();return{x:r.x,y:r.y,width:r.width,height:r.height};};return{map:rect('.map-area'),left:rect('.hud-left-panel'),right:rect('.hud-right-panel'),row:rect('.hud-lower-row'),overflow:document.documentElement.scrollWidth>innerWidth};})()`);}
(async()=>{
 const endpoint=await new Promise((resolve,reject)=>{let stderr="";browser.stderr.on("data",data=>{stderr+=data;const m=stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m)resolve(m[1]);});browser.on("error",reject);browser.on("exit",code=>reject(Error("Chromium exited "+code)));});
 const targets=await(await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json`)).json();socket=new WebSocket(targets.find(t=>t.type==="page").webSocketDebuggerUrl);await new Promise(r=>socket.onopen=r);
 socket.onmessage=({data})=>{const m=JSON.parse(data);if(m.method==="Network.requestWillBeSent"&&/\.php(?:\?|$)/.test(m.params.request.url))network.push(m.params.request.url);const p=pending.get(m.id);if(!p)return;pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(JSON.stringify(m.error))):p.resolve(m.result);};
 await command("Page.enable");await command("Network.enable");
 await command("Page.addScriptToEvaluateOnNewDocument",{source:`window.__fixture=${JSON.stringify(sample)};window.__passiveCalls=[];window.__op=0;window.fetch=async(url,options)=>{const body=JSON.parse(options.body);const op=__fixture.operations[__op++];if(url!=='unlock_passive.php'||body.passive_key!==op.key)throw Error('Unexpected passive intent');__passiveCalls.push({url,body});return{ok:true,json:async()=>({result:op.result,state:op.state})};};`});
 let html;
 for(const[level,spent]of[[50,0],[51,0],[55,5],[70,20],[71,20],[90,40]]){
  html=path.join(output,`level${level}.html`);fs.writeFileSync(html,execFileSync("php",[path.join(__dirname,"fixtures/task35_game.php"),root,"--level="+level,"--spent="+spent]));
  await command("Emulation.setDeviceMetricsOverride",{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await command("Page.navigate",{url:`file://${html}`});await ready();
  await evaluate("document.querySelector('[data-tab-target=right-skills]').click()");const baseline=await measure();await evaluate("document.querySelector('[data-tab-target=right-passives]').click()");
  assert.equal(await evaluate("document.querySelectorAll('[data-passive-key]').length"),80);assert.equal(await evaluate("document.querySelectorAll('.passive-row').length"),20);
  const state=sample.states[`level${level}_spent${spent}`];assert.equal(await evaluate("ASCIIQuestPassivesController.state().points.earned"),state.points.earned);
  const candidate=state.rows[0].nodes.find(n=>n.state==="available")||state.rows[0].nodes[0];await evaluate(`document.querySelector('[data-passive-key="${candidate.key}"]').click()`);
  assert.equal(await evaluate("!document.getElementById('passiveUnlockButton').disabled"),candidate.state==="available");assert.equal(await evaluate("document.getElementById('itemDetailsTitle').textContent"),"Passive Details");
  assert.deepEqual((await measure()).map,baseline.map,"Desktop map stays frozen");assert.deepEqual((await measure()).right,baseline.right,"Desktop right frame stays bounded");assert.deepEqual((await measure()).row,baseline.row,"Desktop lower frame unchanged");
  assert.equal(await evaluate("(()=>{const b=document.getElementById('passiveBoard');return b.scrollHeight>b.clientHeight && b.clientHeight>0;})()"),true,"Internal scroll not expanded outer panel");
  if([51,70,71,90].includes(level))await screenshot(`level${level}-1440.png`);
  results.push({level,spent,points:state.points,progress:state.progress,layout:await measure(),internalScroll:true});
 }
 // Full second-pass completion from a fresh Level90 board, forty actual clicks.
 await evaluate("ASCIIQuestPassivesController.applyState(__fixture.states.level90_spent0)");
 for(const[i,op]of sample.operations.entries()){
  const before=await measure();await evaluate(`document.querySelector('[data-passive-key="${op.key}"]').click();document.getElementById('passiveUnlockButton').click()`);await ready();
  assert.equal(await evaluate("__passiveCalls.length"),i+1);assert.deepEqual(await evaluate("ASCIIQuestPassivesController.state()"),op.state);
  assert.deepEqual((await measure()).map,before.map,"AJAX passive leaves map geometry unchanged");
 }
 assert.equal(await evaluate("document.querySelectorAll('.unlocked-pass-1').length"),20);assert.equal(await evaluate("document.querySelectorAll('.unlocked-pass-2').length"),20);
 assert.equal(await evaluate("__passiveCalls.some(c=>Object.hasOwn(c.body,'points')||Object.hasOwn(c.body,'level')||Object.hasOwn(c.body,'pass_number'))"),false);
 results.push({ajaxOperations:40,pass1Markers:20,pass2Markers:20,complete:true});await screenshot("level90-full-two-pass.png");
 for(const width of[1440,1101,1100,720,390,320]){
  await command("Emulation.setDeviceMetricsOverride",{width,height:1000,deviceScaleFactor:1,mobile:false});
  await evaluate("document.querySelector('[data-tab-target=right-items]').click();window.scrollTo(0,0)");const items=await measure();
  await evaluate("document.querySelector('[data-tab-target=right-passives]').click();ASCIIQuestPassivesController.select('passive_r20_d_divine_vitality')");const passive=await measure();
  assert.equal(passive.overflow,false,"No horizontal overflow at"+width);assert.deepEqual(passive.map,items.map,"Map unchanged "+width);
  if(width>1100){
   await evaluate("document.querySelector('[data-tab-target=right-skills]').click()");const skills=await measure();
   assert.deepEqual(passive.right,skills.right,"Passive outer frame matches existing Skill tab");assert.deepEqual(passive.row,skills.row);
   await evaluate("document.querySelector('[data-tab-target=right-passives]').click()");
  }
  assert.equal(await evaluate("(()=>{const a=document.getElementById('passiveDetailActions').getBoundingClientRect(),p=document.querySelector('.item-details-panel').getBoundingClientRect();return a.top>=p.top&&a.bottom<=p.bottom;})()"),true,"Details controls bounded "+width);
  assert.equal(await evaluate("document.querySelectorAll('.item-details-panel').length"),1,"Shared physical Details panel");
  assert.equal(await evaluate("(()=>{const b=document.getElementById('passiveBoard');b.scrollTop=b.scrollHeight;return b.scrollTop>0&&b.scrollHeight>b.clientHeight;})()"),true,"Board remains internally scrollable");
  const calls=await evaluate("__passiveCalls.length");
  await evaluate("document.querySelector('[data-inventory-slot]').click();document.querySelector('[data-tab-target=right-skills]').click();ASCIIQuestSkillsController.select('warrior_power_strike')");assert.equal(await evaluate("document.getElementById('itemDetailsTitle').textContent"),"Skill Details");
  await evaluate("document.querySelector('[data-tab-target=right-passives]').click()");assert.equal(await evaluate("document.getElementById('contextDetailName').textContent"),"Divine Vitality");assert.equal(await evaluate("document.getElementById('skillDetailActions').hidden"),true);
  await evaluate("document.querySelector('[data-tab-target=right-items]').click()");assert.equal(await evaluate("document.getElementById('itemDetailsTitle').textContent"),"Item Details");assert.equal(await evaluate("document.getElementById('passiveDetailActions').hidden"),true);assert.equal(await evaluate("__passiveCalls.length"),calls,"Tabs don't fetch");
  await evaluate("document.querySelector('[data-tab-target=right-passives]').click();document.getElementById('passiveBoard').scrollTop=document.getElementById('passiveBoard').scrollHeight");if([1100,720,390].includes(width))await screenshot(`major-details-${width}.png`);
  results.push({width,itemsLayout:items,passiveLayout:passive,internalScroll:true,sharedDetails:true});
 }
 assert.equal(network.length,0,"No real application API requests");fs.writeFileSync(path.join(output,"results.json"),JSON.stringify(results,null,2));
 console.log(`Task35 browser PASS:6 level fixtures,40 AJAX unlocks,two pass transitions,40 pass markers,6 responsive layouts,shared Details,internal scroll. Evidence: ${output}`);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(socket)socket.close();for(const p of pending.values())clearTimeout(p.timer);if(browser.exitCode===null&&browser.signalCode===null)await new Promise(r=>{browser.once("exit",r);browser.kill();});fs.rmSync(profile,{recursive:true,force:true,maxRetries:10,retryDelay:100});});
