#!/usr/bin/env python3
# ============================================================================
# STREAM VIEWER
# Copyright (C) 2026 Lazaros Chalkidis
# License: GPLv3
# =========================================================================

import os, html

OUT = 'mockups'
BG, SURF, SURF2 = '#1a1a1a', '#242424', '#2e2e2e'
BORDER, BORDER2 = 'rgba(255,255,255,.10)', 'rgba(255,255,255,.18)'
TXT, MID, DIM, MUTED = 'rgba(255,255,255,.85)', 'rgba(255,255,255,.65)', 'rgba(255,255,255,.45)', 'rgba(255,255,255,.30)'
ACCENT, PLAY, STREAM, TRANS, PAUSED, DANGER = '#42a5f5', '#4caf50', '#2196f3', '#ff9800', '#9e9e9e', '#e24b4a'
PLEX, JF, EMBY, LIVE, ON = '#e5a00d', '#00a4dc', '#52b54b', '#486dba', '#f47d42'
FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif"

REFRESH = 'M10 6a4 4 0 1 1-1.2-2.8M9 .8v2.5H6.5'
CHART = 'M2.5 10.5v-4M6 10.5v-8M9.5 10.5v-6'
COG = 'M6 1.8a4.2 4.2 0 1 0 0 8.4a4.2 4.2 0 1 0 0-8.4M6 4.6a1.4 1.4 0 1 0 0 2.8a1.4 1.4 0 1 0 0-2.8'
UP, DOWN = 'M2.5 7.8l3.5-3.6 3.5 3.6', 'M2.5 4.4l3.5 3.6 3.5-3.6'
TV, REEL = 'M1.5 2h9v6.5h-9zM4 10.5h4', 'M2 1.5h8v9H2zM4.4 1.5v9M7.6 1.5v9'
CLOCK = 'M6 1.5a4.5 4.5 0 1 0 0 9a4.5 4.5 0 1 0 0-9M6 3.6v2.6l1.8 1'
WARN = 'M6 1.6l4.6 8.4H1.4zM6 5v2.2M6 8.5v.2'
CHECK, PLUS = 'M2.5 6.4l2.4 2.4 4.6-5', 'M6 2.5v7M2.5 6h7'

def esc(t):
    return html.escape(str(t))

def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" '
            f'viewBox="0 0 {w} {h}" font-family="{FONT}" role="img" aria-label="{esc(title)}">'
            f'<title>{esc(title)}</title>'
            f'<rect width="{w}" height="{h}" rx="6" fill="{BG}"/>{body}</svg>')

def box(x, y, w, h, fill=SURF, stroke=BORDER, r=4):
    return f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" fill="{fill}" stroke="{stroke}"/>'

def text(x, y, t, fill=TXT, size=12, anchor='start', weight='normal'):
    return (f'<text x="{x}" y="{y}" fill="{fill}" font-size="{size}" '
            f'text-anchor="{anchor}" font-weight="{weight}">{esc(t)}</text>')

def spans(x, y, parts, size=10):
    inner = ''.join(f'<tspan fill="{c}" font-weight="{w}">{esc(t)}</tspan>' for t, c, w in parts)
    return f'<text x="{x}" y="{y}" font-size="{size}">{inner}</text>'

def line(x, y, w, opacity=1):
    return f'<path d="M{x} {y}h{w}" stroke="{BORDER}" opacity="{opacity}"/>'

def note(x, y, t, anchor='start'):
    return text(x, y, t, ACCENT, 10.5, anchor, '600')

def arrow(x1, y1, x2, y2):
    return (f'<path d="M{x1} {y1}L{x2} {y2}" stroke="{ACCENT}" stroke-width="1.6" '
            f'marker-end="url(#a)"/>')

DEFS = (f'<defs><marker id="a" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="5" '
        f'markerHeight="5" orient="auto"><path d="M0 0l10 5-10 5z" fill="{ACCENT}"/></marker></defs>')

