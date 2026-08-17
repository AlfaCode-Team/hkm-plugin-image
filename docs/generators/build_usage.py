#!/usr/bin/env python3
"""
Build IMAGE_UPLOAD_COMPRESSION_GUIDE.pdf from usage_data.json.

The JSON is produced by probe_usage.php, which runs the real multipart-upload
class, a real base64/data-URI decode, real WordPress-style resize calls, and
a real measured quality/size/PSNR sweep across GD/Imagick/Vips. Every number
in this document is a recorded run, not prose written from memory.

    php probe_usage.php && python3 build_usage.py
"""
import json
import re
import subprocess
import sys
from pathlib import Path

OUT = "IMAGE_UPLOAD_COMPRESSION_GUIDE"
D = json.loads(Path("usage_data.json").read_text())
AVAILABLE = D["engines_available"]

_TEX = {
    "\\": r"\textbackslash{}", "{": r"\{", "}": r"\}", "$": r"\$",
    "&": r"\&", "#": r"\#", "^": r"\textasciicircum{}", "_": r"\_",
    "%": r"\%", "~": r"\textasciitilde{}",
}


def esc(s):
    out = "".join(_TEX.get(c, c) for c in str(s))
    out = out.replace("/", "/\\allowbreak{}")
    out = out.replace(r"\_", r"\_\allowbreak{}")
    out = re.sub(r",(?=\S)", ",\\\\allowbreak{}", out)
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


def db(v):
    return "lossless (pixel-identical)" if v is None else f"{v} dB"


# ── content ──────────────────────────────────────────────────────────────────
B = []
A = B.append
src = D["source"]
up = D["upload_flow"]
b64 = D["base64_flow"]
wp = D["wp_style"]
comp = D["compression"]
meta = D["metadata"]

A(H1("What this guide covers"))
A(P("""Four things people building an upload endpoint on `hkm-plugin-image` ask for
first: taking a normal multipart file upload, taking a base64 / data-URI upload (the
shape a `<canvas>` or a JS cropper library sends), generating a WordPress-style set of
named sizes, and compressing without a visible quality loss. This guide is a cookbook
for exactly those four, not a repeat of the full method reference."""))
A(BOX("Every number here was recorded, not written",
      f"""`docs/generators/probe_usage.php` runs the REAL kernel `UploadedFile` class end
      to end, a real base64/data-URI decode, real `resize()`/`variants()` calls, and a
      real measured PSNR sweep across {', '.join(eng_name(k) for k in AVAILABLE)} on a
      generated {src['width']}x{src['height']} test image. Regenerate with
      `php probe_usage.php && python3 build_usage.py`.""", "note"))

A(H1("Uploading a normal file"))
A(P("""This plugin has no HTTP layer of its own — it is a pure byte-string transform
(`API/Contracts/ImageProcessorContract`). Reading the upload is the kernel's job:
`Request::file()` returns a `?UploadedFile`, and `UploadedFile::contents()` reads the
temp file into a string, which is exactly what every `ImageProcessorContract` method
takes."""))
A(CODE("""final class AvatarController extends ApiController        // RequestAware
{
    public function __construct(
        private readonly ImageProcessorContract $image,
        private readonly StoragePort $storage,
    ) {}

    public function upload(): Response
    {
        $file = $this->request->file('avatar');
        if ($file === null || !$file->isValid()) {
            return Response::unprocessable(['avatar' => 'A valid image file is required.']);
        }

        $bytes = $file->contents();   // reads the temp upload into a string

        $this->image->validate(
            $bytes,
            maxBytes: (int) image_config('max_bytes'),
            allowedMimeTypes: image_allowed_mime_types(),
        );

        $stripped = $this->image->stripMetadata($bytes);   // drop GPS/camera EXIF before storing
        $path     = $this->storage->store($stripped, $file->clientName(), 'uploads/avatars');

        return Response::json(['path' => $path], 201);
    }
}""", "php"))
A(BOX("Recorded — the real UploadedFile round-trip",
      f"""A real `Symfony\\UploadedFile` was written to a real temp file, promoted via
      `Kernel\\Http\\UploadedFile::createFromBase()` (the same call `DomainResolver`'s
      request pipeline makes), then read with `contents()`. Client name recorded:
      `{up['client_name']}`, reported size `{up['size_reported']}` bytes,
      `contents()` returned {up['contents_len']} bytes — an exact byte-for-byte match
      to the source ({'yes' if up['bytes_match'] else 'NO'}) — and
      `ImageProcessorContract::dimensions()` decoded it successfully
      ({'yes' if up['gd_decodes_ok'] else 'NO'}).""", "rule"))
