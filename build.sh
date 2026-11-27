#!/bin/bash
# StreamViewer - build.sh
# Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3

PLUGIN_NAME="streamviewer"
AUTHOR="Lazaros Chalkidis"
GIT_URL="https://github.com/Lazaros-Chalkidis/unraid-streamviewer"
PACKAGE_DIR_FINAL="packages"
PACKAGE_DIR_TEMP="package-temp"

BASE_VERSION=$(date +'%Y.%m.%d')
LETTER_SUFFIX="${1}"
STAGE_INPUT="${2}"
LOCAL_INSTALL="${3:-}"

STAGE_SUFFIX=""
if [[ -n "$STAGE_INPUT" && "$STAGE_INPUT" != "release" ]]; then
    STAGE_SUFFIX="-${STAGE_INPUT}"
fi
VERSION="${BASE_VERSION}${LETTER_SUFFIX}${STAGE_SUFFIX}"

if [[ "$LOCAL_INSTALL" == "local" ]]; then
    BRANCH="local"
    PLUGIN_URL_STRUCTURE=""
    CHANGES_TEXT="- Local build (embedded package; no URL download)."
elif [[ "$STAGE_INPUT" == "dev" ]]; then
    BRANCH="dev"
    PLUGIN_URL_STRUCTURE="&gitURL;/raw/&branch;/packages/&name;-&version;.txz"
    CHANGES_TEXT="- Development build from the 'dev' branch. For testing only."
else
    BRANCH="main"
    PLUGIN_URL_STRUCTURE="&gitURL;/releases/download/&version;/&name;-&version;.txz"
    CHANGES_TEXT="- Automated release build."
fi

CHANGELOG_MD_FILE="CHANGELOG.md"
if [[ -f "$CHANGELOG_MD_FILE" ]]; then
    CHANGES_BLOCK="$(cat "$CHANGELOG_MD_FILE")"
else
    CHANGES_BLOCK="### ${VERSION}
${CHANGES_TEXT}"
fi
CHANGES_BLOCK="${CHANGES_BLOCK//]]>/]]]]><![CDATA[>}"

RELEASE_VERSION="${BASE_VERSION}${LETTER_SUFFIX}"
CHANGELOG_TOP=""
if [[ -f "$CHANGELOG_MD_FILE" ]]; then
    CHANGELOG_TOP=$(grep -m1 -E '^#{1,3}[[:space:]]+v?[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?[[:space:]]*$' "$CHANGELOG_MD_FILE" \
                    | sed -E 's/^#+[[:space:]]+v?//; s/[[:space:]]+$//')
fi

if [[ "$CHANGELOG_TOP" != "$RELEASE_VERSION" ]]; then
    if [[ "$BRANCH" == "main" ]]; then
        echo "Build stopped."
        echo "  CHANGELOG.md top entry : ${CHANGELOG_TOP:-none found}"
        echo "  Version being built    : ${RELEASE_VERSION}"
        echo "  Add the ${RELEASE_VERSION} entry at the top of CHANGELOG.md, then build again."
        exit 1
    fi
    echo "Warning: CHANGELOG.md top entry is '${CHANGELOG_TOP:-none found}', building ${VERSION} anyway (${BRANCH} build)."
fi

echo "=============================================="
echo " StreamViewer build"
echo " Version : ${VERSION}"
echo " Branch  : ${BRANCH}"
echo "=============================================="

rm -rf "${PACKAGE_DIR_TEMP}" "${PACKAGE_DIR_FINAL}"
mkdir -p "${PACKAGE_DIR_TEMP}" "${PACKAGE_DIR_FINAL}"