def tint(c, a):
    return f'rgba({int(c[1:3], 16)},{int(c[3:5], 16)},{int(c[5:7], 16)},{a})'

def ico(x, y, d, c=MID, w=1.4):
    return (f'<path transform="translate({x} {y})" d="{d}" fill="none" stroke="{c}" '
            f'stroke-width="{w}" stroke-linecap="round" stroke-linejoin="round"/>')

def ibtn(x, y, d):
    return box(x, y, 21, 21, SURF2, BORDER, 3) + ico(x + 4.5, y + 4.5, d)

def logo(x, y, c=MID, play=True):
    return (f'<g transform="translate({x} {y})"><rect width="22" height="22" rx="4" fill="none" '
            f'stroke="{c}" stroke-width="1.5"/>'
            + (f'<path d="M8.5 6.5l7 4.5-7 4.5z" fill="{c}"/>' if play else '') + '</g>')

def tri(x, y, c, s=8):
    return f'<path d="M{x} {y}l{s} {s / 2}-{s} {s / 2}z" fill="{c}"/>'

def stop(x, y):
    return f'<rect x="{x}" y="{y}" width="8" height="8" rx="1.5" fill="{DANGER}"/>'

def edge(x, y, h, c):
    return f'<rect x="{x}" y="{y + 1}" width="3.5" height="{h - 2}" rx="1.5" fill="{c}"/>'

def pw(label, k=.7):
    return round(len(label) * 8.5 * k + 14)

def pill(x, y, label, c=MID, k=.7, weight='700'):
    w, col = pw(label, k), c.startswith('#')
    return (box(x, y, w, 15, tint(c, .15) if col else 'rgba(255,255,255,.07)',
                tint(c, .32) if col else BORDER, 3)
            + text(x + w / 2, y + 10.8, label, c, 8.5, 'middle', weight))

def pills(x, y, items, right=False, k=.7, weight='700'):
    out = ''
    for label, c in (reversed(items) if right else items):
        w = pw(label, k)
        out += pill(x - w if right else x, y, label, c, k, weight)
        x += -(w + 4) if right else w + 4
    return out

def chips(x, y, items):
    return pills(x, y, items, k=.62, weight='600')

def bar(x, y, w, part, c, h=3):
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="1.5" fill="rgba(255,255,255,.12)"/>'
            f'<rect x="{x}" y="{y}" width="{round(w * part, 1)}" height="{h}" rx="1.5" fill="{c}"/>')

def toggle(x, y, label):
    return (f'<rect x="{x}" y="{y}" width="26" height="12" rx="6" fill="rgba(255,255,255,.16)"/>'
            f'<circle cx="{x + 6}" cy="{y + 6}" r="4.4" fill="#fff"/>' + text(x + 34, y + 10, label, DIM, 10.5))

def select(x, y, w, label):
    return box(x, y, w, 20, SURF2, BORDER2, 3) + text(x + 8, y + 13.8, label, TXT, 10) + ico(x + w - 17, y + 4, DOWN, DIM)

def field(x, y, w, value):
    return box(x, y, w, 20, 'rgba(0,0,0,.28)', BORDER2, 3) + text(x + 8, y + 13.8, value, TXT, 10)

def button(x, y, label, c=MID, d=None, w=None):
    w, col = w or round(len(label) * 6.2 + (34 if d else 20)), c.startswith('#')
    out = box(x, y, w, 22, tint(c, .14) if col else SURF2, tint(c, .4) if col else BORDER2, 3)
    if d:
        out += ico(x + 8, y + 5, d, c)
    return out + text(x + (w + (14 if d else 0)) / 2, y + 14.8, label, c, 10, 'middle', '600'), w

def section(y, title, tag, opened):
    return (box(14, y, 500, 26, SURF2, BORDER, 3) + text(26, y + 17, title, TXT, 11.5, weight='600')
            + pill(480 - pw(tag, .62), y + 5.5, tag, ON, .62, '600') + ico(490, y + 7, UP if opened else DOWN, DIM))