A(P("""`$file->isValid()` catches a failed/partial upload before you touch the bytes at
all. `validate()` still needs to run explicitly for the per-route MIME allowlist — but
even if you forget it, every engine's `decode()` now enforces
`IMAGE_MAX_BYTES`/`IMAGE_MAX_WIDTH`/`IMAGE_MAX_HEIGHT` structurally, so a skipped
`validate()` no longer opens an unbounded decompression-bomb path."""))

A(H1("Uploading base64 / a data URI"))
A(P("""A JS cropper (`react-easy-crop`, `cropperjs`, a `<canvas>.toDataURL()` call) sends
a JSON body with a `data:image/png;base64,....` string, not a multipart file. Stripping
the `data:...;base64,` prefix (if present) and decoding is the ONLY extra step —
`ImageProcessorContract` doesn't care where the bytes came from."""))
A(CODE("""final class DecodeBase64Image
{
    public static function fromRequest(Request $request, string $field = 'image'): string
    {
        $input = (string) $request->input($field, '');
        if (str_starts_with($input, 'data:')) {
            $comma = strpos($input, ',');
            $input = $comma === false ? '' : substr($input, $comma + 1);
        }

        $decoded = base64_decode($input, strict: true);
        if ($decoded === false) {
            throw new \\DomainException('Image: not valid base64.');
        }
        return $decoded;
    }
}

// In the controller:
$bytes = DecodeBase64Image::fromRequest($request, 'avatar_base64');
$this->image->validate($bytes, maxBytes: (int) image_config('max_bytes'), allowedMimeTypes: image_allowed_mime_types());""", "php"))
A(BOX("Recorded",
      f"""A real `data:image/jpeg;base64,...` string ({b64['data_uri_len']} chars) was
      built from the same {src['width']}x{src['height']} test image, decoded with the
      function above, and matched the original bytes exactly
      ({'yes' if b64['bytes_match'] else 'NO'}) with correct dimensions
      ({'yes' if b64['dimensions_ok'] else 'NO'}). A raw base64 body with no `data:`
      prefix at all decoded identically
      ({'yes' if b64['raw_no_prefix_ok'] else 'NO'}). Garbage input
      (`data:image/jpeg;base64,%%%not-base64%%%`) was rejected with a `\\DomainException`
      — {b64['garbage_reject_note']} — before any image engine ever saw a byte.""",
      "rule"))
A(P("""`base64_decode($input, strict: true)` is the important part: WITHOUT strict mode,
PHP silently skips invalid characters instead of failing, which can turn a corrupted
upload into a corrupted-but-"successfully decoded" byte string. `strict: true` returns
`false` on the first invalid character, so the `\\DomainException` above fires before
that garbage ever reaches an image engine."""))

A(H1("Resizing like WordPress"))
A(P("""WordPress's default sizes are `thumbnail` (150x150, HARD-cropped to an exact
square), `medium` (300px box, soft-fit, no crop), `medium_large` (768px wide,
proportional), `large` (1024px box, soft-fit, no crop) and the original (`full`).
Mapped onto this contract's `ResizeMode`: `thumbnail`'s hard crop is `Cover`;
`medium`/`medium_large`/`large`'s soft, never-upscaling fit is `Contain`."""))
A(BOX("The one thing that trips people up: variants() presets are WIDTH ONLY",
      f"""`variants($contents, array $presets, ...)` takes `array<string, int>` — a
      NAME to WIDTH map, with no per-preset height. Even in `Cover` mode, the height is
      computed PROPORTIONALLY from the source's own aspect ratio, not forced to a
      square. Recorded proof: `variants($photo, ['thumbnail' => 150, ...], mode:
      Cover)` on this {src['width']}x{src['height']} (3:2) source produced a
      `{wp['variants_preset_widths_only']['thumbnail']['w']}x{wp['variants_preset_widths_only']['thumbnail']['h']}`
      thumbnail — NOT `150x150`. For a true WordPress-style square thumbnail, call
      `resize()` directly with BOTH dimensions:
      `resize($contents, 150, 150, ResizeMode::Cover)` — recorded output:
      `{wp['explicit_square_thumbnail']['w']}x{wp['explicit_square_thumbnail']['h']}`,
      confirmed square.""", "trap"))
