# omni-market.ru

Сайт-визитка Omni Market (ООО «ПАРСЕК-Н»).

- `index.html` — главная страница
- `privacy.html` — политика обработки персональных данных
- `consent.html` — согласие на обработку персональных данных
- `logos/` — логотипы брендов

При каждом изменении ветки `main` GitHub Actions заливает файлы на хостинг reg.ru
в папку `www/omni-market.ru/` (см. `.github/workflows/deploy.yml`).
Доступы FTP хранятся в секретах репозитория: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
