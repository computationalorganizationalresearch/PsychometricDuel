<?php
/*
 * Validity Wars: Power Protocol — Multiplayer API
 * State is persisted in /rooms/{room_code}.txt as JSON.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$DATA_DIR = __DIR__ . '/rooms/';
if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0777, true);

const MAX_HAND_SIZE = 12;
const STARTING_HAND_SIZE = 12;
const EXPERIENCE_MISS_THRESHOLD = 4;
const EXPERIENCE_DRAW_COUNT = 3;
const MONSTER_BASE_N = 50;
const META_BASE_N = 50;

$MONSTER_SPRITES = ['⚔️','🐉','🦁','🔥','💎','🌟','🗡️','🛡️','👁️','🌀'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['action'])) respond(false, 'Missing action');

switch ($input['action']) {
    case 'create_room': createRoom(); break;
    case 'join_room': joinRoom($input); break;
    case 'poll': pollState($input); break;
    case 'submit_move': submitMove($input); break;
    case 'submit_mulligan': submitMulligan($input); break;
    default: respond(false, 'Unknown action');
}

function respond($success, $msg = '', $data = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $msg], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function clamp($v, $lo, $hi) { return max($lo, min($hi, $v)); }

function generateRoomCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

function generateToken() { return bin2hex(random_bytes(16)); }

function getRoomPath($code) {
    global $DATA_DIR;
    return $DATA_DIR . preg_replace('/[^A-Z0-9]/', '', $code) . '.txt';
}

function loadRoom($code) {
    $path = getRoomPath($code);
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function saveRoom($code, $state) {
    file_put_contents(getRoomPath($code), json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function withLockedRoom($code, $callback) {
    $path = getRoomPath($code);
    if (!file_exists($path)) respond(false, 'Room not found');

    $fp = fopen($path, 'c+');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = json_decode($raw, true);
    if (!$state) { flock($fp, LOCK_UN); fclose($fp); respond(false, 'Corrupt state'); }

    $result = $callback($state);

    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return [$state, $result];
}

function getConstructs() {
    return [
        'cog_ability' => ['name'=>'Cognitive Ability','type'=>'predictor','short'=>'COG','avgR'=>0.65,'sprite'=>'🧠','indicators'=>[['name'=>'GMA Test','r'=>0.67],['name'=>'Wonderlic','r'=>0.63],['name'=>"Raven's",'r'=>0.64]]],
        'conscient' => ['name'=>'Conscientiousness','type'=>'predictor','short'=>'CON','avgR'=>0.45,'sprite'=>'📋','indicators'=>[['name'=>'NEO-PI-R C','r'=>0.47],['name'=>'BFI Consc.','r'=>0.43],['name'=>'HEXACO C','r'=>0.45]]],
        'struct_int' => ['name'=>'Struct. Interview','type'=>'predictor','short'=>'INT','avgR'=>0.55,'sprite'=>'🎤','indicators'=>[['name'=>'Behavioral','r'=>0.56],['name'=>'Situational','r'=>0.53],['name'=>'Panel Rtg','r'=>0.55]]],
        'work_sample' => ['name'=>'Work Sample','type'=>'predictor','short'=>'WST','avgR'=>0.50,'sprite'=>'🔧','indicators'=>[['name'=>'In-Basket','r'=>0.48],['name'=>'Role Play','r'=>0.52],['name'=>'Present.','r'=>0.50]]],
        'job_perf' => ['name'=>'Job Performance','type'=>'outcome','short'=>'PERF','avgR'=>0.52,'sprite'=>'⭐','indicators'=>[['name'=>'Super. Rtg','r'=>0.54],['name'=>'Obj. Output','r'=>0.50],['name'=>'Peer Rtg','r'=>0.52]]],
        'turnover' => ['name'=>'Turnover','type'=>'outcome','short'=>'TURN','avgR'=>0.40,'sprite'=>'🚪','indicators'=>[['name'=>'Intent Quit','r'=>0.42],['name'=>'Actual Quit','r'=>0.38],['name'=>'Absent','r'=>0.40]]],
        'job_sat' => ['name'=>'Job Satisfaction','type'=>'outcome','short'=>'SAT','avgR'=>0.48,'sprite'=>'😊','indicators'=>[['name'=>'JDI Scale','r'=>0.50],['name'=>'MSQ Score','r'=>0.47],['name'=>'Faces Scale','r'=>0.47]]],
        'ocb' => ['name'=>'OCB','type'=>'outcome','short'=>'OCB','avgR'=>0.44,'sprite'=>'🤝','indicators'=>[['name'=>'OCBI Items','r'=>0.46],['name'=>'OCBO Items','r'=>0.42],['name'=>'Altruism','r'=>0.44]]]
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


function getAdverseImpactBwd() {
    return [
        // Combo-specific gameplay tuning: Job Performance pairings are intentionally a bit harsher.
        'cog_ability' => ['job_perf'=>0.95,'turnover'=>0.60,'job_sat'=>0.58,'ocb'=>0.55],
        'conscient'   => ['job_perf'=>0.20,'turnover'=>0.05,'job_sat'=>0.05,'ocb'=>0.05],
        'struct_int'  => ['job_perf'=>0.35,'turnover'=>0.22,'job_sat'=>0.22,'ocb'=>0.22],
        'work_sample' => ['job_perf'=>0.55,'turnover'=>0.40,'job_sat'=>0.40,'ocb'=>0.40]
    ];
}

function adverseImpactStarsFromBwd($rawBwd) {
    $d = abs($rawBwd ?? 0);
    if ($d <= 0.10) return 5;
    if ($d <= 0.25) return 4;
    if ($d <= 0.45) return 3;
    if ($d <= 0.65) return 2;
    return 1;
}

function requiresJobRelevanceFromStars($stars) {
    return $stars <= 3;
}

function getPairAdverseImpact($predId, $outId) {
    $map = getAdverseImpactBwd();
    $bwd = $map[$predId][$outId] ?? 0.3;
    $stars = adverseImpactStarsFromBwd($bwd);
    return [
        'bwd' => $bwd,
        'stars' => $stars,
        'requiresJobRelevance' => requiresJobRelevanceFromStars($stars),
        'starsText' => str_repeat('★', $stars) . str_repeat('☆', max(0, 5 - $stars))
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

function spearmanBrown($k, $avgR) {
    if ($k <= 0) return 0;
    return ($k * $avgR) / (1 + ($k - 1) * $avgR);
}

function approxPowerFromROBSandN($rObs, $n) {
    $alpha = 0.05;
    $sampleN = max(4, (int)floor($n ?? 0));
    $r = clamp(abs($rObs), 0, 0.999999);
    $fisherZ = 0.5 * log((1 + $r) / (1 - $r));
    $nonCentrality = $fisherZ * sqrt($sampleN - 3);
    $zCritical = inverseNormalCdf(1 - $alpha / 2);
    $beta = normalCdf($zCritical - $nonCentrality) - normalCdf(-$zCritical - $nonCentrality);
    return clamp(1 - $beta, 0.05, 0.99);
}

function normalCdf($z) {
    $t = 1 / (1 + 0.2316419 * abs($z));
    $d = 0.3989423 * exp(-$z * $z / 2);
    $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));
    return $z >= 0 ? 1 - $p : $p;
}

function inverseNormalCdf($p) {
    $a = [-39.6968302866538, 220.946098424521, -275.928510446969, 138.357751867269, -30.6647980661472, 2.50662827745924];
    $b = [-54.4760987982241, 161.585836858041, -155.698979859887, 66.8013118877197, -13.2806815528857];
    $c = [-0.00778489400243029, -0.322396458041136, -2.40075827716184, -2.54973253934373, 4.37466414146497, 2.93816398269878];
    $d = [0.00778469570904146, 0.32246712907004, 2.445134137143, 3.75440866190742];
    $low = 0.02425;
    $high = 1 - $low;
    $pp = clamp((float)$p, 1e-12, 1 - 1e-12);

    if ($pp < $low) {
        $q = sqrt(-2 * log($pp));
        return (((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / ((((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q) + 1));
    }

    if ($pp > $high) {
        $q = sqrt(-2 * log(1 - $pp));
        return -((((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / ((((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q) + 1)));
    }

    $q = $pp - 0.5;
    $r = $q * $q;
    return (((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q)
        / (((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r) + 1);
}

function getPowerValidityCoefficient($m) {
    if (!$m) return 0;
    return max(0, abs($m['atk'] ?? 0) / 10000);
}

function refreshMonsterStats(&$m) {
    if (!$m) return;
    if (!empty($m['isMeta'])) {
        $m['power'] = clamp(($m['power'] ?? 0.95), 0.7, 0.99);
        $m['atk'] = $m['baseAtk'];
        return;
    }

    $predAlpha = max(0.05, $m['predAlpha'] ?? 0.05);
    $outAlpha = max(0.05, $m['outAlpha'] ?? 0.05);
    $validityMultiplier = max(0, (float)($m['validityMultiplier'] ?? 1.0));
    $effectiveValidityMultiplier = !empty($m['itemLeakageApplied']) ? 0.0 : $validityMultiplier;
    $m['rObs'] = (($m['rTrue'] ?? 0) * sqrt($predAlpha * $outAlpha)) * $effectiveValidityMultiplier;
    $m['baseAtk'] = (int) round(abs($m['rObs']) * 10000);

    $correctionBaseAtk = (int) round(abs($m['rTrue'] ?? 0) * $effectiveValidityMultiplier * 10000);
    $effectiveAtkBase = !empty($m['correctionApplied']) ? $correctionBaseAtk : $m['baseAtk'];
    $rangeStacks = max(0, (int)($m['rangeRestrictionStacks'] ?? 0));
    $atk = $effectiveAtkBase;
    for ($i = 0; $i < $rangeStacks; $i++) {
        $atk = (int) round($atk / 2);
    }
    $m['atk'] = $atk;

    $m['power'] = approxPowerFromROBSandN(getPowerValidityCoefficient($m), $m['n'] ?? MONSTER_BASE_N);
}

function consumeAttackSample(&$monster) {
    if (!$monster) return;
    if (!empty($monster['hasPracticeEffect'])) {
        $monster['validityMultiplier'] = max(0.03125, ($monster['validityMultiplier'] ?? 1.0) / 2);
    }
    if (!empty($monster['isMeta'])) {
        $monster['n'] = $monster['baseN'] ?? META_BASE_N;
        $monster['power'] = clamp(0.9 + (($monster['n'] ?? META_BASE_N) / 1200), 0.9, 0.99);
        $monster['atk'] = $monster['baseAtk'];
        return;
    }

    $monster['n'] = $monster['baseN'] ?? MONSTER_BASE_N;
    refreshMonsterStats($monster);
}

function makeItemCard($constructId, $construct, $indicatorIndex = null) {
    $indicators = $construct['indicators'] ?? [];
    if (empty($indicators)) {
        $indicators = [[
            'name' => $construct['type'] === 'predictor' ? 'Item indicator' : 'Criterion indicator',
            'r' => $construct['avgR']
        ]];
    }

    if ($indicatorIndex === null) {
        $picked = $indicators[random_int(0, count($indicators) - 1)];
    } else {
        $picked = $indicators[$indicatorIndex % count($indicators)];
    }

    return [
        'isItem' => true,
        'type' => $construct['type'],
        'constructId' => $constructId,
        'construct' => $construct['name'],
        'short' => $construct['short'],
        'avgR' => $picked['r'],
        'sprite' => $construct['sprite'],
        'indicator' => $picked['name']
    ];
}

function makeSpellCard($id, $type, $name, $icon, $desc) {
    return ['isItem'=>false,'id'=>$id,'type'=>$type,'name'=>$name,'icon'=>$icon,'desc'=>$desc];
}

function buildDeck() {
    $constructs = getConstructs();
    $counts = [
        'cog_ability'=>4,'conscient'=>4,'struct_int'=>4,'work_sample'=>4,
        'job_perf'=>4,'turnover'=>4,'job_sat'=>4,'ocb'=>4,
        'sample_size'=>5,'job_relevance'=>4,'imputation'=>3,'missing_data'=>3,'range_restrict'=>4,'item_leakage'=>3,'correction'=>3,'p_hacking'=>3,'practice_effect'=>3,
        'bootstrapping'=>4,'item_analysis'=>3,
        'construct_drift'=>3,'criterion_contam'=>3,
    ];

    $spellDefs = [
        'sample_size'=>['resource','Sample Size','N+150','Increase target friendly monster sample size (N), boosting power.'],
        'job_relevance'=>['spell','Job Relevance','📌','Equip to your monster. Monsters with ★★★☆☆ or less need this to attack.'],
        'imputation'=>['spell','Imputation','🩹','Equip to your monster. If Missing Data targets it, Imputation is destroyed instead.'],
        'missing_data'=>['trap','Missing Data','∅','Destroy any card on the field.'],
        'range_restrict'=>['trap','Range Restriction','↔','Target enemy monster. Halve ATK.'],
        'item_leakage'=>['trap','Item Leakage','💧','Target enemy monster. Set validity to 0 (ATK 0) for 1 turn.'],
        'correction'=>['resource','Correction for Attenuation','↺','Target your monster. Set ATK to r_true × 10,000 for this turn.'],
        'p_hacking'=>['spell','P-hacking','🎯','Equip to your monster. Its next attack cannot miss, then it is destroyed at the end of that attack.'],
        'practice_effect'=>['trap','Practice Effect','🌀','Equip to a monster. After it attacks, halve its current validity coefficient.'],
        'bootstrapping'=>['spell','Bootstrapping','🧮','Target your monster. Permanently increase its base N by 50.'],
        'item_analysis'=>['spell','Automated Item Generation','🧩','Target your construct stack. Add one matching item (max 3) to improve reliability.'],
        'construct_drift'=>['trap','Construct Drift','↘','Target enemy construct. Remove one stacked item (or destroy stack if only one).'],
        'criterion_contam'=>['trap','Attrition','⚠','Target enemy monster. Permanently halve N.'],
    ];

    $deck = [];
    foreach ($counts as $id => $n) {
        for ($i = 0; $i < $n; $i++) {
            if (isset($constructs[$id])) $deck[] = makeItemCard($id, $constructs[$id], $i);
            else {
                [$type, $name, $icon, $desc] = $spellDefs[$id];
                $deck[] = makeSpellCard($id, $type, $name, $icon, $desc);
            }
        }
    }

    shuffle($deck);
    return $deck;
}

function drawCards(&$player, $n, $allowOverflow = false) {
    for ($i = 0; $i < $n; $i++) {
        if (!$allowOverflow && count($player['hand']) >= MAX_HAND_SIZE) break;
        if (count($player['deck']) > 0) $player['hand'][] = array_pop($player['deck']);
    }
}

function enforceHandLimit(&$state, &$player, $pNum, $reason = 'draw') {
    $overflow = max(0, count($player['hand']) - MAX_HAND_SIZE);
    if ($overflow > 0) {
        $player['pending_discard'] = $overflow;
        $state['log'][] = ['msg' => "P{$pNum} must trash {$overflow} card" . ($overflow === 1 ? '' : 's') . " after {$reason}.", 'type' => 'info-log'];
    }
}

function grantExperienceToken(&$state, &$player, $pNum) {
    $player['experience_tokens'] = (int)($player['experience_tokens'] ?? 0) + 1;
    $toward = $player['experience_tokens'] % EXPERIENCE_MISS_THRESHOLD;
    $remain = $toward === 0 ? 0 : EXPERIENCE_MISS_THRESHOLD - $toward;
    if ($remain === 0) {
        $state['log'][] = ['msg' => "P{$pNum} gains an Experience token ({$player['experience_tokens']}). Experience Draw is ready!", 'type' => 'meta-log'];
    } else {
        $state['log'][] = ['msg' => "P{$pNum} gains an Experience token ({$player['experience_tokens']}). {$remain} more miss" . ($remain === 1 ? '' : 'es') . ' to unlock Draw 3.', 'type' => 'info-log'];
    }
}

function getPlayerNum($state, $token) {
    if (($state['player1_token'] ?? null) === $token) return 1;
    if (($state['player2_token'] ?? null) === $token) return 2;
    return null;
}

function getMetaTarget($monsters) {
    if (!$monsters[0] || !$monsters[1] || !$monsters[2]) return null;
    if ($monsters[0]['predId'] === $monsters[1]['predId'] && $monsters[1]['predId'] === $monsters[2]['predId'] && $monsters[0]['predId'] !== 'META') {
        return ['type' => 'pred', 'id' => $monsters[0]['predId']];
    }
    if ($monsters[0]['outId'] === $monsters[1]['outId'] && $monsters[1]['outId'] === $monsters[2]['outId'] && $monsters[0]['outId'] !== 'META') {
        return ['type' => 'out', 'id' => $monsters[0]['outId']];
    }
    return null;
}

function createRoom() {
    $code = generateRoomCode();
    while (file_exists(getRoomPath($code))) $code = generateRoomCode();

    $token1 = generateToken();
    $deck1 = buildDeck();
    $deck2 = buildDeck();

    $state = [
        'room_code' => $code,
        'status' => 'waiting',
        'player1_token' => $token1,
        'player2_token' => null,
        'current_turn' => 1,
        'turn_number' => 1,
        'winner' => null,
        'last_update' => time(),
        'mulligan' => ['phase' => true, 'done' => ['1' => false, '2' => false]],
        'player1' => ['lp'=>8000,'hand'=>[],'deck'=>$deck1,'constructs'=>[null,null,null],'monsters'=>[null,null,null],'summoned_this_turn'=>false,'experience_tokens'=>0,'pending_discard'=>0],
        'player2' => ['lp'=>8000,'hand'=>[],'deck'=>$deck2,'constructs'=>[null,null,null],'monsters'=>[null,null,null],'summoned_this_turn'=>false,'experience_tokens'=>0,'pending_discard'=>0],
        'log' => []
    ];

    drawCards($state['player1'], STARTING_HAND_SIZE);
    drawCards($state['player2'], STARTING_HAND_SIZE);

    saveRoom($code, $state);
    respond(true, 'Room created', ['room_code' => $code, 'player_token' => $token1, 'player_num' => 1]);
}

function joinRoom($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    if (strlen($code) !== 10) respond(false, 'Invalid room code');

    [$state, $_] = withLockedRoom($code, function (&$state) {
        if ($state['status'] !== 'waiting') return ['ok' => false, 'msg' => 'Room is full or game already started'];
        $token2 = generateToken();
        $state['player2_token'] = $token2;
        $state['status'] = 'active';
        $state['last_update'] = time();
        $state['log'][] = ['msg' => 'Opponent has joined! Opening mulligan phase started.', 'type' => 'info-log'];
        return ['ok' => true, 'token2' => $token2];
    });

    if (!$_['ok']) respond(false, $_['msg']);
    respond(true, 'Joined room', ['room_code' => $code, 'player_token' => $_['token2'], 'player_num' => 2]);
}

function pollState($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    $token = $input['player_token'] ?? '';

    $state = loadRoom($code);
    if (!$state) respond(false, 'Room not found');
    $pNum = getPlayerNum($state, $token);
    if (!$pNum) respond(false, 'Invalid token');

    respond(true, '', ['state' => filterStateForPlayer($state, $pNum)]);
}

function submitMulligan($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    $token = $input['player_token'] ?? '';
    $indices = $input['replace_indices'] ?? [];
    if (!is_array($indices)) $indices = [];

    [$state, $result] = withLockedRoom($code, function (&$state) use ($token, $indices) {
        $pNum = getPlayerNum($state, $token);
        if (!$pNum) return ['ok' => false, 'msg' => 'Invalid token'];
        if ($state['status'] !== 'active') return ['ok' => false, 'msg' => 'Game not active'];
        if (empty($state['mulligan']['phase'])) return ['ok' => false, 'msg' => 'Mulligan phase is over'];
        if (!empty($state['mulligan']['done'][(string)$pNum])) return ['ok' => false, 'msg' => 'Mulligan already completed'];

        $player = &$state['player' . $pNum];
        $uniq = array_values(array_unique(array_map('intval', $indices)));
        rsort($uniq);

        $replaced = [];
        foreach ($uniq as $idx) {
            if ($idx < 0 || $idx >= count($player['hand'])) continue;
            $replaced[] = $player['hand'][$idx];
            array_splice($player['hand'], $idx, 1);
        }

        if (count($replaced) > 0) {
            foreach ($replaced as $card) $player['deck'][] = $card;
            shuffle($player['deck']);
            drawCards($player, count($replaced));
        }

        $state['mulligan']['done'][(string)$pNum] = true;
        $state['log'][] = ['msg' => "P{$pNum} completes mulligan (" . count($replaced) . " replaced).", 'type' => 'info-log'];

        if (!empty($state['mulligan']['done']['1']) && !empty($state['mulligan']['done']['2'])) {
            $state['mulligan']['phase'] = false;
            $state['log'][] = ['msg' => 'Mulligans complete. P1 begins the duel.', 'type' => 'meta-log'];
        }

        $state['last_update'] = time();
        return ['ok' => true, 'pNum' => $pNum];
    });

    if (!$result['ok']) respond(false, $result['msg']);
    respond(true, 'Mulligan submitted', ['state' => filterStateForPlayer($state, $result['pNum'])]);
}

function submitMove($input) {
    $code = strtoupper(trim($input['room_code'] ?? ''));
    $token = $input['player_token'] ?? '';
    $move = $input['move'] ?? null;
    if (!$move) respond(false, 'No move provided');

    [$state, $result] = withLockedRoom($code, function (&$state) use ($token, $move) {
        $pNum = getPlayerNum($state, $token);
        if (!$pNum) return ['ok' => false, 'msg' => 'Invalid token'];
        if ($state['status'] !== 'active') return ['ok' => false, 'msg' => 'Game not active'];
        if ($state['current_turn'] !== $pNum) return ['ok' => false, 'msg' => 'Not your turn'];
        if (!empty($state['mulligan']['phase'])) return ['ok' => false, 'msg' => 'Complete mulligan first'];

        $me = &$state['player' . $pNum];
        $opp = &$state['player' . ($pNum === 1 ? 2 : 1)];

        $moveResult = processMove($state, $me, $opp, $pNum, $move);
        if (empty($moveResult['ok'])) return ['ok' => false, 'msg' => $moveResult['msg'] ?? 'Move failed'];

        if ($me['lp'] <= 0 || $opp['lp'] <= 0) {
            $state['status'] = 'finished';
            $state['winner'] = $me['lp'] > 0 ? $pNum : ($pNum === 1 ? 2 : 1);
            $state['log'][] = ['msg' => 'Duel finished.', 'type' => 'meta-log'];
        }

        $state['last_update'] = time();
        return ['ok' => true, 'pNum' => $pNum, 'move_result' => $moveResult];
    });

    if (!$result['ok']) respond(false, $result['msg']);
    respond(true, $result['move_result']['msg'] ?? 'OK', [
        'state' => filterStateForPlayer($state, $result['pNum']),
        'move_result' => $result['move_result']
    ]);
}

function processMove(&$state, &$me, &$opp, $pNum, $move) {
    if (!empty($me['pending_discard']) && ($move['type'] ?? '') !== 'discard_card') {
        return ['ok'=>false,'msg'=>'Trash required cards first'];
    }
    $type = $move['type'] ?? '';
    switch ($type) {
        case 'place_card': return movePlaceCard($state, $me, $pNum, $move);
        case 'summon': return moveSummon($state, $me, $pNum, $move);
        case 'play_spell': return movePlaySpell($state, $me, $opp, $pNum, $move);
        case 'attack': return moveAttack($state, $me, $opp, $pNum, $move);
        case 'meta': return moveMeta($state, $me, $pNum);
        case 'experience_draw': return moveExperienceDraw($state, $me, $pNum);
        case 'discard_card': return moveDiscardCard($state, $me, $pNum, $move);
        case 'end_turn': return moveEndTurn($state, $me, $opp, $pNum);
        default: return ['ok'=>false,'msg'=>'Unknown move type'];
    }
}

function moveDiscardCard(&$state, &$me, $pNum, $move) {
    $need = (int)($me['pending_discard'] ?? 0);
    if ($need <= 0) return ['ok'=>false,'msg'=>'No discard required'];
    $handIdx = (int)($move['hand_index'] ?? -1);
    if ($handIdx < 0 || $handIdx >= count($me['hand'])) return ['ok'=>false,'msg'=>'Invalid hand index'];
    $card = $me['hand'][$handIdx];
    array_splice($me['hand'], $handIdx, 1);
    $me['pending_discard'] = max(0, $need - 1);
    $name = !empty($card['isItem']) ? ($card['short'] ?? 'item') : ($card['name'] ?? 'card');
    $state['log'][] = ['msg' => "P{$pNum} trashes {$name} ({$me['pending_discard']} remaining).", 'type' => 'info-log'];
    return ['ok'=>true,'msg'=>'Card trashed'];
}

function moveExperienceDraw(&$state, &$me, $pNum) {
    if ((int)($me['experience_tokens'] ?? 0) < EXPERIENCE_MISS_THRESHOLD) return ['ok'=>false,'msg'=>'Need 4 Experience tokens'];
    $me['experience_tokens'] -= EXPERIENCE_MISS_THRESHOLD;
    drawCards($me, EXPERIENCE_DRAW_COUNT, true);
    enforceHandLimit($state, $me, $pNum, 'Experience Draw');
    $state['log'][] = ['msg' => "P{$pNum} spends 4 Experience tokens and draws 3 cards.", 'type' => 'meta-log'];
    return ['ok'=>true,'msg'=>'Experience Draw'];
}

function movePlaceCard(&$state, &$me, $pNum, $move) {
    $handIdx = (int)($move['hand_index'] ?? -1);
    $slotIdx = (int)($move['slot'] ?? -1);

    if ($handIdx < 0 || $handIdx >= count($me['hand'])) return ['ok'=>false,'msg'=>'Invalid hand index'];
    if ($slotIdx < 0 || $slotIdx > 2) return ['ok'=>false,'msg'=>'Invalid slot'];

    $card = $me['hand'][$handIdx];
    if (!($card['isItem'] ?? false)) return ['ok'=>false,'msg'=>'Not an item card'];

    $existing = $me['constructs'][$slotIdx];
    if ($existing && $existing['constructId'] !== $card['constructId']) return ['ok'=>false,'msg'=>'Construct mismatch'];
    if ($existing && count($existing['cards']) >= 3) return ['ok'=>false,'msg'=>'Slot full'];

    array_splice($me['hand'], $handIdx, 1);
    if (!$existing) $me['constructs'][$slotIdx] = ['constructId'=>$card['constructId'],'type'=>$card['type'],'cards'=>[$card]];
    else $me['constructs'][$slotIdx]['cards'][] = $card;

    $stackCount = count($me['constructs'][$slotIdx]['cards']);
    $state['log'][] = ['msg' => "P{$pNum} places {$card['construct']} ({$stackCount}/3).", 'type' => 'formula-log'];
    return ['ok'=>true,'msg'=>'Card placed'];
}

function moveSummon(&$state, &$me, $pNum, $move) {
    if (!empty($me['summoned_this_turn'])) return ['ok'=>false,'msg'=>'You can only summon once per turn'];

    $predSlot = (int)($move['pred_slot'] ?? -1);
    $outSlot = (int)($move['out_slot'] ?? -1);
    if ($predSlot === $outSlot) return ['ok'=>false,'msg'=>'Predictor and Outcome must be different slots'];

    $pred = $me['constructs'][$predSlot] ?? null;
    $out = $me['constructs'][$outSlot] ?? null;
    if (!$pred || $pred['type'] !== 'predictor') return ['ok'=>false,'msg'=>'Invalid predictor'];
    if (!$out || $out['type'] !== 'outcome') return ['ok'=>false,'msg'=>'Invalid outcome'];

    $mSlot = array_search(null, $me['monsters'], true);
    if ($mSlot === false) return ['ok'=>false,'msg'=>'No empty monster slot'];

    $tv = getTrueValidity();
    $mn = getMonsterNames();
    $predId = $pred['constructId'];
    $outId = $out['constructId'];

    $ai = getPairAdverseImpact($predId, $outId);

    $monster = [
        'name' => $mn[$predId][$outId] ?? "{$predId} × {$outId}",
        'sprite' => $GLOBALS['MONSTER_SPRITES'][array_rand($GLOBALS['MONSTER_SPRITES'])],
        'predId' => $predId,
        'outId' => $outId,
        'predAlpha' => spearmanBrown(count($pred['cards']), $pred['cards'][0]['avgR']),
        'outAlpha' => spearmanBrown(count($out['cards']), $out['cards'][0]['avgR']),
        'rTrue' => $tv[$predId][$outId] ?? 0.1,
        'adverseImpact' => $ai['bwd'],
        'adverseStars' => $ai['stars'],
        'adverseStarsText' => $ai['starsText'],
        'requiresJobRelevance' => $ai['requiresJobRelevance'],
        'rObs' => 0,
        'baseAtk' => 0,
        'atk' => 0,
        'baseN' => MONSTER_BASE_N,
        'n' => MONSTER_BASE_N,
        'power' => 0.05,
        'attacksMade' => 0,
        'maxAttacks' => 1,
        'summoningSick' => true,
        'hasJobRelevance' => false,
        'hasImputation' => false,
        'hasPHacking' => false,
        'hasPracticeEffect' => false,
        'itemLeakageApplied' => false,
        'correctionApplied' => false,
        'rangeRestrictionStacks' => 0,
        'validityMultiplier' => 1.0,
        'isMeta' => false
    ];
    refreshMonsterStats($monster);

    $me['monsters'][$mSlot] = $monster;
    $me['constructs'][$predSlot] = null;
    $me['constructs'][$outSlot] = null;
    $me['summoned_this_turn'] = true;

    $state['log'][] = ['msg' => "P{$pNum} summons {$monster['name']} (ATK {$monster['atk']}, PWR " . floor($monster['power'] * 100) . "%).", 'type' => 'formula-log'];
    return ['ok'=>true,'msg'=>'Summoned'];
}

function movePlaySpell(&$state, &$me, &$opp, $pNum, $move) {
    $handIdx = (int)($move['hand_index'] ?? -1);
    if ($handIdx < 0 || $handIdx >= count($me['hand'])) return ['ok'=>false,'msg'=>'Invalid hand index'];

    $card = $me['hand'][$handIdx];
    if (!empty($card['isItem'])) return ['ok'=>false,'msg'=>'Not a spell/trap/resource card'];

    $targetOwner = ($move['target_owner'] ?? '') === 'opp' ? 'opp' : 'me';
    $targetType = $move['target_type'] ?? '';
    $targetSlot = (int)($move['target_slot'] ?? -1);

    $targetPlayer = $targetOwner === 'opp' ? $opp : $me;
    $targetArr = $targetType === 'construct' ? ($targetPlayer['constructs'] ?? []) : ($targetPlayer['monsters'] ?? []);
    $target = $targetArr[$targetSlot] ?? null;
    if (!$target) return ['ok'=>false,'msg'=>'No valid target there'];

    $id = $card['id'];
    if ($id === 'sample_size') {
        if ($targetType !== 'monster' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'Sample Size must target your monster'];
        $me['monsters'][$targetSlot]['n'] = clamp(($me['monsters'][$targetSlot]['n'] ?? MONSTER_BASE_N) + 150, MONSTER_BASE_N, 420);
        refreshMonsterStats($me['monsters'][$targetSlot]);
    } elseif ($id === 'job_relevance') {
        if ($targetType !== 'monster' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'Job Relevance must target your monster'];
        if (empty($me['monsters'][$targetSlot]['requiresJobRelevance'])) return ['ok'=>false,'msg'=>'This monster does not need Job Relevance to attack'];
        if (!empty($me['monsters'][$targetSlot]['hasJobRelevance'])) return ['ok'=>false,'msg'=>'Job Relevance is already equipped to this monster'];
        $me['monsters'][$targetSlot]['hasJobRelevance'] = true;
    } elseif ($id === 'imputation') {
        if ($targetType !== 'monster' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'Imputation must target your monster'];
        if (!empty($me['monsters'][$targetSlot]['hasImputation'])) return ['ok'=>false,'msg'=>'Imputation is already equipped to this monster'];
        $me['monsters'][$targetSlot]['hasImputation'] = true;
    } elseif ($id === 'p_hacking') {
        if ($targetType !== 'monster' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'P-hacking must target your monster'];
        if (!empty($me['monsters'][$targetSlot]['hasPHacking'])) return ['ok'=>false,'msg'=>'P-hacking is already equipped to this monster'];
        $me['monsters'][$targetSlot]['hasPHacking'] = true;
    } elseif ($id === 'practice_effect') {
        if ($targetType !== 'monster') return ['ok'=>false,'msg'=>'Practice Effect must target a monster'];
        if ($targetOwner === 'me') {
            if (!empty($me['monsters'][$targetSlot]['hasPracticeEffect'])) return ['ok'=>false,'msg'=>'Practice Effect is already equipped to this monster'];
            $me['monsters'][$targetSlot]['hasPracticeEffect'] = true;
        } else {
            if (!empty($opp['monsters'][$targetSlot]['hasPracticeEffect'])) return ['ok'=>false,'msg'=>'Practice Effect is already equipped to this monster'];
            $opp['monsters'][$targetSlot]['hasPracticeEffect'] = true;
        }
    } elseif ($id === 'missing_data') {
        if ($targetType === 'construct') {
            if ($targetOwner === 'me') $me['constructs'][$targetSlot] = null;
            else $opp['constructs'][$targetSlot] = null;
        } else {
            if ($targetOwner === 'me') {
                if (!empty($me['monsters'][$targetSlot]['hasImputation'])) $me['monsters'][$targetSlot]['hasImputation'] = false;
                else $me['monsters'][$targetSlot] = null;
            } else {
                if (!empty($opp['monsters'][$targetSlot]['hasImputation'])) $opp['monsters'][$targetSlot]['hasImputation'] = false;
                else $opp['monsters'][$targetSlot] = null;
            }
        }
    } elseif ($id === 'range_restrict') {
        if ($targetType !== 'monster' || $targetOwner !== 'opp') return ['ok'=>false,'msg'=>'Range Restriction must target enemy monster'];
        $opp['monsters'][$targetSlot]['rangeRestrictionStacks'] = max(0, (int)($opp['monsters'][$targetSlot]['rangeRestrictionStacks'] ?? 0)) + 1;
        refreshMonsterStats($opp['monsters'][$targetSlot]);
    } elseif ($id === 'item_leakage') {
        if ($targetType !== 'monster' || $targetOwner !== 'opp') return ['ok'=>false,'msg'=>'Item Leakage must target enemy monster'];
        $opp['monsters'][$targetSlot]['itemLeakageApplied'] = true;
        refreshMonsterStats($opp['monsters'][$targetSlot]);
    } elseif ($id === 'correction') {
        if ($targetType !== 'monster' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'Correction for Attenuation must target your monster'];
        $me['monsters'][$targetSlot]['correctionApplied'] = true;
        $me['monsters'][$targetSlot]['rangeRestrictionStacks'] = 0;
        refreshMonsterStats($me['monsters'][$targetSlot]);
    } elseif ($id === 'bootstrapping') {
        if ($targetType !== 'monster' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'Bootstrapping must target your monster'];
        $me['monsters'][$targetSlot]['baseN'] = ($me['monsters'][$targetSlot]['baseN'] ?? MONSTER_BASE_N) + 50;
        $me['monsters'][$targetSlot]['n'] = ($me['monsters'][$targetSlot]['n'] ?? MONSTER_BASE_N) + 50;
        refreshMonsterStats($me['monsters'][$targetSlot]);
    } elseif ($id === 'item_analysis') {
        if ($targetType !== 'construct' || $targetOwner !== 'me') return ['ok'=>false,'msg'=>'Automated Item Generation must target your construct'];
        if (count($me['constructs'][$targetSlot]['cards']) >= 3) return ['ok'=>false,'msg'=>'Construct stack already at maximum size'];
        $last = end($me['constructs'][$targetSlot]['cards']);
        $me['constructs'][$targetSlot]['cards'][] = $last;
    } elseif ($id === 'construct_drift') {
        if ($targetType !== 'construct' || $targetOwner !== 'opp') return ['ok'=>false,'msg'=>'Construct Drift must target enemy construct'];
        if (count($opp['constructs'][$targetSlot]['cards']) > 1) array_pop($opp['constructs'][$targetSlot]['cards']);
        else $opp['constructs'][$targetSlot] = null;
    } elseif ($id === 'criterion_contam') {
        if ($targetType !== 'monster' || $targetOwner !== 'opp') return ['ok'=>false,'msg'=>'Attrition must target enemy monster'];
        $opp['monsters'][$targetSlot]['n'] = max(1, (int) floor(($opp['monsters'][$targetSlot]['n'] ?? MONSTER_BASE_N) / 2));
        $opp['monsters'][$targetSlot]['baseN'] = max(1, (int) floor(($opp['monsters'][$targetSlot]['baseN'] ?? MONSTER_BASE_N) / 2));
        refreshMonsterStats($opp['monsters'][$targetSlot]);
    } else {
        return ['ok'=>false,'msg'=>'Unknown card effect'];
    }

    array_splice($me['hand'], $handIdx, 1);
    $state['log'][] = ['msg' => "P{$pNum} plays {$card['name']}.", 'type' => 'spell-log'];
    return ['ok'=>true,'msg'=>'Spell played'];
}

function moveAttack(&$state, &$me, &$opp, $pNum, $move) {
    $atkSlot = (int)($move['attacker_slot'] ?? -1);
    $attacker = &$me['monsters'][$atkSlot];
    if (!$attacker) return ['ok'=>false,'msg'=>'No attacker in that slot'];
    if (!empty($attacker['summoningSick'])) return ['ok'=>false,'msg'=>'This monster cannot attack the turn it was summoned'];
    if (!empty($attacker['requiresJobRelevance']) && empty($attacker['hasJobRelevance'])) return ['ok'=>false,'msg'=>'This monster needs Job Relevance equipped to attack'];
    if (($attacker['attacksMade'] ?? 0) >= ($attacker['maxAttacks'] ?? 1)) return ['ok'=>false,'msg'=>'This monster has no attacks left'];

    $threshold = clamp((int) round(($attacker['power'] ?? 0.05) * 20), 1, 20);
    $roll = random_int(1, 20);
    $isPHackingAttack = !empty($attacker['hasPHacking']);
    $hit = $isPHackingAttack || $roll <= $threshold;

    $result = ['ok'=>true,'roll'=>$roll,'threshold'=>$threshold,'hit'=>$hit,'attack_data'=>['damage'=>0,'outcome'=>'tie']];

    $attacker['attacksMade']++;

    if (!$hit) {
        $state['log'][] = ['msg' => "P{$pNum}'s {$attacker['name']} misses (roll {$roll}, needs ≤ {$threshold}).", 'type' => 'battle-log'];
        grantExperienceToken($state, $me, $pNum);
        consumeAttackSample($attacker);
        $result['msg'] = 'Type II Error! Attack missed.';
        return $result;
    }

    $targetType = $move['target_type'] ?? 'lp';
    $targetSlot = (int)($move['target_slot'] ?? -1);
    $opponentHasMonsters = count(array_filter($opp['monsters'])) > 0;
    if ($targetType === 'lp' && $opponentHasMonsters) return ['ok'=>false,'msg'=>'You must attack an enemy monster first'];
    if ($targetType === 'monster' && empty($opp['monsters'][$targetSlot])) {
        if ($opponentHasMonsters) return ['ok'=>false,'msg'=>'No defender in that slot'];
        $targetType = 'lp';
    }

    if ($targetType === 'lp') {
        $dmg = $attacker['atk'];
        $opp['lp'] = max(0, $opp['lp'] - $dmg);
        $result['attack_data'] = ['damage'=>$dmg,'outcome'=>'win'];
        $state['log'][] = ['msg' => "P{$pNum}'s {$attacker['name']} attacks directly for {$dmg}.", 'type' => 'battle-log'];
        consumeAttackSample($attacker);
        $result['msg'] = 'Attack resolved';
        return $result;
    }

    $defender = &$opp['monsters'][$targetSlot];
    if ($attacker['atk'] > $defender['atk']) {
        $damage = $attacker['atk'] - $defender['atk'];
        $opp['monsters'][$targetSlot] = null;
        $opp['lp'] = max(0, $opp['lp'] - $damage);
        $result['attack_data'] = ['damage'=>$damage,'outcome'=>'win'];
        $state['log'][] = ['msg' => "{$attacker['name']} defeats {$defender['name']} and deals {$damage}.", 'type' => 'battle-log'];
        consumeAttackSample($attacker);
    } elseif ($attacker['atk'] < $defender['atk']) {
        $damage = $defender['atk'] - $attacker['atk'];
        $me['monsters'][$atkSlot] = null;
        $me['lp'] = max(0, $me['lp'] - $damage);
        $result['attack_data'] = ['damage'=>$damage,'outcome'=>'lose'];
        $state['log'][] = ['msg' => "{$attacker['name']} loses to {$defender['name']} and takes {$damage}.", 'type' => 'battle-log'];
    } else {
        $me['monsters'][$atkSlot] = null;
        $opp['monsters'][$targetSlot] = null;
        $result['attack_data'] = ['damage'=>0,'outcome'=>'tie'];
        $state['log'][] = ['msg' => "{$attacker['name']} and {$defender['name']} destroy each other.", 'type' => 'battle-log'];
    }

    if ($isPHackingAttack && !empty($me['monsters'][$atkSlot])) {
        $attackerName = $me['monsters'][$atkSlot]['name'];
        $me['monsters'][$atkSlot] = null;
        $state['log'][] = ['msg' => "P{$pNum}'s {$attackerName} is destroyed by P-hacking after attacking.", 'type' => 'battle-log'];
    }

    $result['msg'] = 'Attack resolved';
    return $result;
}

function moveMeta(&$state, &$me, $pNum) {
    $target = getMetaTarget($me['monsters']);
    if (!$target) return ['ok'=>false,'msg'=>'No valid meta target'];

    $m = $me['monsters'];
    $meanR = (abs($m[0]['rObs']) + abs($m[1]['rObs']) + abs($m[2]['rObs'])) / 3;
    $constructs = getConstructs();
    $id = $target['id'];
    $short = $constructs[$id]['short'] ?? 'META';

    $metaRTrue = clamp($meanR * 1.35, 0.35, 0.95);
    $meta = [
        'name' => "Meta-{$short} Titan",
        'sprite' => '🌌',
        'predId' => 'META',
        'outId' => 'META',
        'predAlpha' => 0.99,
        'outAlpha' => 0.99,
        'rTrue' => $metaRTrue,
        'adverseImpact' => 0,
        'adverseStars' => 5,
        'adverseStarsText' => '★★★★★',
        'requiresJobRelevance' => false,
        'rObs' => $metaRTrue,
        'baseAtk' => (int) round(abs($metaRTrue) * 10000),
        'atk' => 0,
        'baseN' => META_BASE_N,
        'n' => META_BASE_N,
        'power' => clamp(0.9 + (META_BASE_N / 1000), 0.9, 0.99),
        'attacksMade' => 0,
        'maxAttacks' => 2,
        'summoningSick' => false,
        'hasJobRelevance' => false,
        'hasImputation' => false,
        'hasPHacking' => false,
        'hasPracticeEffect' => false,
        'itemLeakageApplied' => false,
        'correctionApplied' => false,
        'rangeRestrictionStacks' => 0,
        'validityMultiplier' => 1.0,
        'isMeta' => true
    ];
    refreshMonsterStats($meta);

    $me['monsters'] = [null, null, null];
    $me['monsters'][0] = $meta;

    $state['log'][] = ['msg' => "P{$pNum} performs META-ANALYSIS and summons {$meta['name']}!", 'type' => 'meta-log'];
    return ['ok'=>true,'msg'=>'Meta Analysis'];
}

function resetMonstersForTurn(&$player) {
    foreach ($player['monsters'] as &$m) {
        if (!$m) continue;
        if (!empty($m['correctionApplied'])) {
            $m['correctionApplied'] = false;
        }
        $m['summoningSick'] = false;
        $m['attacksMade'] = 0;
        $m['maxAttacks'] = !empty($m['isMeta']) ? 2 : 1;
        refreshMonsterStats($m);
    }
}

function clearEndOfTurnEffects(&$player) {
    foreach ($player['monsters'] as &$m) {
        if (!$m) continue;
        $changed = false;
        if (!empty($m['correctionApplied'])) {
            $m['correctionApplied'] = false;
            $changed = true;
        }
        if (!empty($m['itemLeakageApplied'])) {
            $m['itemLeakageApplied'] = false;
            $changed = true;
        }
        if ($changed) refreshMonsterStats($m);
    }
}

function moveEndTurn(&$state, &$me, &$opp, $pNum) {
    if (!empty($me['pending_discard'])) return ['ok'=>false,'msg'=>'Resolve required trash first'];
    clearEndOfTurnEffects($me);

    $state['current_turn'] = $pNum === 1 ? 2 : 1;
    $state['turn_number']++;

    $opp['summoned_this_turn'] = false;
    resetMonstersForTurn($opp);
    drawCards($opp, 1, true);
    enforceHandLimit($state, $opp, $pNum === 1 ? 2 : 1, 'turn draw');

    $state['log'][] = ['msg' => "P{$pNum} ends turn.", 'type' => 'info-log'];
    return ['ok'=>true,'msg'=>'Turn ended','turn_ended'=>true];
}

function filterStateForPlayer($state, $pNum) {
    $oppNum = $pNum === 1 ? 2 : 1;
    $meKey = 'player' . $pNum;
    $oppKey = 'player' . $oppNum;

    return [
        'room_code' => $state['room_code'],
        'status' => $state['status'],
        'is_my_turn' => $state['current_turn'] === $pNum,
        'my_player_num' => $pNum,
        'turn_number' => $state['turn_number'],
        'winner' => $state['winner'],
        'i_won' => $state['winner'] === $pNum,

        'my_lp' => $state[$meKey]['lp'],
        'my_hand' => $state[$meKey]['hand'],
        'my_deck_count' => count($state[$meKey]['deck']),
        'my_constructs' => $state[$meKey]['constructs'],
        'my_monsters' => $state[$meKey]['monsters'],
        'my_summoned' => $state[$meKey]['summoned_this_turn'],
        'my_experience_tokens' => (int)($state[$meKey]['experience_tokens'] ?? 0),
        'opp_experience_tokens' => (int)($state[$oppKey]['experience_tokens'] ?? 0),
        'pending_discard_count' => (int)($state[$meKey]['pending_discard'] ?? 0),

        'opp_lp' => $state[$oppKey]['lp'],
        'opp_hand_count' => count($state[$oppKey]['hand']),
        'opp_deck_count' => count($state[$oppKey]['deck']),
        'opp_constructs' => $state[$oppKey]['constructs'],
        'opp_monsters' => $state[$oppKey]['monsters'],

        'mulligan_pending' => !empty($state['mulligan']['phase']) && empty($state['mulligan']['done'][(string)$pNum]),
        'mulligan_done' => !empty($state['mulligan']['done'][(string)$pNum]),

        'log' => $state['log']
    ];
}
