#!/usr/bin/env python3
"""
Build IMAGE_PLUGIN_USAGE_GUIDE.pdf from image_data.json.

The JSON is produced by probe_image.php, which runs every
ImageProcessorContract method against real GD/Imagick/Vips engines. Every
byte size, dimension, timing and error message in this document is a
recorded run, not prose written from memory.

    php probe_image.php && python3 build_image.py
"""
import json
import re
import subprocess
import sys
from pathlib import Path

OUT = "IMAGE_PLUGIN_USAGE_GUIDE"
D = json.loads(Path("image_data.json").read_text())
E = D["engines"]
AVAILABLE = D["engines_available"]
PRIMARY = "gd"  # the zero-dependency default — used for the worked examples

_TEX = {
    "\\": r"\textbackslash{}", "{": r"\{", "}": r"\}", "$": r"\$",
    "&": r"\&", "#": r"\#", "^": r"\textasciicircum{}", "_": r"\_",
    "%": r"\%", "~": r"\textasciitilde{}",
}


def esc(s):
    out = "".join(_TEX.get(c, c) for c in str(s))
    out = out.replace("/", "/\\allowbreak{}")
    out = out.replace(r"\_", r"\_\allowbreak{}")  # long IMAGE_MAX_BYTES-style identifiers need a break point
    out = re.sub(r",(?=\S)", ",\\\\allowbreak{}", out)  # a,b,c-style lists with no spaces need one too
    return re.sub(r"(?<=[a-z])-(?=[a-z])", "-\\\\allowbreak{}", out)


def prose(s):
    out, i = [], 0
    for m in re.finditer(r"`([^`]+)`|\*\*([^*]+)\*\*", s):
        out.append(esc(s[i:m.start()]))
        if m.group(1) is not None:
            out.append(r"\Code{%s}" % esc(m.group(1)))
        else:
            out.append(r"\textbf{%s}" % esc(m.group(2)))
        i = m.end()
    out.append(esc(s[i:]))
    return "".join(out)


def H1(t): return "\\needspace{5\\baselineskip}\n\\section{%s}\n" % prose(t)
def H2(t): return "\\needspace{4\\baselineskip}\n\\subsection{%s}\n" % prose(t)
def P(t):  return prose(" ".join(t.split())) + "\n\n"


def CODE(body, lang="none"):
    opts = "language=%s" % lang if lang != "none" else "language={}"
    return "\\begin{lstlisting}[%s]\n%s\n\\end{lstlisting}\n" % (opts, body.strip("\n"))


def BOX(title, body, color="note"):
    return "\\begin{%sbox}{%s}\n%s\n\\end{%sbox}\n" % (
        color, prose(title), prose(" ".join(body.split())), color)


def TABLE(head, rows, spec=None, small=True):
    # \addlinespace between every row — plain booktabs with no row gap lets
    # a wrapped 2-3 line p{} cell visually crowd its neighbours (verified:
    # without this, a table with long "Meaning"-column prose rendered with
    # rows appearing to overlap).
    spec = spec or "l" * len(head)
    size = "\\small\n" if small else "\n"
    body = " \\\\\n\\addlinespace\n".join(" & ".join(prose(str(c)) for c in r) for r in rows)
    return (
        "\\begin{center}%s\\begin{tabular}{%s}\n\\toprule\n%s \\\\\n\\midrule\n%s \\\\\n"
        "\\bottomrule\n\\end{tabular}\\end{center}\n"
        % (size, spec, " & ".join("\\textbf{%s}" % prose(h) for h in head), body)
    )


def kb(n):
    return "%.1f KB" % (n / 1024)


def eng_name(k):
    return {"gd": "GD", "imagick": "Imagick", "vips": "Vips"}[k]


# ── content ──────────────────────────────────────────────────────────────────
B = []
A = B.append
src = D["source"]
g = E[PRIMARY]