def card(x, y, w, c, left, right, kind, title, kill=False):
    out = box(x, y, w, 54, SURF2, BORDER, 4) + edge(x, y, 54, c)
    out += pills(x + 12, y + 8, left) + pills(x + w - 10, y + 8, right, True)
    out += ico(x + 12, y + 31, kind, DIM, 1.2) + tri(x + 30, y + 33, PLAY)
    if kill:
        out += stop(x + 44, y + 33)
    return out + text(x + (60 if kill else 46), y + 41, title, TXT, 11.5, weight='600')

Q2160, Q1080 = ('2160', TXT), ('1080', TXT)
SEVERANCE = ([Q2160, ('24.0 Mbps', DIM)], [('JELLYFIN', JF), ('ATTIC', TXT), ('TRANSCODE 4K→720p', TRANS)])
DUNE = ([Q1080, ('8.4 Mbps', DIM)], [('PLEX', PLEX), ('TOWER', TXT), ('DIRECT PLAY', PLAY)])

os.makedirs(OUT, exist_ok=True)
made = []

b = DEFS
b += box(14, 14, 752, 30, SURF2, BORDER, 3)
x = 26
for word in ['DASHBOARD', 'MAIN', 'SHARES', 'USERS', 'SETTINGS', 'PLUGINS', 'APPS', 'TOOLS']:
    b += text(x, 33, word, TXT if word == 'TOOLS' else DIM, 9, weight='600')
    x += len(word) * 7 + 14
b += tri(610, 23, ACCENT, 12) + text(626, 24, '3', ACCENT, 9, weight='700')
for i in range(5):
    b += f'<circle cx="{652 + i * 24}" cy="29" r="5" fill="none" stroke="{MUTED}" stroke-width="1.3"/>'
b += arrow(588, 62, 610, 44)
b += note(580, 68, 'In the top bar only while something plays', 'end')
b += box(14, 84, 752, 26, SURF2, BORDER, 3)
b += text(26, 101, 'Viewers Suite', TXT, 12, weight='600')
b += box(14, 116, 752, 78, SURF, BORDER)
for i, (label, hi) in enumerate([('Disk Viewer', False), ('Files Viewer', False),
                                 ('Logs Viewer', False), ('Stream Viewer', True)]):
    cx = 90 + i * 150
    b += logo(cx - 11, 130, ACCENT if hi else MID, hi)
    b += text(cx, 176, label, ACCENT if hi else MID, 10.5, 'middle')
b += arrow(428, 216, 506, 188)
b += note(420, 220, 'Tools > Viewers Suite > Stream Viewer', 'end')
made.append(('01-where-it-lives.svg', svg(780, 236, b, 'The header button and the Viewers Suite section on the Tools page')))

b = DEFS
b += box(14, 14, 506, 190, SURF, BORDER)
b += tri(26, 24, PLEX, 9) + text(42, 32.5, 'Add Plex Servers', TXT, 12, weight='600')
b += pill(508 - pw('Auto-discovery', .62), 20.5, 'Auto-discovery', ON, .62, '600')
b += line(14, 42, 506)
b += ico(26, 51, CHECK, EMBY, 1.6) + spans(42, 61, [('Found ', MID, 'normal'), ('1', TXT, '700'), (' server', MID, 'normal')], 10.5)
b += box(26, 72, 482, 120, SURF2, BORDER)
b += text(38, 91, 'Tower', TXT, 11.5, weight='600')
b += box(38, 99, 362, 22, 'rgba(0,0,0,.28)', ACCENT, 3)
b += text(46, 114, 'Local Container: 192.168.1.10:32400 (recommended)', TXT, 10) + ico(382, 104, DOWN, DIM)
b += box(38, 122, 362, 60, BG, BORDER2, 3)
for i, t in enumerate(['Local: 192.168.1.10:32400 (plex.direct)', 'Remote: 203.0.113.7:32400 (not optimal here)',
                       'Relay (via plex.tv) (slow fallback)']):
    b += text(46, 138 + i * 18, t, MID, 10)
