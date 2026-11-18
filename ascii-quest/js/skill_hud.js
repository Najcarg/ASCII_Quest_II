(function (root, factory) {
    "use strict";
    const hud = factory();
    if (typeof module === "object" && module.exports) module.exports = hud;
    if (!root) return;
    root.ASCIIQuestSkillHud = hud;
    function initialize() {
        const state = root.ASCII_QUEST_STATE?.skills;
        if (!state || !root.ASCIIQuestInventoryController) return;
        root.ASCIIQuestSkillsController = hud.createController({
            document: root.document, initialState: state, details: root.ASCIIQuestInventoryController,
            csrfToken: root.ASCII_QUEST_STATE.csrfToken,
            fetchImplementation: typeof root.fetch === "function" ? root.fetch.bind(root) : null,
            uuidFactory: () => root.ASCIIQuestItemHud.createRequestToken(root.crypto),
        });
    }
    if (root.document.readyState === "loading") root.document.addEventListener("DOMContentLoaded", initialize);
    else initialize();
})(typeof window === "undefined" ? null : window, function () {
    "use strict";
    const SLOTS = ["skill_1","skill_2","skill_3","ultimate"];
    const LABELS = { skill_1:"Skill 1", skill_2:"Skill 2", skill_3:"Skill 3", ultimate:"Ultimate" };
    const REASONS = { already_unlocked:"Already learned", dead:"Champion is dead", wrong_class:"Wrong class", inactive:"Skill is inactive", level_required:"Required level not reached", prerequisite_required:"Learn the prerequisite first", no_points:"No skill points available", not_unlocked:"Learn this skill first", combat_locked:"Loadout locked during combat and victory loot", empty_slot:"Empty slot" };
    function reasonText(reason) { return reason ? REASONS[reason] || "Skill unavailable" : "Available"; }
    function detailsFor(skill) {
        const effect = skill.effect;
        return {
            name: skill.name, description: skill.description,
            stat_lines: [
                skill.ultimate ? "Ultimate skill" : "Normal skill",
                "Damage: " + skill.damage + " " + skill.type,
                effect ? "Effect: " + effect.key + " " + effect.power + " · " + effect.chance_percent + "% chance · " + effect.duration_ms/1000 + "s" : "Effect: none",
                "Cooldown: " + skill.cooldown_ms/1000 + "s", "Base action duration: " + skill.duration_ms/1000 + "s",
                "Required level: " + skill.minimum_level,
                "Prerequisite: " + (skill.prerequisites.length ? skill.prerequisites.map(p => p.name).join(", ") : "None"),
                "Status: " + skill.state.toUpperCase(), "Slot: " + (LABELS[skill.assigned_slot] || "Unassigned"),
                "Unlock: " + reasonText(skill.reason),
            ],
        };
    }
    function createController(options) {
        const document = options.document;
        let state = options.initialState;
        let selected = null;
        let pending = false;
        let retry = null;
        let invalid = false;
        const node = id => document.getElementById(id);
        const text = (id,value) => { const n=node(id); if (n) n.textContent=String(value); };
        function paint() {
            const refresh=node("skillRefreshButton");if(refresh)refresh.disabled=pending||invalid;
            text("skillPoints", "Points " + state.points.available + " · " + state.points.spent + "/" + state.points.earned + " spent");
            for (const button of document.querySelectorAll("[data-skill-key]")) {
                const skill=state.skills.find(s=>s.key===button.dataset.skillKey);
                if (!skill) continue;
                for (const value of ["locked","available","unlocked"]) button.classList.toggle("state-"+value,skill.state===value);
                button.classList.toggle("is-selected",selected===skill.key);
                button.classList.toggle("is-assigned",skill.assigned_slot!==null);
                button.setAttribute("aria-pressed",String(selected===skill.key));
                button.dataset.prerequisites=skill.prerequisites.map(p=>p.key).join(" ");
                button.title=skill.name+" · "+skill.state.toUpperCase()+" · "+reasonText(skill.reason);
                const name=button.querySelector("[data-skill-name]");if(name)name.textContent=skill.name;
                const status=button.querySelector("[data-skill-state]"); if (status) status.textContent=skill.state.toUpperCase();
                const assigned=button.querySelector("[data-skill-assigned]"); if (assigned) assigned.textContent=skill.assigned_slot?"ASSIGNED":"";
            }
            for (const slot of SLOTS) {
                const name=state.loadout[slot]?.name || "Empty";
                for (const el of document.querySelectorAll('[data-skill-loadout="'+slot+'"]')) el.textContent=name;
                for (const el of document.querySelectorAll('[data-loadout-slot="'+slot+'"] strong')) el.textContent=name;
            }
            const skill=state.skills.find(s=>s.key===selected);
            if (!skill) return;
            const actions=node("skillDetailActions");
            if (actions) actions.dataset.skillSelection="true";
            options.details.setSkillDetails(detailsFor(skill));
            const unlock=node("skillUnlockButton");
            if (unlock) { unlock.disabled=pending || invalid || skill.state!=="available"; unlock.textContent=skill.state==="unlocked"?"Learned":"Unlock · 1 point"; }
            text("skillActionReason",reasonText(skill.reason)+(skill.assignment_reason?" · Assignment: "+reasonText(skill.assignment_reason):""));
            for (const button of document.querySelectorAll("[data-skill-assign]")) {
                const compatible=(button.dataset.skillAssign==="ultimate")===skill.ultimate;
                button.hidden=!compatible;
                button.disabled=pending || invalid || !compatible || skill.assignment_reason!==null;
                button.title=reasonText(skill.assignment_reason);
            }
            const unassign=node("skillUnassignButton");
            if (unassign) { unassign.disabled=pending || invalid || skill.assigned_slot===null || state.loadout_locked || skill.assignment_reason!==null; }
        }
        function applyState(next) {
            if (!next || !Array.isArray(next.skills) || next.skills.length!==4 || !next.points || !next.loadout
                || next.champion?.id!==state.champion.id || next.champion?.class_key!==state.champion.class_key) {
                invalid=true;paint();
                throw new Error("Skill session changed. Reload the game.");
            }
            state=next; paint();
        }
        function select(key) {
            if (!state.skills.some(s=>s.key===key)) return false;
            selected=key; paint();
            const context=node("contextDetails"); if (context) context.scrollTop=0;
            const detailText=node("contextDetailText"); if (detailText) detailText.scrollTop=0;
            return true;
        }
        async function mutate(command,slot=null) {
            const skill=state.skills.find(s=>s.key===selected);
            if (!skill || pending || invalid || typeof options.fetchImplementation!=="function") return false;
            if (command==="unlock" && skill.state!=="available") return false;
            if (command!=="unlock" && (state.loadout_locked || skill.assignment_reason!==null)) return false;
            if (command==="assign" && (!SLOTS.includes(slot) || ((slot==="ultimate")!==skill.ultimate))) return false;
            if (command==="unassign" && skill.assigned_slot===null) return false;
            const target=command==="unassign"?skill.assigned_slot:slot;
            const fingerprint=JSON.stringify([command,skill.key,target]);
            if (!retry || retry.fingerprint!==fingerprint) {
                try { retry={ fingerprint, token:options.uuidFactory() }; }
                catch (_) { text("skillMessage","Secure request tokens unavailable. No skill change sent.");return false; }
            }
            const payload={csrf_token:options.csrfToken,request_token:retry.token};
            if (command!=="unassign") payload.skill_key=skill.key;
            if (command!=="unlock") payload.slot=target;
            const urls={unlock:"unlock_skill.php",assign:"assign_skill.php",unassign:"unassign_skill.php"};
            if (!urls[command]) return false;
            pending=true; text("skillMessage",""); paint();
            try {
                const response=await options.fetchImplementation(urls[command],{method:"POST",headers:{Accept:"application/json","Content-Type":"application/json"},body:JSON.stringify(payload)});
                const result=await response.json();
                if (!response.ok || result?.success===false || !result.state) throw new Error("Skill change unavailable. Refresh and try again.");
                applyState(result.state); retry=null;
                text("skillMessage",command==="unlock"?"Skill learned.":"Loadout saved.");
                return true;
            } catch (_) { text("skillMessage","Unable to change skills. Retry the same action safely."); return false; }
            finally { pending=false; paint(); }
        }
        async function refresh() {
            if (pending || invalid || typeof options.fetchImplementation!=="function") return false;
            pending=true;paint();
            try {
                const response=await options.fetchImplementation("skill_state.php",{method:"GET",headers:{Accept:"application/json"}});
                const result=await response.json();
                if (!response.ok || result?.success===false) throw new Error("Unavailable");
                applyState(result);retry=null;text("skillMessage","Skill state refreshed.");return true;
            } catch (_) { text("skillMessage","Unable to refresh skills. Check the session and try again.");return false; }
            finally { pending=false;paint(); }
        }
        node("skillRefreshButton")?.addEventListener("click",refresh);
        for (const button of document.querySelectorAll("[data-skill-key]")) button.addEventListener("click",()=>select(button.dataset.skillKey));
        node("skillUnlockButton")?.addEventListener("click",()=>mutate("unlock"));
        for (const button of document.querySelectorAll("[data-skill-assign]")) button.addEventListener("click",()=>mutate("assign",button.dataset.skillAssign));
        node("skillUnassignButton")?.addEventListener("click",()=>mutate("unassign"));
        paint();
        return { applyState,select,mutate,refresh,state:()=>state,selectedKey:()=>selected,pending:()=>pending };
    }
    return { createController,detailsFor,reasonText };
});
