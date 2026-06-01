#!/usr/bin/env python3
"""
Video Pipeline Web Panel — FastAPI Server v2
Start: python server.py  →  open http://localhost:8888
"""
import os, sys, json, shutil, threading, uuid, re
from pathlib import Path
from datetime import datetime
from typing import List

ROOT = Path(__file__).parent
sys.path.insert(0, str(ROOT))

# Lazy import — if deps missing, user must install manually
try:
    from fastapi import FastAPI, UploadFile, File, Form, HTTPException, Request
    from fastapi.responses import FileResponse, HTMLResponse
    import uvicorn
except ImportError:
    print("Missing dependencies. Run:")
    print("  pip install fastapi uvicorn python-multipart")
    sys.exit(1)

from pipeline import (
    load_config, init_dirs, load_states, save_states,
    run_pipeline, generate_storyboard, _safe_name,
    INPUT_DIR, CLIPS_DIR, OUTPUT_DIR, BGM_DIR,
    log
)

app = FastAPI(title="zzz电商视频工作流", version="2.0")
init_dirs()
load_config()

# ====== API Routes ======

@app.get("/api/projects")
def list_projects():
    states = load_states()
    projects = []
    for pid, p in states.get("projects", {}).items():
        projects.append({
            "id": pid,
            "name": p["name"],
            "status": p.get("status", "draft"),
            "progress": p.get("progress", 0),
            "message": p.get("message", ""),
            "created": p.get("created", ""),
            "image_count": len(p.get("images", [])),
            "has_video": bool(p.get("final_video", "")),
            "video_path": p.get("final_video", ""),
        })
    return sorted(projects, key=lambda x: x["created"], reverse=True)


@app.post("/api/projects")
def new_project(name: str = Form(...)):
    pid = str(uuid.uuid4())[:8]
    proj_input = INPUT_DIR / pid
    proj_input.mkdir(parents=True, exist_ok=True)

    states = load_states()
    states["projects"][pid] = {
        "id": pid,
        "name": name,
        "created": datetime.now().isoformat(),
        "status": "draft",
        "images": [],
        "clips": {},
        "final_video": "",
        "progress": 0,
        "message": "等待上传图片...",
    }
    save_states(states)
    log.info(f"Project created: {pid} ({name})")
    return {"id": pid, "name": name}


@app.delete("/api/projects/{pid}")
def delete_project(pid: str):
    states = load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")
    del states["projects"][pid]
    save_states(states)
    for d in [INPUT_DIR, CLIPS_DIR, OUTPUT_DIR]:
        shutil.rmtree(d / pid, ignore_errors=True)
    log.info(f"Project deleted: {pid}")
    return {"ok": True}


@app.post("/api/projects/{pid}/upload")
async def upload_images(pid: str, files: List[UploadFile] = File(...)):
    states = load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")

    proj_input = INPUT_DIR / pid
    proj_input.mkdir(parents=True, exist_ok=True)

    saved = []
    for f in files:
        safe = _safe_name(f.filename)
        filepath = proj_input / safe
        content = await f.read()
        filepath.write_bytes(content)
        saved.append(safe)
        log.info(f"Uploaded: {pid}/{safe}")

    existing = states["projects"][pid].get("images", [])
    saved = [s for s in saved if s not in existing]
    images = existing + saved
    images.sort()
    states["projects"][pid]["images"] = images
    total = len(images)
    states["projects"][pid]["message"] = f"已上传 {total} 张图片"
    save_states(states)
    return {"images": images, "added": len(saved), "count": total}


@app.post("/api/projects/{pid}/reorder")
async def reorder_images(pid: str, order: str = Form(...)):
    states = load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")
    order_list = json.loads(order)
    existing = set(states["projects"][pid].get("images", []))
    order_list = [n for n in order_list if n in existing]
    states["projects"][pid]["images"] = order_list
    save_states(states)
    return {"images": order_list}