b += button(420, 158, 'Add', EMBY, PLUS, 76)[0]
b += box(14, 214, 506, 38, SURF, BORDER)
x = 26
for label, c, d in [('Apply', EMBY, CHECK), ('Reset to Defaults', MID, None), ('Done', MID, None)]:
    s, w = button(x, 222, label, c, d)
    b += s
    x += w + 8
b += arrow(540, 110, 404, 110)
b += note(546, 106, 'Best first. Stay on the top one') + note(546, 121, 'unless Plex is on another network.')
b += arrow(58, 270, 58, 248)
b += note(26, 284, 'Nothing is saved until Apply: not an added server, not a removed one.')
made.append(('02-adding-a-plex-server.svg', svg(780, 298, b, 'Picking the address of a Plex server after signing in')))

b = DEFS
b += box(14, 14, 470, 204, SURF, BORDER)
b += logo(26, 26) + text(58, 35, 'STREAM VIEWER', TXT, 12, weight='700')
b += f'<circle cx="62" cy="47" r="3" fill="{LIVE}"/>' + text(70, 50.5, '2 Stream/s', DIM, 9.5)
for i, d in enumerate([REFRESH, CHART, COG, UP]):
    b += ibtn(373 + i * 26, 26, d)
b += line(14, 60, 470)
b += card(24, 68, 450, TRANS, *SEVERANCE, TV, 'Severance - S02E01 - Hello, Ms. Cobel')
b += card(24, 128, 450, PLAY, *DUNE, REEL, 'Dune: Part Two')
b += line(14, 190, 470)
x = 26
for label, part, value, c in [('CPU', .2, '20.0%', '#a3a3a3'), ('RAM', .3, '956 MB', '#a3a3a3'),
                              ('TOTAL BW', .32, '32.4 Mbps', LIVE)]:
    b += text(x, 208, label, MUTED, 8.5, weight='600')
    x = round(x + len(label) * 6.4 + 6, 1)
    b += bar(x, 203, 22, part, c, 4) + text(x + 27, 208, value, MID, 8.5, weight='600')
    x = round(x + 27 + len(value) * 5.6 + 9, 1)
b += ico(x, 198, WARN, TRANS, 1.2) + text(x + 15, 208, '1', TRANS, 8.5, weight='700')
b += f'<circle cx="404" cy="205" r="2.5" fill="{PLAY}"/>' + ico(411, 199, CLOCK, DIM, 1.1)
b += text(472, 208, '13:31:30', MID, 8.5, 'end', '600')
b += arrow(504, 36.5, 488, 36.5) + note(510, 40, 'Refresh, statistics, settings')
b += arrow(504, 204.5, 488, 204.5) + note(510, 208, 'Time of the last refresh')
b += arrow(x + 6, 238, x + 6, 221)
b += note(484, 250, 'A server that did not answer. Hover it for the reason.', 'end')
b += arrow(60, 256, 60, 221)
b += note(26, 270, 'CPU and RAM of the media server containers, bandwidth of all streams')
made.append(('03-the-tile.svg', svg(780, 284, b, 'The dashboard tile with its header icons and its footer')))

b = DEFS
b += box(14, 40, 500, 146, SURF2, BORDER, 4) + edge(14, 40, 146, TRANS)
b += arrow(36, 18, 36, 47) + note(46, 28, 'Resolution and bitrate')
b += arrow(492, 18, 492, 47) + note(482, 28, 'Server, its name, how it plays', 'end')
b += pills(28, 50, SEVERANCE[0]) + pills(502, 50, SEVERANCE[1], True)
b += ico(28, 75, TV, DIM, 1.2) + tri(46, 77, PLAY) + stop(60, 77)
b += text(76, 85, 'Severance - S02E01 - Hello, Ms. Cobel', TXT, 11.5, weight='600')
b += spans(28, 104, [('nikos', TXT, '600'), (' · ', MUTED, 'normal'), ('Firefox', DIM, 'normal'),
                     (' · ', MUTED, 'normal'), ('10.0.0.3', DIM, 'normal')])
