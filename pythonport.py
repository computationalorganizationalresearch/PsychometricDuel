from __future__ import annotations

from dataclasses import dataclass, replace
from typing import Dict, List, Optional, Tuple, Any
import json
import copy

# Mirrors index.html constants
MAX_HAND_SIZE = 12
STARTING_HAND_SIZE = 12
EXPERIENCE_MISS_THRESHOLD = 4
EXPERIENCE_DRAW_COUNT = 3

CONSTRUCTS: Dict[str, Dict[str, Any]] = {
    "cog_ability": {"name": "Cognitive Ability", "type": "predictor", "short": "COG", "avgR": 0.65},
    "conscient": {"name": "Conscientiousness", "type": "predictor", "short": "CON", "avgR": 0.45},
    "struct_int": {"name": "Struct. Interview", "type": "predictor", "short": "INT", "avgR": 0.55},
    "work_sample": {"name": "Work Sample", "type": "predictor", "short": "WST", "avgR": 0.50},
    "job_perf": {"name": "Job Performance", "type": "outcome", "short": "PERF", "avgR": 0.52},
    "turnover": {"name": "Turnover", "type": "outcome", "short": "TURN", "avgR": 0.40},
    "job_sat": {"name": "Job Satisfaction", "type": "outcome", "short": "SAT", "avgR": 0.48},
    "ocb": {"name": "OCB", "type": "outcome", "short": "OCB", "avgR": 0.44},
}

TRUE_VALIDITY = {
    "cog_ability": {"job_perf": 0.51, "turnover": 0.20, "job_sat": 0.15, "ocb": 0.12},
    "conscient": {"job_perf": 0.31, "turnover": 0.26, "job_sat": 0.25, "ocb": 0.30},
    "struct_int": {"job_perf": 0.51, "turnover": 0.22, "job_sat": 0.18, "ocb": 0.15},
    "work_sample": {"job_perf": 0.54, "turnover": 0.15, "job_sat": 0.12, "ocb": 0.10},
}

ADVERSE_IMPACT_BWD = {
    "cog_ability": {"job_perf": 0.95, "turnover": 0.60, "job_sat": 0.58, "ocb": 0.55},
    "conscient": {"job_perf": 0.20, "turnover": 0.05, "job_sat": 0.05, "ocb": 0.05},
    "struct_int": {"job_perf": 0.35, "turnover": 0.22, "job_sat": 0.22, "ocb": 0.22},
    "work_sample": {"job_perf": 0.55, "turnover": 0.40, "job_sat": 0.40, "ocb": 0.40},
}

COUNTS = {
    "cog_ability": 4, "conscient": 4, "struct_int": 4, "work_sample": 4,
    "job_perf": 4, "turnover": 4, "job_sat": 4, "ocb": 4,
    "sample_size": 3, "job_relevance": 4, "imputation": 1, "missing_data": 1,
    "range_restrict": 2, "item_leakage": 2, "correction": 2, "p_hacking": 1,
    "practice_effect": 2, "bootstrapping": 2, "item_analysis": 2,
    "construct_drift": 1, "criterion_contam": 1,
}

TARGETING_MONSTER_SPELLS = {
    "sample_size", "job_relevance", "imputation", "p_hacking", "practice_effect",
    "range_restrict", "item_leakage", "correction", "bootstrapping", "criterion_contam"
}

TARGETING_CONSTRUCT_SPELLS = {"missing_data", "construct_drift", "item_analysis"}


@dataclass(frozen=True)
class GameState:
    state: Dict[str, Any]

    def state_key(self) -> str:
        return json.dumps(self.state, sort_keys=True, separators=(",", ":"))


def _clone(state: GameState | Dict[str, Any]) -> Dict[str, Any]:
    return copy.deepcopy(state.state if isinstance(state, GameState) else state)


def clamp(v: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, v))


def spearmanBrown(k: int, avgR: float) -> float:
    return (k * avgR) / (1 + (k - 1) * avgR)


def adverseImpactStarsFromBwd(rawBwd: float) -> int:
    d = abs(rawBwd or 0)
    if d <= 0.10:
        return 5
    if d <= 0.25:
        return 4
    if d <= 0.45:
        return 3
    if d <= 0.65:
        return 2
    return 1


