window.__doorModuleStarted = true;
window.__doorBootStage = 'module-started';
const API = '/api/v1';
const DB_NAME = 'door-offline-v1';
let state = { user: null, csrf: '', serverAuthenticated: false, page: 'home', workTypes: [], online: navigator.onLine, busy: false };

const app = document.getElementById('app');
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = value => new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB',maximumFractionDigits:2}).format(Number(value||0));
const date = value => value ? new Date(value).toLocaleString('ru-RU',{dateStyle:'short',timeStyle:'short'}) : '—';
const localDateTime = value => { const p=n=>String(n).padStart(2,'0'); return value.getFullYear()+'-'+p(value.getMonth()+1)+'-'+p(value.getDate())+'T'+p(value.getHours())+':'+p(value.getMinutes()); };
const toast = message => { const el=document.createElement('div');el.className='toast';el.textContent=message;document.body.append(el);setTimeout(()=>el.remove(),3200); };

async function api(path, options={}) {
  const headers = new Headers(options.headers || {});
  if (state.csrf && !['GET','HEAD'].includes((options.method||'GET').toUpperCase())) headers.set('X-CSRF-Token',state.csrf);
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type','application/json');
  const response = await fetch(API+path,{...options,headers,credentials:'same-origin',cache:'no-store'});
  let json={};try{json=await response.json();}catch(_){}
  if(!response.ok || json.success===false){const err=new Error(json.error?.message || 'Ошибка запроса');err.status=response.status;err.code=json.error?.code;err.details=json.error?.details;throw err;}
  return json.data;
}

async function downloadFile(path){
  const response=await fetch(API+path,{credentials:'same-origin',cache:'no-store'});
  if(!response.ok){let payload={};try{payload=await response.json();}catch(_){}throw new Error(payload.error?.message||'Не удалось сформировать файл');}
  const blob=await response.blob();
  const disposition=response.headers.get('Content-Disposition')||'';
  const match=disposition.match(/filename="([^"]+)"/i);
  const filename=match?.[1]||'door-report';
  const url=URL.createObjectURL(blob);
  const link=document.createElement('a');link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function openDb(){
  return new Promise((resolve,reject)=>{
    const req=indexedDB.open(DB_NAME,1);
    req.onupgradeneeded=()=>{const db=req.result;if(!db.objectStoreNames.contains('queue'))db.createObjectStore('queue',{keyPath:'id'});if(!db.objectStoreNames.contains('meta'))db.createObjectStore('meta',{keyPath:'key'});};
    req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error);
  });
}
async function dbAction(store,mode,fn){
  const db=await openDb();
  return new Promise((resolve,reject)=>{const tx=db.transaction(store,mode);const result=fn(tx.objectStore(store));tx.oncomplete=()=>{db.close();resolve(result?.result??result);};tx.onerror=()=>{db.close();reject(tx.error);};});
}
const queueAll=()=>dbAction('queue','readonly',s=>s.getAll());
const queuePut=item=>dbAction('queue','readwrite',s=>s.put(item));
const queueDelete=id=>dbAction('queue','readwrite',s=>s.delete(id));
const metaPut=(key,value)=>dbAction('meta','readwrite',s=>s.put({key,value}));
const metaGet=key=>dbAction('meta','readonly',s=>s.get(key));

async function loadWorkTypes(){
  if(!state.user || !['master','director'].includes(state.user.role)){state.workTypes=[];return;}
  try{
    const data=await api('/work-types');
    const list=Array.isArray(data)?data:(data.items||[]);
    state.workTypes=list.filter(x=>x.is_active===undefined || Number(x.is_active)===1 || x.is_active===true);
    await metaPut('workTypes',state.workTypes);
  }catch(e){
    const cached=await metaGet('workTypes').catch(()=>null);
    state.workTypes=cached?.value||[];
    if(!state.workTypes.length && e.status!==401) toast('Справочник видов работ пока недоступен');
  }
}

async function boot(){
  window.__doorBootStage = 'boot-started';
  try{
    // Сначала восстанавливаем локальную сессию.
    window.__doorBootStage = 'reading-auth-indexeddb';
    const cached = await metaGet('auth').catch(() => null);
    window.__doorBootStage = 'auth-indexeddb-read-complete';

    state.csrf = '';
    state.serverAuthenticated = false;

    if(cached?.value?.user){
      state.user = cached.value.user;

      // Справочник также восстанавливаем локально.
      const cachedTypes = await metaGet('workTypes').catch(() => null);
      state.workTypes = cachedTypes?.value || [];

      // Не ждём сеть: интерфейс должен открыться сразу.
      render();

      // Проверка авторизации и синхронизация выполняются в фоне.
      if(navigator.onLine){
        reconnectAndSync();
      }

      return;
    }

    // Если локальной сессии нет, необходим первый вход через сервер.
    state.user = null;

    if(navigator.onLine){
      try{
        const data = await api('/auth/me');
        state.user = data.user;
        state.csrf = data.csrf_token;
        state.serverAuthenticated = true;

        await metaPut('auth', {user: state.user});
        await loadWorkTypes();

        render();
        syncQueue();
        return;
      }catch(e){
        if(e.status !== 401){
          renderLogin('Сервер недоступен. Для первого входа требуется подключение.');
          return;
        }
      }
    }

    renderLogin();
  }catch(e){
    if(state.user){
      render();
    }else{
      renderLogin(e.message);
    }
  }
}

let loginCaptchaId='';

async function refreshLoginCaptcha(){
  const question=document.getElementById('captcha-question');
  const input=document.getElementById('captcha-answer');
  if(question)question.textContent='Загрузка…';
  try{
    const data=await api('/auth/captcha');
    loginCaptchaId=data.id;
    if(question)question.textContent=data.question;
    if(input)input.value='';
  }catch(err){
    loginCaptchaId='';
    if(question)question.textContent='Не удалось загрузить пример';
    const status=document.getElementById('captcha-status');
    if(status)status.textContent=err.message;
  }
}

function renderLogin(error=''){
  loginCaptchaId='';
  app.innerHTML=`<main class="login-wrap"><form class="login-card" id="login-form"><div class="brand-mark">Д</div><h1>Учёт дверей</h1><p class="muted">Вход в систему</p>${error?`<p class="error">${esc(error)}</p>`:''}<div class="field"><label for="username">Логин</label><input id="username" name="username" autocomplete="username" required></div><div class="field"><label for="password">Пароль</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><div class="field"><label for="captcha-answer">Проверочный пример</label><div style="display:flex;align-items:center;gap:12px;margin-bottom:8px"><strong id="captcha-question" style="font-size:18px;letter-spacing:.3px">Загрузка…</strong><button class="btn" id="captcha-refresh" type="button" style="padding:7px 11px">Обновить</button></div><input id="captcha-answer" name="captcha_answer" inputmode="numeric" autocomplete="off" placeholder="Введите ответ" required><small id="captcha-status" class="hint"></small></div><button class="btn block" type="submit">Войти</button><p class="hint">Для первого входа требуется подключение к серверу.</p></form></main>`;
  document.getElementById('captcha-refresh').addEventListener('click',refreshLoginCaptcha);
  refreshLoginCaptcha();
  document.getElementById('login-form').addEventListener('submit',async e=>{
    e.preventDefault();
    const form=e.currentTarget;
    const btn=form.querySelector('button[type="submit"]');
    btn.disabled=true;btn.textContent='Проверка…';
    try{
      if(!loginCaptchaId)throw new Error('CAPTCHA ещё не загружена. Обновите пример.');
      const data=await api('/auth/login',{method:'POST',body:JSON.stringify({
        username:form.username.value,
        password:form.password.value,
        captcha_id:loginCaptchaId,
        captcha_answer:form.captcha_answer.value.trim()
      })});
      loginCaptchaId='';
      state.user=data.user;state.csrf=data.csrf_token;state.serverAuthenticated=true;
      await metaPut('auth',{user:state.user});
      await loadWorkTypes();state.page='home';render();toast('Вход выполнен');
    }catch(err){
      renderLogin(err.message);
    }finally{
      btn.disabled=false;
    }
  });
}

const navItems=[['home','Обзор','⌂'],['new','Новая работа','＋'],['queue','Офлайн-очередь','⇧'],['history','История','▤'],['doors','Двери','▣'],['stats','Статистика','▥'],['finance','Финансы','₽'],['work-management','Виды работ и тарифы','⚒'],['settings','Пользователи','⚙']];
const rolePages={owner:['home','stats','settings'],master:['home','new','queue','history','doors','stats','finance'],director:['home','history','doors','stats','finance','work-management']};
function canOpenPage(page){return (rolePages[state.user?.role]||[]).includes(page);}
function render(){
  const role=state.user?.role||'';
  if(!canOpenPage(state.page))state.page='home';
  const items=navItems.filter(([id])=>canOpenPage(id));
  app.innerHTML=`<div class="layout"><aside class="sidebar"><div class="brand"><span class="brand-mark">Д</span><div><strong>Учёт дверей</strong><small>Производственный учёт</small></div></div><nav class="nav">${items.map(([id,label])=>navButton(id,label)).join('')}</nav><div class="sidebar-bottom"><div>${esc(state.user.full_name||state.user.username||'Пользователь')}</div><div>${esc(roleLabel(role))}</div><button class="nav-button" data-action="logout" style="margin-top:14px;background:transparent;border:0;color:#d9e5eb;padding:8px">Выйти ↗</button></div></aside><main class="main"><header class="topbar"><h1>${pageTitle()}</h1><div class="user-chip">${esc(state.user.full_name||'')} · ${esc(roleLabel(role))}</div><div class="connection"><span class="dot ${state.online?'':'offline'}"></span>${state.online?'В сети':'Нет сети'}</div></header><section class="content" id="page-content"></section></main></div><nav class="mobile-nav">${items.map(([id,label,icon])=>`<button data-page="${id}" class="${state.page===id?'active':''}"><span class="nav-icon">${icon}</span><span>${label}</span></button>`).join('')}</nav>`;
  app.querySelectorAll('[data-page]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.page)));
  app.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.nav)));
  app.querySelector('[data-action="logout"]')?.addEventListener('click',logout);
  renderPage();
}
function navButton(id,label){return `<button data-nav="${id}" class="${state.page===id?'active':''}">${label}</button>`;}
function roleLabel(role){return ({owner:'Администратор',master:'Мастер',director:'Директор'})[role]||'Пользователь';}
function pageTitle(){return ({home:'Обзор',new:'Регистрация работы',queue:'Офлайн-очередь',history:'История работ',doors:'Двери',stats:'Статистика',finance:'Финансы','work-management':'Виды работ и тарифы',settings:'Пользователи'})[state.page]||'Учёт дверей';}
function go(page){if(!canOpenPage(page))return;state.page=page;render();}
function pageHead(title,sub,button=''){return `<div class="page-head"><div><h2>${title}</h2><p>${sub}</p></div>${button}</div>`;}