@app.get("/api/projects/{pid}/images")
def get_project_images(pid: str):
    states = load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    images = []
    for name in proj.get("images", []):
        img_path = INPUT_DIR / pid / name
        images.append({
            "name": name,
            "exists": img_path.exists(),
            "has_clip": name in proj.get("clips", {}),
            "clip_path": proj.get("clips", {}).get(name, ""),
        })
    return images


@app.get("/api/projects/{pid}/image/{name}")
def serve_image(pid: str, name: str):
    safe = _safe_name(name)
    img_path = INPUT_DIR / pid / safe
    if not img_path.exists():
        raise HTTPException(404, "Image not found")
    return FileResponse(img_path)


@app.get("/api/projects/{pid}/video/{name}")
def serve_clip(pid: str, name: str):
    safe = _safe_name(name)
    clip_path = CLIPS_DIR / pid / safe
    if not clip_path.exists():
        raise HTTPException(404, "Clip not found")
    return FileResponse(clip_path, media_type="video/mp4")


@app.get("/api/projects/{pid}/download")
def download_final(pid: str):
    states = load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj or not proj.get("final_video"):
        raise HTTPException(404, "Video not ready")
    path = Path(proj["final_video"])
    if not path.exists():
        raise HTTPException(404, "Video file missing")
    return FileResponse(path, media_type="video/mp4", filename=f"{proj['name']}.mp4")


@app.post("/api/projects/{pid}/generate")
def start_generation(pid: str,
                     prompt: str = Form("smooth cinematic product shot, professional lighting, 4K"),
                     caption: str = Form(""),
                     bgm_name: str = Form(""),
                     clip_configs: str = Form("{}"),
                     resolution: str = Form("1080x1920"),
                     fps: str = Form("30"),
                     crf: str = Form("20"),
                     transition: str = Form("fade"),
                     model_name: str = Form("kling-v1-6")):
    states = load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    if not proj.get("images"):
        raise HTTPException(400, "请先上传图片")

    try:
        cc = json.loads(clip_configs)
    except json.JSONDecodeError:
        cc = {}

    proj["status"] = "generating"
    proj["progress"] = 0
    proj["message"] = "排队中..."
    proj["clip_configs"] = cc
    save_states(states)

    bgm_path = str(BGM_DIR / _safe_name(bgm_name)) if bgm_name else None
    if bgm_path and not Path(bgm_path).exists():
        bgm_path = None

    log.info(f"Generating {pid}: {len(proj['images'])} imgs, {resolution}@{fps}fps crf{crf} {transition} {model_name}")
    t = threading.Thread(target=run_pipeline,
        args=(pid, bgm_path, caption, prompt, cc, resolution, int(fps), int(crf), transition, model_name),
        daemon=True)
    t.start()
    return {"ok": True, "message": "开始生成..."}


@app.post("/api/projects/{pid}/ai-storyboard")
def ai_storyboard(pid: str, description: str = Form(...)):
    """Generate storyboard prompts via Groq AI."""
    try:
        result = generate_storyboard(description)
        # Save to project clip_configs
        states = load_states()
        if pid in states.get("projects", {}):
            cc = {}
            images = states["projects"][pid].get("images", [])
            shots = result.get("shots", [])
            for i, img_name in enumerate(images):
                if i < len(shots):
                    cc[img_name] = {
                        "prompt": shots[i].get("prompt", ""),
                        "duration": shots[i].get("duration", 5)
                    }
            states["projects"][pid]["clip_configs"] = cc
            save_states(states)
        return {"ok": True, "storyboard": result, "clip_configs": cc}
    except Exception as e:
        log.error(f"AI storyboard failed: {e}")
        raise HTTPException(500, f"AI 生成失败: {e}")


@app.get("/api/projects/{pid}/clip-configs")
def get_clip_configs(pid: str):
    """Return per-clip configs saved on project."""
    states = load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    return proj.get("clip_configs", {})


@app.get("/api/bgm")
def list_bgm():
    if not BGM_DIR.exists():
        return []
    return [f.name for f in BGM_DIR.iterdir() if f.suffix.lower() in (".mp3", ".wav", ".m4a", ".ogg")]


