<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require __DIR__.'/../auth.php';require __DIR__.'/../db.php';require __DIR__.'/../remember_auth.php';
function ban_check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$conn->query('CREATE TEMPORARY TABLE users (user_id INT PRIMARY KEY,first_name VARCHAR(50),last_name VARCHAR(50),profile_pic VARCHAR(100),status VARCHAR(20)) ENGINE=InnoDB');
$conn->query('CREATE TEMPORARY TABLE peso_staff (staff_id INT PRIMARY KEY,first_name VARCHAR(50),last_name VARCHAR(50),profile_picture VARCHAR(100),status VARCHAR(20)) ENGINE=InnoDB');
$conn->query("INSERT INTO users VALUES(1,'Synthetic','User','','Active')");
$conn->query("INSERT INTO peso_staff VALUES(1,'Synthetic','Staff','','Active')");
$conn->query('CREATE TEMPORARY TABLE auth_remember_tokens (token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,selector CHAR(24) UNIQUE,validator_hash CHAR(64),account_role VARCHAR(20),account_id INT,expires_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_used_at DATETIME NULL) ENGINE=InnoDB');
foreach(['user'=>['users','user_id'],'peso_staff'=>['peso_staff','staff_id']] as $role=>[$table,$key]){
 $_SESSION=[];$_SESSION[$key]=1;auth_activate_role($role);
 ban_check(auth_role_account_is_active($role),'Active account rejected.');
 $conn->query("UPDATE $table SET status='Banned' WHERE $key=1");
 ban_check(!auth_role_account_is_active($role),'Existing banned session still active.');
 ban_check(auth_first_active_role([$role])===null&&!auth_has_role($role),'API guard retained a banned role.');
 ban_check(!remember_auth_load_account($conn,$role,1)&&!auth_has_role($role),'Remember-me loader restored a banned account.');
 $selector=bin2hex(random_bytes(12));$validator=bin2hex(random_bytes(32));$hash=hash('sha256',$validator);
 $s=$conn->prepare('INSERT INTO auth_remember_tokens(selector,validator_hash,account_role,account_id,expires_at) VALUES(?,?,?,1,DATE_ADD(NOW(),INTERVAL 1 DAY))');$s->bind_param('sss',$selector,$hash,$role);$s->execute();$s->close();
 $_COOKIE[REMEMBER_AUTH_COOKIE]=$selector.':'.$validator;
 ban_check(remember_auth_attempt($conn)===null&&!auth_has_role($role),'A valid remember-me token bypassed the ban.');
 $s=$conn->prepare('SELECT COUNT(*) n FROM auth_remember_tokens WHERE selector=?');$s->bind_param('s',$selector);$s->execute();ban_check((int)$s->get_result()->fetch_assoc()['n']===0,'Rejected token was not revoked.');$s->close();
 $conn->query("UPDATE $table SET status='Active' WHERE $key=1");
 ban_check(remember_auth_load_account($conn,$role,1)&&auth_role_account_is_active($role),'Restored account could not authenticate.');
}
$_SESSION=[];$_SESSION['user_id']=1;auth_activate_role('user');$conn->query("UPDATE users SET status='Banned' WHERE user_id=1");
register_shutdown_function(static function():void{
 if(auth_has_role('user')){fwrite(STDERR,"Banned page session was not cleared.\n");exit(1);}
 if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
 echo "Ban checks passed for users/staff: existing sessions, API/page guards, valid remember-me rejection/revocation and account restoration. Temporary tables only.\n";
});
check_user_role('user');
throw new RuntimeException('Protected content executed for a banned account.');
