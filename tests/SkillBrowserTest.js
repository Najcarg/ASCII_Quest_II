"use strict";
// Real template + authoritative PHP/FakeRepository state, local Chromium/CDP only.
const {spawn,execFileSync}=require("node:child_process");
const fs=require("node:fs"),os=require("node:os"),path=require("node:path"),assert=require("node:assert/strict");
const root=path.resolve(__dirname,"..");
const output=path.resolve(process.argv[2]||path.join(os.tmpdir(),"ascii-quest-task34b-browser"));fs.mkdirSync(output,{recursive:true});
const reference=process.argv[3]?JSON.parse(fs.readFileSync(process.argv[3],"utf8")):null;
const profile=fs.mkdtempSync(path.join(os.tmpdir(),"task34b-chromium-"));
const browser=spawn(process.env.CHROMIUM||"chromium",["--headless","--no-sandbox","--disable-gpu","--allow-file-access-from-files","--remote-debugging-port=0",`--user-data-dir=${profile}`,"about:blank"],{stdio:["ignore","ignore","pipe"]});
let socket,next=0;const pending=new Map(),network=[],results=[];
function command(method,params={}){const id=++next;return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{pending.delete(id);reject(Error("CDP timeout "+method));},15000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params}));});}
async function evaluate(expression){const r=await command("Runtime.evaluate",{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
async function screenshot(name){const m=await command("Page.getLayoutMetrics");const r=await command("Page.captureScreenshot",{captureBeyondViewport:true,clip:{x:0,y:0,width:m.cssContentSize.width,height:m.cssContentSize.height,scale:1}});fs.writeFileSync(path.join(output,name),Buffer.from(r.data,"base64"));}
async function wait(){for(let i=0;i<100;i++){if(await evaluate("!!window.ASCIIQuestSkillsController && !ASCIIQuestSkillsController.pending()"))return;await new Promise(r=>setTimeout(r,30));}throw Error("Skill controller did not settle");}
async function select(key){await evaluate(`document.querySelector('[data-skill-key="${key}"]').click()`);}
async function measure(){return evaluate(`(()=>{const rect=s=>{const r=document.querySelector(s).getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};};return {map:rect('.map-area'),left:rect('.hud-left-panel'),right:rect('.hud-right-panel'),row:rect('.hud-lower-row'),overflow:document.documentElement.scrollWidth>innerWidth};})()`);}
(async()=>{
    const endpoint=await new Promise((resolve,reject)=>{let stderr="";browser.stderr.on("data",c=>{stderr+=c;const m=stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m)resolve(m[1]);});browser.on("error",reject);browser.on("exit",code=>reject(Error("Chromium exited "+code)));});
    const targets=await(await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json`)).json();socket=new WebSocket(targets.find(t=>t.type==="page").webSocketDebuggerUrl);await new Promise(r=>socket.onopen=r);
    socket.onmessage=({data})=>{const m=JSON.parse(data);if(m.method==="Network.requestWillBeSent"&&/\.php(?:\?|$)/.test(m.params.request.url))network.push(m.params.request.url);const p=pending.get(m.id);if(!p)return;pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(JSON.stringify(m.error))):p.resolve(m.result);};
    await command("Page.enable");await command("Network.enable");
    let injection;
    for(const cls of ["warrior","mage","rogue","cleric"]){
        const sample=JSON.parse(execFileSync("php",[path.join(__dirname,"fixtures/task34b_states.php"),cls],{encoding:"utf8"}));
        const html=path.join(output,cls+".html");fs.writeFileSync(html,execFileSync("php",[path.join(__dirname,"fixtures/task34b_game.php"),root,"--class="+cls,"--level=5"]));
        if(injection)await command("Page.removeScriptToEvaluateOnNewDocument",{identifier:injection});
        injection=(await command("Page.addScriptToEvaluateOnNewDocument",{source:`Object.defineProperty(window.crypto,"randomUUID",{value:undefined,configurable:true});window.__fixture=${JSON.stringify(sample)};window.__skillCalls=[];window.__op=0;window.fetch=async(url,options)=>{const body=JSON.parse(options.body);const op=__fixture.operations[__op++];const command=String(url).split('_skill.php')[0];if(!op||command!==op.command||(body.skill_key??null)!==op.key||(body.slot??null)!==op.slot)throw Error('Unexpected fixture intent');__skillCalls.push({url,body});return {ok:true,json:async()=>({result:op.result,state:op.state})};};`})).identifier;
        await command("Emulation.setDeviceMetricsOverride",{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await command("Page.navigate",{url:`file://${html}`});await wait();
        assert.equal(await evaluate("document.querySelectorAll('[data-skill-key]').length"),4);
        assert.equal(await evaluate("document.querySelectorAll('.skill-connection').length"),2);
        assert.equal(await evaluate("document.querySelectorAll('.skill-node-ultimate .skill-ultimate-label').length"),1);
        await evaluate("document.querySelector('[data-inventory-slot]').click();document.querySelector('[data-tab-target=right-skills]').click()");
        const itemName=await evaluate("document.getElementById('inventoryDetailName').textContent");
        for(const [name,position,reason,enabled] of [["level1",0,"Required level",false],["level2",0,"Available",true],["level3",1,"prerequisite",false],["level3_root",1,"Available",true],["level3_two",2,"No skill points",false],["level5",3,"prerequisite",false]]){
            await evaluate(`ASCIIQuestSkillsController.applyState(__fixture.states.${name})`);await select(sample.keys[position]);
            assert.equal(await evaluate("!document.getElementById('skillUnlockButton').disabled"),enabled,cls+name);
            assert.ok((await evaluate("document.getElementById('skillActionReason').textContent")).includes(reason),cls+name+reason);
            assert.equal(await evaluate("document.getElementById('itemDetailsTitle').textContent"),"Skill Details");
            results.push({class:cls,state:name,unlockEnabled:enabled});
        }
        assert.equal(await evaluate("__skillCalls.length"),0,"Selection/point state changes are presentation only");
        await evaluate("ASCIIQuestSkillsController.applyState(__fixture.states.level5)");
        for(const [i,op] of sample.operations.entries()){
            if(op.command==="unassign")await select(sample.keys[0]);else await select(op.key);
            const before=await measure();
            const button=op.command==="unlock"?"document.getElementById('skillUnlockButton')":op.command==="unassign"?"document.getElementById('skillUnassignButton')":`document.querySelector('[data-skill-assign="${op.slot}"]')`;
            assert.equal(await evaluate(`${button}.disabled`),false,cls+JSON.stringify(op));
            await evaluate(`${button}.scrollIntoView({block:'nearest'});${button}.click()`);await wait();
            assert.equal(await evaluate("__skillCalls.length"),i+1);
            const state=await evaluate("ASCIIQuestSkillsController.state()");assert.deepEqual(state,op.state,"AJAX refresh follows actual server projection");
            assert.equal((await measure()).overflow,false);
            assert.deepEqual((await measure()).map,before.map,"AJAX leaves map geometry unchanged");
            const tokens=await evaluate("__skillCalls.map(c=>c.body.request_token)");assert.equal(new Set(tokens).size,tokens.length);assert.ok(tokens.every(t=>/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(t)),"LAN crypto fallback produces UUIDv4");
            assert.equal(await evaluate("__skillCalls.some(c=>Object.hasOwn(c.body,'points')||Object.hasOwn(c.body,'level'))"),false);
        }
        assert.equal(await evaluate("ASCIIQuestSkillsController.state().points.available"),0);
        assert.equal(await evaluate("document.querySelector('[data-loadout-slot=skill_1] strong').textContent"),"Empty");
        assert.equal(await evaluate("document.querySelector('[data-loadout-slot=skill_2] strong').textContent"),"Empty");
        assert.equal(await evaluate("document.querySelectorAll('.skill-node.is-assigned').length"),2,"Moved source/target and unassign are authoritative");
        for(const key of ["active","victory_loot"]){
            await evaluate(`ASCIIQuestSkillsController.applyState(__fixture.states.${key})`);await select(sample.keys[2]);
            assert.equal(await evaluate("[...document.querySelectorAll('[data-skill-assign]')].filter(b=>!b.hidden).every(b=>b.disabled)"),true);
            assert.ok((await evaluate("document.getElementById('skillActionReason').textContent")).includes("combat"));
        }
        await evaluate("ASCIIQuestSkillsController.applyState(__fixture.operations[7].state)");
        for(const width of [1440,1101,1100,720,390,320]){
            await command("Emulation.setDeviceMetricsOverride",{width,height:1000,deviceScaleFactor:1,mobile:false});
            await evaluate("document.querySelector('[data-tab-target=right-items]').click()");
            await evaluate("window.scrollTo(0,0)");
            const itemsLayout=await measure();
            await evaluate("document.querySelector('[data-tab-target=right-skills]').click()");
            const baseline=await measure();
            await select(sample.keys[3]);
            const selected=await measure();assert.equal(selected.overflow,false);
            assert.deepEqual(selected.map,itemsLayout.map,"Tree selection preserves map rectangle "+width);
            if(width>1100){
                assert.deepEqual(selected.left,baseline.left);assert.deepEqual(selected.right,baseline.right);assert.deepEqual(selected.row,baseline.row,"Desktop Details bounded inside existing frame");
                if(reference){
                    const old=reference[width===1440?"1440x900-right-skills":width+"x1100-right-skills"];
                    for(const [now,previous] of [["left","left"],["right","items"],["row","row"],["map","map"]]){
                        const box=Object.fromEntries(Object.keys(selected[now]).map(k=>[k,old[previous][k]]));assert.deepEqual(selected[now],box,"Exact34A skill-tab geometry "+width+now);
                    }
                }
            }
            assert.equal(await evaluate("document.querySelectorAll('.item-details-panel').length"),1);
            assert.equal(await evaluate("(()=>{const a=document.getElementById('skillDetailActions').getBoundingClientRect(),p=document.querySelector('.item-details-panel').getBoundingClientRect();return a.top>=p.top&&a.bottom<=p.bottom;})()"),true,"Controls visible inside Details frame");
            assert.equal(await evaluate("document.querySelector('[data-skill-assign=ultimate]').hidden"),false);
            assert.equal(await evaluate("[...document.querySelectorAll('[data-skill-assign]')].filter(b=>!b.hidden).length"),1);
            if((cls==="mage"&&width===1440)||(cls==="warrior"&&width===390))await screenshot(cls+"-assigned-"+width+".png");
            results.push({class:cls,width,selectedLayout:selected});
        }
        const calls=await evaluate("__skillCalls.length");
        for(const tab of ["right-items","right-passives","right-skills"]){await evaluate(`document.querySelector('[data-tab-target=${tab}]').click()`);}
        assert.equal(await evaluate("__skillCalls.length"),calls,"Tabs do not issue requests");
        await evaluate("document.querySelector('[data-tab-target=right-items]').click()");
        assert.equal(await evaluate("document.getElementById('itemDetailsTitle').textContent"),"Item Details");assert.equal(await evaluate("document.getElementById('inventoryDetailName').textContent"),itemName);
        await evaluate("document.querySelector('[data-tab-target=right-passives]').click()");
        assert.ok((await evaluate("document.getElementById('right-passives').textContent")).includes("Passive state unavailable"));
        results.push({class:cls,ajaxOperations:calls,retainedItem:true,noTabRequests:true});
    }
    // Independent SSR fixtures: left34A level and skill points are coherent,
    // unlike intentionally applied in-memory lower-level projections above.
    for(const level of [1,2,3]){
        const filename=path.join(output,"mage-level"+level+".html");
        const args=[path.join(__dirname,"fixtures/task34b_game.php"),root,"--class=mage","--level="+level];if(level===3)args.push("--learn-root");
        fs.writeFileSync(filename,execFileSync("php",args));
        await command("Emulation.setDeviceMetricsOverride",{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await command("Page.navigate",{url:`file://${filename}`});await wait();
        await evaluate("document.querySelector('[data-tab-target=right-skills]').click()");await select(level===3?"mage_frost_bolt":"mage_flame_strike");
        assert.equal(await evaluate("document.getElementById('playerLevel').textContent"),String(level));
        assert.equal(await evaluate("!document.getElementById('skillUnlockButton').disabled"),level!==1);
        await screenshot("mage-level"+level+".png");results.push({coherentSsrLevel:level,fixtureOnly:true});
    }
    // Actual four-slot PHP combat projection; no polling, actions, or live server.
    const combatHtml=path.join(output,"mage-combat.html");fs.writeFileSync(combatHtml,execFileSync("php",[path.join(__dirname,"fixtures/task34b_game.php"),root,"--class=mage","--combat","--assigned"]));
    await command("Emulation.setDeviceMetricsOverride",{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await command("Page.navigate",{url:`file://${combatHtml}`});await wait();
    await evaluate(`window.ASCII_QUEST_STATE.mode='fixture';new Promise((resolve,reject)=>{const s=document.createElement('script');s.src=${JSON.stringify(`file://${root}/ascii-quest/js/combat_hud.js`)};s.onload=resolve;s.onerror=reject;document.head.append(s);})`);
    await evaluate("ASCIIQuestCombatHud.renderCombatHud(document,ASCII_QUEST_STATE.combat,{},0)");
    const combat=await evaluate("['combatSkill1','combatSkill2','combatSkill3','combatUltimate'].map(id=>({name:document.getElementById(id+'Name').textContent,status:document.getElementById(id+'Status').textContent,ready:document.getElementById(id+'Button').classList.contains('combat-action-ready')}))");
    assert.deepEqual(combat.map(s=>s.name),["Flame Strike","Frost Bolt","Lightning Bolt","Arcane Inferno"]);assert.ok(combat.every(s=>s.status==="Ready"&&s.ready));
    await evaluate("document.querySelector('[data-tab-target=right-skills]').click()");await select("mage_frost_bolt");await screenshot("mage-combat-four-slots.png");
    results.push({combat,allFourAssigned:true});assert.equal(network.length,0,"No real application API calls");
    fs.writeFileSync(path.join(output,"results.json"),JSON.stringify(results,null,2));
    console.log(`Task34B browser PASS:4 class trees,24 level/reason states,40 AJAX operations,24 responsive layouts,shared Details/items/passive/no-tab calls,and4 combat slots. Evidence: ${output}`);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(socket)socket.close();for(const p of pending.values())clearTimeout(p.timer);if(browser.exitCode===null&&browser.signalCode===null)await new Promise(r=>{browser.once("exit",r);browser.kill();});fs.rmSync(profile,{recursive:true,force:true,maxRetries:10,retryDelay:100});});