async function renderPage(){
  const el=document.getElementById('page-content');if(!el)return;
  if(!canOpenPage(state.page))state.page='home';
  if(state.page==='new'){renderNew(el);return;}
  if(state.page==='queue'){await renderQueue(el);return;}
  if(state.page==='history'){await renderHistory(el);return;}
  if(state.page==='doors'){renderDoors(el);return;}
  if(state.page==='stats'){await renderStats(el);return;}
  if(state.page==='finance'){await renderFinance(el);return;}
  if(state.page==='work-management'){await renderWorkManagement(el);return;}
  if(state.page==='settings'){await renderSettings(el);return;}
  await renderHome(el);
}
async function renderHome(el){
  const role=state.user.role;
  if(role==='owner'){
    el.innerHTML=pageHead('Обзор','Общая статистика системы')+`<div class="cards" id="admin-overview"><div class="stat-card"><span>Дверей</span><strong>—</strong></div><div class="stat-card"><span>Операций</span><strong>—</strong></div><div class="stat-card"><span>Ремонтов</span><strong>—</strong></div><div class="stat-card"><span>Упаковок</span><strong>—</strong></div><div class="stat-card"><span>Дополнительных работ</span><strong>—</strong></div></div><div class="panel"><h3>Администрирование</h3><div class="actions"><button class="btn" data-nav="settings">Управление пользователями</button><button class="btn secondary" data-nav="stats">Общая статистика</button></div></div>`;
    el.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.nav)));
    try{const summary=await api('/stats/summary');const n=summary.counts||{};const vals=[n.doors,n.operations,n.repairs,n.packings,n.other_works];el.querySelectorAll('#admin-overview .stat-card strong').forEach((node,i)=>node.textContent=vals[i]??0);}
    catch(e){el.insertAdjacentHTML('beforeend',`<div class="notice warn">${esc(e.message)}</div>`);}
    return;
  }
  if(role==='director'){
    await renderDirectorHome(el);
    return;
  }
  const isMaster=role==='master';
  el.innerHTML=pageHead('Рабочий обзор','Состояние учёта выполненных работ')+`<div class="cards">${isMaster?'<div class="stat-card"><span>Ожидают отправки</span><strong id="pending-count">…</strong></div>':''}<div class="stat-card"><span>Подключение</span><strong style="font-size:20px">${state.online?'Онлайн':'Офлайн'}</strong></div><div class="stat-card"><span>Пользователь</span><strong style="font-size:18px">${esc(state.user.full_name||'')}</strong></div></div><div class="panel"><h3>Быстрые действия</h3><div class="actions">${isMaster?'<button class="btn" data-nav="new">＋ Зарегистрировать работу</button><button class="btn secondary" data-nav="queue">Открыть очередь</button>':''}<button class="btn secondary" data-nav="history">История работ</button>${role==='director'?'<button class="btn secondary" data-nav="work-management">Виды работ и тарифы</button><button class="btn secondary" data-nav="finance">Финансы и выплаты</button>':''}</div></div><div class="panel"><h3>Последние работы</h3><div id="recent-list" class="list"><div class="muted">Загрузка…</div></div></div>`;
  el.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.nav)));
  if(isMaster){const queue=await queueAll().catch(()=>[]);const pc=el.querySelector('#pending-count');if(pc)pc.textContent=queue.filter(q=>Number(q.ownerUserId)===Number(state.user.id)).length;}
  try{const data=await api('/work-records?per_page=5');const rows=data.items||[];const list=el.querySelector('#recent-list');if(list){list.innerHTML=rows.length?rows.map(recordHtml).join(''):'<div class="empty">Записей пока нет</div>';bindWorkCards(list);}}
  catch(e){const list=el.querySelector('#recent-list');if(list)list.innerHTML=`<div class="notice warn">${esc(e.message)}</div>`;}
}

async function renderDirectorHome(el){
  const today=new Date();
  const formatDate=d=>[
    d.getFullYear(),
    String(d.getMonth()+1).padStart(2,'0'),
    String(d.getDate()).padStart(2,'0')
  ].join('-');

  const firstDay=new Date(today.getFullYear(),today.getMonth(),1);
  const defaultFrom=formatDate(firstDay);
  const defaultTo=formatDate(today);

  el.innerHTML=pageHead(
    'Панель директора',
    'Производство, начисления и состояние расчётов'
  )+`
    <form id="director-filter" class="panel">
      <h3>Период отчёта</h3>
      <div class="form-grid">
        <div class="field">
          <label for="director-from">Дата с</label>
          <input id="director-from" name="date_from" type="date" value="${defaultFrom}">
        </div>
        <div class="field">
          <label for="director-to">Дата по</label>
          <input id="director-to" name="date_to" type="date" value="${defaultTo}">
        </div>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Применить</button>
        <button class="btn ghost" type="reset">Текущий месяц</button>
      </div>
      <div id="director-filter-error" class="error"></div>
    </form>

    <div class="panel">
      <h3>Быстрый переход</h3>
      <div class="actions">
        <button class="btn secondary" data-nav="history">История работ</button>
        <button class="btn secondary" data-nav="doors">Двери</button>
        <button class="btn secondary" data-nav="stats">Статистика</button>
        <button class="btn secondary" data-nav="finance">Финансы и выплаты</button>
        <button class="btn secondary" data-nav="work-management">Виды работ и тарифы</button>
      </div>
    </div>

    <div class="panel">
      <h3>Производственные показатели за период</h3>
      <div id="director-production" class="cards">
        <div class="muted">Загрузка…</div>
      </div>
    </div>

    <div class="panel">
      <h3>Финансовая сводка</h3>
      <h4>Оборот за выбранный период</h4>
      <div id="director-finance-period" class="cards">
        <div class="muted">Загрузка…</div>
      </div>
      <h4>Итоги за всё время</h4>
      <div id="director-finance-all" class="cards">
        <div class="muted">Загрузка…</div>
      </div>
      <h4>Текущие остатки</h4>
      <div id="director-finance-balance" class="cards">
        <div class="muted">Загрузка…</div>
      </div>
    </div>

    <div class="panel">
      <h3>Исполнители за выбранный период</h3>
      <div id="director-executors" class="list">
        <div class="muted">Загрузка…</div>
      </div>
    </div>

    <div class="panel">
      <h3>Последние работы за период</h3>
      <div id="director-recent" class="list">
        <div class="muted">Загрузка…</div>
      </div>
    </div>

    <div class="panel" id="director-reports">
      <h3>Отчёты и документы</h3>
      <p class="hint">PDF-отчёты и табличные выгрузки формируются за период, выбранный выше.</p>

      <h4>Документы PDF</h4>
      <div class="actions">
        <button class="btn secondary" type="button" data-director-pdf="production">Производство</button>
        <button class="btn secondary" type="button" data-director-pdf="payments">Выплаты</button>
        <button class="btn secondary" type="button" data-director-pdf="finance">Финансы</button>
        <button class="btn secondary" type="button" data-director-pdf="photos">Фотофиксация</button>
      </div>

      <h4>Выгрузка данных</h4>
      <div class="form-grid">
        <div class="field">
          <label for="director-export-dataset">Набор данных</label>
          <select id="director-export-dataset">
            <option value="works">Выполненные работы</option>
            <option value="payments">Выплаты</option>
            <option value="balances">Финансовые остатки</option>
            <option value="stats-users">Статистика по исполнителям</option>
            <option value="stats-types">Статистика по видам работ</option>
            <option value="stats-daily">Статистика по дням</option>
            <option value="stats-doors">Статистика по дверям</option>
          </select>
        </div>
        <div class="field">
          <label>Формат файла</label>
          <div class="actions">
            <button class="btn" id="director-export-csv" type="button">CSV</button>
            <button class="btn" id="director-export-xlsx" type="button">XLSX</button>
          </div>
        </div>
      </div>

      <h4>Расчётные листы</h4>
      <div class="form-grid">
        <div class="field">
          <label for="director-payslip-run">Подтверждённый расчёт</label>
          <select id="director-payslip-run">
            <option value="">Загрузка расчётов…</option>
          </select>
        </div>
        <div class="field">
          <label>Документ</label>
          <button class="btn secondary" id="director-payslip-download" type="button" disabled>Скачать расчётный лист PDF</button>
        </div>
      </div>
      <div id="director-payslip-hint" class="hint">В списке отображаются подтверждённые расчёты. Период отчёта выше на расчётный лист не влияет.</div>
    </div>
  `;

  el.querySelectorAll('[data-nav]').forEach(button=>{
    button.addEventListener('click',()=>go(button.dataset.nav));
  });

  const form=el.querySelector('#director-filter');
  const filterError=el.querySelector('#director-filter-error');
  const production=el.querySelector('#director-production');
  const financePeriod=el.querySelector('#director-finance-period');
  const financeAll=el.querySelector('#director-finance-all');
  const financeBalance=el.querySelector('#director-finance-balance');
  const executors=el.querySelector('#director-executors');
  const recent=el.querySelector('#director-recent');

  // Отчёты директора: сервер самостоятельно применяет права и ограничения.
  const reportPanel=el.querySelector('#director-reports');
  const payslipSelect=el.querySelector('#director-payslip-run');
  const payslipButton=el.querySelector('#director-payslip-download');
  const payslipHint=el.querySelector('#director-payslip-hint');

  const directorReportQuery=()=>{
    const from=form.elements.date_from.value;
    const to=form.elements.date_to.value;

    if(from && to && from>to){
      throw new Error('Дата начала периода не может быть позже даты окончания.');
    }

    const params=new URLSearchParams();
    if(from)params.set('date_from',from);
    if(to)params.set('date_to',to);

    const query=params.toString();
    return query?'?'+query:'';
  };

  const runReportDownload=async(button,path)=>{
    button.disabled=true;
    const originalText=button.textContent;
    button.textContent='Формирование…';

    try{
      await downloadFile(path);
    }catch(e){
      toast(e.message||'Не удалось сформировать отчёт');
    }finally{
      button.disabled=false;
      button.textContent=originalText;
    }
  };

  reportPanel.querySelectorAll('[data-director-pdf]').forEach(button=>{
    button.addEventListener('click',async()=>{
      let query;
      try{
        query=directorReportQuery();
      }catch(e){
        toast(e.message);
        return;
      }

      await runReportDownload(
        button,
        '/export/pdf/'+encodeURIComponent(button.dataset.directorPdf)+query
      );
    });
  });

  const exportDirectorData=async(format)=>{
    let query;
    try{
      query=new URLSearchParams({
        dataset:el.querySelector('#director-export-dataset').value
      });

      const dates=directorReportQuery();
      const dateParams=new URLSearchParams(dates);
      for(const [key,value] of dateParams.entries()){
        query.set(key,value);
      }
    }catch(e){
      toast(e.message);
      return;
    }

    const button=el.querySelector(
      format==='csv'?'#director-export-csv':'#director-export-xlsx'
    );

    await runReportDownload(button,'/export/'+format+'?'+query.toString());
  };

  el.querySelector('#director-export-csv').addEventListener('click',()=>{
    exportDirectorData('csv');
  });

  el.querySelector('#director-export-xlsx').addEventListener('click',()=>{
    exportDirectorData('xlsx');
  });

  payslipSelect.addEventListener('change',()=>{
    payslipButton.disabled=!payslipSelect.value;
  });

  payslipButton.addEventListener('click',async()=>{
    const id=payslipSelect.value;
    if(!id)return;

    await runReportDownload(
      payslipButton,
      '/export/pdf/payroll/'+encodeURIComponent(id)
    );
  });

  // Загружаем только подтверждённые расчёты. Изменять их из этого раздела нельзя.
  const loadDirectorPayslips=async()=>{
    try{
      const result=await api('/payroll-runs?status=confirmed&per_page=50');
      const runs=(result.items||[]).filter(run=>run.status==='confirmed');

      payslipSelect.innerHTML=runs.length
        ?'<option value="">Выберите расчёт…</option>'+runs.map(run=>{
          const user=run.user?.full_name||'Исполнитель';
          const period=esc(run.period_from||'—')+' — '+esc(run.period_to||'—');
          const amount=money(run.total_amount);
          return `<option value="${esc(run.id)}">${esc(user)} · ${period} · ${esc(amount)}</option>`;
        }).join('')
        :'<option value="">Нет подтверждённых расчётов</option>';

      payslipSelect.disabled=!runs.length;
      payslipButton.disabled=true;

      if(!runs.length){
        payslipHint.textContent='Подтверждённых расчётов пока нет.';
      }else{
        payslipHint.textContent='Доступно расчётов: '+runs.length+'. Отменённые и черновые расчёты не отображаются.';
      }
    }catch(e){
      payslipSelect.innerHTML='<option value="">Не удалось загрузить расчёты</option>';
      payslipSelect.disabled=true;
      payslipButton.disabled=true;
      payslipHint.textContent='Ошибка загрузки списка расчётов: '+(e.message||'ошибка запроса');
    }
  };

  const cardsHtml=items=>items.map(([label,value])=>
    `<div class="stat-card"><span>${esc(label)}</span><strong>${value}</strong></div>`
  ).join('');

  const errorHtml=e=>`<div class="notice warn">${esc(e.message||'Ошибка загрузки данных')}</div>`;

  const load=async()=>{
    const from=form.elements.date_from.value;
    const to=form.elements.date_to.value;

    filterError.textContent='';

    if(from && to && from>to){
      filterError.textContent='Дата начала периода не может быть позже даты окончания.';
      return;
    }

    const params=new URLSearchParams();
    if(from)params.set('date_from',from);
    if(to)params.set('date_to',to);
    const q=params.toString()?'?'+params.toString():'';

    production.innerHTML='<div class="muted">Загрузка…</div>';
    financePeriod.innerHTML='<div class="muted">Загрузка…</div>';
    financeAll.innerHTML='<div class="muted">Загрузка…</div>';
    financeBalance.innerHTML='<div class="muted">Загрузка…</div>';
    executors.innerHTML='<div class="muted">Загрузка…</div>';
    recent.innerHTML='<div class="muted">Загрузка…</div>';

    const results=await Promise.allSettled([
      api('/stats/summary'+q),
      api('/finance/summary'+q),
      api('/stats/users'+q),
      api('/work-records?per_page=5'+(q?'&'+params.toString():''))
    ]);

    // Производственные показатели
    if(results[0].status==='fulfilled'){
      const n=results[0].value.counts||{};
      production.innerHTML=cardsHtml([
        ['Дверей',n.doors??0],
        ['Операций',n.operations??0],
        ['Ремонтов',n.repairs??0],
        ['Упаковок',n.packings??0],
        ['Дополнительных работ',n.other_works??0]
      ]);
    }else{
      production.innerHTML=errorHtml(results[0].reason);
    }

    // Финансы: период, всё время и текущие остатки
    if(results[1].status==='fulfilled'){
      const finance=results[1].value||{};
      const totals=finance.totals||{};
      const period=finance.period||{};

      financePeriod.innerHTML=cardsHtml([
        ['Начислено за период',money(period.accrued)],
        ['Выплачено за период',money(period.paid)],
        ['Корректировки за период',money(period.adjustments)],
        ['Выдано авансом за период',money(period.advance_issued)],
        ['Зачтено авансов за период',money(period.advance_applied)]
      ]);

      financeAll.innerHTML=cardsHtml([
        ['Начислено за всё время',money(totals.accrued)],
        ['Выплачено за всё время',money(totals.paid)]
      ]);

      financeBalance.innerHTML=cardsHtml([
        ['Задолженность на текущий момент',money(totals.debt_total)],
        ['Переплата на текущий момент',money(totals.overpayment_total)],
        ['Остаток авансов',money(totals.advance_balance)],
        ['Исполнителей с задолженностью',totals.executors_with_debt??0]
      ]);
    }else{
      const error=errorHtml(results[1].reason);
      financePeriod.innerHTML=error;
      financeAll.innerHTML=error;
      financeBalance.innerHTML=error;
    }

    // Исполнители
    if(results[2].status==='fulfilled'){
      const rows=(results[2].value.items||[]).slice().sort((a,b)=>{
        const works=Number(b.works_count||0)-Number(a.works_count||0);
        return works || Number(b.accrued||0)-Number(a.accrued||0);
      });

      executors.innerHTML=rows.length?rows.map((r,index)=>{
        const user=r.user||{};
        const balance=[];
        if(Number(r.debt||0)>0)balance.push('Долг на конец периода: '+money(r.debt));
        if(Number(r.overpayment||0)>0)balance.push('Переплата на конец периода: '+money(r.overpayment));

        return `<article class="record-card">
          <div class="record-main">
            <strong>${index+1}. ${esc(user.full_name||'Исполнитель')}</strong>
            <div class="record-meta">
              <span>${Number(r.works_count||0)} операций</span>
              <span>${Number(r.doors_count||0)} дверей</span>
              <span>Начислено: ${money(r.accrued)}</span>
              <span>Выплачено: ${money(r.paid)}</span>
              ${balance.map(x=>`<span>${esc(x)}</span>`).join('')}
            </div>
          </div>
          <div class="amount">${money(r.accrued)}</div>
        </article>`;
      }).join(''):'<div class="empty">Нет работ за выбранный период</div>';
    }else{
      executors.innerHTML=errorHtml(results[2].reason);
    }

    // Последние работы. Используем существующие карточки и просмотр фото.
    if(results[3].status==='fulfilled'){
      const rows=results[3].value.items||[];
      recent.innerHTML=rows.length
        ?rows.map(recordHtml).join('')
        :'<div class="empty">Нет работ за выбранный период</div>';
      bindWorkCards(recent);
    }else{
      recent.innerHTML=errorHtml(results[3].reason);
    }
  };

  form.addEventListener('submit',e=>{
    e.preventDefault();
    load();
  });

  form.addEventListener('reset',()=>{
    setTimeout(()=>{
      form.elements.date_from.value=defaultFrom;
      form.elements.date_to.value=defaultTo;
      load();
    },0);
  });

  const payslipsLoading=loadDirectorPayslips();
  await load();
  await payslipsLoading;
}

