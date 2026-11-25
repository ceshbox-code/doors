#!/usr/bin/env python3
from pathlib import Path
import re
import sys

ROOT = Path.cwd()
EXPECTED = [
    'app/Services/WorkRecordService.php',
    'app/Repositories/WorkRecordRepository.php',
    'app/Models/WorkRecord.php',
    'app/Controllers/WorkRecordController.php',
    'app/routes.php',
    'public/js/app.js',
    'database/migrations/019_create_employee_advance_ledger.sql',
]
for name in EXPECTED:
    if not (ROOT / name).is_file():
        raise SystemExit(f'ОШИБКА: не найден обязательный файл: {name}')

migration = ROOT / 'database/migrations/020_work_record_groups.sql'
if migration.exists():
    raise SystemExit('ОШИБКА: миграция 020 уже существует. Ничего не изменено.')

# Read all targets first. No file is written until every precondition passes.
paths = [ROOT / p for p in EXPECTED[:-1]]
original = {p: p.read_text(encoding='utf-8') for p in paths}
updated = dict(original)


def once(text, old, new, label):
    n = text.count(old)
    if n != 1:
        raise RuntimeError(f'{label}: ожидался ровно 1 якорь, найдено {n}')
    return text.replace(old, new, 1)

# 1) WorkRecordRepository: expose group_id and write NULL for legacy single records.
p = ROOT / 'app/Repositories/WorkRecordRepository.php'
s = updated[p]
s = once(s,
"wr.id, wr.work_type_id, wr.door_id, wr.user_id, wr.rate_id, wr.quantity, wr.unit,",
"wr.id, wr.group_id, wr.work_type_id, wr.door_id, wr.user_id, wr.rate_id, wr.quantity, wr.unit,",
"WorkRecordRepository SELECT")
s = once(s,
"                (work_type_id, door_id, user_id, rate_id, quantity, unit, unit_price, total_amount,\n                 allow_unique_per_door, completed_at, received_at, completed_at_source, created_by, comment)\n             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
"(group_id, work_type_id, door_id, user_id, rate_id, quantity, unit, unit_price, total_amount,\n                 allow_unique_per_door, completed_at, received_at, completed_at_source, created_by, comment)\n             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
"WorkRecordRepository INSERT columns")
s = once(s,
"            $d['work_type_id'], $d['door_id'], $d['user_id'], $d['rate_id'], $d['quantity'], $d['unit'],\n            $d['unit_price'], $d['total_amount'], $d['allow_unique_per_door'], $d['completed_at'],",
"$d['group_id'] ?? null, $d['work_type_id'], $d['door_id'], $d['user_id'], $d['rate_id'], $d['quantity'], $d['unit'],\n            $d['unit_price'], $d['total_amount'], $d['allow_unique_per_door'], $d['completed_at'],",
"WorkRecordRepository INSERT values")
updated[p] = s

# 2) Model: make the group identifier visible without changing old record semantics.
p = ROOT / 'app/Models/WorkRecord.php'
s = updated[p]
s = once(s,
"            'id'           => (int) $r['id'],\n            'status'       => $voided ? 'voided' : 'active',",
"            'id'           => (int) $r['id'],\n            'group_id'     => isset($r['group_id']) && $r['group_id'] !== null ? (int) $r['group_id'] : null,\n            'status'       => $voided ? 'voided' : 'active',",
"WorkRecord model")
updated[p] = s

