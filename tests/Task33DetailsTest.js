"use strict";
const assert = require("node:assert/strict");
const hud = require("../ascii-quest/js/item_hud.js");
function element() {
    const listeners = {};
    return {
        children: [], dataset: {}, hidden: false, disabled: false,
        classList: { add() {}, remove() {} },
        setAttribute() {},
        addEventListener(name, listener) { (listeners[name] ||= []).push(listener); },
        click() { for (const listener of listeners.click || []) listener({currentTarget:this}); },
        replaceChildren(...children) { this.children = children; this.value = ""; },
        get textContent() { return this.children.length ? this.children.map(node => node.textContent).join("") : this.value || ""; },
        set textContent(value) { this.children = []; this.value = value; },
    };
}
function fixture() {
    const ids = Object.fromEntries(["itemDetailsTitle", "inventoryDetailsEmpty", "inventoryDetails", "inventoryDetailName", "inventoryDetailMeta", "inventoryDetailStats", "contextDetails", "contextDetailName", "contextDetailDescription", "contextDetailStats", "equipmentAction"].map(id => [id, element()]));
    const cell = element(), slot = element(); slot.dataset.equipmentSlot = "weapon";
    const tabs = ["item", "skill", "passive"].map(mode => { const node = element(); node.dataset.detailsMode = mode; return node; });
    const document = {
        getElementById: id => ids[id] || null,
        createElement: element,
        querySelectorAll: selector => ({"[data-inventory-slot]":[cell], "[data-equipment-slot]":[slot], "[data-details-mode]":tabs}[selector] || []),
    };
    const item = {id:1, display_name:"Fine Mace of Accuracy", rarity:"magic", item_level:4, base_type:"mace", equipment_slot:"weapon", base_stats:{damage_min:5,damage_max:7,damage_type:"physical"}, stat_lines:["+5 Accuracy"]};
    const state = {items:[item],equipment:{},pagination:{}};
    const snapshot = JSON.stringify(state);
    const calls = [];
    const controller = hud.createController({document,initialState:state,fetchImplementation:(...args)=>{calls.push(args); throw Error("unexpected network");}});
    return {document,ids,cell,slot,tabs,item,state,snapshot,calls,controller};
}
const tests = {
    "initial item mode uses the normal placeholder and one item renderer"() {
        const {controller,ids} = fixture();
        assert.equal(controller.detailsMode(), "item");
        assert.equal(ids.itemDetailsTitle.textContent, "Item Details");
        assert.equal(ids.inventoryDetailsEmpty.textContent, "Select an inventory or equipped item.");
        assert.equal(ids.inventoryDetails.hidden, true);
        assert.equal(ids.contextDetails.hidden, true);
    },
    "all top tabs drive exact titles/placeholders without server calls or state mutations"() {
        const f = fixture();
        for (const [index,title,text] of [[1,"Skill Details","Select a skill to view its details."],[2,"Passive Details","Select a passive skill to view its details."],[0,"Item Details","Select an inventory or equipped item."]]) {
            f.tabs[index].click();
            assert.equal(f.ids.itemDetailsTitle.textContent,title);
            assert.equal(f.ids.inventoryDetailsEmpty.textContent,text);
            assert.equal(f.ids.inventoryDetails.hidden,true);
            assert.equal(f.ids.contextDetails.hidden,true);
        }
        assert.deepEqual(f.calls,[]);
        assert.equal(JSON.stringify(f.state),f.snapshot);
    },
    "return to Items retains inventory selection and original rarity/level/type/stats/icons"() {
        const f = fixture(); f.cell.click();
        for (const tab of [f.tabs[1],f.tabs[2],f.tabs[0]]) tab.click();
        assert.equal(f.controller.selectedItemId(),1);
        assert.equal(f.ids.inventoryDetails.hidden,false);
        assert.equal(f.ids.inventoryDetailName.textContent,"𓉒 Fine Mace of Accuracy");
        assert.equal(f.ids.inventoryDetailMeta.textContent,"Magic · Item level 4 · Mace");
        assert.equal(f.ids.inventoryDetailStats.textContent,"Physical damage 5–7 · +5 Accuracy");
        assert.deepEqual(f.calls,[]);
    },
    "return to Items safely resolves current equipped selection"() {
        const f = fixture();
        f.controller.applyState({...f.state,items:[],equipment:{weapon:{...f.item,equipped:true,equipped_slot:"weapon"}}});
        f.slot.click(); f.tabs[2].click(); f.tabs[0].click();
        assert.equal(f.controller.selectedItemId(),1);
        assert.equal(f.ids.inventoryDetails.hidden,false);
        assert.equal(f.ids.equipmentAction.textContent,"Unequip");
        assert.deepEqual(f.calls,[]);
    },
    "state refresh while in another mode keeps its placeholder and uses fresh item data on return"() {
        const f = fixture(); f.cell.click(); f.tabs[1].click();
        f.controller.applyState({...f.state,items:[{...f.item,display_name:"Updated Mace"}]});
        assert.equal(f.ids.inventoryDetails.hidden,true);
        assert.equal(f.ids.inventoryDetailsEmpty.textContent,"Select a skill to view its details.");
        f.tabs[0].click();
        assert.equal(f.ids.inventoryDetailName.textContent,"𓉒 Updated Mace");
    },
    "removed selection cannot return stale item details"() {
        const f = fixture(); f.cell.click(); f.tabs[2].click();
        f.controller.applyState({items:[],equipment:{},pagination:{}});
        assert.equal(f.ids.inventoryDetailsEmpty.textContent,"Select a passive skill to view its details.");
        f.tabs[0].click();
        assert.equal(f.controller.selectedItemId(),null);
        assert.equal(f.ids.inventoryDetails.hidden,true);
        assert.equal(f.ids.inventoryDetailsEmpty.hidden,false);
        assert.equal(f.ids.inventoryDetailsEmpty.textContent,"Select an inventory or equipped item.");
    },
    "skill/passive public hooks render text-only selected details, retain independently and clear"() {
        const f = fixture();
        const skill = Object.freeze({name:"<b>Skill</b>",description:"<img src=x>",stat_lines:Object.freeze(["Example text"])});
        f.controller.setSkillDetails(skill);
        assert.equal(f.controller.detailsMode(),"item", "hook stores data without switching the active tab");
        f.tabs[1].click();
        assert.equal(f.ids.inventoryDetails.hidden,true);
        assert.equal(f.ids.inventoryDetailsEmpty.hidden,true);
        assert.equal(f.ids.contextDetails.hidden,false);
        assert.equal(f.ids.contextDetailName.textContent,skill.name);
        assert.equal(f.ids.contextDetailDescription.textContent,skill.description);
        assert.equal(f.ids.contextDetailStats.textContent,"Example text");
        f.controller.setPassiveDetails({name:"Passive example",description:"Presentation only"});
        f.tabs[2].click();
        assert.equal(f.ids.contextDetailName.textContent,"Passive example");
        f.tabs[1].click(); assert.equal(f.ids.contextDetailName.textContent,skill.name);
        f.controller.setSkillDetails(null);
        assert.equal(f.ids.contextDetails.hidden,true);
        assert.equal(f.ids.inventoryDetailsEmpty.textContent,"Select a skill to view its details.");
        f.tabs[2].click(); f.controller.setPassiveDetails(null);
        assert.equal(f.ids.inventoryDetailsEmpty.textContent,"Select a passive skill to view its details.");
        assert.deepEqual(f.calls,[]);
        assert.equal(JSON.stringify(f.state),f.snapshot);
    },
    "inventory updates cannot overwrite a selected skill or passive context"() {
        const f = fixture(); f.cell.click();
        for (const [index, setter, name] of [[1,"setSkillDetails","Skill selection"],[2,"setPassiveDetails","Passive selection"]]) {
            f.tabs[index].click(); f.controller[setter]({name});
            f.controller.applyState({...f.state,items:[{...f.item,display_name:"Fresh item"}]});
            assert.equal(f.ids.contextDetailName.textContent,name);
            assert.equal(f.ids.contextDetails.hidden,false);
            assert.equal(f.ids.inventoryDetails.hidden,true);
        }
        f.tabs[0].click();
        assert.equal(f.ids.inventoryDetailName.textContent,"𓉒 Fresh item");
        assert.deepEqual(f.calls,[]);
    },
    "invalid or cleared future selections show placeholders instead of stale text"() {
        const f = fixture();
        for (const [index,setter] of [[1,"setSkillDetails"],[2,"setPassiveDetails"]]) {
            f.tabs[index].click(); f.controller[setter]({name:"Example"});
            for (const invalid of [null,undefined,{}, {name:"  "},{name:42}]) {
                f.controller[setter](invalid);
                assert.equal(f.ids.contextDetails.hidden,true);
                assert.equal(f.ids.inventoryDetailsEmpty.hidden,false);
            }
        }
        assert.deepEqual(f.calls,[]);
    },
    "standalone public details controller is presentation-only and copies input lines"() {
        const f = fixture();
        const c = hud.createDetailsController(f.document);
        const skill = {name:"Example",stat_lines:["Original"]};
        c.setSkillDetails(skill); skill.stat_lines.push("Later external edit");
        c.setDetailsMode("skill");
        assert.equal(f.ids.contextDetailStats.textContent,"Original");
        c.setDetailsMode("item"); c.setItem(f.item);
        assert.equal(f.ids.inventoryDetailName.textContent,"𓉒 Fine Mace of Accuracy");
        assert.deepEqual(f.calls,[]);
    },
    "public mode hook rejects invalid modes without disturbing selection"() {
        const f = fixture(); f.cell.click();
        assert.equal(f.controller.setDetailsMode("skill"),true);
        for (const mode of ["toString", "constructor", "invalid", null, ["item"]]) assert.equal(f.controller.setDetailsMode(mode),false);
        assert.equal(f.controller.detailsMode(),"skill");
        assert.equal(f.controller.selectedItemId(),1);
        assert.deepEqual(f.calls,[]);
    },
};
let passed=0,failed=0;
for (const [name,test] of Object.entries(tests)) {
    try { test(); passed++; console.log(`[PASS] ${name}`); }
    catch (error) { failed++; console.error(`[FAIL] ${name}: ${error.message}`); }
}
console.log(`\n${passed} passed\n${failed} failed`);
process.exitCode = failed ? 1 : 0;
