<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

class AdminController {

    public static function stats(): void {
        $user=authenticate(); requireAdmin($user); $db=getDB();
        $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $budgets=(int)$db->query('SELECT COUNT(*) FROM budgets')->fetchColumn();
        $transactions=(int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $st=$db->query("SELECT type,SUM(amount) as total FROM transactions GROUP BY type");
        $globalStats=array_map(fn($r)=>['_id'=>$r['type'],'total'=>(float)$r['total']],$st->fetchAll());
        jsonResponse(['success'=>true,'users'=>$users,'budgets'=>$budgets,'transactions'=>$transactions,'globalStats'=>$globalStats], 200);
    }

    public static function users(): void {
        $user=authenticate(); requireAdmin($user);
        $st=getDB()->query('SELECT id,name,email,role,is_active,avatar,created_at FROM users ORDER BY created_at DESC');
        jsonResponse(['success'=>true,'users'=>array_map('fmtUser',$st->fetchAll())], 200);
    }

    public static function activate(int $id): void {
        $user=authenticate(); requireAdmin($user); $db=getDB();
        $st=$db->prepare('UPDATE users SET is_active=1 WHERE id=?'); $st->execute([$id]);
        if (!$st->rowCount()) { jsonResponse(['success'=>false,'message'=>'Utilisateur introuvable.'], 404); return; }
        $st=$db->prepare('SELECT id,name,email,role,is_active,avatar,created_at FROM users WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'message'=>'Compte activé.','user'=>fmtUser($st->fetch())], 200);
    }

    public static function deactivate(int $id): void {
        $user=authenticate(); requireAdmin($user); $db=getDB();
        $db->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$id]);
        $st=$db->prepare('SELECT id,name,email,role,is_active,avatar,created_at FROM users WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'message'=>'Compte désactivé.','user'=>fmtUser($st->fetch())], 200);
    }

    public static function changeRole(int $id): void {
        $user=authenticate(); requireAdmin($user); $db=getDB();
        $d=json_decode(file_get_contents('php://input'),true)??[]; $role=$d['role']??'';
        if (!in_array($role,['user','admin'],true)) { jsonResponse(['success'=>false,'message'=>'Rôle invalide.'], 400); return; }
        $db->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$id]);
        $st=$db->prepare('SELECT id,name,email,role,is_active,avatar,created_at FROM users WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'user'=>fmtUser($st->fetch())], 200);
    }

    public static function deleteUser(int $id): void {
        $user=authenticate(); requireAdmin($user);
        if ($id===(int)$user['id']) { jsonResponse(['success'=>false,'message'=>'Impossible de supprimer son propre compte.'], 400); return; }
        getDB()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        jsonResponse(['success'=>true,'message'=>'Compte supprimé.'], 200);
    }

    public static function budgets(): void {
        $user=authenticate(); requireAdmin($user); $db=getDB();
        $st=$db->query("SELECT b.*,u.name as owner_name,u.email as owner_email FROM budgets b JOIN users u ON b.owner_id=u.id WHERE b.is_shared=1 ORDER BY b.created_at DESC");
        $budgets=array_map(fn($b)=>array_merge(fmtBudget($b,$db),['owner'=>['id'=>(int)$b['owner_id'],'name'=>$b['owner_name'],'email'=>$b['owner_email']]]),$st->fetchAll());
        jsonResponse(['success'=>true,'budgets'=>$budgets], 200);
    }
}
