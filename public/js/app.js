const API = '/api/v1';
const DB_NAME = 'door-offline-v1';
let state = { user: null, csrf: '', page: 'home', workTypes: [], online: navigator.onLine, busy: false };

const app = document.getElementById('app');
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = value => new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB',maximumFractionDigits:2}).format(Number(value||0));
const date = value => value ? new Date(value).toLocaleString('ru-RU',{dateStyle:'short',timeStyle:'short'}) : '—';
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
  try{
    const cached=await metaGet('auth').catch(()=>null);
    if(cached?.value){state.user=cached.value.user;state.csrf=cached.value.csrf||'';}
    if(navigator.onLine){
      try{const data=await api('/auth/me');state.user=data.user;state.csrf=data.csrf_token;await metaPut('auth',{user:state.user,csrf:state.csrf});}
      catch(e){if(e.status===401){state.user=null;state.csrf='';await metaPut('auth',null);}else if(!state.user)throw e;}
    }
    if(state.user){await loadWorkTypes();render();if(navigator.onLine)syncQueue();}
    else renderLogin();
  }catch(e){renderLogin(e.message);}
}

function renderLogin(error=''){
  app.innerHTML=`<main class="login-wrap"><form class="login-card" id="login-form"><div class="brand-mark">Д</div><h1>Учёт дверей</h1><p class="muted">Вход в систему</p>${error?`<p class="error">${esc(error)}</p>`:''}<div class="field"><label for="username">Логин</label><input id="username" name="username" autocomplete="username" required></div><div class="field"><label for="password">Пароль</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="btn block" type="submit">Войти</button><p class="hint">Для первого входа требуется подключение к серверу.</p></form></main>`;
  document.getElementById('login-form').addEventListener('submit',async e=>{
    e.preventDefault();const form=e.currentTarget;const btn=form.querySelector('button');btn.disabled=true;btn.textContent='Вход…';
    try{const data=await api('/auth/login',{method:'POST',body:JSON.stringify({username:form.username.value,password:form.password.value})});state.user=data.user;state.csrf=data.csrf_token;await metaPut('auth',{user:state.user,csrf:state.csrf});await loadWorkTypes();state.page='home';render();toast('Вход выполнен');}
    catch(err){renderLogin(err.message);}finally{btn.disabled=false;}
  });
}

const navItems=[['home','Обзор','⌂'],['new','Новая работа','＋'],['queue','Офлайн-очередь','⇧'],['history','История','▤']];
function render(){
  const role=state.user?.role||'';
  const items=navItems.filter(([id])=>id!=='new'||['owner','master'].includes(role));
  app.innerHTML=`<div class="layout"><aside class="sidebar"><div class="brand"><span class="brand-mark">Д</span><div><strong>Учёт дверей</strong><small>Производственный учёт</small></div></div><nav class="nav">${items.map(([id,label])=>navButton(id,label)).join('')}</nav><div class="sidebar-bottom"><div>${esc(state.user.full_name||state.user.username||'Пользователь')}</div><div>${esc(roleLabel(role))}</div><button class="nav-button" data-action="logout" style="margin-top:14px;background:transparent;border:0;color:#d9e5eb;padding:8px">Выйти ↗</button></div></aside><main class="main"><header class="topbar"><h1>${pageTitle()}</h1><div class="user-chip">${esc(state.user.full_name||'')} · ${esc(roleLabel(role))}</div><div class="connection"><span class="dot ${state.online?'':'offline'}"></span>${state.online?'В сети':'Нет сети'}</div></header><section class="content" id="page-content"></section></main></div><nav class="mobile-nav">${items.map(([id,label,icon])=>`<button data-page="${id}" class="${state.page===id?'active':''}"><span class="nav-icon">${icon}</span><span>${label}</span></button>`).join('')}</nav>`;
  app.querySelectorAll('[data-page]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.page)));
  app.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.nav)));
  app.querySelector('[data-action="logout"]')?.addEventListener('click',logout);
  renderPage();
}
function navButton(id,label){return `<button data-nav="${id}" class="${state.page===id?'active':''}">${label}</button>`;}
function roleLabel(role){return ({owner:'Администратор',master:'Мастер',director:'Директор'})[role]||'Пользователь';}
function pageTitle(){return ({home:'Обзор',new:'Регистрация работы',queue:'Офлайн-очередь',history:'История работ'})[state.page]||'Учёт дверей';}
function go(page){state.page=page;render();}
function pageHead(title,sub,button=''){return `<div class="page-head"><div><h2>${title}</h2><p>${sub}</p></div>${button}</div>`;}

