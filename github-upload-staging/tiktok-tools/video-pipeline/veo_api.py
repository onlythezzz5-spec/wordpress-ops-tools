#!/usr/bin/env python3
"""
Google Veo Video Generation via Vertex AI REST API.

Auth: Google Service Account JSON key OR application default credentials
Requires: pip install google-auth google-auth-requests
"""

import json, os, time, logging, base64
from pathlib import Path
import requests as http

log = logging.getLogger("veo")

MODELS = {
    "veo-3.1":      {"id": "veo-3.1-generate-001",      "name": "Veo 3.1 (最高画质)"},
    "veo-3.1-fast": {"id": "veo-3.1-fast-generate-001",  "name": "Veo 3.1 Fast (推荐)"},
    "veo-3.1-lite": {"id": "veo-3.1-lite-generate-001",  "name": "Veo 3.1 Lite (经济)"},
}
DURATIONS = [4, 6, 8]


def _nearest_duration(dur: int) -> int:
    return min(DURATIONS, key=lambda x: abs(x - dur))


def _get_token() -> str:
    """Get Google Cloud access token."""
    creds_file = os.getenv("GOOGLE_APPLICATION_CREDENTIALS", "")
    if creds_file:
        os.environ["GOOGLE_APPLICATION_CREDENTIALS"] = creds_file
    try:
        import google.auth
        from google.auth.transport.requests import Request
        credentials, project = google.auth.default(scopes=["https://www.googleapis.com/auth/cloud-platform"])
        credentials.refresh(Request())
        return credentials.token, project
    except ImportError:
        raise RuntimeError("pip install google-auth google-auth-requests")
    except Exception as e:
        raise RuntimeError(f"Google 认证失败: {e}\n请确认已设置 GOOGLE_APPLICATION_CREDENTIALS 或运行 gcloud auth application-default login")


def image_to_video(image_path: str, prompt: str = "", duration: int = 5,
                   model_id: str = "veo-3.1-fast") -> dict:
    """Submit Veo image-to-video task via REST API."""
    token, project = _get_token()
    location = os.getenv("GOOGLE_CLOUD_LOCATION", "us-central1")
    model = MODELS.get(model_id, MODELS["veo-3.1-fast"])
    dur = _nearest_duration(int(duration))

    # Read image as base64
    with open(image_path, "rb") as f:
        img_b64 = base64.b64encode(f.read()).decode("utf-8")
    # Detect MIME type
    ext = Path(image_path).suffix.lower()
    mime = {"jpg": "image/jpeg", "jpeg": "image/jpeg", "png": "image/png", "webp": "image/webp"}.get(ext, "image/jpeg")

    body = {
        "instances": [{
            "prompt": prompt or "smooth cinematic camera movement, professional lighting, 4K quality",
            "image": {"bytesBase64Encoded": img_b64, "mimeType": mime},
        }],
        "parameters": {
            "durationSeconds": dur,
            "aspectRatio": "9:16",
            "resolution": "1080p",
            "personGeneration": "allow_all",
        },
    }

    url = (f"https://{location}-aiplatform.googleapis.com/v1/projects/{project}/"
           f"locations/{location}/publishers/google/models/{model['id']}:predictLongRunning")
    resp = http.post(url, headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
                     json=body, timeout=30)
    resp.raise_for_status()
    data = resp.json()
    op_name = data.get("name", "")
    if not op_name:
        raise RuntimeError(f"Veo: missing operation name in response")
    log.info(f"Veo submitted: {op_name} ({model['name']}, {dur}s)")
    return {"operation_name": op_name, "model": model_id, "duration": dur, "image_stem": Path(image_path).stem}


