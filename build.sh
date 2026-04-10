#!/bin/bash
# StreamViewer — build.sh
# Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3
# Packages the plugin source into a .txz and generates the .plg file.
#
# Usage:
#   ./build.sh                        → release build (today's date, main branch)
#   ./build.sh a                      → versioned suffix: 2026.01.15a
#   ./build.sh a dev                  → dev build (dev branch)
#   ./build.sh "" local               → local build (embeds .txz in .plg, no URL)
#   ./build.sh a dev local            → dev + local
#
# Output:
#   packages/streamviewer-<version>.txz
#   streamviewer.plg

# ── Configuration ─────────────────────────────────────────────────────────────
PLUGIN_NAME="streamviewer"
AUTHOR="Lazaros Chalkidis"
GITHUB_USER="Lazaros-Chalkidis"
GIT_URL="https://github.com/Lazaros-Chalkidis/unraid-streamviewer"
PACKAGE_DIR_FINAL="packages"
PACKAGE_DIR_TEMP="package-temp"

# ── Versioning ────────────────────────────────────────────────────────────────
BASE_VERSION=$(date +'%Y.%m.%d')
LETTER_SUFFIX="${1}"          # optional: a b c …
STAGE_INPUT="${2}"            # optional: dev | release | (empty)
LOCAL_INSTALL="${3:-}"        # optional: local

STAGE_SUFFIX=""
if [[ -n "$STAGE_INPUT" && "$STAGE_INPUT" != "release" ]]; then
    STAGE_SUFFIX="-${STAGE_INPUT}"
fi
VERSION="${BASE_VERSION}${LETTER_SUFFIX}${STAGE_SUFFIX}"

# ── Branch & URL ──────────────────────────────────────────────────────────────
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

# ── Changelog ─────────────────────────────────────────────────────────────────
CHANGELOG_MD_FILE="CHANGELOG.md"
if [[ -f "$CHANGELOG_MD_FILE" ]]; then
    CHANGES_BLOCK="$(cat "$CHANGELOG_MD_FILE")"
else
    CHANGES_BLOCK="### ${VERSION}
${CHANGES_TEXT}"
fi

# ── Build ─────────────────────────────────────────────────────────────────────
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

# Branch metadata (readable by PHP for self-identification)
cat > "${PLUGIN_DEST}/branch.meta" << METAEOF
BRANCH="${BRANCH}"
IS_MAIN_BRANCH=$([[ "$BRANCH" == "main" ]] && echo "1" || echo "0")
METAEOF

# ── Permissions ───────────────────────────────────────────────────────────────
find "${PLUGIN_DEST}" -type d                          -exec chmod 755 {} \;
find "${PLUGIN_DEST}" -type f                          -exec chmod 644 {} \;
find "${PLUGIN_DEST}" -name "*.page"                   -exec chmod 755 {} \;
find "${PLUGIN_DEST}" -name "*.sh"                     -exec chmod 755 {} \;
# .php and all other files stay at 644 (set by the find above)

# ── Create .txz ───────────────────────────────────────────────────────────────
FILENAME="${PLUGIN_NAME}-${VERSION}"
PACKAGE_PATH="${PACKAGE_DIR_FINAL}/${FILENAME}.txz"

echo "Creating package: ${FILENAME}.txz ..."
tar -C "${PACKAGE_DIR_TEMP}" -cJf "${PACKAGE_PATH}" usr

if [[ ! -f "${PACKAGE_PATH}" ]]; then
    echo "❌ Package creation failed!"
    exit 1
fi
echo "✅ Package: $(du -h "${PACKAGE_PATH}" | cut -f1)  →  ${PACKAGE_PATH}"

# ── MD5 ───────────────────────────────────────────────────────────────────────
if command -v md5sum &>/dev/null; then
    PACKAGE_MD5="$(md5sum "${PACKAGE_PATH}" | cut -d' ' -f1)"
elif command -v md5 &>/dev/null; then
    PACKAGE_MD5="$(md5 -q "${PACKAGE_PATH}")"
else
    echo "⚠️  md5sum/md5 not found — MD5 will be empty in PLG!"
    PACKAGE_MD5=""