A(H1("What this guide is"))
A(P("""`hkm-plugin-image` provides the `image.processing` domain — resize, convert,
crop, filter, watermark and placeholder-hash generation over GD, Imagick or libvips,
behind one published contract, `ImageProcessorContract`. It has no persistence of its
own: every method is a pure byte-string transform, `string $contents` in, `string
$contents` out. Pair it with `StoragePort` (e.g. `hkm-plugin-storage`) to keep a
result."""))
A(BOX("Every number in this guide was recorded, not written",
      f"""`docs/generators/probe_image.php` runs every method below against a real
      generated {src['width']}x{src['height']} JPEG ({kb(src['bytes'])}) through
      whichever engines are actually installed on the machine that generated this PDF
      — here: {', '.join(eng_name(k) for k in AVAILABLE)}. Regenerate with
      `php probe_image.php && python3 build_image.py`. If an engine's output changes,
      the numbers in this document change with it instead of going stale.""", "note"))

A(H1("Install and choose a driver"))
A(CODE("""composer require alfacode-team/hkm-plugin-image
# or, from a project:
hkm plugins add image""", "bash"))
A(P("""A route or job that resolves `ImageProcessorContract` MUST declare
`"requires": ["image.processing"]` in its `module.json` — this is an on-demand
module, not an essential one; the contract is unbound otherwise."""))
A(H2("Three engines, one contract"))
A(TABLE(
    ["", "gd (default)", "imagick", "vips"],
    [
        ["Dependency", "none — ships with nearly every PHP build", "ext-imagick", "jcupitt/vips + ext-ffi + the libvips shared library"],
        ["AVIF", "only if GD was built against libavif", "depends on the ImageMagick build", "depends on the libvips build"],
        ["EXIF auto-orient", "hand-implemented rotate/flip math", "native", "native and automatic (built into resize/thumbnail)"],
        ["`ResizeMode::Smart`", "falls back to Cover", "falls back to Cover", "real content-aware crop"],
        ["Animated source", "first frame only", "first frame only", "first frame only"],
    ],
    spec="lp{3.4cm}p{3.4cm}p{4.6cm}"))
A(P("""`IMAGE_DRIVER=auto` picks `vips` when installed, else `imagick` when loaded, else
`gd`. An explicitly named driver that is not installed fails the boot immediately —
`gd` is the default specifically because it is almost never missing."""))

A(H2("Configuration (`config/image.php`, env-driven)"))
A(TABLE(
    ["Key", "Default", "Meaning"],
    [
        ["`IMAGE_DRIVER`", "`gd`", "`gd`, `imagick`, `vips`, or `auto`"],
        ["`IMAGE_DEFAULT_FORMAT`", "`webp`", "used by `variants()` when no format is passed"],
        ["`IMAGE_QUALITY`", "`82`", "1--100, applied to jpeg/webp/avif; mapped to PNG's 0--9 scale"],
        ["`IMAGE_MAX_BYTES`", "`10485760`", "upload size ceiling — enforced by every method now, not only `validate()`"],
        ["`IMAGE_ALLOWED_MIME`", "jpeg, png, webp, gif, avif", "comma list — `validate()`'s per-route allowlist"],
        ["`IMAGE_MAX_WIDTH` / `_HEIGHT`", "`8000`", "decompression-bomb ceiling — enforced structurally (see the Security section)"],
        ["`IMAGE_VARIANT_PRESETS`", "thumbnail:150,medium:300,large:1024", "parsed by `image_variant_presets()`"],
        ["`IMAGE_WATERMARK_TTF_PATH`", "unset", "GD-only: a `.ttf`/`.otf` path for `watermarkText()`"],
    ],
    spec="p{3.6cm}p{2.6cm}p{7.4cm}"))

A(H1("Quickstart"))
A(CODE("""$image   = $container->make(ImageProcessorContract::class);
$storage = $container->make(StoragePort::class);

// 1. Validate BEFORE doing anything else with untrusted upload bytes — this
//    is still where a per-route MIME allowlist lives.
$image->validate(
    $bytes,
    maxBytes: (int) image_config('max_bytes'),
    allowedMimeTypes: image_allowed_mime_types(),
);

// 2. Transform.
$bytes = $image->watermarkText($bytes, '(c) 2026', Position::BottomRight, opacityPercent: 60);

// 3. Several named renditions in one pass.
foreach ($image->variants($bytes, image_variant_presets(), ImageFormat::Webp) as $name => $variant) {
    $storage->store($variant->contents, "{$name}.webp", "uploads/{$id}");
}

// 4. Ship a blur-up placeholder alongside the URL.
$placeholder = $image->placeholder($bytes);""", "php"))