PLUGIN_DEST="${PACKAGE_DIR_TEMP}/usr/local/emhttp/plugins/${PLUGIN_NAME}"
mkdir -p "${PLUGIN_DEST}"
cp -R source/* "${PLUGIN_DEST}/"

echo "${VERSION}" > "${PLUGIN_DEST}/VERSION"

cat > "${PLUGIN_DEST}/branch.meta" << METAEOF
BRANCH="${BRANCH}"
IS_MAIN_BRANCH=$([[ "$BRANCH" == "main" ]] && echo "1" || echo "0")
METAEOF

find "${PLUGIN_DEST}" -type d                          -exec chmod 755 {} \;
find "${PLUGIN_DEST}" -type f                          -exec chmod 644 {} \;
find "${PLUGIN_DEST}" -name "*.sh"                     -exec chmod 755 {} \;
find "${PLUGIN_DEST}/event" -type f                    -exec chmod 755 {} \; 2>/dev/null

FILENAME="${PLUGIN_NAME}-${VERSION}"
PACKAGE_PATH="${PACKAGE_DIR_FINAL}/${FILENAME}.txz"

echo "Creating package: ${FILENAME}.txz ..."
if tar --version 2>/dev/null | grep -q GNU; then
    TAR_OWNER=(--owner=0 --group=0 --numeric-owner)
else
    TAR_OWNER=(--uid 0 --gid 0 --numeric-owner)
fi
tar "${TAR_OWNER[@]}" -C "${PACKAGE_DIR_TEMP}" -cJf "${PACKAGE_PATH}" usr

if [[ ! -f "${PACKAGE_PATH}" ]]; then
    echo "Package creation failed."
    exit 1
fi
echo "Package: $(du -h "${PACKAGE_PATH}" | cut -f1)  ->  ${PACKAGE_PATH}"

if command -v md5sum &>/dev/null; then
    PACKAGE_MD5="$(md5sum "${PACKAGE_PATH}" | cut -d' ' -f1)"
elif command -v md5 &>/dev/null; then
    PACKAGE_MD5="$(md5 -q "${PACKAGE_PATH}")"
else
    echo "Warning: md5sum/md5 not found, MD5 will be empty in the plg."
    PACKAGE_MD5=""
fi
echo "MD5: ${PACKAGE_MD5}"

b64_nolf() {
    if base64 --help 2>/dev/null | grep -q -- "-w"; then
        base64 -w 0 "$1"
    else
        base64 "$1" | tr -d '\n'
    fi
}

read -r -d '' DEFAULT_CFG << 'CFGEOF'
SERVER1_ENABLED="0"
SERVER1_TYPE=""
SERVER1_NAME=""
SERVER1_URL=""
SERVER1_TOKEN=""
SERVER2_ENABLED="0"
SERVER2_TYPE=""
SERVER2_NAME=""
SERVER2_URL=""
SERVER2_TOKEN=""
REFRESH_ENABLED="1"
REFRESH_INTERVAL="30"
WIDGET_MAX_STREAMS="10"
WIDGET_SHOW_DEVICE="1"
WIDGET_SHOW_IP="1"
WIDGET_SHOW_PROGRESS="1"
WIDGET_SHOW_QUALITY="1"
WIDGET_SHOW_TRANSCODE="1"
WIDGET_SHOW_DETAILS="1"
WIDGET_SHOW_DOCKER="1"
TOOL_ALLOW_KILL="0"
VERIFY_SSL="0"
STATS_ENABLED="0"
STATS_DB_PATH="/mnt/user/appdata/Stream-Viewer"
STATS_RETENTION_DAYS="90"
STATS_MERGE_WINDOW_MIN="60"
STATS_ANONYMIZE_IP="0"
STATS_GEO_LOOKUP="0"
LIB_SECTIONS_OPEN="0"
CFGEOF

PLG_DESCRIPTION="A real-time media stream monitor and statistics tracker for Plex, Jellyfin and Emby. View active streams directly from the dashboard, and track detailed watch history through a dedicated statistics page."

PLG_OLD_PACKAGES='[ -f /var/lib/pkgtools/packages/&name;-&version; ] || exit 0
STAMP=$(date +%Y-%m-%d,%T)
for pkg in /var/lib/pkgtools/packages/&name;-[0-9]*; do
  if [ ! -f "$pkg" ] || [ "${pkg##*/}" = "&name;-&version;" ]; then continue; fi
  mv "$pkg" "$pkg-upgraded-$STAMP" || continue
  removepkg "${pkg##*/}-upgraded-$STAMP" | grep -v "Skipping\.\|Removing files:"