fi
echo "🔑 MD5: ${PACKAGE_MD5}"

# ── Base64 helper (portable: GNU -w0 vs BSD no-newline) ───────────────────────
b64_nolf() {
    if base64 --help 2>/dev/null | grep -q -- "-w"; then
        base64 -w 0 "$1"
    else
        base64 "$1" | tr -d '\n'
    fi
}

# ── Default config (written to flash on first install only) ───────────────────
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
WIDGET_DETAILS_OPEN="0"
WIDGET_SHOW_DOCKER="1"
TOOL_ALLOW_KILL="0"
VERIFY_SSL="0"
STATS_ENABLED="0"
STATS_DB_PATH="/mnt/user/appdata/Stream-Viewer"
STATS_RETENTION_DAYS="90"
STATS_ANONYMIZE_IP="0"
LIB_SECTIONS_OPEN="0"
CFGEOF

# ── Shared PLG sections ───────────────────────────────────────────────────────
PLG_DESCRIPTION="A real-time media stream monitor and statistics tracker for Plex, Jellyfin and Emby servers on Unraid."

PLG_INSTALL_SCRIPT='# Fix ownership and permissions
chown -R root:root /usr/local/emhttp/plugins/&name;
find /usr/local/emhttp/plugins/&name; -type d -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name; -type f -exec chmod 644 {} \;
find /usr/local/emhttp/plugins/&name; -name "*.page" -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name; -name "*.sh"   -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name;/event -type f -exec chmod 755 {} \; 2>/dev/null

# Init cache dir with restricted permissions
mkdir -p /tmp/streamviewer_cache
chmod 700 /tmp/streamviewer_cache

# Restore user config if update overwrote it with defaults
CFG=/boot/config/plugins/&name;/&name;.cfg
BAK=/boot/config/plugins/&name;/&name;.cfg.bak
if [[ -f "$BAK" ]]; then
    cp "$BAK" "$CFG"
    rm -f "$BAK"
fi

# Stop any running poll daemon (from previous version)
if [[ -f /var/run/streamviewer_poll.pid ]]; then
    kill $(cat /var/run/streamviewer_poll.pid) 2>/dev/null
    rm -f /var/run/streamviewer_poll.pid
    sleep 1
fi

# Remove old cron entry (from previous versions)
if grep -q "streamviewer_cron" /var/spool/cron/crontabs/root 2>/dev/null; then
    sed -i "/streamviewer_cron/d" /var/spool/cron/crontabs/root
fi

