#!/usr/bin/env python3
"""AlphaZero-style training pipeline for Psychometric Duel.

Core stages included in one script:
- self-play with MCTS + neural priors/values,
- replay buffer accumulation,
- supervised training of policy/value heads,
- periodic checkpointing, evaluation, and best-model gating.
"""

from __future__ import annotations

import argparse
import copy
import json
import logging
import math
import random
from collections import deque
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Deque, Dict, List, Optional, Sequence, Tuple

import numpy as np
import torch
import torch.nn as nn
import torch.nn.functional as F
from torch.utils.data import DataLoader, TensorDataset

import pythonport


LOGGER = logging.getLogger("alphazero")


def _json_default(value: object):
    if isinstance(value, Path):
        return str(value)
    raise TypeError(f"Object of type {value.__class__.__name__} is not JSON serializable")


def make_json_safe(value: object) -> object:
    if isinstance(value, dict):
        return {str(k): make_json_safe(v) for k, v in value.items()}
    if isinstance(value, list):
        return [make_json_safe(v) for v in value]
    if isinstance(value, tuple):
        return [make_json_safe(v) for v in value]
    if isinstance(value, Path):
        return str(value)
    return value


MAX_HAND_SIZE = pythonport.MAX_HAND_SIZE


def configure_logging(verbose: bool) -> None:
    level = logging.DEBUG if verbose else logging.INFO
    logging.basicConfig(level=level, format="%(asctime)s | %(levelname)s | %(message)s")


def set_global_seed(seed: int) -> None:
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    torch.cuda.manual_seed_all(seed)
    torch.backends.cudnn.deterministic = True
    torch.backends.cudnn.benchmark = False


class PsychometricDuelEnv:
    def initial_state(self) -> pythonport.GameState:
        return pythonport.initial_state()

    def legal_actions(self, state: pythonport.GameState) -> List[Dict]:
        return pythonport.legal_actions(state)

    def next_state(self, state: pythonport.GameState, action: Dict) -> pythonport.GameState:
        return pythonport.next_state(state, action)

    def is_terminal(self, state: pythonport.GameState) -> bool:
        return pythonport.is_terminal(state)

    def terminal_value(self, state: pythonport.GameState, player: int) -> int:
        return pythonport.terminal_value(state, player)

    def current_player(self, state: pythonport.GameState) -> int:
        return int(state.state["currentPlayer"])


class ActionSpace:
    def __init__(self) -> None:
        self.actions: List[Dict] = []
        self.action_to_id: Dict[str, int] = {}
        self._build()

    @staticmethod
    def _action_key(action: Dict) -> str:
        return json.dumps(action, sort_keys=True, separators=(",", ":"))

    def _register(self, action: Dict) -> None:
        k = self._action_key(action)
        if k not in self.action_to_id:
            self.action_to_id[k] = len(self.actions)
            self.actions.append(action)

    def _build(self) -> None:
        self._register({"type": "end_turn"})
        self._register({"type": "meta"})
        self._register({"type": "experience_draw"})

        for h in range(MAX_HAND_SIZE):
            self._register({"type": "discard_card", "hand_index": h})
            for slot in range(3):
                self._register({"type": "place_card", "hand_index": h, "slot": slot})

            for owner in ("me", "opp"):
                for target_slot in range(3):
                    self._register(
                        {
                            "type": "play_spell",
                            "hand_index": h,
                            "target_owner": owner,
                            "target_type": "monster",
                            "target_slot": target_slot,
                        }
                    )
                    self._register(
                        {
                            "type": "play_spell",
                            "hand_index": h,
                            "target_owner": owner,
                            "target_type": "construct",
                            "target_slot": target_slot,
                        }
                    )

        for pred_slot in range(3):
            for out_slot in range(3):
                self._register({"type": "summon", "pred_slot": pred_slot, "out_slot": out_slot})
                for rep in range(3):
                    self._register(
                        {
                            "type": "summon",
                            "pred_slot": pred_slot,
                            "out_slot": out_slot,
                            "replace_monster_slot": rep,
                        }
                    )

        for attacker_slot in range(3):
            self._register(
                {
                    "type": "attack",
                    "attacker_slot": attacker_slot,
                    "target_type": "lp",
                    "target_slot": None,
                }
            )
            for target_slot in range(3):
                self._register(
                    {
                        "type": "attack",
                        "attacker_slot": attacker_slot,
                        "target_type": "monster",
                        "target_slot": target_slot,
                    }
                )

    def to_id(self, action: Dict) -> int:
        k = self._action_key(action)
        if k not in self.action_to_id:
            raise KeyError(f"Action not present in static action space: {action}")
        return self.action_to_id[k]

    def size(self) -> int:
        return len(self.actions)


