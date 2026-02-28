(function(global) {
    const PROFILES = {
        rookie: {
            label: 'Rookie',
            description: 'Forgiving AI: short search with noisy choices.',
            thinkMs: 220,
            rolloutDepth: 3,
            exploration: 1.35,
            topMoveWidth: 10,
            noise: 0.2
        },
        balanced: {
            label: 'Balanced',
            description: 'Solid AI: moderate search depth and cleaner sequencing.',
            thinkMs: 700,
            rolloutDepth: 5,
            exploration: 1.2,
            topMoveWidth: 14,
            noise: 0.08
        },
        strong: {
            label: 'Strong',
            description: 'Optimized AI: deeper MCTS and stronger tactical conversion.',
            thinkMs: 1800,
            rolloutDepth: 7,
            exploration: 1.08,
            topMoveWidth: 18,
            noise: 0.02
        },
        expert: {
            label: 'Expert',
            description: 'Very strong AI: longest search budget and highest line accuracy.',
            thinkMs: 3600,
            rolloutDepth: 9,
            exploration: 1.0,
            topMoveWidth: 24,
            noise: 0
        }
    };

    function scoreRoot(node, parentVisits, exploration) {
        if (node.visits === 0) return Number.POSITIVE_INFINITY;
        return (node.value / node.visits) + exploration * Math.sqrt(Math.log(parentVisits + 1) / node.visits);
    }

    function createController(adapter) {
        function resolveProfile(state) {
            const key = adapter.getDifficulty();
            const profile = PROFILES[key] || PROFILES.strong;
            const branching = adapter.enumerateMoves(state, 2).length;
            const complexityBonus = branching > 16 ? 500 : branching > 10 ? 250 : 0;
            return { ...profile, thinkMs: Math.min(profile.thinkMs + complexityBonus, profile.thinkMs * 1.75) };
        }

        function chooseMoveWithMcts(state, pid, profile) {
            const moves = adapter.enumerateMoves(state, pid).slice(0, profile.topMoveWidth);
            if (!moves.length) return null;
            if (moves.length === 1) return moves[0];

            const nodes = moves.map(move => ({ move, visits: 0, value: 0 }));
            const start = performance.now();
            let iterations = 0;

            while (performance.now() - start < profile.thinkMs) {
                iterations += 1;
                const parentVisits = iterations + 1;
                nodes.sort((a, b) => scoreRoot(b, parentVisits, profile.exploration) - scoreRoot(a, parentVisits, profile.exploration));
                const node = nodes[0];

                const firstStep = adapter.simulateMove(state, pid, node.move);
                if (!firstStep.success) {
                    node.visits += 1;
                    node.value -= 2500;
                    continue;
                }

                let simState = firstStep.state;
                let simPid = firstStep.state.currentPlayer;
                let depth = 1;

                while (simState.status === 'active' && depth < profile.rolloutDepth) {
                    const rolloutMoves = adapter.enumerateMoves(simState, simPid).slice(0, 8);
                    if (!rolloutMoves.length) break;
                    const pick = rolloutMoves[Math.floor(Math.random() * rolloutMoves.length)];
                    const next = adapter.simulateMove(simState, simPid, pick);
                    if (!next.success) break;
                    simState = next.state;
                    simPid = simState.currentPlayer;
                    depth += 1;
                }

                const score = adapter.evaluateState(simState, pid);
                node.visits += 1;
                node.value += score;
            }

            nodes.sort((a, b) => (b.value / Math.max(1, b.visits)) - (a.value / Math.max(1, a.visits)));
            if (profile.noise > 0 && nodes.length > 1 && Math.random() < profile.noise) {
                return nodes[1].move;
            }
            return nodes[0].move;
        }

        async function playTurn(pid) {
            let state = adapter.getState();
            if (!state || state.status !== 'active' || state.currentPlayer !== pid) return;
            const profile = resolveProfile(state);
            await new Promise(r => setTimeout(r, Math.max(120, profile.thinkMs * 0.25)));

            let guard = 0;
            while (guard++ < 14) {
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
                adapter.applyMove(pid, { type: 'end_turn' });
            }
        }

        return { playTurn };
    }

    global.PsychometricDuelMctsAI = {
        PROFILES,
        createController
    };
})(window);
