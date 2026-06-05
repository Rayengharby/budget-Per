<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

class TransactionController {

    private static string $JOIN = "
        SELECT t.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
               u.name as user_name, u.avatar as user_avatar
        FROM transactions t
        LEFT JOIN categories c ON t.category_id = c.id
        LEFT JOIN users      u ON t.user_id      = u.id
    ";

    public static function index(): void {
        $user = authenticate(); $db = getDB();

        $st = $db->prepare('SELECT budget_id FROM budget_members WHERE user_id = ?');
        $st->execute([$user['id']]); $sharedIds = $st->fetchAll(PDO::FETCH_COLUMN);

        $where=[]; $params=[];
        if (!empty($sharedIds)) {
            $ph = implode(',', array_fill(0,count($sharedIds),'?'));
            $where[] = "(t.user_id = ? OR t.budget_id IN ($ph))";
            $params[] = $user['id'];
            foreach ($sharedIds as $bid) $params[]=(int)$bid;
        } else { $where[]='t.user_id = ?'; $params[]=$user['id']; }

        $type=($_GET['type']??''); $cat=($_GET['category']??''); $budget=($_GET['budget']??'');
        $start=($_GET['startDate']??''); $end=($_GET['endDate']??'');
        $page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(1,(int)($_GET['limit']??20)));

        if ($type)   { $where[]='t.type=?';        $params[]=$type; }
        if ($cat)    { $where[]='t.category_id=?'; $params[]=(int)$cat; }
        if ($budget) { $where[]='t.budget_id=?';   $params[]=(int)$budget; }
        if ($start)  { $where[]='t.date>=?';        $params[]=$start; }
        if ($end)    { $where[]='t.date<=?';        $params[]=$end; }

        $sql = implode(' AND ',$where);
        $cs=$db->prepare("SELECT COUNT(*) FROM transactions t WHERE $sql"); $cs->execute($params);
        $total=(int)$cs->fetchColumn();

