(function (root, factory) {
    "use strict";
    const hud = factory();
    if (typeof module === "object" && module.exports) module.exports = hud;
    if (!root) return;
    root.ASCIIQuestPassiveHud = hud;
    function initialize() {
        if (!root.ASCII_QUEST_STATE?.passives || !root.ASCIIQuestInventoryController) return;
        root.ASCIIQuestPassivesController = hud.createController({
            document: root.document, initialState: root.ASCII_QUEST_STATE.passives,
            details: root.ASCIIQuestInventoryController, csrfToken: root.ASCII_QUEST_STATE.csrfToken,
            updateChampion: champion => root.ASCIIQuestItemHud.updateChampion(root.document, champion),
            fetchImplementation: typeof root.fetch === "function" ? root.fetch.bind(root) : null,
            uuidFactory: () => root.ASCIIQuestItemHud.createRequestToken(root.crypto),
        });
    }
    if (root.document.readyState === "loading") root.document.addEventListener("DOMContentLoaded", initialize);
    else initialize();
})(typeof window === "undefined" ? null : window, function () {
    "use strict";
    const REASONS = { already_unlocked:"Already unlocked", dead:"Champion is dead", combat_locked:"Allocation locked during combat and victory loot", inactive:"Passive is inactive", tree_complete:"Two passes complete", row_required:"Complete the current row first", no_points:"No Passive Points available" };
    const LABELS = { strength:"Strength", energy:"Energy", dexterity:"Dexterity", vitality:"Vitality", maximum_life:"Maximum Life", maximum_mana:"Maximum Mana", accuracy:"Accuracy", physical_damage:"Physical Damage", fire_damage:"Fire Damage", cold_damage:"Cold Damage", lightning_damage:"Lightning Damage", critical_chance:"Critical Chance", critical_damage:"Critical Damage", block_rate_bp:"Block Rate", toughness:"Toughness", dodging:"Dodging", fire_resistance:"Fire Resistance", cold_resistance:"Cold Resistance", lightning_resistance:"Lightning Resistance", poison_damage:"Poison Damage", bleed_damage:"Bleed Damage", life_on_hit:"Life on Hit", healing_power_percent:"Healing Power", cast_rate_bp:"Cast / Action Rate", elemental_status_chance_percent:"Elemental Status Chance" };
    const PERCENT = new Set(["critical_chance","critical_damage","dodging","fire_resistance","cold_resistance","lightning_resistance","healing_power_percent","elemental_status_chance_percent"]);
    function reasonText(reason) { return reason ? REASONS[reason] || "Passive unavailable" : "Available"; }
    function effectsFor(node) {
        return Object.entries(node.effects).map(([key,value]) => (LABELS[key] || key) + " +" + (key.endsWith("_bp") ? value/100 + "%" : value + (PERCENT.has(key) ? "%" : "")));
    }
    function detailsFor(node, progress) {
        return { name:node.name, description:node.description, stat_lines:[
            "Row " + node.row + " · Lane " + node.lane.toUpperCase(),
            ...effectsFor(node), node.major ? "MAJOR PASSIVE" : "Normal passive",
            "State: " + (node.unlocked_pass ? "UNLOCKED_PASS" + node.unlocked_pass : node.state.toUpperCase()),
            node.unlocked_pass ? "Selected in Pass " + node.unlocked_pass : progress.complete ? "Tree complete" : "Current Pass " + progress.current_pass + " · Next Row " + progress.next_row,
            "Unlock: " + reasonText(node.reason),
        ] };
    }
    function createController(options) {
        const document=options.document;
        let state=options.initialState, selected=null, pending=false, retry=null, invalid=false;
        const node=id=>document.getElementById(id);
        const text=(id,value)=>{const element=node(id);if(element)element.textContent=String(value);};
        const nodes=()=>state.rows.flatMap(row=>row.nodes);
        function paint() {
            const refresh=node("passiveRefreshButton");if(refresh)refresh.disabled=pending||invalid;
            text("passivePoints","Points " + state.points.available + " · " + state.points.spent + "/" + state.points.earned + " spent");
            const p=state.progress;
            text("passiveProgress",p.complete ? "PASS 1 COMPLETE · PASS 2 COMPLETE · TREE COMPLETE" : (p.completed_passes ? "PASS 1 COMPLETE · " : "") + "PASS " + p.current_pass + " ACTIVE · ROW " + p.next_row);
            for (const button of document.querySelectorAll("[data-passive-key]")) {
                const passive=nodes().find(n=>n.key===button.dataset.passiveKey);if(!passive)continue;
                for (const s of ["locked","available","unlocked"]) button.classList.toggle("state-"+s,passive.state===s);
                for (const pass of [1,2]) button.classList.toggle("unlocked-pass-"+pass,passive.unlocked_pass===pass);
                button.classList.toggle("is-selected",selected===passive.key);
                button.setAttribute("aria-pressed",String(selected===passive.key));
                const status=passive.unlocked_pass ? "UNLOCKED_PASS"+passive.unlocked_pass : passive.state.toUpperCase();
                button.title=passive.name+" · "+status+" · "+reasonText(passive.reason);
                const label=button.querySelector("[data-passive-state]");if(label)label.textContent=status;
            }
            const passive=nodes().find(n=>n.key===selected);if(!passive)return;
            const actions=node("passiveDetailActions");if(actions)actions.dataset.passiveSelection="true";
            options.details.setPassiveDetails(detailsFor(passive,p));
            const unlock=node("passiveUnlockButton");
            if(unlock){unlock.disabled=pending||invalid||passive.state!=="available";unlock.textContent=passive.unlocked_pass?"Unlocked · Pass "+passive.unlocked_pass:"Unlock · 1 point";}
            text("passiveActionReason",reasonText(passive.reason));
        }
        function applyState(next) {
            const incoming=Array.isArray(next?.rows)?next.rows.flatMap(r=>Array.isArray(r.nodes)?r.nodes:[]):[];
            if(next?.champion?.id!==state.champion.id || !next.points || !next.progress || incoming.length!==80 || new Set(incoming.map(n=>n.key)).size!==80
                || incoming.some(n=>!nodes().some(old=>old.key===n.key) || !["locked","available","unlocked"].includes(n.state))) {
                invalid=true;paint();throw new Error("Passive session changed. Reload the game.");
            }
            state=next;paint();
        }
        function select(key) {
            if(!nodes().some(n=>n.key===key))return false;
            selected=key;paint();
            const scroll=node("contextDetailText");if(scroll)scroll.scrollTop=0;
            return true;
        }
        async function unlock() {
            const passive=nodes().find(n=>n.key===selected);
            if(!passive || pending || invalid || passive.state!=="available" || typeof options.fetchImplementation!=="function")return false;
            if(!retry || retry.key!==passive.key){
                try{retry={key:passive.key,token:options.uuidFactory()};}
                catch(_){text("passiveMessage","Secure request tokens unavailable. No passive change sent.");return false;}
            }
            pending=true;paint();text("passiveMessage","");
            try{
                const response=await options.fetchImplementation("unlock_passive.php",{method:"POST",headers:{Accept:"application/json","Content-Type":"application/json"},body:JSON.stringify({csrf_token:options.csrfToken,request_token:retry.token,passive_key:passive.key})});
                const result=await response.json();if(!response.ok || result?.success===false || !result.state)throw new Error("Unavailable");
                applyState(result.state);if(result.champion && typeof options.updateChampion==="function")options.updateChampion(result.champion);
                retry=null;text("passiveMessage","Passive unlocked.");return true;
            }catch(_){text("passiveMessage","Unable to unlock. Retry the same choice safely, or refresh.");return false;}
            finally{pending=false;paint();}
        }
        async function refresh() {
            if(pending||invalid||typeof options.fetchImplementation!=="function")return false;
            pending=true;paint();
            try{
                const response=await options.fetchImplementation("passive_state.php",{method:"GET",headers:{Accept:"application/json"}});
                const result=await response.json();if(!response.ok||result?.success===false)throw new Error("Unavailable");
                applyState(result);retry=null;text("passiveMessage","Passive state refreshed.");return true;
            }catch(_){text("passiveMessage","Unable to refresh passives. Check your session.");return false;}
            finally{pending=false;paint();}
        }
        for(const button of document.querySelectorAll("[data-passive-key]"))button.addEventListener("click",()=>select(button.dataset.passiveKey));
        node("passiveUnlockButton")?.addEventListener("click",unlock);
        node("passiveRefreshButton")?.addEventListener("click",refresh);
        paint();
        return {applyState,select,unlock,refresh,state:()=>state,selectedKey:()=>selected,pending:()=>pending};
    }
    return {createController,detailsFor,effectsFor,reasonText};
});