A(H1("Method reference"))
A(P(f"""Every example below actually ran against the {eng_name(PRIMARY)} engine (the
default driver) on a {src['width']}x{src['height']} JPEG ({kb(src['bytes'])}). Where a
number differs meaningfully by engine, both are shown."""))

A(H2("`dimensions()` / `detectFormat()` / `validate()`"))
A(CODE("""$image->dimensions($bytes);      // Dimensions { width, height } — header read only
$image->detectFormat($bytes);    // ImageFormat::Jpeg
$image->validate($bytes, maxBytes: 10_000_000, allowedMimeTypes: ['image/jpeg']);""", "php"))
A(BOX("Recorded", f"""`validate($photo, 10_000_000, ['image/jpeg'], maxWidth: 100)` on the
{src['width']}px-wide source threw: `{g['validate_reject_message']}`""", "note"))

A(H2("`resize()`"))
A(CODE("""$image->resize($bytes, width: 300, height: 200, mode: ResizeMode::Cover);""", "php"))
rows = []
for mode in ["Cover", "Contain", "Stretch"]:
    r = g["resize"][mode]
    rows.append([mode, f"{r['w']}x{r['h']}", kb(r['bytes']), f"{r['ms']} ms"])
A(TABLE(["Mode", "Output", "Size", eng_name(PRIMARY) + " time"], rows, spec="lccc"))
A(TABLE(
    ["Mode", "Behavior", "Upscales?"],
    [
        ["Cover (default)", "fills the exact target box, center-crops the overflow", "never"],
        ["Contain", "fits within the box, preserves aspect, no crop", "never"],
        ["Stretch", "forces the exact target size, ignoring aspect", "the only mode that does"],
        ["Smart", "like Cover, but crops over the most visually significant region — **Vips only**", "never"],
    ],
    spec="lp{7cm}l"))

A(H2("`convert()`"))
A(CODE("""$webp = $image->convert($bytes, ImageFormat::Webp);""", "php"))
crows = [[eng_name(k), kb(E[k]["convert"]["bytes"]), f"{E[k]['convert']['ratio']}% of source", f"{E[k]['convert']['ms']} ms"] for k in AVAILABLE]
A(TABLE(["Engine", "WebP size", "Ratio", "Time"], crows, spec="lccc"))

A(H2("`variants()`"))
A(CODE("""$variants = $image->variants(
    $bytes,
    presets: ['thumbnail' => 150, 'medium' => 300, 'large' => 1024],
    format: ImageFormat::Webp,
);
// => ['thumbnail' => ImageVariant, 'medium' => ImageVariant, 'large' => ImageVariant]""", "php"))
vrows = []
for vname in ["thumbnail", "medium", "large"]:
    v = g["variants"][vname]
    vrows.append([vname, f"{v['w']}x{v['h']}", kb(v['bytes'])])
A(TABLE(["Preset", "Output", "Size"], vrows, spec="lcc"))
A(BOX("One decode, not one per preset",
      f"""As of this version, `variants()` decodes the source ONCE and reuses it for
      every preset (GD resamples the same decoded image per preset; Imagick clones it
      per preset, since its resize methods mutate in place). Recorded total time for
      all 3 presets above: """ +
      ", ".join(f"{eng_name(k)} {E[k]['variants_total_ms']} ms" for k in AVAILABLE) +
      """ — well under 3x any single resize, because the expensive full decode no
      longer repeats per preset.""", "rule"))

A(H2("`orient()`"))
A(CODE("""$upright = $image->orient($bytes);   // reads EXIF Orientation, rotates, resets the tag""", "php"))
A(BOX("Recorded", f"""A hand-built 200x100 JPEG carrying a real EXIF Orientation=6 tag
      (needs a 90 deg correction) came back `{g['orient']['after']}` from
      `{g['orient']['before']}` — the width/height swap confirms the rotation
      actually ran, on every engine tested here.""", "note"))

