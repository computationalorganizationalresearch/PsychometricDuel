(function(global) {
    const MODEL_PATH = 'ai/models/alphazero_best.onnx';
    const MAX_HAND_SIZE = 12;

    const PROFILES = {
        rookie: { label: 'Rookie', description: 'Neural AI with exploratory sampling.', temperature: 1.15, topK: 14, thinkDelayMs: 120, maxChainMoves: 8 },
        balanced: { label: 'Balanced', description: 'Neural AI with mild exploration.', temperature: 0.75, topK: 10, thinkDelayMs: 140, maxChainMoves: 10 },
        strong: { label: 'Strong', description: 'Neural AI mostly greedy with occasional variety.', temperature: 0.35, topK: 6, thinkDelayMs: 150, maxChainMoves: 12 },
        expert: { label: 'Expert', description: 'Neural AI greedy best-move play.', temperature: 0, topK: 1, thinkDelayMs: 160, maxChainMoves: 14 }
    };

    function actionKey(action) {
        return JSON.stringify(action, Object.keys(action).sort());
    }

    function normalize(weights) {
        const sum = weights.reduce((a, b) => a + b, 0);
        if (sum <= 0) return weights.map(() => 0);
        return weights.map(v => v / sum);
    }

    function sampleIndex(weights) {
        let roll = Math.random();
        for (let i = 0; i < weights.length; i += 1) {
            roll -= weights[i];
            if (roll <= 0) return i;
        }
        return weights.length - 1;
    }

    function getPlayerBucket(state, pid) {
        if (!state || !state.players) return null;
        return state.players[pid] || state.players[String(pid)] || null;
    }

    function encodeState(state, pid) {
        const me = getPlayerBucket(state, pid);
        const opp = getPlayerBucket(state, pid === 1 ? 2 : 1);
        if (!me || !opp) throw new Error('Invalid state shape for AlphaZero encoder.');

        const feats = [];
        feats.push(me.lp / 8000.0, opp.lp / 8000.0);
        feats.push((me.hand?.length || 0) / MAX_HAND_SIZE, (opp.hand?.length || 0) / MAX_HAND_SIZE);
        feats.push((me.deck?.length || 0) / 80.0, (opp.deck?.length || 0) / 80.0);
        feats.push((me.experienceTokens || 0) / 10.0, (opp.experienceTokens || 0) / 10.0);
        feats.push(Number(state.currentPlayer === pid));

        function encodeSide(player) {
            (player.constructs || []).forEach(stack => {
                if (!stack) feats.push(0, 0);
                else feats.push(1, (stack.cards?.length || 0) / 3.0);
            });

            (player.monsters || []).forEach(monster => {
                if (!monster) {
                    feats.push(0, 0, 0, 0, 0, 0, 0, 0);
                    return;
                }
                feats.push(
                    1,
                    (monster.atk || 0) / 10000.0,
                    (monster.baseN || 50) / 500.0,
                    monster.power || 0,
                    Number(Boolean(monster.summoningSick)),
                    Number(Boolean(monster.hasJobRelevance)),
                    Number(Boolean(monster.itemLeakageApplied)),
                    Number(Boolean(monster.correctionApplied))
                );
            });
        }

        encodeSide(me);
        encodeSide(opp);
        return new Float32Array(feats);
    }

    function buildActionSpace() {
        const actions = [];
        const actionToId = new Map();
        const register = action => {
            const key = actionKey(action);
            if (!actionToId.has(key)) {
                actionToId.set(key, actions.length);
                actions.push(action);
            }
        };

        register({ type: 'end_turn' });
        register({ type: 'meta' });
        register({ type: 'experience_draw' });

        for (let h = 0; h < MAX_HAND_SIZE; h += 1) {
            register({ type: 'discard_card', hand_index: h });
            for (let slot = 0; slot < 3; slot += 1) register({ type: 'place_card', hand_index: h, slot });
            ['me', 'opp'].forEach(target_owner => {
                for (let target_slot = 0; target_slot < 3; target_slot += 1) {
                    register({ type: 'play_spell', hand_index: h, target_owner, target_type: 'monster', target_slot });
                    register({ type: 'play_spell', hand_index: h, target_owner, target_type: 'construct', target_slot });
                }
            });
        }

        for (let pred_slot = 0; pred_slot < 3; pred_slot += 1) {
            for (let out_slot = 0; out_slot < 3; out_slot += 1) {
                register({ type: 'summon', pred_slot, out_slot });
                for (let replace_monster_slot = 0; replace_monster_slot < 3; replace_monster_slot += 1) {
                    register({ type: 'summon', pred_slot, out_slot, replace_monster_slot });
                }
            }
        }

        for (let attacker_slot = 0; attacker_slot < 3; attacker_slot += 1) {
            register({ type: 'attack', attacker_slot, target_type: 'lp', target_slot: null });
            for (let target_slot = 0; target_slot < 3; target_slot += 1) {
                register({ type: 'attack', attacker_slot, target_type: 'monster', target_slot });
            }
        }

        return { actions, actionToId };
    }

    const ACTION_SPACE = buildActionSpace();

    function createController(adapter, options = {}) {
        let sessionPromise = null;
        let fallbackController = null;
        let fallbackWarned = false;

        async function getFallbackController() {
            if (!fallbackController) {
                if (global.PsychometricDuelMctsAI?.createController) {
                    fallbackController = global.PsychometricDuelMctsAI.createController(adapter);
                } else {
                    fallbackController = {
                        async playTurn(pid) {
                            const moves = adapter.enumerateMoves(adapter.getState(), pid) || [];
                            if (!moves.length) return;
                            const chosen = moves[Math.floor(Math.random() * moves.length)];
                            adapter.applyMove(pid, chosen);
                        }
                    };
                }
            }
            return fallbackController;
        }

        async function loadSession() {
            if (sessionPromise) return sessionPromise;
            sessionPromise = (async () => {
                if (!global.ort?.InferenceSession) {
                    throw new Error('onnxruntime-web is not loaded.');
                }
                return global.ort.InferenceSession.create(options.modelPath || MODEL_PATH, {
                    executionProviders: ['wasm']
                });
            })();
            return sessionPromise;
        }

        function profileForState() {
            const key = adapter.getDifficulty?.() || 'strong';
            return PROFILES[key] || PROFILES.strong;
        }

        function legalActionIds(state, pid, legalMoves) {
            const ids = [];
            const pairs = [];
            legalMoves.forEach(move => {
                const k = actionKey(move);
                const id = ACTION_SPACE.actionToId.get(k);
                if (id === undefined) return;
                ids.push(id);
                pairs.push({ id, move });
            });
            return { ids, pairs };
        }

        async function inferPolicyValue(state, pid) {
            const vec = encodeState(state, pid);
            const sess = await loadSession();
            const inputName = sess.inputNames[0] || 'state';
            const feed = {};
            feed[inputName] = new global.ort.Tensor('float32', vec, [1, vec.length]);
            const outputs = await sess.run(feed);
            const policyName = outputs.policy_probs ? 'policy_probs' : (sess.outputNames.find(name => name.includes('policy')) || sess.outputNames[0]);
            const valueName = outputs.value ? 'value' : (sess.outputNames.find(name => name.includes('value')) || sess.outputNames[sess.outputNames.length - 1]);
            return {
                policy: Array.from(outputs[policyName].data),
                value: Number(outputs[valueName].data[0] ?? 0)
            };
        }

        function pickMoveFromPolicy(legalPairs, policy, profile) {
            const weighted = legalPairs.map(pair => ({
                move: pair.move,
                score: Math.max(0, policy[pair.id] ?? 0)
            }));

            const sorted = weighted.slice().sort((a, b) => b.score - a.score);
            const topK = Math.max(1, Math.min(profile.topK || sorted.length, sorted.length));
            const finalists = sorted.slice(0, topK);

            if ((profile.temperature || 0) <= 0 || finalists.length === 1) return finalists[0].move;

            const scaled = finalists.map(item => Math.pow(Math.max(item.score, 1e-8), 1 / profile.temperature));
            const probs = normalize(scaled);
            return finalists[sampleIndex(probs)].move;
        }

        async function selectMove(state, pid) {
            const legalMoves = adapter.enumerateMoves(state, pid) || [];
            if (!legalMoves.length) return null;

            const { pairs } = legalActionIds(state, pid, legalMoves);
            if (!pairs.length) return legalMoves.find(move => move.type === 'end_turn') || legalMoves[0];

            const { policy, value } = await inferPolicyValue(state, pid);
            const move = pickMoveFromPolicy(pairs, policy, profileForState());
            return { move, value };
        }

        async function playTurn(pid) {
            const profile = profileForState();
            await new Promise(resolve => setTimeout(resolve, profile.thinkDelayMs || 120));
            let guard = 0;

            while (guard < (profile.maxChainMoves || 10)) {
                guard += 1;
                const state = adapter.getState();
                if (!state || state.status !== 'active' || state.currentPlayer !== pid) break;
                try {
                    const result = await selectMove(state, pid);
                    const move = result?.move || result;
                    if (!move) break;
                    const applied = adapter.applyMove(pid, move);
                    if (!applied.success || move.type === 'end_turn') break;
                } catch (error) {
                    if (!fallbackWarned) {
                        console.warn('[AlphaZeroAI] ONNX inference unavailable, falling back to MCTS/random.', error);
                        fallbackWarned = true;
                    }
                    const fallback = await getFallbackController();
                    await fallback.playTurn(pid);
                    return;
                }
            }

            const state = adapter.getState();
            if (state && state.status === 'active' && state.currentPlayer === pid) {
                adapter.applyMove(pid, { type: 'end_turn' });
            }
        }

        return {
            playTurn,
            selectMove,
            load: loadSession
        };
    }

    global.PsychometricDuelAlphaZeroAI = {
        PROFILES,
        ACTION_SPACE,
        encodeState,
        createController
    };
})(window);