def getPairAdverseImpact(pred_id: str, out_id: str) -> Dict[str, Any]:
    bwd = ADVERSE_IMPACT_BWD.get(pred_id, {}).get(out_id, 0.3)
    stars = adverseImpactStarsFromBwd(bwd)
    return {
        "bwd": bwd,
        "stars": stars,
        "requiresJobRelevance": stars <= 3,
        "starsText": "★" * stars + "☆" * (5 - stars),
    }


def alphaFromStack(stack: Optional[Dict[str, Any]]) -> float:
    if not stack or not stack.get("cards"):
        return 0.0
    return spearmanBrown(len(stack["cards"]), stack["cards"][0]["avgR"])


def calcObservedValidity(pred_stack: Dict[str, Any], out_stack: Dict[str, Any]) -> float:
    rho = TRUE_VALIDITY.get(pred_stack["constructId"], {}).get(out_stack["constructId"], 0.1)
    aP = alphaFromStack(pred_stack)
    aO = alphaFromStack(out_stack)
    return rho * ((max(0.05, aP) * max(0.05, aO)) ** 0.5)


def approxPowerFromROBSandN(r_obs: float, n: int) -> float:
    # faithful-enough monotonic approximation for parity in deterministic tests
    r = clamp(abs(r_obs), 0.0, 0.999999)
    return clamp(0.05 + 0.94 * r * ((max(4, int(n)) - 3) / max(4, int(n))), 0.05, 0.99)


def refreshMonsterStats(m: Dict[str, Any]) -> None:
    if m.get("isMeta"):
        m["power"] = clamp(m.get("power", 0.95), 0.7, 0.99)
        return
    validity_multiplier = max(0.0, m.get("validityMultiplier", 1.0))
    effective_multiplier = 0.0 if m.get("itemLeakageApplied") else validity_multiplier
    m["rObs"] = (m["rTrue"] * (max(0.05, m["predAlpha"]) * max(0.05, m["outAlpha"])) ** 0.5) * effective_multiplier
    m["baseAtk"] = round(abs(m["rObs"]) * 10000)
    correction_base = round(abs(m.get("rTrue", 0)) * effective_multiplier * 10000)
    effective_atk_base = correction_base if m.get("correctionApplied") else m["baseAtk"]
    next_atk = effective_atk_base
    for _ in range(max(0, m.get("rangeRestrictionStacks", 0))):
        next_atk = round(next_atk / 2)
    m["atk"] = next_atk
    m["power"] = approxPowerFromROBSandN(abs(m["atk"]) / 10000.0, m.get("n", 50))


def makeItemCard(construct_id: str) -> Dict[str, Any]:
    c = CONSTRUCTS[construct_id]
    return {
        "isItem": True,
        "type": c["type"],
        "constructId": construct_id,
        "construct": c["name"],
        "short": c["short"],
        "avgR": c["avgR"],
    }


def makeSpellCard(card_id: str) -> Dict[str, Any]:
    return {"isItem": False, "id": card_id}


def buildStartingDeck() -> List[Dict[str, Any]]:
    deck: List[Dict[str, Any]] = []
    for cid, n in COUNTS.items():
        for _ in range(n):
            if cid in CONSTRUCTS:
                deck.append(makeItemCard(cid))
            else:
                deck.append(makeSpellCard(cid))
    return deck


def drawCards(player: Dict[str, Any], n: int, allowOverflow: bool = False) -> None:
    for _ in range(n):
        if (not allowOverflow) and len(player["hand"]) >= MAX_HAND_SIZE:
            break
        if player["deck"]:
            player["hand"].append(player["deck"].pop())


def enforceHandLimit(player: Dict[str, Any]) -> None:
    player["pendingDiscard"] = max(0, len(player["hand"]) - MAX_HAND_SIZE)


def firstEmptySlot(arr: List[Any]) -> int:
    for i, x in enumerate(arr):
        if x is None:
            return i
    return -1


def makeConstructStackFromCard(card: Dict[str, Any]) -> Dict[str, Any]:
    return {"type": card["type"], "constructId": card["constructId"], "cards": [copy.deepcopy(card)]}