def poll_task_and_save(op_info: dict, save_path: str, timeout_minutes: int = 15) -> str:
    """Poll Veo operation, download video, save to save_path. Returns save_path."""
    token, _ = _get_token()
    op_name = op_info["operation_name"]
    deadline = time.time() + timeout_minutes * 60

    while time.time() < deadline:
        url = f"https://us-central1-aiplatform.googleapis.com/v1/{op_name}"
        if not op_name.startswith("projects/"):
            url = f"https://us-central1-aiplatform.googleapis.com/v1/{op_name}"

        resp = http.get(url, headers={"Authorization": f"Bearer {token}"}, timeout=15)
        if resp.status_code != 200:
            time.sleep(10)
            token, _ = _get_token()
            continue

        data = resp.json()
        if data.get("done"):
            if "error" in data:
                raise RuntimeError(f"Veo failed: {data['error']}")
            resp_data = data.get("response", {})

            # Find video URI in response
            gcs_uri = ""
            videos = resp_data.get("generatedVideos", resp_data.get("generated_videos", []))
            for v in videos:
                vid = v.get("video", v)
                gcs_uri = vid.get("uri", vid.get("gcsUri", ""))
                if gcs_uri:
                    break

            if gcs_uri:
                bucket_path = gcs_uri.replace("gs://", "")
                dl_url = f"https://storage.googleapis.com/{bucket_path}"
                dl_resp = http.get(dl_url, timeout=180)
                dl_resp.raise_for_status()
                Path(save_path).parent.mkdir(parents=True, exist_ok=True)
                Path(save_path).write_bytes(dl_resp.content)
                log.info(f"Veo saved: {save_path}")
                return save_path
            raise RuntimeError(f"Veo: no video URI in response")

        time.sleep(10)

    raise TimeoutError(f"Veo timeout: {op_name}")


def poll_task(op_info: dict, timeout_minutes: int = 15) -> str:
    """Poll Veo operation, download video, return local path."""
    token, _ = _get_token()
    op_name = op_info["operation_name"]
    stem = op_info.get("image_stem", "veo_output")
    deadline = time.time() + timeout_minutes * 60

    while time.time() < deadline:
        resp = http.get(f"https://{op_name.split('/locations/')[0]}/v1/{op_name}"
                        if not op_name.startswith("https") else op_name,
                        headers={"Authorization": f"Bearer {token}"}, timeout=15)
        # Build proper URL
        base = op_name.split("//")[1] if "//" in op_name else f"us-central1-aiplatform.googleapis.com/v1/{op_name}"
        url = f"https://{base}" if not base.startswith("http") else base
        if not url.startswith("http"):
            url = f"https://us-central1-aiplatform.googleapis.com/v1/{op_name}"

        resp = http.get(url, headers={"Authorization": f"Bearer {token}"}, timeout=15)
        if resp.status_code != 200:
            time.sleep(10)
            token, _ = _get_token()  # refresh token
            continue

        data = resp.json()
        if data.get("done"):
            if "error" in data:
                raise RuntimeError(f"Veo failed: {data['error']}")
            resp_data = data.get("response", {})
            # Navigate response structure
            videos = (resp_data.get("generatedVideos", []) or
                      resp_data.get("generated_videos", []))
            if not videos and "video" in resp_data:
                videos = [resp_data]
            if not videos:
                # Try deeper nesting
                for key in resp_data:
                    if isinstance(resp_data[key], list):
                        videos = resp_data[key]
                        break

            gcs_uri = ""
            for v in videos:
                if isinstance(v, dict):
                    vid = v.get("video", v)
                    gcs_uri = vid.get("uri", vid.get("gcsUri", ""))
                    if gcs_uri:
                        break

            if gcs_uri:
                # Download via GCS direct HTTP
                bucket_path = gcs_uri.replace("gs://", "")
                dl_url = f"https://storage.googleapis.com/{bucket_path}"
                dl_resp = http.get(dl_url, timeout=180)
                dl_resp.raise_for_status()
                local_path = str(Path(tempfile.gettempdir()) / f"{stem}_{op_info.get('duration',5)}s_veo.mp4")
                # Actually save to a reasonable location
                import tempfile
                out = Path(os.getenv("TEMP", tempfile.gettempdir())) / f"{stem}_veo_{int(time.time())}.mp4"
                out.write_bytes(dl_resp.content)
                log.info(f"Veo video saved: {out}")
                return str(out)
            raise RuntimeError(f"Veo: no video URI in done response")

        time.sleep(10)

    raise TimeoutError(f"Veo task timeout: {op_name}")