def encode_state(state: pythonport.GameState, player: int) -> np.ndarray:
    s = state.state
    me = s["players"][str(player)]
    opp = s["players"]["2" if player == 1 else "1"]

    feats: List[float] = []
    feats.extend(
        [
            me["lp"] / 8000.0,
            opp["lp"] / 8000.0,
            len(me["hand"]) / MAX_HAND_SIZE,
            len(opp["hand"]) / MAX_HAND_SIZE,
            len(me["deck"]) / 80.0,
            len(opp["deck"]) / 80.0,
            me.get("experienceTokens", 0) / 10.0,
            opp.get("experienceTokens", 0) / 10.0,
            float(s["currentPlayer"] == player),
        ]
    )

    def encode_side(p: Dict) -> None:
        for stack in p["constructs"]:
            if stack is None:
                feats.extend([0.0, 0.0])
            else:
                feats.extend([1.0, len(stack["cards"]) / 3.0])
        for m in p["monsters"]:
            if m is None:
                feats.extend([0.0] * 8)
            else:
                feats.extend(
                    [
                        1.0,
                        m.get("atk", 0) / 10000.0,
                        m.get("baseN", 50) / 500.0,
                        m.get("power", 0.0),
                        float(m.get("summoningSick", False)),
                        float(m.get("hasJobRelevance", False)),
                        float(m.get("itemLeakageApplied", False)),
                        float(m.get("correctionApplied", False)),
                    ]
                )

    encode_side(me)
    encode_side(opp)

    vec = np.asarray(feats, dtype=np.float32)
    return vec


