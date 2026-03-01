(function(global) {
    const PROFILES = {
        rookie: {
            label: 'Rookie',
            description: 'Forgiving AI: short search with noisy choices.',
            thinkMs: 260,
            rolloutDepth: 4,
            exploration: 1.45,
            topMoveWidth: 12,
            noise: 0.2,
            wideningStep: 3,
            tacticalDepthBonus: 1,
            simulationTemperature: 1.25,
            maxThinkPerMove: 320,
            maxSimulations: 240,
            turnBudgetMs: 900,
            maxChainMoves: 8
        },
        balanced: {
            label: 'Balanced',
            description: 'Solid AI: moderate search depth and cleaner sequencing.',
            thinkMs: 900,
            rolloutDepth: 6,
            exploration: 1.25,
            topMoveWidth: 16,
            noise: 0.08,
            wideningStep: 4,
            tacticalDepthBonus: 2,
            simulationTemperature: 1.0,
            maxThinkPerMove: 700,
            maxSimulations: 520,
            turnBudgetMs: 1600,
            maxChainMoves: 10
        },
        strong: {
            label: 'Strong',
            description: 'Optimized AI: deeper MCTS and stronger tactical conversion.',
            thinkMs: 2200,
            rolloutDepth: 9,
            exploration: 1.08,
            topMoveWidth: 22,
            noise: 0.02,
            wideningStep: 5,
            tacticalDepthBonus: 3,
            simulationTemperature: 0.85,
            maxThinkPerMove: 1200,
            maxSimulations: 900,
            turnBudgetMs: 2400,
            maxChainMoves: 12
        },
        expert: {
            label: 'Expert',
            description: 'Very strong AI: longest search budget and highest line accuracy.',
            thinkMs: 4200,
            rolloutDepth: 11,
            exploration: 0.95,
            topMoveWidth: 30,
            noise: 0,
            wideningStep: 6,
            tacticalDepthBonus: 4,
            simulationTemperature: 0.65,
            maxThinkPerMove: 1700,
            maxSimulations: 1300,
            turnBudgetMs: 3200,
            maxChainMoves: 14
        }
    };

    function safeStringify(value) {
        const seen = new WeakSet();
        return JSON.stringify(value, (key, input) => {
            if (input && typeof input === 'object') {
                if (seen.has(input)) return '[Circular]';
                seen.add(input);
                if (Array.isArray(input)) return input;
                const ordered = {};
                Object.keys(input).sort().forEach(k => {
                    ordered[k] = input[k];
                });
                return ordered;
            }
            return input;
        });
    }

    function moveKey(move) {
        return safeStringify(move);
    }

    function stateKey(state, pid) {
        return `${pid}|${safeStringify(state)}`;
    }

    function weightedPick(entries) {
        let total = 0;
        for (let i = 0; i < entries.length; i += 1) total += entries[i].weight;
        if (total <= 0) return entries[Math.floor(Math.random() * entries.length)].move;
        let r = Math.random() * total;
        for (let i = 0; i < entries.length; i += 1) {
            r -= entries[i].weight;
            if (r <= 0) return entries[i].move;
        }
        return entries[entries.length - 1].move;
    }

    function createController(adapter) {
        const moveCache = new Map();
        const evalCache = new Map();
        const transitionCache = new Map();
        const transpositionTable = new Map();
        const criticalityCache = new Map();
        const objectHashCache = new WeakMap();

        const VALUE_CLAMP = 5000;
        const MIN_SIM_TEMP = 0.18;
        const MAX_SIM_TEMP = 1.35;
        const EARLY_STOP_VISIT_SHARE = 0.68;
        const EARLY_STOP_MIN_VISITS = 110;

        const MAX_MOVE_CACHE = 900;
        const MAX_EVAL_CACHE = 1800;
        const MAX_TRANSITION_CACHE = 1800;
        const MAX_TT_ENTRIES = 2200;
        const MAX_CRITICALITY_CACHE = 1200;

        function clamp(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        function setWithLimit(map, key, value, maxSize) {
            if (map.has(key)) {
                map.set(key, value);
                return;
            }
            if (map.size >= maxSize) {
                const oldest = map.keys().next();
                if (!oldest.done) map.delete(oldest.value);
            }
            map.set(key, value);
        }

        function resetSearchCaches() {
            moveCache.clear();
            evalCache.clear();
            transitionCache.clear();
            transpositionTable.clear();
            criticalityCache.clear();
        }

        function cachedStateKey(state, pid) {
            if (state && typeof state === 'object') {
                const cached = objectHashCache.get(state);
                if (cached && cached.pid === pid) return cached.key;
                const key = stateKey(state, pid);
                objectHashCache.set(state, { pid, key });
                return key;
            }
            return stateKey(state, pid);
        }

        class TreeNode {
            constructor({ state, pid, rootPid, parent = null, move = null, prior = 1, isRoot = false }) {
                this.state = state;
                this.pid = pid;
                this.rootPid = rootPid;
                this.parent = parent;
                this.move = move;
                this.prior = prior;
                this.isRoot = isRoot;
                this.children = [];
                this.childByMove = new Map();
                this.visits = 0;
                this.valueSum = 0;
                this.expanded = false;
                this.terminal = !state || state.status !== 'active';
                this.candidateMoves = null;
                this.stateHash = cachedStateKey(state, pid);
            }

            qValue() {
                return this.visits > 0 ? this.valueSum / this.visits : 0;
            }
        }

        function simulateWithCache(state, pid, move) {
            const key = `${cachedStateKey(state, pid)}|${moveKey(move)}`;
            if (transitionCache.has(key)) return transitionCache.get(key);
            const result = adapter.simulateMove(state, pid, move);
            setWithLimit(transitionCache, key, result, MAX_TRANSITION_CACHE);
            return result;
        }

        function enumerateWithCache(state, pid, includeEndTurn) {
            const key = `${cachedStateKey(state, pid)}|${includeEndTurn ? 'withEnd' : 'withoutEnd'}`;
            if (!moveCache.has(key)) {
                const baseMoves = adapter.enumerateMoves(state, pid) || [];
                let moves = baseMoves.slice();
                if (includeEndTurn) {
                    const hasEnd = moves.some(move => move && move.type === 'end_turn');
                    if (!hasEnd) moves.push({ type: 'end_turn' });
                }
                setWithLimit(moveCache, key, moves, MAX_MOVE_CACHE);
            }
            return moveCache.get(key);
        }

        function evaluateWithCache(state, rootPid) {
            const key = `${cachedStateKey(state, rootPid)}|eval`;
            if (!evalCache.has(key)) {
                setWithLimit(evalCache, key, adapter.evaluateState(state, rootPid), MAX_EVAL_CACHE);
            }
            return evalCache.get(key);
        }

        function transpositionStats(node) {
            return transpositionTable.get(node.stateHash) || null;
        }

        function profileCriticality(state, pid) {
            const cacheKey = `${cachedStateKey(state, pid)}|criticality`;
            if (criticalityCache.has(cacheKey)) return criticalityCache.get(cacheKey);

            const moves = enumerateWithCache(state, pid, true);
            const branching = moves.length;
            if (!branching) {
                const empty = {
                    branching,
                    tacticalVolatility: 0,
                    closeContest: 0,
                    criticality: 0
                };
                setWithLimit(criticalityCache, cacheKey, empty, MAX_CRITICALITY_CACHE);
                return empty;
            }

            const scored = [];
            for (let i = 0; i < moves.length; i += 1) {
                const transition = simulateWithCache(state, pid, moves[i]);
                if (!transition.success) continue;
                const score = evaluateWithCache(transition.state, pid);
                scored.push(score);
            }
            scored.sort((a, b) => b - a);

            const topGap = scored.length > 1 ? Math.abs(scored[0] - scored[1]) : 150;
            const closeContest = topGap < 25 ? 1 : topGap < 60 ? 0.6 : 0.25;
            const tacticalVolatility = scored.length > 1 && (scored[0] - scored[scored.length - 1]) > 160 ? 1 : 0.4;
            const branchFactor = branching > 20 ? 1 : branching > 12 ? 0.65 : 0.35;
            const criticality = Math.min(1, (closeContest * 0.45) + (tacticalVolatility * 0.35) + (branchFactor * 0.2));

            const result = { branching, tacticalVolatility, closeContest, criticality };
            setWithLimit(criticalityCache, cacheKey, result, MAX_CRITICALITY_CACHE);
            return result;
        }

        function resolveProfile(state) {
            const key = adapter.getDifficulty();
            const profile = PROFILES[key] || PROFILES.strong;
            const currentPid = state && state.currentPlayer;
            const diagnostics = profileCriticality(state, currentPid);

            const branchingBonus = diagnostics.branching > 24 ? 1400 : diagnostics.branching > 16 ? 700 : diagnostics.branching > 10 ? 300 : 0;
            const criticalityBonus = Math.floor(profile.thinkMs * 0.45 * diagnostics.criticality);
            const maxThink = Math.floor(profile.thinkMs * 1.35);

            return {
                ...profile,
                diagnostics,
                thinkMs: Math.min(profile.thinkMs + branchingBonus + criticalityBonus, maxThink)
            };
        }

        function scoreMoveCandidate(state, pid, move, rootPid) {
            const transition = simulateWithCache(state, pid, move);
            if (!transition.success) return { score: -100000, transition };

            const nextState = transition.state;
            const meBefore = state.players && state.players[pid];
            const oppBefore = state.players && state.players[pid === 1 ? 2 : 1];
            const meAfter = nextState.players && nextState.players[pid];
            const oppAfter = nextState.players && nextState.players[pid === 1 ? 2 : 1];

            const base = evaluateWithCache(nextState, rootPid);
            let tactical = 0;

            if (move && move.type === 'end_turn') {
                const legalWithoutEnd = enumerateWithCache(state, pid, false);
                const actionableMoves = legalWithoutEnd.filter(candidate => candidate && candidate.type !== 'end_turn').length;
                tactical -= 35 + (actionableMoves * 6);
            }

            if (move && move.type === 'attack') {
                if (move.target_type === 'lp') tactical += 180;
                else tactical += 120;

                if (oppBefore && oppAfter) {
                    const oppMonstersBefore = oppBefore.monsters.filter(Boolean).length;
                    const oppMonstersAfter = oppAfter.monsters.filter(Boolean).length;
                    tactical += (oppMonstersBefore - oppMonstersAfter) * 90;
                    tactical += (oppBefore.lp - oppAfter.lp) * 0.45;
                }
            }

            if (move && move.type === 'summon' && meBefore && meAfter) {
                const myMonstersBefore = meBefore.monsters.filter(Boolean).length;
                const myMonstersAfter = meAfter.monsters.filter(Boolean).length;
                tactical += (myMonstersAfter - myMonstersBefore) * 135;
            }

            if (move && move.type === 'meta') tactical += 160;
            if (move && move.type === 'place_card') tactical += 70;
            if (move && move.type === 'play_spell') tactical += 85;

            if (nextState.status && nextState.status !== 'active') tactical += 2200;
            if (nextState.currentPlayer === rootPid) tactical += 30;
            else tactical += 10;

            return { score: base + tactical, transition };
        }

        function selectTopCandidates(state, pid, rootPid, profile) {
            const legal = enumerateWithCache(state, pid, true);
            const scored = [];

            for (let i = 0; i < legal.length; i += 1) {
                const move = legal[i];
                const result = scoreMoveCandidate(state, pid, move, rootPid);
                if (!result.transition.success) continue;
                scored.push({
                    move,
                    score: result.score,
                    prior: Math.max(0.001, result.score + 5000)
                });
            }

            scored.sort((a, b) => b.score - a.score);
            const cap = Math.min(profile.topMoveWidth, scored.length);
            return scored.slice(0, cap);
        }

        function expandNode(node, profile) {
            if (node.expanded || node.terminal) return;

            const candidates = selectTopCandidates(node.state, node.pid, node.rootPid, profile);
            node.candidateMoves = candidates;
            const childCap = node.isRoot
                ? Math.max(2, Math.min(candidates.length, 2 + Math.floor(node.visits / Math.max(1, profile.wideningStep))))
                : candidates.length;

            for (let i = 0; i < childCap; i += 1) {
                const candidate = candidates[i];
                const key = moveKey(candidate.move);
                if (node.childByMove.has(key)) continue;

                const transition = simulateWithCache(node.state, node.pid, candidate.move);
                if (!transition.success) continue;

                const child = new TreeNode({
                    state: transition.state,
                    pid: transition.state.currentPlayer,
                    rootPid: node.rootPid,
                    parent: node,
                    move: candidate.move,
                    prior: candidate.prior
                });
                node.children.push(child);
                node.childByMove.set(key, child);
            }

            node.expanded = true;
        }

        function maybeWidenRoot(node, profile) {
            if (!node.isRoot || !node.candidateMoves || !node.candidateMoves.length) return;
            const allowed = Math.max(2, Math.min(node.candidateMoves.length, 2 + Math.floor(node.visits / Math.max(1, profile.wideningStep))));
            if (allowed <= node.children.length) return;

            for (let i = 0; i < allowed; i += 1) {
                const candidate = node.candidateMoves[i];
                const key = moveKey(candidate.move);
                if (node.childByMove.has(key)) continue;
                const transition = simulateWithCache(node.state, node.pid, candidate.move);
                if (!transition.success) continue;

                const child = new TreeNode({
                    state: transition.state,
                    pid: transition.state.currentPlayer,
                    rootPid: node.rootPid,
                    parent: node,
                    move: candidate.move,
                    prior: candidate.prior
                });
                node.children.push(child);
                node.childByMove.set(key, child);
            }
        }

        function puctScore(parent, child, exploration) {
            const localQ = child.visits > 0 ? child.valueSum / child.visits : 0;
            const transposed = transpositionStats(child);
            const ttVisits = transposed ? transposed.visits : 0;
            const ttQ = ttVisits > 0 ? transposed.valueSum / ttVisits : 0;
            const blend = ttVisits > 0 ? clamp(ttVisits / (ttVisits + child.visits + 10), 0.12, 0.7) : 0;
            const q = localQ * (1 - blend) + (ttQ * blend);

            const dynamicExploration = exploration * (1 + (0.45 / (1 + Math.sqrt(child.visits + ttVisits))));
            const u = dynamicExploration * child.prior * Math.sqrt(parent.visits + 1) / (1 + child.visits + (ttVisits * 0.5));
            return q + u;
        }

        function selectChild(node, exploration) {
            let best = null;
            let bestScore = -Infinity;
            for (let i = 0; i < node.children.length; i += 1) {
                const child = node.children[i];
                const score = puctScore(node, child, exploration);
                if (score > bestScore) {
                    bestScore = score;
                    best = child;
                }
            }
            return best;
        }

        function rolloutPolicy(state, pid, rootPid, profile) {
            const moves = enumerateWithCache(state, pid, true);
            if (!moves.length) return null;

            const scored = [];
            let bestScore = -Infinity;
            for (let i = 0; i < moves.length; i += 1) {
                const move = moves[i];
                const candidate = scoreMoveCandidate(state, pid, move, rootPid);
                if (!candidate.transition.success) continue;
                scored.push({ move, score: candidate.score });
                if (candidate.score > bestScore) bestScore = candidate.score;
            }
            if (!scored.length) return null;

            const temp = clamp(profile.simulationTemperature, MIN_SIM_TEMP, MAX_SIM_TEMP);
            const weighted = scored.map(item => ({
                move: item.move,
                weight: Math.exp((item.score - bestScore) / (35 * temp))
            }));
            return weightedPick(weighted);
        }

        function rolloutDepthForState(baseDepth, state, profile) {
            const currentPid = state && state.currentPlayer;
            const diagnostics = profileCriticality(state, currentPid);
            const bonus = diagnostics.branching > 18 ? 1 : diagnostics.branching > 10 ? 2 : 3;
            return baseDepth + bonus + Math.floor(profile.tacticalDepthBonus * diagnostics.criticality);
        }

        function simulateLeaf(node, profile) {
            if (node.terminal) {
                return evaluateWithCache(node.state, node.rootPid);
            }

            let simState = node.state;
            let simPid = node.pid;
            let depth = 0;
            const baseDepth = profile.rolloutDepth;
            let depthLimit = rolloutDepthForState(baseDepth, node.state, profile);

            while (simState.status === 'active' && depth < depthLimit) {
                const move = rolloutPolicy(simState, simPid, node.rootPid, profile);
                if (!move) break;

                const next = simulateWithCache(simState, simPid, move);
                if (!next.success) break;

                const preScore = evaluateWithCache(simState, node.rootPid);
                const postScore = evaluateWithCache(next.state, node.rootPid);
                if (depth + 1 >= depthLimit && Math.abs(postScore - preScore) > 60) {
                    depthLimit += profile.tacticalDepthBonus;
                }

                simState = next.state;
                simPid = simState.currentPlayer;
                depth += 1;
            }

            return evaluateWithCache(simState, node.rootPid);
        }

        function backpropagate(path, value, rootPid) {
            for (let i = path.length - 1; i >= 0; i -= 1) {
                const node = path[i];
                node.visits += 1;
                const signed = clamp(node.pid === rootPid ? value : -value, -VALUE_CLAMP, VALUE_CLAMP);
                node.valueSum += signed;

                const existing = transpositionTable.get(node.stateHash);
                if (existing) {
                    existing.visits += 1;
                    existing.valueSum += signed;
                } else {
                    setWithLimit(transpositionTable, node.stateHash, { visits: 1, valueSum: signed }, MAX_TT_ENTRIES);
                    transpositionTable.set(node.stateHash, { visits: 1, valueSum: signed });
                }
            }
        }

        function shouldEarlyStop(root, elapsedMs, thinkMs) {
            if (!root.children || root.children.length < 2) return false;
            if (elapsedMs < thinkMs * 0.45 || root.visits < EARLY_STOP_MIN_VISITS) return false;

            let first = null;
            let second = null;
            for (let i = 0; i < root.children.length; i += 1) {
                const child = root.children[i];
                if (!first || child.visits > first.visits) {
                    second = first;
                    first = child;
                } else if (!second || child.visits > second.visits) {
                    second = child;
                }
            }
            if (!first || !second) return false;

            const share = first.visits / Math.max(1, root.visits);
            const qGap = first.qValue() - second.qValue();
            return share >= EARLY_STOP_VISIT_SHARE && qGap > 45;
        }

        function strategicMoveBonus(move) {
            if (!move) return 0;
            if (move.type === 'attack') return move.target_type === 'lp' ? 95 : 70;
            if (move.type === 'summon') return 65;
            if (move.type === 'meta') return 80;
            if (move.type === 'play_spell') return 40;
            if (move.type === 'place_card') return 35;
            if (move.type === 'end_turn') return -60;
            return 0;
        }

        function finalMoveSelection(children) {
            if (!children.length) return null;
            let best = children[0];
            let bestScore = -Infinity;

            for (let i = 0; i < children.length; i += 1) {
                const child = children[i];
                const score = (child.visits * 0.85) + (child.qValue() * 1.25) + strategicMoveBonus(child.move);
                if (score > bestScore) {
                    bestScore = score;
                    best = child;
                }
            }
            return best;
        }

        function chooseMoveWithMcts(state, pid, profile) {
            resetSearchCaches();

            const root = new TreeNode({
                state,
                pid,
                rootPid: pid,
                isRoot: true
            });

            expandNode(root, profile);
            if (!root.children.length) return null;
            if (root.children.length === 1) return root.children[0].move;

            const searchBudgetMs = Math.min(profile.thinkMs, profile.maxThinkPerMove || profile.thinkMs);
            const simulationCap = Math.max(80, profile.maxSimulations || Math.floor(searchBudgetMs * 0.8));

            const start = performance.now();
            let iterations = 0;
            while ((performance.now() - start) < searchBudgetMs && iterations < simulationCap) {
                iterations += 1;
                maybeWidenRoot(root, profile);
                const path = [root];
                let node = root;

                while (!node.terminal) {
                    if (!node.expanded) {
                        expandNode(node, profile);
                        break;
                    }
                    if (!node.children.length) break;
                    node = selectChild(node, profile.exploration);
                    path.push(node);
                }

                if (!node.terminal && !node.expanded) {
                    expandNode(node, profile);
                    if (node.children.length) {
                        node = selectChild(node, profile.exploration);
                        path.push(node);
                    }
                }

                const value = simulateLeaf(node, profile);
                backpropagate(path, value, pid);

                if (shouldEarlyStop(root, performance.now() - start, searchBudgetMs)) break;
                if (shouldEarlyStop(root, performance.now() - start, profile.thinkMs)) break;
            }

            root.children.sort((a, b) => {
                if (b.visits !== a.visits) return b.visits - a.visits;
                return b.qValue() - a.qValue();
            });

            const primary = finalMoveSelection(root.children);
            if (profile.noise > 0 && root.children.length > 1 && Math.random() < profile.noise) {
                const fallback = root.children.find(child => child.move && child.move.type !== 'end_turn' && child !== primary);
                return (fallback || root.children[1]).move;
            }
            return primary ? primary.move : root.children[0].move;
        }

        async function playTurn(pid) {
            let state = adapter.getState();
            if (!state || state.status !== 'active' || state.currentPlayer !== pid) return;
            const profile = resolveProfile(state);
            await new Promise(r => setTimeout(r, Math.min(180, Math.max(60, profile.thinkMs * 0.12))));

            const turnStarted = performance.now();
            const turnBudgetMs = Math.max(300, profile.turnBudgetMs || profile.thinkMs);
            const maxChainMoves = Math.max(4, profile.maxChainMoves || 10);

            let guard = 0;
            while (guard++ < maxChainMoves && (performance.now() - turnStarted) < turnBudgetMs) {
                state = adapter.getState();
                if (!state || state.status !== 'active' || state.currentPlayer !== pid) break;
                const move = chooseMoveWithMcts(state, pid, profile);
                if (!move) break;
                const applied = adapter.applyMove(pid, move);
                if (!applied.success) break;
                if (move.type === 'end_turn') break;
            }

            state = adapter.getState();
            if (state && state.status === 'active' && state.currentPlayer === pid) {
                const endTurn = adapter.applyMove(pid, { type: 'end_turn' });
                if (!endTurn.success) {
                    const fallbackMove = chooseMoveWithMcts(state, pid, { ...profile, thinkMs: Math.min(profile.thinkMs, 200) });
                    if (fallbackMove) adapter.applyMove(pid, fallbackMove);
                }
            }
        }

        return { playTurn };
    }

    global.PsychometricDuelMctsAI = {
        PROFILES,
        createController
    };
})(window);
