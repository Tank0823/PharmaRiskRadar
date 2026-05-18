<?php
require __DIR__ . '/load_env.php';

error_reporting(0);
header_remove('X-Powered-By');

// ─── API Keys ───
define('DS_KEY', \['DS_KEY'] ?? getenv('DS_KEY') ?: '');
define('SERPER_KEY', \['SERPER_KEY'] ?? getenv('SERPER_KEY') ?: '');

// ─── SQLite Database ───
function init_db() {
    $db = new PDO('sqlite:/tmp/pharma.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS stealth_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT, ticker TEXT, keyword TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, ticker, keyword))");
    $db->exec("CREATE TABLE IF NOT EXISTS prr_user_profiles (user_id TEXT PRIMARY KEY, role TEXT DEFAULT 'analyst', history TEXT DEFAULT '[]', updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS hedge_superpool (id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT, focus TEXT, hedge_output TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    return $db;
}

// ─── Circuit Breaker ───
function circuit_breaker_allowed() { return true; }
function circuit_breaker_record_success() {}
function circuit_breaker_record_failure() {}

// ─── HTTP POST Helper ───
function http_post($url, $headers, $body, $timeout=20) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => explode("\r\n", $headers),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("http_post error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $resp;
}

// ─── DeepSeek Call ───
function deepseek($messages, $retries=1) {
    for ($i=0; $i<=$retries; $i++) {
        $resp = http_post('https://api.deepseek.com/v1/chat/completions',
            "Content-Type: application/json\r\nAuthorization: Bearer ".DS_KEY."\r\n",
            json_encode(['model'=>'deepseek-chat','messages'=>$messages,'max_tokens'=>2000,'repetition_penalty'=>1.2]));
        if ($resp) {
            $d = json_decode($resp, true);
            $content = $d['choices'][0]['message']['content'] ?? '';
            if ($content) return $content;
            // Log the API error
            error_log("DeepSeek API error: " . substr($resp, 0, 500));
        } else {
            error_log("DeepSeek API call failed (network/key)");
        }
        if ($i<$retries) sleep(1);
    }
    return '';
}

// ─── Web Search (Serper) ───
function web_search($query, $limit=5) {
    if (!SERPER_KEY) return [];
    $ch = curl_init('https://google.serper.dev/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-API-KEY: " . SERPER_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode(['q'=>$query,'num'=>$limit]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return $d['organic'] ?? [];
}

function format_web($results) {
    if (empty($results)) return '';
    $out = "**Latest Web Data**\n";
    foreach ($results as $i=>$r) {
        $out .= ($i+1).'. '.($r['title']??'Untitled').' - '.($r['link']??'#')."\n".($r['snippet']??'')."\n\n";
    }
    return $out;
}

// ─── Risk Pool ───
$RISK_POOL = [
    'PFE'=>['company'=>'Pfizer Inc.','risks'=>['regulatory'=>['FDA warning letters','EU MDR compliance'],'supply_chain'=>['API sourcing from Asia','cold chain logistics'],'patent'=>['Lipitor patent expiry','Lyrica generic competition'],'clinical'=>['mRNA pipeline delays','Phase III hold on oncology candidate'],'financial'=>['debt maturity wall 2027','currency exposure']]],
    'JNJ'=>['company'=>'Johnson & Johnson','risks'=>['regulatory'=>['talcum powder safety','medical device MDR'],'supply_chain'=>['surgical robotics parts'],'patent'=>['Risperdal patent expirations'],'clinical'=>['vaccine thrombosis concerns'],'financial'=>['consumer health spin-off']]],
    'MRK'=>['company'=>'Merck & Co.','risks'=>['regulatory'=>['Keytruda label expansion delays'],'supply_chain'=>['glass vial shortage'],'patent'=>['Januvia LOE 2026'],'clinical'=>['Cardio pipeline setback'],'financial'=>['integration costs']]],
    'LLY'=>['company'=>'Eli Lilly','risks'=>['regulatory'=>['GLP-1 pricing pressure'],'supply_chain'=>['API manufacturing scaling'],'patent'=>['tirzepatide patent expiry 2036'],'clinical'=>['orforglipron Phase 3'],'financial'=>['currency headwinds']]],
    'ABBV'=>['company'=>'AbbVie Inc.','risks'=>['regulatory'=>['Skyrizi/Rinvoq label expansions'],'supply_chain'=>['biologic manufacturing'],'patent'=>['Humira biosimilar erosion'],'clinical'=>['neuroscience pipeline'],'financial'=>['Humira revenue cliff']]],
    'BMY'=>['company'=>'Bristol-Myers Squibb','risks'=>['regulatory'=>['Opdivo combination approvals'],'supply_chain'=>['biologics manufacturing'],'patent'=>['Opdivo LOE 2028'],'clinical'=>['KarXT launch uptake'],'financial'=>['established products erosion']]],
    'NVS'=>['company'=>'Novartis AG','risks'=>['regulatory'=>['generic drug pricing controls'],'supply_chain'=>['cell therapy logistics'],'patent'=>['Entresto LOE 2026'],'clinical'=>['Pluvicto manufacturing hurdles'],'financial'=>['Sandoz spin-off']]],
    'AZN'=>['company'=>'AstraZeneca','risks'=>['regulatory'=>['EU marketing authorisation changes'],'supply_chain'=>['vaccine cold chain'],'patent'=>['Tagrisso LOE 2028'],'clinical'=>['oncology combination trials'],'financial'=>['emerging market currency exposure']]],
];

function get_pool($ticker) { global $RISK_POOL; return $RISK_POOL[strtoupper($ticker)] ?? ['company'=>strtoupper($ticker),'risks'=>[]]; }

// ─── ask_ai (compact, used internally) ───
function ask_ai($question, $ticker) {
    $pool = get_pool($ticker); $company = $pool['company']??strtoupper($ticker); $risks = $pool['risks']??[];
    $ctx = "You are a top-tier pharmaceutical competitive intelligence analyst. Company: $company ($ticker).\n";
    $ctx .= "Provide a CONDENSED analysis in exactly this format:\n";
    $ctx .= "### Top 5 Risks for $company\n1. **Risk Name**: one-line explanation\n...\n\n### 5 Strategic Actions\n1. **Action**: one-line recommendation\n...\n\nIMPORTANT: Provide exactly 5 strategic actions. Do NOT invent filler.";
    $live = deepseek([['role'=>'system','content'=>$ctx],['role'=>'user','content'=>$question]]);
    if (empty($live) || stripos($live,'I cannot provide')!==false) {
        $live = "### Top 5 Risks for $company\n"; $j=0;
        foreach ($risks as $cat=>$items) { if($j>=5)break; if(!empty($items)){$j++; $live.=$j.'. **'.ucfirst($cat).'**: '.(is_array($items)?$items[0]:$items)."\n";} }
        $live.="\n### 5 Strategic Actions\n1. Address top risk\n2. Strengthen pipeline\n3. Diversify revenue\n4. Invest in R&D\n5. Enhance compliance\n";
    }
    return $live;
}

// ─── full_analysis (full package: risks + actions + sources + advantage + 10 AI) ───
function full_analysis($ticker, $question) {
    $pool = get_pool($ticker); $company = $pool['company']??strtoupper($ticker); $risks = $pool['risks']??[];
    $analysis = ask_ai($question, $ticker);
    $sourceLinks = web_search($ticker.' pharmaceutical risk analysis '.$question, 3);
    if (!empty($sourceLinks)) $analysis .= "\n**📚 Data Sources & Related Searches**\n".format_web($sourceLinks);
    $advantage = "\n\n### 🏆 Pharmaceutical Business Advantage\n**Strategic Actions for $company ($ticker)**\n\n";
    preg_match_all('/\d+\.\s+\*\*(.+?)\*\*/', $analysis, $matches); $actions = $matches[1]??[];
    for ($k=0; $k<min(5,count($actions)); $k++) $advantage.=($k+1).'. '.$actions[$k]."\n";
    $advQ = "Based on the analysis of $company ($ticker) regarding: $question, generate exactly 10 strategic business advantages that the company (or an investor) could leverage to stay ahead of competitors. Format as a numbered list with concise, actionable advantages. Be forward-looking.";
    $advAnswer = deepseek([['role'=>'system','content'=>'You are a top-tier pharmaceutical strategist. Provide exactly 10 forward-looking strategic advantages.'],['role'=>'user','content'=>$advQ]]);
    if (empty($advAnswer)) $advAnswer = "1. Accelerate digital transformation in clinical trials\n2. Expand into high-growth therapeutic areas\n3. Strengthen pipeline through strategic M&A\n4. Leverage AI for drug discovery\n5. Optimise global supply chain\n6. Invest in personalised medicine\n7. Enhance patient access programmes\n8. Build strategic partnerships with biotech\n9. Focus on real-world evidence generation\n10. Implement dynamic pricing strategies";
    return "### Analysis for $ticker\n".$analysis."\n".$advantage."\n\n### 🚀 10 AI Strategic Business Advantages\n".$advAnswer;
}

// ─── Stealth Functions ───
function generate_dynamic_stealth_buttons($ticker, $user_id) {
    $db = init_db(); $max=30;
    $stmt = $db->prepare("SELECT COUNT(*) FROM stealth_alerts WHERE user_id=? AND ticker=?");
    $stmt->execute([$user_id, $ticker]); $count = (int)$stmt->fetchColumn();
    if ($count < $max) {
        $pool = get_pool($ticker); $own_keywords=[];
        foreach ($pool['risks'] as $items) foreach ($items as $item) $own_keywords[]=$item;
        shuffle($own_keywords); $own_pick=array_slice($own_keywords,0,3);
        $compList=['MRK','JNJ','NVS','PFE','LLY','ABBV','AZN','BMY','AMGN','GILD'];
        $other=array_diff($compList,[strtoupper($ticker)]); $comp_pick=[];
        if(!empty($other)){ $ct=$other[array_rand($other)]; $ck=[]; foreach(get_pool($ct)['risks'] as $items) foreach($items as $item) $ck[]="$ct: $item"; shuffle($ck); $comp_pick=array_slice($ck,0,2); }
        $new_keywords=array_merge($own_pick,$comp_pick);
        $insert=$db->prepare("INSERT OR IGNORE INTO stealth_alerts (user_id, ticker, keyword) VALUES (?,?,?)");
        foreach($new_keywords as $kw) $insert->execute([$user_id,$ticker,$kw]);
    }
    $stmt=$db->prepare("SELECT keyword FROM stealth_alerts WHERE user_id=? AND ticker=? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id,$ticker]); $keywords=$stmt->fetchAll(PDO::FETCH_COLUMN);
    $buttons=[]; foreach($keywords as $kw) $buttons[]=['label'=>"🔍 $kw Deep Dive", 'query'=>"Analyze '$kw' risk and suggest mitigation."];
    if($count>=$max) $buttons[]=['label'=>"🧹 Alert limit reached – clear cache to see new alerts", 'query'=>"clear_stealth_cache"];
    return $buttons;
}
function clear_stealth_cache($user_id, $ticker) {
    $db=init_db(); $db->prepare("DELETE FROM stealth_alerts WHERE user_id=? AND ticker=?")->execute([$user_id,$ticker]);
    return ['status'=>'ok','message'=>'Stealth cache cleared.'];
}

// ─── Router ───
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/api/test') { echo json_encode(['status'=>'ok']); exit; }
if ($uri === '/api/debug') { echo json_encode(['php'=>phpversion(),'keys'=>DS_KEY?'set':'missing']); exit; }

// Cascade (multi-ticker, full package)
if ($uri === '/api/cascade' || $uri === '/api/persona' || $uri === '/api/deepthink') {
    $input = json_decode(file_get_contents('php://input'), true)?:[];
    $question = $input['question']??'Top risks for Pfizer';
    $ticker = $input['ticker']??'PFE';
    $comp_tickers = $input['comp_tickers']??[];
    $all_tickers = array_unique(array_merge([$ticker], $comp_tickers));
    $handles=[]; $mh=curl_multi_init(); $company_analyses=[];
    foreach($all_tickers as $t) {
        $pool=get_pool($t); $company=$pool['company']??strtoupper($t); $risks=$pool['risks']??[];
        $ctx = "You are a top-tier pharmaceutical competitive intelligence analyst. Company: $company ($t).\nProvide a CONDENSED analysis in exactly this format:\n### Top 5 Risks for $company\n1. **Risk Name**: one-line explanation\n...\n\n### 5 Strategic Actions\n1. **Action**: one-line recommendation\n...\n\nIMPORTANT: Provide exactly 5 strategic actions. Do NOT invent filler.";
        $msgs = [['role'=>'system','content'=>$ctx],['role'=>'user','content'=>$question]];
        $body = json_encode(['model'=>'deepseek-chat','messages'=>$msgs,'max_tokens'=>2000,'repetition_penalty'=>1.2]);
        $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.DS_KEY],CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        curl_multi_add_handle($mh,$ch); $handles[$t]=$ch;
    }
    $running=null; do{curl_multi_exec($mh,$running);}while($running>0);
    $report='';
    foreach($handles as $t=>$ch) {
        $resp=curl_multi_getcontent($ch); $content='';
        if($resp){$d=json_decode($resp,true);$content=$d['choices'][0]['message']['content']??'';}
        if(empty($content)){
            $pool=get_pool($t);$risks=$pool['risks']??[];
            $content="### Top 5 Risks for $t\n";$j=0;
            foreach($risks as $cat=>$items){if($j>=5)break;$j++;$content.=$j.'. **'.ucfirst($cat).':** '.(is_array($items)?$items[0]:$items)."\n";}
            $content.="\n### 5 Strategic Actions\n1. Address top risk\n2. Strengthen pipeline\n3. Diversify revenue\n4. Invest in R&D\n5. Enhance compliance\n";
        }
        $sourceLinks=web_search($t.' pharmaceutical risk analysis '.$question,3);
        if(!empty($sourceLinks))$content.="\n**📚 Data Sources & Related Searches**\n".format_web($sourceLinks);
        $report.="\n### Analysis for ".$t."\n".$content."\n\n---\n";
        $company_analyses[$t]=$content;
        curl_multi_remove_handle($mh,$ch);curl_close($ch);
    }
    curl_multi_close($mh);
    $advantage="\n### 🏆 Pharmaceutical Business Advantage\n**Competitive Strategic Actions — Ticker Order**\n\n";
    foreach($company_analyses as $t=>$text){
        $advantage.="**$t**\n"; preg_match_all('/\d+\.\s+\*\*(.+?)\*\*/',$text,$matches); $actions=$matches[1]??[];
        for($k=0;$k<min(5,count($actions));$k++) $advantage.=($k+1).'. '.$actions[$k]."\n"; $advantage.="\n";
    }
    $report.=$advantage;
    $advQ="Based on the analysis of the following pharmaceutical companies: ".implode(', ',$all_tickers).", regarding the query: ".$question.", generate exactly 10 strategic business advantages that the main company (or an investor) could leverage to stay ahead of these competitors. Format as a numbered list with concise, actionable advantages. Be forward-looking.";
    $advAnswer=deepseek([['role'=>'system','content'=>'You are a top-tier pharmaceutical strategist. Provide exactly 10 forward-looking strategic advantages.'],['role'=>'user','content'=>$advQ]]);
    if(empty($advAnswer))$advAnswer="1. Accelerate digital transformation in clinical trials\n2. Expand into high-growth therapeutic areas\n3. Strengthen pipeline through strategic M&A\n4. Leverage AI for drug discovery\n5. Optimise global supply chain\n6. Invest in personalised medicine\n7. Enhance patient access programmes\n8. Build strategic partnerships with biotech\n9. Focus on real-world evidence generation\n10. Implement dynamic pricing strategies";
    $report.="\n\n---\n### 🚀 10 AI Strategic Business Advantages\n".$advAnswer;
    echo json_encode(['final'=>$report]); exit;
}

// Stealth buttons
if ($uri === '/api/stealth-buttons') {
    ob_start(); error_reporting(0);
    header('Content-Type: application/json');
    echo json_encode(generate_dynamic_stealth_buttons($_GET['ticker']??'PFE',$_GET['user_id']??'1'));
    ob_end_flush(); exit;
}
if ($uri === '/api/clear-stealth') {
    $input = json_decode(file_get_contents('php://input'), true)?:[];
    echo json_encode(clear_stealth_cache($input['user_id']??'1',$input['ticker']??'PFE')); exit;
}

// Bubble (full package with personalization)
if ($uri === '/api/bubble') {
    ob_start(); error_reporting(0);
    try {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true)?:[];
        $question = $input['question']??'Top risks for Pfizer';
        $ticker = $input['ticker']??'PFE';
        $userId = $input['user_id']??'1';
        $db = init_db();
        $db->exec("INSERT OR IGNORE INTO prr_user_profiles (user_id, role) VALUES ('1','analyst')");
        $stmt = $db->prepare("SELECT role, history FROM prr_user_profiles WHERE user_id=?");
        $stmt->execute([$userId]); $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        $hist = $profile['history'] ? json_decode($profile['history'], true) : [];
        if (!is_array($hist)) $hist = [];
        $final = full_analysis($ticker, $question);
        $hist[] = ['question'=>$question,'timestamp'=>date('c')];
        $db->prepare("UPDATE prr_user_profiles SET history=?, updated_at=datetime('now') WHERE user_id=?")->execute([json_encode($hist),$userId]);
        ob_end_clean();
        echo json_encode(['final'=>$final]);
    } catch (Throwable $e) {
        ob_end_clean(); http_response_code(500);
        echo json_encode(['final'=>'We encountered a temporary issue.']);
        error_log('Bubble error: '.$e->getMessage());
    }
    exit;
}

// Delete History
if ($uri === '/api/delete-history') {
    $input = json_decode(file_get_contents('php://input'), true)?:[];
    $userId = $input['user_id']??'1';
    try {
        $db = init_db();
        $db->prepare("UPDATE prr_user_profiles SET history='[]', updated_at=datetime('now') WHERE user_id=?")->execute([$userId]);
        echo json_encode(['status'=>'ok','message'=>'Search history deleted.']);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['status'=>'error']); }
    exit;
}

// Homework – Hedge Superpool
if ($uri === '/api/homework') {
    ob_start(); error_reporting(0);
    try {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true)?:[];
        $ticker = $input['ticker']??'PFE'; $focus = $input['focus']??'general';
        $mainPool = get_pool($ticker); $mainCompany = $mainPool['company']??strtoupper($ticker);
        $mainActions = ask_ai("Generate exactly 5 strategic actions for $mainCompany", $ticker);
        $competitors = array_diff(['JNJ','MRK','LLY','ABBV','BMY','NVS','AZN','PFE'],[$ticker]);
        shuffle($competitors); $competitors = array_slice($competitors,0,5);
        $compActions = [];
        foreach($competitors as $comp) {
            $cp = get_pool($comp); $cn = $cp['company']??strtoupper($comp);
            $compActions[$comp] = ask_ai("Generate exactly 5 strategic actions for $cn", $comp);
        }
        $superpool = "Strategic Actions for $mainCompany ($ticker):\n".$mainActions."\n\n";
        foreach($compActions as $comp=>$act) $superpool .= "Strategic Actions for $comp:\n".$act."\n\n";
        $hedgePrompt = "You are a top-tier pharmaceutical hedge strategist. Based on the following collection of strategic actions that competitors are likely to pursue, generate exactly 10 'Hedge Business Advantages' for $mainCompany ($ticker). These are counter-strategies that neutralise competitor moves and create a sustainable competitive advantage. Format as a numbered list with concise, actionable items. Be extremely sharp and forward-looking.\n\n".$superpool;
        $hedgeAnswer = deepseek([['role'=>'system','content'=>'You are a secret corporate hedge strategist. Provide exactly 10 hedge business advantages.'],['role'=>'user','content'=>$hedgePrompt]]);
        if (empty($hedgeAnswer)) $hedgeAnswer = "1. Pre-empt competitor supply chain moves by securing exclusive API contracts\n2. Launch a shadow patent portfolio in emerging markets\n3. Deploy counter-detailing teams to defend key therapeutic areas\n4. Acquire biotech startups targeting competitor pipeline gaps\n5. Introduce a patient loyalty programme with real-world evidence\n6. Secure favourable pricing agreements ahead of competitor launches\n7. Invest in AI-driven clinical trial design to outpace rivals\n8. Form a strategic alliance with a key distributor in Asia\n9. Develop a rapid-response regulatory team for label expansions\n10. Create a corporate venture fund to invest in adjacencies";
        $db = init_db();
        $count = (int)$db->query("SELECT COUNT(*) FROM hedge_superpool")->fetchColumn();
        if ($count >= 3000) {
            $db->exec("DELETE FROM hedge_superpool WHERE id IN (SELECT id FROM hedge_superpool ORDER BY created_at ASC LIMIT ".($count-2999).")");
        }
        $stmt = $db->prepare("INSERT INTO hedge_superpool (ticker, focus, hedge_output) VALUES (?,?,?)");
        $stmt->execute([$ticker, $focus, $hedgeAnswer]);
        $derivatives = [];
        for ($i=0; $i<2; $i++) {
            $derivPrompt = "Based on the following hedge strategy:\n".$hedgeAnswer."\n\nGenerate 3 additional, even more refined business advantages that build exponentially on the original ones. Format as a numbered list.";
            $derivAnswer = deepseek([['role'=>'system','content'=>'You are a secret corporate hedge strategist. Provide 3 exponential business advantages.'],['role'=>'user','content'=>$derivPrompt]]);
            if (!empty($derivAnswer)) {
                $derivatives[] = $derivAnswer;
                $stmt->execute([$ticker,"exponential_derivative_".($i+1),$derivAnswer]);
            }
        }
        $output = "### 🛡️ Hedge Business Advantage Superpool for $mainCompany ($ticker)\n\n**Focus:** $focus\n\n".$hedgeAnswer;
        if (!empty($derivatives)) {
            $output .= "\n\n### ⚡ Exponential Derivatives Generated\n";
            foreach($derivatives as $d) $output .= $d."\n\n";
        }
        ob_end_clean();
        echo json_encode(['status'=>'complete','ticker'=>$ticker,'final'=>$output]);
    } catch (Throwable $e) {
        ob_end_clean(); http_response_code(500);
        echo json_encode(['status'=>'error','final'=>'Hedge assessment failed.']);
        error_log('Homework error: '.$e->getMessage());
    }
    exit;
}

