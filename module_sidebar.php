  <aside class="side-area" id="sideArea">
    <div class="side-top">
      <div class="side-brand">
        <img src="img/pesologo.png" alt="PESO Logo" class="side-logo">
        <div>
          <div class="side-title">BENEPESO</div>
          <div class="side-sub"><?=module_escape($label)?></div>
        </div>
      </div>
      <button class="side-close" id="sideClose" type="button" aria-label="Close menu">
        <span class="side-close-glyph" aria-hidden="true">&#215;</span>
      </button>
    </div>

    <div class="side-user">
      <div class="user-pic-wrap">
        <img src="<?php echo module_escape($pic_path); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($sidebarName); ?>&background=1f7a54&color=fff';">
      </div>
      <div>
        <div class="user-name"><?php echo module_escape($sidebarName); ?></div>
        <div class="user-role"><?=$role==='admin'?'Administrator':'PESO Staff'?></div>
      </div>
    </div>

    <nav class="nav-area">
      <a href="<?=module_escape($prefix)?>_dashboard.php" class="nav-item <?=$active==='dashboard'?'active':''?>"><i class="ph-fill ph-squares-four"></i> Dashboard</a>
      <a href="<?=module_escape($prefix)?>_program.php" class="nav-item <?=$active==='program'?'active':''?>"><i class="ph-fill ph-briefcase"></i> Programs</a>
      <a href="<?=module_escape($prefix)?>_application.php" class="nav-item <?=$active==='application'?'active':''?>"><i class="ph ph-file-text"></i> Application</a>
      <a href="<?=module_escape($prefix)?>_beneficiaries.php" class="nav-item <?=$active==='report'?'active':''?>"><i class="ph ph-chart-bar"></i> Report</a>
      <a href="forms_downloads.php?role=<?=module_escape($role)?>" class="nav-item <?=$active==='forms'?'active':''?>"><i class="ph ph-download-simple"></i> Forms &amp; Templates</a>
<?php if($role==='admin'): ?>      <a href="<?=module_escape($prefix)?>_accounts.php" class="nav-item <?=$active==='accounts'?'active':''?>"><i class="ph-fill ph-user-circle-gear"></i> Manage Accounts</a><?php endif; ?>
      <a href="<?=module_escape($prefix)?>_activity_log.php" class="nav-item <?=$active==='logs'?'active':''?>"><i class="ph-fill ph-clock-counter-clockwise"></i> System Logs</a>
      <form method="POST" action="logout.php" class="sidebar-logout-form"><?php echo auth_csrf_input(); ?><input type="hidden" name="role" value="<?=module_escape($role)?>"><button type="submit" class="nav-item logout-item"><i class="ph-bold ph-sign-out"></i> Logout</button></form>
    </nav>
  </aside>