class AlphaZeroNet(nn.Module):
    def __init__(self, input_dim: int, action_dim: int, hidden_dim: int = 256) -> None:
        super().__init__()
        self.trunk = nn.Sequential(
            nn.Linear(input_dim, hidden_dim),
            nn.ReLU(),
            nn.Linear(hidden_dim, hidden_dim),
            nn.ReLU(),
        )
        self.policy_head = nn.Linear(hidden_dim, action_dim)
        self.value_head = nn.Sequential(nn.Linear(hidden_dim, hidden_dim // 2), nn.ReLU(), nn.Linear(hidden_dim // 2, 1), nn.Tanh())

    def forward(self, x: torch.Tensor) -> Tuple[torch.Tensor, torch.Tensor]:
        z = self.trunk(x)
        return self.policy_head(z), self.value_head(z).squeeze(-1)


@dataclass
class Node:
    state: pythonport.GameState
    to_play: int
    parent: Optional["Node"] = None
    prior: float = 0.0
    visit_count: int = 0
    value_sum: float = 0.0
    children: Dict[int, "Node"] = field(default_factory=dict)

    def q(self) -> float:
        return 0.0 if self.visit_count == 0 else self.value_sum / self.visit_count


class MCTS:
    def __init__(
        self,
        env: PsychometricDuelEnv,
        action_space: ActionSpace,
        net: AlphaZeroNet,
        device: torch.device,
        simulations: int,
        cpuct: float,
        dirichlet_alpha: float,
        dirichlet_eps: float,
    ) -> None:
        self.env = env
        self.action_space = action_space
        self.net = net
        self.device = device
        self.simulations = simulations
        self.cpuct = cpuct
        self.dirichlet_alpha = dirichlet_alpha
        self.dirichlet_eps = dirichlet_eps

    def _predict(self, state: pythonport.GameState, player: int) -> Tuple[np.ndarray, float]:
        x = torch.from_numpy(encode_state(state, player)).to(self.device).unsqueeze(0)
        with torch.no_grad():
            logits, value = self.net(x)
            priors = F.softmax(logits, dim=-1).squeeze(0).cpu().numpy()
            return priors, float(value.item())

    def _legal_ids(self, state: pythonport.GameState) -> List[int]:
        ids = []
        for a in self.env.legal_actions(state):
            try:
                ids.append(self.action_space.to_id(a))
            except KeyError:
                continue
        return ids

    def _expand(self, node: Node, add_root_noise: bool) -> float:
        priors, value = self._predict(node.state, node.to_play)
        legal_ids = self._legal_ids(node.state)
        if not legal_ids:
            return value

        legal_priors = np.array([max(1e-8, priors[i]) for i in legal_ids], dtype=np.float64)
        legal_priors /= legal_priors.sum()

        if add_root_noise and legal_ids:
            noise = np.random.dirichlet([self.dirichlet_alpha] * len(legal_ids))
            legal_priors = (1.0 - self.dirichlet_eps) * legal_priors + self.dirichlet_eps * noise

        for idx, p in zip(legal_ids, legal_priors):
            if idx not in node.children:
                node.children[idx] = Node(state=None, to_play=0, parent=node, prior=float(p))  # lazy state init
        return value

    def _select_child(self, node: Node) -> Tuple[int, Node]:
        sqrt_parent = math.sqrt(max(1, node.visit_count))
        best_score = -float("inf")
        best: Tuple[int, Node] = next(iter(node.children.items()))
        for action_id, child in node.children.items():
            u = self.cpuct * child.prior * sqrt_parent / (1 + child.visit_count)
            score = child.q() + u
            if score > best_score:
                best_score = score
                best = (action_id, child)
        return best

    def run(self, root_state: pythonport.GameState, to_play: int, training: bool) -> np.ndarray:
        root = Node(state=copy.deepcopy(root_state), to_play=to_play)
        self._expand(root, add_root_noise=training)

        for _ in range(self.simulations):
            node = root
            path = [node]

            while node.children:
                action_id, child = self._select_child(node)
                if child.state is None:
                    action = self.action_space.actions[action_id]
                    next_state = self.env.next_state(node.state, action)
                    child.state = next_state
                    child.to_play = self.env.current_player(next_state)
                node = child
                path.append(node)
                if self.env.is_terminal(node.state):
                    break

            if self.env.is_terminal(node.state):
                value = float(self.env.terminal_value(node.state, node.to_play))
            else:
                value = self._expand(node, add_root_noise=False)

            for back_node in reversed(path):
                back_node.visit_count += 1
                back_node.value_sum += value
                value = -value

        policy = np.zeros(self.action_space.size(), dtype=np.float32)
        total_visits = sum(c.visit_count for c in root.children.values())
        if total_visits > 0:
            for action_id, child in root.children.items():
                policy[action_id] = child.visit_count / total_visits
        return policy


@dataclass
class Sample:
    state: np.ndarray
    policy: np.ndarray
    player: int
    value: Optional[float] = None


def choose_action(policy: np.ndarray, move_number: int, temp_opening: int, temp: float) -> Tuple[int, np.ndarray]:
    if move_number < temp_opening:
        eff_temp = temp
    else:
        eff_temp = 0.1

    p = np.asarray(policy, dtype=np.float64)
    if p.sum() <= 0:
        p = np.ones_like(p) / len(p)
    else:
        p /= p.sum()

    if eff_temp <= 1e-6:
        action = int(np.argmax(p))
        out = np.zeros_like(p)
        out[action] = 1.0
        return action, out.astype(np.float32)

    pt = np.power(p + 1e-12, 1.0 / eff_temp)
    pt = pt / pt.sum()
    action = int(np.random.choice(np.arange(len(pt)), p=pt))
    return action, pt.astype(np.float32)


def self_play_episode(
    env: PsychometricDuelEnv,
    mcts: MCTS,
    action_space: ActionSpace,
    temp_opening_moves: int,
    temp: float,
    max_moves: int,
) -> Tuple[List[Sample], int]:
    state = env.initial_state()
    trajectory: List[Sample] = []
    move_num = 0

    while (not env.is_terminal(state)) and move_num < max_moves:
        to_play = env.current_player(state)
        search_policy = mcts.run(state, to_play=to_play, training=True)
        action_id, store_policy = choose_action(search_policy, move_num, temp_opening_moves, temp)
        trajectory.append(Sample(state=encode_state(state, to_play), policy=store_policy, player=to_play))

        action = action_space.actions[action_id]
        state = env.next_state(state, action)
        move_num += 1

    if env.is_terminal(state):
        winner = int(state.state["winner"])
    else:
        p1_lp = state.state["players"]["1"]["lp"]
        p2_lp = state.state["players"]["2"]["lp"]
        winner = 1 if p1_lp >= p2_lp else 2

    for s in trajectory:
        s.value = 1.0 if s.player == winner else -1.0

    return trajectory, winner


def train_epoch(
    net: AlphaZeroNet,
    optimizer: torch.optim.Optimizer,
    replay: Sequence[Sample],
    batch_size: int,
    device: torch.device,
) -> Dict[str, float]:
    states = torch.tensor(np.stack([x.state for x in replay]), dtype=torch.float32)
    policies = torch.tensor(np.stack([x.policy for x in replay]), dtype=torch.float32)
    values = torch.tensor(np.array([x.value for x in replay], dtype=np.float32))
    loader = DataLoader(TensorDataset(states, policies, values), batch_size=batch_size, shuffle=True)

    net.train()
    pol_loss_total = 0.0
    val_loss_total = 0.0
    n_batches = 0

    for xb, pb, vb in loader:
        xb, pb, vb = xb.to(device), pb.to(device), vb.to(device)
        logits, pred_v = net(xb)
        log_probs = F.log_softmax(logits, dim=-1)
        policy_loss = -(pb * log_probs).sum(dim=-1).mean()
        value_loss = F.mse_loss(pred_v, vb)
        loss = policy_loss + value_loss

        optimizer.zero_grad(set_to_none=True)
        loss.backward()
        optimizer.step()

        pol_loss_total += float(policy_loss.item())
        val_loss_total += float(value_loss.item())
        n_batches += 1

    return {
        "policy_loss": pol_loss_total / max(1, n_batches),
        "value_loss": val_loss_total / max(1, n_batches),
    }


def play_match(
    env: PsychometricDuelEnv,
    action_space: ActionSpace,
    net_a: AlphaZeroNet,
    net_b: AlphaZeroNet,
    device: torch.device,
    simulations: int,
    cpuct: float,
    max_moves: int,
) -> int:
    state = env.initial_state()
    move_num = 0
    while (not env.is_terminal(state)) and move_num < max_moves:
        player = env.current_player(state)
        active_net = net_a if player == 1 else net_b
        mcts = MCTS(
            env,
            action_space,
            active_net,
            device,
            simulations=simulations,
            cpuct=cpuct,
            dirichlet_alpha=0.3,
            dirichlet_eps=0.0,
        )
        policy = mcts.run(state, to_play=player, training=False)
        action_id = int(np.argmax(policy))
        action = action_space.actions[action_id]
        state = env.next_state(state, action)
        move_num += 1

    if env.is_terminal(state):
        return int(state.state["winner"])
    p1_lp = state.state["players"]["1"]["lp"]
    p2_lp = state.state["players"]["2"]["lp"]
    return 1 if p1_lp >= p2_lp else 2


def evaluate_candidate(
    env: PsychometricDuelEnv,
    action_space: ActionSpace,
    candidate: AlphaZeroNet,
    best: AlphaZeroNet,
    device: torch.device,
    simulations: int,
    cpuct: float,
    games: int,
    max_moves: int,
) -> float:
    wins = 0
    for g in range(games):
        if g % 2 == 0:
            winner = play_match(env, action_space, candidate, best, device, simulations, cpuct, max_moves)
            wins += int(winner == 1)
        else:
            winner = play_match(env, action_space, best, candidate, device, simulations, cpuct, max_moves)
            wins += int(winner == 2)
    return wins / max(1, games)


def save_checkpoint(path: Path, model: AlphaZeroNet, optimizer: torch.optim.Optimizer, metadata: Dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    torch.save({"model": model.state_dict(), "optimizer": optimizer.state_dict(), "metadata": metadata}, path)


def write_metadata(path: Path, metadata: Dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    safe_metadata = make_json_safe(metadata)
    with path.open("w", encoding="utf-8") as f:
        json.dump(safe_metadata, f, indent=2, sort_keys=True, default=_json_default)


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Train AlphaZero-style model for Psychometric Duel.")
    p.add_argument("--iterations", type=int, default=20)
    p.add_argument("--episodes-per-iter", type=int, default=8)
    p.add_argument("--simulations", type=int, default=100, help="MCTS simulations per move")
    p.add_argument("--learning-rate", type=float, default=1e-3)
    p.add_argument("--replay-size", type=int, default=20000)
    p.add_argument("--batch-size", type=int, default=64)
    p.add_argument("--epochs", type=int, default=2)
    p.add_argument("--checkpoint-frequency", type=int, default=1)
    p.add_argument("--evaluation-games", type=int, default=20)
    p.add_argument("--gating-threshold", type=float, default=0.55)
    p.add_argument("--cpuct", type=float, default=1.25)
    p.add_argument("--temp-opening-moves", type=int, default=12)
    p.add_argument("--temperature", type=float, default=1.0)
    p.add_argument("--dirichlet-alpha", type=float, default=0.3)
    p.add_argument("--dirichlet-eps", type=float, default=0.25)
    p.add_argument("--max-game-moves", type=int, default=300)
    p.add_argument("--seed", type=int, default=7)
    p.add_argument("--hidden-dim", type=int, default=256)
    p.add_argument("--output-dir", type=Path, default=Path("checkpoints"))
    p.add_argument("--device", type=str, default="cpu")
    p.add_argument("--verbose", action="store_true")
    return p.parse_args()


def main() -> None:
    args = parse_args()
    configure_logging(args.verbose)
    set_global_seed(args.seed)

    device = torch.device(args.device)
    env = PsychometricDuelEnv()
    action_space = ActionSpace()

    input_dim = int(encode_state(env.initial_state(), 1).shape[0])
    net = AlphaZeroNet(input_dim=input_dim, action_dim=action_space.size(), hidden_dim=args.hidden_dim).to(device)
    best_net = AlphaZeroNet(input_dim=input_dim, action_dim=action_space.size(), hidden_dim=args.hidden_dim).to(device)
    best_net.load_state_dict(net.state_dict())

    optimizer = torch.optim.Adam(net.parameters(), lr=args.learning_rate)
    replay: Deque[Sample] = deque(maxlen=args.replay_size)

    metadata = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "seed": args.seed,
        "hyperparameters": vars(args),
        "history": [],
        "expectations": {
            "primary_target": "Candidate model reaches >=55% win rate in gating matches before promotion.",
            "benchmark_target": "After training, verify >=60% win rate versus a baseline random-prior MCTS agent over 500 games.",
        },
    }

    for it in range(1, args.iterations + 1):
        mcts = MCTS(
            env,
            action_space,
            net,
            device,
            simulations=args.simulations,
            cpuct=args.cpuct,
            dirichlet_alpha=args.dirichlet_alpha,
            dirichlet_eps=args.dirichlet_eps,
        )

        winners = []
        for _ in range(args.episodes_per_iter):
            episode, winner = self_play_episode(env, mcts, action_space, args.temp_opening_moves, args.temperature, args.max_game_moves)
            replay.extend(episode)
            winners.append(winner)

        if len(replay) == 0:
            continue

        losses = {"policy_loss": 0.0, "value_loss": 0.0}
        for _ in range(args.epochs):
            losses = train_epoch(net, optimizer, list(replay), args.batch_size, device)

        win_rate = evaluate_candidate(
            env,
            action_space,
            candidate=net,
            best=best_net,
            device=device,
            simulations=args.simulations,
            cpuct=args.cpuct,
            games=args.evaluation_games,
            max_moves=args.max_game_moves,
        )

        promoted = False
        if win_rate >= args.gating_threshold:
            best_net.load_state_dict(net.state_dict())
            promoted = True

        record = {
            "iteration": it,
            "self_play_winners": winners,
            "replay_size": len(replay),
            "policy_loss": losses["policy_loss"],
            "value_loss": losses["value_loss"],
            "gating_win_rate": win_rate,
            "promoted": promoted,
        }
        metadata["history"].append(record)

        latest_ckpt = args.output_dir / "latest.pt"
        best_ckpt = args.output_dir / "best.pt"
        metadata_path = args.output_dir / "training_metadata.json"
        save_checkpoint(latest_ckpt, net, optimizer, metadata)
        if promoted:
            save_checkpoint(best_ckpt, best_net, optimizer, metadata)
        write_metadata(metadata_path, metadata)

        if it % args.checkpoint_frequency == 0:
            iter_path = args.output_dir / f"iter_{it:04d}.pt"
            save_checkpoint(iter_path, net, optimizer, metadata)

        LOGGER.info(
            "iter=%d replay=%d pol_loss=%.4f val_loss=%.4f gate_wr=%.3f promoted=%s",
            it,
            len(replay),
            losses["policy_loss"],
            losses["value_loss"],
            win_rate,
            promoted,
        )


if __name__ == "__main__":
    main()
