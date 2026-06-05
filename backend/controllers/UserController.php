<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

class UserController {

    public static function me(): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT id,name,email,role,is_active,avatar,created_at FROM users WHERE id=?'); $st->execute([$user['id']]);
        $row=$st->fetch();
        if (!$row) { jsonResponse(['success'=>false,'message'=>'Utilisateur introuvable.'], 404); return; }
        jsonResponse(['success'=>true,'user'=>fmtUser($row)], 200);
    }

    public static function update(): void {
        $user=authenticate(); $db=getDB();
        $d=json_decode(file_get_contents('php://input'),true)??[];
        $sets=[]; $params=[];
        if (isset($d['name']))   { $sets[]='name=?';   $params[]=trim($d['name']); }
        if (isset($d['avatar'])) { $sets[]='avatar=?'; $params[]=$d['avatar']; }
        if ($sets) { $params[]=$user['id']; $db->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($params); }
        $st=$db->prepare('SELECT id,name,email,role,is_active,avatar,created_at FROM users WHERE id=?'); $st->execute([$user['id']]);
        jsonResponse(['success'=>true,'user'=>fmtUser($st->fetch())], 200);
    }

    public static function updatePassword(): void {
        $user=authenticate(); $db=getDB();
        $d=json_decode(file_get_contents('php://input'),true)??[];
        $curr=$d['currentPassword']??''; $new=$d['newPassword']??'';
        if (!$curr||!$new) { jsonResponse(['success'=>false,'message'=>'Les deux mots de passe sont requis.'], 400); return; }
        if (strlen($new)<6) { jsonResponse(['success'=>false,'message'=>'Le nouveau mot de passe doit contenir au moins 6 caractères.'], 400); return; }
        $st=$db->prepare('SELECT password FROM users WHERE id=?'); $st->execute([$user['id']]); $row=$st->fetch();
        if (!password_verify($curr,$row['password'])) { jsonResponse(['success'=>false,'message'=>'Mot de passe actuel incorrect.'], 401); return; }
        $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($new,PASSWORD_BCRYPT,['cost'=>12]),$user['id']]);
        jsonResponse(['success'=>true,'message'=>'Mot de passe mis à jour.'], 200);
    }
}
