// Menu on scroll
let header = document.querySelector('.header');
window.onscroll = function () {
    if (this.scrollY >= 100) {
        header.classList.add('active');
    } else {
        header.classList.remove('active');
    }
    if (btnMenu) btnMenu.classList.remove('fa-times');
    if (NavLinks) NavLinks.classList.remove('active');
};

let btnMenu = document.getElementById('btnMenu');
let NavLinks = document.querySelector('.nav-links');

// Menu toggle with delay
btnMenu.onclick = function () {
    btnMenu.classList.toggle('fa-times');

    if (!NavLinks.classList.contains("active")) {
        setTimeout(() => {
            NavLinks.classList.add("active");
        }, 200); // 200ms delay before showing
    } else {
        setTimeout(() => {
            NavLinks.classList.remove("active");
        }, 200); // 200ms delay before hiding
    }
};

// Smooth Scroll with Delay (Fixed Login Button Issue)
document.querySelectorAll('.nav-links a').forEach(link => {
    link.addEventListener("click", function (e) {
        // Let the Login button (with its inline onclick) handle navigation/overlay
        if (this.classList.contains("btn")) {
            return;
        }

        e.preventDefault(); // Prevent default for internal links
        let targetId = this.getAttribute("href");
        let targetSection = document.querySelector(targetId);

        if (targetSection) {
            setTimeout(() => {
                window.scrollTo({
                    top: targetSection.offsetTop - 50,
                    behavior: "smooth"
                });
            }, 300);
        }

        // Close menu after clicking
        setTimeout(() => {
            if (NavLinks) NavLinks.classList.remove("active");
            if (btnMenu) btnMenu.classList.remove("fa-times");
        }, 500);
    });
});

// Swiper Slider (guard if container exists)
if (document.querySelector('.swip-test-imo')) {
    var swiper = new Swiper(".swip-test-imo", {
        slidesPerView: 1,
        spaceBetween: 30,
        loop: true,
        pagination: {
            el: ".swiper-pagination",
            clickable: true,
        },
        navigation: {
            nextEl: ".swiper-button-next",
            prevEl: ".swiper-button-prev",
        },
    });
}

// Add class active to link in menu by scroll
let links = document.querySelectorAll('.nav-links a');
let sections = document.querySelectorAll('section');

function activeMenu() {
    let len = sections.length;
    while (--len && window.scrollY + 100 < sections[len].offsetTop) {}
    links.forEach(ltx => ltx.classList.remove("active"));
    if (links[len]) {
        links[len].classList.add("active");
    } else if (links[0]) {
        links[0].classList.add("active");
    }
}

// Load site config (home bg) and apply to hero
async function loadAndApplySiteConfig(){
  try{
    // Apply cached home background immediately to avoid flash of fallback
    try{
      var cached = localStorage.getItem('site_bg_home');
      if (cached) {
        var homeEl0 = document.querySelector('.home');
        if (homeEl0) {
          homeEl0.style.background = 'url("'+cached+'")';
          homeEl0.style.backgroundSize = 'cover';
          homeEl0.style.backgroundPosition = 'center';
        }
      }
    }catch(e){}
    var urls = ['php/site_config.php?action=get&keys=home_bg','/php/site_config.php?action=get&keys=home_bg','/VISITACION-LANDING-PAGE/php/site_config.php?action=get&keys=home_bg'];
    var res = null;
    for (var i=0;i<urls.length;i++){
      try{ var r = await fetch(urls[i], { credentials: 'same-origin', cache: 'no-store' }); if(r.ok){ res = r; break; } }catch(e){}
    }
    var data = res ? await res.json() : null;
    var home = data && (data.home_bg || data['home_bg']);
    var imgs = [];
    if (home && typeof home === 'string' && home.indexOf('http') === 0) {
      imgs.push(home);
      var homeEl = document.querySelector('.home');
      if (homeEl) {
        homeEl.style.background = 'url("'+home+'")';
        homeEl.style.backgroundSize = 'cover';
        homeEl.style.backgroundPosition = 'center';
      }
      try{ localStorage.setItem('site_bg_home', home); }catch(e){}
    }
    imgs.push('img/bgOld.jpg','img/bg.jpg');
    initHeroSlideshow(imgs);
  }catch(e){
    initHeroSlideshow();
  }
}

// Open Lightbox
function openLightbox(src, type) {
    const lightbox = document.getElementById("lightbox");
    const img = document.getElementById("lightbox-img");
    const video = document.getElementById("lightbox-video");

    lightbox.style.display = "flex";

    if (type === "image") {
        img.src = src;
        img.style.display = "block";
        video.style.display = "none";
    } else if (type === "video") {
        video.src = src;
        video.style.display = "block";
        img.style.display = "none";
    }
}