@app.post("/api/bgm/upload")
async def upload_bgm(file: UploadFile = File(...)):
    BGM_DIR.mkdir(parents=True, exist_ok=True)
    safe = _safe_name(file.filename)
    path = BGM_DIR / safe
    path.write_bytes(await file.read())
    log.info(f"BGM uploaded: {safe}")
    return {"name": safe}


# ====== Image Pipeline ======
from image_pipeline import (
    generate_strategy, render_all_shots, check_compliance, analyze_product,
    _render_and_save,
    STYLE_PRESETS, ASPECT_RATIOS, IMAGE_INPUT_DIR, IMAGE_OUTPUT_DIR,
    load_states as img_load_states, save_states as img_save_states,
    init_dirs as img_init_dirs,
)

img_init_dirs()

# ====== State Migration ======
def _migrate_image_state():
    states = img_load_states()
    changed = False
    for pid, proj in states.get("projects", {}).items():
        for key, default in [
            ("product_analysis", None),
            ("strategy_edited", False),
            ("shot_overrides", {}),
            ("workflow_step", "upload"),
        ]:
            if key not in proj:
                proj[key] = default
                changed = True
        # Fix old saved_path values that contain paths
        for r in proj.get("results", []):
            sp = r.get("saved_path", "")
            if sp and ("\\" in sp or "/" in sp):
                r["saved_path"] = sp.replace("\\", "/").split("/")[-1]
                changed = True
    if changed:
        img_save_states(states)
    return states

_migrate_image_state()
def _load_images_b64(pid, image_names):
    import base64
    result = []
    for name in (image_names or []):
        p = IMAGE_INPUT_DIR / pid / name
        if p.exists():
            ext = p.suffix.lower()
            mime = {'jpg':'image/jpeg','jpeg':'image/jpeg','png':'image/png','webp':'image/webp'}.get(ext,'image/jpeg')
            result.append({'mime':mime,'data':base64.b64encode(p.read_bytes()).decode()})
    return result



@app.get("/api/image/styles")
def get_image_styles():
    return {
        "styles": [{"id": k, "name": v} for k, v in STYLE_PRESETS.items()],
        "ratios": [{"id": k, "size": v} for k, v in ASPECT_RATIOS.items()],
        "platforms": [
            {"id": "TikTok", "name": "TikTok", "icon": "🎵", "hint": "野性细节，肌肉张力，瞬间唤醒购买冲动"},
            {"id": "Shopee", "name": "Shopee", "icon": "🛍️", "hint": "参数前置，功能明确，搜索导向"},
            {"id": "DirectResponse", "name": "Meta 广告", "icon": "📘", "hint": "痛点压迫，说服逻辑，信用背书"},
            {"id": "Ozon", "name": "Ozon/WB (俄区)", "icon": "🟣", "hint": "科技硬件：碳纤维黑背景，冰蓝光效，参数数字驱动，热成像/RGB/气流可视化，8K商业渲染"},
        ],
        "triggers": [
            {"id": "Impulse", "name": "痛点与野性", "icon": "⚡", "hint": "汗水、青筋、抓握张力等极限细节"},
            {"id": "Rational", "name": "绝对性能", "icon": "🧠", "hint": "强调为什么能解决，展示极致机理"},
            {"id": "Premium", "name": "身份阶级", "icon": "💎", "hint": "高级、克制的使用场景，赋予身份认同"},
        ],
    }


@app.get("/api/image/projects")
def list_image_projects():
    states = img_load_states()
    projects = []
    for pid, p in states.get("projects", {}).items():
        projects.append({
            "id": pid, "name": p["name"], "status": p.get("status", "draft"),
            "progress": p.get("progress", 0), "message": p.get("message", ""),
            "style": p.get("style", ""), "ratio": p.get("ratio", ""),
            "created": p.get("created", ""), "images": p.get("images", []),
            "image_count": len(p.get("images", [])),
            "result_count": len(p.get("results", [])),
            "platform": p.get("platform", ""), "trigger": p.get("trigger", ""),
            "description": p.get("description", ""),
            "workflow_step": p.get("workflow_step", "upload"),
            "product_analysis": p.get("product_analysis"),
        })
    return sorted(projects, key=lambda x: x["created"], reverse=True)