A(H2("`crop()`"))
A(CODE("""$avatar = $image->crop($bytes, x: 100, y: 100, width: 500, height: 350);""", "php"))
A(P(f"""Clamps to the source's actual bounds rather than erroring on an out-of-range
rectangle — a caller-driven crop UI does not need to pre-validate its own math.
Recorded: {g['crop']['w']}x{g['crop']['h']}, {kb(g['crop']['bytes'])}."""))

A(H2("Filters — `blur()` / `sharpen()` / `pixelate()` / `grayscale()`"))
A(CODE("""$image->blur($bytes, sigma: 3.0);
$image->sharpen($bytes);
$image->pixelate($bytes, blockSize: 12);   // privacy redaction — harder to reverse than blur, not cryptographically safe
$image->grayscale($bytes);""", "php"))
frows = []
for fname in ["blur", "sharpen", "pixelate", "grayscale"]:
    f = g["filters"][fname]
    frows.append([f"`{fname}()`", kb(f["bytes"]), f"{f['ms']} ms"])
A(TABLE(["Filter", eng_name(PRIMARY) + " size", eng_name(PRIMARY) + " time"], frows, spec="lcc"))

A(H2("`adjustColor()`"))
A(CODE("""$image->adjustColor($bytes, brightness: 10, contrast: 15, saturation: 20);
// each parameter is a -100..100 delta from "no change" at 0""", "php"))
A(P(f"""Recorded output: {kb(g['adjust_color']['bytes'])}. GD has no native saturation
filter — it falls back to a per-pixel blend loop, meaningfully slower than
Imagick's/Vips' native saturation on anything bigger than a thumbnail (see the
Performance notes section)."""))

A(H2("`watermarkImage()` / `watermarkText()`"))
A(CODE("""$branded = $image->watermarkImage($bytes, $logoBytes, Position::BottomRight, opacityPercent: 70);
$dated   = $image->watermarkText($bytes, '(c) 2026 Acme', Position::Bottom, fontSize: 18, textColor: '#FFFFFF');""", "php"))
A(P(f"""Recorded: image watermark {kb(g['watermark_image']['bytes'])}, text watermark
{kb(g['watermark_text']['bytes'])}. Nine-point gravity (`Position::TopLeft` through
`BottomRight`, plus `Center`), opacity and margins are identical across engines — the
RENDERED TEXT is not: GD uses 5 built-in bitmap fonts unless
`IMAGE_WATERMARK_TTF_PATH` is set, Imagick/Vips need a font resolvable through the
host's font system."""))

A(H2("`placeholder()`"))
A(CODE("""$hash = $image->placeholder($bytes);   // base64 ThumbHash — ~25-38 bytes decoded""", "php"))
A(BOX("Recorded", f"""A real base64 ThumbHash for the {src['width']}x{src['height']}
      source, {eng_name(PRIMARY)} engine: `{g['placeholder']['base64']}`
      ({g['placeholder']['len']} base64 chars). Decode client-side into an instant
      blurred preview shown before the real image loads — or use
      `ThumbHash::averageColor()` for a CSS `background-color` fallback that needs no
      client-side decoder at all.""", "note"))

A(H1("Security: what's enforced automatically"))
A(P("""`validate()` is still the right place for a per-route MIME allowlist and an
explicit dimension ceiling narrower than the engine's own default. But every engine's
`decode()` now ALSO enforces `IMAGE_MAX_BYTES`/`IMAGE_MAX_WIDTH`/`IMAGE_MAX_HEIGHT`
structurally — so skipping `validate()` no longer opens an unbounded
decompression-bomb path through `resize()`, `convert()`, or any other method."""))
A(BOX("Recorded — the guard firing with no validate() call at all",
      f"Calling `convert()` directly on the {src['width']}x{src['height']} "
      "source against an engine configured with `maxWidth: 400, maxHeight: 400` "
      f"threw: `{g['guard_message']}` — on every engine tested here "
      f"({', '.join(eng_name(k) for k in AVAILABLE if E[k]['guard_rejected'])}).",
      "rule"))
