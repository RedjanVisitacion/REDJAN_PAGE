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
if ($role !== 'admin') { header('Location: user.php'); exit; }
require __DIR__ . '/db.php';
// Count users for dashboard tile
$users_total = 0;
$rs_cnt = $conn->query("SELECT COUNT(*) AS c FROM users");
if ($rs_cnt && $row = $rs_cnt->fetch_assoc()) { $users_total = (int)$row['c']; }
if ($rs_cnt) { $rs_cnt->close(); }
$messages_total = 0;
$rs_m = $conn->query("SHOW TABLES LIKE 'messages'");
if ($rs_m && $rs_m->num_rows > 0) {
  $rs_m->close();
  $rs_cnt2 = $conn->query("SELECT COUNT(*) AS c FROM messages");
  if ($rs_cnt2 && $row2 = $rs_cnt2->fetch_assoc()) { $messages_total = (int)$row2['c']; }
  if ($rs_cnt2) { $rs_cnt2->close(); }
} else { if ($rs_m) { $rs_m->close(); } }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
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
        <a class="item" href="user.php"><i class='bx bxs-dashboard'></i> <span>Dashboard</span></a>
        <a class="item" href="messages.php"><i class='bx bxs-conversation'></i> <span>Messages</span></a>
        <a class="item" href="#settings"><i class='bx bxs-cog'></i> <span>Settings</span></a>
        <?php if ($role === 'admin'): ?>
          <a class="item active" href="admin.php"><i class='bx bxs-shield'></i> <span>Admin</span></a>
        <?php endif; ?>
      </nav>
    </aside>

    <main class="content" style="overflow-x: hidden;">
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

      <section class="grid" style="grid-template-columns: 1fr;">
        <div class="card quick-links" style="display:none;">
          <h2 class="card-title">Quick Actions</h2>
          <div class="tiles">
            <a class="tile" href="#"><i class='bx bxs-group'></i><span>Total Users: <?php echo $users_total; ?></span></a>
            <a class="tile" href="#"><i class='bx bxs-message-dots'></i><span>Total Messages: <?php echo $messages_total; ?></span></a>
            <a class="tile" href="#"><i class='bx bxs-file-doc'></i><span>Documents</span></a>
            <a class="tile" href="#"><i class='bx bxs-bar-chart-alt-2'></i><span>Reports</span></a>
          </div>
        </div>

        <div class="card calendar-card" style="display:none;">
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

        <div class="card" id="userMgmtCard" style="grid-column: 1 / -1;">
          <h2 class="card-title">Manage Users</h2>
          <div id="userMgmtStatus" style="margin-bottom:8px;color:var(--p-color);"></div>
          <div style="overflow-x:auto; overflow-y: visible;">
            <table style="width:max-content; min-width:100%; border-collapse:collapse;">
              <thead>
                <tr style="text-align:left;">
                  <th style="padding:8px;border-bottom:1px solid #eee;">ID</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Username</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Email</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Role</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Blocked</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Created</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Password</th>
                  <th style="padding:8px;border-bottom:1px solid #eee;">Actions</th>
                </tr>
              </thead>
              <tbody id="adminUsersTbody">
                <tr><td colspan="8" style="padding:12px;color:#666;">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" id="galleryCard" style="grid-column: 1 / -1;">
          <h2 class="card-title">Manage Gallery</h2>
          <div id="galleryStatus" style="margin-bottom:8px;color:var(--p-color);"></div>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <button class="db-btn db-btn-small db-btn-outline" id="galleryUploadBtn"><i class='bx bxs-cloud-upload'></i> <span>Upload</span></button>
            <input type="file" id="galleryFileInput" accept="image/*" multiple style="display:none;">
          </div>
          <div id="galleryGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;">
            <!-- images here -->
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

    // Admin: Manage Users
    (function(){
      var tbody = document.getElementById('adminUsersTbody');
      if (!tbody) return;
      var status = document.getElementById('userMgmtStatus');
      var API_BASE = <?php echo json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/php/admin.php'), '/\\').'/'); ?>;

      async function loadUsers(){
        if (status) { status.style.color = 'var(--p-color)'; status.textContent = 'Loading users...'; }
        try{
          const res = await fetch(API_BASE + 'admin_users.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
          const data = await res.json();
          if (!data.success) { tbody.innerHTML = '<tr><td colspan="8" style="padding:12px;color:#c00;">'+(data.message||'Failed to load')+'</td></tr>'; if(status){status.textContent='';} return; }
          render(data.users||[]);
          if (status) status.textContent = '';
        }catch(err){
          tbody.innerHTML = '<tr><td colspan="8" style="padding:12px;color:#c00;">Network error.</td></tr>';
          if (status) status.textContent = '';
        }
      }

      function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;'); }
      function render(users){
        if (!users.length){ tbody.innerHTML = '<tr><td colspan="8" style="padding:12px;color:#666;">No users found.</td></tr>'; return; }
        tbody.innerHTML = users.map(function(u){
          var blocked = parseInt(u.is_blocked||0,10) ? 'Yes' : 'No';
          var acts = [];
          if (parseInt(u.is_blocked||0,10)) acts.push('<button class="db-btn db-btn-small db-btn-success" data-act="unblock" data-id="'+u.id+'">Unblock</button>');
          else acts.push('<button class="db-btn db-btn-small db-btn-warning" data-act="block" data-id="'+u.id+'">Block</button>');
          acts.push('<button class="db-btn db-btn-small db-btn-outline" data-act="reset" data-id="'+u.id+'">Reset PW</button>');
          acts.push('<button class="db-btn db-btn-small db-btn-danger" data-act="delete" data-id="'+u.id+'">Delete</button>');
          return '<tr>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">'+u.id+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">'+esc(u.username)+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">'+esc(u.email)+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">'+esc(u.role)+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">'+blocked+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;">'+esc(u.created_at)+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;word-break:break-all;font-size:12px;">'+esc(u.password_plain||'')+'</td>'
              +  '<td style="padding:8px;border-bottom:1px solid #f0f0f0;display:flex;gap:6px;flex-wrap:wrap;">'+acts.join(' ')+'</td>'
              +'</tr>';
        }).join('');
      }

      async function postAction(action, body){
        if (status) { status.style.color = 'var(--p-color)'; status.textContent = 'Working...'; }
        try{
          const res = await fetch(API_BASE + 'admin_users.php?action='+action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
          });
          const data = await res.json();
          if (!data.success) { if(status){ status.style.color = '#c00'; status.textContent = data.message||'Action failed.'; } return null; }
          if (status) { status.style.color = 'green'; status.textContent = 'Done.'; }
          return data;
        }catch(err){ if(status){ status.style.color='#c00'; status.textContent='Network error.'; } return null; }
      }

      tbody.addEventListener('click', async function(e){
        var t = e.target;
        if (!t.matches('[data-act]')) return;
        var id = parseInt(t.getAttribute('data-id'),10);
        if (!isFinite(id) || id<=0) return;
        var act = t.getAttribute('data-act');
        if (act === 'block'){
          if (!confirm('Block this user? They will not be able to login.')) return;
          await postAction('block', 'user_id='+encodeURIComponent(id));
          loadUsers();
        } else if (act === 'unblock'){
          await postAction('unblock', 'user_id='+encodeURIComponent(id));
          loadUsers();
        } else if (act === 'delete'){
          if (!confirm('Delete this user? This cannot be undone.')) return;
          await postAction('delete', 'user_id='+encodeURIComponent(id));
          loadUsers();
        } else if (act === 'reset'){
          var newpw = prompt('Enter new password (leave empty to auto-generate):','');
          var body = 'user_id='+encodeURIComponent(id) + (newpw!==null && newpw!==''? ('&new_password='+encodeURIComponent(newpw)) : '');
          var data = await postAction('reset_password', body);
          if (data && data.new_password){
            alert('New password: '+data.new_password);
          }
          loadUsers();
        }
      });

      loadUsers();
    })();

    // Admin: Manage Gallery
    (function(){
      var grid = document.getElementById('galleryGrid');
      var status = document.getElementById('galleryStatus');
      var btn = document.getElementById('galleryUploadBtn');
      var fileInput = document.getElementById('galleryFileInput');
      if (!grid || !btn || !fileInput) return;
      var API_BASE = <?php echo json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/php/admin.php'), '/\\').'/'); ?>;

      function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;'); }
      function setStatus(msg, color){ if(status){ status.style.color = color||'var(--p-color)'; status.textContent = msg||''; } }

      function render(items){
        if (!items || !items.length){ grid.innerHTML = '<div style="grid-column:1/-1;color:#666;text-align:center;padding:16px;">No photos yet.</div>'; return; }
        grid.innerHTML = items.map(function(it){
          var url = esc(it.url||'');
          var id = it.id;
          var hidden = parseInt(it.is_hidden||0, 10) ? 1 : 0;
          var imgStyle = 'width:100%;height:140px;object-fit:cover;display:block;' + (hidden ? 'filter:grayscale(100%);opacity:.5;' : '');
          var badge = hidden ? '<div style="position:absolute;bottom:6px;left:6px;background:#333;color:#fff;font-size:11px;padding:3px 6px;border-radius:6px;opacity:.85;">Hidden</div>' : '';
          return '<div style="position:relative;border-radius:10px;overflow:hidden;border:1px solid #eee;">'
            + '<img src="'+url+'" alt="Photo" style="'+imgStyle+'">'
            + '<div style="position:absolute;top:6px;left:6px;display:flex;gap:6px;flex-wrap:wrap;">'
              + '<button class="db-btn db-btn-small db-btn-outline" data-home="'+id+'" data-url="'+url+'">Home BG</button>'
              + '<button class="db-btn db-btn-small db-btn-outline" data-login="'+id+'" data-url="'+url+'">Login BG</button>'
              + '<button class="db-btn db-btn-small '+(hidden?'db-btn-success':'db-btn-warning')+'" data-hide="'+id+'" data-hidden="'+hidden+'">'+(hidden?'Show':'Hide')+'</button>'
            + '</div>'
            + '<button class="db-btn db-btn-small db-btn-danger" data-del="'+id+'" style="position:absolute;top:6px;right:6px;">Delete</button>'
            + badge
          + '</div>';
        }).join('');
      }

      async function loadGallery(){
        setStatus('Loading gallery...');
        try{
          var res = await fetch(API_BASE + 'gallery_api.php?action=list&include_hidden=1&t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' });
          var data = await res.json();
          render((data && data.images) || []);
          setStatus('');
        }catch(e){ setStatus('Failed to load gallery','\#c00'); }
      }

      async function deleteImage(id){
        if (!confirm('Delete this image?')) return;
        setStatus('Deleting...');
        try{
          var res = await fetch(API_BASE + 'gallery_api.php?action=delete', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id) });
          var data = await res.json();
          if (!data.success){ setStatus(data.message||'Delete failed','\#c00'); return; }
          setStatus('Deleted.','green');
          loadGallery();
        }catch(e){ setStatus('Network error','\#c00'); }
      }

      async function uploadFiles(files){
        if (!files || !files.length) return;
        setStatus('Uploading...');
        for (var i=0;i<files.length;i++){
          var fd = new FormData();
          fd.append('file', files[i]);
          try{
            var res = await fetch(API_BASE + 'gallery_api.php?action=upload', { method:'POST', credentials:'same-origin', body: fd });
            var data = await res.json();
            if (!data.success){ setStatus(data.message||'Upload failed','\#c00'); return; }
          }catch(e){ setStatus('Network error','\#c00'); return; }
        }
        setStatus('Uploaded.','green');
        fileInput.value = '';
        loadGallery();
      }

      async function setSiteBg(key, url){
        setStatus('Saving background...');
        try{
          var res = await fetch(API_BASE + 'site_config.php?action=set', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'key='+encodeURIComponent(key)+'&value='+encodeURIComponent(url) });
          var data = await res.json();
          if (!data.success){ setStatus(data.message||'Save failed','\#c00'); return; }
          setStatus('Saved.','green');
        }catch(err){ setStatus('Network error','\#c00'); }
      }

      async function setHidden(id, hidden){
        setStatus('Updating...');
        try{
          var res = await fetch(API_BASE + 'gallery_api.php?action=set_hidden', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+encodeURIComponent(id)+'&hidden='+encodeURIComponent(hidden) });
          var data = await res.json();
          if (!data.success){ setStatus(data.message||'Update failed','\#c00'); return; }
          setStatus('Updated.','green');
          loadGallery();
        }catch(err){ setStatus('Network error','\#c00'); }
      }

      grid.addEventListener('click', function(e){
        var t = e.target;
        if (t && t.matches('[data-del]')){
          var id = parseInt(t.getAttribute('data-del'),10);
          if (isFinite(id) && id>0) deleteImage(id);
          return;
        }
        if (t && t.matches('[data-home]')){
          var url = t.getAttribute('data-url')||'';
          if (url) setSiteBg('home_bg', url);
          return;
        }
        if (t && t.matches('[data-login]')){
          var url2 = t.getAttribute('data-url')||'';
          if (url2) setSiteBg('login_bg', url2);
          return;
        }
        if (t && t.matches('[data-hide]')){
          var id2 = parseInt(t.getAttribute('data-hide'),10);
          var cur = parseInt(t.getAttribute('data-hidden')||'0',10) ? 1 : 0;
          var next = cur ? 0 : 1;
          var ok = confirm(next ? 'Hide this image from the homepage gallery?' : 'Show this image in the homepage gallery?');
          if (!ok) return;
          setHidden(id2, next);
          return;
        }
      });
      btn.addEventListener('click', function(){ fileInput.click(); });
      fileInput.addEventListener('change', function(){ uploadFiles(this.files); });

      loadGallery();
    })();
  </script>
</body>
</html>