async function renderPage(){
  const el=document.getElementById('page-content');if(!el)return;
  if(state.page==='new'){renderNew(el);return;}
  if(state.page==='queue'){await renderQueue(el);return;}
  if(state.page==='history'){await renderHistory(el);return;}
  await renderHome(el);
}
async function renderHome(el){
  el.innerHTML=pageHead('Рабочий обзор','Состояние учёта выполненных работ')+`<div class="cards"><div class="stat-card"><span>Ожидают отправки</span><strong id="pending-count">…</strong></div><div class="stat-card"><span>Подключение</span><strong style="font-size:20px">${state.online?'Онлайн':'Офлайн'}</strong></div><div class="stat-card"><span>Пользователь</span><strong style="font-size:18px">${esc(state.user.full_name||'')}</strong></div></div><div class="panel"><h3>Быстрые действия</h3><div class="actions">${['owner','master'].includes(state.user.role)?'<button class="btn" data-nav="new">＋ Зарегистрировать работу</button>':''}<button class="btn secondary" data-nav="queue">Открыть очередь</button><button class="btn secondary" data-nav="history">История работ</button></div></div><div class="panel"><h3>Последние работы</h3><div id="recent-list" class="list"><div class="muted">Загрузка…</div></div></div>`;
  el.querySelectorAll('[data-nav]').forEach(b=>b.addEventListener('click',()=>go(b.dataset.nav)));
  const queue=await queueAll().catch(()=>[]);const pc=document.getElementById('pending-count');if(pc)pc.textContent=queue.length;
  try{const data=await api('/work-records?per_page=5');const rows=data.items||[];const list=document.getElementById('recent-list');if(!list)return;list.innerHTML=rows.length?rows.map(recordHtml).join(''):'<div class="empty">Записей пока нет</div>';}catch(e){document.getElementById('recent-list').innerHTML=`<div class="notice warn">${esc(e.message)}</div>`;}
}
async function renderNew(el){
  if(!state.workTypes.length)await loadWorkTypes();
  if(!state.workTypes.length){el.innerHTML=pageHead('Регистрация работы','Нет доступного справочника видов работ')+'<div class="notice warn">Подключитесь к серверу и обновите страницу. Для офлайн-регистрации справочник нужно загрузить заранее.</div>';return;}
  el.innerHTML=pageHead('Новая работа','Фото обязательно. При отсутствии сети запись сохранится на устройстве.')+`<form id="work-form" class="panel"><div class="form-grid"><div class="field"><label for="work_type_id">Вид работы *</label><select id="work_type_id" name="work_type_id" required>${state.workTypes.map(t=>`<option value="${esc(t.id)}" data-door-required="${t.door_required?'1':'0'}">${esc(t.name)}</option>`).join('')}</select><div class="hint" id="door-requirement"></div></div><div class="field"><label for="quantity">Количество</label><input id="quantity" name="quantity" type="number" min="0.001" max="99999.999" step="0.001" value="1" required></div><div class="field span-2"><label for="door_number">Номер двери</label><input id="door_number" name="door_number" placeholder="Например, D-000123" autocomplete="off" inputmode="text"><label class="checkline"><input id="new_door" name="new_door" type="checkbox"> Новая дверь — присвоить номер автоматически</label><div class="hint">Формат номера: D- и от 6 до 12 цифр. Для видов работ без обязательной привязки дверь можно не указывать.</div></div><div class="field span-2"><label for="comment">Комментарий</label><textarea id="comment" name="comment" maxlength="2000" placeholder="Необязательно"></textarea></div><div class="field span-2"><label>Фото выполненной работы *</label><div class="filebox"><input id="photos" name="photos" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required><div class="hint">Можно выбрать несколько фотографий. Файлы сохраняются на устройстве до подтверждения сервером.</div><div id="file-list" class="file-list">Файлы не выбраны</div></div></div></div><div class="notice">Время выполнения будет записано с часов устройства. Сервер отдельно зафиксирует время получения записи.</div><div class="actions"><button class="btn" type="submit">Сохранить работу</button><button class="btn ghost" type="reset">Очистить</button></div><div id="form-error" class="error"></div></form>`;
  const form=document.getElementById('work-form'), files=document.getElementById('photos');
  const typeSelect=form.elements.work_type_id, doorInput=form.elements.door_number, newDoor=form.elements.new_door;
  const updateDoorRule=()=>{const option=typeSelect.selectedOptions[0];const required=option?.dataset.doorRequired==='1';document.getElementById('door-requirement').textContent=required?'Для выбранного вида работы обязательно укажите существующую дверь или отметьте создание новой.':'Привязка к двери необязательна для выбранного вида работы.';doorInput.required=required&&!newDoor.checked;};
  typeSelect.addEventListener('change',updateDoorRule);newDoor.addEventListener('change',()=>{doorInput.disabled=newDoor.checked;doorInput.value=newDoor.checked?'':doorInput.value;updateDoorRule();});updateDoorRule();
  form.addEventListener('reset',()=>setTimeout(()=>{doorInput.disabled=false;updateDoorRule();document.getElementById('file-list').textContent='Файлы не выбраны';},0));
  files.addEventListener('change',()=>{document.getElementById('file-list').textContent=Array.from(files.files).map(f=>f.name).join(', ')||'Файлы не выбраны';});
  form.addEventListener('submit',async e=>{
    e.preventDefault();const fd=new FormData(form);const photos=Array.from(files.files||[]);
    if(!photos.length){document.getElementById('form-error').textContent='Добавьте хотя бы одну фотографию.';return;}
    if(photos.length>10){document.getElementById('form-error').textContent='За одну работу можно приложить не более 10 фотографий.';return;}
    if(photos.some(file=>!['image/jpeg','image/png','image/webp'].includes(file.type))){document.getElementById('form-error').textContent='Допустимы только JPEG, PNG и WebP.';return;}
    if(photos.some(file=>file.size>15*1024*1024)){document.getElementById('form-error').textContent='Размер одного фото не должен превышать 15 МБ.';return;}
    if(photos.reduce((sum,file)=>sum+file.size,0)>60*1024*1024){document.getElementById('form-error').textContent='Общий размер фотографий должен быть не более 60 МБ.';return;}
    const selectedType=state.workTypes.find(t=>String(t.id)===String(fd.get('work_type_id')));
    const doorNumber=String(fd.get('door_number')||'').trim();
    if(selectedType?.door_required&&!doorNumber&&fd.get('new_door')!=='on'){document.getElementById('form-error').textContent='Для выбранного вида работы необходимо указать дверь или создать новую.';return;}
    if(doorNumber&&!/^D-\d{6,12}$/i.test(doorNumber)){document.getElementById('form-error').textContent='Номер двери должен иметь формат D-000123.';return;}
    if(fd.get('new_door')==='on'&&doorNumber){document.getElementById('form-error').textContent='Укажите номер существующей двери или отметьте новую дверь.';return;}
    const item={id:crypto.randomUUID(),idemKey:crypto.randomUUID(),createdLocal:new Date().toISOString(),status:'queued',data:{work_type_id:fd.get('work_type_id'),quantity:fd.get('quantity')||'1',door_number:String(fd.get('door_number')||'').trim(),new_door:fd.get('new_door')==='on'?'true':'false',comment:fd.get('comment').trim(),completed_at:new Date().toISOString()},photos};
    const btn=form.querySelector('button[type="submit"]');btn.disabled=true;btn.textContent='Сохранение…';
    try{await queuePut(item);form.reset();document.getElementById('file-list').textContent='Файлы не выбраны';toast('Работа сохранена в очереди устройства');if(state.online)await syncQueue();go('queue');}
    catch(err){document.getElementById('form-error').textContent='Не удалось сохранить локально: '+err.message;}
    finally{btn.disabled=false;btn.textContent='Сохранить работу';}
  });
}
async function renderQueue(el){
  const rows=await queueAll().catch(()=>[]);
  el.innerHTML=pageHead('Офлайн-очередь','Работы хранятся на этом устройстве до подтверждения сервером',state.online?'<button class="btn secondary" id="sync-now">Синхронизировать</button>':'')+(rows.length?`<div class="list">${rows.map(q=>`<article class="record-card"><div class="record-main"><strong>${esc(typeName(q.data.work_type_id))} · ${esc(q.data.door_number|| (q.data.new_door==='true'?'Новая дверь':'Без двери'))}</strong><div class="record-meta"><span>${date(q.data.completed_at)}</span><span>${q.photos.length} фото</span><span class="pill pending">${esc(q.status==='error'?'Ошибка отправки':'Ожидает отправки')}</span></div>${q.error?`<div class="error">${esc(q.error)}</div>`:''}</div><button class="btn ghost" data-remove="${esc(q.id)}" aria-label="Удалить">Удалить</button></article>`).join('')}</div>`:'<div class="empty">Очередь пуста. Новые работы появятся здесь при сохранении.</div>')+`<div class="notice warn">Не очищайте данные браузера и не удаляйте приложение, пока очередь не синхронизирована. Фото удаляются с устройства только после успешного ответа сервера.</div>`;
  el.querySelector('#sync-now')?.addEventListener('click',syncQueue);
  el.querySelectorAll('[data-remove]').forEach(b=>b.addEventListener('click',async()=>{if(confirm('Удалить несинхронизированную запись и её фотографии?')){await queueDelete(b.dataset.remove);renderPage();}}));
}
function typeName(id){return state.workTypes.find(t=>String(t.id)===String(id))?.name||'Вид работы';}
async function syncQueue(){
  if(!navigator.onLine||!state.user||state.busy)return;
  state.busy=true;
  try{
    const rows=(await queueAll()).sort((a,b)=>a.createdLocal.localeCompare(b.createdLocal));
    for(const item of rows){
      const body=new FormData();Object.entries(item.data).forEach(([k,v])=>body.append(k,v));
      item.photos.forEach((f,i)=>body.append('photos[]',f,f.name||('photo-'+i+'.jpg')));
      try{
        await api('/work-records',{method:'POST',headers:{'Idempotency-Key':item.idemKey},body});
        await queueDelete(item.id);toast('Работа отправлена на сервер');
      }catch(err){
        item.status='error';item.error=err.message;await queuePut(item);
        if(err.status===401||err.status===403||err.status===413||err.status===422||err.status===409)continue;
        break;
      }
    }
  }finally{state.busy=false;if(state.page==='queue')renderPage();if(state.page==='home')renderPage();}
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
function recordHtml(r){return `<article class="record-card"><div class="record-main"><strong>${esc(r.work_type?.name||'Работа')} · ${esc(r.door?.number||'Без номера')}</strong><div class="record-meta"><span>Выполнено: ${date(r.completed_at)}</span><span>${esc(r.user?.full_name||'')}</span><span>${Number(r.photos_count||0)} фото</span></div><div class="hint">${r.completed_at_source==='device'?'Время выполнения с устройства':'Время выполнения по серверу'} · Получено сервером: ${date(r.received_at)}</div></div><div class="amount">${money(r.total_amount)}</div></article>`;}
async function logout(){
  if(navigator.onLine){try{await api('/auth/logout',{method:'POST',body:'{}'});}catch(_){}}
  state.user=null;state.csrf='';await metaPut('auth',null);renderLogin();
}
window.addEventListener('online',()=>{state.online=true;if(state.user){render();syncQueue();}else renderLogin();});
window.addEventListener('offline',()=>{state.online=false;if(state.user)render();});
window.addEventListener('focus',()=>{if(state.user&&navigator.onLine)syncQueue();});
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&state.user&&navigator.onLine)syncQueue();});
if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));}
boot();