# 3) Service: add isolated batch path; existing register()/void() remain untouched.
p = ROOT / 'app/Services/WorkRecordService.php'
s = updated[p]
s = once(s, "use App\\Core\\Config;\n", "use App\\Core\\Config;\nuse App\\Core\\Database;\n", "WorkRecordService Database import")
method = r'''    /**
     * Пакетная регистрация нескольких видов работ для одной двери.
     * Все записи, фотографии, авансовые зачёты и ключ идемпотентности фиксируются атомарно.
     */
    public function registerBatch(array $actor, array $input, array $uploads, string $idemKey, string $ip): array
    {
        $receivedAt = Dates::nowUtc();
        if (!IdempotencyService::validKey($idemKey)) {
            throw new ApiException('IDEMPOTENCY_KEY_INVALID', 'Заголовок Idempotency-Key обязателен: 8–100 символов (буквы, цифры, . _ : -)', 400);
        }

        $rawIds = $input['work_type_ids'] ?? null;
        if (!is_array($rawIds) || count($rawIds) < 2 || count($rawIds) > 20) {
            throw new ApiException('VALIDATION_ERROR', 'Выберите от 2 до 20 видов работ', 422, ['work_type_ids' => 'Необходимо выбрать от 2 до 20 видов работ']);
        }
        $typeIds = [];
        foreach ($rawIds as $rawId) {
            $id = Input::id($rawId);
            if ($id === null || in_array($id, $typeIds, true)) {
                throw new ApiException('VALIDATION_ERROR', 'Список видов работ содержит некорректные или повторяющиеся значения', 422, ['work_type_ids' => 'ID должны быть положительными и уникальными']);
            }
            $typeIds[] = $id;
        }

        // Используем штатный валидатор каждой записи: количество, дверь, комментарий и время
        // имеют ровно те же правила, что и у существующего одиночного API.
        $sharedInput = $input;
        unset($sharedInput['work_type_ids'], $sharedInput['work_type_id']);
        $d = WorkRecordValidator::register(array_merge($sharedInput, ['work_type_id' => $typeIds[0]]));

        $actorId = (int) $actor['id'];
        $executorId = $actorId;
        if ($d['user_id'] !== null && $d['user_id'] !== $actorId) {
            if (!Permissions::can($actor['role'], 'work.register_for_others')) {
                throw new ApiException('FORBIDDEN', 'Регистрировать работы за других исполнителей может только администратор', 403);
            }
            $executorId = $d['user_id'];
        }
        if (!$uploads) {
            throw new ApiException('PHOTO_REQUIRED', 'Без фотографии работу зарегистрировать нельзя. Приложите фото (поле photos).', 422);
        }

        $prepared = $this->storage->prepare($uploads);
        $shas = array_column($prepared, 'sha256');
        sort($shas);
        $hashData = [
            'executor' => $executorId, 'work_type_ids' => $typeIds, 'door_number' => $d['door_number'],
            'new_door' => $d['new_door'], 'quantity' => $d['quantity'], 'comment' => $d['comment'], 'photos' => $shas,
        ];
        if ($d['completed_at'] !== null) {
            $hashData['completed_at'] = $d['completed_at'];
        }
        $hash = IdempotencyService::hash($hashData);
        $completedAt = $d['completed_at'] ?? Dates::nowUtc();
        $operation = 'work_record.batch_create';
        $done = $this->idem->peek($actorId, $idemKey, $operation, $hash);
        if ($done !== null) {
            return ['status' => (int) $done['status'], 'data' => $done['data'], 'replayed' => true];
        }

        $staged = $this->storage->stage($prepared);
        $placed = [];
        try {
            return Tx::run(function () use ($actor, $actorId, $executorId, $d, $typeIds, $completedAt, $receivedAt, $idemKey, $hash, $operation, $ip, $staged, &$placed) {
                $this->storage->discard($placed);
                $placed = [];

                $cached = $this->idem->begin($actorId, $idemKey, $operation, $hash);
                if ($cached !== null) {
                    return ['status' => (int) $cached['status'], 'data' => $cached['data'], 'replayed' => true];
                }

                // Блокировка строки исполнителя обязательна для корректного FIFO-зачёта авансов.
                $executor = $this->users->find($executorId, true);
                if ($executor === null || !(int) $executor['is_active'] || !in_array($executor['role'], ['master'], true)) {
                    throw new ApiException('INVALID_EXECUTOR', 'Исполнитель не найден, деактивирован или не может выполнять работы', 422);
                }

                $types = [];
                $doorRequired = false;
                foreach ($typeIds as $typeId) {
                    $type = $this->types->find($typeId);
                    if ($type === null) {
                        throw new ApiException('WORK_TYPE_NOT_FOUND', 'Один из выбранных видов работ не найден', 404, ['work_type_id' => $typeId]);
                    }
                    if (!(int) $type['is_active']) {
                        throw new ApiException('WORK_TYPE_INACTIVE', 'Один из выбранных видов работ отключён', 409, ['work_type_id' => $typeId]);
                    }
                    $doorRequired = $doorRequired || (bool) (int) $type['door_required'];
                    $types[] = $type;
                }

                $doorId = null;
                if ($d['door_number'] !== null) {
                    $door = $this->doors->findByNumber($d['door_number']);
                    if ($door === null) {
                        throw new ApiException('DOOR_NOT_FOUND', 'Дверь с таким номером не найдена. Для новой двери передайте new_door=true.', 404);
                    }
                    $doorId = (int) $door['id'];
                } elseif ($d['new_door']) {
                    $number = $this->doors->nextNumber();
                    $doorId = $this->doors->insert($number, $actorId);
                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);
                } elseif ($doorRequired) {
                    throw new ApiException('DOOR_REQUIRED', 'Для одного или нескольких выбранных видов работ нужна дверь: укажите номер или создайте новую.', 422);
                }

                $source = $d['completed_at'] === null ? 'server' : 'device';
                $pdo = Database::pdo();
                $st = $pdo->prepare(
                    'INSERT INTO work_record_groups (created_by, user_id, door_id, completed_at, received_at, completed_at_source, comment) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$actorId, $executorId, $doorId, $completedAt, $receivedAt, $source, $d['comment']]);
                $groupId = (int) $pdo->lastInsertId();

                $items = [];
                foreach ($types as $type) {
                    $typeId = (int) $type['id'];
                    $unique = !(int) $type['allow_multiple_per_door'];
                    if ($doorId !== null && $unique) {
                        $dup = $this->records->findActiveDuplicate($typeId, $doorId);
                        if ($dup !== null) {
                            throw $this->duplicate($dup);
                        }
                    }

                    $rate = $this->rates->activeAt($executorId, $typeId, $completedAt);
                    if ($rate === null) {
                        throw new ApiException('RATE_NOT_FOUND', 'Для исполнителя не назначена расценка на один из выбранных видов работ.', 422, ['work_type_id' => $typeId]);
                    }
                    $total = Money::multiply($d['quantity'], $rate['unit_price']);
                    try {
                        $recordId = $this->records->insert([
                            'group_id' => $groupId, 'work_type_id' => $typeId, 'door_id' => $doorId, 'user_id' => $executorId,
                            'rate_id' => (int) $rate['id'], 'quantity' => $d['quantity'], 'unit' => $type['unit'],
                            'unit_price' => $rate['unit_price'], 'total_amount' => $total,
                            'allow_unique_per_door' => $unique ? 1 : 0, 'completed_at' => $completedAt,
                            'received_at' => $receivedAt, 'completed_at_source' => $source,
                            'created_by' => $actorId, 'comment' => $d['comment'],
                        ]);
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000' && $doorId !== null) {
                            throw $this->duplicate($this->records->findActiveDuplicate($typeId, $doorId));
                        }
                        throw $e;
                    }

                    // Финансовые правила не меняются: каждый work_record получает собственный FIFO-зачёт.
                    $this->advances->applyToWork($executorId, $recordId, (float) $total, $actorId);

                    // У каждой записи собственные уникальные имена файлов (PhotoStorage использует random_bytes).
                    // finally гарантирует включение файлов в общий rollback-список даже при ошибке INSERT photos.
                    $recordPlaced = [];
                    try {
                        $photos = $this->photos->attach($recordId, $staged, $recordPlaced);
                    } finally {
                        $placed = array_merge($placed, $recordPlaced);
                    }

                    $row = $this->records->findDetailed($recordId);
                    $presented = WorkRecord::present($row) + ['photos' => $photos];
                    $this->audit->log($actorId, 'work_record.create', 'work_record', $recordId, null, [
                        'group_id' => $groupId, 'work_type_id' => $typeId, 'door_id' => $doorId,
                        'door_number' => $row['door_number'], 'user_id' => $executorId,
                        'quantity' => $d['quantity'], 'unit' => $type['unit'], 'rate_id' => (int) $rate['id'],
                        'unit_price' => $rate['unit_price'], 'total_amount' => $total,
                        'photos' => array_map(static fn(array $photo): array => ['sha256' => $photo['sha256'], 'size' => $photo['size']], $staged),
                    ], null, $ip);
                    $items[] = $presented;
                }

                $data = ['group_id' => $groupId, 'count' => count($items), 'items' => $items];
                $this->audit->log($actorId, 'work_record.batch_create', 'work_record_group', $groupId, null, [
                    'work_record_ids' => array_column($items, 'id'), 'work_type_ids' => $typeIds,
                    'door_id' => $doorId, 'user_id' => $executorId,
                ], null, $ip);
                $this->idem->complete($actorId, $idemKey, $groupId, 201, $data);
                return ['status' => 201, 'data' => $data, 'replayed' => false];
            });
        } catch (Throwable $e) {
            $this->storage->discard($placed);
            throw $e;
        } finally {
            $this->storage->discardStaged($staged);
        }
    }

'''
s = once(s, '    private function duplicate(?array $existing): ApiException\n', method + '    private function duplicate(?array $existing): ApiException\n', 'WorkRecordService batch method insertion')
updated[p] = s