@app.post("/api/image/projects")
def new_image_project(name: str = Form(...), style: str = Form("amazon"),
                      ratio: str = Form("1:1")):
    import uuid
    pid = str(uuid.uuid4())[:8]
    (IMAGE_INPUT_DIR / pid).mkdir(parents=True, exist_ok=True)
    states = img_load_states()
    states["projects"][pid] = {
        "id": pid, "name": name, "style": style, "ratio": ratio,
        "created": datetime.now().isoformat(),
        "status": "draft", "progress": 0, "message": "等待上传产品图...",
        "images": [], "results": [], "lighting": "", "tone": "", "bg": "", "quality": "standard",
    }
    img_save_states(states)
    return {"id": pid, "name": name}


@app.delete("/api/image/projects/{pid}")
def delete_image_project(pid: str):
    states = img_load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")
    del states["projects"][pid]
    img_save_states(states)
    import shutil
    shutil.rmtree(IMAGE_INPUT_DIR / pid, ignore_errors=True)
    shutil.rmtree(IMAGE_OUTPUT_DIR / pid, ignore_errors=True)
    return {"ok": True}


@app.post("/api/image/projects/{pid}/upload")
async def upload_image_files(pid: str, files: List[UploadFile] = File(...)):
    states = img_load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")
    inp = IMAGE_INPUT_DIR / pid
    inp.mkdir(parents=True, exist_ok=True)
    saved = []
    for f in files:
        safe = re.sub(r'[\\/:*?"<>|]', '_', f.filename or "image.png")
        p = inp / safe
        p.write_bytes(await f.read())
        saved.append(safe)
    existing = states["projects"][pid].get("images", [])
    saved = [s for s in saved if s not in existing]
    images = existing + saved
    images.sort()
    states["projects"][pid]["images"] = images
    total = len(images)
    states["projects"][pid]["message"] = f"已上传 {total} 张产品图"
    img_save_states(states)
    return {"images": images, "added": len(saved), "total": total}


@app.delete("/api/image/projects/{pid}/image/{name}")
def remove_image_from_project(pid: str, name: str):
    states = img_load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")
    safe = re.sub(r'[\\/:*?"<>|]', '_', name)
    # Remove file
    img_path = IMAGE_INPUT_DIR / pid / safe
    if img_path.exists():
        img_path.unlink()
    # Remove from state
    images = states["projects"][pid].get("images", [])
    images = [n for n in images if n != name]
    states["projects"][pid]["images"] = images
    states["projects"][pid]["message"] = f"已更新 {len(images)} 张产品图" if images else "就绪"
    img_save_states(states)
    return {"ok": True, "images": images}


@app.get("/api/image/projects/{pid}/image/{name}")
def serve_image_input(pid: str, name: str):
    p = IMAGE_INPUT_DIR / pid / name
    if not p.exists():
        raise HTTPException(404, "Image not found")
    return FileResponse(p)