done
for txz in /boot/config/plugins/&name;/&name;-[0-9]*.txz; do
  if [ ! -f "$txz" ] || [ "${txz##*/}" = "&name;-&version;.txz" ]; then continue; fi
  if rm -f "$txz"; then echo "Removed old package ${txz##*/}"; else echo "Could not remove old package ${txz##*/}"; fi
done'

PLG_DEFAULT_CFG='CFG=/boot/config/plugins/&name;/&name;.cfg
if [ ! -f "$CFG" ]; then
  mkdir -p /boot/config/plugins/&name;
  cat > "$CFG.tmp" &lt;&lt; "CFGEOF"
'"${DEFAULT_CFG}"'
CFGEOF
  mv "$CFG.tmp" "$CFG"
fi'

PLG_STOP_POLL='PIDFILE=/var/run/streamviewer_poll.pid
if [[ -f "$PIDFILE" ]]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [[ "$PID" =~ ^[0-9]+$ ]]; then
        if grep -qs streamviewer_poll "/proc/$PID/cmdline"; then
            kill "$PID" 2>/dev/null
        fi
    fi
    rm -f "$PIDFILE"
fi

if grep -q "streamviewer_cron" /var/spool/cron/crontabs/root 2>/dev/null; then
    sed -i "/streamviewer_cron/d" /var/spool/cron/crontabs/root
fi'

PLG_INSTALL_SCRIPT='chown root:root /usr /usr/local /usr/local/emhttp /usr/local/emhttp/plugins
chown -R root:root /usr/local/emhttp/plugins/&name;
find /usr/local/emhttp/plugins/&name; -type d -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name; -type f -exec chmod 644 {} \;
find /usr/local/emhttp/plugins/&name; -name "*.sh"   -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name;/event -type f -exec chmod 755 {} \; 2>/dev/null

mkdir -p /tmp/streamviewer_cache
chmod 700 /tmp/streamviewer_cache

'"${PLG_STOP_POLL}"'
sleep 1

if grep -qs "mdState=\"STARTED\"" /var/local/emhttp/var.ini; then
    if grep -qs "^STATS_ENABLED=\"1\"" /boot/config/plugins/&name;/&name;.cfg; then
        setsid /usr/local/emhttp/plugins/&name;/include/streamviewer_poll.sh >/dev/null 2>/dev/null &lt;/dev/null &amp;
    fi
fi

echo ""
echo "----------------------------------------------------"
echo " &name; (&branch; build) installed successfully."
echo " Version : &version;"
echo " Settings: Settings > Stream Viewer Settings"
echo "----------------------------------------------------"
echo ""'

PLG_REMOVE_SCRIPT="${PLG_STOP_POLL}"'