b += bar(240, 99, 96, .06, TRANS) + text(502, 104, '0:02:51 / 0:50:00 · 48m left', DIM, 9, 'end')
b += chips(28, 114, [('HEVC → H264', MID), ('10-bit', MID), ('Dolby Vision', JF), ('EAC3 5.1', MID), ('MKV', MID)])
b += chips(28, 134, [('Sub: gre (SRT)', DIM), ('HW: QSV', EMBY), ('Video Codec Not Supported', ON), ('Buffer: 43%', MID)])
b += text(28, 169, 'BW', MUTED, 8, weight='600')
b += (f'<path d="M48 170l10-3 10 1 10-5 10 2 10-4 10 3 10-2 10 1 10-4 10 2" fill="none" '
      f'stroke="{LIVE}" stroke-width="1.4" stroke-linejoin="round"/>')
b += text(158, 169, '24.0 Mbps', TXT, 9, weight='600')
b += text(486, 174, 'Synopsis', DIM, 9, 'end') + ico(489, 164, DOWN, DIM, 1.2)
for y, t in [(85, 'Media kind, state, stop button'), (104, 'Who, device, address, progress'),
             (125, 'Codecs, HDR, audio, container'), (145, 'Subtitles, hardware, the reason'),
             (169, 'Bandwidth while the page is open')]:
    b += arrow(536, y - 3.5, 520, y - 3.5) + note(542, y, t)
b += arrow(40, 204, 19, 189) + note(46, 208, 'The colour of the bar is the play type')
made.append(('04-reading-a-stream.svg', svg(780, 222, b, 'One stream and what each part of it says')))

b = DEFS
for i, (c, label) in enumerate([(PLAY, 'DIRECT PLAY'), (STREAM, 'DIRECT STREAM'),
                                (TRANS, 'TRANSCODE 4K→720p 2.4X'), (PAUSED, 'PAUSED')]):
    x = 14 + i * 190
    b += box(x, 14, 182, 40, SURF2, BORDER, 4) + edge(x, 14, 40, c) + pill(x + 16, 26.5, label, DIM if c == PAUSED else c)
b += note(26, 76, 'The bar at the left edge of a stream, and the badge that goes with it')
made.append(('05-play-types.svg', svg(780, 90, b, 'The four colours of the bar: direct play, direct stream, transcode, paused')))

b = DEFS
b += card(14, 14, 360, PLAY, *DUNE, REEL, 'Dune: Part Two', True)
b += arrow(62, 96, 62, 59)
b += note(26, 112, 'The red square stops the stream. It is there only') + note(26, 127, 'when Allow killing sessions is switched on.')
b += box(400, 14, 366, 128, SURF, BORDER2)
b += ico(577, 24, WARN, PLEX, 1.5)
b += text(583, 54, 'Stop stream', TXT, 12, 'middle', '700')
b += text(583, 74, 'Stop stream "Dune: Part Two"?', MID, 10.5, 'middle')
b += text(583, 89, 'This will immediately disconnect the user.', MID, 10.5, 'middle')
b += line(400, 104, 366)
b += button(630, 112, 'Cancel', MID, None, 60)[0] + button(696, 112, 'Stop', DANGER, None, 58)[0]
made.append(('06-stopping-a-stream.svg', svg(780, 156, b, 'The stop button on a stream and the question it asks')))