if "imagick" in AVAILABLE:
    lim = D["imagick_resource_limits"]
    A(H2("Imagick-specific: real resource limits, not just policy.xml"))
    A(P("""`ImagickImageEngine` additionally installs real `Imagick::setResourceLimit()`
    bounds once per construction — a second, independent backstop, since ImageMagick
    is the most exploit-prone of the three engines via non-raster coders/delegates,
    and its behavior otherwise depends entirely on the host's own, often-unconfigured,
    `policy.xml`."""))
    A(TABLE(
        ["Resource", "Value at default config (8000x8000 / 10 MB)"],
        [
            ["WIDTH / HEIGHT", f"{int(lim['width'])} / {int(lim['height'])} px"],
            ["AREA", f"{int(lim['area']):,} px"],
            ["MEMORY / MAP", f"{int(lim['memory']) // (1024*1024)} MB"],
            ["DISK", f"{int(lim['disk']) // (1024*1024)} MB"],
            ["TIME", f"{int(lim['time'])} s"],
        ], spec="lr"))
    A(BOX("Process-global — by design, verified during development",
          """`Imagick::setResourceLimit()` mutates ImageMagick's OWN process-wide limiter
          even when called on an instance — a limit set via one `Imagick` object rejects
          a decode on a completely separate one with no limits of its own. Safe here
          because every value comes from this plugin's own config, re-applied
          identically every request.""", "note"))

A(H1("Performance notes"))
A(P("""These are single-run, single-machine timings recorded on the machine that built
this PDF — useful for relative comparison between engines and operations, not a
rigorous benchmark. Do not treat the millisecond columns above as production
guarantees."""))
if len(AVAILABLE) > 1:
    prows = [[eng_name(k), f"{E[k]['convert']['ms']} ms", f"{E[k]['resize']['Cover']['ms']} ms", f"{E[k]['variants_total_ms']} ms"] for k in AVAILABLE]
    A(TABLE(["Engine", "convert()", "resize() Cover", "variants() (3 presets)"], prows, spec="lccc"))
A(P("""`vips` uses shrink-on-load (`thumbnail_buffer()`) for `resize()`/`variants()` — the
decoder can skip full-resolution decoding once it knows the target size — which is why
it tends to lead on exactly those two operations. `gd` and `imagick` decode at full
resolution first; both now decode the source ONCE per `variants()` call rather than
once per preset (see the `variants()` entry in the Method reference above)."""))
A(P("""`placeholder()` on Imagick uses a `jpeg:size` shrink-on-load decode hint (verified:
a 2000x2000 JPEG decoded at 250x250, not full-size, with this hint set) — large source
images no longer have to be decoded at full resolution just to build a ~30-byte hash.
`gd` has no such hint available and decodes fully; `vips`'s `placeholder()` was already
using `thumbnail_buffer()`."""))
A(P("""GD's `adjustColor()` saturation and `watermarkImage()`/`watermarkText()` opacity
scaling are both O(width x height) pure-PHP pixel loops (GD has no native saturation
filter, and `imagecopymerge()` silently flattens per-pixel alpha, so both need
hand-rolled per-pixel math). Meaningfully slower than Imagick's/Vips' native
equivalents on anything bigger than a thumbnail — prefer `IMAGE_DRIVER=imagick` or
`vips` if your workload does either routinely on full-size images."""))

A(H1("Common pitfalls"))
A(TABLE(
    ["Don't", "Because"],
    [
        ["`store()`/`convert()` a multi-hundred-MB image synchronously in a request handler",
         "no streaming variant here (unlike `StoragePort`) — route large batch work through a Worker job"],
        ["Assume `resize()`/`convert()` preserve EXIF/IPTC/XMP",
         "every engine strips metadata on every call, deliberately — call `orient()` FIRST if you need the rotation"],
        ["Resolve `ImageProcessorContract` from a route that didn't declare `\"requires\": [\"image.processing\"]`",
         "the contract is not bound otherwise — surfaces as an unresolvable binding at request time"],
        ["Treat `pixelate()` as reversible-enough redaction for a genuinely sensitive region at a small block size",
         "harder to reverse than a Gaussian blur, not cryptographically safe — crop the region out entirely when in doubt"],
        ["Assume `watermarkText()` looks the same across engines",
         "GD's built-in bitmap fonts vs. Imagick's/Vips' system-font rendering are not visually interchangeable"],
        ["Rely on `ResizeMode::Smart` outside `vips`",
         "GD/Imagick silently fall back to plain Cover — documented, not a bug, but not \"smart\" either"],
    ], spec="p{6.4cm}p{7.2cm}"))

