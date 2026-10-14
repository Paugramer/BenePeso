<?php
function module_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function module_shell_start(string $role, string $active, string $title, string $description, string $pageClass = ''): void {
    $prefix = $role === 'admin' ? 'admin' : 'peso_staff';
    $label = $role === 'admin' ? 'Admin Panel' : 'PESO Staff Panel';
    $links = [['dashboard','Dashboard','squares-four',$prefix.'_dashboard.php'],['program','Programs','briefcase',$prefix.'_program.php'],
        ['application','Application','file-text',$prefix.'_application.php'],['report','Report','chart-bar',$prefix.'_beneficiaries.php'],
        ['forms','Forms & Downloads','download-simple','forms_downloads.php?role='.$role]];
    if ($role === 'admin') $links[] = ['accounts','Manage Accounts','user-circle-gear','admin_accounts.php'];
    $links[] = ['logs','System Logs','clock-counter-clockwise',$prefix.'_activity_log.php'];
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BENEPESO | <?=module_escape($title)?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css"><link rel="stylesheet" href="module_center.css"><link rel="stylesheet" href="shared_sidebar.css">
<?php if ($pageClass !== ''): ?><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="application_refinements.css?v=9"><?php endif ?></head>
<body class="<?=module_escape($pageClass)?>"><div class="module-layout"><div class="sidebar-overlay" id="sidebarOverlay"></div><aside class="side-area" id="sideArea"><div class="side-top"><div class="side-brand"><img class="side-logo" src="img/pesologo.png" alt="PESO logo"><div><div class="side-title">BENEPESO</div><div class="side-sub"><?=module_escape($label)?></div></div></div><button type="button" class="side-close" id="sideClose" aria-label="Close menu">&#215;</button></div>
<div class="side-user"><div class="user-pic-wrap"><img src="img/default_user.svg" alt="" class="user-img-side"></div><div><div class="user-name"><?=module_escape($_SESSION[$role==='admin'?'admin_name':'staff_name'] ?? ($role==='admin'?'PESO Vinzons':'PESO Staff'))?></div><div class="user-role"><?=$role==='admin'?'Administrator':'PESO Staff'?></div></div></div>
<nav class="nav-area" aria-label="Main navigation"><?php foreach($links as [$key,$name,$icon,$url]):?><a class="nav-item <?=$key===$active?'active':''?>" href="<?=module_escape($url)?>" <?=$key===$active?'aria-current="page"':''?>><i class="ph ph-<?=module_escape($icon)?>" aria-hidden="true"></i><?=module_escape($name)?></a><?php endforeach?>
<form method="post" action="logout.php" class="sidebar-logout-form"><?=auth_csrf_input()?><input type="hidden" name="role" value="<?=module_escape($role)?>"><button class="nav-item logout-item" type="submit"><i class="ph ph-sign-out"></i>Logout</button></form></nav></aside>
<main class="module-main"><header class="module-heading"><div><p class="eyebrow"><?=module_escape($label)?></p><h1><?=module_escape($title)?></h1><p><?=module_escape($description)?></p></div><button class="menu-toggle" id="menuToggle" type="button" aria-controls="sideArea" aria-expanded="false" aria-label="Open menu"><i class="ph ph-list"></i> Menu</button></header>
<?php }
function module_shell_end(): void { ?></main></div><script src="module_center.js"></script></body></html><?php }
