<?php
require_once __DIR__ . '/route_helpers.php';
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
if (!isset($_SESSION['user_id'])) { header('Location: ' . rpsv_route_path('login')); exit; }
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$role = htmlspecialchars($_SESSION['role'] ?? 'user', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Messages</title>
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
        <a class="item active" href="messages.php"><i class='bx bxs-conversation'></i> <span>Messages</span></a>
        <a class="item" href="#settings"><i class='bx bxs-cog'></i> <span>Settings</span></a>
        <?php if ($role === 'admin'): ?>
          <a class="item" href="admin.php"><i class='bx bxs-shield'></i> <span>Admin</span></a>
        <?php endif; ?>
      </nav>
    </aside>

    <main class="content">
      <div class="topbar">
        <div class="topbar-left">
          <span class="topbar-title">Messages</span>
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

      <section class="grid messages-grid">
        

        <div class="card" id="adminChats">
          <h2 class="card-title" id="chatPanelTitle">Chats</h2>
          <div id="chatTabs" style="display:flex;gap:8px;margin:6px 0 8px 0;">
            <button id="tabChats" type="button" class="db-btn db-btn-small">Chats</button>
            <button id="tabFriends" type="button" class="db-btn db-btn-small" style="display: <?php echo ($role==='admin'?'none':'inline-flex'); ?>;">Friends</button>
          </div>
          <div id="userTools" style="display:none;padding-bottom:8px;border-bottom:1px solid #eee;margin-bottom:8px;">
            <div style="display:flex;gap:8px;align-items:center;">
              <input id="addContactName" type="text" placeholder="Add by username" style="flex:1;padding:8px;border:1px solid #eee;border-radius:8px;" />
              <button id="addContactBtn" type="button" class="db-btn db-btn-small">Add</button>
            </div>
            <div id="addStatus" style="margin-top:6px;color:var(--p-color);"></div>
            <div id="reqSection" style="margin-top:10px;display:none;">
              <div style="font-weight:700;color:#333;margin-bottom:6px;">Requests</div>
              <div id="reqList" style="display:flex;flex-direction:column;gap:6px;"></div>
            </div>
            <div id="sugSection" style="margin-top:12px;display:none;">
              <div style="font-weight:700;color:#333;margin-bottom:6px;">Suggestions</div>
              <div id="sugList" style="display:flex;flex-direction:column;gap:6px;"></div>
            </div>
          </div>
          <div id="chatList" style="padding:8px 0; max-height:50vh; overflow-y:auto;">
            <div style="padding:12px;color:#666;">Loading...</div>
          </div>
        </div>

        <div class="card calendar-card" style="display:flex;flex-direction:column;height:calc(100vh - 220px);min-height:420px;">
          <div class="calendar-header">
            <div class="cal-title" id="convTitle">Conversation</div>
          </div>
          <div class="calendar" id="msgList" style="padding:12px; flex:1 1 auto; overflow-y:auto;">
            <div style="padding:12px;color:#666;">Loading...</div>
          </div>
          <form id="msgForm" style="border-top:1px solid #eee;padding:10px 12px;display:flex;gap:10px;align-items:flex-end;">
            <textarea id="msgText" name="content" rows="2" maxlength="2000" placeholder="Type your message..." style="flex:1;padding:12px;border:1px solid #eee;border-radius:8px;resize:none" required></textarea>
            <button type="submit" class="db-btn db-btn-small">Send</button>
          </form>
          <div id="msgStatus" style="padding:0 12px 12px;color:var(--p-color);"></div>
        </div>
      </section>
    </main>
  </div>

  <script>
    var MY_ROLE = <?php echo json_encode($role); ?>;
    var API_BASE = <?php echo json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/php/messages.php'), '/\\').'/'); ?>;
    function getWithFromQuery(){
      var s = new URLSearchParams(window.location.search);
      var w = parseInt(s.get('with')||'0',10); return isNaN(w)?0:w;
    }
    var CURRENT_WITH = getWithFromQuery();
    var CURRENT_ME = 0;
    var POLL_MS = 2000;
    var pollTimer = null;
    var lastRenderedId = 0;
    var isLoadingMessages = false;
    var CURRENT_LEFT_TAB = 'chats';
    (function(){
      var btn = document.getElementById('userMenuBtn');
      var menu = document.getElementById('userMenu');
      if(btn && menu){
        function open(){ menu.classList.add('open'); btn.setAttribute('aria-expanded','true'); }
        function close(){ menu.classList.remove('open'); btn.setAttribute('aria-expanded','false'); }
        btn.addEventListener('click', function(e){ e.stopPropagation(); if(menu.classList.contains('open')){ close(); } else { open(); } });
        btn.addEventListener('keydown', function(e){ if(e.key==='Enter' || e.key===' '){ e.preventDefault(); btn.click(); } });
        document.addEventListener('click', function(e){ if(menu.classList.contains('open') && !menu.contains(e.target) && e.target!==btn && !btn.contains(e.target)) close(); });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
      }
    })();

    // Tabs: Chats / Friends (users only)
    var tabChats = document.getElementById('tabChats');
    var tabFriends = document.getElementById('tabFriends');
    function updateLeftTabButtons(){
      if (tabChats){ tabChats.style.background = (CURRENT_LEFT_TAB==='chats') ? 'var(--blue)' : '#f2f2f2'; tabChats.style.color = (CURRENT_LEFT_TAB==='chats') ? '#fff' : '#333'; }
      if (tabFriends){ tabFriends.style.background = (CURRENT_LEFT_TAB==='friends') ? 'var(--blue)' : '#f2f2f2'; tabFriends.style.color = (CURRENT_LEFT_TAB==='friends') ? '#fff' : '#333'; }
    }
    function setLeftTab(tab){
      if (tab !== 'chats' && tab !== 'friends') return;
      CURRENT_LEFT_TAB = tab;
      updateLeftTabButtons();
      try { loadChatUsers(); } catch(e){}
    }
    if (tabChats){ tabChats.addEventListener('click', function(){ setLeftTab('chats'); }); }
    if (tabFriends){ tabFriends.addEventListener('click', function(){ setLeftTab('friends'); }); }
    updateLeftTabButtons();

    async function loadMessages(force){
      if (isLoadingMessages) { return; }
      isLoadingMessages = true;
      try {
        const qs = CURRENT_WITH? ('?with='+CURRENT_WITH) : '';
        const res = await fetch(API_BASE + 'message_list.php'+qs, { credentials: 'same-origin' });
        const data = await res.json();
        const list = document.getElementById('msgList');
        if(!data.success){ list.innerHTML = '<div style="padding:12px;color:#c00;">'+(data.message||'Failed to load')+'</div>'; return; }
        CURRENT_ME = data.me_id || 0;
        CURRENT_WITH = data.with_id || CURRENT_WITH || 0;
        var title = document.getElementById('convTitle');
        if (data.with_username) { title.textContent = 'Conversation with '+data.with_username; }
        var items = (data.items || []);
        var newLastId = items.length ? items[items.length - 1].id : 0;
        if (!force && lastRenderedId === newLastId) { return; }
        if(!items.length){ list.innerHTML = '<div style="padding:12px;color:#666;">No messages yet.</div>'; lastRenderedId = 0; return; }
        var nearBottom = (list.scrollHeight - list.scrollTop - list.clientHeight) < 100;
        list.innerHTML = items.map(function(m){
          var mine = (m.sender_id === CURRENT_ME);
          var src = m.created_at_iso || (m.created_at ? (m.created_at.replace(' ','T')+"+08:00") : null);
          var d = src ? new Date(src) : new Date();
          var when = d.toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
          var txt = (m.content||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');
          var align = mine ? 'flex-end' : 'flex-start';
          var bg = mine ? 'var(--blue)' : '#f3f3f3';
          var color = mine ? '#fff' : '#222';
          return '<div style="display:flex;justify-content:'+align+';padding:8px 12px">'
              + '<div style="max-width:70%;background:'+bg+';color:'+color+';padding:10px 12px;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.06);white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">'
              + '<div style="font-size:12px;opacity:.8;margin-bottom:4px;color:'+ (mine?'#eef':'#666') +'">'+when+'</div>'
              + '<div>'+txt+'</div>'
              + '</div>'
              + '</div>';
        }).join('');
        if (force || nearBottom) { list.scrollTop = list.scrollHeight; }
        lastRenderedId = newLastId;
        try { loadChatUsers(); } catch(e){}
      } catch(err){
        document.getElementById('msgList').innerHTML = '<div style="padding:12px;color:#c00;">Network error.</div>';
      } finally {
        isLoadingMessages = false;
      }
    }

    function startPolling(){
      if (pollTimer) return;
      pollTimer = setInterval(function(){ loadMessages(false); }, POLL_MS);
    }
    function stopPolling(){
      if (!pollTimer) return;
      clearInterval(pollTimer);
      pollTimer = null;
    }

    document.getElementById('msgForm').addEventListener('submit', async function(e){
      e.preventDefault();
      var txt = document.getElementById('msgText');
      var status = document.getElementById('msgStatus');
      var val = txt.value.trim();
      if(val===''){ status.textContent = 'Please enter a message.'; return; }
      status.textContent = 'Sending...';
      const body = new URLSearchParams();
      body.set('content', val);
      if (CURRENT_WITH) { body.set('to', String(CURRENT_WITH)); }
      try{
        const res = await fetch(API_BASE + 'message_send.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        const data = await res.json();
        if(data.success){
          status.style.color = 'green';
          status.textContent = 'Message sent.';
          if (data.ai && data.ai.attempted && !data.ai.inserted) {
            status.style.color = '#c00';
            status.textContent = 'AI error: ' + (data.ai.error || 'unknown');
          }
          txt.value='';
          loadMessages(true);
          setTimeout(function(){ loadMessages(true); }, 2000);
        } else {
          status.style.color = '#c00';
          status.textContent = data.message || 'Failed to send.';
        }
      }catch(err){
        status.style.color = '#c00';
        status.textContent = 'Network error.';
      }
    });

    async function fetchFirstOk(urls, opts){
      for (let i=0;i<urls.length;i++){
        try{
          const r = await fetch(urls[i], opts);
          if (r && r.ok) return r;
        }catch(_){ }
      }
      return null;
    }

    async function loadChatUsers(){
      var wrap = document.getElementById('chatList');
      if (!wrap) return;
      try{
        var isAdmin = (MY_ROLE === 'admin');
        var tools = document.getElementById('userTools');
        var title = document.getElementById('chatPanelTitle');
        var tabFriendsBtn = document.getElementById('tabFriends');
        var tabChatsBtn = document.getElementById('tabChats');
        if (tabFriendsBtn) { tabFriendsBtn.style.display = isAdmin ? 'none' : 'inline-flex'; }
        var showFriends = (!isAdmin && CURRENT_LEFT_TAB === 'friends');
        if (tools) tools.style.display = showFriends ? 'block' : 'none';
        if (wrap) wrap.style.display = showFriends ? 'none' : 'block';
        if (title) title.textContent = showFriends ? 'Friends' : 'Chats';
        var ts = Date.now();
        var opts = { credentials: 'same-origin', cache: 'no-store' };
        let res = null;
        if (isAdmin){
          var listCandidates = [
            API_BASE + 'message_list.php?list_users=1&ts='+ts,
            '/php/message_list.php?list_users=1&ts='+ts,
            '/message_list.php?list_users=1&ts='+ts,
            'message_list.php?list_users=1&ts='+ts,
            'php/message_list.php?list_users=1&ts='+ts
          ];
          res = await fetchFirstOk(listCandidates, opts);
          if (!res){
            var userCandidates = [
              API_BASE + 'chat_users.php?ts='+ts,
              '/php/chat_users.php?ts='+ts,
              '/chat_users.php?ts='+ts,
              'chat_users.php?ts='+ts,
              'php/chat_users.php?ts='+ts
            ];
            res = await fetchFirstOk(userCandidates, opts);
          }
        } else {
          var contactCandidates = [
            API_BASE + 'contacts_api.php?action=list&ts='+ts,
            '/php/contacts_api.php?action=list&ts='+ts,
            '/contacts_api.php?action=list&ts='+ts,
            'contacts_api.php?action=list&ts='+ts,
            'php/contacts_api.php?action=list&ts='+ts
          ];
          res = await fetchFirstOk(contactCandidates, opts);
        }
        if (!res) { wrap.innerHTML = '<div style="padding:12px;color:#c00;">HTTP 404 Error</div>'; return; }
        let raw = await res.text();
        let data = null;
        try { data = JSON.parse(raw); } catch(parseErr) {
          wrap.innerHTML = '<div style="padding:12px;color:#c00;">Invalid server response.</div>';
          return;
        }
        if(!data.success){ wrap.innerHTML = '<div style="padding:12px;color:#c00;">'+(data.message||'Failed to load users')+'</div>'; return; }
        var users = data.users||data.contacts||[];
        // Render incoming requests only in Friends tab for regular users
        if (!isAdmin && tools && CURRENT_LEFT_TAB === 'friends'){
          var reqSec = document.getElementById('reqSection');
          var reqList = document.getElementById('reqList');
          var incoming = data.incoming||[];
          if (incoming.length){
            reqSec.style.display = 'block';
            reqList.innerHTML = incoming.map(function(r){
              var uname = (r.username||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');
              return '<div style="display:flex;align-items:center;gap:8px;">'
                   +   '<div style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+uname+'</div>'
                   +   '<button type="button" class="db-btn db-btn-small" data-accept-id="'+r.id+'">Confirm</button>'
                   + '</div>';
            }).join('');
            Array.prototype.forEach.call(reqList.querySelectorAll('[data-accept-id]'), function(btn){
              btn.addEventListener('click', async function(){
                var uid = parseInt(btn.getAttribute('data-accept-id'),10);
                var paths = [API_BASE+'contacts_api.php?action=accept', '/php/contacts_api.php?action=accept', 'contacts_api.php?action=accept'];
                var ok = false; var resp = null;
                for (var i=0;i<paths.length;i++){
                  try{ resp = await fetch(paths[i], {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'from_id='+encodeURIComponent(uid)}); if(resp.ok){ ok=true; break;} }catch(_){}
                }
                if(!ok){ alert('Failed to accept.'); return; }
                await resp.text();
                loadChatUsers();
              });
            });
          } else { reqSec.style.display = 'none'; reqList.innerHTML = ''; }
          var addBtn = document.getElementById('addContactBtn');
          var addInput = document.getElementById('addContactName');
          var addStatus = document.getElementById('addStatus');
          if (addBtn && addInput){
            addBtn.onclick = async function(){
              var name = (addInput.value||'').trim(); if(!name){ addStatus.textContent = 'Enter a username.'; return; }
              addStatus.textContent = 'Adding...';
              var paths = [API_BASE+'contacts_api.php?action=add', '/php/contacts_api.php?action=add', 'contacts_api.php?action=add'];
              var resp = null; var ok = false;
              for (var i=0;i<paths.length;i++){
                try{ resp = await fetch(paths[i], {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'username='+encodeURIComponent(name)}); if(resp.ok){ ok=true; break;} }catch(_){}
              }
              if(!ok){ addStatus.style.color='#c00'; addStatus.textContent = 'Failed to add contact.'; return; }
              var data = {};
              try{ data = JSON.parse(await resp.text()); }catch(_){ data={success:false,message:'Invalid server response'}; }
              if(data.success){ addStatus.style.color='green'; addStatus.textContent = data.message || 'Request sent.'; addInput.value=''; loadChatUsers(); }
              else { addStatus.style.color='#c00'; addStatus.textContent = data.message||'Failed to add.'; }
            };
          }
          // Suggestions list
          var sugSec = document.getElementById('sugSection');
          var sugList = document.getElementById('sugList');
          var suggestions = data.suggestions||[];
          if (sugSec && sugList){
            if (suggestions.length){
              sugSec.style.display = 'block';
              sugList.innerHTML = suggestions.map(function(s){
                var uname = (s.username||'').replace(/&/g,'&amp;').replace(/</g,'&lt;');
                var av = (uname.charAt(0)||'?').toUpperCase();
                return '<div style="display:flex;align-items:center;gap:8px;">'
                     +   '<div style="width:28px;height:28px;border-radius:50%;background:#e7e7e7;display:flex;align-items:center;justify-content:center;font-weight:600;color:#444">'+ av +'</div>'
                     +   '<div style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+uname+'</div>'
                     +   '<button type="button" class="db-btn db-btn-small" data-add-id="'+s.id+'">Add</button>'
                     + '</div>';
              }).join('');
              Array.prototype.forEach.call(sugList.querySelectorAll('[data-add-id]'), function(btn){
                btn.addEventListener('click', async function(){
                  var uid = parseInt(btn.getAttribute('data-add-id'),10);
                  var paths = [API_BASE+'contacts_api.php?action=add_id', '/php/contacts_api.php?action=add_id', 'contacts_api.php?action=add_id'];
                  var ok = false; var resp = null;
                  for (var i=0;i<paths.length;i++){
                    try{ resp = await fetch(paths[i], {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'user_id='+encodeURIComponent(uid)}); if(resp.ok){ ok=true; break;} }catch(_){ }
                  }
                  if(!ok){ alert('Failed to send request.'); return; }
                  await resp.text();
                  loadChatUsers();
                });
              });
            } else {
              sugSec.style.display = 'none';
              sugList.innerHTML = '';
            }
          }
        }
        if (!isAdmin && CURRENT_LEFT_TAB === 'friends') { return; }
        if(!users.length){ wrap.innerHTML = '<div style="padding:12px;color:#666;">No users yet.</div>'; return; }
        wrap.innerHTML = users.map(function(u){
          var active = (u.id === CURRENT_WITH);
          var uname = (u.username||'');
          var unameEsc = uname.replace(/&/g,'&amp;').replace(/</g,'&lt;');
          var av = (u.avatar||uname.charAt(0).toUpperCase()||'?').replace(/&/g,'&amp;').replace(/</g,'&lt;');
          return '<div class="chat-item" data-id="'+u.id+'" style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;cursor:pointer;'+(active?'background:#f5f7ff;':'')+'">'
               +   '<div style="width:34px;height:34px;border-radius:50%;background:#e7e7e7;display:flex;align-items:center;justify-content:center;font-weight:600;color:#444">'+ av +'</div>'
               +   '<div style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+ unameEsc +'</div>'
               + '</div>';
        }).join('');
        Array.prototype.forEach.call(wrap.querySelectorAll('.chat-item'), function(el){
          el.addEventListener('click', function(){
            var id = parseInt(el.getAttribute('data-id'),10);
            if (!isFinite(id) || id<=0) return;
            CURRENT_WITH = id;
            lastRenderedId = 0;
            var url = new URL(window.location.href);
            url.searchParams.set('with', String(id));
            window.history.replaceState({}, '', url.toString());
            loadMessages(true);
            loadChatUsers();
          });
        });
      }catch(err){
        wrap.innerHTML = '<div style="padding:12px;color:#c00;">Network error.</div>';
      }
    }

    loadMessages(true);
    startPolling();
    if (MY_ROLE === 'admin') { loadChatUsers(); }
  </script>
</body>
</html>