def buildMonster(pred_stack: Dict[str, Any], out_stack: Dict[str, Any]) -> Dict[str, Any]:
    rho = TRUE_VALIDITY.get(pred_stack["constructId"], {}).get(out_stack["constructId"], 0.1)
    ai = getPairAdverseImpact(pred_stack["constructId"], out_stack["constructId"])
    m = {
        "name": f"{pred_stack['constructId']}×{out_stack['constructId']}",
        "predId": pred_stack["constructId"],
        "outId": out_stack["constructId"],
        "predAlpha": alphaFromStack(pred_stack),
        "outAlpha": alphaFromStack(out_stack),
        "rTrue": rho,
        "adverseImpact": ai["bwd"],
        "adverseStars": ai["stars"],
        "adverseStarsText": ai["starsText"],
        "requiresJobRelevance": ai["requiresJobRelevance"],
        "rObs": 0,
        "baseAtk": 0,
        "atk": 0,
        "baseN": 50,
        "n": 50,
        "power": 0.1,
        "attacksMade": 0,
        "maxAttacks": 1,
        "summoningSick": True,
        "hasJobRelevance": False,
        "hasImputation": False,
        "hasPHacking": False,
        "hasPracticeEffect": False,
        "itemLeakageApplied": False,
        "correctionApplied": False,
        "rangeRestrictionStacks": 0,
        "validityMultiplier": 1,
        "isMeta": False,
    }
    refreshMonsterStats(m)
    return m


def buildMetaMonster(monsters: List[Dict[str, Any]]) -> Dict[str, Any]:
    mean_r = sum(abs(m.get("rObs", 0)) for m in monsters) / len(monsters)
    meta_r_true = clamp(mean_r * 1.35, 0.35, 0.95)
    combined_n = sum(m.get("baseN", 50) for m in monsters)
    m = {
        "name": "Meta-Analytic Titan",
        "predId": "META",
        "outId": "META",
        "predAlpha": 0.99,
        "outAlpha": 0.99,
        "rTrue": meta_r_true,
        "adverseImpact": 0,
        "adverseStars": 5,
        "adverseStarsText": "★★★★★",
        "requiresJobRelevance": False,
        "rObs": meta_r_true,
        "baseAtk": round(abs(meta_r_true) * 10000),
        "atk": 0,
        "baseN": combined_n,
        "n": combined_n,
        "power": 0.97,
        "attacksMade": 0,
        "maxAttacks": 1,
        "summoningSick": False,
        "hasJobRelevance": False,
        "hasImputation": False,
        "hasPHacking": False,
        "hasPracticeEffect": False,
        "itemLeakageApplied": False,
        "correctionApplied": False,
        "rangeRestrictionStacks": 0,
        "validityMultiplier": 1,
        "isMeta": True,
    }
    m["rObs"] = m["rTrue"]
    m["baseAtk"] = round(abs(m["rTrue"]) * 10000)
    m["atk"] = m["baseAtk"]
    m["power"] = clamp(0.9 + (m["n"] / 1000), 0.9, 0.99)
    return m


def localCanMeta(player: Dict[str, Any]) -> bool:
    m = player["monsters"]
    if not (m[0] and m[1] and m[2]):
        return False
    same_pred = all(x["predId"] == m[0]["predId"] and x["predId"] != "META" for x in m)
    same_out = all(x["outId"] == m[0]["outId"] and x["outId"] != "META" for x in m)
    return same_pred or same_out


def canMonsterAttack(monster: Optional[Dict[str, Any]]) -> bool:
    if not monster or monster.get("summoningSick") or monster.get("attacksMade", 0) >= monster.get("maxAttacks", 1):
        return False
    if monster.get("requiresJobRelevance") and not monster.get("hasJobRelevance"):
        return False
    return True


def initial_state() -> GameState:
    p1 = {"lp": 8000, "deck": buildStartingDeck(), "hand": [], "constructs": [None] * 3, "monsters": [None] * 3,
          "summoned": False, "experienceTokens": 0, "pendingDiscard": 0}
    p2 = {"lp": 8000, "deck": buildStartingDeck(), "hand": [], "constructs": [None] * 3, "monsters": [None] * 3,
          "summoned": False, "experienceTokens": 0, "pendingDiscard": 0}
    drawCards(p1, STARTING_HAND_SIZE)
    drawCards(p2, STARTING_HAND_SIZE)
    return GameState({
        "status": "active",
        "currentPlayer": 1,
        "winner": None,
        "mulligan": {"phase": False, "done": {"1": True, "2": True}},
        "players": {"1": p1, "2": p2},
    })