// Close Lightbox
function closeLightbox() {
    const lightbox = document.getElementById("lightbox");
    const video = document.getElementById("lightbox-video");

    lightbox.style.display = "none";
    video.pause();
    video.currentTime = 0;
}

// Close Lightbox on Click Outside
var _lb = document.getElementById("lightbox");
if (_lb) {
    _lb.addEventListener("click", function (e) {
        if (e.target === this) {
            closeLightbox();
        }
    });
}

// Close Lightbox with Escape Key
document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
        closeLightbox();
    }
});

let currentMedia = null; // Stores the currently playing media (audio/video)

function handleMediaPlayback(mediaElement) {
    // Pause any currently playing media before playing a new one
    if (currentMedia && currentMedia !== mediaElement) {
        currentMedia.pause();
        if (currentMedia.tagName === "VIDEO") {
            currentMedia.currentTime = 0; // Reset video to start
        }
    }
    currentMedia = mediaElement; // Update current playing media
}

// Select all audio elements
const audios = document.querySelectorAll("audio");
audios.forEach(audio => {
    audio.addEventListener("play", function () {
        handleMediaPlayback(this);
    });
});

// Select all video elements
const videos = document.querySelectorAll("video");
videos.forEach(video => {
    video.addEventListener("play", function () {
        handleMediaPlayback(this);
    });
});
window.addEventListener("scroll", activeMenu);
// Initialize dynamic enhancements on DOM ready
document.addEventListener('DOMContentLoaded', function(){
  loadAndApplySiteConfig();
  initTypedRoles();
  initCountersAndProgress();
  initReveal();
  initContactGate();
  activeMenu();
});

// Dynamic hero background slideshow
function initHeroSlideshow(customImgs){
  var host = document.querySelector('.hero-slides');
  if(!host) return;
  var imgs = Array.isArray(customImgs) && customImgs.length ? customImgs : ['img/bgOld.jpg','img/bg.jpg'];
  var a = document.createElement('div');
  var b = document.createElement('div');
  a.className = 'slide active';
  b.className = 'slide';
  host.appendChild(a); host.appendChild(b);
  var cur = 0, active = a, idle = b;
  function setBg(el, src){ el.style.backgroundImage = 'url("'+src+'")'; }
  setBg(active, imgs[cur]);
  setBg(idle, imgs[(cur+1)%imgs.length]);
  setInterval(function(){
    cur = (cur+1)%imgs.length;
    var tmp = active; active = idle; idle = tmp;
    setBg(idle, imgs[(cur+1)%imgs.length]);
    active.classList.add('active');
    idle.classList.remove('active');
  }, 6000);
}

// Typed roles effect
function initTypedRoles(){
  var el = document.getElementById('typed');
  if(!el) return;
  var roles = ['A BSIT Student','Editor','Programmer','Photographer'];
  var i = 0, j = 0, typing = true;
  function tick(){
    var word = roles[i];
    if(typing){
      j++; el.textContent = word.slice(0, j);
      if(j === word.length){ typing = false; return setTimeout(tick, 1200); }
    }else{
      j--; el.textContent = word.slice(0, j);
      if(j === 0){ typing = true; i = (i+1) % roles.length; return setTimeout(tick, 300); }
    }
    setTimeout(tick, typing ? 90 : 45);
  }
  tick();
}

// Animate counters and progress bars
function initCountersAndProgress(){
  var target = document.querySelector('.promo-number') || document.getElementById('Service');
  var bars = document.querySelectorAll('.progress .progress-bar');
  if(!target && !bars.length) return;
  var done = false;
  var io = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(e.isIntersecting && !done){ done = true; animate(); io.disconnect(); }
    });
  }, {threshold: 0.3});
  io.observe(target || document.body);
  function animate(){
    document.querySelectorAll('.promo-number .small-box span').forEach(function(span){
      var to = parseInt(span.textContent, 10); if(isNaN(to)) return;
      var startTs = null, dur = 1200 + to * 50;
      function step(ts){ if(!startTs) startTs = ts; var p = Math.min((ts - startTs)/dur, 1); var val = Math.floor(p * to); span.textContent = val; if(p < 1) requestAnimationFrame(step); }
      requestAnimationFrame(step);
    });
    bars.forEach(function(bar){
      var dest = bar.style.width || getComputedStyle(bar).width;
      bar.style.width = '0%';
      void bar.offsetWidth;
      setTimeout(function(){ bar.style.width = dest; }, 50);
    });
  }
}

