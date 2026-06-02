<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

class BudgetController {

    public static function index(): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare("SELECT DISTINCT b.* FROM budgets b LEFT JOIN budget_members bm ON b.id=bm.budget_id WHERE b.owner_id=? OR bm.user_id=? ORDER BY b.created_at DESC");
        $st->execute([$user['id'],$user['id']]);
        jsonResponse(['success'=>true,'budgets'=>array_map(fn($b)=>fmtBudget($b,$db),$st->fetchAll())], 200);
    }

    public static function create(): void {
        $user=authenticate(); $db=getDB();
        $d=json_decode(file_get_contents('php://input'),true)??[];
        $name=trim($d['name']??''); $period=$d['period']??'monthly';
        $start=$d['startDate']??$d['start_date']??''; $end=$d['endDate']??$d['end_date']??null;
        $limit=isset($d['globalLimit'])&&$d['globalLimit']!==''&&$d['globalLimit']!==null?(float)$d['globalLimit']:null;
        $threshold=isset($d['alertThreshold'])?(int)$d['alertThreshold']:80;
        $isShared=(bool)($d['isShared']??false);

        if (!$name||!$start) { jsonResponse(['success'=>false,'message'=>'Nom et date de début requis.'], 400); return; }

        $db->prepare('INSERT INTO budgets (name,is_shared,owner_id,period,start_date,end_date,global_limit,alert_threshold) VALUES (?,?,?,?,?,?,?,?)')->execute([$name,$isShared?1:0,$user['id'],$period,$start,$end,$limit,$threshold]);
        $id=(int)$db->lastInsertId();
        $st=$db->prepare('SELECT * FROM budgets WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'budget'=>fmtBudget($st->fetch(),$db)], 201);
    }

    public static function addMember(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT * FROM budgets WHERE id=?'); $st->execute([$id]); $b=$st->fetch();
        if (!$b) { jsonResponse(['success'=>false,'message'=>'Budget introuvable.'], 404); return; }
        if ($b['owner_id']!=$user['id']) { jsonResponse(['success'=>false,'message'=>'Seul le propriétaire peut ajouter des membres.'], 403); return; }

        $d=json_decode(file_get_contents('php://input'),true)??[];
        $email=strtolower(trim($d['email']??''));
        $st=$db->prepare('SELECT id,name,email FROM users WHERE email=?'); $st->execute([$email]); $member=$st->fetch();
        if (!$member) { jsonResponse(['success'=>false,'message'=>'Utilisateur avec cet email introuvable.'], 404); return; }
        if ($member['id']==$b['owner_id']) { jsonResponse(['success'=>false,'message'=>'Le propriétaire est déjà membre.'], 400); return; }

        $ck=$db->prepare('SELECT 1 FROM budget_members WHERE budget_id=? AND user_id=?'); $ck->execute([$id,$member['id']]);
        if ($ck->fetch()) { jsonResponse(['success'=>false,'message'=>'Cet utilisateur est déjà membre.'], 400); return; }

        $db->prepare('INSERT INTO budget_members (budget_id,user_id) VALUES (?,?)')->execute([$id,$member['id']]);
        $db->prepare('UPDATE budgets SET is_shared=1 WHERE id=?')->execute([$id]);
        $st=$db->prepare('SELECT * FROM budgets WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'budget'=>fmtBudget($st->fetch(),$db),'message'=>"{$member['name']} a été ajouté au budget."], 200);
    }

    public static function summary(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT * FROM budgets WHERE id=?'); $st->execute([$id]); $b=$st->fetch();
        if (!$b) { jsonResponse(['success'=>false,'message'=>'Budget introuvable.'], 404); return; }
        $ck=$db->prepare('SELECT 1 FROM budget_members WHERE budget_id=? AND user_id=?'); $ck->execute([$id,$user['id']]);
        if ($b['owner_id']!=$user['id']&&!$ck->fetch()&&$user['role']!=='admin') { jsonResponse(['success'=>false,'message'=>'Accès refusé.'], 403); return; }
        $sp=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE budget_id=? AND type='expense'"); $sp->execute([$id]);
        $totalSpent=(float)$sp->fetchColumn();
        $pctGlobal=$b['global_limit']?round(($totalSpent/$b['global_limit'])*100):null;
        $threshold=(int)$b['alert_threshold'];
        $status=$pctGlobal===null?'ok':($pctGlobal>=100?'exceeded':($pctGlobal>=$threshold?'warning':'ok'));
        jsonResponse(['success'=>true,'budget'=>fmtBudget($b,$db),'totalSpent'=>$totalSpent,'pctGlobal'=>$pctGlobal,'status'=>$status], 200);
    }

    public static function delete(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT owner_id FROM budgets WHERE id=?'); $st->execute([$id]); $b=$st->fetch();
        if (!$b) { jsonResponse(['success'=>false,'message'=>'Budget introuvable.'], 404); return; }
        if ($b['owner_id']!=$user['id']&&$user['role']!=='admin') { jsonResponse(['success'=>false,'message'=>'Accès refusé.'], 403); return; }
        $db->prepare('DELETE FROM budgets WHERE id=?')->execute([$id]);
        jsonResponse(['success'=>true,'message'=>'Budget supprimé.'], 200);
    }
}