async function renderNew(el){
  if(!state.workTypes.length)await loadWorkTypes();
  if(!state.workTypes.length){el.innerHTML=pageHead('Регистрация работы','Нет доступного справочника видов работ')+'<div class="notice warn">Подключитесь к серверу и обновите страницу. Для офлайн-регистрации справочник нужно загрузить заранее.</div>';return;}
  el.innerHTML=pageHead('Новая работа','Выберите несколько видов работ для одной двери. Фото обязательно.')+`<form id="work-form" class="panel"><div class="form-grid"><div class="field span-2"><label>Виды работ * (можно выбрать несколько)</label><div class="form-grid">${state.workTypes.map(t=>`<label class="checkline"><input type="checkbox" name="work_type_ids[]" value="${esc(t.id)}" > ${esc(t.name)}</label>`).join('')}</div><div class="hint" id="door-requirement">Выберите хотя бы один вид работы.</div></div><div class="field"><label for="quantity">Количество для каждого вида работ</label><input id="quantity" name="quantity" type="number" min="0.001" max="99999.999" step="0.001" value="1" required></div><div class="field"><label for="completed_at_local">Дата и время выполнения *</label><input id="completed_at_local" name="completed_at_local" type="datetime-local" required><div class="hint">По умолчанию — текущее местное время устройства. При необходимости укажите фактическое время выполнения.</div></div><div class="field span-2"><div class="hint" id="door-requirement">Для этой регистрации сервер автоматически создаст дверь и присвоит ей номер.</div></div><div class="field span-2"><label for="comment">Комментарий</label><textarea id="comment" name="comment" maxlength="2000" placeholder="Необязательно"></textarea></div><div class="field span-2"><label>Фото выполненной работы *</label><div class="filebox"><input id="photos" name="photos" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple required><div class="hint">Фотографии будут прикреплены к каждой записи группы. Файлы сохраняются на устройстве до подтверждения сервером.</div><div id="file-list" class="file-list">Файлы не выбраны</div></div></div></div><div class="notice">Для каждого выбранного вида будет создана отдельная запись с собственной расценкой и начислением. Время выполнения будет записано с часов устройства; сервер отдельно зафиксирует время получения.</div><div class="actions"><button class="btn" type="submit">Сохранить группу работ</button><button class="btn ghost" type="reset">Очистить</button></div><div id="form-error" class="error"></div></form>`;
  const form=document.getElementById('work-form'),files=document.getElementById('photos');
  const completedInput=form.elements.completed_at_local;
  const selectedBoxes=()=>Array.from(form.querySelectorAll('input[name="work_type_ids[]"]:checked'));
  completedInput.value=localDateTime(new Date());
  const updateDoorRule=()=>{document.getElementById('door-requirement').textContent=selectedBoxes().length<1?'Выберите хотя бы один вид работы.':'Для выбранных видов работ будет автоматически создана одна дверь с новым номером.';};
  form.querySelectorAll('input[name="work_type_ids[]"]').forEach(x=>x.addEventListener('change',updateDoorRule));
    form.addEventListener('reset',()=>setTimeout(()=>{updateDoorRule();completedInput.value=localDateTime(new Date());document.getElementById('file-list').textContent='Файлы не выбраны';},0));
  files.addEventListener('change',()=>{document.getElementById('file-list').textContent=Array.from(files.files).map(f=>f.name).join(', ')||'Файлы не выбраны';});
  form.addEventListener('submit',async e=>{
    e.preventDefault();const fd=new FormData(form),photos=Array.from(files.files||[]),selected=selectedBoxes(),typeIds=selected.map(x=>String(x.value));
    const error=document.getElementById('form-error');
    if(typeIds.length<1){error.textContent='Выберите хотя бы один вид работы.';return;}
    if(typeIds.length>20){error.textContent='За одну операцию можно выбрать не более 20 видов работ.';return;}
    if(!photos.length){error.textContent='Добавьте хотя бы одну фотографию.';return;}
    if(photos.length>10){error.textContent='За одну операцию можно приложить не более 10 фотографий.';return;}
    const allowedPhotoTypes=['image/jpeg','image/png','image/webp'],allowedPhotoExts=['jpg','jpeg','png','webp'];
    if(photos.some(file=>{const type=String(file.type||'').toLowerCase(),ext=String(file.name||'').split('.').pop().toLowerCase();return !allowedPhotoTypes.includes(type)&&!allowedPhotoExts.includes(ext);})){error.textContent='Допустимы только JPEG, PNG и WebP. Фотографии HEIC/HEIF необходимо предварительно сохранить в JPEG.';return;}
    if(photos.some(file=>file.size>15*1024*1024)){error.textContent='Размер одного фото не должен превышать 15 МБ.';return;}
    if(photos.reduce((sum,file)=>sum+file.size,0)>60*1024*1024){error.textContent='Общий размер фотографий должен быть не более 60 МБ.';return;}
    const completedDate=new Date(String(fd.get('completed_at_local')||''));
    if(!fd.get('completed_at_local')||Number.isNaN(completedDate.getTime())){error.textContent='Укажите корректные дату и время выполнения.';return;}
    const data={quantity:fd.get('quantity')||'1',new_door:'true',comment:String(fd.get('comment')||'').trim(),completed_at:completedDate.toISOString()};
    if(typeIds.length===1)data.work_type_id=typeIds[0];else data.work_type_ids=typeIds;
    const item={id:crypto.randomUUID(),idemKey:crypto.randomUUID(),ownerUserId:Number(state.user.id),createdLocal:new Date().toISOString(),status:'queued',data,photos:photos.map(file=>({blob:file.slice(0,file.size,file.type),name:file.name||'photo.jpg',type:file.type||'application/octet-stream',lastModified:file.lastModified||Date.now()}))};
    const btn=form.querySelector('button[type="submit"]');btn.disabled=true;btn.textContent='Сохранение…';
    try{await queuePut(item);form.reset();document.getElementById('file-list').textContent='Файлы не выбраны';toast(typeIds.length===1?'Работа сохранена в очереди устройства':'Группа работ сохранена в очереди устройства');if(state.online)await syncQueue();go('queue');}
    catch(err){error.textContent='Не удалось сохранить локально: '+err.message;}
    finally{btn.disabled=false;btn.textContent='Сохранить';}
  });
}

