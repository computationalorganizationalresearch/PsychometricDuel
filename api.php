<?php
/*
 * Validity Wars: Power Protocol — Multiplayer API
 * Game state stored in text files named {room_code}.txt
 * Each file contains JSON with full game state.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$DATA_DIR = __DIR__ . '/rooms/';
if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0777, true);

$MONSTER_SPRITES = ['⚔️','🐉','🦁','🔥','💎','🌟','🗡️','🛡️','👁️','🌀'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['action'])) {
    respond(false, 'Missing action');
}

$action = $input['action'];

switch ($action) {
    case 'create_room': createRoom(); break;
    case 'join_room':   joinRoom($input); break;
    case 'poll':        pollState($input); break;
    case 'submit_move': submitMove($input); break;
    default: respond(false, 'Unknown action');
}

// ========== RESPONSES ==========
function respond($success, $msg = '', $data = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== ROOM MANAGEMENT ==========
function generateRoomCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

function generateToken() {
    return bin2hex(random_bytes(16));
}

function getRoomPath($code) {
    global $DATA_DIR;
    return $DATA_DIR . preg_replace('/[^A-Z0-9]/', '', $code) . '.txt';
}

function loadRoom($code) {
    $path = getRoomPath($code);
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'r');
    flock($fp, LOCK_SH);
    $data = json_decode(file_get_contents($path), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

function saveRoom($code, $state) {
    $path = getRoomPath($code);
    $fp = fopen($path, 'c');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ========== PSYCHOMETRICS DATA ==========
function getConstructs() {
    return [
        'cog_ability' => ['name'=>'Cognitive Ability','type'=>'predictor','short'=>'COG','avgR'=>0.65,'sprite'=>'🧠',
            'indicators'=>[['name'=>'GMA Test','r'=>0.67],['name'=>'Wonderlic','r'=>0.63],['name'=>"Raven's",'r'=>0.64]]],
        'conscient' => ['name'=>'Conscientiousness','type'=>'predictor','short'=>'CON','avgR'=>0.45,'sprite'=>'📋',
            'indicators'=>[['name'=>'NEO-PI-R C','r'=>0.47],['name'=>'BFI Consc.','r'=>0.43],['name'=>'HEXACO C','r'=>0.45]]],
        'struct_int' => ['name'=>'Struct. Interview','type'=>'predictor','short'=>'INT','avgR'=>0.55,'sprite'=>'🎤',
            'indicators'=>[['name'=>'Behavioral','r'=>0.56],['name'=>'Situational','r'=>0.53],['name'=>'Panel Rtg','r'=>0.55]]],
        'work_sample' => ['name'=>'Work Sample','type'=>'predictor','short'=>'WST','avgR'=>0.50,'sprite'=>'🔧',
            'indicators'=>[['name'=>'In-Basket','r'=>0.48],['name'=>'Role Play','r'=>0.52],['name'=>'Present.','r'=>0.50]]],
        'job_perf' => ['name'=>'Job Performance','type'=>'outcome','short'=>'PERF','avgR'=>0.52,'sprite'=>'⭐',
            'indicators'=>[['name'=>'Super. Rtg','r'=>0.54],['name'=>'Obj. Output','r'=>0.50],['name'=>'Peer Rtg','r'=>0.52]]],
        'turnover' => ['name'=>'Turnover','type'=>'outcome','short'=>'TURN','avgR'=>0.40,'sprite'=>'🚪',
            'indicators'=>[['name'=>'Intent Quit','r'=>0.42],['name'=>'Actual Quit','r'=>0.38],['name'=>'Absent','r'=>0.40]]],
        'job_sat' => ['name'=>'Job Satisfaction','type'=>'outcome','short'=>'SAT','avgR'=>0.48,'sprite'=>'😊',
            'indicators'=>[['name'=>'JDI Scale','r'=>0.50],['name'=>'MSQ Score','r'=>0.47],['name'=>'Faces Scale','r'=>0.47]]],
        'ocb' => ['name'=>'OCB','type'=>'outcome','short'=>'OCB','avgR'=>0.44,'sprite'=>'🤝',
            'indicators'=>[['name'=>'OCBI Items','r'=>0.46],['name'=>'OCBO Items','r'=>0.42],['name'=>'Altruism','r'=>0.44]]]
    ];
}

function getTrueValidity() {
    return [
        'cog_ability' => ['job_perf'=>0.51,'turnover'=>0.20,'job_sat'=>0.15,'ocb'=>0.12],
        'conscient'   => ['job_perf'=>0.31,'turnover'=>0.26,'job_sat'=>0.25,'ocb'=>0.30],
        'struct_int'  => ['job_perf'=>0.51,'turnover'=>0.22,'job_sat'=>0.18,'ocb'=>0.15],
        'work_sample' => ['job_perf'=>0.54,'turnover'=>0.15,'job_sat'=>0.12,'ocb'=>0.10]
    ];
}

function getMonsterNames() {
    return [
        'cog_ability' => ['job_perf'=>'Sapient Performer','turnover'=>'Logic Guardian','job_sat'=>"Mind's Content",'ocb'=>'Brilliant Helper'],
        'conscient'   => ['job_perf'=>'Diligent Titan','turnover'=>'Steadfast Anchor','job_sat'=>'Dutiful Spirit','ocb'=>'Noble Worker'],
        'struct_int'  => ['job_perf'=>'Interview Sage','turnover'=>'Vetting Sentinel','job_sat'=>'Rapport Weaver','ocb'=>'Dialogue Knight'],
        'work_sample' => ['job_perf'=>'Craft Master','turnover'=>'Task Binder','job_sat'=>'Skilled Spirit','ocb'=>'Practice Hero']
    ];
}

// ========== MATH ==========
function spearmanBrown($k, $avgR) {
    if ($k <= 0) return 0;
    return ($k * $avgR) / (1 + ($k - 1) * $avgR);
}

function calcObservedValidity($rTrue, $relX, $relY) {
    return $rTrue * sqrt($relX) * sqrt($relY);
}

function calcPower($rObs, $n) {
    if ($n <= 3) return 0.05;
    $z_r = 0.5 * log((1 + $rObs) / (1 - $rObs));
    $z = $z_r * sqrt($n - 3);
    $x = $z - 1.96;
    $p = 1 / (1 + exp(-1.702 * $x));
    return min(0.99, max(0.05, $p));
}

// ========== DECK BUILDING ==========
function buildDeck() {
    $constructs = getConstructs();
    $deck = [];

    // 2 copies of each indicator
    for ($i = 0; $i < 2; $i++) {
        foreach ($constructs as $cKey => $c) {
            foreach ($c['indicators'] as $ind) {
                $deck[] = [
                    'isItem' => true, 'constructId' => $cKey,
                    'construct' => $c['name'], 'type' => $c['type'],
                    'indicator' => $ind['name'], 'avgR' => $c['avgR'],
                    'itemR' => $ind['r'], 'short' => $c['short'], 'sprite' => $c['sprite']
                ];
            }
        }
    }

    // Spells
    $spells = [
        ['isItem'=>false,'id'=>'missing_data','type'=>'spell','name'=>'Missing Data','icon'=>'❓','desc'=>'Destroy ANY card on the field.'],
    ];
    for ($i = 0; $i < 2; $i++) {
        $spells[] = ['isItem'=>false,'id'=>'correction','type'=>'spell','name'=>'Attenuation Corr.','icon'=>'✨','desc'=>'Target Your Monster. Restores ATK to True Validity.'];
        $spells[] = ['isItem'=>false,'id'=>'range_restrict','type'=>'trap','name'=>'Range Restriction','icon'=>'📉','desc'=>'Target Enemy Monster. Halves ATK power.'];
        $spells[] = ['isItem'=>false,'id'=>'replication','type'=>'spell','name'=>'Replication','icon'=>'👯','desc'=>'Target Your Monster. Can attack twice. Preserves N.'];
    }
    $deck = array_merge($deck, $spells);

    // Sample Size cards
    for ($i = 0; $i < 25; $i++) {
        $deck[] = ['isItem'=>false,'id'=>'sample_size','type'=>'resource','name'=>'Sample Size','icon'=>'🧑‍🤝‍🧑','desc'=>'Add +100 N to a monster.','n_val'=>100];
    }

    shuffle($deck);
    return $deck;
}

// ========== CREATE ROOM ==========
function createRoom() {
    $code = generateRoomCode();
    while (file_exists(getRoomPath($code))) $code = generateRoomCode();

    $token1 = generateToken();
    $deck1 = buildDeck();
    $deck2 = buildDeck();

    $hand1 = array_splice($deck1, -5);
    $hand2 = array_splice($deck2, -5);

    $state = [
        'room_code'    => $code,
        'status'       => 'waiting', // waiting | active | finished
        'player1_token'=> $token1,
        'player2_token'=> null,
        'current_turn' => 1,
        'turn_number'  => 0,
        'winner'       => null,
        'last_update'  => time(),
        'player1' => [
            'lp' => 8000, 'hand' => $hand1, 'deck' => $deck1,
            'constructs' => [null,null,null], 'monsters' => [null,null,null],
            'summoned_this_turn' => false
        ],
        'player2' => [
            'lp' => 8000, 'hand' => $hand2, 'deck' => $deck2,
            'constructs' => [null,null,null], 'monsters' => [null,null,null],
            'summoned_this_turn' => false
        ],
        'log' => [],
        'pending_events' => [] // for attack results, dice rolls etc.
    ];

    saveRoom($code, $state);
    respond(true, 'Room created', ['room_code' => $code, 'player_token' => $token1, 'player_num' => 1]);
}

// ========== JOIN ROOM ==========
function joinRoom($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    if (strlen($code) !== 10) respond(false, 'Invalid room code');

    $state = loadRoom($code);
    if (!$state) respond(false, 'Room not found');
    if ($state['status'] !== 'waiting') respond(false, 'Room is full or game already started');

    $token2 = generateToken();
    $state['player2_token'] = $token2;
    $state['status'] = 'active';
    $state['turn_number'] = 1;
    $state['last_update'] = time();

    // Reset attack counters for turn start
    resetMonstersForTurn($state['player1']);

    // Player 1 draws 2 cards for their first turn
    for ($i = 0; $i < 2; $i++) {
        if (count($state['player1']['deck']) > 0 && count($state['player1']['hand']) < 8) {
            $state['player1']['hand'][] = array_pop($state['player1']['deck']);
        }
    }

    $state['log'][] = ['msg' => 'Opponent has joined! Game Start!', 'type' => 'info-log'];

    saveRoom($code, $state);
    respond(true, 'Joined room', ['room_code' => $code, 'player_token' => $token2, 'player_num' => 2]);
}

// ========== POLL STATE ==========
function pollState($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    $token = $input['player_token'] ?? '';

    $state = loadRoom($code);
    if (!$state) respond(false, 'Room not found');

    $pNum = getPlayerNum($state, $token);
    if (!$pNum) respond(false, 'Invalid token');

    $filtered = filterStateForPlayer($state, $pNum);
    respond(true, '', ['state' => $filtered]);
}

// ========== SUBMIT MOVE ==========
function submitMove($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    $token = $input['player_token'] ?? '';
    $move = $input['move'] ?? null;

    if (!$move) respond(false, 'No move provided');

    $path = getRoomPath($code);
    if (!file_exists($path)) respond(false, 'Room not found');

    // Lock file for exclusive access during move processing
    $fp = fopen($path, 'c+');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = json_decode($raw, true);

    if (!$state) { flock($fp, LOCK_UN); fclose($fp); respond(false, 'Corrupt state'); }

    $pNum = getPlayerNum($state, $token);
    if (!$pNum) { flock($fp, LOCK_UN); fclose($fp); respond(false, 'Invalid token'); }
    if ($state['status'] !== 'active') { flock($fp, LOCK_UN); fclose($fp); respond(false, 'Game not active'); }
    if ($state['current_turn'] !== $pNum) { flock($fp, LOCK_UN); fclose($fp); respond(false, 'Not your turn'); }

    $me = &$state['player' . $pNum];
    $opp = &$state['player' . ($pNum === 1 ? 2 : 1)];

    $result = processMove($state, $me, $opp, $pNum, $move);

    $state['last_update'] = time();

    // Check win conditions
    if ($me['lp'] <= 0) {
        $state['status'] = 'finished';
        $state['winner'] = ($pNum === 1 ? 2 : 1);
        $state['log'][] = ['msg' => 'Player ' . $pNum . ' has been defeated!', 'type' => 'battle-log'];
    }
    if ($opp['lp'] <= 0) {
        $state['status'] = 'finished';
        $state['winner'] = $pNum;
        $state['log'][] = ['msg' => 'Player ' . ($pNum === 1 ? 2 : 1) . ' has been defeated!', 'type' => 'battle-log'];
    }

    // Write back
    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $filtered = filterStateForPlayer($state, $pNum);
    respond(true, $result['msg'] ?? 'OK', ['state' => $filtered, 'move_result' => $result]);
}

// ========== MOVE PROCESSING ==========
function processMove(&$state, &$me, &$opp, $pNum, $move) {
    $type = $move['type'] ?? '';

    switch ($type) {
        case 'place_card':     return movePlaceCard($state, $me, $pNum, $move);
        case 'summon':         return moveSummon($state, $me, $pNum, $move);
        case 'play_spell':     return movePlaySpell($state, $me, $opp, $pNum, $move);
        case 'attack':         return moveAttack($state, $me, $opp, $pNum, $move);
        case 'meta':           return moveMeta($state, $me, $pNum, $move);
        case 'end_turn':       return moveEndTurn($state, $me, $opp, $pNum);
        default: return ['msg' => 'Unknown move type', 'ok' => false];
    }
}

function movePlaceCard(&$state, &$me, $pNum, $move) {
    $handIdx = $move['hand_index'] ?? -1;
    $slotIdx = $move['slot'] ?? -1;

    if ($handIdx < 0 || $handIdx >= count($me['hand'])) return ['msg' => 'Invalid hand index', 'ok' => false];
    if ($slotIdx < 0 || $slotIdx > 2) return ['msg' => 'Invalid slot', 'ok' => false];

    $card = $me['hand'][$handIdx];
    if (!$card['isItem']) return ['msg' => 'Not an item card', 'ok' => false];

    $existing = $me['constructs'][$slotIdx];
    if ($existing && $existing['constructId'] !== $card['constructId']) return ['msg' => 'Construct mismatch', 'ok' => false];
    if ($existing && count($existing['cards']) >= 3) return ['msg' => 'Slot full', 'ok' => false];

    if (!$existing) {
        $me['constructs'][$slotIdx] = [
            'constructId' => $card['constructId'],
            'type' => $card['type'],
            'cards' => [$card]
        ];
    } else {
        $me['constructs'][$slotIdx]['cards'][] = $card;
    }

    array_splice($me['hand'], $handIdx, 1);
    $state['log'][] = ['msg' => "P{$pNum} placed {$card['indicator']} on construct slot.", 'type' => 'info-log'];
    return ['msg' => 'Card placed', 'ok' => true];
}

function moveSummon(&$state, &$me, $pNum, $move) {
    $predSlot = $move['pred_slot'] ?? -1;
    $outSlot = $move['out_slot'] ?? -1;

    if ($me['summoned_this_turn']) return ['msg' => 'Already summoned this turn', 'ok' => false];

    $pred = $me['constructs'][$predSlot] ?? null;
    $out = $me['constructs'][$outSlot] ?? null;
    if (!$pred || $pred['type'] !== 'predictor') return ['msg' => 'Invalid predictor', 'ok' => false];
    if (!$out || $out['type'] !== 'outcome') return ['msg' => 'Invalid outcome', 'ok' => false];

    $mSlot = -1;
    for ($i = 0; $i < 3; $i++) { if ($me['monsters'][$i] === null) { $mSlot = $i; break; } }
    if ($mSlot === -1) return ['msg' => 'No monster slot available', 'ok' => false];

    $tv = getTrueValidity();
    $mn = getMonsterNames();

    $relP = spearmanBrown(count($pred['cards']), $pred['cards'][0]['avgR']);
    $relO = spearmanBrown(count($out['cards']), $out['cards'][0]['avgR']);
    $rTrue = $tv[$pred['constructId']][$out['constructId']];
    $rObs = calcObservedValidity($rTrue, $relP, $relO);
    $atk = floor($rObs * 10000);
    $name = $mn[$pred['constructId']][$out['constructId']];
    $baseN = 20;
    $power = calcPower($rObs, $baseN);

    global $MONSTER_SPRITES;
    $me['monsters'][$mSlot] = [
        'name' => $name, 'atk' => $atk,
        'predId' => $pred['constructId'], 'outId' => $out['constructId'],
        'relP' => $relP, 'relO' => $relO,
        'rTrue' => $rTrue, 'rObs' => $rObs,
        'n' => $baseN, 'baseN' => $baseN, 'power' => $power,
        'attacksMade' => 1, 'maxAttacks' => 1,
        'hasReplication' => false,
        'sprite' => $MONSTER_SPRITES[array_rand($MONSTER_SPRITES)],
        'corrected' => false, 'isMeta' => false
    ];

    $me['constructs'][$predSlot] = null;
    $me['constructs'][$outSlot] = null;
    $me['summoned_this_turn'] = true;

    $state['log'][] = ['msg' => "P{$pNum} summoned {$name}! ATK: {$atk}", 'type' => 'info-log'];
    return ['msg' => "Summoned {$name}", 'ok' => true];
}

function movePlaySpell(&$state, &$me, &$opp, $pNum, $move) {
    $handIdx = $move['hand_index'] ?? -1;
    $targetOwner = $move['target_owner'] ?? ''; // 'me' or 'opp'
    $targetType = $move['target_type'] ?? '';    // 'monster' or 'construct'
    $targetSlot = $move['target_slot'] ?? -1;

    if ($handIdx < 0 || $handIdx >= count($me['hand'])) return ['msg' => 'Invalid hand index', 'ok' => false];

    $card = $me['hand'][$handIdx];
    if ($card['isItem']) return ['msg' => 'Not a spell card', 'ok' => false];

    $target = &$me;
    if ($targetOwner === 'opp') $target = &$opp;

    $spellId = $card['id'];

    switch ($spellId) {
        case 'missing_data':
            if ($targetType === 'construct') {
                $c = &$target['constructs'][$targetSlot];
                if (!$c) return ['msg' => 'No construct there', 'ok' => false];
                array_pop($c['cards']);
                if (count($c['cards']) === 0) $target['constructs'][$targetSlot] = null;
                $state['log'][] = ['msg' => "P{$pNum} used Missing Data on a construct!", 'type' => 'spell-log'];
            } else {
                if (!$target['monsters'][$targetSlot]) return ['msg' => 'No monster there', 'ok' => false];
                $target['monsters'][$targetSlot] = null;
                $state['log'][] = ['msg' => "P{$pNum} used Missing Data to destroy a monster!", 'type' => 'spell-log'];
            }
            break;

        case 'range_restrict':
            if ($targetOwner !== 'opp' || $targetType !== 'monster') return ['msg' => 'Invalid target', 'ok' => false];
            $m = &$opp['monsters'][$targetSlot];
            if (!$m) return ['msg' => 'No monster', 'ok' => false];
            $m['atk'] = floor($m['atk'] / 2);
            $state['log'][] = ['msg' => "P{$pNum} used Range Restriction! ATK halved to {$m['atk']}.", 'type' => 'spell-log'];
            break;

        case 'correction':
            if ($targetOwner !== 'me' || $targetType !== 'monster') return ['msg' => 'Invalid target', 'ok' => false];
            $m = &$me['monsters'][$targetSlot];
            if (!$m || $m['isMeta']) return ['msg' => 'Invalid target', 'ok' => false];
            $m['atk'] = floor($m['rTrue'] * 10000);
            $m['rObs'] = $m['rTrue'];
            $m['corrected'] = true;
            $m['power'] = calcPower($m['rObs'], $m['n']);
            $state['log'][] = ['msg' => "P{$pNum} used Correction! ATK restored to {$m['atk']}.", 'type' => 'spell-log'];
            break;

        case 'sample_size':
            if ($targetOwner !== 'me' || $targetType !== 'monster') return ['msg' => 'Invalid target', 'ok' => false];
            $m = &$me['monsters'][$targetSlot];
            if (!$m) return ['msg' => 'No monster', 'ok' => false];
            $m['n'] += 100;
            $m['power'] = calcPower($m['rObs'], $m['n']);
            $pwr = floor($m['power'] * 100);
            $state['log'][] = ['msg' => "P{$pNum} added Sample Size! N={$m['n']} (Power: {$pwr}%)", 'type' => 'spell-log'];
            break;

        case 'replication':
            if ($targetOwner !== 'me' || $targetType !== 'monster') return ['msg' => 'Invalid target', 'ok' => false];
            $m = &$me['monsters'][$targetSlot];
            if (!$m) return ['msg' => 'No monster', 'ok' => false];
            $m['maxAttacks'] += 1;
            $m['hasReplication'] = true;
            $state['log'][] = ['msg' => "P{$pNum} used Replication! Monster can attack twice.", 'type' => 'spell-log'];
            break;

        default:
            return ['msg' => 'Unknown spell', 'ok' => false];
    }

    array_splice($me['hand'], $handIdx, 1);
    return ['msg' => 'Spell played', 'ok' => true];
}

function moveAttack(&$state, &$me, &$opp, $pNum, $move) {
    $atkSlot = $move['attacker_slot'] ?? -1;
    $defType = $move['target_type'] ?? '';  // 'monster' or 'lp'
    $defSlot = $move['target_slot'] ?? -1;

    $atk = &$me['monsters'][$atkSlot];
    if (!$atk) return ['msg' => 'No attacker', 'ok' => false];
    if ($atk['attacksMade'] >= $atk['maxAttacks']) return ['msg' => 'Monster already attacked', 'ok' => false];

    // D20 roll
    $roll = random_int(1, 20);
    $threshold = max(1, round($atk['power'] * 20));
    $hit = $roll <= $threshold;

    $result = [
        'ok' => true,
        'roll' => $roll,
        'threshold' => $threshold,
        'hit' => $hit,
        'attack_data' => []
    ];

    if (!$hit) {
        // Type II Error
        $atk['attacksMade']++;
        if ($atk['hasReplication']) {
            $atk['hasReplication'] = false;
            $state['log'][] = ['msg' => "{$atk['name']} TYPE II ERROR! (Rolled {$roll}, needed ≤{$threshold}). Replication preserved N.", 'type' => 'error-log'];
            $result['preserved_n'] = true;
        } else {
            $atk['n'] = $atk['baseN'];
            $atk['power'] = calcPower($atk['rObs'], $atk['baseN']);
            $state['log'][] = ['msg' => "{$atk['name']} TYPE II ERROR! (Rolled {$roll}, needed ≤{$threshold}). N resets to {$atk['baseN']}.", 'type' => 'error-log'];
            $result['preserved_n'] = false;
        }
        $result['msg'] = 'Type II Error! Attack missed.';
        return $result;
    }

    // HIT!
    $state['log'][] = ['msg' => "{$atk['name']} achieved significance! (Rolled {$roll} ≤ {$threshold})", 'type' => 'formula-log'];

    if ($defType === 'monster') {
        $def = &$opp['monsters'][$defSlot];
        if (!$def) {
            // Direct attack if target gone
            $defType = 'lp';
        }
    }

    if ($defType === 'monster') {
        $def = &$opp['monsters'][$defSlot];
        $diff = $atk['atk'] - $def['atk'];
        $result['attack_data'] = ['diff' => $diff, 'atk_atk' => $atk['atk'], 'def_atk' => $def['atk'], 'def_name' => $def['name']];

        if ($diff > 0) {
            $opp['monsters'][$defSlot] = null;
            $opp['lp'] = max(0, $opp['lp'] - $diff);
            $state['log'][] = ['msg' => "{$atk['name']} ({$atk['atk']}) destroys {$def['name']} ({$def['atk']})! {$diff} damage!", 'type' => 'battle-log'];
            $result['attack_data']['outcome'] = 'win';
            $result['attack_data']['damage'] = $diff;
        } elseif ($diff < 0) {
            $me['monsters'][$atkSlot] = null;
            $me['lp'] = max(0, $me['lp'] - abs($diff));
            $state['log'][] = ['msg' => "{$atk['name']} ({$atk['atk']}) destroyed by {$def['name']} ({$def['atk']})! " . abs($diff) . " damage!", 'type' => 'battle-log'];
            $result['attack_data']['outcome'] = 'lose';
            $result['attack_data']['damage'] = abs($diff);
            // attacker destroyed, skip consumption
            return $result;
        } else {
            $me['monsters'][$atkSlot] = null;
            $opp['monsters'][$defSlot] = null;
            $state['log'][] = ['msg' => "Mutual destruction! Both had {$atk['atk']} ATK.", 'type' => 'battle-log'];
            $result['attack_data']['outcome'] = 'draw';
            return $result;
        }
    } else {
        // Direct LP attack
        $opp['lp'] = max(0, $opp['lp'] - $atk['atk']);
        $state['log'][] = ['msg' => "{$atk['name']} attacks directly for {$atk['atk']}!", 'type' => 'battle-log'];
        $result['attack_data'] = ['outcome' => 'direct', 'damage' => $atk['atk']];
    }

    // Consume data (if attacker survived)
    if ($me['monsters'][$atkSlot]) {
        $me['monsters'][$atkSlot]['attacksMade']++;
        if ($me['monsters'][$atkSlot]['hasReplication']) {
            $me['monsters'][$atkSlot]['hasReplication'] = false;
            $state['log'][] = ['msg' => "Replication preserved N={$atk['n']}.", 'type' => 'spell-log'];
            $result['preserved_n'] = true;
        } else {
            $me['monsters'][$atkSlot]['n'] = $me['monsters'][$atkSlot]['baseN'];
            $me['monsters'][$atkSlot]['power'] = calcPower($me['monsters'][$atkSlot]['rObs'], $me['monsters'][$atkSlot]['baseN']);
            $state['log'][] = ['msg' => "Data consumed. N resets to {$me['monsters'][$atkSlot]['baseN']}.", 'type' => 'info-log'];
            $result['preserved_n'] = false;
        }
    }

    $result['msg'] = 'Attack resolved';
    return $result;
}

function moveMeta(&$state, &$me, $pNum, $move) {
    $monsters = &$me['monsters'];
    if (!$monsters[0] || !$monsters[1] || !$monsters[2]) return ['msg' => 'Need 3 monsters', 'ok' => false];

    $target = null;
    if ($monsters[0]['predId'] === $monsters[1]['predId'] && $monsters[1]['predId'] === $monsters[2]['predId'] && $monsters[0]['predId'] !== 'META') {
        $target = ['type' => 'pred', 'id' => $monsters[0]['predId']];
    } elseif ($monsters[0]['outId'] === $monsters[1]['outId'] && $monsters[1]['outId'] === $monsters[2]['outId'] && $monsters[0]['outId'] !== 'META') {
        $target = ['type' => 'out', 'id' => $monsters[0]['outId']];
    }

    if (!$target) return ['msg' => 'No valid meta target', 'ok' => false];

    $constructs = getConstructs();
    $totalN = $monsters[0]['n'] + $monsters[1]['n'] + $monsters[2]['n'];
    $baseN = 60;
    $startN = max($baseN, $totalN);
    $avgRTrue = ($monsters[0]['rTrue'] + $monsters[1]['rTrue'] + $monsters[2]['rTrue']) / 3;
    $metaAtk = floor($avgRTrue * 10000);
    $name = "Meta-{$constructs[$target['id']]['short']} Titan";

    $metaMonster = [
        'name' => $name, 'atk' => $metaAtk,
        'predId' => $target['type'] === 'pred' ? $target['id'] : 'META',
        'outId' => $target['type'] === 'out' ? $target['id'] : 'META',
        'relP' => 1.0, 'relO' => 1.0,
        'rTrue' => $avgRTrue, 'rObs' => $avgRTrue,
        'n' => $startN, 'baseN' => $baseN,
        'power' => calcPower($avgRTrue, $startN),
        'attacksMade' => 0, 'maxAttacks' => 2,
        'hasReplication' => true,
        'sprite' => '🌌', 'corrected' => true, 'isMeta' => true
    ];

    $monsters[0] = null;
    $monsters[2] = null;
    $monsters[1] = $metaMonster;

    $state['log'][] = ['msg' => "P{$pNum} performed META-ANALYSIS! Summoned {$name} with {$metaAtk} ATK!", 'type' => 'meta-log'];
    return ['msg' => "Meta Analysis: {$name}", 'ok' => true, 'meta' => true];
}

function moveEndTurn(&$state, &$me, &$opp, $pNum) {
    $nextPlayer = $pNum === 1 ? 2 : 1;
    $state['current_turn'] = $nextPlayer;
    $state['turn_number']++;

    // Reset opponent monsters for their turn
    resetMonstersForTurn($opp);
    $opp['summoned_this_turn'] = false;

    // Opponent draws 2 cards
    for ($i = 0; $i < 2; $i++) {
        if (count($opp['deck']) > 0 && count($opp['hand']) < 8) {
            $opp['hand'][] = array_pop($opp['deck']);
        }
    }

    $state['log'][] = ['msg' => "P{$pNum} ended their turn.", 'type' => 'info-log'];
    return ['msg' => 'Turn ended', 'ok' => true, 'turn_ended' => true];
}

function resetMonstersForTurn(&$player) {
    for ($i = 0; $i < 3; $i++) {
        $m = &$player['monsters'][$i];
        if ($m) {
            $m['attacksMade'] = 0;
            $m['maxAttacks'] = $m['isMeta'] ? 2 : 1;
            if ($m['isMeta']) $m['hasReplication'] = true;
        }
    }
}

// ========== HELPERS ==========
function getPlayerNum($state, $token) {
    if ($state['player1_token'] === $token) return 1;
    if ($state['player2_token'] === $token) return 2;
    return null;
}

function filterStateForPlayer($state, $pNum) {
    $oppNum = $pNum === 1 ? 2 : 1;
    $meKey = 'player' . $pNum;
    $oppKey = 'player' . $oppNum;

    return [
        'room_code'    => $state['room_code'],
        'status'       => $state['status'],
        'is_my_turn'   => $state['current_turn'] === $pNum,
        'my_player_num'=> $pNum,
        'turn_number'  => $state['turn_number'],
        'winner'       => $state['winner'],
        'i_won'        => $state['winner'] === $pNum,

        'my_lp'        => $state[$meKey]['lp'],
        'my_hand'      => $state[$meKey]['hand'],
        'my_deck_count'=> count($state[$meKey]['deck']),
        'my_constructs'=> $state[$meKey]['constructs'],
        'my_monsters'  => $state[$meKey]['monsters'],
        'my_summoned'  => $state[$meKey]['summoned_this_turn'],

        'opp_lp'        => $state[$oppKey]['lp'],
        'opp_hand_count'=> count($state[$oppKey]['hand']),
        'opp_deck_count'=> count($state[$oppKey]['deck']),
        'opp_constructs'=> $state[$oppKey]['constructs'],
        'opp_monsters'  => $state[$oppKey]['monsters'],

        'log'           => $state['log']
    ];
}