        $st=$db->prepare(self::$JOIN." WHERE $sql ORDER BY t.date DESC,t.created_at DESC LIMIT ? OFFSET ?");
        $st->execute(array_merge($params,[$limit,($page-1)*$limit]));
        jsonResponse(['success'=>true,'total'=>$total,'page'=>$page,'transactions'=>array_map('fmtTransaction',$st->fetchAll())], 200);
    }

    public static function create(): void {
        $user=authenticate(); $db=getDB();
        $d=json_decode(file_get_contents('php://input'),true)??[];
        $type=$d['type']??''; $amount=(float)($d['amount']??0); $desc=trim($d['description']??'');
        $date=$d['date']??date('Y-m-d');
        $catId=isset($d['category'])&&$d['category'] ? (int)$d['category'] : null;
        $budgetId=isset($d['budget'])&&$d['budget']?(int)$d['budget']:null;
        $comment=$d['comment']??null;

        if (!in_array($type,['income','expense'],true)) { jsonResponse(['success'=>false,'message'=>'Type invalide.'], 400); return; }
        if ($amount<=0) { jsonResponse(['success'=>false,'message'=>'Le montant doit être positif.'], 400); return; }
        if (!$desc) { jsonResponse(['success'=>false,'message'=>'La description est requise.'], 400); return; }

        $db->prepare('INSERT INTO transactions (type,amount,description,date,category_id,user_id,budget_id,comment) VALUES (?,?,?,?,?,?,?,?)')->execute([$type,$amount,$desc,$date,$catId,$user['id'],$budgetId,$comment]);
        $id=(int)$db->lastInsertId();

        if ($budgetId && $type==='expense') {
            $bs=$db->prepare('SELECT name,global_limit,alert_threshold FROM budgets WHERE id=?'); $bs->execute([$budgetId]); $b=$bs->fetch();
            if ($b&&$b['global_limit']) {
                $sp=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE budget_id=? AND type='expense'"); $sp->execute([$budgetId]);
                $pct=round(((float)$sp->fetchColumn()/$b['global_limit'])*100);
                if ($pct>=$b['alert_threshold']) error_log("Budget alert: {$b['name']} at {$pct}%");
            }
        }

        $st=$db->prepare(self::$JOIN." WHERE t.id=?"); $st->execute([$id]);
        jsonResponse(['success'=>true,'transaction'=>fmtTransaction($st->fetch())], 201);
    }

    public static function update(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT * FROM transactions WHERE id=?'); $st->execute([$id]); $tx=$st->fetch();
        if (!$tx) { jsonResponse(['success'=>false,'message'=>'Transaction introuvable.'], 404); return; }
        if ($tx['user_id']!=$user['id']&&$user['role']!=='admin') { jsonResponse(['success'=>false,'message'=>'Accès refusé.'], 403); return; }

        $d=json_decode(file_get_contents('php://input'),true)??[]; $sets=[]; $params=[];
        foreach (['type','amount','description','date','comment'] as $f) { if (array_key_exists($f,$d)) { $sets[]="$f=?"; $params[]=$d[$f]; } }
        if (isset($d['category'])) { $sets[]='category_id=?'; $params[]=(int)$d['category']; }
        if (array_key_exists('budget',$d)) { $sets[]='budget_id=?'; $params[]=$d['budget']?(int)$d['budget']:null; }
        if ($sets) { $params[]=$id; $db->prepare('UPDATE transactions SET '.implode(',',$sets).' WHERE id=?')->execute($params); }

        $st=$db->prepare(self::$JOIN." WHERE t.id=?"); $st->execute([$id]);
        jsonResponse(['success'=>true,'transaction'=>fmtTransaction($st->fetch())], 200);
    }

    public static function delete(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT user_id FROM transactions WHERE id=?'); $st->execute([$id]); $tx=$st->fetch();
        if (!$tx) { jsonResponse(['success'=>false,'message'=>'Transaction introuvable.'], 404); return; }
        if ($tx['user_id']!=$user['id']&&$user['role']!=='admin') { jsonResponse(['success'=>false,'message'=>'Accès refusé.'], 403); return; }
        $db->prepare('DELETE FROM transactions WHERE id=?')->execute([$id]);
        jsonResponse(['success'=>true,'message'=>'Transaction supprimée.'], 200);
    }

    public static function stats(): void {
        $user=authenticate(); $db=getDB(); $uid=$user['id']; $som=date('Y-m-01');
        $st=$db->prepare("SELECT type,SUM(amount) as total FROM transactions WHERE user_id=? AND date>=? GROUP BY type");
        $st->execute([$uid,$som]); $income=0; $expense=0;
        foreach ($st->fetchAll() as $r) { if ($r['type']==='income') $income=(float)$r['total']; else $expense=(float)$r['total']; }

        $st=$db->prepare("SELECT c.id,c.name,c.icon,c.color,SUM(t.amount) as total FROM transactions t JOIN categories c ON t.category_id=c.id WHERE t.user_id=? AND t.type='expense' AND t.date>=? GROUP BY c.id ORDER BY total DESC");
        $st->execute([$uid,$som]);
        $byCategory=array_map(fn($r)=>['_id'=>(int)$r['id'],'name'=>$r['name'],'icon'=>$r['icon'],'color'=>$r['color'],'total'=>(float)$r['total']],$st->fetchAll());

        $st=$db->prepare("SELECT YEAR(date) as yr,MONTH(date) as mo,SUM(amount) as total FROM transactions WHERE user_id=? AND type='expense' GROUP BY yr,mo ORDER BY yr,mo LIMIT 6");
        $st->execute([$uid]);
        $evolution=array_map(fn($r)=>['_id'=>['year'=>(int)$r['yr'],'month'=>(int)$r['mo']],'total'=>(float)$r['total']],$st->fetchAll());

        jsonResponse(['success'=>true,'income'=>$income,'expense'=>$expense,'balance'=>$income-$expense,'byCategory'=>$byCategory,'evolution'=>$evolution], 200);
    }

    public static function exportPdf(): void {
        // Override JSON content-type set globally
        $user = authenticate(); $db = getDB();

        $st = $db->prepare('SELECT budget_id FROM budget_members WHERE user_id = ?');
        $st->execute([$user['id']]); $sharedIds = $st->fetchAll(PDO::FETCH_COLUMN);

        $where=[]; $params=[];
        if (!empty($sharedIds)) {
            $ph = implode(',', array_fill(0,count($sharedIds),'?'));
            $where[] = "(t.user_id = ? OR t.budget_id IN ($ph))";
            $params[] = $user['id'];
            foreach ($sharedIds as $bid) $params[]=(int)$bid;
        } else { $where[]='t.user_id = ?'; $params[]=$user['id']; }

        $type=($_GET['type']??''); $cat=($_GET['category']??''); $budget=($_GET['budget']??'');
        $start=($_GET['startDate']??''); $end=($_GET['endDate']??'');

        if ($type)   { $where[]='t.type=?';        $params[]=$type; }
        if ($cat)    { $where[]='t.category_id=?'; $params[]=(int)$cat; }
        if ($budget) { $where[]='t.budget_id=?';   $params[]=(int)$budget; }
        if ($start)  { $where[]='t.date>=?';        $params[]=$start; }
        if ($end)    { $where[]='t.date<=?';        $params[]=$end; }

        $sql = implode(' AND ',$where);

        $st=$db->prepare(self::$JOIN." WHERE $sql ORDER BY t.date DESC,t.created_at DESC");
        $st->execute($params);
        $transactions = $st->fetchAll();

        $totalIncome = 0.0;
        $totalExpense = 0.0;
        foreach ($transactions as $t) {
            if ($t['type'] === 'income') {
                $totalIncome += (float)$t['amount'];
            } else {
                $totalExpense += (float)$t['amount'];
            }
        }
        $netBalance = $totalIncome - $totalExpense;

        $html = self::buildPdfHtml($transactions, $user, $totalIncome, $totalExpense, $netBalance);

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Override JSON content-type with PDF headers
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="rapport_transactions.pdf"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $dompdf->output();
        exit;
    }

    private static function buildPdfHtml(array $transactions, array $user, float $totalIncome, float $totalExpense, float $netBalance): string {
        $dateStr     = date('d/m/Y H:i');
        $incomeStr   = number_format($totalIncome,  2, ',', ' ') . ' TND';
        $expenseStr  = number_format($totalExpense, 2, ',', ' ') . ' TND';
        $balanceStr  = number_format($netBalance,   2, ',', ' ') . ' TND';
        $balanceColor = $netBalance >= 0 ? '#22c55e' : '#ef4444';
        $userName    = htmlspecialchars($user['name'] ?? '');
        $userEmail   = htmlspecialchars($user['email'] ?? '');

        $rowsHtml = '';
        foreach ($transactions as $t) {
            $date      = date('d/m/Y', strtotime($t['date']));
            $desc      = htmlspecialchars($t['description']);
            if (!empty($t['comment'])) {
                $desc .= ' <br><span style="font-size:8px;color:#777;">&#128172; ' . htmlspecialchars($t['comment']) . '</span>';
            }
            $cat       = $t['category_name'] ? (htmlspecialchars($t['category_name'])) : '-';
            $typeLabel = $t['type'] === 'income' ? 'Revenu' : 'Depense';
            $typeColor = $t['type'] === 'income' ? '#22c55e' : '#ef4444';
            $amount    = number_format((float)$t['amount'], 2, ',', ' ') . ' TND';
            $prefix    = $t['type'] === 'income' ? '+' : '-';

            $rowsHtml .= '
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #e2e8f0;font-size:11px;">' . $date . '</td>
                    <td style="padding:8px;border-bottom:1px solid #e2e8f0;font-size:11px;">' . $desc . '</td>
                    <td style="padding:8px;border-bottom:1px solid #e2e8f0;font-size:11px;">' . $cat . '</td>
                    <td style="padding:8px;border-bottom:1px solid #e2e8f0;font-size:11px;color:' . $typeColor . ';font-weight:bold;">' . $typeLabel . '</td>
                    <td style="padding:8px;border-bottom:1px solid #e2e8f0;font-size:11px;text-align:right;color:' . $typeColor . ';font-weight:bold;">' . $prefix . ' ' . $amount . '</td>
                </tr>';
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport de Transactions</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.5; margin: 20px; }
        .header { margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; overflow: hidden; }
        .logo { font-size: 20px; font-weight: bold; color: #4f46e5; float: left; }
        .meta { float: right; text-align: right; font-size: 10px; color: #64748b; }
        .clearfix { clear: both; }
        .title { font-size: 18px; font-weight: bold; margin: 15px 0 5px 0; color: #0f172a; }
        .user-info { font-size: 12px; color: #475569; margin-bottom: 20px; }
        .stats-table { width: 100%; margin-bottom: 25px; border-collapse: collapse; }
        .stat-cell { width: 33%; padding: 5px; }
        .stat-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center; }
        .stat-label { font-size: 9px; text-transform: uppercase; color: #64748b; font-weight: bold; letter-spacing: 0.05em; }
        .stat-value { font-size: 15px; font-weight: bold; margin-top: 4px; }
        .tx-table { width: 100%; border-collapse: collapse; }
        .tx-table th { background: #f1f5f9; padding: 8px; text-align: left; font-size: 9px; text-transform: uppercase; color: #475569; font-weight: bold; border-bottom: 2px solid #cbd5e1; letter-spacing: 0.05em; }
        .footer { margin-top: 40px; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">BudgetCollab</div>
        <div class="meta">Rapport genere le ' . $dateStr . '</div>
        <div class="clearfix"></div>
    </div>

    <div class="title">Rapport de Transactions</div>
    <div class="user-info">Utilisateur : <strong>' . $userName . '</strong> (' . $userEmail . ')</div>

    <table class="stats-table">
        <tr>
            <td class="stat-cell">
                <div class="stat-card">
                    <div class="stat-label">Revenus</div>
                    <div class="stat-value" style="color:#22c55e;">+ ' . $incomeStr . '</div>
                </div>
            </td>
            <td class="stat-cell">
                <div class="stat-card">
                    <div class="stat-label">Depenses</div>
                    <div class="stat-value" style="color:#ef4444;">- ' . $expenseStr . '</div>
                </div>
            </td>
            <td class="stat-cell">
                <div class="stat-card">
                    <div class="stat-label">Solde Net</div>
                    <div class="stat-value" style="color:' . $balanceColor . ';">' . $balanceStr . '</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="tx-table">
        <thead>
            <tr>
                <th style="width:13%;">Date</th>
                <th style="width:37%;">Description</th>
                <th style="width:22%;">Categorie</th>
                <th style="width:13%;">Type</th>
                <th style="width:15%;text-align:right;">Montant</th>
            </tr>
        </thead>
        <tbody>
            ' . $rowsHtml . '
        </tbody>
    </table>

    <div class="footer">BudgetCollab &mdash; Application de gestion collaborative de budget</div>
</body>
</html>';

        return $html;
    }
}
