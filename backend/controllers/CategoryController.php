<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

class CategoryController {

    public static function index(): void {
        authenticate(); $db=getDB();
        $st=$db->query('SELECT * FROM categories ORDER BY name ASC');
        jsonResponse(['success'=>true,'categories'=>array_map('fmtCategory',$st->fetchAll())], 200);
    }

    public static function create(): void {
        $user=authenticate(); $db=getDB();
        $d=json_decode(file_get_contents('php://input'),true)??[];
        $name=trim($d['name']??''); $icon=$d['icon']??'📦'; $color=$d['color']??'#6b7280';
        if (!$name) { jsonResponse(['success'=>false,'message'=>'Le nom est requis.'], 400); return; }
        $db->prepare('INSERT INTO categories (name,icon,color,is_default,created_by) VALUES (?,?,?,0,?)')->execute([$name,$icon,$color,$user['id']]);
        $id=(int)$db->lastInsertId();
        $st=$db->prepare('SELECT * FROM categories WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'category'=>fmtCategory($st->fetch())], 201);
    }

    public static function update(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT * FROM categories WHERE id=?'); $st->execute([$id]); $cat=$st->fetch();
        if (!$cat) { jsonResponse(['success'=>false,'message'=>'Catégorie introuvable.'], 404); return; }
        if (!$cat['is_default']&&$cat['created_by']!=$user['id']&&$user['role']!=='admin') { jsonResponse(['success'=>false,'message'=>'Accès refusé.'], 403); return; }
        $d=json_decode(file_get_contents('php://input'),true)??[]; $sets=[]; $params=[];
        foreach (['name','icon','color'] as $f) { if (isset($d[$f])) { $sets[]="$f=?"; $params[]=$d[$f]; } }
        if ($sets) { $params[]=$id; $db->prepare('UPDATE categories SET '.implode(',',$sets).' WHERE id=?')->execute($params); }
        $st=$db->prepare('SELECT * FROM categories WHERE id=?'); $st->execute([$id]);
        jsonResponse(['success'=>true,'category'=>fmtCategory($st->fetch())], 200);
    }

    public static function delete(int $id): void {
        $user=authenticate(); $db=getDB();
        $st=$db->prepare('SELECT * FROM categories WHERE id=?'); $st->execute([$id]); $cat=$st->fetch();
        if (!$cat) { jsonResponse(['success'=>false,'message'=>'Catégorie introuvable.'], 404); return; }
        if (!$cat['is_default']&&$cat['created_by']!=$user['id']&&$user['role']!=='admin') { jsonResponse(['success'=>false,'message'=>'Accès refusé.'], 403); return; }
        $db->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
        jsonResponse(['success'=>true,'message'=>'Catégorie supprimée.'], 200);
    }
}