def legal_actions(state: GameState) -> List[Dict[str, Any]]:
    s = state.state
    if s["status"] == "finished":
        return []
    pid = str(s["currentPlayer"])
    p = s["players"][pid]
    oppid = "2" if pid == "1" else "1"
    moves: List[Dict[str, Any]] = []

    if p.get("pendingDiscard", 0) > 0:
        for i in range(len(p["hand"])):
            moves.append({"type": "discard_card", "hand_index": i})
        return moves

    for h, card in enumerate(p["hand"]):
        if card.get("isItem"):
            for slot in range(3):
                stack = p["constructs"][slot]
                if stack and stack["constructId"] != card["constructId"]:
                    continue
                if stack and len(stack["cards"]) >= 3:
                    continue
                moves.append({"type": "place_card", "hand_index": h, "slot": slot})
        else:
            cid = card["id"]
            if cid in TARGETING_MONSTER_SPELLS:
                for owner in ("me", "opp"):
                    arr = s["players"][pid if owner == "me" else oppid]["monsters"]
                    for ts, m in enumerate(arr):
                        if m is not None:
                            moves.append({"type": "play_spell", "hand_index": h, "target_owner": owner, "target_type": "monster", "target_slot": ts})
            if cid in TARGETING_CONSTRUCT_SPELLS:
                for owner in ("me", "opp"):
                    arr = s["players"][pid if owner == "me" else oppid]["constructs"]
                    for ts, c in enumerate(arr):
                        if c is not None:
                            moves.append({"type": "play_spell", "hand_index": h, "target_owner": owner, "target_type": "construct", "target_slot": ts})

    if p.get("experienceTokens", 0) >= EXPERIENCE_MISS_THRESHOLD and len(p["deck"]) > 0:
        moves.append({"type": "experience_draw"})

    if not p.get("summoned"):
        open_slot = firstEmptySlot(p["monsters"])
        for pred_slot in range(3):
            for out_slot in range(3):
                if open_slot != -1:
                    moves.append({"type": "summon", "pred_slot": pred_slot, "out_slot": out_slot})
                else:
                    for r in range(3):
                        if p["monsters"][r] is not None:
                            moves.append({"type": "summon", "pred_slot": pred_slot, "out_slot": out_slot, "replace_monster_slot": r})

    if localCanMeta(p):
        moves.append({"type": "meta"})

    opponent_has_monsters = any(m is not None for m in s["players"][oppid]["monsters"])
    for a, m in enumerate(p["monsters"]):
        if not canMonsterAttack(m):
            continue
        if not opponent_has_monsters:
            moves.append({"type": "attack", "attacker_slot": a, "target_type": "lp", "target_slot": None})
        for t, d in enumerate(s["players"][oppid]["monsters"]):
            if d is not None:
                moves.append({"type": "attack", "attacker_slot": a, "target_type": "monster", "target_slot": t})

    moves.append({"type": "end_turn"})
    return moves


def _mark_game_over(state: Dict[str, Any]) -> None:
    p1, p2 = state["players"]["1"], state["players"]["2"]
    if p1["lp"] <= 0 or p2["lp"] <= 0:
        state["status"] = "finished"
        state["winner"] = 1 if p1["lp"] > 0 else 2


