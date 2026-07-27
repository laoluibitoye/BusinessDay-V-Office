<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');

$user = Auth::require();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $s = $db->prepare("SELECT id, bank, account_number, account_name FROM irs_saved_accounts WHERE user_id=? ORDER BY id DESC");
        $s->execute([$user['id']]);
        echo json_encode(['ok'=>true, 'accounts'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>true, 'accounts'=>[]]);
    }
    exit;
}

// POST actions
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $bank    = trim($_POST['bank']           ?? '');
    $acctNo  = trim($_POST['account_number'] ?? '');
    $acctName= trim($_POST['account_name']   ?? '');
    if ($bank === '') { echo json_encode(['ok'=>false,'error'=>'Bank name required.']); exit; }
    try {
        // Upsert: if same account_number already saved for this user, update name/bank
        $chk = $db->prepare("SELECT id FROM irs_saved_accounts WHERE user_id=? AND account_number=?");
        $chk->execute([$user['id'], $acctNo]);
        if ($chk->fetch()) {
            $db->prepare("UPDATE irs_saved_accounts SET bank=?, account_name=? WHERE user_id=? AND account_number=?")
               ->execute([$bank, $acctName, $user['id'], $acctNo]);
        } else {
            $db->prepare("INSERT INTO irs_saved_accounts (user_id,bank,account_number,account_name) VALUES (?,?,?,?)")
               ->execute([$user['id'], $bank, $acctNo, $acctName]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>'Could not save account.']);
    }
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    // Enforce ownership — only delete own saved accounts
    $db->prepare("DELETE FROM irs_saved_accounts WHERE id=? AND user_id=?")->execute([$id, $user['id']]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
