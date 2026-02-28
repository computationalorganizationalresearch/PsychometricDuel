# Single-Player TCG-Style AI Design (JavaScript)

This document outlines a **TCG-style** AI for PsychometricDuel single-player mode, inspired by how AI is often built for games like MTG/Yu-Gi-Oh in digital clients:

- heuristic-driven action generation,
- hidden-information assumptions,
- short tactical lookahead,
- and Monte Carlo Tree Search (MCTS) for turn-level optimization.

## 1) Core Behavior Goals

A strong card-game AI should:

1. Evaluate board advantage (tempo, card advantage, life totals, threats).
2. Plan a full turn sequence (order of actions matters a lot in TCGs).
3. Handle uncertain information (unknown hand/deck draws).
4. Trade speed vs quality depending on difficulty.

MCTS is a strong fit because it can search action sequences under stochastic outcomes and return the best move under a strict time budget.

## 2) High-Level Architecture

Use a two-layer approach:

1. **Rule/Policy Layer (fast):**
   - Enumerate legal actions.
   - Filter clearly bad actions (domain heuristics).
   - Score tactical urgency (e.g., lethal check, survival check).

2. **MCTS Layer (expensive):**
   - Searches candidate turn lines by simulation.
   - Uses rollout/value heuristics to estimate outcomes.
   - Returns an action sequence (or first action in that sequence).

### Suggested JS Modules

- `ai/DecisionEngine.js` – top-level `chooseAction(gameState, difficulty)`
- `ai/mcts/MCTS.js` – selection/expansion/simulation/backprop
- `ai/mcts/Node.js` – tree node stats (N, W, Q, P)
- `ai/eval/Evaluator.js` – board-state scoring function
- `ai/policy/ActionPruner.js` – legal move pruning & ordering
- `ai/model/OpponentModel.js` – hidden info assumptions

## 3) MCTS Setup for a Card Game

Each MCTS node stores:

- `state`: deterministic snapshot or determinized sample,
- `playerToAct`,
- `children` keyed by action,
- `N` (visit count),
- `W` (total value),
- `Q = W / N`,
- optional `P` prior from heuristic policy.

Use UCT/PUCT for selection:

`score = Q + c * P * sqrt(parentN) / (1 + N)`

### Turn-Sequence Search

In TCGs, one turn may include many actions. Two common options:

- **Atomic-action tree:** each node = one action.
- **Macro-action tree:** each node = meaningful chunks (e.g., “play card X then attack”).

For performance, prefer **macro-actions** on easier levels and atomic actions on higher levels.

## 4) Handling Hidden Information

Like most TCG AIs, use **determinization**:

1. Sample plausible opponent hands/decks from known constraints.
2. Run MCTS on several sampled worlds.
3. Aggregate root action values across samples.

This gives practical strength without full imperfect-information game solving.

## 5) Evaluation Function (Heuristic Value)

Use a weighted score (from AI perspective):

- life/health differential,
- board power differential,
- threat quality (evasion, protected units, combo pieces),
- card advantage / hand size,
- resource efficiency (mana/energy utilization),
- imminent lethal risk (big penalty),
- near-lethal opportunity (big bonus).

This evaluator is used for:

- leaf evaluation,
- rollout cutoffs,
- action ordering/pruning.

## 6) Difficulty Levels and Think Time

Implement difficulty by changing:

- time budget,
- max iterations,
- depth cap,
- breadth pruning aggressiveness,
- opponent-model quality,
- noise/randomization in final selection.

### Four Levels

1. **Rookie**
   - Think time: ~150–300ms
   - Heavy pruning, shallow depth
   - More randomness and obvious-play bias

2. **Balanced**
   - Think time: ~500–1200ms
   - Moderate pruning, medium depth
   - Good tactical play, occasional misses

3. **Strong**
   - Think time: ~1.5–3.0s
   - Wider branch coverage, deeper search
   - Better hidden-info handling and sequencing

4. **Expert**
   - Think time: ~3–8s (or adaptive up to cap)
   - Deepest search, least random noise
   - Better long-term planning and lethal setups

### Adaptive Thinking

Increase think time automatically when:

- there are many legal lines,
- lethal is possible for either side,
- board state is highly volatile,
- combo windows are open.

Keep a hard cap to avoid UX stalls.

## 7) Example JavaScript Flow (Pseudo-code)

```js
async function chooseAction(gameState, difficulty) {
  const budget = getDifficultyBudget(difficulty, gameState);

  const urgent = detectForcedLines(gameState); // lethal / must-answer checks
  if (urgent) return urgent;

  const candidateActions = pruneAndOrderActions(gameState, difficulty);
  if (candidateActions.length === 1) return candidateActions[0];

  const worlds = sampleDeterminizedWorlds(gameState, difficulty);
  const rootStats = new Map();

  const start = performance.now();
  while (performance.now() - start < budget.ms) {
    for (const world of worlds) {
      runOneMctsIteration(world, candidateActions, difficulty, rootStats);
    }
  }

  return selectFinalAction(rootStats, difficulty); // best or softened-best
}
```

## 8) Practical Performance Tips (JS)

- Use **state cloning pools** or reversible moves instead of deep JSON cloning.
- Use **bitmasks/compact structs** for frequent state checks.
- Cache legal moves and incremental evaluator deltas.
- Run AI in a **Web Worker** (browser) or worker thread (Node) to avoid frame drops.
- Keep deterministic RNG seeds for reproducible debugging.

## 9) TCG-Like “Human” Behavior

To mimic typical card-game AI personality:

- Rookie/Balanced: occasionally pick top-2/top-3 move with weighted randomness.
- Strong/Expert: mostly best move; only tiny entropy to reduce repetition.
- Add “style profiles” (aggro/control/combo bias) as policy priors.

## 10) Recommended Default Parameters

- MCTS exploration constant `c`: 1.1–1.8 (tune per game).
- Rollout depth cap: 4/6/8/10 plies from Rookie→Expert.
- Root parallelization: 4–16 determinization samples.
- Action pruning target: keep top 4/8/12/20 candidates by level.

## 11) Integration Checklist

1. Add difficulty config table with per-level budgets.
2. Implement evaluator weights and unit tests.
3. Add MCTS core loop with time-based cutoff.
4. Add determinization sampler for hidden info.
5. Add logging hooks (`nodes`, `depth`, `bestQ`, `timeMs`).
6. Tune via self-play and scripted puzzle positions.

With this setup, the AI behaves similarly to many commercial-style digital TCG AIs: quick but imperfect at low levels, and increasingly thoughtful and line-accurate at higher levels, with **Expert** taking longer to compute stronger turn decisions.
