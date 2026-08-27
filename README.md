# majordomo-dev_hvac

Модуль [MajorDoMo](https://github.com/sergejey/majordomo) для управления кондиционерами
и тепловыми насосами Gree и Cooper&Hunter через встроенные Wi-Fi контроллеры.

Форк [indimouse/majordomo-dev_hvac](https://github.com/indimouse/majordomo-dev_hvac)
с исправлениями совместимости с PHP 8 и поддержкой второй версии протокола Gree.
Перечень изменений — в [CHANGELOG.md](CHANGELOG.md).

## Поддерживаемые устройства

- кондиционеры Gree
- кондиционеры Cooper&Hunter с Wi-Fi контроллерами Gree
- кондиционеры Cooper&Hunter с Wi-Fi контроллерами Smart Wi-Fi

## Требования

- MajorDoMo
- PHP 7.0 – 8.4, расширения `sockets`, `openssl`, `json`
- MySQL 5.7+ / MariaDB 10.2+

## Установка

Скачайте репозиторий и запустите установщик — он сделает резервную копию текущей
версии модуля, скопирует файлы, выставит владельца и права как у остальных файлов
MajorDoMo и проверит синтаксис:

```
sudo sh install-dev_hvac.sh                     # корень MajorDoMo ищется автоматически
sudo sh install-dev_hvac.sh /var/www/majordomo  # или указать его явно
```

Либо скопируйте каталоги `modules/`, `templates/`, `img/` и `scripts/` в корень
MajorDoMo вручную, сохраняя структуру.

Дальше:

1. Откройте панель управления → **Устройства** → **HVAC**. При первом открытии
   модуль создаст свои таблицы и добавит недостающие колонки.
2. Нажмите **Сканировать устройства** и добавьте найденные кондиционеры.
3. На вкладке **Данные** свяжите нужные свойства с объектами MajorDoMo.
4. Убедитесь, что запущен цикл опроса `scripts/cycle_dev_hvac.php`. Его поднимает
   менеджер потоков `cycle.php`; если цикл не стартовал, выполните в консоли
   MajorDoMo `setGlobal('cycle_dev_hvacControl', 'restart');` и подождите минуту.

Цикл завершается сразу и без ошибки, если в модуле не заведено ни одного
устройства — опрашивать нечего. В панели это выглядит как «Цикл остановлен».

## Обновление с версии 0.1

Схема базы и имена свойств не менялись, обновление ставится поверх существующей
установки, привязки объектов сохраняются. Колонка `ENCRYPTION` добавляется
автоматически при первом открытии модуля.

Если в вашей установке в таблице `dev_hvac_commands` отсутствует колонка `VALUE`,
это последствие ошибки версии 0.1 (MySQL запрещает `DEFAULT ''` для `TEXT`).
Колонка будет создана при первом открытии модуля.

## Протоколы

| Устройство | Транспорт | Шифрование |
|---|---|---|
| Cooper&Hunter Smart Wi-Fi (devtype `0x202`) | обнаружение UDP 12414, обмен TCP 12416 | нет |
| Gree (devtype `0x2711`), протокол v1 | UDP 7000 | AES-128-ECB |
| Gree (devtype `0x2711`), протокол v2 | UDP 7000 | AES-128-GCM |

Протокол v2 используется прошивками Gree и Cooper&Hunter примерно с 2021 года.
Версия определяется автоматически: при сканировании — по наличию поля `tag` в
ответе устройства, при привязке — перебором двух вариантов. Вручную режим
переключается в карточке устройства («Протокол Gree»).

## Свойства устройств

**Cooper&Hunter Smart Wi-Fi:** `power`, `ac_mode`, `temperature`, `fan_speed`,
`fan_direction`, `quiet`, `light`, `health`, `sleep`, `eco`, `dry`, `timing`,
`energy_save`, `wdnumber_mode`, `temptype`, `stepless_max`, `indoorTemperature`.

**Gree:** `power`, `ac_mode`, `temperature`, `fan_speed`, `fan_direction` (SwUpDn),
`fan_directionh` (SwingLfRig), `quiet`, `turbo`, `air`, `blow`, `light`, `health`,
`sleep`, `stht`, `energy_save`, `temptype`, `heatcooltype`, `temrec`.

Свойства, доступные только на чтение (`indoorTemperature`, `dry`, `timing`,
`wdnumber_mode`, `heatcooltype`, `temrec`), при записи игнорируются.

## Если что-то не работает

- **Устройство не находится при сканировании.** Кондиционер и MajorDoMo должны быть
  в одной подсети. Широковещательный поиск не проходит через гостевую Wi-Fi сеть и
  не работает при включённой изоляции клиентов на точке доступа.
- **Устройство найдено, но привязка не удаётся.** Обычно кондиционер уже привязан к
  облаку приложения Gree или EWPE Smart. Сбросьте настройки Wi-Fi с пульта и
  настройте модуль заново.
- **Свойства не обновляются.** Проверьте, что у устройства задан интервал опроса
  (не «none») и что цикл `cycle_dev_hvac` запущен.

## Благодарности

Исходный модуль — [indimouse](https://github.com/indimouse/majordomo-dev_hvac).
Протокол Gree разобран по открытым реализациям
[tomikaa87/gree-remote](https://github.com/tomikaa87/gree-remote) и
[cmroche/greeclimate](https://github.com/cmroche/greeclimate).