@app.post("/api/image/projects/{pid}/generate")
def start_image_generation(pid: str,
        platform: str = Form("TikTok"), trigger: str = Form("Impulse"),
        style: str = Form(""), ratio: str = Form(""),
        description: str = Form("")):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    if not proj.get("images"):
        raise HTTPException(400, "请先上传产品图")

    if style: proj["style"] = style
    if ratio: proj["ratio"] = ratio
    proj["platform"] = platform
    proj["trigger"] = trigger
    if description: proj["description"] = description
    proj["status"] = "generating"
    proj["progress"] = 0
    proj["message"] = "GPT-4o 生成转化策略..."
    img_save_states(states)

    desc = description or proj.get("description", "") or proj.get("name", "")
    images_b64 = _load_images_b64(pid, proj.get("images", []))

    def _run():
        try:
            # Phase 1: Product analysis (if not done)
            product_analysis = proj.get("product_analysis")
            if not product_analysis and images_b64:
                product_analysis = analyze_product(desc, images_b64)
                p0 = img_load_states()
                p0["projects"][pid]["product_analysis"] = product_analysis
                p0["projects"][pid]["message"] = "GPT-4o 生成转化策略..."
                p0["projects"][pid]["progress"] = 20
                img_save_states(p0)

            # Phase 2: Strategy generation
            strategy = generate_strategy(desc, platform, trigger, style, ratio,
                                         images_b64, product_analysis)
            p2 = img_load_states()
            p2["projects"][pid]["strategy"] = strategy
            p2["projects"][pid]["results"] = []  # Clear old results
            p2["projects"][pid]["message"] = "gpt-image-2 渲染图片中..."
            p2["projects"][pid]["progress"] = 40
            img_save_states(p2)

            # Phase 3: Render all shots with reference images
            results = render_all_shots(strategy, ratio, pid,
                                       reference_images=images_b64,
                                       product_analysis=product_analysis,
                                       progress_callback=lambda d, t: _image_render_progress(pid, d, t))
            p3 = img_load_states()
            p3["projects"][pid]["results"] = results
            n_ok = len([r for r in results if "error" not in r])
            n_total = len(results)
            if n_ok == 0:
                p3["projects"][pid]["status"] = "failed"
                p3["projects"][pid]["message"] = f"生成失败: 0/{n_total} 张，请检查 API/网络后重试"
            elif n_ok < n_total:
                p3["projects"][pid]["status"] = "partial"
                p3["projects"][pid]["message"] = f"部分完成: {n_ok}/{n_total} 张，可重试失败图片"
            else:
                p3["projects"][pid]["status"] = "done"
                p3["projects"][pid]["message"] = f"完成! {n_ok}/{n_total} 张"
            p3["projects"][pid]["progress"] = 100
            img_save_states(p3)
        except Exception as e:
            p3 = img_load_states()
            p3["projects"][pid]["status"] = "failed"
            p3["projects"][pid]["message"] = f"失败: {e}"
            img_save_states(p3)

    threading.Thread(target=_run, daemon=True).start()
    return {"ok": True}




def _image_render_progress(pid, done, total, base=60, span=35):
    try:
        st = img_load_states()
        proj = st.get("projects", {}).get(pid)
        if not proj:
            return
        pct = base + int((done / max(total, 1)) * span)
        proj["status"] = "rendering"
        proj["progress"] = min(99, pct)
        proj["message"] = f"渲染中... {done}/{total} 张"
        img_save_states(st)
    except Exception as e:
        log.warning(f"Progress update failed: {e}")

# ====== Image Pipeline: New Step-by-Step Endpoints ======

@app.post("/api/image/projects/{pid}/analyze")
def analyze_product_endpoint(pid: str):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    if not proj.get("images"):
        raise HTTPException(400, "请先上传产品图")

    proj["status"] = "analyzing"
    proj["message"] = "GPT-4o 分析产品特征..."
    proj["progress"] = 10
    img_save_states(states)

    images_b64 = _load_images_b64(pid, proj.get("images", []))
    desc = proj.get("description", "") or proj.get("name", "")

    def _run():
        try:
            analysis = analyze_product(desc, images_b64)
            p2 = img_load_states()
            p2["projects"][pid]["product_analysis"] = analysis
            p2["projects"][pid]["status"] = "analyzed"
            p2["projects"][pid]["message"] = "产品分析完成 — 已提取材质/颜色/特征"
            p2["projects"][pid]["progress"] = 25
            p2["projects"][pid]["workflow_step"] = "analyzed"
            img_save_states(p2)
        except Exception as e:
            p2 = img_load_states()
            p2["projects"][pid]["status"] = "draft"
            p2["projects"][pid]["message"] = f"分析失败: {e}"
            img_save_states(p2)

    threading.Thread(target=_run, daemon=True).start()
    return {"ok": True}