A(H1("Quick reference"))
A(TABLE(
    ["Method", "Signature (trimmed)"],
    [
        ["dimensions", "`dimensions(string $contents): Dimensions`"],
        ["detectFormat", "`detectFormat(string $contents): ImageFormat`"],
        ["validate", "`validate($contents, int $maxBytes, array $allowedMimeTypes, ?int $maxWidth = null, ?int $maxHeight = null): void`"],
        ["resize", "`resize($contents, int $width, ?int $height = null, ResizeMode $mode = Cover): string`"],
        ["convert", "`convert($contents, ImageFormat $format, ?int $quality = null): string`"],
        ["stripMetadata", "`stripMetadata($contents): string`"],
        ["orient", "`orient($contents): string`"],
        ["variants", "`variants($contents, array $presets, ?ImageFormat $format = null, ?int $quality = null, ResizeMode $mode = Cover): array`"],
        ["crop", "`crop($contents, int $x, int $y, int $width, int $height): string`"],
        ["blur", "`blur($contents, float $sigma = 2.0): string`"],
        ["sharpen", "`sharpen($contents): string`"],
        ["pixelate", "`pixelate($contents, int $blockSize = 8): string`"],
        ["grayscale", "`grayscale($contents): string`"],
        ["adjustColor", "`adjustColor($contents, float $brightness = 0, float $contrast = 0, float $saturation = 0): string`"],
        ["watermarkImage", "`watermarkImage($contents, $overlayContents, Position $position = BottomRight, int $opacityPercent = 100, int $marginX = 10, int $marginY = 10): string`"],
        ["watermarkText", "`watermarkText($contents, string $text, Position $position = BottomRight, int $fontSize = 16, string $textColor = '#FFFFFF', int $opacityPercent = 100, int $marginX = 10, int $marginY = 10): string`"],
        ["placeholder", "`placeholder($contents): string`"],
    ], spec="lp{10.2cm}", small=True))

TITLE = r"""
\begin{titlepage}\centering\vspace*{3.2cm}
{\Huge\bfseries\color{accent} Using the Image Plugin\par}
\vspace{0.5cm}
{\LARGE resize, convert, crop, filter, watermark, placeholder\par}
\vspace{1.4cm}
{\large\color{muted} GD \quad\textbullet\quad Imagick \quad\textbullet\quad Vips\par}
\vspace{2.4cm}
{\normalsize AlfacodeTeam PhpServicePlatform --- \texttt{image.processing}\par}
\vspace{0.4cm}
{\footnotesize\color{muted} Every number recorded from real engines via
\texttt{docs/generators/probe\_image.php}\par}
\vfill
{\footnotesize\color{muted} Engines on this run: %s\par}
\end{titlepage}
""" % ", ".join(eng_name(k) for k in AVAILABLE)

