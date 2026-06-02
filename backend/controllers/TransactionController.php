<?php
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
}