async function renderQueue(el){
  const rows=await queueAll().catch(()=>[]);
  const currentRows=rows.filter(q=>Number(q.ownerUserId)===Number(state.user.id));
  const foreignCount=rows.length-currentRows.length;
  const foreignNotice=foreignCount?`<div class="notice warn">${foreignCount} запись(ей) не принадлежат текущей учётной записи или созданы старой версией приложения. Для синхронизации войдите под исходной учётной записью. Содержимое этих записей скрыто.</div>`:'';
  el.innerHTML=pageHead('Офлайн-очередь','Работы хранятся на этом устройстве до подтверждения сервером',state.online?'<button class="btn secondary" id="sync-now">Синхронизировать</button>':'')+foreignNotice+(currentRows.length?`<div class="list">${currentRows.map(q=>`<article class="record-card"><div class="record-main"><strong>${esc((Array.isArray(q.data.work_type_ids)?q.data.work_type_ids.map(typeName).join(', '):typeName(q.data.work_type_id)))} · ${esc(q.data.door_number||'Номер будет присвоен автоматически')}</strong><div class="record-meta"><span>${date(q.data.completed_at)}</span><span>${q.photos.length} фото</span><span class="pill pending">${esc(q.status==='error'?'Ошибка отправки':'Ожидает отправки')}</span></div>${q.error?`<div class="error">${esc(q.error)}</div>`:''}</div><button class="btn ghost" data-remove="${esc(q.id)}" aria-label="Удалить">Удалить</button></article>`).join('')}</div>`:'<div class="empty">Нет ожидающих записей для текущей учётной записи.</div>')+`<div class="notice warn">Не очищайте данные браузера и не удаляйте приложение, пока очередь не синхронизирована. Фото удаляются с устройства только после подтверждения сервером.</div>`;
  el.querySelector('#sync-now')?.addEventListener('click',syncQueue);
  el.querySelectorAll('[data-remove]').forEach(b=>b.addEventListener('click',async()=>{if(confirm('Удалить несинхронизированную запись и её фотографии?')){await queueDelete(b.dataset.remove);renderPage();}}));
}
function typeName(id){return state.workTypes.find(t=>String(t.id)===String(id))?.name||'Вид работы';}
async function syncQueue(){
  if(!navigator.onLine||!state.user||state.user.role!=='master'||!state.serverAuthenticated||state.busy)return;
  state.busy=true;
  try{
    const rows=(await queueAll()).sort((a,b)=>a.createdLocal.localeCompare(b.createdLocal));
    if(rows.some(item=>Number(item.ownerUserId)!==Number(state.user.id))){toast('В очереди есть работы другого пользователя. Войдите под исходной учётной записью для синхронизации.');return;}
    for(const item of rows){
      const body=new FormData();Object.entries(item.data).forEach(([k,v])=>{if(k==='work_type_ids'&&Array.isArray(v)){v.forEach(id=>body.append('work_type_ids[]',String(id)));}else{body.append(k,v);}});
      item.photos.forEach((photo,i)=>{const blob=photo&&photo.blob instanceof Blob?photo.blob:photo;const name=photo&&typeof photo.name==='string'?photo.name:(blob.name||('photo-'+i+'.jpg'));body.append('photos[]',blob,name);});
      try{
        const endpoint=Array.isArray(item.data.work_type_ids)?'/work-records/batch':'/work-records';
        await api(endpoint,{method:'POST',headers:{'Idempotency-Key':item.idemKey},body});
        await queueDelete(item.id);toast('Работа отправлена на сервер');
      }catch(err){
        item.status='error';item.error=err.message;await queuePut(item);
        if(err.status===401){state.user=null;state.csrf='';await metaPut('auth',null);renderLogin('Сессия завершена. Войдите снова — очередь сохранена на устройстве.');break;}
        if(err.status===403){toast('Недостаточно прав для синхронизации. Очередь сохранена.');break;}
        if([413,422,409].includes(err.status))continue;
        break;
      }
    }
  }finally{state.busy=false;if(state.user&&(state.page==='queue'||state.page==='home'))renderPage();}
}
async function renderHistory(el){
  const typeOptions=state.workTypes.map(t=>`<option value="${esc(t.id)}">${esc(t.name)}</option>`).join('');
  el.innerHTML=pageHead('История работ','Поиск и фильтрация зарегистрированных работ')+`<form id="history-filter" class="panel"><div class="form-grid"><div class="field"><label for="history-door">Номер двери</label><input id="history-door" name="door_number" placeholder="D-000123"></div><div class="field"><label for="history-type">Вид работы</label><select id="history-type" name="work_type_id"><option value="">Все виды работ</option>${typeOptions}</select></div><div class="field"><label for="history-from">Дата с</label><input id="history-from" name="date_from" type="date"></div><div class="field"><label for="history-to">Дата по</label><input id="history-to" name="date_to" type="date"></div><div class="field"><label for="history-status">Состояние записи</label><select id="history-status" name="status"><option value="active">Действующие</option><option value="voided">Аннулированные</option><option value="all">Все</option></select></div></div><div class="actions"><button class="btn" type="submit">Применить фильтры</button><button class="btn ghost" type="reset">Сбросить</button></div></form><div id="history-summary" class="hint"></div><div id="history-list" class="list"><div class="muted">Загрузка…</div></div><div id="history-pages" class="actions"></div>`;
  const form=document.getElementById('history-filter');
  const list=document.getElementById('history-list');
  const summary=document.getElementById('history-summary');
  const pages=document.getElementById('history-pages');
  const load=async(page=1)=>{
    const params=new URLSearchParams({page:String(page),per_page:'50'});
    for(const key of ['door_number','work_type_id','date_from','date_to','status']){
      const value=String(form.elements[key].value||'').trim();
      if(value)params.set(key,value);
    }
    list.innerHTML='<div class="muted">Загрузка…</div>';pages.innerHTML='';
    try{
      const data=await api('/work-records?'+params.toString());
      const rows=data.items||[];
      list.innerHTML=rows.length?rows.map(recordHtml).join(''):'<div class="empty">По заданным условиям записи не найдены</div>';
      bindWorkCards(list);
      summary.textContent=`Найдено: ${data.total||0}. Страница ${data.page||page} из ${Math.max(1,Math.ceil((data.total||0)/(data.per_page||50)))}`;
      const current=data.page||page,totalPages=Math.max(1,Math.ceil((data.total||0)/(data.per_page||50)));
      if(current>1)pages.insertAdjacentHTML('beforeend','<button class="btn secondary" id="history-prev" type="button">← Назад</button>');
      if(current<totalPages)pages.insertAdjacentHTML('beforeend','<button class="btn secondary" id="history-next" type="button">Далее →</button>');
      document.getElementById('history-prev')?.addEventListener('click',()=>load(current-1));
      document.getElementById('history-next')?.addEventListener('click',()=>load(current+1));
    }catch(e){list.innerHTML=`<div class="notice warn">${esc(e.message)}</div>`;summary.textContent='';}
  };
  form.addEventListener('submit',e=>{e.preventDefault();load(1);});
  form.addEventListener('reset',()=>setTimeout(()=>load(1),0));
  await load(1);
}
async function renderSettings(el){
  if(state.user.role!=='owner'){el.innerHTML='<div class="notice warn">Недостаточно прав.</div>';return;}
  el.innerHTML=pageHead('Пользователи','Создание и управление учётными записями')+`<div id="settings-content"><div class="muted">Загрузка…</div></div>`;
  const root=el.querySelector('#settings-content');
  const load=async()=>{
    try{
      const data=await api('/users?per_page=200');
      const users=data.items||[];
      root.innerHTML=`<div class="panel"><h3>Создать пользователя</h3><form id="user-create-form"><div class="form-grid"><div class="field"><label>ФИО</label><input name="full_name" required minlength="2" maxlength="190"></div><div class="field"><label>Логин</label><input name="username" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._-]+"></div><div class="field"><label>Пароль (не менее 10 символов)</label><input name="password" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></div><div class="field"><label>Роль</label><select name="role"><option value="master">Мастер</option><option value="director">Директор</option><option value="owner">Администратор</option></select></div></div><button class="btn" type="submit">Создать пользователя</button></form></div><div class="panel"><h3>Список пользователей</h3><div class="list">${users.map(u=>`<article class="record-card"><div class="record-main"><strong>${esc(u.full_name)}</strong><div class="record-meta"><span>@${esc(u.username)}</span><span>${esc(roleLabel(u.role))}</span><span class="pill ${u.is_active?'synced':'pending'}">${u.is_active?'Активен':'Отключён'}</span></div></div>${u.id!==state.user.id?'<button class="btn ghost" type="button" data-user-toggle="'+u.id+'" data-active="'+(u.is_active?'1':'0')+'">'+(u.is_active?'Отключить':'Включить')+'</button>':''}</article>`).join('')||'<div class="empty">Пользователей нет</div>'}</div></div>`;
      root.querySelector('#user-create-form').addEventListener('submit',async e=>{
        e.preventDefault();const form=e.currentTarget,button=form.querySelector('button');button.disabled=true;
        try{const d=new FormData(form);await api('/users',{method:'POST',body:JSON.stringify({full_name:d.get('full_name'),username:d.get('username'),password:d.get('password'),role:d.get('role')})});toast('Пользователь создан');await load();}
        catch(err){toast(err.message);}finally{button.disabled=false;}
      });
      root.querySelectorAll('[data-user-toggle]').forEach(button=>button.addEventListener('click',async()=>{
        const action=button.dataset.active==='1'?'deactivate':'activate';
        if(!confirm((action==='deactivate'?'Отключить':'Включить')+' пользователя?'))return;
        button.disabled=true;
        try{await api('/users/'+button.dataset.userToggle+'/'+action,{method:'POST',body:'{}'});toast('Статус пользователя изменён');await load();}
        catch(err){toast(err.message);button.disabled=false;}
      }));
    }catch(err){root.innerHTML=`<div class="notice warn">${esc(err.message)}</div>`;}
  };
  await load();
}

