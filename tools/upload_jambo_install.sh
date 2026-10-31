#!/bin/bash
# Run on the BlueHost server by upload_jambo.bat.
# Installs the Jambo PDFs uploaded to ~/jambo_upload into the website folder public_html/pdf
# (www.savannahexplorers.com/pdf/), keeping a backup of the replaced files.
cd ~ || exit 1
DST=public_html/pdf
if [ ! -d "$DST" ]; then
  echo "Folder $DST not found. Jambo PDFs on the server:"; find ~ -maxdepth 4 -iname 'Jambo*.pdf' 2>/dev/null; exit 1
fi
STAMP=$(date +%Y%m%d)
mkdir -p "$DST/_old_jambo_$STAMP"
inst() { # $1 = uploaded file, $2 = name on the website
  if [ ! -f "jambo_upload/$1" ]; then echo "MISSING upload: $1"; return; fi
  if [ -f "$DST/$2" ]; then
    [ -f "$DST/_old_jambo_$STAMP/$2" ] || cp -p "$DST/$2" "$DST/_old_jambo_$STAMP/$2"
    echo "UPDATED: $2"
  else
    echo "NEW: $2"
  fi
  cp "jambo_upload/$1" "$DST/$2"
}
# Same names as in Dropbox
inst "Jambo da Savannah Explorers IT.pdf"       "Jambo da Savannah Explorers IT.pdf"
inst "Jambo from Savannah Explorers.pdf"         "Jambo from Savannah Explorers.pdf"
inst "Jambo da Savannah Explorers FR.pdf"       "Jambo da Savannah Explorers FR.pdf"
inst "Jambo da Savannah Explorers SPA.pdf"      "Jambo da Savannah Explorers SPA.pdf"
inst "Jambo da Savannah Explorers KENYA IT.pdf" "Jambo da Savannah Explorers KENYA IT.pdf"
inst "Jambo from Savannah Explorers KENYA.pdf"   "Jambo from Savannah Explorers KENYA.pdf"
# The email signatures link the English one as "...EN.pdf"
inst "Jambo from Savannah Explorers.pdf"         "Jambo from Savannah Explorers EN.pdf"
# Wrong name created by the first run (25 Oct 2026): not linked anywhere
if [ -f "$DST/Jambo from Savannah Explorers KENYA EN.pdf" ]; then
  rm "$DST/Jambo from Savannah Explorers KENYA EN.pdf" && echo "REMOVED: Jambo from Savannah Explorers KENYA EN.pdf (wrong name)"
fi
rm -rf jambo_upload
echo; ls -la "$DST" | grep -i jambo