# 4) Controller and route.
p = ROOT / 'app/Controllers/WorkRecordController.php'
s = updated[p]
controller_method = '''    public function storeBatch(Request $r): Response
    {
        $key = (string) $r->header('Idempotency-Key');
        if ($key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'Передайте уникальный заголовок Idempotency-Key', 400);
        }
        if ($r->bodyTooLarge) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Размер запроса превышает допустимый (64 МБ)', 413);
        }
        $res = $this->records->registerBatch($r->user, $r->input(), $r->files, $key, $r->ip);
        $response = Response::ok($res['data'], $res['status']);
        return $res['replayed'] ? $response->withHeader('Idempotent-Replay', 'true') : $response;
    }

'''
s = once(s, '    public function index(Request $r): Response\n', controller_method + '    public function index(Request $r): Response\n', 'WorkRecordController batch method')
updated[p] = s

p = ROOT / 'app/routes.php'
s = updated[p]
s = once(s,
"    $router->post('/api/v1/work-records',             Guard::auth([$works, 'store'],  'work.register'));\n",
"    $router->post('/api/v1/work-records/batch',       Guard::auth([$works, 'storeBatch'], 'work.register'));\n    $router->post('/api/v1/work-records',             Guard::auth([$works, 'store'],  'work.register'));\n",
"work-records batch route")
updated[p] = s

