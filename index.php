<?php
session_start();

/* ===================== DATABASE ===================== */
$host = "localhost"; $db = "ecommerce_db"; $user = "root"; $pass = "";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Database connection failed. Import database.sql first. Error: ".$conn->connect_error);
$conn->set_charset("utf8mb4");

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect($url){ header("Location: $url"); exit; }
function flash($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function money($n){ return "₹".number_format((float)$n,2); }
function logged(){ return isset($_SESSION['user_id']); }
function admin(){ return isset($_SESSION['role']) && $_SESSION['role']==='admin'; }
function require_login(){ if(!logged()){ flash('error','Please login first.'); redirect('?page=login'); } }
function require_admin(){ if(!admin()){ flash('error','Admin access required.'); redirect('?page=login'); } }
function get_user($conn,$id){
    $s=$conn->prepare("SELECT * FROM users WHERE id=?"); $s->bind_param("i",$id); $s->execute();
    return $s->get_result()->fetch_assoc();
}
function product_price($p){ return max(0,(float)$p['price']-(float)$p['discount']); }
function cart_items($conn,$uid){
    $s=$conn->prepare("SELECT c.*,p.product_name,p.price,p.discount,p.stock,p.image,p.status,cat.category_name
                       FROM cart c JOIN products p ON p.id=c.product_id LEFT JOIN categories cat ON cat.id=p.category_id
                       WHERE c.user_id=? ORDER BY c.id DESC");
    $s->bind_param("i",$uid); $s->execute(); return $s->get_result();
}
function cart_totals($conn,$uid){
    $r=cart_items($conn,$uid); $sub=0;
    while($p=$r->fetch_assoc()) $sub += product_price($p)*$p['quantity'];
    $coupon=$_SESSION['coupon']??null; $disc=0;
    if($coupon && $sub >= $coupon['minimum_order']){
        if($coupon['discount_type']==='percent') $disc=$sub*$coupon['discount_value']/100;
        else $disc=$coupon['discount_value'];
        if($coupon['maximum_discount']>0) $disc=min($disc,$coupon['maximum_discount']);
        $disc=min($disc,$sub);
    } else if($coupon) unset($_SESSION['coupon']);
    $shipping=($sub-$disc>0 && $sub-$disc<1000)?80:0;
    return [$sub,$disc,$shipping,$sub-$disc+$shipping];
}
function active_coupon($conn,$code){
    $s=$conn->prepare("SELECT * FROM coupons WHERE coupon_code=? AND status='active' AND start_date<=CURDATE() AND expiry_date>=CURDATE()");
    $s->bind_param("s",$code); $s->execute(); return $s->get_result()->fetch_assoc();
}

/* ===================== ACTIONS ===================== */
$action=$_GET['action']??'';

if($action==='login' && $_SERVER['REQUEST_METHOD']==='POST'){
    $email=trim($_POST['email']); $password=$_POST['password'];
    $s=$conn->prepare("SELECT * FROM users WHERE email=? LIMIT 1"); $s->bind_param("s",$email); $s->execute(); $u=$s->get_result()->fetch_assoc();
    if(!$u || !password_verify($password,$u['password'])) flash('error','Invalid email or password.');
    elseif($u['status']!=='active') flash('error','Your account is blocked/inactive.');
    else { session_regenerate_id(true); $_SESSION['user_id']=$u['id']; $_SESSION['name']=$u['name']; $_SESSION['role']=$u['role']; flash('success','Welcome back, '.e($u['name']).'!'); redirect($u['role']==='admin'?'?page=admin':'?page=home'); }
    redirect('?page=login');
}
if($action==='register' && $_SERVER['REQUEST_METHOD']==='POST'){
    $name=trim($_POST['name']); $email=trim($_POST['email']); $mobile=trim($_POST['mobile']); $password=$_POST['password'];
    if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<6) flash('error','Enter valid details. Password must be at least 6 characters.');
    else {
        $s=$conn->prepare("SELECT id FROM users WHERE email=?"); $s->bind_param("s",$email); $s->execute();
        if($s->get_result()->num_rows) flash('error','Email already registered.');
        else { $hash=password_hash($password,PASSWORD_DEFAULT); $s=$conn->prepare("INSERT INTO users(name,email,password,mobile,role,status) VALUES(?,?,?,?,'user','active')"); $s->bind_param("ssss",$name,$email,$hash,$mobile); $s->execute(); flash('success','Registration successful. Please login.'); redirect('?page=login'); }
    }
    redirect('?page=register');
}
if($action==='logout'){ session_destroy(); redirect('?page=home'); }

if($action==='addcart'){
    require_login(); $pid=(int)$_POST['product_id']; $qty=max(1,(int)$_POST['quantity']);
    $s=$conn->prepare("SELECT stock,status FROM products WHERE id=?"); $s->bind_param("i",$pid); $s->execute(); $p=$s->get_result()->fetch_assoc();
    if(!$p || $p['status']!=='active' || $p['stock']<1) flash('error','Product is unavailable.');
    else {
        $qty=min($qty,(int)$p['stock']);
        $s=$conn->prepare("SELECT id,quantity FROM cart WHERE user_id=? AND product_id=?"); $s->bind_param("ii",$_SESSION['user_id'],$pid); $s->execute(); $old=$s->get_result()->fetch_assoc();
        if($old){ $new=min($old['quantity']+$qty,(int)$p['stock']); $s=$conn->prepare("UPDATE cart SET quantity=? WHERE id=?"); $s->bind_param("ii",$new,$old['id']); $s->execute(); }
        else { $s=$conn->prepare("INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,?)"); $s->bind_param("iii",$_SESSION['user_id'],$pid,$qty); $s->execute(); }
        flash('success','Product added to cart.');
    }
    redirect($_SERVER['HTTP_REFERER']??'?page=products');
}
if($action==='cart_update'){
    require_login(); $cid=(int)$_POST['cart_id']; $qty=(int)$_POST['quantity'];
    $s=$conn->prepare("SELECT c.id,p.stock FROM cart c JOIN products p ON p.id=c.product_id WHERE c.id=? AND c.user_id=?"); $s->bind_param("ii",$cid,$_SESSION['user_id']); $s->execute(); $x=$s->get_result()->fetch_assoc();
    if($x){ if($qty<=0){$s=$conn->prepare("DELETE FROM cart WHERE id=?");$s->bind_param("i",$cid);} else {$qty=min($qty,(int)$x['stock']);$s=$conn->prepare("UPDATE cart SET quantity=? WHERE id=?");$s->bind_param("ii",$qty,$cid);} $s->execute(); }
    redirect('?page=cart');
}
if($action==='remove_cart'){ require_login(); $cid=(int)$_GET['id']; $s=$conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");$s->bind_param("ii",$cid,$_SESSION['user_id']);$s->execute(); redirect('?page=cart'); }
if($action==='coupon'){
    require_login(); $code=strtoupper(trim($_POST['coupon_code'])); [$sub]=cart_totals($conn,$_SESSION['user_id']);
    $c=active_coupon($conn,$code);
    if(!$c) flash('error','Invalid, expired, inactive, or unavailable coupon.');
    elseif($sub < $c['minimum_order']) flash('error','Minimum order for this coupon is '.money($c['minimum_order']).'.');
    else { $_SESSION['coupon']=$c; flash('success','Coupon applied successfully.'); }
    redirect('?page=cart');
}
if($action==='remove_coupon'){ unset($_SESSION['coupon']); redirect('?page=cart'); }

if($action==='checkout' && $_SERVER['REQUEST_METHOD']==='POST'){
    require_login(); $items=cart_items($conn,$_SESSION['user_id']); if($items->num_rows<1){flash('error','Your cart is empty.');redirect('?page=cart');}
    [$sub,$disc,$ship,$final]=cart_totals($conn,$_SESSION['user_id']);
    $pm=$_POST['payment_method']==='online'?'Online Payment / Demo':'Cash on Delivery';
    $fields=['name','email','mobile','address','city','state','pincode','country'];
    foreach($fields as $f) if(trim($_POST[$f]??'')===''){flash('error','Please fill all checkout fields.');redirect('?page=checkout');}
    $conn->begin_transaction();
    try{
        foreach($items as $it) if($it['stock']<$it['quantity']) throw new Exception("Not enough stock for ".$it['product_name']);
        $s=$conn->prepare("INSERT INTO orders(user_id,total_amount,coupon_code,discount,shipping_charge,final_amount,payment_method,payment_status,order_status,delivered_status,shipping_name,shipping_email,shipping_mobile,shipping_address,shipping_city,shipping_state,shipping_pincode,created_at) VALUES(?,?,?,?,?,?,?,'pending','Pending','Not Delivered',?,?,?,?,?,?,?,NOW())");
        $couponcode=$_SESSION['coupon']['coupon_code']??null;
        $s->bind_param("idddds sssssssss",$_SESSION['user_id'],$sub,$couponcode,$disc,$ship,$final,$pm,$_POST['name'],$_POST['email'],$_POST['mobile'],$_POST['address'],$_POST['city'],$_POST['state'],$_POST['pincode']);
    }catch(Throwable $ex){ $conn->rollback(); flash('error',$ex->getMessage()); redirect('?page=checkout'); }
    /* Rebind cleanly because the compact statement above is intentionally replaced below. */
    $conn->rollback();
    $conn->begin_transaction();
    try{
        $couponcode=$_SESSION['coupon']['coupon_code']??null;
        $sql="INSERT INTO orders(user_id,total_amount,coupon_code,discount,shipping_charge,final_amount,payment_method,payment_status,order_status,delivered_status,shipping_name,shipping_email,shipping_mobile,shipping_address,shipping_city,shipping_state,shipping_pincode) VALUES(?,?,?,?,?,?,?,'pending','Pending','Not Delivered',?,?,?,?,?,?,?)";
        $s=$conn->prepare($sql);
        $s->bind_param("idddds ssssssss",$_SESSION['user_id'],$sub,$couponcode,$disc,$ship,$final,$pm,$_POST['name'],$_POST['email'],$_POST['mobile'],$_POST['address'],$_POST['city'],$_POST['state'],$_POST['pincode']);
        /* Correct PHP type string without spaces */
        $s->close();
        $s=$conn->prepare($sql);
        $s->bind_param("idsddds" . "sssssss",$_SESSION['user_id'],$sub,$couponcode,$disc,$ship,$final,$pm,$_POST['name'],$_POST['email'],$_POST['mobile'],$_POST['address'],$_POST['city'],$_POST['state'],$_POST['pincode']);
        $s->execute(); $oid=$conn->insert_id;
        $items=cart_items($conn,$_SESSION['user_id']);
        while($it=$items->fetch_assoc()){
            $price=product_price($it); $st=$price*$it['quantity'];
            $s=$conn->prepare("INSERT INTO order_items(order_id,product_id,product_name,price,quantity,subtotal) VALUES(?,?,?,?,?,?)");
            $s->bind_param("iisdid",$oid,$it['product_id'],$it['product_name'],$price,$it['quantity'],$st); $s->execute();
            $s=$conn->prepare("UPDATE products SET stock=stock-? WHERE id=?"); $s->bind_param("ii",$it['quantity'],$it['product_id']); $s->execute();
        }
        $tx='DEMO-'.date('YmdHis').'-'.$oid;
        $pstatus='success';
        $s=$conn->prepare("INSERT INTO payments(order_id,user_id,payment_method,transaction_id,amount,payment_status,payment_date) VALUES(?,?,?,?,?, ?,NOW())");
        $s->bind_param("iissds",$oid,$_SESSION['user_id'],$pm,$tx,$final,$pstatus); $s->execute();
        $s=$conn->prepare("DELETE FROM cart WHERE user_id=?");$s->bind_param("i",$_SESSION['user_id']);$s->execute();
        unset($_SESSION['coupon']); $conn->commit(); redirect('?page=success&id='.$oid);
    }catch(Throwable $ex){$conn->rollback();flash('error','Order failed: '. $ex->getMessage());redirect('?page=checkout');}
}

if($action==='profile' && $_SERVER['REQUEST_METHOD']==='POST'){
    require_login(); $s=$conn->prepare("UPDATE users SET name=?,mobile=?,address=?,city=?,state=?,pincode=? WHERE id=?");$s->bind_param("ssssssi",$_POST['name'],$_POST['mobile'],$_POST['address'],$_POST['city'],$_POST['state'],$_POST['pincode'],$_SESSION['user_id']);$s->execute();$_SESSION['name']=$_POST['name'];flash('success','Profile updated.');redirect('?page=profile');
}
if($action==='password' && $_SERVER['REQUEST_METHOD']==='POST'){
    require_login();$u=get_user($conn,$_SESSION['user_id']);
    if(!password_verify($_POST['current'],$u['password'])) flash('error','Current password is incorrect.');
    elseif(strlen($_POST['new'])<6) flash('error','New password must be at least 6 characters.');
    elseif($_POST['new']!==$_POST['confirm']) flash('error','Passwords do not match.');
    else {$h=password_hash($_POST['new'],PASSWORD_DEFAULT);$s=$conn->prepare("UPDATE users SET password=? WHERE id=?");$s->bind_param("si",$h,$_SESSION['user_id']);$s->execute();flash('success','Password changed successfully.');}
    redirect('?page=password');
}
if($action==='contact' && $_SERVER['REQUEST_METHOD']==='POST'){
    $s=$conn->prepare("INSERT INTO contact_messages(name,email,mobile,subject,message) VALUES(?,?,?,?,?)");$s->bind_param("sssss",$_POST['name'],$_POST['email'],$_POST['mobile'],$_POST['subject'],$_POST['message']);$s->execute();flash('success','Thank you! Your message was sent.');redirect('?page=contact');
}
if($action==='reorder'){
    require_login();$oid=(int)$_GET['id'];$s=$conn->prepare("SELECT oi.product_id,oi.quantity,p.stock,p.status FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id WHERE o.id=? AND o.user_id=?");$s->bind_param("ii",$oid,$_SESSION['user_id']);$s->execute();
    while($x=$s->get_result()->fetch_assoc()){ $q=min($x['quantity'],$x['stock']); if($q>0&&$x['status']==='active'){ $a=$conn->prepare("SELECT id,quantity FROM cart WHERE user_id=? AND product_id=?");$a->bind_param("ii",$_SESSION['user_id'],$x['product_id']);$a->execute();$old=$a->get_result()->fetch_assoc(); if($old){$n=min($old['quantity']+$q,$x['stock']);$a=$conn->prepare("UPDATE cart SET quantity=? WHERE id=?");$a->bind_param("ii",$n,$old['id']);}else{$a=$conn->prepare("INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,?)");$a->bind_param("iii",$_SESSION['user_id'],$x['product_id'],$q);} $a->execute();}}
    flash('success','Available products were added to your cart.');redirect('?page=cart');
}

/* ===================== ADMIN CRUD ===================== */
if($action==='save_category'){require_admin();$id=(int)($_POST['id']??0);$name=trim($_POST['category_name']);$desc=trim($_POST['description']);$status=$_POST['status']==='discontinued'?'discontinued':'active';if($id){$s=$conn->prepare("UPDATE categories SET category_name=?,description=?,status=? WHERE id=?");$s->bind_param("sssi",$name,$desc,$status,$id);}else{$s=$conn->prepare("INSERT INTO categories(category_name,description,status) VALUES(?,?,?)");$s->bind_param("sss",$name,$desc,$status);} $s->execute();flash('success','Category saved.');redirect('?page=admin_categories');}
if($action==='delete_category'){require_admin();$id=(int)$_GET['id'];$s=$conn->prepare("DELETE FROM categories WHERE id=?");$s->bind_param("i",$id);$s->execute();flash('success','Category deleted.');redirect('?page=admin_categories');}
if($action==='save_product'){require_admin();$id=(int)($_POST['id']??0);$cat=(int)$_POST['category_id'];$s=$conn->prepare("SELECT status FROM categories WHERE id=?");$s->bind_param("i",$cat);$s->execute();$c=$s->get_result()->fetch_assoc();if(!$c||$c['status']!=='active'){flash('error','Cannot add a product to a discontinued category.');redirect('?page=admin_products');}
    $name=trim($_POST['product_name']);$desc=trim($_POST['description']);$price=(float)$_POST['price'];$discount=(float)$_POST['discount'];$stock=(int)$_POST['stock'];$sku=trim($_POST['sku']);$image=trim($_POST['image']);$status=$_POST['status']==='inactive'?'inactive':'active';
    if($id){$s=$conn->prepare("UPDATE products SET category_id=?,product_name=?,description=?,price=?,discount=?,stock=?,sku=?,image=?,status=? WHERE id=?");$s->bind_param("issddisssi",$cat,$name,$desc,$price,$discount,$stock,$sku,$image,$status,$id);}
    else{$s=$conn->prepare("INSERT INTO products(category_id,product_name,description,price,discount,stock,sku,image,status) VALUES(?,?,?,?,?,?,?,?,?)");$s->bind_param("issddisss",$cat,$name,$desc,$price,$discount,$stock,$sku,$image,$status);}
    $s->execute();$pid=$id?:$conn->insert_id;
    if(!empty($_FILES['image_file']['name']) && is_uploaded_file($_FILES['image_file']['tmp_name'])){ $dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0777,true);$ext=strtolower(pathinfo($_FILES['image_file']['name'],PATHINFO_EXTENSION));if(in_array($ext,['jpg','jpeg','png','webp'])){$fn='p_'.time().'_'.rand(100,999).'.'.$ext;move_uploaded_file($_FILES['image_file']['tmp_name'],$dir.'/'.$fn);$rel='uploads/'.$fn;$s=$conn->prepare("UPDATE products SET image=? WHERE id=?");$s->bind_param("si",$rel,$pid);$s->execute();}}
    flash('success','Product saved.');redirect('?page=admin_products');
}
if($action==='delete_product'){require_admin();$id=(int)$_GET['id'];$s=$conn->prepare("DELETE FROM products WHERE id=?");$s->bind_param("i",$id);$s->execute();flash('success','Product deleted.');redirect('?page=admin_products');}
if($action==='save_coupon'){require_admin();$id=(int)($_POST['id']??0);$code=strtoupper(trim($_POST['coupon_code']));$type=$_POST['discount_type']==='fixed'?'fixed':'percent';$val=(float)$_POST['discount_value'];$min=(float)$_POST['minimum_order'];$max=(float)$_POST['maximum_discount'];$start=$_POST['start_date'];$expiry=$_POST['expiry_date'];$status=$_POST['status']==='inactive'?'inactive':'active';
    if($id){$s=$conn->prepare("UPDATE coupons SET coupon_code=?,discount_type=?,discount_value=?,minimum_order=?,maximum_discount=?,start_date=?,expiry_date=?,status=? WHERE id=?");$s->bind_param("ssdddsssi",$code,$type,$val,$min,$max,$start,$expiry,$status,$id);}
    else{$s=$conn->prepare("INSERT INTO coupons(coupon_code,discount_type,discount_value,minimum_order,maximum_discount,start_date,expiry_date,status) VALUES(?,?,?,?,?,?,?,?)");$s->bind_param("ssdddsss",$code,$type,$val,$min,$max,$start,$expiry,$status);}
    $s->execute();flash('success','Coupon saved.');redirect('?page=admin_coupons');
}
if($action==='delete_coupon'){require_admin();$id=(int)$_GET['id'];$s=$conn->prepare("DELETE FROM coupons WHERE id=?");$s->bind_param("i",$id);$s->execute();redirect('?page=admin_coupons');}
if($action==='user_status'){require_admin();$id=(int)$_GET['id'];$status=$_GET['status']==='blocked'?'blocked':'active';$s=$conn->prepare("UPDATE users SET status=? WHERE id=? AND role='user'");$s->bind_param("si",$status,$id);$s->execute();redirect('?page=admin_users');}
if($action==='delete_user'){require_admin();$id=(int)$_GET['id'];$s=$conn->prepare("DELETE FROM users WHERE id=? AND role='user'");$s->bind_param("i",$id);$s->execute();redirect('?page=admin_users');}
if($action==='order_status'){require_admin();$id=(int)$_POST['id'];$os=$_POST['order_status'];$allowed=['Pending','Confirmed','Processing','Shipped','Out for Delivery','Delivered','Cancelled'];if(!in_array($os,$allowed))$os='Pending';$ds=$os==='Delivered'?'Delivered':'Not Delivered';$ps=$_POST['payment_status']==='paid'?'paid':'pending';$s=$conn->prepare("UPDATE orders SET order_status=?,delivered_status=?,payment_status=? WHERE id=?");$s->bind_param("sssi",$os,$ds,$ps,$id);$s->execute();redirect('?page=admin_orders');}

/* ===================== PAGE DATA ===================== */
$page=$_GET['page']??'home';
if($page==='home'){$featured=$conn->query("SELECT p.*,c.category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status='active' ORDER BY p.id DESC LIMIT 8");$cats=$conn->query("SELECT * FROM categories WHERE status='active' ORDER BY id DESC LIMIT 6");}
if($page==='products'){
    $where="WHERE p.status='active'";$params=[];$types='';
    if(!empty($_GET['q'])){$where.=" AND (p.product_name LIKE ? OR p.description LIKE ?)";$q='%'.$_GET['q'].'%';$params=[$q,$q];$types='ss';}
    if(!empty($_GET['cat'])){$where.=" AND p.category_id=?";$params[]=(int)$_GET['cat'];$types.='i';}
    if(isset($_GET['max'])&&$_GET['max']!==''){$where.=" AND (p.price-p.discount)<=?";$params[]=(float)$_GET['max'];$types.='d';}
    $sort=$_GET['sort']??'new';$order=$sort==='low'?'(p.price-p.discount) ASC':($sort==='high'?'(p.price-p.discount) DESC':'p.id DESC');
    $sql="SELECT p.*,c.category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id $where ORDER BY $order";
    $s=$conn->prepare($sql);if($types)$s->bind_param($types,...$params);$s->execute();$products=$s->get_result();$cats=$conn->query("SELECT * FROM categories WHERE status='active'");
}
if($page==='product'){$id=(int)$_GET['id'];$s=$conn->prepare("SELECT p.*,c.category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?");$s->bind_param("i",$id);$s->execute();$product=$s->get_result()->fetch_assoc();}
if($page==='cart' && logged()){$items=cart_items($conn,$_SESSION['user_id']);[$sub,$disc,$ship,$final]=cart_totals($conn,$_SESSION['user_id']);}
if($page==='checkout'){require_login();$u=get_user($conn,$_SESSION['user_id']);$items=cart_items($conn,$_SESSION['user_id']);[$sub,$disc,$ship,$final]=cart_totals($conn,$_SESSION['user_id']);}
if($page==='profile'||$page==='password'){$u=get_user($conn,$_SESSION['user_id']);}
if($page==='orders'||$page==='myorders'){require_login();$s=$conn->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY id DESC");$s->bind_param("i",$_SESSION['user_id']);$s->execute();$orders=$s->get_result();}
if($page==='success'){require_login();$oid=(int)$_GET['id'];$s=$conn->prepare("SELECT o.*,u.name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? AND o.user_id=?");$s->bind_param("ii",$oid,$_SESSION['user_id']);$s->execute();$order=$s->get_result()->fetch_assoc();}

/* admin */
if(str_starts_with($page,'admin')) require_admin();
if($page==='admin'){
 $stats=[];$stats['users']=$conn->query("SELECT COUNT(*) n FROM users WHERE role='user'")->fetch_assoc()['n'];$stats['categories']=$conn->query("SELECT COUNT(*) n FROM categories")->fetch_assoc()['n'];$stats['products']=$conn->query("SELECT COUNT(*) n FROM products")->fetch_assoc()['n'];$stats['orders']=$conn->query("SELECT COUNT(*) n FROM orders")->fetch_assoc()['n'];$stats['pending']=$conn->query("SELECT COUNT(*) n FROM orders WHERE order_status NOT IN ('Delivered','Cancelled')")->fetch_assoc()['n'];$stats['delivered']=$conn->query("SELECT COUNT(*) n FROM orders WHERE order_status='Delivered'")->fetch_assoc()['n'];$stats['sales']=$conn->query("SELECT COALESCE(SUM(final_amount),0) n FROM orders WHERE payment_status='paid' OR payment_method LIKE 'Online%'")->fetch_assoc()['n'];$stats['coupons']=$conn->query("SELECT COUNT(*) n FROM coupons WHERE status='active' AND expiry_date>=CURDATE()")->fetch_assoc()['n'];
}
if($page==='admin_users')$users=$conn->query("SELECT * FROM users ORDER BY id DESC");
if($page==='admin_categories')$categories=$conn->query("SELECT * FROM categories ORDER BY id DESC");
if($page==='admin_products')$admin_products=$conn->query("SELECT p.*,c.category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC");
if($page==='admin_orders')$admin_orders=$conn->query("SELECT o.*,u.name,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC");
if($page==='admin_coupons')$coupons=$conn->query("SELECT *, IF(expiry_date<CURDATE(),'Expired',status) AS live_status FROM coupons ORDER BY id DESC");

$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
$cart_count=0;if(logged()){ $r=$conn->query("SELECT COALESCE(SUM(quantity),0) n FROM cart WHERE user_id=".(int)$_SESSION['user_id']);$cart_count=$r->fetch_assoc()['n'];}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ShopKart - Modern E-Commerce</title><link rel="stylesheet" href="style.css"></head><body>
<header class="navbar"><div class="container navin"><a class="logo" href="?page=home">Shop<span>Kart</span></a><button class="menu" onclick="document.querySelector('.navlinks').classList.toggle('show')">☰</button><div class="navlinks"><a href="?page=home">Home</a><a href="?page=products">Products</a><a href="?page=home#categories">Categories</a><a href="?page=about">About</a><a href="?page=contact">Contact</a><?php if(logged()):?><a href="?page=profile">My Profile</a><?php endif;?><?php if(admin()):?><a href="?page=admin">Admin</a><?php endif;?></div><form class="search" method="get"><input type="hidden" name="page" value="products"><input name="q" placeholder="Search products..." value="<?=e($_GET['q']??'')?>"><button>⌕</button></form><a class="cart" href="?page=cart">🛒 <b><?=$cart_count?></b></a><?php if(logged()):?><a class="loginlink" href="?action=logout">Logout</a><?php else:?><a class="loginlink" href="?page=login">Login</a><?php endif;?></div></header>
<main class="container"><?php if($flash):?><div class="alert <?=$flash['type']?>"><?=e($flash['msg'])?></div><?php endif;?>

<?php if($page==='home'):?>
<section class="hero"><div><span class="pill">NEW SEASON • BIG SAVINGS</span><h1>Shop smarter.<br><span>Live better.</span></h1><p>Discover quality products, exciting offers and a smooth shopping experience with ShopKart.</p><a class="btn" href="?page=products">Shop Now →</a></div><div class="heroart">🛍️<small>UP TO 50% OFF</small></div></section>
<h2>Featured Products</h2><div class="grid"><?php while($p=$featured->fetch_assoc()):?><div class="card"><img src="<?=e($p['image'])?>" alt=""><div class="cardbody"><small><?=e($p['category_name'])?></small><h3><?=e($p['product_name'])?></h3><div class="rating">★★★★★</div><strong><?=money(product_price($p))?></strong><?php if($p['discount']>0):?><del><?=money($p['price'])?></del><?php endif;?><div class="actions"><a class="btn outline" href="?page=product&id=<?=$p['id']?>">Details</a><form method="post" action="?action=addcart"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="quantity" value="1"><button class="btn">Add Cart</button></form></div></div></div><?php endwhile;?></div>
<section class="offer"><div><span class="pill">LIMITED OFFER</span><h2>Get more. Pay less.</h2><p>Free shipping on orders above ₹1,000. Use coupons for extra savings.</p><a class="btn light" href="?page=products">Explore Deals</a></div><div class="offerbig">50%</div></section>
<h2 id="categories">Popular Categories</h2><div class="catgrid"><?php while($c=$cats->fetch_assoc()):?><a href="?page=products&cat=<?=$c['id']?>" class="cat"><div>▦</div><h3><?=e($c['category_name'])?></h3><p><?=e($c['description'])?></p></a><?php endwhile;?></div>
<?php elseif($page==='products'):?>
<div class="pagehead"><div><h1>All Products</h1><p>Find your favorites at the best prices.</p></div></div><div class="filters"><form><input type="hidden" name="page" value="products"><input name="q" placeholder="Search..." value="<?=e($_GET['q']??'')?>"><select name="cat"><option value="">All Categories</option><?php while($c=$cats->fetch_assoc()):?><option value="<?=$c['id']?>" <?=((int)($_GET['cat']??0)==$c['id'])?'selected':''?>><?=e($c['category_name'])?></option><?php endwhile;?></select><input type="number" name="max" placeholder="Max ₹" value="<?=e($_GET['max']??'')?>"><select name="sort"><option value="new">Newest</option><option value="low">Price Low</option><option value="high">Price High</option></select><button class="btn">Filter</button></form></div><div class="grid"><?php while($p=$products->fetch_assoc()):?><div class="card"><img src="<?=e($p['image'])?>" alt=""><div class="cardbody"><small><?=e($p['category_name'])?></small><h3><?=e($p['product_name'])?></h3><div class="rating">★★★★★</div><strong><?=money(product_price($p))?></strong> <?php if($p['discount']>0):?><del><?=money($p['price'])?></del><?php endif;?><p class="stock <?=$p['stock']?'':'bad'?>"><?=($p['stock']>0)?$p['stock'].' in stock':'Out of stock'?></p><div class="actions"><a class="btn outline" href="?page=product&id=<?=$p['id']?>">View Details</a><?php if($p['stock']>0):?><form method="post" action="?action=addcart"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button class="btn">Add Cart</button></form><?php endif;?></div></div></div><?php endwhile;?></div>
<?php elseif($page==='product'): if(!$product):?><div class="empty">Product not found.</div><?php else:?><div class="detail"><div><img class="detailimg" src="<?=e($product['image'])?>"></div><div><small><?=e($product['category_name'])?></small><h1><?=e($product['product_name'])?></h1><div class="rating">★★★★★ <span>4.8/5</span></div><div class="bigprice"><?=money(product_price($product))?> <?php if($product['discount']>0):?><del><?=money($product['price'])?></del><span class="save">SAVE <?=money($product['discount'])?></span><?php endif;?></div><p><?=nl2br(e($product['description']))?></p><p><b>SKU:</b> <?=e($product['sku'])?> &nbsp; <b>Stock:</b> <?=e($product['stock'])?></p><form class="buyform" method="post" action="?action=addcart"><input type="hidden" name="product_id" value="<?=$product['id']?>"><input type="number" name="quantity" value="1" min="1" max="<?=$product['stock']?>"><button class="btn" <?=$product['stock']<1?'disabled':''?>>Add to Cart</button><a class="btn outline" href="?page=checkout">Buy Now</a></form><div class="info"><b>✓ Quality products</b><b>✓ Secure checkout</b><b>✓ Fast delivery</b></div></div></div><?php endif;?>
<?php elseif($page==='cart'):require_login();?><h1>Your Shopping Cart</h1><?php if($items->num_rows===0):?><div class="empty"><div>🛒</div><h2>Your cart is empty</h2><a class="btn" href="?page=products">Start Shopping</a></div><?php else:?><div class="cartlayout"><div><?php while($it=$items->fetch_assoc()):?><div class="cartitem"><img src="<?=e($it['image'])?>"><div class="grow"><h3><?=e($it['product_name'])?></h3><p><?=money(product_price($it))?></p><form class="qty" method="post" action="?action=cart_update"><input type="hidden" name="cart_id" value="<?=$it['id']?>"><button name="quantity" value="<?=max(0,$it['quantity']-1)?>">−</button><span><?=$it['quantity']?></span><button name="quantity" value="<?=$it['quantity']+1?>">+</button></form></div><strong><?=money(product_price($it)*$it['quantity'])?></strong><a class="danger" href="?action=remove_cart&id=<?=$it['id']?>">Remove</a></div><?php endwhile;?><div class="coupon"><form method="post" action="?action=coupon"><input name="coupon_code" placeholder="Coupon code" value="<?=e($_SESSION['coupon']['coupon_code']??'')?>"><button class="btn">Apply</button></form><?php if(isset($_SESSION['coupon'])):?><a href="?action=remove_coupon">Remove coupon</a><?php endif;?></div></div><aside class="summary"><h2>Order Summary</h2><p>Subtotal <b><?=money($sub)?></b></p><p>Discount <b>-<?=money($disc)?></b></p><p>Shipping <b><?=money($ship)?></b></p><hr><h2>Total <b><?=money($final)?></b></h2><a class="btn full" href="?page=checkout">Proceed to Checkout</a></aside></div><?php endif;?>
<?php elseif($page==='checkout'):?><h1>Checkout</h1><?php if($items->num_rows===0):?><div class="empty">Your cart is empty.</div><?php else:?><div class="checkout"><form method="post" action="?action=checkout" class="formbox"><h2>Shipping Details</h2><div class="formgrid"><?php foreach(['name','email','mobile','address','city','state','pincode','country'] as $f):?><label><?=ucfirst($f)?><input name="<?=$f?>" value="<?=e($u[$f]??($f==='country'?'India':''))?>" required></label><?php endforeach;?></div><h2>Payment</h2><label class="radio"><input type="radio" name="payment_method" value="cod" checked> Cash on Delivery</label><label class="radio"><input type="radio" name="payment_method" value="online"> Online Payment / Demo Payment</label><button class="btn full">Place Order</button></form><aside class="summary"><h2>Order Summary</h2><?php while($it=$items->fetch_assoc()):?><p><?=e($it['product_name'])?> × <?=$it['quantity']?> <b><?=money(product_price($it)*$it['quantity'])?></b></p><?php endwhile;?><hr><p>Subtotal <b><?=money($sub)?></b></p><p>Coupon <b>-<?=money($disc)?></b></p><p>Shipping <b><?=money($ship)?></b></p><h2>Grand Total <b><?=money($final)?></b></h2></aside></div><?php endif;?>
<?php elseif($page==='success'):?><div class="successbox"><div class="successicon">✓</div><h1>Payment Successful</h1><p>Your order has been placed successfully.</p><div class="receipt"><p>Order ID <b>#<?=$order['id']?></b></p><p>Payment Status <b><?=e($order['payment_status'])?></b></p><p>Order Amount <b><?=money($order['final_amount'])?></b></p><p>Customer <b><?=e($order['shipping_name'])?></b></p><p>Date <b><?=e($order['created_at'])?></b></p></div><a class="btn" href="?page=products">Continue Shopping</a> <a class="btn outline" href="?page=orders">View Order</a></div>
<?php elseif($page==='about'):?><div class="pagehead"><h1>About ShopKart</h1><p>Simple shopping. Better value.</p></div><div class="about"><div><h2>Our Mission</h2><p>We make online shopping simple, affordable and reliable for everyone.</p><h2>Our Vision</h2><p>To become a trusted everyday shopping destination with quality products and excellent service.</p></div><div class="why"><div>⭐<b>Quality Products</b><p>Carefully selected products.</p></div><div>🚚<b>Fast Delivery</b><p>Reliable delivery experience.</p></div><div>🔒<b>Secure Payment</b><p>Protected accounts and safe demo checkout.</p></div><div>❤️<b>Customer First</b><p>Support that puts customers first.</p></div></div></div>
<?php elseif($page==='contact'):?><div class="pagehead"><h1>Contact Us</h1><p>We would love to hear from you.</p></div><div class="checkout"><form class="formbox" method="post" action="?action=contact"><h2>Send a Message</h2><div class="formgrid"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Mobile<input name="mobile"></label><label>Subject<input name="subject" required></label></div><label>Message<textarea name="message" required></textarea></label><button class="btn">Send Message</button></form><aside class="summary"><h2>ShopKart</h2><p>📧 support@shopkart.local</p><p>📞 +91 98765 43210</p><p>📍 Ahmedabad, Gujarat, India</p><p>🕘 Mon–Sat, 9:00 AM–7:00 PM</p></aside></div>
<?php elseif($page==='login'||$page==='register'):?><div class="auth"><div class="authbox"><div class="logo">Shop<span>Kart</span></div><h1><?=$page==='login'?'Welcome Back':'Create Account'?></h1><?php if($page==='login'):?><form method="post" action="?action=login"><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required></label><button class="btn full">Login</button></form><p>New customer? <a href="?page=register">Create account</a></p><div class="demo">Default admin: <b>admin@shopkart.local</b> / <b>admin123</b></div><?php else:?><form method="post" action="?action=register"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Mobile<input name="mobile"></label><label>Password<input type="password" name="password" minlength="6" required></label><button class="btn full">Register</button></form><p>Already have account? <a href="?page=login">Login</a></p><?php endif;?></div></div>
<?php elseif($page==='profile'):?><div class="pagehead"><h1>My Profile</h1><p>Manage your account.</p></div><form class="formbox" method="post" action="?action=profile"><div class="profiletop"><div class="avatar"><?=strtoupper(substr($u['name'],0,1))?></div><div><h2><?=e($u['name'])?></h2><p><?=e($u['email'])?></p></div></div><div class="formgrid"><?php foreach(['name','mobile','address','city','state','pincode'] as $f):?><label><?=ucfirst($f)?><input name="<?=$f?>" value="<?=e($u[$f])?>"></label><?php endforeach;?></div><button class="btn">Save Profile</button> <a class="btn outline" href="?page=password">Change Password</a> <a class="btn outline" href="?page=orders">My Orders</a></form>
<?php elseif($page==='password'):?><div class="auth"><div class="authbox"><h1>Change Password</h1><form method="post" action="?action=password"><label>Current Password<input type="password" name="current" required></label><label>New Password<input type="password" name="new" minlength="6" required></label><label>Confirm Password<input type="password" name="confirm" minlength="6" required></label><button class="btn full">Change Password</button></form></div></div>
<?php elseif($page==='orders'):?><div class="pagehead"><h1>My Orders</h1><p>Track previous purchases and reorder.</p></div><div class="tablewrap"><table><tr><th>Order</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Delivered</th><th>Actions</th></tr><?php while($o=$orders->fetch_assoc()):?><tr><td>#<?=$o['id']?></td><td><?=e($o['created_at'])?></td><td><?=money($o['final_amount'])?></td><td><?=e($o['payment_status'])?></td><td><span class="badge"><?=e($o['order_status'])?></span></td><td><?=e($o['delivered_status'])?></td><td><a class="btn small" href="?page=success&id=<?=$o['id']?>">View</a> <a class="btn small outline" href="?action=reorder&id=<?=$o['id']?>">Reorder</a></td></tr><?php endwhile;?></table></div>

<?php elseif($page==='admin'):?><div class="adminlayout"><aside class="sidebar"><h2>ShopKart Admin</h2><a href="?page=admin">Dashboard</a><a href="?page=admin_users">Users</a><a href="?page=admin_categories">Categories</a><a href="?page=admin_products">Products</a><a href="?page=admin_orders">Orders</a><a href="?page=admin_coupons">Coupons</a><a href="?action=logout">Logout</a></aside><section class="adminmain"><div class="adminhead"><h1>Dashboard</h1><a class="addbtn" href="?page=admin_products">＋ Add</a></div><div class="stats"><?php foreach(['users'=>'Total Users','categories'=>'Categories','products'=>'Products','orders'=>'Orders','pending'=>'Pending Orders','delivered'=>'Delivered','sales'=>'Total Sales','coupons'=>'Active Coupons'] as $k=>$label):?><div class="stat"><span><?=e($label)?></span><b><?=($k==='sales'?money($stats[$k]):$stats[$k])?></b></div><?php endforeach;?></div><div class="adminnote">Welcome, <?=e($_SESSION['name'])?>. Use the sidebar or top-right + Add button to manage the store.</div></section></div>
<?php elseif(in_array($page,['admin_users','admin_categories','admin_products','admin_orders','admin_coupons'])):?>
<div class="adminlayout"><aside class="sidebar"><h2>ShopKart Admin</h2><a href="?page=admin">Dashboard</a><a href="?page=admin_users">Users</a><a href="?page=admin_categories">Categories</a><a href="?page=admin_products">Products</a><a href="?page=admin_orders">Orders</a><a href="?page=admin_coupons">Coupons</a><a href="?action=logout">Logout</a></aside><section class="adminmain"><div class="adminhead"><h1><?=ucwords(str_replace('admin_','',str_replace('_',' ',$page)))?></h1><?php if($page==='admin_products'):?><a class="addbtn" href="?page=admin_product_form">＋ Add Product</a><?php elseif($page==='admin_categories'):?><a class="addbtn" href="?page=admin_category_form">＋ Add Category</a><?php elseif($page==='admin_coupons'):?><a class="addbtn" href="?page=admin_coupon_form">＋ Add Coupon</a><?php else:?><span class="addbtn">＋ Add</span><?php endif;?></div>
<div class="tablewrap">
<?php if($page==='admin_users'):?><table><tr><th>ID</th><th>Name</th><th>Email</th><th>Mobile</th><th>Registered</th><th>Status</th><th>Actions</th></tr><?php while($x=$users->fetch_assoc()):?><tr><td><?=$x['id']?></td><td><?=e($x['name'])?></td><td><?=e($x['email'])?></td><td><?=e($x['mobile'])?></td><td><?=e($x['created_at'])?></td><td><span class="badge"><?=e($x['status'])?></span></td><td><?php if($x['role']!=='admin'):?><a class="btn small" href="?action=user_status&id=<?=$x['id']?>&status=<?=$x['status']==='blocked'?'active':'blocked'?>"><?=$x['status']==='blocked'?'Unblock':'Block'?></a> <a class="danger" href="?action=delete_user&id=<?=$x['id']?>" onclick="return confirm('Delete user?')">Delete</a><?php endif;?></td></tr><?php endwhile;?></table>
<?php elseif($page==='admin_categories'):?><table><tr><th>ID</th><th>Category</th><th>Description</th><th>Status</th><th>Created</th><th>Actions</th></tr><?php while($x=$categories->fetch_assoc()):?><tr><td><?=$x['id']?></td><td><?=e($x['category_name'])?></td><td><?=e($x['description'])?></td><td><?=e($x['status'])?></td><td><?=e($x['created_at'])?></td><td><a class="btn small" href="?page=admin_category_form&id=<?=$x['id']?>">Edit</a> <a class="danger" href="?action=delete_category&id=<?=$x['id']?>" onclick="return confirm('Delete category?')">Delete</a></td></tr><?php endwhile;?></table>
<?php elseif($page==='admin_products'):?><table><tr><th>ID</th><th>Image</th><th>Product</th><th>Category</th><th>Price</th><th>Discount</th><th>Stock</th><th>Status</th><th>Actions</th></tr><?php while($x=$admin_products->fetch_assoc()):?><tr><td><?=$x['id']?></td><td><img class="thumb" src="<?=e($x['image'])?>"></td><td><?=e($x['product_name'])?></td><td><?=e($x['category_name'])?></td><td><?=money($x['price'])?></td><td><?=money($x['discount'])?></td><td><?=$x['stock']?></td><td><?=$x['status']?></td><td><a class="btn small" href="?page=admin_product_form&id=<?=$x['id']?>">Edit</a> <a class="danger" href="?action=delete_product&id=<?=$x['id']?>" onclick="return confirm('Delete product?')">Delete</a></td></tr><?php endwhile;?></table>
<?php elseif($page==='admin_orders'):?><table><tr><th>ID</th><th>Customer</th><th>Total</th><th>Payment</th><th>Payment Status</th><th>Order Status</th><th>Delivered</th><th>Update</th></tr><?php while($x=$admin_orders->fetch_assoc()):?><tr><td>#<?=$x['id']?></td><td><?=e($x['name'])?><br><small><?=e($x['email'])?></small></td><td><?=money($x['final_amount'])?></td><td><?=e($x['payment_method'])?></td><td><?=e($x['payment_status'])?></td><td><form method="post" action="?action=order_status"><input type="hidden" name="id" value="<?=$x['id']?>"><select name="order_status"><?php foreach(['Pending','Confirmed','Processing','Shipped','Out for Delivery','Delivered','Cancelled'] as $st):?><option <?=$x['order_status']===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select><select name="payment_status"><option value="pending" <?=$x['payment_status']==='pending'?'selected':''?>>Pending</option><option value="paid" <?=$x['payment_status']==='paid'?'selected':''?>>Paid</option></select><button class="btn small">Save</button></form></td><td><?=e($x['delivered_status'])?></td><td>#<?=$x['id']?></td></tr><?php endwhile;?></table>
<?php elseif($page==='admin_coupons'):?><table><tr><th>ID</th><th>Code</th><th>Type</th><th>Value</th><th>Min</th><th>Max</th><th>Dates</th><th>Status</th><th>Actions</th></tr><?php while($x=$coupons->fetch_assoc()):?><tr><td><?=$x['id']?></td><td><b><?=e($x['coupon_code'])?></b></td><td><?=e($x['discount_type'])?></td><td><?=$x['discount_value']?></td><td><?=money($x['minimum_order'])?></td><td><?=money($x['maximum_discount'])?></td><td><?=$x['start_date']?> → <?=$x['expiry_date']?></td><td><?=e($x['live_status'])?></td><td><a class="btn small" href="?page=admin_coupon_form&id=<?=$x['id']?>">Edit</a> <a class="danger" href="?action=delete_coupon&id=<?=$x['id']?>" onclick="return confirm('Delete coupon?')">Delete</a></td></tr><?php endwhile;?></table><?php endif;?>
</div></section></div>

<?php elseif($page==='admin_category_form'): $id=(int)($_GET['id']??0);$x=['category_name'=>'','description'=>'','status'=>'active'];if($id){$s=$conn->prepare("SELECT * FROM categories WHERE id=?");$s->bind_param("i",$id);$s->execute();$x=$s->get_result()->fetch_assoc();}?>
<div class="formbox"><h1><?=($id?'Edit':'Add')?> Category</h1><form method="post" action="?action=save_category"><input type="hidden" name="id" value="<?=$id?>"><label>Category Name<input name="category_name" value="<?=e($x['category_name'])?>" required></label><label>Description<textarea name="description"><?=e($x['description'])?></textarea></label><label>Status<select name="status"><option value="active" <?=$x['status']==='active'?'selected':''?>>Open / Active</option><option value="discontinued" <?=$x['status']==='discontinued'?'selected':''?>>Discontinued</option></select></label><button class="btn">Save Category</button></form></div>
<?php elseif($page==='admin_product_form'): $id=(int)($_GET['id']??0);$x=['category_id'=>'','product_name'=>'','description'=>'','price'=>'','discount'=>'0','stock'=>'10','sku'=>'','image'=>'','status'=>'active'];if($id){$s=$conn->prepare("SELECT * FROM products WHERE id=?");$s->bind_param("i",$id);$s->execute();$x=$s->get_result()->fetch_assoc();}$categories2=$conn->query("SELECT * FROM categories WHERE status='active' ORDER BY category_name");?>
<div class="formbox"><h1><?=($id?'Edit':'Add')?> Product</h1><form method="post" action="?action=save_product" enctype="multipart/form-data"><input type="hidden" name="id" value="<?=$id?>"><div class="formgrid"><label>Product Name<input name="product_name" value="<?=e($x['product_name'])?>" required></label><label>Category<select name="category_id" required><?php while($c=$categories2->fetch_assoc()):?><option value="<?=$c['id']?>" <?=$x['category_id']==$c['id']?'selected':''?>><?=e($c['category_name'])?></option><?php endwhile;?></select></label><label>Price<input type="number" step="0.01" name="price" value="<?=e($x['price'])?>" required></label><label>Discount Amount<input type="number" step="0.01" name="discount" value="<?=e($x['discount'])?>"></label><label>Stock<input type="number" name="stock" value="<?=e($x['stock'])?>" required></label><label>SKU<input name="sku" value="<?=e($x['sku'])?>" required></label></div><label>Description<textarea name="description" required><?=e($x['description'])?></textarea></label><label>Image URL<input name="image" value="<?=e($x['image'])?>" placeholder="https://..."></label><label>Or Upload Image<input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp"></label><label>Status<select name="status"><option value="active">Active</option><option value="inactive" <?=$x['status']==='inactive'?'selected':''?>>Inactive</option></select></label><button class="btn">Save Product</button></form></div>
<?php elseif($page==='admin_coupon_form'): $id=(int)($_GET['id']??0);$x=['coupon_code'=>'','discount_type'=>'percent','discount_value'=>'10','minimum_order'=>'500','maximum_discount'=>'500','start_date'=>date('Y-m-d'),'expiry_date'=>date('Y-m-d',strtotime('+30 days')),'status'=>'active'];if($id){$s=$conn->prepare("SELECT * FROM coupons WHERE id=?");$s->bind_param("i",$id);$s->execute();$x=$s->get_result()->fetch_assoc();}?>
<div class="formbox"><h1><?=($id?'Edit':'Add')?> Coupon</h1><form method="post" action="?action=save_coupon"><input type="hidden" name="id" value="<?=$id?>"><div class="formgrid"><label>Coupon Code<input name="coupon_code" value="<?=e($x['coupon_code'])?>" required></label><label>Discount Type<select name="discount_type"><option value="percent" <?=$x['discount_type']==='percent'?'selected':''?>>Percent</option><option value="fixed" <?=$x['discount_type']==='fixed'?'selected':''?>>Fixed</option></select></label><label>Discount Value<input type="number" step="0.01" name="discount_value" value="<?=e($x['discount_value'])?>" required></label><label>Minimum Order<input type="number" step="0.01" name="minimum_order" value="<?=e($x['minimum_order'])?>"></label><label>Maximum Discount<input type="number" step="0.01" name="maximum_discount" value="<?=e($x['maximum_discount'])?>"></label><label>Start Date<input type="date" name="start_date" value="<?=e($x['start_date'])?>"></label><label>Expiry Date<input type="date" name="expiry_date" value="<?=e($x['expiry_date'])?>"></label><label>Status<select name="status"><option value="active" <?=$x['status']==='active'?'selected':''?>>Active</option><option value="inactive" <?=$x['status']==='inactive'?'selected':''?>>Inactive</option></select></label></div><button class="btn">Save Coupon</button></form></div>
<?php else:?><div class="empty"><h2>Page not found</h2><a class="btn" href="?page=home">Home</a></div><?php endif;?></main>
<footer><div class="container foot"><div><div class="logo">Shop<span>Kart</span></div><p>Modern shopping made simple.</p></div><div><h3>Quick Links</h3><a href="?page=products">Products</a><a href="?page=about">About</a><a href="?page=contact">Contact</a></div><div><h3>Support</h3><p>support@shopkart.local</p><p>+91 98765 43210</p></div></div><div class="copy">© <?=date('Y')?> ShopKart. Built with PHP + MySQL for XAMPP.</div></footer>
</body></html>