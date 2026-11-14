"""Content OS hero: one idea in, agents fan it out to every channel, views and pipeline flow back.
Backdrop and orb by Kie.ai (seedream 4.5, backdrop-1.png); diagram, motion and type drawn here."""
import math, sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 1200, 600          # drawn at 2x, GIF written at 600x300
FRAMES = 36
ICONS = '/Users/adithyamurali/content-os-work/tantra-content-engine/public/images/accounts/'
FONT = '/Users/adithyamurali/Library/Fonts/Inter-Variable.ttf'
VIOLET, TEAL, GREEN, WHITE = (157, 133, 255), (77, 214, 200), (52, 211, 153), (244, 245, 249)

def font(size, weight=600):
    f = ImageFont.truetype(FONT, size)
    try:
        f.set_variation_by_axes([weight])
    except Exception:
        pass
    return f

# Backdrop: crop 16:9 to 2:1 around the orb and floor.
bd = Image.open('backdrop-kie.jpg').convert('RGB')
bw, bh = bd.size
ch = bw // 2
top = int(bh * 0.10)
bd = bd.crop((0, top, bw, top + ch)).resize((W, H), Image.LANCZOS)
ORB = (312, 310)  # orb centre, measured on the composed frame (radius about 100)

CHANNELS = ['linkedin', 'instagram', 'x', 'youtube', 'tiktok', 'threads']
CARD_X, CARD_W, CARD_H = 610, 196, 58
cards = [(CARD_X, 78 + i * 76) for i in range(len(CHANNELS))]
PANEL = (930, 170, 1150, 430)

def bezier(p0, p1, p2, p3, t):
    u = 1 - t
    return (u**3*p0[0] + 3*u*u*t*p1[0] + 3*u*t*t*p2[0] + t**3*p3[0],
            u**3*p0[1] + 3*u*u*t*p1[1] + 3*u*t*t*p2[1] + t**3*p3[1])

out_paths = [((ORB[0] + 96, ORB[1] - 6), (ORB[0] + 230, ORB[1] - 6), (CARD_X - 140, y + CARD_H / 2), (CARD_X, y + CARD_H / 2)) for _, y in cards]
back_paths = [((CARD_X + CARD_W, y + CARD_H / 2), (CARD_X + CARD_W + 70, y + CARD_H / 2), (PANEL[0] - 60, (PANEL[1] + PANEL[3]) / 2), (PANEL[0], (PANEL[1] + PANEL[3]) / 2)) for _, y in cards]
icons = {c: Image.open(ICONS + c + '.png').convert('RGBA').resize((34, 34), Image.LANCZOS) for c in CHANNELS}

def rounded(draw, box, r, **kw):
    draw.rounded_rectangle(box, r, **kw)

def glow_dot(layer, xy, r, color, alpha=255):
    d = ImageDraw.Draw(layer)
    for k in range(4, 0, -1):
        rr = r * k
        d.ellipse((xy[0]-rr, xy[1]-rr, xy[0]+rr, xy[1]+rr), fill=color + (int(alpha * 0.12 * (5-k)),))
    d.ellipse((xy[0]-r, xy[1]-r, xy[0]+r, xy[1]+r), fill=(255, 255, 255, alpha))

def frame(f):
    t = f / FRAMES
    im = bd.copy().convert('RGBA')
    lines = Image.new('RGBA', (W, H), (0, 0, 0, 0)); ld = ImageDraw.Draw(lines)
    for path in out_paths:
        pts = [bezier(*path, s / 40) for s in range(41)]
        ld.line(pts, fill=VIOLET + (70,), width=2)
    for path in back_paths:
        pts = [bezier(*path, s / 40) for s in range(41)]
        ld.line(pts, fill=TEAL + (55,), width=2)
    im = Image.alpha_composite(im, lines)

    # Pulses: agents carry the idea out, results come back. Each path offset in time.
    fx = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    lit = []
    for i, path in enumerate(out_paths):
        s = (t * 1.0 - i * 0.07) % 1.0
        if s < 0.5:
            glow_dot(fx, bezier(*path, s / 0.5), 4, VIOLET)
        lit.append(0.5 <= s < 0.72)
    for i, path in enumerate(back_paths):
        s = (t - 0.55 - i * 0.07) % 1.0
        if s < 0.45:
            glow_dot(fx, bezier(*path, s / 0.45), 3.5, GREEN)
    fx = fx.filter(ImageFilter.GaussianBlur(0.6))
    im = Image.alpha_composite(im, fx)

    d = ImageDraw.Draw(im)
    # Orb label
    d.text((ORB[0], ORB[1] + 168), 'Your idea', font=font(26, 700), fill=WHITE, anchor='mm')
    d.text((ORB[0], ORB[1] + 200), 'a note, a call, a voice memo', font=font(18, 450), fill=(170, 176, 196), anchor='mm')
    d.text((430, 46), 'AI agents write, adapt and schedule', font=font(18, 600), fill=(196, 184, 255), anchor='mm')

    for (x, y), ch, on in zip(cards, CHANNELS, lit):
        rounded(d, (x, y, x + CARD_W, y + CARD_H), 14, fill=(28, 30, 42, 235) if not on else (46, 38, 92, 245), outline=(157, 133, 255, 200) if on else (60, 64, 82, 255), width=2)
        im.paste(icons[ch], (x + 12, y + 12), icons[ch])
        d.rounded_rectangle((x + 58, y + 17, x + 58 + 110, y + 25), 4, fill=(110, 116, 140))
        d.rounded_rectangle((x + 58, y + 33, x + 58 + 74, y + 41), 4, fill=(78, 84, 104))

    # Results panel with numbers that climb as results arrive.
    rounded(d, PANEL, 20, fill=(20, 22, 32, 235), outline=(52, 211, 153, 120), width=2)
    grow = 0.5 - 0.5 * math.cos(2 * math.pi * t)
    views = 24.0 + 7.6 * grow
    leads = 31 + int(11 * grow)
    px = PANEL[0] + 24
    d.text((px, PANEL[1] + 26), 'Views', font=font(18, 600), fill=(150, 156, 176))
    d.text((px, PANEL[1] + 50), f'{views:.1f}K', font=font(44, 750), fill=WHITE)
    d.text((px, PANEL[1] + 128), 'Pipeline', font=font(18, 600), fill=(150, 156, 176))
    d.text((px, PANEL[1] + 152), f'{leads} leads', font=font(36, 750), fill=GREEN)
    d.text((PANEL[0] + 110, PANEL[3] + 30), 'flows back to you', font=font(18, 600), fill=(150, 220, 200), anchor='mm')
    return im.convert('RGB')

frames = [frame(f) for f in range(FRAMES)]
frames[FRAMES // 2].save('content-os-hero.png', optimize=True)  # static fallback, 1200x600
small = [fr.resize((600, 300), Image.LANCZOS) for fr in frames]
mosaic = Image.new('RGB', (600, 300 * 4))
for k, idx in enumerate([0, FRAMES // 4, FRAMES // 2, 3 * FRAMES // 4]):
    mosaic.paste(small[idx], (0, 300 * k))
pal = mosaic.quantize(colors=192, method=Image.MAXCOVERAGE)
q = [s.quantize(palette=pal, dither=Image.FLOYDSTEINBERG) for s in small]
q[0].save('content-os-hero.gif', save_all=True, append_images=q[1:], duration=70, loop=0, optimize=True, disposal=1)
import os
print('gif bytes', os.path.getsize('content-os-hero.gif'), 'png bytes', os.path.getsize('content-os-hero.png'))