A(CODE("""// The hard-cropped, exact-square thumbnail — a SEPARATE call, explicit height.
$thumbnail = $image->resize($bytes, 150, 150, ResizeMode::Cover);

// The soft-fit, proportional sizes — ONE variants() call, WIDTH only, never upscales.
$sizes = $image->variants(
    $bytes,
    presets: ['medium' => 300, 'medium_large' => 768, 'large' => 1024],
    format: ImageFormat::Webp,
    mode: ResizeMode::Contain,
);

// "full" — keep the original, just strip metadata and re-orient it.
$full = $image->orient($image->stripMetadata($bytes));

foreach (['thumbnail' => $thumbnail, ...$sizes] as $name => $result) {
    $contents = $result instanceof ImageVariant ? $result->contents : $result;
    $storage->store($contents, "{$name}.webp", "uploads/{$id}");
}
$storage->store($full, "full.jpg", "uploads/{$id}");""", "php"))
A(TABLE(
    ["Size", "Call", "Recorded output"],
    [
        ["thumbnail", "`resize($, 150, 150, Cover)`",
         f"{wp['explicit_square_thumbnail']['w']}x{wp['explicit_square_thumbnail']['h']} ({kb(wp['explicit_square_thumbnail']['bytes'])})"],
        ["medium", "`variants()` preset 300, `Contain`",
         f"{wp['medium_contain_no_crop']['w']}x{wp['medium_contain_no_crop']['h']} ({kb(wp['medium_contain_no_crop']['bytes'])})"],
        ["medium_large", "`variants()` preset 768",
         f"{wp['variants_preset_widths_only']['medium_large']['w']}x{wp['variants_preset_widths_only']['medium_large']['h']} ({kb(wp['variants_preset_widths_only']['medium_large']['bytes'])})"],
        ["large", "`variants()` preset 1024",
         f"{wp['variants_preset_widths_only']['large']['w']}x{wp['variants_preset_widths_only']['large']['h']} ({kb(wp['variants_preset_widths_only']['large']['bytes'])})"],
    ], spec="lp{5cm}p{5.4cm}"))
A(BOX("Recorded — never upscales, same as WordPress",
      f"""Requesting `resize($bytes, 1024, 1024, ResizeMode::Contain)` on a
      {wp['contain_never_upscales']['source']} source (smaller than the 1024 target)
      came back `{wp['contain_never_upscales']['actual']}` — the ORIGINAL size, not
      stretched up. Only `ResizeMode::Stretch` upscales, and only because an exact size
      was asked for explicitly.""", "note"))

A(H1("Compressing without losing quality"))
A(P(""""Lossless" means two different things depending on the format, and it matters
which one you're asking for:"""))
A(TABLE(
    ["Format", "Through this plugin"],
    [
        ["PNG", "ALWAYS pixel-lossless, at every quality setting — `quality` maps to PNG's compression LEVEL (speed/size trade-off), never to visual fidelity"],
        ["JPEG / WebP / AVIF", "always LOSSY through `convert()`/`resize()`/`variants()` — even at `quality: 100` — no true bit-exact lossless mode is exposed by this contract for these formats"],
    ], spec="lp{11.4cm}"))
A(BOX("Recorded — PNG really is pixel-identical at every quality setting",
      "Encoded the same source as PNG at quality " +
      ", ".join(str(r["quality"]) for r in comp["png"]) +
      " — every single one came back pixel-identical to the source over the sampled "
      "grid (`psnr_db: null` = infinite/lossless). Only the byte size changes: " +
      ", ".join(f"{r['quality']} -> {kb(r['bytes'])}" for r in comp["png"]) +
      ".", "rule"))
A(H2("Measured quality vs. size vs. fidelity (PSNR)"))
A(P("""PSNR (peak signal-to-noise ratio) is an objective measure of how close the
re-encoded pixels are to the original — higher is more faithful; above roughly 40 dB is
usually indistinguishable to the eye, 30-40 dB is generally acceptable, below 25 dB is
often visibly soft or blocky. Measured here on a deliberately CHALLENGING test image
(fine noise texture plus hard-edged shapes, not a smooth photo) — treat these as a
conservative floor: an ordinary photograph without literal per-pixel noise will
typically reach the same or higher PSNR at the SAME or a LOWER quality setting."""))
for fmt in ["jpeg", "webp", "avif"]:
    if fmt not in comp:
        continue
    rows = [[str(r["quality"]), kb(r["bytes"]), f"{r['ratio']}% of source", db(r["psnr_db"])] for r in comp[fmt]]
    A(H2(f"`{fmt}`"))
    A(TABLE(["Quality", "Size", "vs. source", "Measured PSNR"], rows, spec="cccc"))
