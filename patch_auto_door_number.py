#!/usr/bin/env python3
from pathlib import Path

ROOT = Path("/volume1/docker/doors")

def replace_once(path: Path, old: str, new: str, label: str) -> None:
    text = path.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"ОШИБКА: {label}: ожидалось одно совпадение, найдено {count}. Файлы не изменены этим шагом.")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")
    print(f"OK: {label}")

def main() -> None:
    js = ROOT / "public/js/app.js"
    service = ROOT / "app/Services/WorkRecordService.php"

    if not js.is_file() or not service.is_file():
        raise SystemExit(f"Не найдены исходные файлы проекта в {ROOT}")

    # Сначала проверяем все якоря до изменения любых файлов.
    js_text = js.read_text(encoding="utf-8")
    php_text = service.read_text(encoding="utf-8")

    checks = [
        (js_text, 'data-door-required="${t.door_required?\'1\':\'0\'}"', "атрибут door_required"),
        (js_text, '<div class="field span-2"><label for="door_number">Номер двери</label><input id="door_number" name="door_number" placeholder="Например, D-000123" autocomplete="off" inputmode="text"><label class="checkline"><input id="new_door" name="new_door" type="checkbox"> Новая дверь — присвоить номер автоматически</label><div class="hint">Формат номера: D- и от 6 до 12 цифр. Если хотя бы один выбранный вид требует дверь, укажите её.</div></div>', "блок номера двери в форме"),
        (js_text, "const doorInput=form.elements.door_number,newDoor=form.elements.new_door,completedInput=form.elements.completed_at_local;", "переменные формы"),
        (js_text, "const updateDoorRule=()=>{const selected=selectedBoxes();const required=selected.some(x=>x.dataset.doorRequired==='1');document.getElementById('door-requirement').textContent=selected.length<1?'Выберите хотя бы один вид работы.':(required?'Для выбранных видов работ обязательно укажите существующую дверь или отметьте создание новой.':'Привязка к двери необязательна для выбранных видов работ.');doorInput.required=required&&!newDoor.checked;};", "логика подсказки двери"),
        (js_text, "newDoor.addEventListener('change',()=>{doorInput.disabled=newDoor.checked;doorInput.value=newDoor.checked?'':doorInput.value;updateDoorRule();});", "обработчик новой двери"),
        (js_text, "form.addEventListener('reset',()=>setTimeout(()=>{doorInput.disabled=false;updateDoorRule();completedInput.value=localDateTime(new Date());document.getElementById('file-list').textContent='Файлы не выбраны';},0));", "сброс формы"),
        (js_text, "const doorNumber=String(fd.get('door_number')||'').trim(),requiresDoor=selected.some(x=>x.dataset.doorRequired==='1');\n    if(requiresDoor&&!doorNumber&&fd.get('new_door')!=='on'){error.textContent='Для одного или нескольких выбранных видов работ необходимо указать дверь или создать новую.';return;}\n    if(doorNumber&&!/^D-\\d{6,12}$/i.test(doorNumber)){error.textContent='Номер двери должен иметь формат D-000123.';return;}\n    if(fd.get('new_door')==='on'&&doorNumber){error.textContent='Укажите номер существующей двери или отметьте новую дверь.';return;}\n    const data={quantity:fd.get('quantity')||'1',door_number:doorNumber,new_door:fd.get('new_door')==='on'?'true':'false',comment:String(fd.get('comment')||'').trim(),completed_at:completedDate.toISOString()};", "валидация и данные формы"),
        (js_text, "q.data.door_number|| (q.data.new_door==='true'?'Новая дверь':'Без двери')", "подпись двери в офлайн-очереди"),
        (php_text, "} elseif ($d['new_door']) {\n                    $number = $this->doors->nextNumber();\n                    $doorId = $this->doors->insert($number, $actorId);\n                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);\n                } elseif ((int) $type['door_required']) {\n                    throw new ApiException('DOOR_REQUIRED', 'Для этого вида работы нужна дверь: укажите номер двери или создайте новую (new_door=true)', 422);\n                }", "одиночная регистрация"),
        (php_text, "} elseif ($d['new_door']) {\n                    $number = $this->doors->nextNumber();\n                    $doorId = $this->doors->insert($number, $actorId);\n                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);\n                } elseif ($doorRequired) {\n                    throw new ApiException('DOOR_REQUIRED', 'Для одного или нескольких выбранных видов работ нужна дверь: укажите номер или создайте новую.', 422);\n                }", "пакетная регистрация"),
    ]
    for content, anchor, label in checks:
        if content.count(anchor) != 1:
            raise SystemExit(f"ОШИБКА: проверка «{label}»: ожидалось одно совпадение, найдено {content.count(anchor)}. Файлы не изменены.")

    # Frontend: одна новая регистрация = одна новая дверь; номер формирует сервер.
    replace_once(
        js,
        'data-door-required="${t.door_required?\'1\':\'0\'}"',
        '',
        "удалён атрибут door_required из списка видов работ",
    )
    replace_once(
        js,
        '<div class="field span-2"><label for="door_number">Номер двери</label><input id="door_number" name="door_number" placeholder="Например, D-000123" autocomplete="off" inputmode="text"><label class="checkline"><input id="new_door" name="new_door" type="checkbox"> Новая дверь — присвоить номер автоматически</label><div class="hint">Формат номера: D- и от 6 до 12 цифр. Если хотя бы один выбранный вид требует дверь, укажите её.</div></div>',
        '<div class="field span-2"><div class="hint" id="door-requirement">Для этой регистрации сервер автоматически создаст дверь и присвоит ей номер.</div></div>',
        "удалён блок ручного номера и чекбокса",
    )
    replace_once(
        js,
        "const doorInput=form.elements.door_number,newDoor=form.elements.new_door,completedInput=form.elements.completed_at_local;",
        "const completedInput=form.elements.completed_at_local;",
        "удалены ссылки на поля двери",
    )
    replace_once(
        js,
        "const updateDoorRule=()=>{const selected=selectedBoxes();const required=selected.some(x=>x.dataset.doorRequired==='1');document.getElementById('door-requirement').textContent=selected.length<1?'Выберите хотя бы один вид работы.':(required?'Для выбранных видов работ обязательно укажите существующую дверь или отметьте создание новой.':'Привязка к двери необязательна для выбранных видов работ.');doorInput.required=required&&!newDoor.checked;};",
        "const updateDoorRule=()=>{document.getElementById('door-requirement').textContent=selectedBoxes().length<1?'Выберите хотя бы один вид работы.':'Для выбранных видов работ будет автоматически создана одна дверь с новым номером.';};",
        "обновлена подсказка автоматической нумерации",
    )
    replace_once(
        js,
        "newDoor.addEventListener('change',()=>{doorInput.disabled=newDoor.checked;doorInput.value=newDoor.checked?'':doorInput.value;updateDoorRule();});\n",
        "",
        "удалён обработчик чекбокса",
    )
    replace_once(
        js,
        "form.addEventListener('reset',()=>setTimeout(()=>{doorInput.disabled=false;updateDoorRule();completedInput.value=localDateTime(new Date());document.getElementById('file-list').textContent='Файлы не выбраны';},0));",
        "form.addEventListener('reset',()=>setTimeout(()=>{updateDoorRule();completedInput.value=localDateTime(new Date());document.getElementById('file-list').textContent='Файлы не выбраны';},0));",
        "исправлен сброс формы",
    )
    replace_once(
        js,
        "const doorNumber=String(fd.get('door_number')||'').trim(),requiresDoor=selected.some(x=>x.dataset.doorRequired==='1');\n    if(requiresDoor&&!doorNumber&&fd.get('new_door')!=='on'){error.textContent='Для одного или нескольких выбранных видов работ необходимо указать дверь или создать новую.';return;}\n    if(doorNumber&&!/^D-\\d{6,12}$/i.test(doorNumber)){error.textContent='Номер двери должен иметь формат D-000123.';return;}\n    if(fd.get('new_door')==='on'&&doorNumber){error.textContent='Укажите номер существующей двери или отметьте новую дверь.';return;}\n    const data={quantity:fd.get('quantity')||'1',door_number:doorNumber,new_door:fd.get('new_door')==='on'?'true':'false',comment:String(fd.get('comment')||'').trim(),completed_at:completedDate.toISOString()};",
        "const data={quantity:fd.get('quantity')||'1',new_door:'true',comment:String(fd.get('comment')||'').trim(),completed_at:completedDate.toISOString()};",
        "убрана ручная валидация номера; включено автоматическое создание",
    )
    replace_once(
        js,
        "q.data.door_number|| (q.data.new_door==='true'?'Новая дверь':'Без двери')",
        "q.data.door_number||'Номер будет присвоен автоматически'",
        "обновлена подпись в офлайн-очереди",
    )

    # Backend: переданный старой версией номер по-прежнему означает привязку
    # к существующей двери. Если номера нет — всегда создаём новую.
    single_old = "} elseif ($d['new_door']) {\n                    $number = $this->doors->nextNumber();\n                    $doorId = $this->doors->insert($number, $actorId);\n                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);\n                } elseif ((int) $type['door_required']) {\n                    throw new ApiException('DOOR_REQUIRED', 'Для этого вида работы нужна дверь: укажите номер двери или создайте новую (new_door=true)', 422);\n                }"
    single_new = "} else {\n                    $number = $this->doors->nextNumber();\n                    $doorId = $this->doors->insert($number, $actorId);\n                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);\n                }"
    replace_once(service, single_old, single_new, "сервер всегда создаёт дверь при одиночной регистрации без номера")

    batch_old = "} elseif ($d['new_door']) {\n                    $number = $this->doors->nextNumber();\n                    $doorId = $this->doors->insert($number, $actorId);\n                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);\n                } elseif ($doorRequired) {\n                    throw new ApiException('DOOR_REQUIRED', 'Для одного или нескольких выбранных видов работ нужна дверь: укажите номер или создайте новую.', 422);\n                }"
    batch_new = "} else {\n                    $number = $this->doors->nextNumber();\n                    $doorId = $this->doors->insert($number, $actorId);\n                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);\n                }"
    replace_once(service, batch_old, batch_new, "сервер всегда создаёт одну дверь на пакетную регистрацию")

    print("\nПАТЧ ПРИМЕНЁН.")
    print("Следующий шаг: пересобрать doors-app и проверить синтаксис PHP/JS.")
    print("Резервные копии не создавались. Миграции и данные БД не изменялись.")

if __name__ == "__main__":
    main()
