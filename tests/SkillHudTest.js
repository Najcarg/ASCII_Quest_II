"use strict";
const assert=require("node:assert/strict");
const {execFileSync}=require("node:child_process");
const path=require("node:path");
const hud=require("../ascii-quest/js/skill_hud.js");
const itemHud=require("../ascii-quest/js/item_hud.js");
const combatHud=require("../ascii-quest/js/combat_hud.js");
function data(cls="warrior") { return JSON.parse(execFileSync("php",[path.join(__dirname,"fixtures/task34b_states.php"),cls],{encoding:"utf8"})); }
function element() {
    const listeners={}, classes=new Set();let value="";
    return {dataset:{},attributes:{},hidden:false,disabled:false,children:[],
        classList:{toggle(k,on){on?classes.add(k):classes.delete(k);},contains:k=>classes.has(k),add(...v){v.forEach(k=>classes.add(k));},remove(...v){v.forEach(k=>classes.delete(k));}},
        setAttribute(k,v){this.attributes[k]=v;},addEventListener(k,fn){(listeners[k]||=[]).push(fn);},
        click(){if(!this.disabled&&!this.hidden) return Promise.all((listeners.click||[]).map(fn=>fn({currentTarget:this})));},
        replaceChildren(...v){this.children=v;value="";},get textContent(){return this.children.length?this.children.map(n=>n.textContent).join(""):value;},set textContent(v){this.children=[];value=String(v);},querySelector(){return null;}};
}
function fixture(sample=data(),initial=sample.states.level5,fetchImplementation=null) {
    const ids=Object.fromEntries(["skillPoints","skillMessage","skillRefreshButton","skillUnlockButton","skillActionReason","skillUnassignButton","skillDetailActions","contextDetails","contextDetailName","contextDetailDescription","contextDetailStats","itemDetailsTitle","inventoryDetailsEmpty","inventoryDetails","inventoryDetailName","inventoryDetailMeta","inventoryDetailStats"].map(k=>[k,element()]));
    const tabs=["item","skill","passive"].map(k=>{const n=element();n.dataset.detailsMode=k;return n;});
    const buttons=initial.skills.map(s=>{const n=element();n.dataset.skillKey=s.key;const state=element(),assigned=element();n.querySelector=q=>q==="[data-skill-state]"?state:q==="[data-skill-assigned]"?assigned:null;return n;});
    const assignments=["skill_1","skill_2","skill_3","ultimate"].map(k=>{const n=element();n.dataset.skillAssign=k;return n;});
    const loadout=Object.fromEntries(assignments.map(n=>[n.dataset.skillAssign,element()]));
    const document={getElementById:k=>ids[k]||null,createElement:element,querySelectorAll(q){
        if(q==="[data-details-mode]")return tabs;if(q==="[data-skill-key]")return buttons;if(q==="[data-skill-assign]")return assignments;
        const m=q.match(/data-(?:skill-loadout|loadout-slot)="([^"]+)"/);return m?[loadout[m[1]]]:[];
    }};
    const details=itemHud.createDetailsController(document);
    let uuid=0;
    const controller=hud.createController({document,details,initialState:initial,csrfToken:"fixture",uuidFactory:()=>"fixture-"+(++uuid),fetchImplementation});
    return {ids,tabs,buttons,assignments,loadout,details,controller};
}
const tests={};
for(const cls of ["warrior","mage","rogue","cleric"])tests[cls+" four projected nodes and all details fields"]=()=>{
    const d=data(cls), f=fixture(d);f.tabs[1].click();f.controller.select(d.keys[1]);
    const detail=f.ids.contextDetailStats.textContent;
    for(const field of ["Damage:","Effect:","Cooldown:","Base action duration:","Required level:","Prerequisite:","Status:","Slot:","Unlock:"])assert.ok(detail.includes(field),field);
    assert.equal(f.ids.contextDetailName.textContent,d.states.level5.skills[1].name);assert.equal(f.buttons.length,4);
    assert.ok(f.buttons[1].dataset.prerequisites.includes(d.keys[0]));assert.equal(f.ids.skillUnlockButton.disabled,true);
    assert.equal(f.ids.skillDetailActions.hidden,false);
};
tests["projected level points and lock reasons never inferred from client thresholds"]=()=>{
    const d=data(),f=fixture(d,d.states.level1);f.tabs[1].click();f.controller.select(d.keys[0]);
    assert.equal(f.ids.skillPoints.textContent,"Points 0 · 0/0 spent");assert.equal(f.ids.skillUnlockButton.disabled,true);assert.ok(f.ids.skillActionReason.textContent.includes("Required level"));
    f.controller.applyState(d.states.level2);assert.equal(f.ids.skillUnlockButton.disabled,false);assert.equal(f.ids.skillPoints.textContent,"Points 1 · 0/1 spent");
    f.controller.select(d.keys[3]);assert.equal(f.assignments.filter(n=>!n.hidden).length,1);assert.equal(f.assignments[3].hidden,false);
};
tests["AJAX learns assigns moves replaces unassigns and refreshes both loadout views"]=async()=>{
    const d=data();let index=0;const calls=[];
    const f=fixture(d,d.states.level5,async(url,options)=>{
        const op=d.operations[index++], body=JSON.parse(options.body);calls.push({url,body});
        assert.equal(options.method,"POST");assert.equal(body.csrf_token,"fixture");assert.equal(body.skill_key??null,op.key);assert.equal(body.slot??null,op.slot);
        assert.equal(Object.hasOwn(body,"points"),false);return {ok:true,json:async()=>({result:op.result,state:op.state})};
    });
    for(const op of d.operations){
        if(op.command==="unassign")f.controller.select(d.keys[0]);else f.controller.select(op.key);
        assert.equal(await f.controller.mutate(op.command,op.slot),true);
        assert.deepEqual(f.controller.state(),op.state);
    }
    assert.equal(calls.length,10);assert.equal(f.loadout.skill_1.textContent,"Empty");assert.equal(f.loadout.skill_2.textContent,"Empty");
    assert.equal(f.controller.state().points.spent,4);assert.equal(f.controller.state().points.available,0);
};
tests["normal ultimate compatibility and active victory-loot projected guards prevent calls"]=async()=>{
    const d=data();let calls=0;const f=fixture(d,d.operations[3].state,async()=>{calls++;throw Error();});
    f.controller.select(d.keys[0]);assert.equal(await f.controller.mutate("assign","ultimate"),false);
    f.controller.select(d.keys[3]);assert.equal(await f.controller.mutate("assign","skill_1"),false);
    for(const state of [d.states.active,d.states.victory_loot]){
        f.controller.applyState(state);f.controller.select(d.keys[0]);assert.equal(await f.controller.mutate("assign","skill_1"),false);assert.ok(f.ids.skillActionReason.textContent.includes("combat"));
    }
    assert.equal(calls,0);
};
tests["network retry reuses token and pending controls cannot double-send"]=async()=>{
    const d=data(),calls=[];let release,first=true;
    const f=fixture(d,d.states.level5,async(url,options)=>{calls.push(JSON.parse(options.body));if(first){first=false;await new Promise(r=>release=r);throw Error("network");}return {ok:true,json:async()=>({state:d.operations[0].state})};});
    f.controller.select(d.keys[0]);const waiting=f.controller.mutate("unlock");assert.equal(f.controller.pending(),true);
    assert.equal(await f.controller.mutate("unlock"),false);release();assert.equal(await waiting,false);
    assert.equal(await f.controller.mutate("unlock"),true);assert.equal(calls.length,2);assert.equal(calls[0].request_token,calls[1].request_token);assert.equal(f.ids.skillUnlockButton.disabled,true);
};
tests["Items restore Item Details Passive stays placeholder tab switches make no requests"]=()=>{
    const d=data();let calls=0;const f=fixture(d,d.states.level5,()=>{calls++;throw Error();});
    f.details.setItem({display_name:"Retained sword",rarity:"normal",base_type:"sword",item_level:1,base_stats:{},stat_lines:[]});
    f.tabs[1].click();f.controller.select(d.keys[0]);assert.equal(f.ids.itemDetailsTitle.textContent,"Skill Details");
    f.tabs[0].click();assert.equal(f.ids.itemDetailsTitle.textContent,"Item Details");assert.ok(f.ids.inventoryDetailName.textContent.includes("Retained sword"));assert.equal(f.ids.skillDetailActions.hidden,true);
    f.tabs[2].click();assert.equal(f.ids.itemDetailsTitle.textContent,"Passive Details");assert.equal(f.ids.inventoryDetailsEmpty.textContent,"Select a passive skill to view its details.");
    f.tabs[1].click();assert.equal(f.ids.contextDetailName.textContent,d.states.level5.skills[0].name);assert.equal(calls,0);
};
tests["shared secure UUID helper works without randomUUID on LAN HTTP and refuses weak fallbacks"]=()=>{
    const token=itemHud.createRequestToken({getRandomValues(bytes){bytes.fill(0);return bytes;}});
    assert.equal(token,"00000000-0000-4000-8000-000000000000");
    assert.match(token,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.throws(()=>itemHud.createRequestToken({}));
};
tests["explicit refresh GET reconciles authority without a tab request or mutation"]=async()=>{
    const d=data(),calls=[];const f=fixture(d,d.states.level1,async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>d.states.level2};});
    f.controller.select(d.keys[0]);assert.equal(f.ids.skillUnlockButton.disabled,true);
    assert.equal(await f.controller.refresh(),true);assert.equal(f.ids.skillUnlockButton.disabled,false);
    assert.equal(calls.length,1);assert.equal(calls[0].url,"skill_state.php");assert.equal(calls[0].options.method,"GET");assert.equal(calls[0].options.body,undefined);
};
tests["markup-like skill names stay plain text and changed Champion state is rejected"]=()=>{
    const d=data();d.states.level5.skills[0].name="<img src=x>";const f=fixture(d);f.tabs[1].click();f.controller.select(d.keys[0]);assert.equal(f.ids.contextDetailName.textContent,"<img src=x>");
    const foreign=structuredClone(d.states.level5);foreign.champion.id=999;assert.throws(()=>f.controller.applyState(foreign));
};
tests["canonical combat controller sends exact assigned slot but never calls empty slot"]=async()=>{
    const calls=[];const state={status:"active",player_skills:[{slot:"skill_2",key:"mage_frost_bolt",available:true,requires_slot:true}]};
    const c=combatHud.createCombatController({initialState:state,csrfToken:"csrf",requestTokenFactory:()=>"token",fetchImplementation:async(url,opt)=>{calls.push(JSON.parse(opt.body));return {ok:true,json:async()=>state};}});
    await c.skill("skill_1");assert.equal(calls.length,0);await c.skill("skill_2");assert.equal(calls[0].skill_slot,"skill_2");assert.equal(calls[0].action_key,"mage_frost_bolt");
};
(async()=>{let passed=0;for(const [name,test] of Object.entries(tests)){await test();console.log("[PASS] "+name);passed++;}console.log(`\n${passed} passed\n0 failed`);})().catch(e=>{console.error(e);process.exitCode=1;});