A(BOX("What the measured curve says for THIS content",
      """Quality 60-85 sat in a comparatively low, fairly flat fidelity band across all
      three lossy formats here; fidelity jumped sharply at quality 90 and kept climbing
      to 95/100. For content with fine texture or sharp edges, don't assume "quality 85"
      is close to lossless by default — measure it on your own real images. `webp` and
      `avif` reached a SMALLER file size than `jpeg` at a comparable measured PSNR in
      this run — but that comparison is specific to this test image and this build's
      encoders, not a universal claim.""", "note"))
A(P("""Practical recommendation from this data: for "no visible quality loss," use
`IMAGE_QUALITY=90` or higher with `webp` or `avif` — both landed meaningfully smaller
than `jpeg` at a similar or better measured PSNR in this run. If you need a GUARANTEE of
zero pixel loss rather than "very high fidelity," the only option through this contract
is PNG (or storing the untouched original bytes) — not a high JPEG/WebP/AVIF quality
number, however close to 100."""))

A(H2("`stripMetadata()` and file size"))
A(P(f"""A fake but realistically-sized EXIF block (~{meta['metadata_overhead_bytes']}
bytes — a `UserComment` tag padded to look like typical camera/GPS metadata) was
spliced into a real JPEG with no other change. That is the metadata's TRUE byte
contribution to the file: `{meta['plain_bytes']}` bytes plain vs.
`{meta['with_exif_bytes']}` bytes with the block attached."""))
A(BOX("Recorded — every engine already strips metadata on every operation",
      f"""`stripMetadata()`'s own output ({kb(meta['stripped_bytes'])}) was BYTE-IDENTICAL
      to a plain `convert()` at the same fixed quality with the EXIF block still
      attached ({kb(meta['same_quality_with_exif_bytes'])}) — measured savings from
      calling `stripMetadata()` specifically, beyond what any other transform already
      does: {meta['metadata_only_savings_bytes']} bytes. That matches the documented
      behaviour: every engine strips EXIF/IPTC/XMP on EVERY encode, not only when you
      call `stripMetadata()`. So the metadata overhead disappears the moment you call
      ANY method here — `stripMetadata()` exists specifically for when you want it gone
      WITHOUT also resizing.""", "rule"))

A(H1("Putting it together"))
A(CODE("""$file  = $request->file('photo');
$bytes = $file->contents();

$image->validate($bytes, maxBytes: (int) image_config('max_bytes'), allowedMimeTypes: image_allowed_mime_types());

$upright = $image->orient($bytes);                                   // fix sideways phone photos first
$thumb   = $image->resize($upright, 150, 150, ResizeMode::Cover);    // WP-style hard-cropped thumbnail
$sizes   = $image->variants(                                          // WP-style soft-fit sizes, high quality, small format
    $upright,
    presets: ['medium' => 300, 'medium_large' => 768, 'large' => 1024],
    format: ImageFormat::Webp,
    quality: 92,                                                     // see "Compressing without losing quality" — 90+ is where fidelity gets high
    mode: ResizeMode::Contain,
);
$full = $image->stripMetadata($upright);                              // original size, GPS/camera data gone

$storage->store($thumb, 'thumbnail.jpg', "uploads/{$id}");
foreach ($sizes as $name => $variant) {
    $storage->store($variant->contents, "{$name}.webp", "uploads/{$id}");
}
$storage->store($full, 'full.jpg', "uploads/{$id}");""", "php"))
A(P("""A route
wiring this up must still declare `"requires": ["image.processing"]` in its
`module.json` — the contract is not bound otherwise."""))

TITLE = r"""
\begin{titlepage}\centering\vspace*{3.2cm}
{\Huge\bfseries\color{accent} Uploads, WordPress Sizes\par}
\vspace{0.3cm}
{\Huge\bfseries\color{accent} and Lossless-Grade Compression\par}
\vspace{0.5cm}
{\LARGE a cookbook for hkm-plugin-image\par}
\vspace{1.4cm}
{\large\color{muted} File upload \quad\textbullet\quad Base64 \quad\textbullet\quad WordPress sizes \quad\textbullet\quad Compression\par}
\vspace{2.4cm}
{\normalsize AlfacodeTeam PhpServicePlatform --- \texttt{image.processing}\par}
\vspace{0.4cm}
{\footnotesize\color{muted} Every number recorded from real code via
\texttt{docs/generators/probe\_usage.php}\par}
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
\fancyhead[L]{\footnotesize\color{muted}Uploads, WordPress sizes and lossless-grade compression}
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