def next_state(state: GameState, action: Dict[str, Any]) -> GameState:
    s = _clone(state)
    if s["status"] == "finished":
        return GameState(s)

    pid = str(s["currentPlayer"])
    oppid = "2" if pid == "1" else "1"
    me, opp = s["players"][pid], s["players"][oppid]
    atype = action["type"]

    if atype == "place_card":
        card = me["hand"][action["hand_index"]]
        slot = action["slot"]
        if not card.get("isItem"):
            return GameState(s)
        placed = me["hand"].pop(action["hand_index"])
        if me["constructs"][slot] is None:
            me["constructs"][slot] = makeConstructStackFromCard(placed)
        else:
            me["constructs"][slot]["cards"].append(copy.deepcopy(placed))

    elif atype == "discard_card":
        if 0 <= action["hand_index"] < len(me["hand"]):
            me["hand"].pop(action["hand_index"])
            me["pendingDiscard"] = max(0, me.get("pendingDiscard", 0) - 1)

    elif atype == "experience_draw":
        me["experienceTokens"] -= EXPERIENCE_MISS_THRESHOLD
        drawCards(me, EXPERIENCE_DRAW_COUNT, allowOverflow=True)
        enforceHandLimit(me)

    elif atype == "summon":
        pred, out = me["constructs"][action["pred_slot"]], me["constructs"][action["out_slot"]]
        if pred and out and pred["type"] == "predictor" and out["type"] == "outcome" and action["pred_slot"] != action["out_slot"]:
            monster = buildMonster(pred, out)
            me["constructs"][action["pred_slot"]] = None
            me["constructs"][action["out_slot"]] = None
            mslot = firstEmptySlot(me["monsters"])
            if mslot == -1:
                mslot = action.get("replace_monster_slot", 0)
            me["monsters"][mslot] = monster
            me["summoned"] = True

    elif atype == "play_spell":
        card = me["hand"].pop(action["hand_index"])
        owner = me if action["target_owner"] == "me" else opp
        arr = owner["constructs"] if action["target_type"] == "construct" else owner["monsters"]
        target = arr[action["target_slot"]]
        cid = card["id"]
        if target is not None:
            if cid == "sample_size" and action["target_type"] == "monster":
                target["n"] = int(clamp(target["n"] + 150, 50, 420))
                refreshMonsterStats(target)
            elif cid == "job_relevance" and action["target_type"] == "monster" and owner is me:
                target["hasJobRelevance"] = True
            elif cid == "imputation" and action["target_type"] == "monster" and owner is me:
                target["hasImputation"] = True
            elif cid == "p_hacking" and action["target_type"] == "monster" and owner is me:
                target["hasPHacking"] = True
            elif cid == "practice_effect" and action["target_type"] == "monster":
                target["hasPracticeEffect"] = True
            elif cid == "missing_data":
                if action["target_type"] == "monster" and target.get("hasImputation"):
                    target["hasImputation"] = False
                else:
                    arr[action["target_slot"]] = None
            elif cid == "range_restrict" and action["target_type"] == "monster" and owner is opp:
                target["rangeRestrictionStacks"] = max(0, target.get("rangeRestrictionStacks", 0)) + 1
                refreshMonsterStats(target)
            elif cid == "item_leakage" and action["target_type"] == "monster" and owner is opp:
                target["itemLeakageApplied"] = True
                refreshMonsterStats(target)
            elif cid == "correction" and action["target_type"] == "monster" and owner is me:
                target["correctionApplied"] = True
                target["rangeRestrictionStacks"] = 0
                refreshMonsterStats(target)
            elif cid == "bootstrapping" and action["target_type"] == "monster" and owner is me:
                target["baseN"] += 50
                target["n"] += 50
                refreshMonsterStats(target)
            elif cid == "item_analysis" and action["target_type"] == "construct" and owner is me and len(target["cards"]) < 3:
                target["cards"].append(copy.deepcopy(target["cards"][-1]))
            elif cid == "construct_drift" and action["target_type"] == "construct" and owner is opp:
                if len(target["cards"]) > 1:
                    target["cards"].pop()
                else:
                    arr[action["target_slot"]] = None
            elif cid == "criterion_contam" and action["target_type"] == "monster" and owner is opp:
                target["n"] = max(1, target["n"] // 2)
                target["baseN"] = max(1, target["baseN"] // 2)
                refreshMonsterStats(target)

    elif atype == "attack":
        attacker = me["monsters"][action["attacker_slot"]]
        if attacker:
            attacker["attacksMade"] += 1
            if action["target_type"] == "lp":
                opp["lp"] = max(0, opp["lp"] - attacker["atk"])
            else:
                defender = opp["monsters"][action["target_slot"]]
                if defender:
                    if attacker["atk"] > defender["atk"]:
                        opp["lp"] = max(0, opp["lp"] - (attacker["atk"] - defender["atk"]))
                        opp["monsters"][action["target_slot"]] = None
                    elif attacker["atk"] < defender["atk"]:
                        me["lp"] = max(0, me["lp"] - (defender["atk"] - attacker["atk"]))
                        me["monsters"][action["attacker_slot"]] = None
                    else:
                        me["monsters"][action["attacker_slot"]] = None
                        opp["monsters"][action["target_slot"]] = None
            if me["monsters"][action["attacker_slot"]] and me["monsters"][action["attacker_slot"]].get("hasPHacking"):
                me["monsters"][action["attacker_slot"]] = None

    elif atype == "meta":
        if localCanMeta(me):
            mats = [m for m in me["monsters"] if m is not None]
            me["monsters"] = [None, None, None]
            me["monsters"][0] = buildMetaMonster(mats)

    elif atype == "end_turn":
        for m in me["monsters"]:
            if m:
                m["correctionApplied"] = False
                m["itemLeakageApplied"] = False
                refreshMonsterStats(m)
        next_pid = oppid
        s["currentPlayer"] = int(next_pid)
        np = s["players"][next_pid]
        np["summoned"] = False
        drawCards(np, 1, allowOverflow=True)
        enforceHandLimit(np)
        for m in np["monsters"]:
            if m:
                m["summoningSick"] = False
                m["attacksMade"] = 0
                m["maxAttacks"] = 1
                refreshMonsterStats(m)

    _mark_game_over(s)
    return GameState(s)


def is_terminal(state: GameState) -> bool:
    return state.state.get("status") == "finished"


def terminal_value(state: GameState, player: int) -> int:
    if not is_terminal(state):
        return 0
    w = state.state.get("winner")
    if w == player:
        return 1
    if w is None:
        return 0
    return -1


def _parity_sanity_sequences() -> None:
    # Fixed, deterministic mini-traces derived from index.html transitions.
    s = initial_state()
    st = _clone(s)
    p1 = st["players"]["1"]
    p2 = st["players"]["2"]
    p1["hand"] = [makeItemCard("cog_ability"), makeItemCard("job_perf"), makeSpellCard("job_relevance")]
    p2["hand"] = []
    s = GameState(st)
    s = next_state(s, {"type": "place_card", "hand_index": 0, "slot": 0})
    s = next_state(s, {"type": "place_card", "hand_index": 0, "slot": 1})
    s = next_state(s, {"type": "summon", "pred_slot": 0, "out_slot": 1})
    m = s.state["players"]["1"]["monsters"][0]
    assert m is not None and m["predId"] == "cog_ability" and m["outId"] == "job_perf"
    assert m["summoningSick"] is True

    s = next_state(s, {"type": "end_turn"})
    s = next_state(s, {"type": "end_turn"})
    m = s.state["players"]["1"]["monsters"][0]
    assert m["summoningSick"] is False

    # direct-attack lethal trace
    st = _clone(s)
    st["players"]["2"]["monsters"] = [None, None, None]
    st["players"]["2"]["lp"] = 100
    s = GameState(st)
    s = next_state(s, {"type": "attack", "attacker_slot": 0, "target_type": "lp", "target_slot": None})
    assert is_terminal(s) and terminal_value(s, 1) == 1 and terminal_value(s, 2) == -1


if __name__ == "__main__":
    print("Psychometric Duel python port sanity CLI")
    _parity_sanity_sequences()
    print("Built-in parity sanity traces passed.")

    s = initial_state()
    while True:
        print("\nCurrent player:", s.state["currentPlayer"], "Status:", s.state["status"])
        print("P1 LP", s.state["players"]["1"]["lp"], "P2 LP", s.state["players"]["2"]["lp"])
        acts = legal_actions(s)
        for i, a in enumerate(acts[:20]):
            print(f"[{i}] {a}")
        if len(acts) > 20:
            print(f"... {len(acts)-20} more actions")
        if is_terminal(s):
            print("Terminal. values:", terminal_value(s, 1), terminal_value(s, 2))
            break
        raw = input("Choose action index (blank to quit): ").strip()
        if raw == "":
            break
        idx = int(raw)
        s = next_state(s, acts[idx])
