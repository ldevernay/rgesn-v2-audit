#!/usr/bin/env bash
#
# Régénère le sous-ensemble de Bootstrap Icons (assets/vendor/bootstrap-icons/)
# à partir des classes bi-xxx réellement utilisées dans le code PHP/JS.
#
# À relancer chaque fois qu'une nouvelle icône Bootstrap est utilisée dans le
# projet — sinon son glyphe sera absent de la police et rien ne s'affichera.
#
# Prérequis (outils de développement, pas des dépendances de l'application) :
#   - python3 + pip : `pip install fonttools brotli`
#   - Le paquet npm complet bootstrap-icons, pour disposer de la police
#     ORIGINALE complète à sous-ensembler (la police déjà sous-ensemblée dans
#     assets/vendor/ ne contient QUE les icônes actuelles — on ne peut pas
#     repartir d'elle pour en ajouter de nouvelles).
#
# Usage :
#   npm install --no-save bootstrap-icons@1.11.3   # récupère la police complète
#   bash bin/rebuild-icon-subset.sh ./node_modules/bootstrap-icons

set -euo pipefail

SOURCE_DIR="${1:-}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR_DIR="$PROJECT_ROOT/assets/vendor/bootstrap-icons"

if [ -z "$SOURCE_DIR" ] || [ ! -f "$SOURCE_DIR/font/fonts/bootstrap-icons.woff2" ]; then
    echo "Usage : bash bin/rebuild-icon-subset.sh <dossier du paquet bootstrap-icons complet>" >&2
    echo "Exemple : npm install --no-save bootstrap-icons@1.11.3 && bash bin/rebuild-icon-subset.sh ./node_modules/bootstrap-icons" >&2
    exit 1
fi

if ! command -v pyftsubset >/dev/null 2>&1; then
    echo "Erreur : pyftsubset introuvable. Installez fonttools : pip install fonttools brotli" >&2
    exit 1
fi

echo "1/4 — Recherche des classes bi-xxx utilisées dans le code..."
USED_ICONS_FILE="$(mktemp)"
grep -rhoE 'bi-[a-z0-9-]+' --include="*.php" --include="*.js" "$PROJECT_ROOT" \
    | sort -u > "$USED_ICONS_FILE"
echo "   $(wc -l < "$USED_ICONS_FILE") icônes distinctes trouvées."

echo "2/4 — Extraction des codepoints Unicode correspondants..."
MAPPING_FILE="$(mktemp)"
CODEPOINTS_FILE="$(mktemp)"
python3 - "$SOURCE_DIR/font/bootstrap-icons.css" "$USED_ICONS_FILE" "$MAPPING_FILE" "$CODEPOINTS_FILE" << 'PYEOF'
import re, sys

source_css, used_file, mapping_file, codepoints_file = sys.argv[1:5]

with open(source_css, encoding='utf-8') as f:
    css = f.read()

with open(used_file, encoding='utf-8') as f:
    used = set(line.strip() for line in f if line.strip())

pattern = re.compile(r'\.(bi-[a-z0-9-]+)::before\s*\{\s*content:\s*"\\([0-9a-fA-F]+)"')
mapping = dict(pattern.findall(css))

missing = used - mapping.keys()
if missing:
    print(f"ATTENTION : {len(missing)} classe(s) introuvable(s) dans la police source : {sorted(missing)}", file=sys.stderr)

resolved = {c: mapping[c] for c in used if c in mapping}

with open(mapping_file, 'w', encoding='utf-8') as f:
    for c in sorted(resolved):
        f.write(f"{c} {resolved[c]}\n")

codepoints = sorted(set(int(cp, 16) for cp in resolved.values()))
with open(codepoints_file, 'w') as f:
    f.write(','.join(f"U+{cp:04X}" for cp in codepoints))

print(f"   {len(resolved)} icônes résolues sur {len(used)} utilisées.")
PYEOF

CODEPOINTS="$(cat "$CODEPOINTS_FILE")"

echo "3/4 — Sous-ensemblage de la police (woff2 + woff)..."
mkdir -p "$VENDOR_DIR/fonts"
pyftsubset "$SOURCE_DIR/font/fonts/bootstrap-icons.woff2" \
    --unicodes="$CODEPOINTS" --flavor=woff2 --no-layout-closure \
    --output-file="$VENDOR_DIR/fonts/bootstrap-icons.woff2"
pyftsubset "$SOURCE_DIR/font/fonts/bootstrap-icons.woff" \
    --unicodes="$CODEPOINTS" --flavor=woff --no-layout-closure \
    --output-file="$VENDOR_DIR/fonts/bootstrap-icons.woff"

echo "4/4 — Génération du CSS réduit..."
python3 - "$MAPPING_FILE" "$VENDOR_DIR/bootstrap-icons.min.css" << 'PYEOF'
import sys

mapping_file, output_css = sys.argv[1:3]

mapping = {}
with open(mapping_file, encoding='utf-8') as f:
    for line in f:
        cls, cp = line.strip().split()
        mapping[cls] = cp

rules = "".join(f'.{cls}::before{{content:"\\{cp}"}}' for cls, cp in sorted(mapping.items()))

css = (
    "/*!\n"
    " * Bootstrap Icons — sous-ensemble généré automatiquement par bin/rebuild-icon-subset.sh\n"
    f" * Contient uniquement les {len(mapping)} icônes effectivement utilisées dans ce projet\n"
    " * (au lieu des ~2050 icônes du jeu complet). Licence MIT — https://icons.getbootstrap.com/\n"
    " */\n"
    "@font-face{"
    "font-display:block;"
    'font-family:"bootstrap-icons";'
    'src:url("./fonts/bootstrap-icons.woff2") format("woff2"),'
    'url("./fonts/bootstrap-icons.woff") format("woff");'
    "}"
    '.bi::before,[class^="bi-"]::before,[class*=" bi-"]::before{'
    "display:inline-block;"
    "font-family:bootstrap-icons!important;"
    "font-style:normal;"
    "font-weight:normal!important;"
    "font-variant:normal;"
    "text-transform:none;"
    "line-height:1;"
    "vertical-align:-.125em;"
    "-webkit-font-smoothing:antialiased;"
    "-moz-osx-font-smoothing:grayscale;"
    "}"
    + rules
)

with open(output_css, 'w', encoding='utf-8') as f:
    f.write(css)

print(f"   CSS écrit : {len(css)} octets, {len(mapping)} règles.")
PYEOF

rm -f "$USED_ICONS_FILE" "$MAPPING_FILE" "$CODEPOINTS_FILE"

echo ""
echo "Terminé. Fichiers mis à jour :"
echo "  - $VENDOR_DIR/bootstrap-icons.min.css"
echo "  - $VENDOR_DIR/fonts/bootstrap-icons.woff2"
echo "  - $VENDOR_DIR/fonts/bootstrap-icons.woff"
echo ""
echo "Pensez à vérifier visuellement les pages avant de committer."
