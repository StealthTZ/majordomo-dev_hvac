#!/bin/sh
# ---------------------------------------------------------------------------
# Установка модуля dev_hvac 0.2 в существующую установку MajorDoMo.
#
#   sudo sh install-dev_hvac.sh                       # найти корень автоматически
#   sudo sh install-dev_hvac.sh /var/www/majordomo    # указать корень явно
#
# Скрипт делает резервную копию текущих файлов модуля, копирует новые и
# выставляет владельца и права такими же, как у соседних файлов MajorDoMo.
# Базу данных он не трогает: новая колонка ENCRYPTION добавляется самим
# модулем при первом открытии в панели управления.
# ---------------------------------------------------------------------------
set -e

SRC=$(cd "$(dirname "$0")" && pwd)
ROOT="$1"

say() { printf '%s\n' "$*"; }
die() { printf 'Ошибка: %s\n' "$*" >&2; exit 1; }

# --- проверка, что рядом лежат файлы модуля --------------------------------
if [ ! -f "$SRC/modules/dev_hvac/dev_hvac.class.php" ]; then
    die "рядом со скриптом нет modules/dev_hvac/. Распакуйте dev_hvac-0.2.tar.gz и запускайте скрипт из полученного каталога."
fi

# --- поиск корня MajorDoMo -------------------------------------------------
if [ -z "$ROOT" ]; then
    for candidate in /var/www/majordomo /var/www/html /var/www /home/pi/majordomo /opt/majordomo; do
        if [ -f "$candidate/config.php" ] && [ -f "$candidate/lib/loader.php" ]; then
            ROOT="$candidate"
            break
        fi
    done
fi
[ -n "$ROOT" ] || die "не удалось найти корень MajorDoMo. Укажите его аргументом: sh install-dev_hvac.sh /путь/к/majordomo"

[ -f "$ROOT/config.php" ] && [ -f "$ROOT/lib/loader.php" ] && [ -d "$ROOT/modules" ] \
    || die "$ROOT не похож на корень MajorDoMo (нет config.php, lib/loader.php или modules/)."

say "Корень MajorDoMo: $ROOT"

# --- версия PHP ------------------------------------------------------------
if command -v php >/dev/null 2>&1; then
    say "PHP (CLI):        $(php -r 'echo PHP_VERSION;')"
    for ext in sockets openssl json; do
        php -r "exit(extension_loaded('$ext') ? 0 : 1);" \
            || say "ВНИМАНИЕ: расширение PHP '$ext' не загружено — модуль без него не заработает."
    done
else
    say "ВНИМАНИЕ: php в PATH не найден, проверку версии пропускаю."
fi

# --- резервная копия -------------------------------------------------------
STAMP=$(date +%Y%m%d-%H%M%S)
# каталог для копии выбираем вне веб-корня, чтобы архив не раздавался наружу
BACKUP_DIR=""
for d in /var/backups "$(dirname "$ROOT")" "$HOME"; do
    if [ -d "$d" ] && [ -w "$d" ]; then BACKUP_DIR="$d"; break; fi
done
[ -n "$BACKUP_DIR" ] || BACKUP_DIR="$ROOT"
BACKUP="$BACKUP_DIR/dev_hvac-backup-$STAMP.tar.gz"
EXISTING=""
for p in modules/dev_hvac templates/dev_hvac img/dev_hvac img/modules/dev_hvac.png scripts/cycle_dev_hvac.php; do
    [ -e "$ROOT/$p" ] && EXISTING="$EXISTING $p"
done

if [ -n "$EXISTING" ]; then
    # shellcheck disable=SC2086
    tar -czf "$BACKUP" -C "$ROOT" $EXISTING
    say "Резервная копия:  $BACKUP"
else
    say "Резервная копия:  не требуется, модуль ещё не установлен"
fi

# --- у кого забирать владельца и права -------------------------------------
REF="$ROOT/lib/loader.php"

copy_tree() {
    src="$1"; dst="$2"
    mkdir -p "$dst"
    cp -R "$src/." "$dst/"
}

copy_tree "$SRC/modules/dev_hvac"   "$ROOT/modules/dev_hvac"
copy_tree "$SRC/templates/dev_hvac" "$ROOT/templates/dev_hvac"
copy_tree "$SRC/img/dev_hvac"       "$ROOT/img/dev_hvac"
mkdir -p "$ROOT/img/modules" "$ROOT/scripts"
cp "$SRC/img/modules/dev_hvac.png"  "$ROOT/img/modules/dev_hvac.png"
cp "$SRC/scripts/cycle_dev_hvac.php" "$ROOT/scripts/cycle_dev_hvac.php"

say "Файлы скопированы."

# --- владелец и права ------------------------------------------------------
if [ -e "$REF" ]; then
    OWNER=$(stat -c '%U:%G' "$REF" 2>/dev/null || echo "")
    if [ -n "$OWNER" ]; then
        for p in modules/dev_hvac templates/dev_hvac img/dev_hvac img/modules/dev_hvac.png scripts/cycle_dev_hvac.php; do
            chown -R "$OWNER" "$ROOT/$p" 2>/dev/null || true
        done
        say "Владелец:         $OWNER"
    fi
fi
# MajorDoMo сам дописывает файлы в modules/ и templates/ (install-linux.sh ставит 777/666)
find "$ROOT/modules/dev_hvac" "$ROOT/templates/dev_hvac" -type d -exec chmod 777 {} \; 2>/dev/null || true
find "$ROOT/modules/dev_hvac" "$ROOT/templates/dev_hvac" -type f -exec chmod 666 {} \; 2>/dev/null || true
chmod 644 "$ROOT/scripts/cycle_dev_hvac.php" 2>/dev/null || true

# --- синтаксическая проверка ----------------------------------------------
if command -v php >/dev/null 2>&1; then
    for f in "$ROOT"/modules/dev_hvac/*.php "$ROOT"/scripts/cycle_dev_hvac.php; do
        php -l "$f" >/dev/null || die "файл $f не прошёл проверку синтаксиса"
    done
    say "Синтаксис:        проверен"
fi

say ""
say "Готово. Дальше:"
say "  1. Откройте панель управления -> Устройства -> HVAC."
say "     При первом открытии в таблицу dev_hvac_devices добавится колонка ENCRYPTION."
say "  2. Перезапустите цикл опроса: Панель управления -> Система -> Циклы ->"
say "     cycle_dev_hvac -> перезапустить. Либо перезапустите cycle.php целиком."
say "  3. Проверьте, что устройства опрашиваются: в списке HVAC обновляется"
say "     колонка времени, а вверху горит 'Цикл запущен'."
say ""
say "Откатиться, если что-то пошло не так:"
if [ -n "$EXISTING" ]; then
    say "  sudo tar -xzf $BACKUP -C $ROOT"
else
    say "  sudo rm -rf $ROOT/modules/dev_hvac $ROOT/templates/dev_hvac $ROOT/scripts/cycle_dev_hvac.php"
fi