@app.post("/api/image/projects/{pid}/strategy/generate")
def generate_strategy_endpoint(pid: str,
        platform: str = Form(""),
        trigger: str = Form(""),
        style: str = Form(""),
        ratio: str = Form(""),
        description: str = Form("")):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    if not proj.get("images"):
        raise HTTPException(400, "请先上传产品图")

    # Save config from UI
    if platform: proj["platform"] = platform
    if trigger: proj["trigger"] = trigger
    if style: proj["style"] = style
    if ratio: proj["ratio"] = ratio
    if description: proj["description"] = description
    proj["status"] = "strategy_generating"
    proj["message"] = "GPT-4o 生成 9-shot 转化策略..."
    proj["progress"] = 30
    img_save_states(states)

    images_b64 = _load_images_b64(pid, proj.get("images", []))
    desc = description or proj.get("description", "") or proj.get("name", "")
    platform = platform or proj.get("platform", "TikTok")
    trigger = trigger or proj.get("trigger", "Impulse")
    style = style or proj.get("style", "amazon")
    ratio = ratio or proj.get("ratio", "1:1")
    product_analysis = proj.get("product_analysis")

    def _run():
        try:
            strategy = generate_strategy(
                desc, platform, trigger, style, ratio,
                images_b64, product_analysis
            )
            p2 = img_load_states()
            p2["projects"][pid]["strategy"] = strategy
            p2["projects"][pid]["results"] = []  # Clear old results for new strategy
            p2["projects"][pid]["status"] = "strategy_ready"
            p2["projects"][pid]["progress"] = 50
            p2["projects"][pid]["message"] = "策略已生成 — 请检查并编辑各分镜提示词"
            p2["projects"][pid]["workflow_step"] = "strategy_ready"
            img_save_states(p2)
        except Exception as e:
            p2 = img_load_states()
            if p2["projects"].get(pid, {}).get("strategy"):
                p2["projects"][pid]["status"] = "strategy_ready"
                p2["projects"][pid]["progress"] = 50
                p2["projects"][pid]["workflow_step"] = "strategy_ready"
                p2["projects"][pid]["message"] = f"策略生成超时，已保留上一版可用策略: {e}"
            else:
                p2["projects"][pid]["status"] = "failed"
                p2["projects"][pid]["message"] = f"策略生成失败: {e}"
            img_save_states(p2)

    threading.Thread(target=_run, daemon=True).start()
    return {"ok": True}


@app.post("/api/image/projects/{pid}/strategy/update")
async def update_strategy_endpoint(pid: str, request: Request):
    try:
        body = await request.json()
    except Exception:
        raise HTTPException(400, "Invalid JSON body")
    strategy = body.get("strategy", {})
    if not strategy:
        raise HTTPException(400, "Missing 'strategy' in body")

    states = img_load_states()
    if pid not in states.get("projects", {}):
        raise HTTPException(404, "Project not found")
    states["projects"][pid]["strategy"] = strategy
    states["projects"][pid]["strategy_edited"] = True
    img_save_states(states)
    return {"ok": True}


@app.post("/api/image/projects/{pid}/render")
def render_shots_endpoint(pid: str,
        shot_ids: str = Form(""),
        ratio: str = Form("")):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    strategy = proj.get("strategy")
    if not strategy:
        raise HTTPException(400, "请先生成策略")

    proj["status"] = "rendering"
    proj["message"] = "gpt-image-2 渲染中..."
    proj["progress"] = 60
    proj["workflow_step"] = "rendering"
    img_save_states(states)

    images_b64 = _load_images_b64(pid, proj.get("images", []))
    product_analysis = proj.get("product_analysis")
    use_ratio = ratio or proj.get("ratio", "1:1")

    filter_ids = [int(x.strip()) for x in shot_ids.split(",") if x.strip()] if shot_ids else []
    all_shots = strategy.get("shots", [])
    shots_to_render = [s for s in all_shots if not filter_ids or s.get("id") in filter_ids]

    def _run():
        try:
            results = render_all_shots(
                {"shots": shots_to_render}, use_ratio, pid,
                reference_images=images_b64,
                product_analysis=product_analysis,
                progress_callback=lambda d, t: _image_render_progress(pid, d, t)
            )
            p2 = img_load_states()
            existing = p2["projects"][pid].get("results", [])
            by_id = {r.get("id"): r for r in existing}
            for r in results:
                by_id[r.get("id")] = r
            p2["projects"][pid]["results"] = sorted(by_id.values(), key=lambda r: r.get("id", 99))
            n_ok = len([r for r in results if "error" not in r])
            n_total = len(shots_to_render)
            if n_ok == 0:
                p2["projects"][pid]["status"] = "failed"
                p2["projects"][pid]["message"] = f"生成失败: 0/{n_total} 张，请检查 API/网络后重试"
            elif n_ok < n_total:
                p2["projects"][pid]["status"] = "partial"
                p2["projects"][pid]["message"] = f"部分完成: {n_ok}/{n_total} 张，可重试失败图片"
            else:
                p2["projects"][pid]["status"] = "done"
                p2["projects"][pid]["message"] = f"完成! {n_ok}/{n_total} 张"
            p2["projects"][pid]["progress"] = 100
            img_save_states(p2)
            log.info(f"Render done: {pid} status={p2['projects'][pid]['status']} results={n_ok}/{n_total}")
        except Exception as e:
            log.error(f"Render failed: {pid} — {e}")
            try:
                p2 = img_load_states()
                if pid in p2.get("projects", {}):
                    p2["projects"][pid]["status"] = "failed"
                    p2["projects"][pid]["message"] = f"渲染失败: {e}"
                    img_save_states(p2)
            except:
                pass

    threading.Thread(target=_run, daemon=True).start()
    return {"ok": True, "shot_count": len(shots_to_render)}


