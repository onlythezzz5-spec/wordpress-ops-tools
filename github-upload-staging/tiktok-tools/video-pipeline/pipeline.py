#!/usr/bin/env python3
"""
Video Pipeline Engine v2 — Kling API + FFmpeg
Fixes: token cache, retry, concurrency, safe injection, logging
"""
import os, json, time, subprocess, shutil, hashlib, re, logging
from pathlib import Path
from datetime import datetime, timedelta
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Optional

ROOT = Path(__file__).parent
INPUT_DIR = ROOT / "input"
CLIPS_DIR = ROOT / "clips"
OUTPUT_DIR = ROOT / "output"
BGM_DIR = ROOT / "bgm"
STATES_FILE = ROOT / "states.json"
MAX_RETRIES = 3
RETRY_DELAY = 3  # seconds
MAX_CONCURRENT = 3  # parallel Kling API calls

# ====== Logging ======
LOG_FILE = ROOT / "pipeline.log"
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
        logging.StreamHandler()
    ]
)
log = logging.getLogger("pipeline")


# ====== Config ======
def load_config():
    env_path = ROOT / ".env"
    if env_path.exists():
        with open(env_path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    os.environ.setdefault(k.strip(), v.strip())


# ====== Token Cache ======
_token_cache: dict = {"token": None, "expires_at": None}

def _get_kling_token() -> str:
    """Get Kling JWT with 30min cache."""
    now = datetime.now()
    if _token_cache["token"] and _token_cache["expires_at"] and now < _token_cache["expires_at"]:
        return _token_cache["token"]

    import requests
    ak = os.getenv("KLING_ACCESS_KEY", "")
    sk = os.getenv("KLING_SECRET_KEY", "")
    if not ak or not sk:
        raise RuntimeError("请先填入 KLING_ACCESS_KEY 和 KLING_SECRET_KEY 到 .env")

    resp = requests.post("https://api.klingai.com/v1/token",
        json={"access_key": ak, "secret_key": sk}, timeout=15)
    resp.raise_for_status()
    token = resp.json()["data"]["access_token"]
    _token_cache["token"] = token
    _token_cache["expires_at"] = now + timedelta(minutes=25)  # buffer before actual expiry
    log.info("Kling token refreshed")
    return token


# ====== Retry Wrapper ======
def _retry(func, *args, max_retries=MAX_RETRIES, **kwargs):
    """Retry wrapper with exponential backoff."""
    last_err = None
    for attempt in range(max_retries):
        try:
            return func(*args, **kwargs)
        except Exception as e:
            last_err = e
            if attempt < max_retries - 1:
                delay = RETRY_DELAY * (2 ** attempt)
                log.warning(f"Retry {attempt+1}/{max_retries} after {delay}s: {e}")
                time.sleep(delay)
    raise last_err


# ====== Safe Filename ======
def _safe_name(filename: str) -> str:
    """Sanitize filename, keep extension."""
    name = Path(filename).name
    stem, ext = os.path.splitext(name)
    # Remove path separators, null bytes, leading dots/dashes
    stem = re.sub(r'[\\/:*?"<>|\x00]', '_', stem)
    stem = stem.lstrip('.')
    if not stem:
        stem = "untitled"
    return f"{stem}{ext}"


# ====== Kling API ======
def kling_image_to_video(image_path: str, prompt: str = "", duration: int = 5,
                         model_name: str = "kling-v1-6") -> dict:
    """Call Kling image-to-video API. Returns {'task_id': '...'}"""
    import requests
    token = _get_kling_token()

    with open(image_path, "rb") as f:
        upload_resp = requests.post("https://api.klingai.com/v1/images/upload",
            headers={"Authorization": f"Bearer {token}"},
            files={"image": f}, timeout=30)
        upload_resp.raise_for_status()
    image_key = upload_resp.json()["data"]["image_key"]

    task_resp = requests.post("https://api.klingai.com/v1/videos/image2video",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        json={
            "model_name": model_name,
            "image": image_key,
            "prompt": prompt or "smooth cinematic product shot, professional lighting, 4K",
            "duration": str(duration),
            "mode": "pro",
        }, timeout=15)
    task_resp.raise_for_status()
    return task_resp.json()["data"]


def kling_poll_task(task_id: str, timeout_minutes: int = 10) -> str:
    """Poll Kling task until complete. Returns video download URL."""
    import requests
    deadline = time.time() + timeout_minutes * 60
    while time.time() < deadline:
        token = _get_kling_token()
        resp = requests.get(f"https://api.klingai.com/v1/videos/image2video/{task_id}",
            headers={"Authorization": f"Bearer {token}"}, timeout=10)
        resp.raise_for_status()
        data = resp.json()["data"]
        status = data["task_status"]
        if status == "succeed":
            return data["task_result"]["videos"][0]["url"]
        if status == "failed":
            raise RuntimeError(f"Kling task failed: {data.get('task_status_msg','')}")
        time.sleep(5)
    raise TimeoutError(f"Kling task {task_id} timeout after {timeout_minutes}min")


def _generate_single_clip(args: tuple) -> tuple:
    """Generate one clip with retry. Returns (image_name, clip_path, duration)."""
    image_name, image_path, clip_dir, prompt, duration, model_name, idx, total = args
    dur = int(duration) if duration else 5
    log.info(f"Clip {idx}/{total}: {image_name} ({dur}s) model={model_name}")
    stem = Path(image_name).stem
    clip_name = f"{stem}_{dur}s.mp4"
    clip_path = os.path.join(clip_dir, clip_name)

    if os.path.exists(clip_path):
        log.info(f"  Clip exists, skip: {clip_path}")
        return image_name, clip_path, dur

    # Route to Kling / Jimeng / Veo based on model prefix
    import requests
    if model_name.startswith("veo-"):
        from veo_api import image_to_video as veo_i2v, poll_task_and_save as veo_save
        task = _retry(veo_i2v, image_path, prompt, dur, model_name)
        _retry(veo_save, task, clip_path)
    elif model_name.startswith("jimeng-") or model_name.startswith("seedance"):
        from jimeng_api import image_to_video as jimeng_i2v, poll_task as jimeng_poll
        task = _retry(jimeng_i2v, image_path, prompt, dur, model_name)
        video_url = _retry(jimeng_poll, task["task_id"])
        r = requests.get(video_url, timeout=180)
        r.raise_for_status()
        Path(clip_path).parent.mkdir(parents=True, exist_ok=True)
        Path(clip_path).write_bytes(r.content)
    else:
        task = _retry(kling_image_to_video, image_path, prompt, dur, model_name)
        video_url = _retry(kling_poll_task, task["task_id"], max(10, dur * 2))
        r = requests.get(video_url, timeout=180)
        r.raise_for_status()
        Path(clip_path).parent.mkdir(parents=True, exist_ok=True)
        Path(clip_path).write_bytes(r.content)

    log.info(f"  Done: {clip_path}")
    return image_name, clip_path, dur


# ====== FFmpeg ======
def _find_ffmpeg() -> str:
    bundled = ROOT / "ffmpeg" / "bin" / "ffmpeg.exe"
    if bundled.exists():
        return str(bundled)
    return "ffmpeg"


def _escape_ffmpeg_text(text: str) -> str:
    """Escape special characters for FFmpeg drawtext filter."""
    # Single quotes are the main concern; escape by doubling or wrapping
    return text.replace("'", "'\\\\''").replace(":", "\\:").replace("%", "\\%")


def stitch_clips(clip_paths: list, output_path: str, bgm_path: str = None,
                 caption_text: str = "", durations: list = None,
                 resolution: str = "1080x1920", fps: int = 30, crf: int = 20,
                 transition: str = "fade") -> bool:
    """Stitch clips with configurable quality, transition, resolution, fps."""
    if durations is None:
        durations = [5] * len(clip_paths)
    if len(durations) != len(clip_paths):
        durations = [5] * len(clip_paths)

    # Parse resolution
    w, h = 1080, 1920
    if "x" in resolution:
        parts = resolution.split("x")
        w, h = int(parts[0]), int(parts[1])

    ffmpeg = _find_ffmpeg()
    list_file = ROOT / "temp_concat.txt"
    try:
        # Build filter chain
        vf_parts = [f"scale={w}:{h}:force_original_aspect_ratio=decrease",
                    f"pad={w}:{h}:(ow-iw)/2:(oh-ih)/2", f"fps={fps}"]

        # Transition
        if transition == "fade" and len(clip_paths) > 1:
            # Crossfade: use xfade filter with ffmpeg concat
            # Complex approach: build filter_complex for crossfade
            return _stitch_with_crossfade(clip_paths, durations, output_path, bgm_path,
                                          caption_text, w, h, fps, crf, vf_parts)
        else:
            # Simple concat (no transition or single clip)
            return _stitch_simple(list_file, clip_paths, durations, output_path, bgm_path,
                                  caption_text, w, h, fps, crf, vf_parts)
    finally:
        try:
            list_file.unlink()
        except Exception:
            pass


def _stitch_simple(list_file, clip_paths, durations, output_path, bgm_path,
                   caption_text, w, h, fps, crf, vf_parts) -> bool:
    """Simple concat without crossfade."""
    ffmpeg = _find_ffmpeg()
    with open(list_file, "w", encoding="utf-8") as f:
        for p, d in zip(clip_paths, durations):
            f.write(f"file '{Path(p).absolute().as_posix()}'\n")
            f.write(f"duration {d}\n")
        f.write(f"file '{Path(clip_paths[-1]).absolute().as_posix()}'\n")

    vf = ",".join(vf_parts)
    if caption_text:
        safe_cap = _escape_ffmpeg_text(caption_text)
        fs = max(16, int(w * 0.037))
        vf += f",drawtext=text='{safe_cap}':fontcolor=white:fontsize={fs}:x=(w-text_w)/2:y=h-th-60:box=1:boxcolor=black@0.4:boxborderw=10"

    total_dur = sum(durations)
    if bgm_path and Path(bgm_path).exists():
        cmd = [ffmpeg, "-y",
            "-f", "concat", "-safe", "0", "-i", str(list_file),
            "-stream_loop", "-1", "-i", str(bgm_path),
            "-vf", vf, "-c:v", "libx264", "-crf", str(crf), "-preset", "medium",
            "-pix_fmt", "yuv420p", "-shortest", "-t", str(total_dur),
            "-c:a", "aac", "-b:a", "128k", str(output_path)]
    else:
        cmd = [ffmpeg, "-y",
            "-f", "concat", "-safe", "0", "-i", str(list_file),
            "-vf", vf, "-c:v", "libx264", "-crf", str(crf), "-preset", "medium",
            "-pix_fmt", "yuv420p", "-an", str(output_path)]

    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        log.error(f"FFmpeg failed: {result.stderr[:500]}")
        return False
    log.info(f"Video stitched: {output_path}")
    return True


def _stitch_with_crossfade(clip_paths, durations, output_path, bgm_path,
                           caption_text, w, h, fps, crf, vf_parts) -> bool:
    """Crossfade transition between clips."""
    ffmpeg = _find_ffmpeg()
    # Build filter_complex with crossfade between each pair
    n = len(clip_paths)
    xfade_dur = 0.5  # 0.5s crossfade
    inputs = []
    for p in clip_paths:
        inputs.extend(["-i", str(Path(p).absolute())])

    # Scale all inputs to same resolution/fps first
    scale_filters = []
    for i in range(n):
        scale_filters.append(f"[{i}:v]scale={w}:{h}:force_original_aspect_ratio=decrease,"
                            f"pad={w}:{h}:(ow-iw)/2:(oh-ih)/2,fps={fps},setpts=PTS-STARTPTS[v{i}];")

    # Chain crossfades
    xfades = []
    for i in range(1, n):
        xoffset = sum(durations[:i]) - xfade_dur
        prev = f"xfade{i-1}" if i > 1 else f"v0"
        if i == 1:
            xfades.append(f"[v0][v1]xfade=transition=fade:duration={xfade_dur}:offset={sum(durations[:1])-xfade_dur}[xfade1];")
        else:
            xfades.append(f"[xfade{i-1}][v{i}]xfade=transition=fade:duration={xfade_dur}:offset={xoffset}[xfade{i}];")

    filter_complex = "".join(scale_filters) + "".join(xfades)
    out_label = f"xfade{n-1}" if n > 1 else "v0"

    # Caption
    if caption_text:
        safe_cap = _escape_ffmpeg_text(caption_text)
        fs = max(16, int(w * 0.037))
        filter_complex += f"[{out_label}]drawtext=text='{safe_cap}':fontcolor=white:fontsize={fs}:x=(w-text_w)/2:y=h-th-60:box=1:boxcolor=black@0.4:boxborderw=10[outv]"
        out_label = "outv"

    total_dur = sum(durations)
    if bgm_path and Path(bgm_path).exists():
        cmd = [ffmpeg, "-y"] + inputs + ["-stream_loop", "-1", "-i", str(bgm_path),
            "-filter_complex", filter_complex,
            "-map", f"[{out_label}]", "-map", "1:a",
            "-c:v", "libx264", "-crf", str(crf), "-preset", "medium",
            "-pix_fmt", "yuv420p", "-t", str(total_dur),
            "-c:a", "aac", "-b:a", "128k", str(output_path)]
    else:
        cmd = [ffmpeg, "-y"] + inputs + [
            "-filter_complex", filter_complex,
            "-map", f"[{out_label}]",
            "-c:v", "libx264", "-crf", str(crf), "-preset", "medium",
            "-pix_fmt", "yuv420p", "-an", "-t", str(total_dur), str(output_path)]

    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        log.warning(f"Crossfade failed ({result.stderr[:200]}), falling back to simple concat")
        # Fallback to simple concat
        list_file = ROOT / "temp_concat.txt"
        with open(list_file, "w", encoding="utf-8") as f:
            for p, d in zip(clip_paths, durations):
                f.write(f"file '{Path(p).absolute().as_posix()}'\n")
                f.write(f"duration {d}\n")
            f.write(f"file '{Path(clip_paths[-1]).absolute().as_posix()}'\n")
        return _stitch_simple(list_file, clip_paths, durations, output_path,
                              bgm_path, caption_text, w, h, fps, crf, vf_parts)
    log.info(f"Video stitched (crossfade): {output_path}")
    return True


# ====== Project State ======
def load_states() -> dict:
    if STATES_FILE.exists():
        return json.loads(STATES_FILE.read_text(encoding="utf-8"))
    return {"projects": {}}


def save_states(states: dict):
    STATES_FILE.write_text(json.dumps(states, ensure_ascii=False, indent=2), encoding="utf-8")


# ====== Main Pipeline ======
def run_pipeline(project_id: str, bgm_file: str = None, caption: str = "",
                 default_prompt: str = "", clip_configs: dict = None,
                 resolution: str = "1080x1920", fps: int = 30, crf: int = 20,
                 transition: str = "fade", model_name: str = "kling-v1-6"):
    """
    clip_configs: {image_name: {"prompt": "...", "duration": 5}, ...}
    Falls back to default_prompt / 5s for any image without config.
    """
    states = load_states()
    proj = states["projects"].get(project_id)
    if not proj:
        return

    clip_configs = clip_configs or {}

    proj["status"] = "generating"
    proj["message"] = "开始生成视频..."
    proj["progress"] = 0
    save_states(states)

    image_names = proj["images"]
    if not image_names:
        proj["status"] = "failed"
        proj["message"] = "没有图片"
        save_states(states)
        return

    for name in image_names:
        if not (INPUT_DIR / project_id / name).exists():
            proj["status"] = "failed"
            proj["message"] = f"图片不存在: {name}"
            save_states(states)
            return

    clip_dir = str(CLIPS_DIR / project_id)
    os.makedirs(clip_dir, exist_ok=True)

    # Build tasks with per-clip config
    tasks = []
    for i, name in enumerate(image_names):
        cfg = clip_configs.get(name, {})
        img_path = str(INPUT_DIR / project_id / name)
        prompt = cfg.get("prompt", "") or default_prompt or \
                 "smooth cinematic product shot, professional lighting, 4K"
        duration = int(cfg.get("duration", 5))
        tasks.append((name, img_path, clip_dir, prompt, duration, model_name, i + 1, len(image_names)))

    # Generate clips concurrently
    clip_results = []
    with ThreadPoolExecutor(max_workers=MAX_CONCURRENT) as executor:
        futures = {executor.submit(_generate_single_clip, t): t[0] for t in tasks}
        for future in as_completed(futures):
            try:
                img_name, clip_path, dur = future.result()
                clip_results.append((img_name, clip_path, dur))
                proj["clips"][img_name] = clip_path
                proj["progress"] = int((len(clip_results) / len(image_names)) * 75)
                proj["message"] = f"视频生成 {len(clip_results)}/{len(image_names)}"
                save_states(states)
            except Exception as e:
                proj["status"] = "failed"
                proj["message"] = f"生成失败 ({futures[future]}): {e}"
                log.error(f"Clip generation failed: {e}")
                save_states(states)
                return

    # Sort by original image order
    name_order = {name: i for i, name in enumerate(image_names)}
    clip_results.sort(key=lambda x: name_order.get(x[0], 999))

    # Stitch with per-clip durations
    proj["message"] = "合成最终视频..."
    proj["progress"] = 85
    save_states(states)

    output_dir = OUTPUT_DIR / project_id
    output_dir.mkdir(parents=True, exist_ok=True)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    safe_project_name = re.sub(r'[\\/:*?"<>|]', '_', proj.get("name", "video"))
    output_path = output_dir / f"{safe_project_name}_{timestamp}.mp4"

    clip_files = [cp[1] for cp in clip_results]
    # Use actual durations for stitched segments — pick the first clip's duration as reference
    durations = [cp[2] for cp in clip_results]
    ok = stitch_clips(clip_files, str(output_path), bgm_file, caption, durations,
                      resolution=resolution, fps=fps, crf=crf, transition=transition)

    if ok:
        proj["status"] = "done"
        proj["message"] = "视频生成完成!"
        proj["progress"] = 100
        proj["final_video"] = str(output_path)
    else:
        proj["status"] = "failed"
        proj["message"] = "视频合成失败"
    save_states(states)


# ====== AI Storyboard Generator ======
STORYBOARD_SYSTEM_PROMPT = """You are a professional TikTok video director specializing in product marketing.
Given a product description, generate a 6-shot storyboard for a 30-second product video.

Rules:
- Adapt the visual style, lighting, mood, and camera movement to fit the product naturally
- Shot 1: Product reveal/intro (3-5s)
- Shot 2: Detail or angle variation (3-5s)
- Shot 3: Macro/close-up or texture (4-6s)
- Shot 4: Usage context or lifestyle scene (5-7s)
- Shot 5: Benefit/effect showcase (5-7s)
- Shot 6: Brand logo + CTA (3-4s)
- Each prompt must be in English, concise (under 100 chars), optimized for image-to-video AI
- Include camera movement keywords (slow pan, zoom in, tracking shot, etc.)
- Total duration should be around 30 seconds

Return ONLY valid JSON, no markdown, no explanation:
{
  "shots": [
    {"prompt": "...", "duration": 5},
    ...
  ],
  "style_note": "brief description of the overall visual style in Chinese"
}"""

def generate_storyboard(product_description: str) -> dict:
    """Use Groq to generate a 6-shot storyboard from a product description."""
    groq_key = os.getenv("GROQ_API_KEY", "")
    if not groq_key:
        raise RuntimeError("请先在 .env 中设置 GROQ_API_KEY")

    import requests
    resp = requests.post("https://api.groq.com/openai/v1/chat/completions",
        headers={"Authorization": f"Bearer {groq_key}", "Content-Type": "application/json"},
        json={
            "model": "llama-3.3-70b-versatile",
            "messages": [
                {"role": "system", "content": STORYBOARD_SYSTEM_PROMPT},
                {"role": "user", "content": product_description}
            ],
            "temperature": 0.7,
            "max_tokens": 2000,
        }, timeout=30)
    resp.raise_for_status()
    raw = resp.json()["choices"][0]["message"]["content"]
    # Strip possible markdown ```json fences
    raw = raw.strip()
    if raw.startswith("```"):
        raw = raw.split("\n", 1)[1]
        if raw.endswith("```"):
            raw = raw[:-3]
    return json.loads(raw)


# ====== Init ======
def init_dirs():
    for d in [INPUT_DIR, CLIPS_DIR, OUTPUT_DIR, BGM_DIR]:
        d.mkdir(parents=True, exist_ok=True)


if __name__ == "__main__":
    init_dirs()
    load_config()
    log.info("Pipeline engine v2 ready.")