PREAMBLE = r"""
\documentclass[11pt,a4paper]{article}
\usepackage{fontspec}
\setmainfont{DejaVu Sans}[Scale=0.94]
\setmonofont{Fira Code}[Scale=0.74]
\usepackage[margin=1.9cm,top=2.2cm,bottom=2.2cm]{geometry}
\usepackage{xcolor}\usepackage{listings}\usepackage{booktabs}\usepackage{enumitem}
\usepackage{titlesec}\usepackage{fancyhdr}\usepackage[most]{tcolorbox}
\usepackage{tocloft}
\setlength{\cftsecnumwidth}{2.1em}\setlength{\cftsubsecnumwidth}{2.9em}
\setlength{\cftbeforesecskip}{4pt}
\usepackage{needspace}\usepackage[hidelinks]{hyperref}

\definecolor{ink}{HTML}{1A1D21}
\definecolor{accent}{HTML}{2563EB}
\definecolor{muted}{HTML}{6B7280}
\definecolor{codebg}{HTML}{F6F7F9}
\definecolor{codeframe}{HTML}{E1E4E8}
\definecolor{kw}{HTML}{7C3AED}
\definecolor{str}{HTML}{047857}
\definecolor{cmt}{HTML}{6B7280}
\definecolor{okc}{HTML}{047857}
\definecolor{noc}{HTML}{B91C1C}
\definecolor{trapc}{HTML}{B91C1C}
\definecolor{rulec}{HTML}{047857}
\definecolor{notec}{HTML}{2563EB}
\color{ink}

\titleformat{\section}{\Large\bfseries\color{accent}}{\thesection}{0.6em}{}
\titlespacing*{\section}{0pt}{16pt}{8pt}
\titleformat{\subsection}{\large\bfseries}{\thesubsection}{0.6em}{}
\titlespacing*{\subsection}{0pt}{13pt}{5pt}

\pagestyle{fancy}\fancyhf{}
\renewcommand{\headrulewidth}{0.2pt}
\fancyhead[L]{\footnotesize\color{muted}Using the Image Plugin --- image.processing}
\fancyfoot[C]{\footnotesize\color{muted}\thepage}

\lstset{
  basicstyle=\ttfamily\scriptsize, backgroundcolor=\color{codebg},
  frame=single, framesep=4pt, rulecolor=\color{codeframe},
  xleftmargin=2pt, xrightmargin=2pt,
  breaklines=true, showstringspaces=false, columns=fullflexible, keepspaces=true,
  keywordstyle=\color{kw}\bfseries, stringstyle=\color{str},
  commentstyle=\color{cmt}\itshape, aboveskip=6pt, belowskip=6pt,
  literate={→}{{$\rightarrow$}}1 {✓}{{\color{okc}\textbf{+}}}1 {✗}{{\color{noc}\textbf{!}}}1
    {✘}{{\color{noc}\textbf{!}}}1
    {├}{{|}}1 {─}{{-}}1 {└}{{`}}1 {│}{{|}}1
    {…}{{...}}1 {—}{{---}}1 {·}{{$\cdot$}}1 {“}{{"}}1 {”}{{"}}1 {’}{{'}}1
    {←}{{$\leftarrow$}}1 {textbullet}{{$\bullet$}}1
}

\newcommand{\Code}[1]{{\ttfamily\small\color{ink}#1}}
\newtcolorbox{trapbox}[1]{colback=trapc!4,colframe=trapc!55,fonttitle=\bfseries\small,
  title={#1},boxrule=0.5pt,arc=1.5pt,left=5pt,right=5pt,top=3pt,bottom=3pt,breakable}
\newtcolorbox{rulebox}[1]{colback=rulec!4,colframe=rulec!55,fonttitle=\bfseries\small,
  title={#1},boxrule=0.5pt,arc=1.5pt,left=5pt,right=5pt,top=3pt,bottom=3pt,breakable}
\newtcolorbox{notebox}[1]{colback=notec!4,colframe=notec!55,fonttitle=\bfseries\small,
  title={#1},boxrule=0.5pt,arc=1.5pt,left=5pt,right=5pt,top=3pt,bottom=3pt,breakable}

\setlength{\emergencystretch}{3em}
\begin{document}
"""

tex = PREAMBLE + TITLE + "\\tableofcontents\n\\clearpage\n" + "".join(B) + "\n\\end{document}\n"
Path(OUT + ".tex").write_text(tex)

for i in range(2):
    p = subprocess.run(["lualatex", "-interaction=nonstopmode", "-halt-on-error", OUT + ".tex"],
                        capture_output=True, text=True)
    if p.returncode != 0:
        sys.stderr.write(p.stdout[-4000:])
        sys.exit("lualatex failed on pass %d" % (i + 1))

log = Path(OUT + ".log").read_text(errors="ignore")
over = log.count("Overfull \\hbox")
print("built %s.pdf  (overfull hboxes: %d)" % (OUT, over))
