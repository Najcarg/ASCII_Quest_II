"use strict";
const assert=require("node:assert/strict"),{execFileSync}=require("node:child_process"),path=require("node:path");
const hud=require("../ascii-quest/js/passive_hud.js"),items=require("../ascii-quest/js/item_hud.js");
const sample=JSON.parse(execFileSync("php",[path.join(__dirname,"fixtures/task35_states.php")],{encoding:"utf8"}));
function element(){const listeners={},classes=new Set();let text="";return{dataset:{},hidden:false,disabled:false,children:[],classList:{toggle(k,on){on?classes.add(k):classes.delete(k);},contains:k=>classes.has(k)},setAttribute(){},addEventListener(k,fn){(listeners[k]||=[]).push(fn);},click(){if(!this.disabled)return Promise.all((listeners.click||[]).map(fn=>fn()));},querySelector(){return null;},replaceChildren(...v){this.children=v;text="";},get textContent(){return this.children.length?this.children.map(n=>n.textContent).join(""):text;},set textContent(v){this.children=[];text=String(v);}};}
function fixture(initial=sample.states.level90_spent0,fetchImplementation=null,uuidFactory=null,updateChampion=null){
 const ids=Object.fromEntries(["passivePoints","passiveProgress","passiveMessage","passiveRefreshButton","passiveUnlockButton","passiveActionReason","passiveDetailActions","skillDetailActions","contextDetails","contextDetailText","contextDetailName","contextDetailDescription","contextDetailStats","itemDetailsTitle","inventoryDetailsEmpty","inventoryDetails","inventoryDetailName","inventoryDetailMeta","inventoryDetailStats"].map(k=>[k,element()]));
 const tabs=["item","skill","passive"].map(k=>{const el=element();el.dataset.detailsMode=k;return el;});
 const buttons=initial.rows.flatMap(r=>r.nodes).map(n=>{const el=element(),status=element();el.dataset.passiveKey=n.key;el.querySelector=()=>status;return el;});
 const document={getElementById:k=>ids[k]||null,createElement:element,querySelectorAll(q){return q==="[data-details-mode]"?tabs:q==="[data-passive-key]"?buttons:[];}};
 const details=items.createDetailsController(document);let uuid=0;
 const controller=hud.createController({document,details,initialState:initial,csrfToken:"csrf",uuidFactory:uuidFactory||(()=>"fixture-"+(++uuid)),fetchImplementation,updateChampion});
 return{ids,tabs,buttons,controller,details};
}
const tests={};
for(const [key,available,row,pass] of [["level50_spent0",0,1,1],["level51_spent0",1,1,1],["level55_spent5",0,6,1],["level70_spent20",0,1,2],["level71_spent20",1,1,2],["level90_spent40",0,null,null]])tests[key+" renders authoritative points row and pass"]=()=>{
 const state=sample.states[key],f=fixture(state);assert.equal(f.buttons.length,80);assert.equal(f.controller.state().points.available,available);assert.equal(state.progress.next_row,row);assert.equal(state.progress.current_pass,pass);
 f.tabs[2].click();const candidate=state.rows[(row||1)-1].nodes.find(n=>n.state==="available")||state.rows[0].nodes[0];f.controller.select(candidate.key);
 assert.equal(f.ids.passiveUnlockButton.disabled,candidate.state!=="available");assert.equal(f.ids.itemDetailsTitle.textContent,"Passive Details");
 if(pass===2)assert.ok(f.ids.passiveProgress.textContent.includes("PASS 1 COMPLETE"));if(pass===null)assert.ok(f.ids.passiveProgress.textContent.includes("TREE COMPLETE"));
};
tests["all40 AJAX operations use only server projections and strict intent"]=async()=>{
 const calls=[];let index=0;const f=fixture(sample.states.level90_spent0,async(url,opt)=>{const op=sample.operations[index++],body=JSON.parse(opt.body);assert.deepEqual(Object.keys(body).sort(),["csrf_token","passive_key","request_token"]);assert.equal(body.passive_key,op.key);assert.equal(opt.method,"POST");assert.equal(url,"unlock_passive.php");calls.push(body);return{ok:true,json:async()=>({result:op.result,state:op.state})};});
 for(const op of sample.operations){f.controller.select(op.key);assert.equal(await f.controller.unlock(),true);assert.deepEqual(f.controller.state(),op.state);}
 assert.equal(calls.length,40);assert.equal(new Set(calls.map(c=>c.request_token)).size,40);assert.equal(f.controller.state().progress.complete,true);
 assert.equal(f.buttons.filter(b=>b.classList.contains("unlocked-pass-1")).length,20);assert.equal(f.buttons.filter(b=>b.classList.contains("unlocked-pass-2")).length,20);
};
tests["retry preserves durable token pending prevents double send"]=async()=>{
 let first=true,release;const calls=[];const f=fixture(sample.states.level90_spent0,async(url,opt)=>{calls.push(JSON.parse(opt.body));if(first){first=false;await new Promise(r=>release=r);throw Error("lost response");}return{ok:true,json:async()=>({state:sample.operations[0].state})};});
 f.controller.select(sample.operations[0].key);const request=f.controller.unlock();assert.equal(f.controller.pending(),true);assert.equal(await f.controller.unlock(),false);release();assert.equal(await request,false);assert.equal(await f.controller.unlock(),true);assert.equal(calls[0].request_token,calls[1].request_token);
};
tests["node selection Details all effects major pass reason and tab retention"]=()=>{
 const f=fixture();f.tabs[2].click();f.controller.select("passive_r20_d_divine_vitality");const text=f.ids.contextDetailStats.textContent;
 for(const part of ["Row 20","Lane D","Maximum Life +200","Healing Power +25%","MAJOR PASSIVE","LOCKED","Current Pass 1"])assert.ok(text.includes(part),part);
 assert.equal(f.ids.passiveDetailActions.hidden,false);f.details.setSkillDetails({name:"Skill choice",stat_lines:[]});f.tabs[1].click();assert.equal(f.ids.passiveDetailActions.hidden,true);assert.equal(f.ids.contextDetailName.textContent,"Skill choice");f.tabs[2].click();assert.equal(f.ids.contextDetailName.textContent,"Divine Vitality");assert.equal(f.ids.passiveDetailActions.hidden,false);
};
tests["refresh uses GET no body tabs never issue requests"]=async()=>{
 const calls=[];const f=fixture(sample.states.level50_spent0,async(url,opt)=>{calls.push({url,opt});return{ok:true,json:async()=>sample.states.level51_spent0};});
 f.controller.select(sample.operations[0].key);for(const tab of f.tabs)tab.click();assert.equal(calls.length,0);assert.equal(await f.controller.refresh(),true);assert.equal(calls[0].url,"passive_state.php");assert.equal(calls[0].opt.body,undefined);assert.equal(f.ids.passiveUnlockButton.disabled,false);
};
tests["unavailable choice no call secure UUID failure no call"]=async()=>{
 let calls=0;const f=fixture(sample.states.level50_spent0,async()=>{calls++;});f.controller.select(sample.operations[0].key);assert.equal(await f.controller.unlock(),false);
 const insecure=fixture(sample.states.level51_spent0,async()=>{calls++;},()=>{throw Error();});insecure.controller.select(sample.operations[0].key);assert.equal(await insecure.controller.unlock(),false);assert.equal(calls,0);
};
tests["successful unlock forwards server CharacterStats without client stat calculation"]=async()=>{
 const updates=[],champion={current_hp:100,current_mana:50,stats:{resources:{max_life:180,max_mana:175}}};
 const f=fixture(sample.states.level90_spent0,async()=>({ok:true,json:async()=>({state:sample.operations[0].state,champion})}),null,value=>updates.push(value));
 f.controller.select(sample.operations[0].key);assert.equal(await f.controller.unlock(),true);assert.deepEqual(updates,[champion]);
};
tests["foreign or malformed state fails closed and text stays escaped"]=async()=>{
 const f=fixture();const bad=structuredClone(sample.states.level90_spent0);bad.champion.id=999;assert.throws(()=>f.controller.applyState(bad));f.controller.select(sample.operations[0].key);assert.equal(await f.controller.unlock(),false);
 const markup=structuredClone(sample.states.level90_spent0);markup.rows[0].nodes[0].name="<img src=x>";const g=fixture(markup);g.tabs[2].click();g.controller.select(sample.operations[0].key);assert.equal(g.ids.contextDetailName.textContent,"<img src=x>");
};
(async()=>{let passed=0;for(const[name,test]of Object.entries(tests)){await test();console.log("[PASS] Task35 "+name);passed++;}console.log(passed+" passed\n0 failed");})().catch(e=>{console.error(e);process.exitCode=1;});