# 5) Frontend: multi-select UI; old queue entries keep their original single-record endpoint.
p = ROOT / 'public/js/app.js'
s = updated[p]
start = s.find('async function renderNew(el){')
end = s.find('async function renderQueue(', start)
if start < 0 or end < 0:
    raise RuntimeError('app.js: не найдены границы renderNew()/renderQueue()')
new_render = r'''async function renderNew(el){
  if(!state.workTypes.length)await loadWorkTypes();
  if(!state.workTypes.length){el.innerHTML=pageHead('Регистрация работы','Нет доступного справочника видов работ')+'<div class="notice warn">Подключитесь к серверу и обновите страницу. Для офлайн-регистрации справочник нужно загрузить заранее.</div>';return;}
  el.innerHTML=pageHead('Новая работа','Выберите несколько видов работ для одной двери. Фото обязательно.')+`<form id="work-form" class="panel"><div class="form-grid"><div class="field span-2"><label>Виды работ * (можно выбрать несколько)</label><div class="form-grid">${state.workTypes.map(t=>`<label class="checkline"><input type="checkbox" name="work_type_ids[]" value="${esc(t.id)}" data-door-required="${t.door_required?'1':'0'}"> ${esc(t.name)}</label>`).join('')}</div><div class="hint" id="door-requirement">Выберите хотя бы один вид работы.</div></div><div class="field"><label for="quantity">Количество для каждого вида работ</label><input id="quantity" name="quantity" type="number" min="0.001" max="99999.999" step="0.001" value="1" required></div><div class="field"><label for="completed_at_local">Дата и время выполнения *</label><input id="completed_at_local" name="completed_at_local" type="datetime-local" required><div class="hint">По умолчанию — текущее местное время устройства. При необходимости укажите фактическое время выполнения.</div></div><div class="field span-2"><label for="door_number">Номер двери</label><input id="door_number" name="door_number" placeholder="Например, D-000123" autocomplete="off" inputmode="text"><label class="checkline"><input id="new_door" name="new_door" type="checkbox"> Новая дверь — присвоить номер автоматически</label><div class="hint">Формат номера: D- и от 6 до 12 цифр. Если хотя бы один выбранный вид требует дверь, укажите её.</div></div><div class="field span-2"><label for="comment">Комментарий</label><textarea id="comment" name="comment" maxlength="2000" placeholder="Необязательно"></textarea></div><div class="field span-2"><label>Фото выполненной работы *</label><div class="filebox"><input id="photos" name="photos" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple required><div class="hint">Фотографии будут прикреплены к каждой записи группы. Файлы сохраняются на устройстве до подтверждения сервером.</div><div id="file-list" class="file-list">Файлы не выбраны</div></div></div></div><div class="notice">Для каждого выбранного вида будет создана отдельная запись с собственной расценкой и начислением. Время выполнения будет записано с часов устройства; сервер отдельно зафиксирует время получения.</div><div class="actions"><button class="btn" type="submit">Сохранить группу работ</button><button class="btn ghost" type="reset">Очистить</button></div><div id="form-error" class="error"></div></form>`;
  const form=document.getElementById('work-form'),files=document.getElementById('photos');
  const doorInput=form.elements.door_number,newDoor=form.elements.new_door,completedInput=form.elements.completed_at_local;
  const selectedBoxes=()=>Array.from(form.querySelectorAll('input[name="work_type_ids[]"]:checked'));
  completedInput.value=localDateTime(new Date());
  const updateDoorRule=()=>{const selected=selectedBoxes();const required=selected.some(x=>x.dataset.doorRequired==='1');document.getElementById('door-requirement').textContent=selected.length<1?'Выберите хотя бы один вид работы.':(required?'Для выбранных видов работ обязательно укажите существующую дверь или отметьте создание новой.':'Привязка к двери необязательна для выбранных видов работ.');doorInput.required=required&&!newDoor.checked;};
  form.querySelectorAll('input[name="work_type_ids[]"]').forEach(x=>x.addEventListener('change',updateDoorRule));
  newDoor.addEventListener('change',()=>{doorInput.disabled=newDoor.checked;doorInput.value=newDoor.checked?'':doorInput.value;updateDoorRule();});
  form.addEventListener('reset',()=>setTimeout(()=>{doorInput.disabled=false;updateDoorRule();completedInput.value=localDateTime(new Date());document.getElementById('file-list').textContent='Файлы не выбраны';},0));
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
    const doorNumber=String(fd.get('door_number')||'').trim(),requiresDoor=selected.some(x=>x.dataset.doorRequired==='1');
    if(requiresDoor&&!doorNumber&&fd.get('new_door')!=='on'){error.textContent='Для одного или нескольких выбранных видов работ необходимо указать дверь или создать новую.';return;}
    if(doorNumber&&!/^D-\d{6,12}$/i.test(doorNumber)){error.textContent='Номер двери должен иметь формат D-000123.';return;}
    if(fd.get('new_door')==='on'&&doorNumber){error.textContent='Укажите номер существующей двери или отметьте новую дверь.';return;}
    const data={quantity:fd.get('quantity')||'1',door_number:doorNumber,new_door:fd.get('new_door')==='on'?'true':'false',comment:String(fd.get('comment')||'').trim(),completed_at:completedDate.toISOString()};
    if(typeIds.length===1)data.work_type_id=typeIds[0];else data.work_type_ids=typeIds;
    const item={id:crypto.randomUUID(),idemKey:crypto.randomUUID(),ownerUserId:Number(state.user.id),createdLocal:new Date().toISOString(),status:'queued',data,photos:photos.map(file=>({blob:file.slice(0,file.size,file.type),name:file.name||'photo.jpg',type:file.type||'application/octet-stream',lastModified:file.lastModified||Date.now()}))};
    const btn=form.querySelector('button[type="submit"]');btn.disabled=true;btn.textContent='Сохранение…';
    try{await queuePut(item);form.reset();document.getElementById('file-list').textContent='Файлы не выбраны';toast(typeIds.length===1?'Работа сохранена в очереди устройства':'Группа работ сохранена в очереди устройства');if(state.online)await syncQueue();go('queue');}
    catch(err){error.textContent='Не удалось сохранить локально: '+err.message;}
    finally{btn.disabled=false;btn.textContent='Сохранить';}
  });
}

'''
s = s[:start] + new_render + s[end:]
# Sync supports both the historical queue format and new group format.
s = once(s,
"      const body=new FormData();Object.entries(item.data).forEach(([k,v])=>body.append(k,v));\n",
"      const body=new FormData();Object.entries(item.data).forEach(([k,v])=>{if(k==='work_type_ids'&&Array.isArray(v)){v.forEach(id=>body.append('work_type_ids[]',String(id)));}else{body.append(k,v);}});\n",
"syncQueue FormData")
s = once(s,
"        await api('/work-records',{method:'POST',headers:{'Idempotency-Key':item.idemKey},body});\n",
"        const endpoint=Array.isArray(item.data.work_type_ids)?'/work-records/batch':'/work-records';\n        await api(endpoint,{method:'POST',headers:{'Idempotency-Key':item.idemKey},body});\n",
"syncQueue endpoint")
# Queue item label supports new group payloads, if the existing renderer uses this expression.
if 'typeName(q.data.work_type_id)' in s:
    s = s.replace('typeName(q.data.work_type_id)', "(Array.isArray(q.data.work_type_ids)?q.data.work_type_ids.map(typeName).join(', '):typeName(q.data.work_type_id))")
