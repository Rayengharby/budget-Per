// ── Auth helpers ───────────────────────────────────────────
const Auth = {
  getToken() { return localStorage.getItem('bc_token'); },
  getUser()  { try { return JSON.parse(localStorage.getItem('bc_user')); } catch { return null; } },
  save(token, user) { localStorage.setItem('bc_token', token); localStorage.setItem('bc_user', JSON.stringify(user)); },
  clear()    { localStorage.removeItem('bc_token'); localStorage.removeItem('bc_user'); },
  guard()    { if (!this.getToken()) { location.href = 'login.html'; return false; } return true; },
  adminGuard(){ const u = this.getUser(); if (!this.getToken()||!u) { location.href='login.html'; return false; } if (u.role!=='admin') { location.href='dashboard.html'; return false; } return true; },
  guestGuard(){ if (this.getToken()) { location.href='dashboard.html'; return false; } return true; },
  logout()   { this.clear(); location.href = 'login.html'; },
};

// ── Sidebar builder ─────────────────────────────────────────
function buildSidebar(activePage) {
  const user = Auth.getUser();
  if (!user) return;
  const NAV_USER = [
    { page:'dashboard',    icon:'📊', label:'Tableau de bord', href:'dashboard.html' },
    { page:'transactions', icon:'💳', label:'Transactions',    href:'transactions.html' },
    { page:'budgets',      icon:'🎯', label:'Mes budgets',     href:'budgets.html' },
    { page:'shared',       icon:'👥', label:'Budget partagé',  href:'shared.html' },
    { page:'profile',      icon:'👤', label:'Mon profil',      href:'profile.html' },
  ];
  const NAV_ADMIN = [
    { page:'dashboard',    icon:'📊', label:'Tableau de bord', href:'dashboard.html' },
    { page:'transactions', icon:'💳', label:'Transactions',    href:'transactions.html' },
    { page:'budgets',      icon:'🎯', label:'Mes budgets',     href:'budgets.html' },
    { page:'shared',       icon:'👥', label:'Budget partagé',  href:'shared.html' },
    { page:'categories',   icon:'🏷️',  label:'Catégories',      href:'categories.html' },
    { page:'admin',        icon:'⚙️',  label:'Administration',  href:'admin.html', isAdmin:true },
    { page:'profile',      icon:'👤', label:'Mon profil',      href:'profile.html' },
  ];
  const NAV = user.role === 'admin' ? NAV_ADMIN : NAV_USER;
  const initials = (user.name||'U').split(' ').map(n=>n[0]).join('').toUpperCase().slice(0,2);
  const roleLabel = user.role==='admin' ? '⚙️ Administrateur' : '👤 Utilisateur';

  document.getElementById('sidebar-root').innerHTML = `
    <aside class="sidebar">
      <div class="sidebar-logo">
        <span class="logo-emoji">💰</span>
        <div class="logo-name">BudgetCollab</div>
        <div class="logo-sub">Gestion collaborative</div>
      </div>
      <nav class="sidebar-nav">
        ${NAV.map(n=>`
          <a href="${n.href}" class="nav-item${n.page===activePage?' '+(n.isAdmin?'admin-active':'active'):''}">
            <span class="nav-icon">${n.icon}</span>
            <span class="nav-label">${n.label}</span>
          </a>`).join('')}
      </nav>
      <div class="sidebar-footer">
        <div class="sidebar-user">
          <div class="user-avatar">${initials}</div>
          <div style="overflow:hidden">
            <div class="user-name">${user.name||''}</div>
            <div class="user-role-label">${roleLabel}</div>
          </div>
        </div>
        <button class="btn-logout" id="btn-logout">Se déconnecter</button>
      </div>
    </aside>`;

  document.getElementById('btn-logout').addEventListener('click', ()=>Auth.logout());
}

// ── Toast ────────────────────────────────────────────────────
function showToast(msg, type='info') {
  let c=document.getElementById('toast-container');
  if (!c){c=document.createElement('div');c.id='toast-container';c.className='toast-container';document.body.appendChild(c);}
  const t=document.createElement('div');t.className=`toast ${type}`;t.textContent=msg;
  c.appendChild(t);setTimeout(()=>t.remove(),3500);
}

// ── Formatters ───────────────────────────────────────────────
const fmt = n => new Intl.NumberFormat('fr-TN',{style:'currency',currency:'TND'}).format(n||0);
const fmtDate = d => d ? new Date(d).toLocaleDateString('fr-TN') : '—';