// Hedge management endpoints
if ($uri === '/api/hedge-count') {
    $db = init_db(); $db->exec("CREATE TABLE IF NOT EXISTS hedge_superpool (id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT, focus TEXT, hedge_output TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $count = (int)$db->query("SELECT COUNT(*) FROM hedge_superpool")->fetchColumn();
    $warning = $count >= 2800 ? "⚠️ Hedge pool approaching 3000 limit ($count stored)" : "";
    echo json_encode(['count'=>$count,'warning'=>$warning]); exit;
}
if ($uri === '/api/hedge-show') {
    $db = init_db(); $db->exec("CREATE TABLE IF NOT EXISTS hedge_superpool (id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT, focus TEXT, hedge_output TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $stmt = $db->query("SELECT id, ticker, focus, created_at FROM hedge_superpool ORDER BY id DESC LIMIT 50");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
}
if ($uri === '/api/hedge-delete') {
    $db = init_db(); $db->exec("CREATE TABLE IF NOT EXISTS hedge_superpool (id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT, focus TEXT, hedge_output TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("DELETE FROM hedge_superpool WHERE id IN (SELECT id FROM hedge_superpool ORDER BY created_at ASC LIMIT 10)");
    echo json_encode(['status'=>'ok','message'=>'10 oldest solutions deleted']); exit;
}


// ─── Self‑test AI connection ───
if ($uri === '/api/test-ai') {
    header('Content-Type: application/json');
    $testMessages = [['role'=>'user','content'=>'In one sentence, explain why AI is useful for drug discovery.']];
    $result = deepseek($testMessages, 0, false); // use chat model for speed
    echo json_encode([
        'status' => $result ? 'ok' : 'fallback',
        'response' => $result ?: 'DeepSeek call failed – check DS_KEY'
    ]);
    exit;
}
// Serve dashboard
header("Content-Security-Policy: default-src * 'unsafe-inline' 'unsafe-eval' data:; script-src * 'unsafe-inline' 'unsafe-eval'; style-src * 'unsafe-inline';");
header('Content-Type: text/html');
readfile('dashboard.html');