# Clean orphaned dirs under /mnt/user from failed mounts (previous boot)
if ! mountpoint -q /mnt/user 2>/dev/null; then
    rm -rf /mnt/user/* 2>/dev/null
fi

# Daemon is started by event/started after array is fully mounted

echo ""
echo "----------------------------------------------------"
echo " &name; (&branch; build) installed successfully."
echo " Version : &version;"
echo " Settings: Settings > Stream Viewer"
echo "----------------------------------------------------"
echo ""'

PLG_REMOVE_SCRIPT='# Stop poll daemon
if [[ -f /var/run/streamviewer_poll.pid ]]; then
    kill $(cat /var/run/streamviewer_poll.pid) 2>/dev/null
    rm -f /var/run/streamviewer_poll.pid
fi

# Clean orphaned dirs under /mnt/user from failed mounts
if ! mountpoint -q /mnt/user 2>/dev/null; then
    rm -rf /mnt/user/* 2>/dev/null
fi

# Remove old cron entry (from previous versions)
sed -i "/streamviewer_cron/d" /var/spool/cron/crontabs/root 2>/dev/null

removepkg &name;-&version;
rm -rf /usr/local/emhttp/plugins/&name;
rm -rf /boot/config/plugins/&name;
rm -rf /tmp/streamviewer_cache

echo ""
echo "----------------------------------------------------"
echo " &name; has been removed."
echo "----------------------------------------------------"
echo ""'

# ── Generate .plg ─────────────────────────────────────────────────────────────
echo "Generating ${PLUGIN_NAME}.plg (${BRANCH} target)..."

# ── LOCAL build (embed .txz as base64) ────────────────────────────────────────
if [[ "$LOCAL_INSTALL" == "local" ]]; then
    PACKAGE_B64="$(b64_nolf "${PACKAGE_PATH}")"

    cat > "${PLUGIN_NAME}.plg" << EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
 <!ENTITY name    "${PLUGIN_NAME}">
 <!ENTITY author  "${AUTHOR}">
 <!ENTITY version "${VERSION}">
 <!ENTITY branch  "${BRANCH}">
 <!ENTITY gitURL  "${GIT_URL}">
 <!ENTITY selfURL "&gitURL;/raw/&branch;/&name;.plg">
 <!ENTITY launch  "Settings/StreamViewerSettings">
]>

<PLUGIN name="&name;" Title="Stream Viewer" author="&author;" version="&version;"
        pluginURL="&selfURL;" launch="&launch;"
        icon="streamviewerplugin.png"
        min="7.2.0"
        support="https://forums.unraid.net/topic/197757-plugin-stream-viewer/">

<DESCRIPTION>
${PLG_DESCRIPTION}
</DESCRIPTION>

<CHANGES>
${CHANGES_BLOCK}
</CHANGES>

<!-- Decode embedded base64 .txz and install -->
<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz.b64">
  <INLINE>${PACKAGE_B64}</INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
mkdir -p /boot/config/plugins/&name;
base64 -d /boot/config/plugins/&name;/&name;-&version;.txz.b64 \
    > /boot/config/plugins/&name;/&name;-&version;.txz 2>/dev/null || \
  base64 -D /boot/config/plugins/&name;/&name;-&version;.txz.b64 \
    > /boot/config/plugins/&name;/&name;-&version;.txz
rm -f /boot/config/plugins/&name;/&name;-&version;.txz.b64
upgradepkg --install-new /boot/config/plugins/&name;/&name;-&version;.txz
</INLINE>
</FILE>

<!-- Back up existing user config before the FILE tag writes defaults -->
<FILE Run="/bin/bash">
<INLINE>
if [[ -f /boot/config/plugins/&name;/&name;.cfg ]]; then cp /boot/config/plugins/&name;/&name;.cfg /boot/config/plugins/&name;/&name;.cfg.bak; fi
</INLINE>
</FILE>

<!-- Write default config (post-install script restores backup if user had existing config) -->
<FILE Name="/boot/config/plugins/&name;/&name;.cfg">
  <INLINE>
${DEFAULT_CFG}
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

# ── REMOTE build (download from GitHub) ───────────────────────────────────────
else

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
        icon="streamviewerplugin.png"
        min="7.2.0"
        support="https://forums.unraid.net/topic/197757-plugin-stream-viewer/">

<DESCRIPTION>
${PLG_DESCRIPTION}
</DESCRIPTION>

<CHANGES>
${CHANGES_BLOCK}
</CHANGES>

<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz" Run="upgradepkg --install-new">
  <URL>&pluginURL;</URL>
  <MD5>&md5;</MD5>
</FILE>

<!-- Back up existing user config before the FILE tag writes defaults -->
<FILE Run="/bin/bash">
<INLINE>
if [[ -f /boot/config/plugins/&name;/&name;.cfg ]]; then cp /boot/config/plugins/&name;/&name;.cfg /boot/config/plugins/&name;/&name;.cfg.bak; fi
</INLINE>
</FILE>

<!-- Write default config (post-install script restores backup if user had existing config) -->
<FILE Name="/boot/config/plugins/&name;/&name;.cfg">
  <INLINE>
${DEFAULT_CFG}
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

fi

# ── Cleanup ───────────────────────────────────────────────────────────────────
rm -rf "${PACKAGE_DIR_TEMP}"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "🎉 Build complete!"
echo "   📦 Package : ${PACKAGE_PATH}  ($(du -h "${PACKAGE_PATH}" | cut -f1))"
echo "   📄 PLG     : ${PLUGIN_NAME}.plg"
echo "   🔑 MD5     : ${PACKAGE_MD5}"
echo "   🏷  Version : ${VERSION}"
echo "   🌿 Branch  : ${BRANCH}"
echo ""