async function renderWorkManagement(el){
  if(state.user.role!=='director'){el.innerHTML='<div class="notice warn">Недостаточно прав.</div>';return;}
  el.innerHTML=pageHead('Виды работ и тарифы','Управление производственными справочниками и индивидуальными расценками')+`<div id="work-management-content"><div class="muted">Загрузка…</div></div>`;
  const root=el.querySelector('#work-management-content');
  const load=async()=>{
    try{
      const [typesData,ratesData,executorsData]=await Promise.all([api('/work-types'),api('/rates?current=1&per_page=200'),api('/executors')]);
      const types=Array.isArray(typesData)?typesData:(typesData.items||[]);
      const rates=ratesData.items||[];
      const executors=executorsData.items||[];
      const userOptions=executors.map(u=>`<option value="${u.id}">${esc(u.full_name)}</option>`).join('');
      const typeOptions=types.filter(t=>t.is_active).map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('');
      root.innerHTML=`<div class="panel"><h3>Виды работ</h3><form id="type-create-form"><div class="form-grid"><div class="field"><label>Название</label><input name="name" required minlength="2" maxlength="190"></div><div class="field"><label>Категория</label><select name="category"><option value="repair">Ремонт</option><option value="packing">Упаковка</option><option value="other">Дополнительные работы</option></select></div><div class="field"><label>Единица измерения</label><input name="unit" value="шт" required maxlength="40"></div><div class="field"><label>Описание</label><input name="description" maxlength="2000"></div></div><label class="checkline"><input type="checkbox" name="door_required" checked> Требуется привязка к двери</label><label class="checkline"><input type="checkbox" name="allow_multiple_per_door"> Разрешить несколько таких работ на одну дверь</label><button class="btn" type="submit">Создать вид работы</button></form><div class="list" style="margin-top:18px">${types.map(t=>`<article class="record-card"><div class="record-main"><strong>${esc(t.name)}</strong><div class="record-meta"><span>${({repair:'Ремонт',packing:'Упаковка',other:'Дополнительные'})[t.category]||esc(t.category)}</span><span>${esc(t.unit)}</span><span class="pill ${t.is_active?'synced':'pending'}">${t.is_active?'Активен':'Отключён'}</span></div></div><button class="btn ghost" type="button" data-type-toggle="${t.id}" data-active="${t.is_active?'1':'0'}">${t.is_active?'Отключить':'Включить'}</button></article>`).join('')}</div></div><div class="panel"><h3>Индивидуальные расценки</h3><form id="rate-create-form"><div class="form-grid"><div class="field"><label>Исполнитель</label><select name="user_id" required>${userOptions}</select></div><div class="field"><label>Вид работы</label><select name="work_type_id" required>${typeOptions}</select></div><div class="field"><label>Цена за единицу, ₽</label><input type="number" name="unit_price" min="0" max="9999999.99" step="0.01" required></div></div><button class="btn" type="submit">Назначить расценку</button><div class="hint">Новая расценка закрывает предыдущую и начинает действовать с момента назначения.</div></form><div class="list" style="margin-top:18px">${rates.length?rates.map(rate=>`<article class="record-card"><div class="record-main"><strong>${esc(rate.user_name||'Исполнитель')} · ${esc(rate.work_type_name||'Вид работы')}</strong><div class="record-meta"><span>${money(rate.unit_price)} / единицу</span><span>С: ${date(rate.valid_from)}</span></div></div>${rate.valid_to===null?'<button class="btn ghost" type="button" data-rate-close="'+rate.id+'">Закрыть</button>':''}</article>`).join(''):'<div class="empty">Действующих расценок нет</div>'}</div></div>`;
      root.querySelector('#type-create-form').addEventListener('submit',async e=>{
        e.preventDefault();const form=e.currentTarget,button=form.querySelector('button');button.disabled=true;
        try{const d=new FormData(form);await api('/work-types',{method:'POST',body:JSON.stringify({name:d.get('name'),category:d.get('category'),unit:d.get('unit'),description:d.get('description'),door_required:d.has('door_required'),allow_multiple_per_door:d.has('allow_multiple_per_door')})});toast('Вид работы создан');await load();}
        catch(err){toast(err.message);}finally{button.disabled=false;}
      });
      root.querySelector('#rate-create-form').addEventListener('submit',async e=>{
        e.preventDefault();const form=e.currentTarget,button=form.querySelector('button');button.disabled=true;
        try{const d=new FormData(form);await api('/rates',{method:'POST',body:JSON.stringify({user_id:d.get('user_id'),work_type_id:d.get('work_type_id'),unit_price:d.get('unit_price')})});toast('Расценка назначена');await load();}
        catch(err){toast(err.message);}finally{button.disabled=false;}
      });
      root.querySelectorAll('[data-type-toggle]').forEach(button=>button.addEventListener('click',async()=>{
        const action=button.dataset.active==='1'?'deactivate':'activate';button.disabled=true;
        try{await api('/work-types/'+button.dataset.typeToggle+'/'+action,{method:'POST',body:'{}'});toast('Статус вида работы изменён');await load();}
        catch(err){toast(err.message);button.disabled=false;}
      }));
      root.querySelectorAll('[data-rate-close]').forEach(button=>button.addEventListener('click',async()=>{
        if(!confirm('Закрыть действие этой расценки?'))return;button.disabled=true;
        try{await api('/rates/'+button.dataset.rateClose+'/close',{method:'POST',body:'{}'});toast('Расценка закрыта');await load();}
        catch(err){toast(err.message);button.disabled=false;}
      }));
    }catch(err){root.innerHTML=`<div class="notice warn">${esc(err.message)}</div>`;}
  };
  await load();
}
async function renderStats(el){
  el.innerHTML=pageHead('Статистика','Показатели производства и начислений')+`<form id="stats-filter" class="panel"><div class="form-grid"><div class="field"><label>Дата с</label><input type="date" name="date_from"></div><div class="field"><label>Дата по</label><input type="date" name="date_to"></div></div><div class="actions"><button class="btn" type="submit">Применить</button><button class="btn ghost" type="reset">Сбросить</button></div></form><div id="stats-cards" class="cards"></div><div class="panel"><h3>По видам работ</h3><div id="stats-types" class="list"><div class="muted">Загрузка…</div></div></div><div class="panel"><h3>По дням</h3><div id="stats-days" class="list"><div class="muted">Загрузка…</div></div></div>`;
  const form=document.getElementById('stats-filter');
  const cards=document.getElementById('stats-cards'),types=document.getElementById('stats-types'),days=document.getElementById('stats-days');
  const load=async()=>{
    const params=new URLSearchParams();for(const k of ['date_from','date_to'])if(form.elements[k].value)params.set(k,form.elements[k].value);
    const q=params.toString()?'?'+params.toString():'';
    cards.innerHTML='';types.innerHTML='<div class="muted">Загрузка…</div>';days.innerHTML='<div class="muted">Загрузка…</div>';
    try{
      const [summary,typeData,daily]=await Promise.all([api('/stats/summary'+q),api('/stats/work-types'+q),api('/stats/daily'+q)]);
      const n=summary.counts||{},m=summary.money||{};
      const statCards=[['Дверей',n.doors],['Операций',n.operations],['Ремонтов',n.repairs],['Упаковок',n.packings],['Дополнительных работ',n.other_works],['Начислено',money(m.accrued)],['Выплачено',m.paid===null?'—':money(m.paid)],['Задолженность',m.debt===null?'—':money(m.debt)]];
      cards.innerHTML=(state.user.role==='owner'?statCards.slice(0,5):statCards).map(([label,value])=>`<div class="stat-card"><span>${label}</span><strong>${value??0}</strong></div>`).join('');
      const rows=typeData.items||[];
      types.innerHTML=rows.length?rows.map(t=>`<article class="record-card"><div class="record-main"><strong>${esc(t.work_type.name)}</strong><div class="record-meta"><span>${t.works_count} операций</span><span>${t.doors_count} дверей</span><span>${esc(t.quantity)} ${esc(t.work_type.unit)}</span></div></div>${state.user.role==='owner'?'':'<div class="amount">'+money(t.amount)+'</div>'}</article>`).join(''):'<div class="empty">Нет данных за выбранный период</div>';
      const dailyRows=daily.items||[];
      days.innerHTML=dailyRows.length?dailyRows.slice().reverse().map(d=>`<article class="record-card"><div class="record-main"><strong>${esc(d.date)}</strong><div class="record-meta"><span>${d.works_count} операций</span><span>${d.doors_count} дверей</span><span>Ремонт: ${d.repairs}</span><span>Упаковка: ${d.packings}</span><span>Доп.: ${d.other_works}</span></div></div>${state.user.role==='owner'?'':'<div class="amount">'+money(d.amount)+'</div>'}</article>`).join(''):'<div class="empty">Нет данных по дням</div>';
    }catch(e){cards.innerHTML='';types.innerHTML=`<div class="notice warn">${esc(e.message)}</div>`;days.innerHTML='';}
  };
  form.addEventListener('submit',e=>{e.preventDefault();load();});
  form.addEventListener('reset',()=>setTimeout(load,0));
  await load();
}
function renderDoors(el){
  el.innerHTML=pageHead('Двери','Поиск двери по номеру и просмотр истории работ')+`<form id="door-lookup" class="panel"><div class="form-grid"><div class="field"><label for="door-lookup-number">Номер двери</label><input id="door-lookup-number" name="number" placeholder="D-000123" pattern="D-[0-9]{6,12}" required></div></div><button class="btn" type="submit">Найти дверь</button></form><div id="door-result"></div>`;
  const form=document.getElementById('door-lookup');
  const result=document.getElementById('door-result');
  form.addEventListener('submit',async e=>{
    e.preventDefault();const number=String(new FormData(form).get('number')||'').trim().toUpperCase();
    if(!/^D-\d{6,12}$/.test(number)){result.innerHTML='<div class="notice warn">Номер должен иметь формат D-000123.</div>';return;}
    result.innerHTML='<div class="muted">Поиск…</div>';
    try{
      const door=await api('/doors/number/'+encodeURIComponent(number));
      const history=door.history||[];
      const types=door.registered_work_types||[];
      result.innerHTML=`<div class="panel"><div class="page-head"><div><h2>${esc(door.number)}</h2><p>Создана: ${date(door.created_at)}</p></div><span class="pill">${door.status==='active'?'Активна':esc(door.status||'Дверь')}</span></div><h3>Виды работ</h3><div class="record-meta">${(history.length?[...new Map(history.map(r=>[r.work_type.id,r.work_type.name])).entries()].map(x=>x[1]):types.map(t=>t.name)).map(n=>`<span class="pill">${esc(n)}</span>`).join('')||'Нет зарегистрированных работ'}</div></div>${door.history!==undefined?`<div class="panel"><h3>История работ</h3><div class="list">${history.length?history.map(recordHtml).join(''):'<div class="empty">История работ пуста</div>'}</div></div>`:''}`;
      bindWorkCards(result);
    }catch(err){result.innerHTML=`<div class="notice warn">${esc(err.message)}</div>`;}
  });
}
async function renderFinance(el){
  const isAll=state.user.role==='director';
  const isMaster=state.user.role==='master';
  const canManage=state.user.role==='director';
  let paymentIdemKey=crypto.randomUUID();
  let paymentPayloadSignature=null;
  el.innerHTML=pageHead('Финансы',isAll?'Начисления, выплаты и задолженность':'Ваши начисления и выплаты')+`<form id="finance-filter" class="panel"><div class="form-grid"><div class="field"><label for="finance-from">Период с</label><input id="finance-from" name="date_from" type="date"></div><div class="field"><label for="finance-to">Период по</label><input id="finance-to" name="date_to" type="date"></div></div><div class="actions"><button class="btn" type="submit">Применить</button><button class="btn ghost" type="reset">Сбросить</button></div></form><div id="finance-summary" class="cards"></div><div id="finance-balances"></div><div id="report-panel"></div><div id="finance-admin"></div><div class="panel"><h3>Начисления за выбранный период</h3><div id="accrual-list" class="list"><div class="muted">Загрузка…</div></div><div id="accrual-pages" class="actions"></div></div><div class="panel"><h3>Выплаты</h3><div id="payment-list" class="list"><div class="muted">Загрузка…</div></div></div>`;
  const form=document.getElementById('finance-filter');

  // Текущая неделя: понедельник — воскресенье, по локальному времени.
  const setCurrentWeek=()=>{
    const today=new Date();
    const monday=new Date(
      today.getFullYear(),
      today.getMonth(),
      today.getDate()-((today.getDay()+6)%7)
    );
    const sunday=new Date(monday);
    sunday.setDate(monday.getDate()+6);

    const formatDate=d=>[
      d.getFullYear(),
      String(d.getMonth()+1).padStart(2,'0'),
      String(d.getDate()).padStart(2,'0')
    ].join('-');

    form.elements.date_from.value=formatDate(monday);
    form.elements.date_to.value=formatDate(sunday);
  };

  setCurrentWeek();
  const summary=document.getElementById('finance-summary');
  const balancesPanel=document.getElementById('finance-balances');
  const list=document.getElementById('accrual-list');
  const pages=document.getElementById('accrual-pages');
  const paymentList=document.getElementById('payment-list');
  const admin=document.getElementById('finance-admin');
  const reportPanel=document.getElementById('report-panel');
  if(isAll || isMaster){
    reportPanel.innerHTML=isAll?`<div class="panel"><h3>Отчёты и экспорт</h3><div class="actions"><button class="btn secondary" data-pdf="production">PDF: производство</button><button class="btn secondary" data-pdf="payments">PDF: выплаты</button><button class="btn secondary" data-pdf="finance">PDF: финансы</button><button class="btn secondary" data-pdf="photos">PDF: фотофиксация</button></div><div class="form-grid" style="margin-top:14px"><div class="field"><label for="export-dataset">Набор данных</label><select id="export-dataset"><option value="works">Выполненные работы</option><option value="payments">Выплаты</option><option value="balances">Финансовые остатки</option><option value="stats-users">Статистика по исполнителям</option><option value="stats-types">Статистика по видам работ</option><option value="stats-daily">Статистика по дням</option><option value="stats-doors">Статистика по дверям</option></select></div><div class="field"><label>Формат</label><div class="actions"><button class="btn" id="export-csv" type="button">CSV</button><button class="btn" id="export-xlsx" type="button">XLSX</button></div></div></div><div class="hint">Отчёты используют даты из фильтра выше. Файлы формируются сервером с учётом прав доступа.</div></div>`:`<div class="panel"><h3>Мои отчёты</h3><div class="actions"><button class="btn secondary" data-pdf="production">PDF: мои работы</button><button class="btn secondary" data-pdf="payments">PDF: мои выплаты</button><button class="btn secondary" data-pdf="finance">PDF: мои финансы</button><button class="btn secondary" data-pdf="photos">PDF: мои фото</button></div><div class="form-grid" style="margin-top:14px"><div class="field"><label for="export-dataset">Набор данных</label><select id="export-dataset"><option value="works">Мои работы</option><option value="payments">Мои выплаты</option><option value="balances">Мой баланс</option><option value="stats-types">Мои работы по видам</option><option value="stats-daily">Мои работы по дням</option><option value="stats-users">Моя статистика</option></select></div><div class="field"><label>Формат</label><div class="actions"><button class="btn" id="export-csv" type="button">CSV</button><button class="btn" id="export-xlsx" type="button">XLSX</button></div></div></div><div class="hint">В отчёты включаются только ваши работы, начисления и выплаты.</div></div>`;
    const reportParams=()=>{const p=new URLSearchParams();for(const k of ['date_from','date_to'])if(form.elements[k].value)p.set(k,form.elements[k].value);return p.toString();};
    reportPanel.querySelectorAll('[data-pdf]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;try{const q=reportParams();await downloadFile('/export/pdf/'+button.dataset.pdf+(q?'?'+q:''));}catch(e){toast(e.message);}finally{button.disabled=false;}}));
    const exportData=async format=>{const q=new URLSearchParams({dataset:document.getElementById('export-dataset').value});for(const k of ['date_from','date_to'])if(form.elements[k].value)q.set(k,form.elements[k].value);const button=document.getElementById(format==='csv'?'export-csv':'export-xlsx');button.disabled=true;try{await downloadFile('/export/'+format+'?'+q.toString());}catch(e){toast(e.message);}finally{button.disabled=false;}};
    document.getElementById('export-csv').addEventListener('click',()=>exportData('csv'));
    document.getElementById('export-xlsx').addEventListener('click',()=>exportData('xlsx'));
  }
  const load=async(page=1)=>{
    const params=new URLSearchParams({page:String(page),per_page:'50',payment_state:'all',status:'active'});
    for(const key of ['date_from','date_to']){const value=form.elements[key].value;if(value)params.set(key,value);}
    summary.innerHTML='';list.innerHTML='<div class="muted">Загрузка…</div>';pages.innerHTML='';
    try{
      const tasks=[api('/finance/accruals?'+params.toString()),api('/payments?'+params.toString())];
      if(isAll)tasks.push(api('/finance/summary?'+params.toString()));
      else tasks.push(api('/finance/users/'+encodeURIComponent(state.user.id)+'?'+params.toString()));
      const [accruals,payments,finance]=await Promise.all(tasks);
      const totals=finance.totals;
      const financeCards=[
        ['Начислено за всё время',totals.accrued],
        ['Выплачено за всё время',totals.paid],
        ['Задолженность',totals.debt??totals.debt_total],
        ['Переплата',totals.overpayment??totals.overpayment_total],
        ['Выдано авансом',totals.advance_issued],
        ['Зачтено в работы',totals.advance_applied],
        ['Остаток аванса',totals.advance_balance]
      ];
      if(!isAll && totals.unpaid_accruals_total!==undefined){
        financeCards.push(['Неоплаченные начисления',totals.unpaid_accruals_total]);
      }
      summary.innerHTML=financeCards
        .map(([label,value])=>`<div class="stat-card"><span>${label}</span><strong>${money(value)}</strong></div>`)
        .join('');

      balancesPanel.innerHTML='';
      if(isAll){
        try{
          const balanceParams=new URLSearchParams();
          for(const key of ['date_from','date_to']){
            const value=form.elements[key].value;
            if(value)balanceParams.set(key,value);
          }
          const balances=await api('/finance/balances'+(balanceParams.toString()?'?'+balanceParams.toString():''));
          const balanceRows=balances.items||[];
          balancesPanel.innerHTML=`<div class="panel">
            <h3>Финансовые остатки по исполнителям</h3>
            <div class="list">${balanceRows.length?balanceRows.map(row=>{
              const user=row.user||{};
              return `<article class="record-card">
                <div class="record-main">
                  <strong>${esc(user.full_name||'Исполнитель')}</strong>
                  <div class="record-meta">
                    <span>${esc(roleLabel(user.role||''))}</span>
                    <span class="pill ${Number(row.advance_balance||0)>0?'pending':'synced'}">Аванс: ${money(row.advance_balance)}</span>
                  </div>
                  <div class="hint">
                    Начислено: ${money(row.accrued)} · Выплачено: ${money(row.paid)}
                    <br>Долг: ${money(row.debt)} · Переплата: ${money(row.overpayment)}
                    <br>Выдано авансом: ${money(row.advance_issued)}
                    · Зачтено: ${money(row.advance_applied)}
                  </div>
                </div>
              </article>`;
            }).join(''):'<div class="empty">Финансовых данных пока нет</div>'}</div>
          </div>`;
        }catch(balanceError){
          balancesPanel.innerHTML=`<div class="notice warn">Не удалось загрузить остатки по исполнителям: ${esc(balanceError.message)}</div>`;
        }
      }
      const rows=accruals.items||[];
      list.innerHTML=rows.length?rows.map(a=>`<article class="record-card"><div class="record-main"><strong>${esc(a.work_type?.name||'Работа')} · ${esc(a.door_number||'Без номера')}</strong><div class="record-meta"><span>${date(a.completed_at)}</span><span>${esc(a.user?.full_name||'')}</span><span>${esc(a.quantity)} ${esc(a.unit)}</span><span class="pill ${a.payment_state==='paid'?'synced':'pending'}">${({unpaid:'Не выплачено',partial:'Частично',paid:'Выплачено',voided:'Аннулировано'})[a.payment_state]||a.payment_state}</span></div><div class="hint">Начислено: ${money(a.total_amount)} · Выплачено: ${money(a.paid)} · Остаток: ${money(a.outstanding)}</div></div></article>`).join(''):'<div class="empty">Начислений за выбранный период нет</div>';
      const paymentRows=payments.items||[];
      paymentList.innerHTML=paymentRows.length?paymentRows.map(p=>`<article class="record-card"><div class="record-main"><strong>${esc(p.user?.full_name||'Получатель')} · ${money(p.amount)}</strong><div class="record-meta"><span>${date(p.payment_date)}</span><span>${esc(p.payment_method)}</span><span class="pill ${p.status==='posted'?'synced':'pending'}">${({draft:'Черновик',posted:'Подтверждена',cancelled:'Отменена'})[p.status]||p.status}</span></div>${p.reference?`<div class="hint">Операция: ${esc(p.reference)}</div>`:''}</div>${canManage&&p.status==='draft'?'<button class="btn" type="button" data-post-payment="'+p.id+'">Подтвердить</button>':''}</article>`).join(''):'<div class="empty">Выплат за выбранный период нет</div>';
      paymentList.querySelectorAll('[data-post-payment]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;try{await api('/payments/'+button.dataset.postPayment+'/post',{method:'POST',body:'{}'});toast('Выплата подтверждена');await load(page);}catch(e){toast(e.message);button.disabled=false;}}));
      const current=accruals.page||page,totalPages=Math.max(1,Math.ceil((accruals.total||0)/(accruals.per_page||50)));
      if(current>1)pages.insertAdjacentHTML('beforeend','<button class="btn secondary" id="accrual-prev" type="button">← Назад</button>');
      if(current<totalPages)pages.insertAdjacentHTML('beforeend','<button class="btn secondary" id="accrual-next" type="button">Далее →</button>');
      document.getElementById('accrual-prev')?.addEventListener('click',()=>load(current-1));
      document.getElementById('accrual-next')?.addEventListener('click',()=>load(current+1));
    }catch(e){list.innerHTML=`<div class="notice warn">${esc(e.message)}</div>`;summary.innerHTML='';}
  };
  const loadAdmin=async()=>{
    if(!canManage)return;
    try{
      const [executorsData,runs]=await Promise.all([
        api('/executors'),
        api('/payroll-runs?per_page=50')
      ]);
      const users=executorsData.items||[];
      const options=users.map(u=>`<option value="${u.id}">${esc(u.full_name)} · ${esc(roleLabel(u.role))}</option>`).join('');
      admin.innerHTML=`<div class="panel"><h3>Сформировать расчёт за период</h3><form id="payroll-create-form"><div class="form-grid"><div class="field"><label>Исполнитель</label><select name="user_id" required>${options}</select></div><div class="field"><label>Период с</label><input type="date" name="period_from" required></div><div class="field"><label>Период по</label><input type="date" name="period_to" required></div></div><button class="btn" type="submit">Сформировать черновик</button><div class="hint">В расчёт попадут действующие работы исполнителя, ещё не включённые в другой расчёт.</div></form></div><div class="panel"><h3>Создать выплату</h3><form id="payment-create-form"><div class="form-grid"><div class="field"><label>Получатель</label><select name="user_id" required>${options}</select></div><div class="field"><label>Сумма, ₽</label><input type="number" name="amount" min="0.01" step="0.01" required></div><div class="field"><label>Способ выплаты</label><select name="payment_method" required><option value="Банковский перевод">Банковский перевод</option><option value="Наличные">Наличные</option><option value="Карта">Карта</option></select></div><div class="field"><label>Дата выплаты</label><input type="datetime-local" name="payment_date"></div><div class="field span-2"><label>Номер операции / комментарий</label><input name="reference" maxlength="190" placeholder="Необязательно"></div></div><button class="btn" type="submit">Создать черновик выплаты</button><div class="hint">При подтверждении выплата автоматически распределяется по самым ранним неоплаченным начислениям. Если сумма больше задолженности, нераспределённый остаток будет зачислен сотруднику как аванс. Если неоплаченных начислений нет, вся сумма станет авансом. До подтверждения черновик не изменяет финансовый баланс.</div></form></div><div class="panel"><h3>Расчёты</h3><div id="payroll-run-list" class="list">${(runs.items||[]).length?(runs.items||[]).map(run=>`<article class="record-card"><div class="record-main"><strong>${esc(run.user?.full_name||'Исполнитель')} · ${money(run.total_amount)}</strong><div class="record-meta"><span>${esc(run.period_from)} — ${esc(run.period_to)}</span><span class="pill ${run.status==='confirmed'?'synced':'pending'}">${({draft:'Черновик',confirmed:'Подтверждён',cancelled:'Отменён'})[run.status]||run.status}</span></div></div>${run.status==='draft'?'<button class="btn" type="button" data-confirm-run="'+run.id+'">Подтвердить</button>':''}</article>`).join(''):'<div class="empty">Расчётов пока нет</div>'}</div></div>`;
      document.getElementById('payroll-create-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;const b=f.querySelector('button');b.disabled=true;try{const d=new FormData(f);await api('/payroll-runs',{method:'POST',body:JSON.stringify({user_id:d.get('user_id'),period_from:d.get('period_from'),period_to:d.get('period_to')})});toast('Черновик расчёта создан');await loadAdmin();}catch(err){toast(err.message);}finally{b.disabled=false;}});
      document.getElementById('payment-create-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;const b=f.querySelector('button');b.disabled=true;try{const d=new FormData(f);const payload={user_id:d.get('user_id'),amount:d.get('amount'),payment_method:d.get('payment_method'),reference:d.get('reference'),post:false};const dateValue=d.get('payment_date');if(dateValue)payload.payment_date=new Date(dateValue).toISOString();const signature=JSON.stringify(payload);if(paymentPayloadSignature!==signature){paymentIdemKey=crypto.randomUUID();paymentPayloadSignature=signature;}await api('/payments',{method:'POST',headers:{'Idempotency-Key':paymentIdemKey},body:JSON.stringify(payload)});paymentIdemKey=crypto.randomUUID();paymentPayloadSignature=null;f.reset();toast('Черновик выплаты создан');await load(1);}catch(err){toast(err.message);}finally{b.disabled=false;}});
      document.querySelectorAll('[data-confirm-run]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;try{await api('/payroll-runs/'+button.dataset.confirmRun+'/confirm',{method:'POST',body:'{}'});toast('Расчёт подтверждён');await loadAdmin();}catch(e){toast(e.message);button.disabled=false;}}));
    }catch(e){admin.innerHTML=`<div class="notice warn">Не удалось загрузить инструменты расчётов и выплат: ${esc(e.message)}</div>`;}
  };
  form.addEventListener('submit',e=>{e.preventDefault();load(1);});
  form.addEventListener('reset',()=>setTimeout(()=>{
    setCurrentWeek();
    load(1);
  },0));
  await Promise.all([load(1),loadAdmin()]);
}

function recordHtml(r){
  const count=Number(r.photos_count||0);
  const title=(r.work_type?.name||'Работа')+' · '+(r.door?.number||'Без номера');
  const groupLabel=r.group_id?' · Группа №'+r.group_id:'';
  return `<article class="record-card work-record-card" data-work-record="${esc(r.id)}" tabindex="0" role="button" aria-label="Открыть работу ${esc(title)}">
    <div class="record-main"><strong>${esc(title)}</strong><div class="record-meta"><span>Выполнено: ${date(r.completed_at)}</span><span>${esc(r.user?.full_name||'')}</span><span>${count} фото</span>${groupLabel?`<span>${groupLabel.trim()}</span>`:''}<span class="pill ${r.status==='voided'?'pending':'synced'}">${r.status==='voided'?'Аннулирована':'Действует'}</span></div><div class="hint">${r.completed_at_source==='device'?'Время выполнения с устройства':'Время выполнения по серверу'} · Получено сервером: ${date(r.received_at)}</div></div>
    <div class="work-card-trailing"><div class="amount">${money(r.total_amount)}</div><span class="work-card-open">Подробнее →</span></div>
  </article>`;
}
function bindWorkCards(container){
  if(!document.getElementById('work-card-interaction-style')){
    const style=document.createElement('style');style.id='work-card-interaction-style';
    style.textContent='.work-record-card{cursor:pointer;transition:border-color .16s,transform .16s,box-shadow .16s}.work-record-card:hover,.work-record-card:focus-visible{border-color:var(--blue,#55b9e9);outline:none;transform:translateY(-1px);box-shadow:0 8px 24px #0002}.work-card-trailing{display:grid;justify-items:end;gap:8px;flex:0 0 auto}.work-card-open{font-size:12px;color:var(--blue,#55b9e9);font-weight:650}';
    document.head.append(style);
  }
  if(!container||container.dataset.workCardsBound==='1')return;
  container.dataset.workCardsBound='1';
  container.addEventListener('click',e=>{
    const card=e.target.closest('[data-work-record]');
    if(!card||!container.contains(card)||e.target.closest('button,a,input,select,textarea'))return;
    openWorkModal(card.dataset.workRecord);
  });
  container.addEventListener('keydown',e=>{
    if(e.key!=='Enter'&&e.key!==' ')return;
    const card=e.target.closest('[data-work-record]');
    if(!card||!container.contains(card))return;
    e.preventDefault();openWorkModal(card.dataset.workRecord);
  });
}

function openPhotoViewer(photos, startIndex=0){
  if(!Array.isArray(photos)||!photos.length)return;

  const items=photos.map(photo=>({
    src:photo.original_url||photo.url,
    alt:'Фото выполненной работы'
  }));

  let index=Math.max(0,Math.min(Number(startIndex)||0,items.length-1));
  const previousOverflow=document.body.style.overflow;

  if(!document.getElementById('work-photo-viewer-styles')){
    const style=document.createElement('style');
    style.id='work-photo-viewer-styles';
    style.textContent=`
      .work-photo-viewer{position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.94);display:flex;align-items:center;justify-content:center;padding:65px 70px 75px}
      .work-photo-viewer img{display:block;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;user-select:none}
      .work-photo-viewer-close{position:absolute;right:22px;top:15px;width:46px;height:46px;border:0;border-radius:50%;background:#ffffff20;color:white;font-size:32px;cursor:pointer}
      .work-photo-viewer-nav{position:absolute;top:50%;transform:translateY(-50%);width:48px;height:64px;border:0;border-radius:12px;background:#ffffff20;color:white;font-size:32px;cursor:pointer}
      .work-photo-viewer-nav:disabled{opacity:.25;cursor:default}
      .work-photo-viewer-prev{left:15px}
      .work-photo-viewer-next{right:15px}
      .work-photo-viewer-counter{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:white;background:#0008;padding:8px 15px;border-radius:20px;font-size:13px}
      .work-photo-thumb{display:block;width:100%;padding:0;border:1px solid var(--line,#2b3d59);border-radius:10px;overflow:hidden;background:var(--bg,#101a2d);cursor:zoom-in;aspect-ratio:4/3}
      .work-photo-thumb img{width:100%;height:100%;object-fit:cover;display:block}
      .work-photo-thumb:hover{border-color:var(--blue,#55b9e9)}
      @media(max-width:600px){
        .work-photo-viewer{padding:65px 12px 75px}
        .work-photo-viewer-nav{width:40px;height:52px;font-size:26px;background:#0009}
        .work-photo-viewer-prev{left:5px}
        .work-photo-viewer-next{right:5px}
        .work-photo-viewer-close{right:10px;top:10px}
      }
    `;
    document.head.append(style);
  }

  const root=document.createElement('div');
  root.className='work-photo-viewer';
  root.setAttribute('role','dialog');
  root.setAttribute('aria-modal','true');
  root.setAttribute('aria-label','Просмотр фотографий');
  root.innerHTML=`
    <button type="button" class="work-photo-viewer-close" aria-label="Закрыть">×</button>
    <button type="button" class="work-photo-viewer-nav work-photo-viewer-prev" aria-label="Предыдущее фото">‹</button>
    <img alt="Фото выполненной работы">
    <button type="button" class="work-photo-viewer-nav work-photo-viewer-next" aria-label="Следующее фото">›</button>
    <div class="work-photo-viewer-counter"></div>
  `;

  document.body.append(root);
  document.body.style.overflow='hidden';

  const image=root.querySelector('img');
  const prev=root.querySelector('.work-photo-viewer-prev');
  const next=root.querySelector('.work-photo-viewer-next');
  const counter=root.querySelector('.work-photo-viewer-counter');

  function close(){
    document.removeEventListener('keydown',onKey);
    root.remove();
    document.body.style.overflow=previousOverflow;
  }

  function show(){
    const item=items[index];
    image.src=item.src||'';
    image.alt=item.alt;
    counter.textContent=(index+1)+' / '+items.length;
    prev.disabled=index===0;
    next.disabled=index===items.length-1;
  }

  function onKey(e){
    if(e.key==='Escape'){
      e.preventDefault();
      e.stopPropagation();
      close();
    }else if(e.key==='ArrowLeft'){
      e.preventDefault();
      e.stopPropagation();
      if(index>0){index--;show();}
    }else if(e.key==='ArrowRight'){
      e.preventDefault();
      e.stopPropagation();
      if(index<items.length-1){index++;show();}
    }
  }

  root.querySelector('.work-photo-viewer-close').addEventListener('click',close);
  prev.addEventListener('click',()=>{if(index>0){index--;show();}});
  next.addEventListener('click',()=>{if(index<items.length-1){index++;show();}});
  root.addEventListener('click',e=>{if(e.target===root)close();});

  document.addEventListener('keydown',onKey);
  show();
  root.querySelector('.work-photo-viewer-close').focus();
}

async function openWorkModal(id){
  if(document.getElementById('work-record-modal'))return;
  if(!document.getElementById('work-modal-styles')){
    const style=document.createElement('style');style.id='work-modal-styles';
    style.textContent=`
      .work-record-card{cursor:pointer;transition:border-color .16s,transform .16s,box-shadow .16s}
      .work-record-card:hover,.work-record-card:focus-visible{border-color:var(--blue,#55b9e9);outline:none;transform:translateY(-1px);box-shadow:0 8px 24px #0002}
      .work-card-trailing{display:grid;justify-items:end;gap:8px;flex:0 0 auto}
      .work-card-open{font-size:12px;color:var(--blue,#55b9e9);font-weight:650}
      .work-modal-backdrop{position:fixed;inset:0;z-index:1000;background:#07111dcc;display:grid;place-items:center;padding:20px;overflow:auto}
      .work-modal{width:min(760px,100%);max-height:min(88vh,900px);overflow:auto;background:var(--surface,#17243a);color:var(--text,#dce7f5);border:1px solid var(--line,#2b3d59);border-radius:18px;box-shadow:0 24px 80px #0008}
      .work-modal-head{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 22px;background:var(--surface,#17243a);border-bottom:1px solid var(--line,#2b3d59)}
      .work-modal-head h2{margin:0;font-size:19px;line-height:1.35}
      .work-modal-sub{margin-top:5px;color:var(--muted,#8498b5);font-size:12px}
      .work-modal-close{border:0;background:transparent;color:inherit;font-size:27px;line-height:1;padding:0 4px;cursor:pointer}
      .work-modal-body{padding:20px 22px}
      .work-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
      .work-modal-field{min-width:0;padding:13px 14px;background:var(--bg,#101a2d);border:1px solid var(--line,#2b3d59);border-radius:11px}
      .work-modal-field span{display:block;color:var(--muted,#8498b5);font-size:11px;margin-bottom:6px}
      .work-modal-field strong{display:block;font-size:14px;overflow-wrap:anywhere;font-weight:650}
      .work-modal-section{margin-top:20px}
      .work-modal-section h3{font-size:14px;margin:0 0 10px}
      .work-modal-comment{white-space:pre-wrap;overflow-wrap:anywhere;padding:13px 14px;background:var(--bg,#101a2d);border:1px solid var(--line,#2b3d59);border-radius:11px;font-size:13px}
      .work-modal-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}
      .work-modal-photos a{display:block;aspect-ratio:4/3;border:1px solid var(--line,#2b3d59);border-radius:10px;overflow:hidden;background:var(--bg,#101a2d)}
      .work-modal-photos img{width:100%;height:100%;object-fit:cover;display:block}
      .work-modal-loading{padding:38px 20px;text-align:center;color:var(--muted,#8498b5)}
      @media(max-width:560px){.work-modal-backdrop{padding:8px;align-items:end}.work-modal{max-height:94vh;border-radius:16px}.work-modal-head,.work-modal-body{padding:16px}.work-modal-grid{grid-template-columns:1fr 1fr}.work-modal-field{padding:11px}.work-modal-field strong{font-size:13px}.work-card-open{display:none}}
      @media(max-width:360px){.work-modal-grid{grid-template-columns:1fr}}
    `;
    document.head.append(style);
  }
  const previousOverflow=document.body.style.overflow;
  const root=document.createElement('div');root.id='work-record-modal';root.className='work-modal-backdrop';
  root.innerHTML='<section class="work-modal" role="dialog" aria-modal="true" aria-labelledby="work-modal-title"><div class="work-modal-loading">Загрузка карточки работы…</div></section>';
  document.body.append(root);document.body.style.overflow='hidden';
  const close=()=>{document.removeEventListener('keydown',onKey);root.remove();document.body.style.overflow=previousOverflow;};
  const onKey=e=>{if(e.key==='Escape')close();};
  document.addEventListener('keydown',onKey);
  root.addEventListener('click',e=>{if(e.target===root||e.target.closest('[data-modal-close]'))close();});
  try{
    const r=await api('/work-records/'+encodeURIComponent(id));
    const photos=Array.isArray(r.photos)?r.photos:[];
    const status=r.status==='voided'?'Аннулирована':'Действует';
    const fields=[
      ['Исполнитель',r.user?.full_name||'—'],
      ['Дверь',r.door?.number||'Без номера'],
      ['Дата выполнения',date(r.completed_at)],
      ['Получено сервером',date(r.received_at)],
      ['Количество',String(r.quantity??'—')+' '+String(r.unit||'')],
      ['Цена за единицу',money(r.unit_price)],
      ['Начислено',money(r.total_amount)],
      ['Статус',status],
      ['Создал запись',r.created_by?.full_name||'—'],
      ['Дата регистрации',date(r.created_at)]
    ];
    const section=(title,body)=>'<div class="work-modal-section"><h3>'+title+'</h3>'+body+'</div>';
    const html=`
      <div class="work-modal-head"><div><h2 id="work-modal-title">${esc(r.work_type?.name||'Работа')} · ${esc(r.door?.number||'Без номера')}</h2><div class="work-modal-sub">Запись №${esc(r.id)} · ${esc(status)}</div></div><button class="work-modal-close" type="button" data-modal-close aria-label="Закрыть">×</button></div>
      <div class="work-modal-body">
        <div class="work-modal-grid">${fields.map(([label,value])=>'<div class="work-modal-field"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>').join('')}</div>
        ${r.completed_at_source==='device'?'<div class="hint" style="margin-top:12px">Время выполнения указано с устройства исполнителя.</div>':''}
        ${r.comment?section('Комментарий','<div class="work-modal-comment">'+esc(r.comment)+'</div>'):''}
        ${r.status==='voided'&&r.void_reason?section('Причина аннулирования','<div class="work-modal-comment">'+esc(r.void_reason)+'</div>'):''}
        ${section('Фотографии ('+photos.length+')',photos.length?'<div class="work-modal-photos">'+photos.map((photo,index)=>'<button type="button" class="work-photo-thumb" data-photo-index="'+index+'" title="Открыть фото"><img src="'+esc(photo.url)+'" alt="Фото выполненной работы" loading="lazy"></button>').join('')+'</div>':'<div class="muted">Фотографии не найдены</div>')}
      </div>`;
    const dialog=root.querySelector('.work-modal');dialog.innerHTML=html;
    dialog.querySelector('.work-modal-photos')?.addEventListener('click',e=>{
      const button=e.target.closest('[data-photo-index]');
      if(!button)return;
      e.preventDefault();
      openPhotoViewer(photos,Number(button.dataset.photoIndex));
    });
    root.querySelector('[data-modal-close]')?.focus();
  }catch(err){
    const dialog=root.querySelector('.work-modal');
    if(dialog)dialog.innerHTML='<div class="work-modal-head"><div><h2>Не удалось открыть работу</h2></div><button class="work-modal-close" type="button" data-modal-close aria-label="Закрыть">×</button></div><div class="work-modal-body"><div class="notice warn">'+esc(err.message)+'</div></div>';
    root.querySelector('[data-modal-close]')?.focus();
  }
}
async function logout(){
  if(navigator.onLine&&state.serverAuthenticated){try{await api('/auth/logout',{method:'POST',body:'{}'});}catch(_){}}
  state.user=null;state.csrf='';state.serverAuthenticated=false;await metaPut('auth',null);renderLogin();
}
let reconnecting=false;
async function reconnectAndSync(){
  if(!navigator.onLine||!state.user||reconnecting)return;
  reconnecting=true;
  try{
    const data=await api('/auth/me');
    state.user=data.user;state.csrf=data.csrf_token;state.serverAuthenticated=true;
    await metaPut('auth',{user:state.user});
    await loadWorkTypes();
    await syncQueue();
  }catch(e){
    state.serverAuthenticated=false;state.csrf='';
    if(e.status===401){
      state.user=null;
      await metaPut('auth',null);
      renderLogin('Сессия завершена. Войдите снова — локальная очередь сохранена.');
    }else if(state.user){
      toast('Сервер пока недоступен. Работы останутся на устройстве.');
    }
  }finally{
    reconnecting=false;
  }
}
window.addEventListener('online',()=>{state.online=true;if(state.user){render();reconnectAndSync();}else renderLogin();});
window.addEventListener('offline',()=>{state.online=false;state.serverAuthenticated=false;state.csrf='';if(state.user)render();});
window.addEventListener('focus',()=>{if(state.user&&navigator.onLine)reconnectAndSync();});
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&state.user&&navigator.onLine)reconnectAndSync();});
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));}
boot();
