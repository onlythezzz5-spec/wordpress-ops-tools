from image_pipeline import (
    generate_all_images, analyze_product_image, STYLE_PRESETS, ASPECT_RATIOS,
    LIGHTING_MODES, COLOR_TONES, BACKGROUNDS,
    load_states as img_load_states, save_states as img_save_states,
    IMAGE_INPUT_DIR, IMAGE_OUTPUT_DIR, init_dirs as img_init_dirs,
)

img_init_dirs()


@app.get("/api/image/styles")
def get_image_styles():
    return {
        "styles": [{"id": k, "name": v["name"]} for k, v in STYLE_PRESETS.items()],
        "ratios": [{"id": k, "size": v} for k, v in ASPECT_RATIOS.items()],
        "lighting": [{"id": k, "label": v.split(",")[0]} for k, v in LIGHTING_MODES.items()],
        "tones": [{"id": k, "label": v.split(",")[0]} for k, v in COLOR_TONES.items()],
        "backgrounds": [{"id": k, "label": v.split(",")[0]} for k, v in BACKGROUNDS.items()],
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
            "created": p.get("created", ""), "image_count": len(p.get("images", [])),
            "result_count": len(p.get("results", [])),
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
async def upload_image_files(pid: str, files: list[UploadFile] = File(...)):
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
    saved.sort()
    states["projects"][pid]["images"] = saved
    states["projects"][pid]["message"] = f"已上传 {len(saved)} 张产品图"
    img_save_states(states)
    return {"images": saved}


@app.get("/api/image/projects/{pid}/image/{name}")
def serve_image_input(pid: str, name: str):
    p = IMAGE_INPUT_DIR / pid / name
    if not p.exists():
        raise HTTPException(404, "Image not found")
    return FileResponse(p)


@app.post("/api/image/projects/{pid}/generate")
def start_image_generation(pid: str,
        lighting: str = Form(""), tone: str = Form(""), bg: str = Form(""),
        quality: str = Form("standard"), style: str = Form(""), ratio: str = Form("")):
    states = img_load_states()
    proj = states.get("projects", {}).get(pid)
    if not proj:
        raise HTTPException(404, "Project not found")
    if not proj.get("images"):
        raise HTTPException(400, "请先上传产品图")

    if style: proj["style"] = style
    if ratio: proj["ratio"] = ratio
    proj["lighting"] = lighting
    proj["tone"] = tone
    proj["bg"] = bg
    proj["quality"] = quality
    proj["status"] = "generating"
    proj["progress"] = 0
    proj["message"] = "GPT-4o 分析产品中..."
    img_save_states(states)

    def _run():
        try:
            results = generate_all_images(proj)
            states2 = img_load_states()
            states2["projects"][pid]["results"] = results
            n_ok = len([r for r in results if "error" not in r])
            n_total = len(results)
            states2["projects"][pid]["status"] = "done"
            states2["projects"][pid]["progress"] = 100
            states2["projects"][pid]["message"] = f"完成! {n_ok}/{n_total} 张"
            img_save_states(states2)
        except Exception as e:
            states2 = img_load_states()
            states2["projects"][pid]["status"] = "failed"
            states2["projects"][pid]["message"] = f"失败: {e}"
            img_save_states(states2)

    import threading
    threading.Thread(target=_run, daemon=True).start()
    return {"ok": True}


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