@app.post("/api/image/projects/{pid}/render/{shot_id}")
def render_single_shot_endpoint(pid: str, shot_id: int):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    strategy = proj.get("strategy")
    if not strategy:
        raise HTTPException(400, "请先生成策略")
    shot = next((s for s in strategy.get("shots", []) if s.get("id") == shot_id), None)
    if not shot:
        raise HTTPException(404, f"Shot #{shot_id} not found")

    images_b64 = _load_images_b64(pid, proj.get("images", []))
    product_analysis = proj.get("product_analysis")
    ratio = proj.get("ratio", "1:1")

    def _run():
        try:
            out_dir = IMAGE_OUTPUT_DIR / pid
            out_dir.mkdir(parents=True, exist_ok=True)
            size = ASPECT_RATIOS.get(ratio, "1024x1024")
            idx = shot_id - 1
            result = _render_and_save((shot, size, out_dir, idx, images_b64, product_analysis, ratio))
            p2 = img_load_states()
            existing = p2["projects"][pid].get("results", [])
            replaced = False
            for i, r in enumerate(existing):
                if r.get("id") == shot_id:
                    existing[i] = result
                    replaced = True
                    break
            if not replaced:
                existing.append(result)
            existing.sort(key=lambda r: r.get("id", 99))
            p2["projects"][pid]["results"] = existing
            if "error" not in result:
                p2["projects"][pid]["message"] = f"Shot #{shot_id} 重新渲染完成"
            img_save_states(p2)
        except Exception as e:
            log.error(f"Re-render shot {shot_id} failed: {e}")

    threading.Thread(target=_run, daemon=True).start()
    return {"ok": True}


@app.get("/api/image/projects/{pid}/strategy")
def get_image_strategy(pid: str):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj: raise HTTPException(404, "Project not found")
    return proj.get("strategy", {})

@app.get("/api/image/projects/{pid}/results")
def get_image_results(pid: str):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    return proj.get("results", [])


@app.get("/api/image/projects/{pid}/shot/{filename}")
def serve_image_shot(pid: str, filename: str):
    path = IMAGE_OUTPUT_DIR / pid / filename
    if not path.exists():
        raise HTTPException(404, "Image not found")
    return FileResponse(path)


# ====== Panel ======
@app.get("/", response_class=HTMLResponse)
def serve_panel():
    panel_html = ROOT / "panel.html"
    if panel_html.exists():
        return panel_html.read_text(encoding="utf-8")
    return "<h1>panel.html not found</h1>"


# ====== Start ======
if __name__ == "__main__":
    port = int(os.getenv("PORT", "8888"))
    log.info(f"Server starting on http://localhost:{port}")
    uvicorn.run(app, host="0.0.0.0", port=port, log_level="info")