CFG=/boot/config/plugins/&name;/&name;.cfg
ERASE="0"
DBDIR=""
if [[ -f "$CFG" ]]; then
    ERASE=$(grep -m1 "^ERASE_ON_UNINSTALL=" "$CFG" | cut -d\" -f2)
    DBDIR=$(grep -m1 "^STATS_DB_PATH=" "$CFG" | cut -d\" -f2)
fi

for pkg in /var/lib/pkgtools/packages/&name;-[0-9]*; do
  if [ -f "$pkg" ]; then removepkg "${pkg##*/}"; fi
done
rm -rf /usr/local/emhttp/plugins/&name;
rm -rf /tmp/streamviewer_cache

rm -f /boot/config/plugins/&name;/&name;-*.txz /boot/config/plugins/&name;/&name;-*.txz.b64 /boot/config/plugins/&name;/&name;.cfg.bak

if [[ "$ERASE" == "1" ]]; then
    if [[ -z "$DBDIR" ]]; then DBDIR="/mnt/user/appdata/Stream-Viewer"; fi
    case "$DBDIR" in
        *..*) ;;
        /mnt/*)
            if [[ -d "$DBDIR" ]]; then
                rm -f "$DBDIR/streamviewer.db" "$DBDIR/streamviewer.db-wal" "$DBDIR/streamviewer.db-shm"
                rmdir "$DBDIR" 2>/dev/null
            fi
            ;;
    esac
    rm -rf /boot/config/plugins/&name;
fi

echo ""
echo "----------------------------------------------------"
echo " &name; has been removed."
if [[ "$ERASE" == "1" ]]; then
echo " Settings and statistics database erased."
else
echo " Settings kept : /boot/config/plugins/&name;"
if [[ -n "$DBDIR" ]]; then
echo " Statistics kept: $DBDIR"
fi
fi
echo "----------------------------------------------------"
echo ""'

echo "Generating ${PLUGIN_NAME}.plg (${BRANCH} target)..."

if [[ "$LOCAL_INSTALL" == "local" ]]; then
    PACKAGE_TYPE=' Type="base64"'
    PACKAGE_SOURCE="<INLINE>$(b64_nolf "${PACKAGE_PATH}")</INLINE>"
else
    PACKAGE_TYPE=''
    PACKAGE_SOURCE='<URL>&pluginURL;</URL>'
fi

cat > "${PLUGIN_NAME}.plg" << EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
 <!ENTITY name      "${PLUGIN_NAME}">
 <!ENTITY author    "${AUTHOR}">
 <!ENTITY version   "${VERSION}">
 <!ENTITY branch    "${BRANCH}">
 <!ENTITY gitURL    "${GIT_URL}">
 <!ENTITY pluginURL "${PLUGIN_URL_STRUCTURE}">
 <!ENTITY selfURL   "&gitURL;/raw/&branch;/&name;.plg">
 <!ENTITY md5       "${PACKAGE_MD5}">
 <!ENTITY launch    "Settings/StreamViewerSettings">
]>

<PLUGIN name="&name;" Title="Stream Viewer" author="&author;" version="&version;"
        pluginURL="&selfURL;" launch="&launch;"
        icon="img/streamviewerplugin.png"
        min="7.2.0"
        support="https://github.com/Lazaros-Chalkidis/unraid-streamviewer/issues">

<DESCRIPTION>
${PLG_DESCRIPTION}
</DESCRIPTION>

<CHANGES><![CDATA[
${CHANGES_BLOCK}
]]></CHANGES>

<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz" Run="upgradepkg --install-new"${PACKAGE_TYPE}>
${PACKAGE_SOURCE}
<MD5>&md5;</MD5>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
${PLG_OLD_PACKAGES}
</INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
${PLG_DEFAULT_CFG}
</INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
${PLG_INSTALL_SCRIPT}
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
${PLG_REMOVE_SCRIPT}
</INLINE>
</FILE>

</PLUGIN>
EOF

rm -rf "${PACKAGE_DIR_TEMP}"

if command -v xmllint &>/dev/null && ! xmllint --noout "${PLUGIN_NAME}.plg"; then
    echo "Build stopped: ${PLUGIN_NAME}.plg is not valid XML."
    exit 1
fi

if grep -q '<\|&lt;' "$CHANGELOG_MD_FILE" 2>/dev/null; then
    echo "Warning: CHANGELOG.md contains < or &lt;. Unraid drops the text from there to the next > in the release notes."
fi

echo ""
echo "Build complete."
echo "   Package : ${PACKAGE_PATH}  ($(du -h "${PACKAGE_PATH}" | cut -f1))"
echo "   PLG     : ${PLUGIN_NAME}.plg"
echo "   MD5     : ${PACKAGE_MD5}"
echo "   Version : ${VERSION}"
echo "   Branch  : ${BRANCH}"
echo ""
