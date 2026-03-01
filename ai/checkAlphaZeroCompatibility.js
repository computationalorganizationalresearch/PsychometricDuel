#!/usr/bin/env node
const fs = require('fs');
const vm = require('vm');

const context = {
  window: {},
  console,
  setTimeout,
  clearTimeout,
  performance: { now: () => Date.now() },
  Math,
};
context.window = context;

context.PsychometricDuelMctsAI = {
  createController(adapter) {
    return {
      async playTurn(pid) {
        const state = adapter.getState();
        const moves = adapter.enumerateMoves(state, pid);
        if (!moves.length) return;
        adapter.applyMove(pid, moves.find(m => m.type === 'attack') || moves[0]);
      }
    };
  }
};

const src = fs.readFileSync('ai/singlePlayerAlphaZero.js', 'utf8');
vm.runInNewContext(src, context, { filename: 'singlePlayerAlphaZero.js' });

function buildState() {
  const mkPlayer = () => ({
    lp: 3000,
    hand: [],
    deck: [],
    constructs: [null, null, null],
    monsters: [
      { atk: 500, baseN: 80, power: 0.5, summoningSick: false },
      null,
      null
    ],
    experienceTokens: 0
  });
  return { status: 'active', currentPlayer: 1, players: { 1: mkPlayer(), 2: mkPlayer() } };
}

function enumerateMoves(state, pid) {
  if (state.status !== 'active') return [];
  const me = state.players[pid];
  const opp = state.players[pid === 1 ? 2 : 1];
  const moves = [];
  if (me.monsters[0]) moves.push({ type: 'attack', attacker_slot: 0, target_type: 'lp', target_slot: null });
  if (opp.monsters[0] && me.monsters[0]) moves.push({ type: 'attack', attacker_slot: 0, target_type: 'monster', target_slot: 0 });
  moves.push({ type: 'end_turn' });
  return moves;
}

async function runGame(gameNo) {
  let state = buildState();
  const adapter = {
    getDifficulty: () => 'strong',
    getState: () => state,
    enumerateMoves,
    applyMove: (pid, move) => {
      if (state.status !== 'active' || state.currentPlayer !== pid) return { success: false };
      const me = state.players[pid];
      const opp = state.players[pid === 1 ? 2 : 1];
      if (move.type === 'attack') {
        if (move.target_type === 'lp') opp.lp -= me.monsters[0]?.atk || 0;
        else opp.monsters[0] = null;
      }
      if (opp.lp <= 0) {
        state.status = 'finished';
        state.winner = pid;
      } else {
        state.currentPlayer = pid === 1 ? 2 : 1;
      }
      return { success: true };
    }
  };

  const p1 = context.PsychometricDuelAlphaZeroAI.createController(adapter);
  const p2 = context.PsychometricDuelAlphaZeroAI.createController(adapter);

  let turnGuard = 0;
  while (state.status === 'active' && turnGuard < 50) {
    turnGuard += 1;
    if (state.currentPlayer === 1) await p1.playTurn(1);
    else await p2.playTurn(2);
  }
  return { game: gameNo, status: state.status, winner: state.winner || null, turns: turnGuard };
}

(async () => {
  const results = [];
  for (let i = 1; i <= 3; i += 1) results.push(await runGame(i));
  console.table(results);
  const failed = results.some(r => r.status !== 'finished');
  if (failed) process.exit(1);
})();