b = DEFS
b += section(14, 'Widget settings', 'Widget layout', False)
b += section(44, 'Active Stream/s', 'Active Stream/s tab on Statistics page', False)
b += section(74, 'Statistics settings', 'History and more..', True)
ROWS = [('Enable watch history', toggle, 'Disabled', 'Off until you turn it on'),
        ('Database path', field, '/mnt/user/appdata/Stream-Viewer', 'A share or a pool under /mnt'),
        ('Data retention', select, '90 days - Default', ''),
        ('Merge paused sessions', select, '60 minutes - Default', 'Pause and resume make one play'),
        ('Library sections', select, 'Collapsed - Default', ''),
        ('Anonymize IPs', toggle, 'Disabled', ''),
        ('Viewer locations', toggle, 'Disabled', 'Sends public IPs to ip-api.com'),
        ('Erase data on uninstall', toggle, 'Disabled', ''),
        ('Wipe statistics database', button, 'Wipe now', '')]
b += box(14, 100, 500, len(ROWS) * 24, SURF, BORDER)
for i, (label, kind, value, hint) in enumerate(ROWS):
    y = 100 + i * 24
    b += text(26, y + 16, label, MID, 10.5)
    if kind is toggle:
        b += toggle(212, y + 6, value)
    elif kind is field:
        b += field(212, y + 2, 216, value)
    elif kind is select:
        b += select(212, y + 2, 150, value)
    else:
        b += button(212, y + 1, value, DANGER, None, 76)[0]
    if i:
        b += line(22, y, 484, .5)
    if hint:
        b += arrow(534, y + 12.5, 518, y + 12.5) + note(540, y + 16, hint)
y = 104 + len(ROWS) * 24
b += section(y, 'Connection settings', 'Network', False)
b += arrow(534, y + 13.5, 518, y + 13.5) + note(540, y + 17, 'Folded at first. Click a heading.')
made.append(('07-watch-history.svg', svg(780, y + 40, b, 'The Statistics settings section with watch history switched off')))

b = DEFS
b += arrow(20, 16, 20, 38) + note(30, 28, 'These need watch history turned on')
b += arrow(756, 16, 756, 38) + note(746, 28, 'Live. Works with watch history off.', 'end')
x = 14
for i, label in enumerate(['Dashboard', 'Libraries', 'Users', 'History', 'Downloads', 'Graphs', 'Insights']):
    w = round(len(label) * 6.2 + 22)
    b += box(x, 40, w, 24, SURF if i == 0 else SURF2, BORDER2 if i == 0 else BORDER, 3)
    b += text(x + w / 2, 56, label, TXT if i == 0 else MID, 10, 'middle', '600')
    x += w + 4
b += box(640, 40, 126, 24, SURF2, BORDER, 3) + tri(649, 48, MID, 8) + text(711, 56, 'Active Stream/s', MID, 10, 'middle', '600')
b += box(14, 68, 752, 146, SURF, BORDER)
b += logo(26, 80) + text(58, 89, 'Stream Viewer Statistics and Active Stream/s', TXT, 12, weight='700')
b += text(58, 103, 'Last 30 days', MUTED, 9.5)
b += ibtn(574, 80, REFRESH) + ibtn(600, 80, COG) + select(630, 80.5, 124, 'Last 30 days')
for i, (label, value) in enumerate([('Total plays', '128'), ('Hours watched', '214.5'),
                                    ('Unique users', '6'), ('Peak concurrent', '4')]):
    x = 26 + i * 184
    b += box(x, 114, 176, 44, SURF2, BORDER) + text(x + 12, 131, label, DIM, 9.5) + text(x + 12, 149, value, TXT, 13, weight='700')
for i, (label, part, c) in enumerate([('Direct play', .64, PLAY), ('Direct stream', .09, STREAM),
                                      ('Transcode', .27, TRANS), ('Remote', .31, '#9c27b0')]):
    x = 26 + i * 184
    b += text(x, 178, label, DIM, 9.5) + bar(x, 185, 176, part, c, 4) + text(x, 203, f'{round(part * 100)}%', MUTED, 9)
made.append(('08-statistics-page.svg', svg(780, 228, b, 'The tab bar of the statistics page and its Dashboard tab')))

for name, body in made:
    with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
        f.write(body)
    print(f'  {name:32s} {len(body):6d} bytes')