// Scroll reveal effects
function initReveal(){
  var selector = ['section .container','.page-card','.audio-item','.contact-form','.small-box','.skill-with-social','.section-description'].join(',');
  var list = Array.prototype.slice.call(document.querySelectorAll(selector));
  if(!list.length) return;
  list.forEach(function(el){ el.classList.add('reveal'); });
  var io = new IntersectionObserver(function(entries){
    entries.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('show'); io.unobserve(e.target); } });
  }, {threshold: 0.15});
  list.forEach(function(el){ io.observe(el); });
  var gal = document.getElementById('photoGallery');
  if(gal){
    var mo = new MutationObserver(function(muts){
      muts.forEach(function(m){
        m.addedNodes.forEach(function(n){
          if(n.classList && n.classList.contains('gallery-item')){ n.classList.add('reveal'); io.observe(n); }
        });
      });
    });
    mo.observe(gal, {childList: true});
  }
}

function initContactGate(){
  var form = document.querySelector('.contact-form form');
  if(!form) return;
  // Ensure the form doesn't navigate away
  try{ form.setAttribute('action','#'); form.removeAttribute('target'); }catch(_){ }
  var btn = form.querySelector('#sendMessageBtn');

  async function handleSend(e){
    if (e && e.preventDefault) e.preventDefault();
    var msgEl = form.querySelector('textarea[name="message"]');
    var content = msgEl ? String(msgEl.value||'').trim() : '';
    if (!content){ alert('Please type a message.'); return; }
    try{
      var urls = ['php/session_check.php','/php/session_check.php','/VISITACION-LANDING-PAGE/php/session_check.php'];
      var res = null;
      for (var i=0;i<urls.length;i++){
        var u = urls[i] + (urls[i].indexOf('?')>-1?'&':'?') + 't=' + Date.now();
        try{ var r = await fetch(u, { credentials: 'same-origin', cache: 'no-store' }); if(r.ok){ res = r; break; } }catch(ex){}
      }
      var data = null;
      try{ data = res ? await res.json() : null; }catch(_jsonErr){ data = null; }
      if (!data || !data.logged_in){
        alert('Please register or login to send a message.');
        location.hash = '#login';
        return;
      }
      var sendUrls = ['php/message_send.php','/php/message_send.php','/VISITACION-LANDING-PAGE/php/message_send.php'];
      var res2 = null;
      for (var j=0;j<sendUrls.length;j++){
        var su = sendUrls[j] + (sendUrls[j].indexOf('?')>-1?'&':'?') + 't=' + Date.now();
        try{
          var r2 = await fetch(su, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'content='+encodeURIComponent(content) });
          if (r2.ok){ res2 = r2; break; }
        }catch(ex2){}
      }
      var reply = null;
      try{ reply = res2 ? await res2.json() : null; }catch(_jsonErr2){ reply = null; }
      if (reply && reply.success){ alert('Message sent.'); form.reset(); }
      else { alert((reply && reply.message) ? reply.message : 'Failed to send message.'); }
    }catch(err){
      alert('Network error.');
    }
  }

  // Support both button click and Enter submit
  if (btn) btn.addEventListener('click', handleSend);
  form.addEventListener('submit', handleSend);
}

(function(){
  async function fetchFirstOk(urls, opts){
    for (var i=0;i<urls.length;i++){
      try{ var r = await fetch(urls[i], opts); if(r.ok) return r; }catch(e){}
    }
    throw new Error('all failed');
  }
  function esc(s){ return String(s||'').replace(/[&<>"]/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }
  async function loadGallery(){
    var wrap = document.getElementById('photoGallery');
    if(!wrap) return;
    wrap.innerHTML = '<div style="text-align:center;color:#666;">Loading gallery...</div>';
    var bases = ['php/gallery_api.php','/php/gallery_api.php','/VISITACION-LANDING-PAGE/php/gallery_api.php'];
    var candidates = bases.map(function(u){ return u + '?action=list&t=' + Date.now(); });
    try{
      var res = await fetchFirstOk(candidates, { credentials: 'same-origin', cache: 'no-store' });
      var data = await res.json();
      var items = (data && data.images) || [];
      // Ensure hidden images are not shown on the homepage
      items = items.filter(function(it){ return !parseInt((it && it.is_hidden) || 0, 10); });
      if (!items.length){ wrap.innerHTML = '<div style="text-align:center;color:#666;">No photos yet.</div>'; return; }
      wrap.innerHTML = items.map(function(it){ var url = esc(it.url||''); return '<div class="gallery-item" onclick="openLightbox(\''+url+'\', \'image\')"><img src="'+url+'" alt="Photo"></div>'; }).join('');
    }catch(e){
      wrap.innerHTML = '<div style="text-align:center;color:#c00;">Failed to load gallery.</div>';
    }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', loadGallery); } else { loadGallery(); }
})();
