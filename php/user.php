<?php
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$domain = $_SERVER['HTTP_HOST'] ?? '';
@session_name('RPSVSESSID');
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params([
    'lifetime' => 86400 * 7,
    'path' => '/',
    'domain' => $domain,
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);
} else {
  session_set_cookie_params(86400 * 7, '/; samesite=Lax', $domain, $secure, true);
}
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../html/Login.html'); exit; }
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$role = htmlspecialchars($_SESSION['role'] ?? 'user', ENT_QUOTES, 'UTF-8');
require __DIR__ . '/db.php';
// Count users for dashboard tile
$users_total = 0;
$rs_cnt = $conn->query("SELECT COUNT(*) AS c FROM users");
if ($rs_cnt && $row = $rs_cnt->fetch_assoc()) { $users_total = (int)$row['c']; }
if ($rs_cnt) { $rs_cnt->close(); }
$messages_total = 0;
$me = (int)($_SESSION['user_id'] ?? 0);
$rs_m = $conn->query("SHOW TABLES LIKE 'messages'");
if ($rs_m && $rs_m->num_rows > 0) {
  $rs_m->close();
  $stmtMsg = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE receiver_id = ?");
  if ($stmtMsg) {
    $stmtMsg->bind_param('i', $me);
    if ($stmtMsg->execute()) {
      $resMsg = $stmtMsg->get_result();
      if ($resMsg && $row2 = $resMsg->fetch_assoc()) { $messages_total = (int)$row2['c']; }
    }
    $stmtMsg->close();
  }
} else { if ($rs_m) { $rs_m->close(); } }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Dashboard</title>
  <link rel="icon" href="../img/icon.png">
  <link rel="stylesheet" href="../css/styles.css?v=<?php echo filemtime(__DIR__ . '/../css/styles.css'); ?>" />
  <link rel="stylesheet" href="../css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../css/dashboard.css'); ?>" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
  <div class="dashboard-layout">
    <aside class="sidebar">
      <div class="brand">
        <img src="../img/logo.png" alt="logo" class="brand-logo" />
      </div>
      <nav class="menu">
        <a class="item active" href="user.php"><i class='bx bxs-dashboard'></i> <span>Dashboard</span></a>
        <a class="item" href="messages.php"><i class='bx bxs-conversation'></i> <span>Messages</span></a>
        <a class="item" href="#settings"><i class='bx bxs-cog'></i> <span>Settings</span></a>
        <?php if ($role === 'admin'): ?>
          <a class="item" href="admin.php"><i class='bx bxs-shield'></i> <span>Admin</span></a>
        <?php endif; ?>
      </nav>
    </aside>

    <main class="content">
      <div class="topbar">
        <div class="content-header">
          <h3>Welcome back, <span class="accent"><?php echo $username; ?></span></h3>
        </div>
        <div class="topbar-user" id="userMenuBtn" tabindex="0" role="button" aria-haspopup="true" aria-expanded="false">
          <div class="topbar-name"><?php echo $username; ?></div>
          <div class="topbar-avatar"><?php echo strtoupper(substr($username,0,1)); ?></div>
          <div class="user-menu" id="userMenu" aria-label="User menu">
            <a href="#profile"><i class='bx bxs-user'></i> <span>Profile</span></a>
            <a href="logout.php"><i class='bx bxs-exit'></i> <span>Logout</span></a>
          </div>
        </div>
      </div>

      <section class="grid">
        <div class="card quick-links">
          <h2 class="card-title">Quick Actions</h2>
          <div class="tiles">
            <a class="tile" href="#"><i class='bx bxs-group'></i><span>Total Users: <?php echo $users_total; ?></span></a>
            <a class="tile" href="#"><i class='bx bxs-message-dots'></i><span>Total Received Messages: <?php echo $messages_total; ?></span></a>
            <a class="tile" href="#"><i class='bx bxs-file-doc'></i><span>Documents</span></a>
            <a class="tile" href="#"><i class='bx bxs-bar-chart-alt-2'></i><span>Reports</span></a>
          </div>
        </div>

        <div class="card calendar-card">
          <div class="calendar-header">
            <button id="calPrev" class="cal-btn" aria-label="Previous month"><i class='bx bx-chevron-left'></i></button>
            <div id="calTitle" class="cal-title"></div>
            <button id="calNext" class="cal-btn" aria-label="Next month"><i class='bx bx-chevron-right'></i></button>
          </div>
          <div class="calendar" id="calendar"></div>
          <div class="calendar-footer">
            <button id="calToday" class="db-btn db-btn-small">Today</button>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script>
    (function(){
      const el = {
        wrap: document.getElementById('calendar'),
        title: document.getElementById('calTitle'),
        prev: document.getElementById('calPrev'),
        next: document.getElementById('calNext'),
        today: document.getElementById('calToday')
      };
      const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
      let view = new Date();
      view.setDate(1);

      function daysInMonth(y,m){ return new Date(y, m+1, 0).getDate(); }
      function sameDate(a,b){ return a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate(); }

      function render(){
        const y = view.getFullYear();
        const m = view.getMonth();
        const today = new Date();
        el.title.textContent = view.toLocaleString(undefined, { month: 'long', year: 'numeric' });

        const firstDow = new Date(y, m, 1).getDay();
        const total = daysInMonth(y, m);
        let html = '<div class="cal-grid">';
        for (let i=0;i<7;i++){ html += `<div class=\"cal-dow\">${dayNames[i]}</div>`; }
        for (let i=0;i<firstDow;i++){ html += '<div class=\"cal-cell cal-pad\"></div>'; }
        for (let d=1; d<=total; d++){
          const cur = new Date(y, m, d);
          const cls = ['cal-cell'];
          if (sameDate(cur, today)) cls.push('today');
          html += `<div class=\"${cls.join(' ')}\">${d}</div>`;
        }
        html += '</div>';
        el.wrap.innerHTML = html;
      }

      el.prev.addEventListener('click', function(){ view.setMonth(view.getMonth()-1); render(); });
      el.next.addEventListener('click', function(){ view.setMonth(view.getMonth()+1); render(); });
      el.today.addEventListener('click', function(){ view = new Date(); view.setDate(1); render(); });
      render();
    })();

    // User menu toggle
    (function(){
      var btn = document.getElementById('userMenuBtn');
      var menu = document.getElementById('userMenu');
      if(!btn || !menu) return;
      function open(){ menu.classList.add('open'); btn.setAttribute('aria-expanded','true'); }
      function close(){ menu.classList.remove('open'); btn.setAttribute('aria-expanded','false'); }
      btn.addEventListener('click', function(e){ e.stopPropagation(); if(menu.classList.contains('open')){ close(); } else { open(); } });
      btn.addEventListener('keydown', function(e){ if(e.key==='Enter' || e.key===' '){ e.preventDefault(); btn.click(); } });
      document.addEventListener('click', function(e){ if(menu.classList.contains('open') && !menu.contains(e.target) && e.target!==btn && !btn.contains(e.target)) close(); });
      document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
    })();
  </script>
</body>
</html>