# Add a subtle group marker in history cards; does not alter details or financial totals.
s = once(s,
"  const title=(r.work_type?.name||'Работа')+' · '+(r.door?.number||'Без номера');\n",
"  const title=(r.work_type?.name||'Работа')+' · '+(r.door?.number||'Без номера');\n  const groupLabel=r.group_id?' · Группа №'+r.group_id:'';\n",
"recordHtml group label variable")
s = once(s,
"<span>${count} фото</span><span class=\"pill",
"<span>${count} фото</span>${groupLabel?`<span>${groupLabel.trim()}</span>`:''}<span class=\"pill",
"recordHtml group marker")
updated[p] = s

# Migration uses exact BIGINT UNSIGNED key types verified from the live DDL dump.
migration_sql = '''-- Пакетная регистрация нескольких видов работ для одной двери.
-- Старые записи получают group_id = NULL и продолжают работать без изменений.
CREATE TABLE work_record_groups (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by          BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    door_id             BIGINT UNSIGNED NULL,
    completed_at        DATETIME NOT NULL,
    received_at         DATETIME NOT NULL,
    completed_at_source VARCHAR(16) NOT NULL DEFAULT 'server',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    comment             TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_wrg_user_completed (user_id, completed_at),
    KEY idx_wrg_door (door_id),
    CONSTRAINT fk_wrg_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_wrg_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_wrg_door FOREIGN KEY (door_id) REFERENCES doors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE work_records
    ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER id,
    ADD KEY idx_wr_group (group_id),
    ADD CONSTRAINT fk_wr_group FOREIGN KEY (group_id) REFERENCES work_record_groups (id);
'''

# Ensure all changes are in-memory and all anchors were validated before any write.
# Also ensure no unexpected group_id column already exists in checked source.
if 'group_id' in original[ROOT / 'app/Repositories/WorkRecordRepository.php']:
    raise SystemExit('ОШИБКА: WorkRecordRepository уже содержит group_id; похоже, патч применён частично. Ничего не записано.')

# Write staged content only after all preflight checks pass.
for p, content in updated.items():
    with p.open('w', encoding='utf-8', newline='') as fh:
        fh.write(content)
with migration.open('w', encoding='utf-8', newline='') as fh:
    fh.write(migration_sql)

print('OK: пакетная регистрация подготовлена.')
print('Изменены:')
for p in updated:
    print(' -', p.relative_to(ROOT))
print('Создана миграция:', migration.relative_to(ROOT))
print('Миграция НЕ запускалась. Данные и Docker volumes не изменялись.')
print('Следующий шаг: проверить PHP/JS синтаксис, применить миграцию 020 и пересобрать doors-app.')
