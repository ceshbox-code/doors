# DOOR для Android (Trusted Web Activity)

## Выбранная архитектура

Для существующей DOOR PWA используется Android Trusted Web Activity (TWA), собираемая через Google Chrome Labs Bubblewrap.

TWA открывает сайт DOOR в полноэкранном Chrome без адресной строки после проверки Digital Asset Links. Это предпочтительнее производственного Capacitor WebView с `server.url`: Capacitor прямо помечает этот параметр как предназначенный для live reload, не для production.

TWA сохраняет реальный HTTPS origin сайта и использует тот же Chrome-профиль:

- PHP-сессия `DOORSESS` и CSRF остаются same-origin.
- IndexedDB `door-offline-v1` и фотографии Blob остаются в том же origin.
- Service Worker DOOR продолжает кэшировать оболочку PWA.
- Очередь работ отправляется существующим API с `Idempotency-Key`; локальные записи и фотографии удаляются только после подтверждения сервера.
- Роли и разрешения не переносятся в Android: их по-прежнему проверяет сервер.

## Офлайн-ограничения

- Первый запуск, первый вход и установка Service Worker требуют подключения к серверу.
- После успешной первой загрузки и входа оболочка PWA может открываться из Service Worker cache.
- Работы и фотографии можно сохранять в IndexedDB без сети; отправка на сервер выполняется после восстановления связи и повторной авторизации.
- Офлайн-вход не поддерживается: для синхронизации нужна действующая серверная сессия того же пользователя.
- Chrome должен быть установлен на устройстве Android. Если TWA-проверка домена не пройдёт, Chrome откроет сайт как Custom Tab с интерфейсом браузера.

## Что уже подготовлено в репозитории

- Добавлена PNG-иконка 512×512 для Android/PWA: `public/assets/door-512.png`.
- Обновлён `public/manifest.json`: добавлена PNG-иконка, существующая SVG-иконка сохранена.

## Что нужно для генерации APK

Нужен публичный HTTPS URL DOOR, открывающий приложение в корне домена, например `https://door.example.com/`. Сервер должен отдавать `/manifest.json`, `/sw.js`, `/api/v1/*` и статические файлы с этого же origin.

На Windows/Git Bash после публикации обновлённого DOOR:

```bash
npm install -g @bubblewrap/cli
bubblewrap init --manifest="https://YOUR-DOOR-DOMAIN/manifest.json"
```

В мастере Bubblewrap проверьте:
- Application ID: `com.ceshbox.doors`
- App name: `DOOR`
- Host: HTTPS-домен установленного DOOR
- Start URL: `/`
- Launcher icon: `/assets/door-512.png`

Bubblewrap создаст Android-проект и конфигурацию `twa-manifest.json`. Затем:

```bash
bubblewrap build
```

Результат сборки: `app-release-signed.apk`. Сгенерированный keystore — ключ обновления приложения. Храните его отдельно от GitHub и не теряйте; не добавляйте файл ключа и пароли в репозиторий.

## Digital Asset Links

Для полноэкранного TWA сайт должен подтвердить владение Android-приложением. После сборки возьмите SHA-256 fingerprint сертификата используемого keystore и разместите на сервере файл:

`/.well-known/assetlinks.json`

Структура файла:

```json
[
  {
    "relation": ["delegate_permission/common.handle_all_urls"],
    "target": {
      "namespace": "android_app",
      "package_name": "com.ceshbox.doors",
      "sha256_cert_fingerprints": ["REPLACE_WITH_REAL_SHA256_FINGERPRINT"]
    }
  }
]
```

Значение fingerprint нельзя угадывать или брать от другого keystore. Оно должно соответствовать сертификату, которым подписан распространяемый APK. После размещения проверьте файл по HTTPS и выполните проверку TWA на Android.

## Распространение

Для установки напрямую сотрудникам используется подписанный APK `app-release-signed.apk`. Перед массовой установкой нужно проверить вход под всеми используемыми ролями, фото с камеры, работу в авиарежиме, сохранность очереди после закрытия приложения и автоматическую синхронизацию после восстановления сети.

Для выпуска обновлений используйте тот же Application ID и тот же signing keystore.
