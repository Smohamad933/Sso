#!/usr/bin/env bash
#
# ساخت بسته‌ی آماده‌ی سورس (sso-source.zip)
#
#   bash tools/build-source-zip.sh
#
# خروجی در ریشه‌ی پروژه نوشته می‌شود. محتوا دقیقاً همان چیزی است که در
# آخرین commit مخزن است: بدون .git، بدون node_modules و بدون خودِ فایل زیپ.
# خطوطِ جدیدِ فایل‌های متنی طبق .gitattributes همیشه LF هستند.

set -euo pipefail

cd "$(dirname "$0")/.."

OUTPUT="sso-source.zip"

# اگر تغییرِ commitنشده‌ای وجود دارد، هشدار بده (در بسته نمی‌آید)
if [ -n "$(git status --porcelain)" ]; then
  echo "هشدار: تغییرات commitنشده وجود دارد و در بسته نخواهد بود." >&2
  echo "       بهتر است ابتدا آن‌ها را commit کنید." >&2
  # فقط در حالت تعاملی منتظر تأیید می‌مانیم (در CI/اسکریپت‌ها ادامه می‌دهد)
  if [ -t 0 ]; then
    echo "       برای ادامه Enter بزنید..." >&2
    read -r _
  fi
fi

rm -f "$OUTPUT"
git archive --format=zip --output="$OUTPUT" HEAD

SIZE=$(du -h "$OUTPUT" | cut -f1)
COUNT=$(python3 -c "import zipfile,sys; print(len(zipfile.ZipFile(sys.argv[1]).namelist()))" "$OUTPUT" 2>/dev/null \
        || unzip -l "$OUTPUT" | tail -1 | awk '{print $2}')

echo "ساخته شد: $OUTPUT ($SIZE، $COUNT ورودی)"
echo "لینک دانلود پس از push:"
echo "  https://raw.githubusercontent.com/Smohamad933/Sso/$(git rev-parse --abbrev-ref HEAD)/sso-source.zip"
